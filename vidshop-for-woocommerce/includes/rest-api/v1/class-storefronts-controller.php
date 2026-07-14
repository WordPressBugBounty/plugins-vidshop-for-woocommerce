<?php
/**
 * Storefronts controller
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\REST_API\V1\REST_Controller;
use VSFW\Models\Storefront_Model;
use VSFW\Utils\Validation_Exception;
use WP_REST_Server;
use WP_Error;
use WP_REST_Response;

/**
 * Storefronts controller.
 *
 * CRUD (+ duplicate) for saved shortcode configurations. All routes are
 * admin-only ("manage_options"); storefronts are authored, never public.
 */
class Storefronts_Controller extends REST_Controller {

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'storefronts';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Whether to permanently delete the storefront or move it to trash.', 'vidshop-for-woocommerce' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);
	}

	/**
	 * Item schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'storefront',
			'type'       => 'object',
			'properties' => array(
				'id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the storefront.', 'vidshop-for-woocommerce' ),
					'readonly'    => true,
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'The admin-facing name of the storefront.', 'vidshop-for-woocommerce' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'config' => array(
					'type'        => 'object',
					'description' => __( 'The storefront display configuration (video selection + presentation).', 'vidshop-for-woocommerce' ),
					'required'    => true,
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'The status of the storefront.', 'vidshop-for-woocommerce' ),
					'enum'        => array( 'published', 'draft', 'trash' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}

	/**
	 * Collection parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'vidshop-for-woocommerce' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'minimum'           => 1,
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'vidshop-for-woocommerce' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'description'       => __( 'Limit results to those matching a string.', 'vidshop-for-woocommerce' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'description' => __( 'Limit results to those with a specific status.', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'enum'        => array( 'published', 'draft', 'trash' ),
			),
			'orderby'  => array(
				'description' => __( 'Sort collection by object attribute.', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'default'     => 'created_at',
				'enum'        => array( 'id', 'name', 'created_at', 'updated_at' ),
			),
			'order'    => array(
				'description' => __( 'Order sort attribute ascending or descending.', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
		);
	}

	/**
	 * Get a paginated list of storefronts.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );
		$status   = $request->get_param( 'status' );
		$orderby  = $request->get_param( 'orderby' );
		$order    = 'asc' === $request->get_param( 'order' ) ? 'asc' : 'desc';

		$query = Storefront_Model::query();

		if ( $search ) {
			$query->where( 'name', 'like', '%' . $search . '%' );
		}

		if ( $status ) {
			$query->where( 'status', $status );
		} else {
			// Default view hides trashed storefronts.
			$query->where( 'status', '!=', 'trash' );
		}

		$orderby = in_array( $orderby, array( 'id', 'name', 'created_at', 'updated_at' ), true ) ? $orderby : 'created_at';
		$query->order_by( $orderby, $order );

		$paginator = $query->paginate( $per_page, $page );
		$result    = $paginator->to_array();

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			$result['data'] = array_map( array( $this, 'decode_config_field' ), $result['data'] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Create a storefront.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$storefront = Storefront_Model::create(
				array(
					'name'       => sanitize_text_field( (string) $request->get_param( 'name' ) ),
					'config'     => wp_json_encode( $this->sanitize_config( $request->get_param( 'config' ) ) ),
					'status'     => $this->sanitize_status( $request->get_param( 'status' ) ),
					'created_by' => get_current_user_id(),
				)
			);

			return new WP_REST_Response( $this->prepare_response( $storefront ), 201 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'storefront_creation_failed',
				__( 'Validation failed', 'vidshop-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Get a single storefront.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$storefront = Storefront_Model::find( $request->get_param( 'id' ) );

		if ( ! $storefront ) {
			return $this->not_found_error();
		}

		return new WP_REST_Response( $this->prepare_response( $storefront ), 200 );
	}

	/**
	 * Update a storefront.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$storefront = Storefront_Model::find( $request->get_param( 'id' ) );

		if ( ! $storefront ) {
			return $this->not_found_error();
		}

		$attributes = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$attributes['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}

		if ( null !== $request->get_param( 'config' ) ) {
			$attributes['config'] = wp_json_encode( $this->sanitize_config( $request->get_param( 'config' ) ) );
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$attributes['status'] = $this->sanitize_status( $request->get_param( 'status' ) );
		}

		try {
			$storefront->update( $attributes );

			return new WP_REST_Response( $this->prepare_response( $storefront ), 200 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'storefront_update_failed',
				__( 'Validation failed', 'vidshop-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Delete a storefront (trash or permanent).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$storefront = Storefront_Model::find( $request->get_param( 'id' ) );

		if ( ! $storefront ) {
			return $this->not_found_error();
		}

		if ( $request->get_param( 'force' ) ) {
			$result = $storefront->delete();

			if ( $result ) {
				return new WP_REST_Response( null, 204 );
			}

			return new WP_Error( 'storefront_delete_failed', __( 'Failed to delete storefront', 'vidshop-for-woocommerce' ), array( 'status' => 500 ) );
		}

		try {
			$storefront->update( array( 'status' => 'trash' ) );

			return new WP_REST_Response( $this->prepare_response( $storefront ), 200 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'storefront_trash_failed',
				__( 'Failed to move storefront to trash', 'vidshop-for-woocommerce' ),
				array(
					'status' => 500,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Duplicate a storefront.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ) {
		$storefront = Storefront_Model::find( $request->get_param( 'id' ) );

		if ( ! $storefront ) {
			return $this->not_found_error();
		}

		try {
			$copy = Storefront_Model::create(
				array(
					/* translators: %s is the original storefront name. */
					'name'       => sanitize_text_field( sprintf( __( '%s (copy)', 'vidshop-for-woocommerce' ), $storefront->name ) ),
					'config'     => wp_json_encode( $storefront->get_config_array() ),
					'status'     => 'published',
					'created_by' => get_current_user_id(),
				)
			);

			return new WP_REST_Response( $this->prepare_response( $copy ), 201 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'storefront_duplicate_failed',
				__( 'Failed to duplicate storefront', 'vidshop-for-woocommerce' ),
				array(
					'status' => 500,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Build the response array for a storefront, decoding its config to an object.
	 *
	 * @param Storefront_Model $storefront Storefront model.
	 * @return array
	 */
	private function prepare_response( $storefront ) {
		$data           = $storefront->to_array();
		$data['config'] = $storefront->get_config_array();

		return $data;
	}

	/**
	 * Decode the `config` field on an already-arrayed storefront row (list responses).
	 *
	 * @param array $item Storefront row as an array.
	 * @return array
	 */
	private function decode_config_field( $item ) {
		if ( isset( $item['config'] ) && is_string( $item['config'] ) ) {
			$decoded        = json_decode( $item['config'], true );
			$item['config'] = is_array( $decoded ) ? $decoded : array();
		}

		return $item;
	}

	/**
	 * Sanitize a submitted status, defaulting to "published".
	 *
	 * @param mixed $status Raw status.
	 * @return string
	 */
	private function sanitize_status( $status ) {
		return in_array( $status, array( 'published', 'draft', 'trash' ), true ) ? $status : 'published';
	}

	/**
	 * Not-found error response.
	 *
	 * @return WP_Error
	 */
	private function not_found_error() {
		return new WP_Error( 'storefront_not_found', __( 'Storefront not found', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
	}

	/**
	 * Sanitize the storefront config against a known key allow-list.
	 *
	 * Coerces every field to a safe type/value so arbitrary JSON is never stored.
	 * Keeps both halves of the config: selection (resolved to videos at render)
	 * and presentation (passed into the frontend data blob).
	 *
	 * @param mixed $config Raw config from the request.
	 * @return array
	 */
	private function sanitize_config( $config ) {
		$config = is_array( $config ) ? $config : array();

		$columns = isset( $config['columns'] ) && is_array( $config['columns'] ) ? $config['columns'] : array();

		// Return the value if it's in the allow-list, else the default (evaluated once,
		// so a missing key never triggers an "undefined array key" notice).
		$pick = static function ( $value, $allowed, $default ) {
			return in_array( $value, $allowed, true ) ? $value : $default;
		};

		return array(
			'schema'                    => 1,

			// Selection.
			'video_selection'           => $pick( $config['video_selection'] ?? 'all', array( 'all', 'specific' ), 'all' ),
			'video_ids'                 => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $config['video_ids'] ?? array() ) ) ) ) ),
			'orderby'                   => $pick( $config['orderby'] ?? 'date', array( 'date', 'title', 'id', 'random' ), 'date' ),
			'order'                     => 'asc' === strtolower( (string) ( $config['order'] ?? 'desc' ) ) ? 'asc' : 'desc',
			'tags'                      => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $config['tags'] ?? array() ) ) ) ) ),
			'tags_operator'             => 'AND' === strtoupper( (string) ( $config['tags_operator'] ?? 'OR' ) ) ? 'AND' : 'OR',

			// Presentation.
			'layout'                    => $pick( $config['layout'] ?? 'grid', array( 'grid', 'carousel', 'inline', 'stories' ), 'grid' ),
			'color_schema'              => sanitize_hex_color( (string) ( $config['color_schema'] ?? '' ) ) ?: '#1e40af',
			'columns'                   => array(
				'desktop' => min( 6, max( 1, absint( $columns['desktop'] ?? 4 ) ) ),
				'tablet'  => min( 6, max( 1, absint( $columns['tablet'] ?? 3 ) ) ),
				'mobile'  => min( 6, max( 1, absint( $columns['mobile'] ?? 2 ) ) ),
			),
			'autoplay'                  => ! empty( $config['autoplay'] ),
			'loop'                      => ! empty( $config['loop'] ),
			'play_on_hover'             => ! empty( $config['play_on_hover'] ),
			'show_arrows'               => array_key_exists( 'show_arrows', $config ) ? ! empty( $config['show_arrows'] ) : true,
			'show_dots'                 => array_key_exists( 'show_dots', $config ) ? ! empty( $config['show_dots'] ) : true,
			'show_views'                => array_key_exists( 'show_views', $config ) ? ! empty( $config['show_views'] ) : true,
			'show_likes'                => array_key_exists( 'show_likes', $config ) ? ! empty( $config['show_likes'] ) : true,
			'auto_open_product_details' => ! empty( $config['auto_open_product_details'] ),
			'add_to_cart_action'        => $pick( $config['add_to_cart_action'] ?? 'modal', array( 'modal', 'link' ), 'modal' ),
			'disable_add_to_cart_icon'  => ! empty( $config['disable_add_to_cart_icon'] ),
			'disable_add_to_cart_text'  => ! empty( $config['disable_add_to_cart_text'] ),
			'post_add_to_cart_action'   => $pick( $config['post_add_to_cart_action'] ?? 'open_cart', array( 'open_cart', 'none', 'redirect_checkout', 'custom_url' ), 'open_cart' ),
			'post_add_to_cart_url'      => esc_url_raw( trim( (string) ( $config['post_add_to_cart_url'] ?? '' ) ) ),
		);
	}
}
