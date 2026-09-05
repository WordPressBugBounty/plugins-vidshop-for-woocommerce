<?php
/**
 * WooCommerce service.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

use VSFW\Interfaces\WooCommerce as WooCommerce_Interface;
use VSFW\Interfaces\Settings as Settings_Interface;

/**
 * WooCommerce service.
 */
class WooCommerce implements WooCommerce_Interface {

	/**
	 * Settings service.
	 *
	 * @var Settings_Interface
	 */
	protected $settings;

	/**
	 * Reason the last cart write failed, taken from WooCommerce's notices.
	 *
	 * @var string
	 */
	protected $last_cart_error = '';

	/**
	 * Constructor.
	 *
	 * @param Settings_Interface $settings Settings service.
	 */
	public function __construct( Settings_Interface $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Is WooCommerce active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
	}

	/**
	 * WooCommerce version.
	 *
	 * @return string
	 */
	public function get_version() {
		return defined( 'WC_VERSION' ) ? WC_VERSION : 'Unknown';
	}

	/**
	 * Is WooCommerce installed.
	 *
	 * @return bool
	 */
	public function is_installed() {
		return file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );
	}

	/**
	 * Initialize WooCommerce session, customer, and cart if needed.
	 *
	 * @return bool True if WooCommerce is loaded, false otherwise.
	 */
	public function init_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		if ( is_null( \WC()->session ) ) {
			\WC()->initialize_session();
		}

		if ( is_null( \WC()->customer ) ) {
			\WC()->customer = new \WC_Customer( get_current_user_id(), true );
		}

		if ( is_null( \WC()->cart ) ) {
			\WC()->cart = new \WC_Cart();
		}

		\WC()->cart->get_cart();

		return true;
	}

	/**
	 * Price a shopper should see, honouring WooCommerce's tax display setting.
	 *
	 * `get_price()` is the raw stored amount. The shop and the cart both run it through
	 * wc_get_price_to_display() first, so reading it directly is what made the feed
	 * disagree with the cart on any store that charges tax.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param float|null  $price   Optional explicit amount to convert.
	 *
	 * @return float
	 */
	protected function display_price( $product, $price = null ) {
		$args = ( null === $price ) ? array() : array( 'price' => $price );

		return (float) wc_get_price_to_display( $product, $args );
	}

	/**
	 * Min and max display price for a product.
	 *
	 * Variable products carry a range; `get_price()` returns only the cheapest variation,
	 * which reads as an exact price in the feed and then changes in the cart.
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return array{min:float,max:float}
	 */
	protected function get_price_range( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			// `true` asks WooCommerce for display prices, already converted for the tax
			// setting and sorted ascending — the same array get_price_html() renders from.
			$prices = $product->get_variation_prices( true );

			if ( ! empty( $prices['price'] ) ) {
				return array(
					'min' => (float) current( $prices['price'] ),
					'max' => (float) end( $prices['price'] ),
				);
			}
		}

		$price = $this->display_price( $product );

		return array(
			'min' => $price,
			'max' => $price,
		);
	}

	/**
	 * Formatted price, rendered as a range when the product spans one.
	 *
	 * @param \WC_Product $product Product object.
	 * @param array|null  $range   Range already resolved by the caller, so a variable
	 *                             product's prices are not read twice per payload.
	 *
	 * @return string
	 */
	protected function get_price_html( $product, $range = null ) {
		$range = ( null === $range ) ? $this->get_price_range( $product ) : $range;

		if ( $range['max'] > $range['min'] ) {
			return $this->settings->format_price( $range['min'] ) . ' &ndash; ' . $this->settings->format_price( $range['max'] );
		}

		return $this->settings->format_price( $range['min'] );
	}

	/**
	 * Stock facts the widget needs to stop a shopper before WooCommerce has to.
	 *
	 * `max_quantity` is -1 when the product is unlimited (not managing stock, or
	 * backorders allowed), otherwise the number actually on hand.
	 *
	 * @param \WC_Product $product Product or variation.
	 *
	 * @return array
	 */
	protected function get_stock_info( $product ) {
		$stock_qty = $product->get_stock_quantity();

		// Deliberately WooCommerce's own answer, never our own arithmetic: it runs the
		// `woocommerce_quantity_input_max` filter that min/max-quantity plugins hook, and
		// second-guessing it lets the widget offer a quantity the cart then refuses. The
		// fallback mirrors the same rule on versions without the helper.
		if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
			$max = $product->get_max_purchase_quantity();
		} elseif ( $product->is_sold_individually() ) {
			$max = 1;
		} elseif ( $product->backorders_allowed() || ! $product->managing_stock() ) {
			$max = -1;
		} else {
			$max = (int) $stock_qty;
		}

		return array(
			'is_in_stock'        => $product->is_in_stock(),
			'is_purchasable'     => $product->is_purchasable(),
			'is_on_backorder'    => method_exists( $product, 'is_on_backorder' ) ? $product->is_on_backorder() : false,
			'backorders_allowed' => $product->backorders_allowed(),
			'manage_stock'       => (bool) $product->managing_stock(),
			'stock_quantity'     => ( $product->managing_stock() && null !== $stock_qty ) ? (int) $stock_qty : null,
			'max_quantity'       => ( $max < 0 ) ? -1 : (int) $max,
			'sold_individually'  => $product->is_sold_individually(),
		);
	}

	/**
	 * Prepare simple product.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	public function prepare_simple_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		// False when the product has no featured image, and indexing that is a PHP 8 warning.
		$image_src = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'full' );
		$range     = $this->get_price_range( $product );

		return array_merge(
			array(
				'id'              => $product_id,
				'title'           => $product->get_name(),
				'price'           => $range['min'],
				'price_min'       => $range['min'],
				'price_max'       => $range['max'],
				'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'price_html'      => $this->get_price_html( $product, $range ),
				'image'           => $image_src ? $image_src[0] : '',
				'url'             => get_permalink( $product_id ),
				'type'            => $product->get_type(),
			),
			$this->get_stock_info( $product )
		);
	}

	/**
	 * Prepare stream products.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	public function prepare_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$image_src = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'full' );
		$image_url = $image_src ? $image_src[0] : '';
		$range     = $this->get_price_range( $product );

		return array_merge(
			array(
				'id'              => $product_id,
				'title'           => $product->get_name(),
				'description'     => $product->get_short_description(),
				'price'           => $range['min'],
				'price_min'       => $range['min'],
				'price_max'       => $range['max'],
				'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'price_html'      => $this->get_price_html( $product, $range ),
				'image'           => $image_url,
				'url'             => get_permalink( $product_id ),
				'type'            => $product->get_type(),
				'attributes'      => $this->get_product_attributes( $product ),
				'variations'      => $this->get_product_variations( $product ),
			),
			$this->get_stock_info( $product )
		);
	}

	/**
	 * Get product variations.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	public function get_product_variations( $product ) {
		$variations = array();

		if ( $product->is_type( 'variable' ) ) {
			$product_variations = $product->get_available_variations();
			foreach ( $product_variations as $variation ) {
				$variation_obj = wc_get_product( $variation['variation_id'] );

				if ( ! $variation_obj ) {
					continue;
				}

				$variation_price = $this->display_price( $variation_obj );

				$variations[] = array_merge(
					array(
						'id'          => $variation['variation_id'],
						'price'       => $variation_price,
						'price_html'  => $this->settings->format_price( $variation_price ),
						'attributes'  => $variation['attributes'],
						'image'       => ! empty( $variation['image'] ) ? $variation['image']['src'] : '',
						'description' => $variation_obj->get_description(),
					),
					$this->get_stock_info( $variation_obj )
				);
			}
		}

		return $variations;
	}

	/**
	 * Get product attributes for variations
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return array
	 */
	public function get_product_attributes( $product ) {
		$attributes = array();

		if ( $product->get_type() === 'variable' ) {
			$product_attributes = $product->get_attributes();

			foreach ( $product_attributes as $attribute_name => $attribute ) {
				if ( $attribute->get_variation() ) {
					$attribute_options = array();

					// Offer only the values the VARIATIONS actually use — WooCommerce's own rule.
					// `get_variation_attributes()` reads the children's values (falling back to the
					// parent's terms only for an "any" axis), and the storefront dropdown then keeps
					// the parent terms whose slug appears in that set. Listing every parent term
					// instead shows choices no variation can resolve: a parent carrying five colours
					// whose variations cover three offered two that always failed to add.
					$used = $product->get_variation_attributes();
					$used = isset( $used[ $attribute->get_name() ] )
						? array_map( 'strval', (array) $used[ $attribute->get_name() ] )
						: array();

					if ( $attribute->is_taxonomy() ) {
						// Global attribute (like pa_size)
						$terms = wc_get_product_terms(
							$product->get_id(),
							$attribute->get_name(),
							array( 'fields' => 'all' )
						);

						foreach ( $terms as $term ) {
							if ( ! in_array( $term->slug, $used, true ) ) {
								continue;
							}
							$attribute_options[] = array(
								'id'   => $term->term_id,
								'name' => $term->name,
								'slug' => $term->slug,
							);
						}
					} else {
						// Custom attribute: WooCommerce compares the RAW value, and
						// get_variation_attributes() has already intersected the parent's defined
						// values with the ones variations carry.
						foreach ( $used as $option ) {
							$attribute_options[] = array(
								'id'   => 0,
								'name' => $option,
								'slug' => $option,
							);
						}
					}

					$attributes[] = array(
						'id'      => sanitize_title( $attribute->get_name() ),
						'name'    => wc_attribute_label( $attribute->get_name() ),
						'options' => $attribute_options,
					);
				}
			}
		}

		return $attributes;
	}

	/**
	 * Get cart data.
	 *
	 * @return array
	 */
	public function get_cart_data() {
		$request       = new \WP_REST_Request( 'GET', '/wc/store/cart' );
		$response      = rest_do_request( $request );
		$response_data = ( $response instanceof \WP_REST_Response ) ? $response->get_data() : array();
		return $response_data ?? array();
	}

	/**
	 * Add product to cart
	 *
	 * @param int   $product_id           Product ID.
	 * @param int   $quantity             Quantity.
	 * @param int   $variation_id         Variation ID.
	 * @param array $variation_attributes Variation attributes.
	 *
	 * @return string|false Cart item key or false on failure.
	 */
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation_attributes = array() ) {
		if ( ! $this->init_cart() ) {
			return false;
		}

		// WooCommerce reports a rejected add (out of stock, not enough left, not purchasable)
		// by queueing a notice and returning false. Clear the queue first so the message we
		// read back belongs to this request.
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		$this->last_cart_error = '';

		try {
			if ( $variation_id > 0 ) {
				$result = \WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attributes );
			} else {
				$result = \WC()->cart->add_to_cart( $product_id, $quantity );
			}
		} catch ( \Exception $e ) {
			$this->last_cart_error = $e->getMessage();
			return false;
		}

		if ( ! $result ) {
			$this->last_cart_error = $this->pull_notice_error();
		}

		return $result;
	}

	/**
	 * Read and clear the first queued WooCommerce error notice.
	 *
	 * @return string
	 */
	protected function pull_notice_error() {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return '';
		}

		$notices = wc_get_notices( 'error' );

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		if ( empty( $notices ) ) {
			return '';
		}

		$first = reset( $notices );
		$text  = is_array( $first ) ? ( $first['notice'] ?? '' ) : (string) $first;

		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Reason the last cart write failed, in WooCommerce's own words.
	 *
	 * @return string
	 */
	public function get_last_cart_error() {
		return $this->last_cart_error;
	}

	/**
	 * Remove item from cart
	 *
	 * @param string $item_key Cart item key.
	 *
	 * @return bool Success or failure.
	 */
	public function remove_from_cart( $item_key ) {
		if ( ! $this->init_cart() ) {
			return false;
		}

		try {
			return \WC()->cart->remove_cart_item( $item_key );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Update cart item quantity
	 *
	 * @param string $item_key Cart item key.
	 * @param int    $quantity New quantity.
	 *
	 * @return bool Success or failure.
	 */
	public function update_quantity( $item_key, $quantity ) {
		if ( ! $this->init_cart() ) {
			return false;
		}

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		$this->last_cart_error = '';

		try {
			$result = \WC()->cart->set_quantity( $item_key, $quantity );
		} catch ( \Exception $e ) {
			$this->last_cart_error = $e->getMessage();
			return false;
		}

		if ( ! $result ) {
			$this->last_cart_error = $this->pull_notice_error();
		}

		return $result;
	}

	/**
	 * Get product and verify it exists
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return \WC_Product|false Product object or false if not found.
	 */
	public function get_product( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		return $product;
	}

	/**
	 * Check if product is variable and requires variation ID
	 *
	 * @param \WC_Product $product Product object.
	 * @param int         $variation_id Variation ID.
	 *
	 * @return bool True if valid, false if variation required but not provided.
	 */
	public function validate_product_variation( $product, $variation_id = 0 ) {
		if ( $product->is_type( 'variable' ) && ! $variation_id ) {
			return false;
		}

		return true;
	}
}
