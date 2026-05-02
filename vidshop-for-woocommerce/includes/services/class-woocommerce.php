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

		return array(
			'id'              => $product_id,
			'title'           => $product->get_name(),
			'price'           => (float) $product->get_price(),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'price_html'      => $this->settings->format_price( $product->get_price() ),
			'image'           => wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'full' )[0],
			'url'             => get_permalink( $product_id ),
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

		$prepared_products = array(
			'id'              => $product_id,
			'title'           => $product->get_name(),
			'description'     => $product->get_short_description(),
			'price'           => (float) $product->get_price(),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'price_html'      => $this->settings->format_price( $product->get_price() ),
			'image'           => $image_url,
			'url'             => get_permalink( $product_id ),
			'type'            => $product->get_type(),
			'attributes'      => $this->get_product_attributes( $product ),
			'variations'      => $this->get_product_variations( $product ),
			'is_in_stock'     => $product->is_in_stock(),
		);

		return $prepared_products;
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

				$variations[] = array(
					'id'          => $variation['variation_id'],
					'price'       => (float) $variation_obj->get_price(),
					'price_html'  => $this->settings->format_price( $variation_obj->get_price() ),
					'attributes'  => $variation['attributes'],
					'image'       => ! empty( $variation['image'] ) ? $variation['image']['src'] : '',
					'is_in_stock' => $variation['is_in_stock'],
					'description' => $variation_obj->get_description(),
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

					if ( $attribute->is_taxonomy() ) {
						// Global attribute (like pa_size)
						$terms = wc_get_product_terms(
							$product->get_id(),
							$attribute->get_name(),
							array( 'fields' => 'all' )
						);

						foreach ( $terms as $term ) {
							$attribute_options[] = array(
								'id'   => $term->term_id,
								'name' => $term->name,
								'slug' => $term->slug,
							);
						}
					} else {
						$options = $attribute->get_options();

						foreach ( $options as $option ) {
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

		try {
			if ( $variation_id > 0 ) {
				return \WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attributes );
			} else {
				return \WC()->cart->add_to_cart( $product_id, $quantity );
			}
		} catch ( \Exception $e ) {
			return false;
		}
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

		try {
			return \WC()->cart->set_quantity( $item_key, $quantity );
		} catch ( \Exception $e ) {
			return false;
		}
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
