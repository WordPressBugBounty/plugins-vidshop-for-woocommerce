<?php
/**
 * Videos controller
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\REST_API\V1\REST_Controller;
use VSFW\Models\Video_Model;
use VSFW\Utils\Validation_Exception;
use VSFW\Interfaces\WooCommerce;
use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Videos controller
 */
class Videos_Controller extends REST_Controller {

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'videos';

	/**
	 * WooCommerce service
	 *
	 * @var WooCommerce
	 */
	protected $woocommerce;

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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
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
					'permission_callback' => array( $this, 'check_public_permission' ),
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
							'description' => __( 'Whether to permanently delete the video or move to trash.', 'vidshop-for-woocommerce' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Add restore endpoint for trashed videos
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/restore',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'restore_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);
	}

	/**
	 * Get item schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'video',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the video.', 'vidshop-for-woocommerce' ),
					'readonly'    => true,
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'The title of the video.', 'vidshop-for-woocommerce' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'type'         => array(
					'type'        => 'enum',
					'enum'        => array( 'media_library', 'custom' ),
					'description' => __( 'The type of the video.', 'vidshop-for-woocommerce' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'source_url'   => array(
					'type'        => 'string',
					'description' => __( 'The source URL of the video.', 'vidshop-for-woocommerce' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'thumbnail_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the thumbnail of the video.', 'vidshop-for-woocommerce' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'video_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the video.', 'vidshop-for-woocommerce' ),
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'settings'     => array(
					'type'        => 'object',
					'description' => __( 'The settings of the video.', 'vidshop-for-woocommerce' ),
				),
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'The status of the video.', 'vidshop-for-woocommerce' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}

	/**
	 * Get allowed fields for select query (whitelist for SQL injection prevention)
	 *
	 * @return array
	 */
	private function get_allowed_fields() {
		return array(
			'id',
			'title',
			'type',
			'source_url',
			'thumbnail_id',
			'video_id',
			'settings',
			'status',
			'created_by',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Sanitize fields parameter - whitelist validation
	 *
	 * @param string $fields Comma-separated fields.
	 * @return string Sanitized fields.
	 */
	public function sanitize_fields_param( $fields ) {
		if ( empty( $fields ) ) {
			return '';
		}
		$requested_fields = array_map( 'trim', explode( ',', $fields ) );
		$allowed_fields   = $this->get_allowed_fields();
		$valid_fields     = array_filter(
			$requested_fields,
			function ( $field ) use ( $allowed_fields ) {
				return in_array( $field, $allowed_fields, true );
			}
		);

		return implode( ',', $valid_fields );
	}

	/**
	 * Sanitize ids parameter - ensure all values are integers
	 *
	 * @param string $ids Comma-separated IDs.
	 * @return string Sanitized IDs.
	 */
	public function sanitize_ids_param( $ids ) {
		if ( empty( $ids ) ) {
			return '';
		}

		$id_array = array_map( 'absint', explode( ',', $ids ) );
		$id_array = array_filter( $id_array ); // Remove zeros
		$id_array = array_unique( $id_array );

		return implode( ',', $id_array );
	}

	/**
	 * Get collection parameters
	 */
	public function get_collection_params() {
		$params = array(
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
			'origin'   => array(
				'description' => __( 'Filter by video origin (manual or AI-generated).', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'enum'        => array( 'manual', 'wpcreatix_ai' ),
			),
			'orderby'  => array(
				'description' => __( 'Sort collection by object attribute.', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'title', 'created_at', 'date', 'random' ),
			),
			'order'    => array(
				'description' => __( 'Order sort attribute ascending or descending.', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			'fields'   => array(
				'description'       => __( 'Comma-separated list of fields to include in the response.', 'vidshop-for-woocommerce' ),
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_fields_param' ),
			),
			'ids'      => array(
				'description'       => __( 'Comma-separated list of video IDs.', 'vidshop-for-woocommerce' ),
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_ids_param' ),
			),
		);

		/**
		 * Allow consumers (notably Pro) to declare extra collection params
		 * for the /videos list endpoint (e.g. `tags`, `tags_operator`).
		 *
		 * @param array $params Collection params.
		 */
		return apply_filters( 'vsfw_video_list_query_params', $params );
	}

	/**
	 * Get items
	 */
	public function get_items( $request ) {
		global $wpdb;

		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );
		$status   = $this->check_private_permission( $request ) ? $request->get_param( 'status' ) : 'published';
		$orderby  = $request->get_param( 'orderby' );
		$order    = $request->get_param( 'order' );
		$fields   = $request->get_param( 'fields' ); // Already sanitized via sanitize_callback
		$ids      = $request->get_param( 'ids' ); // Already sanitized via sanitize_callback

		// Map "date" alias to created_at column.
		if ( 'date' === $orderby ) {
			$orderby = 'created_at';
		}

		$query = Video_Model::query();

		// Parse sanitized IDs (already validated as integers by sanitize_callback)
		$ids_array = array();
		if ( ! empty( $ids ) ) {
			$ids_array = array_map( 'intval', explode( ',', $ids ) );
			$ids_array = array_filter( $ids_array );
			if ( ! empty( $ids_array ) ) {
				$query->where_in( 'id', $ids_array );
			}
		}

		$relations = array(
			'products' => array(
				'columns' => array( 'ID' ),
				'map'     => function ( $products ) {
					return array_filter(
						array_map(
							function ( $product ) {
								return $this->woocommerce->prepare_simple_product( $product->ID );
							},
							$products
						)
					);
				},
			),
		);

		if ( $this->check_private_permission( $request ) ) {
			$relations['user'] = array(
				'columns' => array( 'ID', 'display_name' ),
			);
		}

		// Use the enhanced with functionality to load and transform relations
		$query->with(
			$relations
		);

		if ( $search ) {
			$query->where( 'title', 'like', '%' . $search . '%' );
		}

		if ( $status ) {
			$query->where( 'status', $status );
		}

		// Origin filter (admin only) — distinguishes AI-generated videos from manual ones.
		$origin = $this->check_private_permission( $request ) ? $request->get_param( 'origin' ) : '';
		if ( $origin ) {
			$query->where( 'origin', $origin );
		}

		// Order by FIELD() for custom ID ordering - use prepared statement.
		// When explicit IDs are provided, always preserve that order so the shortcode
		// renders videos in the requested sequence.
		if ( ! empty( $ids_array ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids_array ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query->order_by_raw( $wpdb->prepare( "FIELD(id, {$placeholders})", $ids_array ) );
		} elseif ( 'random' === $orderby ) {
			$query->order_by_raw( 'RAND()' );
		} elseif ( $orderby ) {
			$query->order_by( $orderby, $order );
		}

		// Select specific fields (already validated via whitelist in sanitize_callback)
		if ( ! empty( $fields ) ) {
			$selected_fields = explode( ',', $fields );
			$query->select( $selected_fields );
		}

		/**
		 * Allow consumers (notably Pro) to attach extra constraints to the
		 * videos list query (e.g. tag-based filtering via pivot tables).
		 *
		 * @param mixed            $query   Active query builder instance.
		 * @param \WP_REST_Request $request Current REST request.
		 */
		$query = apply_filters( 'vsfw_video_list_query', $query, $request );

		$videos = $query->paginate( $per_page, $page );

		$result = $videos->to_array();

		if ( is_array( $result ) && isset( $result['data'] ) && is_array( $result['data'] ) ) {
			$result['data'] = array_map(
				function ( $video_data ) use ( $request ) {
					/**
					 * Allow consumers to mutate each video's array shape before
					 * it ships out (used by Pro to inject `tags` and `tag_ids`).
					 *
					 * @param array            $video_data Video as array.
					 * @param \WP_REST_Request $request    Current REST request.
					 */
					return apply_filters( 'vsfw_video_response_data', $video_data, $request );
				},
				$result['data']
			);
		}

		if ( $this->check_private_permission( $request ) ) {
			$result = array(
				'data'   => $result,
				'totals' => array(
					'published' => Video_Model::where( 'status', '=', 'published' )->count(),
					'draft'     => Video_Model::where( 'status', '=', 'draft' )->count(),
					'trash'     => Video_Model::where( 'status', '=', 'trash' )->count(),
				),
			);
		}

		$response = new WP_REST_Response( $result );

		/**
		 * Last-chance filter over the full list response - use sparingly.
		 *
		 * @param \WP_REST_Response $response Response about to be returned.
		 * @param \WP_REST_Request  $request  Current REST request.
		 */
		return apply_filters( 'vsfw_video_list_response', $response, $request );
	}

	/**
	 * Create item
	 */
	public function create_item( $request ) {
		try {
			$params = $request->get_params();

			// Set default values if not provided
			if ( ! isset( $params['status'] ) ) {
				$params['status'] = 'published';
			}

			$params['created_by'] = get_current_user_id();

			$video = Video_Model::create( $params );

			$video->products()->sync( $params['products'] ?? array() );

			/**
			 * Fires after a video has been created and its core relations synced.
			 *
			 * Used by Pro to sync `tag_ids` on the pivot table managed in Pro.
			 *
			 * @param Video_Model      $video   The saved video model.
			 * @param \WP_REST_Request $request Current REST request.
			 * @param string           $context 'create' or 'update'.
			 */
			do_action( 'vsfw_video_saved', $video, $request, 'create' );

			$video->refresh();

			$video->load(
				array(
					'products' => array(
						'columns' => array( 'ID' ),
						'map'     => function ( $products ) {
							return array_filter(
								array_map(
									function ( $product ) {
										return $this->woocommerce->prepare_simple_product( $product->ID );
									},
									$products
								)
							);
						},
					),
				)
			);

			$video_data = $video->to_array();

			/** This filter is documented in includes/rest-api/v1/class-videos-controller.php */
			$video_data = apply_filters( 'vsfw_video_response_data', $video_data, $request );

			return new WP_REST_Response( $video_data, 201 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'video_creation_failed',
				__( 'Validation failed', 'vidshop-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Get item
	 */
	public function get_item( $request ) {
		$id    = $request->get_param( 'id' );
		$video = Video_Model::find( $id );

		if ( ! $video ) {
			return new WP_Error( 'video_not_found', __( 'Video not found', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$woocommerce = $this->woocommerce;
		$relations   = array(
			'products' => array(
				'columns' => array( 'ID' ),
				'map'     => function ( $products ) use ( $woocommerce ) {
					return array_filter(
						array_map(
							function ( $product_data ) use ( $woocommerce ) {
								$product_id = $product_data->ID;
								$product    = wc_get_product( $product_id );
								if ( ! $product ) {
									return null;
								}
								return $woocommerce->prepare_product( $product_id );
							},
							$products
						)
					);
				},
			),
		);

		if ( $this->check_private_permission( $request ) ) {
			$relations['user'] = array(
				'columns' => array( 'ID', 'display_name' ),
			);
		}

		// Load relationships using the enhanced load method with configuration arrays
		$video->load(
			$relations
		);

		$video_data = $video->to_array();

		/** This filter is documented in includes/rest-api/v1/class-videos-controller.php */
		$video_data = apply_filters( 'vsfw_video_response_data', $video_data, $request );

		return new WP_REST_Response( $video_data, 200 );
	}

	/**
	 * Update item
	 */
	public function update_item( $request ) {
		$id    = $request->get_param( 'id' );
		$video = Video_Model::find( $id );

		if ( ! $video ) {
			return new WP_Error( 'video_not_found', __( 'Video not found', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
		}

		try {
			$params = $request->get_params();
			$video->update( $params );
			$video->products()->sync( $params['products'] ?? array() );

			/** This action is documented in includes/rest-api/v1/class-videos-controller.php */
			do_action( 'vsfw_video_saved', $video, $request, 'update' );

			$video->refresh();

			$video->load(
				array(
					'products' => array(
						'columns' => array( 'ID' ),
						'map'     => function ( $products ) {
							return array_filter(
								array_map(
									function ( $product ) {
										return $this->woocommerce->prepare_simple_product( $product->ID );
									},
									$products
								)
							);
						},
					),
				)
			);

			$video_data = $video->to_array();

			/** This filter is documented in includes/rest-api/v1/class-videos-controller.php */
			$video_data = apply_filters( 'vsfw_video_response_data', $video_data, $request );

			return new WP_REST_Response( $video_data, 200 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'video_update_failed',
				__( 'Validation failed', 'vidshop-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Delete item
	 */
	public function delete_item( $request ) {
		$id    = $request->get_param( 'id' );
		$force = $request->get_param( 'force' );
		$video = Video_Model::find( $id );

		if ( ! $video ) {
			return new WP_Error( 'video_not_found', __( 'Video not found', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( $force ) {
			// Permanently delete the video
			$video->products()->sync( array() );
			$video->products_stats()->query()->delete();
			$result = $video->delete();

			if ( $result ) {
				return new WP_REST_Response( null, 204 );
			}

			return new WP_Error( 'video_delete_failed', __( 'Failed to delete video', 'vidshop-for-woocommerce' ), array( 'status' => 500 ) );
		} else {
			// Move to trash
			try {
				$video->update( array( 'status' => 'trash' ) );
				return new WP_REST_Response( $video, 200 );
			} catch ( Validation_Exception $e ) {
				return new WP_Error(
					'video_trash_failed',
					__( 'Failed to move video to trash', 'vidshop-for-woocommerce' ),
					array(
						'status' => 500,
						'errors' => $e->errors(),
					)
				);
			}
		}
	}

	/**
	 * Restore item from trash
	 */
	public function restore_item( $request ) {
		$id    = $request->get_param( 'id' );
		$video = Video_Model::find( $id );

		if ( ! $video ) {
			return new WP_Error( 'video_not_found', __( 'Video not found', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( $video->status !== 'trash' ) {
			return new WP_Error( 'video_not_trashed', __( 'Video is not in trash', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		try {
			$video->update( array( 'status' => 'draft' ) );
			return new WP_REST_Response( $video, 200 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'video_restore_failed',
				__( 'Failed to restore video', 'vidshop-for-woocommerce' ),
				array(
					'status' => 500,
					'errors' => $e->errors(),
				)
			);
		}
	}
}
