<?php
/**
 * Products controller
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\Interfaces\WooCommerce;
use VSFW\Models\Video_Product_Stats_Model;
use VSFW\Models\Video_Model;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Products controller
 */
class Products_Controller extends REST_Controller {

	/**
	 * WooCommerce service
	 *
	 * @var WooCommerce
	 */
	protected $woocommerce;

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'products';

	/**
	 * Constructor
	 *
	 * @param WooCommerce $woocommerce WooCommerce service.
	 */
	public function __construct( WooCommerce $woocommerce ) {
		$this->woocommerce = $woocommerce;
	}

	/**
	 * Register routes
	 */
	public function register_routes() {

		// Get product by ID
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
					'args'                => array(
						'video_id' => array(
							'required'    => true,
							'type'        => 'integer',
							'description' => __( 'Video ID.', 'vidshop-for-woocommerce' ),
						),
					),
				),
			)
		);

		// Add to cart
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/add-to-cart',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_to_cart' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
					'args'                => array(
						'id'           => array(
							'required'    => true,
							'type'        => 'integer',
							'description' => __( 'Product ID.', 'vidshop-for-woocommerce' ),
						),
						'quantity'     => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Quantity.', 'vidshop-for-woocommerce' ),
						),
						'variation_id' => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Variation ID.', 'vidshop-for-woocommerce' ),
						),
						'video_id'     => array(
							'required'    => true,
							'type'        => 'integer',
							'description' => __( 'Video ID.', 'vidshop-for-woocommerce' ),
						),
					),
				),
			)
		);

		// Remove from cart
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/remove-from-cart',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_from_cart' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
					'args'                => array(
						'item_key' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Item key.', 'vidshop-for-woocommerce' ),
						),
					),
				),
			)
		);

		// Update quantity
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update-quantity',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_quantity' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
					'args'                => array(
						'item_key' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Item key.', 'vidshop-for-woocommerce' ),
						),
						'quantity' => array(
							'required'    => true,
							'type'        => 'integer',
							'description' => __( 'Quantity.', 'vidshop-for-woocommerce' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Get product by ID
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$product_id = $request->get_param( 'id' );
		$video_id   = $request->get_param( 'video_id' );
		$valid      = $this->get_product_and_video( $request );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Skip the view increment for admin storefront-builder previews.
		if ( ! $request->get_param( 'preview' ) ) {
			Video_Product_Stats_Model::increment_view( $video_id, $product_id, (int) $request->get_param( 'storefront_id' ) );
		}

		return new WP_REST_Response( $this->woocommerce->prepare_product( $product_id ) );
	}

	/**
	 * Add to cart
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_to_cart( $request ) {
		$product_id           = $request->get_param( 'id' );
		$video_id             = $request->get_param( 'video_id' );
		$quantity             = $request->get_param( 'quantity' );
		$variation_id         = $request->get_param( 'variation_id' );
		$variation_attributes = $request->get_param( 'variation_attributes' );
		$data                 = $this->get_product_and_video( $request );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$product = $data['product'];

		if ( ! $this->woocommerce->validate_product_variation( $product, $variation_id ) ) {
			return new WP_Error( 'variation_id_required', __( 'Variation ID is required for variable products.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$cart_item_key = $this->woocommerce->add_to_cart( $product_id, $quantity, $variation_id, $variation_attributes );

		if ( ! $cart_item_key ) {
			return new WP_Error( 'product_not_added_to_cart', __( 'Product not added to cart.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		Video_Product_Stats_Model::increment_add_to_cart( $video_id, $product_id, (int) $request->get_param( 'storefront_id' ) );

		// Return formatted cart data
		return new WP_REST_Response(
			$this->woocommerce->get_cart_data(),
			200
		);
	}

	/**
	 * Get product and video.
	 *
	 *  @param WP_REST_Request $request Request object.
	 *
	 * @return array|WP_Error
	 */
	protected function get_product_and_video( $request ) {
		$product_id = $request->get_param( 'id' );
		$video_id   = $request->get_param( 'video_id' );
		$product    = $this->woocommerce->get_product( $product_id );
		$video      = Video_Model::find( $video_id );

		if ( ! $product ) {
			return new WP_Error( 'product_not_found', __( 'Product not found.', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( ! $video ) {
			return new WP_Error( 'video_not_found', __( 'Video not found.', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return array(
			'product' => $product,
			'video'   => $video,
		);
	}

	/**
	 * Remove from cart
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_from_cart( $request ) {
		$item_key = $request->get_param( 'item_key' );

		$result = $this->woocommerce->remove_from_cart( $item_key );

		if ( ! $result ) {
			return new WP_Error( 'product_not_removed_from_cart', __( 'Failed to remove item from cart.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $this->woocommerce->get_cart_data(), 200 );
	}

	/**
	 * Update quantity
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_quantity( $request ) {
		$item_key = $request->get_param( 'item_key' );
		$quantity = $request->get_param( 'quantity' );

		$result = $this->woocommerce->update_quantity( $item_key, $quantity );

		if ( ! $result ) {
			return new WP_Error( 'quantity_not_updated', __( 'Failed to update quantity.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $this->woocommerce->get_cart_data(), 200 );
	}
}
