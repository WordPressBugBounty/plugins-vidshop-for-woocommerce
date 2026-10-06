<?php
/**
 * Videos controller
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\REST_API\V1\REST_Controller;
use VSFW\Models\Video_Model;
use VSFW\Database\Tables\Video_Events_Table;
use VSFW\Database\Tables\Videos_Table;
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

		// Empty the trash in one request. The Trash screen's "Empty Trash" would otherwise fan out
		// one DELETE per video, which is slow and can half-finish.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/trash',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'empty_trash' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'ids' => array(
							'description'       => __( 'Comma-separated video IDs. Omit to delete every trashed video.', 'vidshop-for-woocommerce' ),
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_ids_param' ),
						),
					),
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
					'type'        => 'string',
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
	 * @param string|array $ids Comma-separated IDs, or an array of IDs (ids[]=1&ids[]=2).
	 * @return string Sanitized IDs.
	 */
	public function sanitize_ids_param( $ids ) {
		if ( empty( $ids ) ) {
			return '';
		}

		// WP hands the callback a real array for ids[]=1&ids[]=2. Flatten it back to the
		// comma-joined string the rest of this method and the caller expect. Nested values
		// become 0 so implode() cannot warn and array_filter() drops them below.
		if ( is_array( $ids ) ) {
			$ids = implode(
				',',
				array_map(
					function ( $id ) {
						return is_scalar( $id ) ? $id : 0;
					},
					$ids
				)
			);
		}

		$id_array = array_map( 'absint', explode( ',', (string) $ids ) );
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
				'description' => __( 'Limit results to those with a specific status. Use "all" for every status except the trash.', 'vidshop-for-woocommerce' ),
				'type'        => 'string',
				'enum'        => array( 'all', 'published', 'draft', 'trash' ),
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
				'enum'        => array( 'id', 'title', 'created_at', 'date', 'random', 'views' ),
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

			// Only the Trash screen asks who removed a video, so the extra join is scoped to it.
			if ( 'trash' === $status ) {
				$relations['deleter'] = array(
					'columns' => array( 'ID', 'display_name' ),
				);
			}
		}

		// Use the enhanced with functionality to load and transform relations
		$query->with(
			$relations
		);

		if ( $search ) {
			// esc_like() first: without it a merchant searching for "50%" or "a_b" gets the
			// wildcard match rather than the literal one they typed.
			$query->where( 'title', 'like', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		if ( 'all' === $status ) {
			// The library view's default. Everything the merchant still has, which is every
			// status but the trash — trashed videos have their own screen, and letting them
			// surface here would put restore-only rows among live ones.
			$query->where( 'status', '!=', 'trash' );
		} elseif ( $status ) {
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
		} elseif ( 'views' === $orderby ) {
			// View counts are not a column — they are rows in the events table. A correlated
			// subquery keeps the rest of the query (relations, pagination, Pro's tag joins)
			// untouched, which a GROUP BY would not.
			$events    = ( new Video_Events_Table() )->get_full_table_name();
			$videos_tb = ( new Videos_Table() )->get_full_table_name();
			$direction = 'asc' === $order ? 'ASC' : 'DESC';

			$query->order_by_raw(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own schema; direction is whitelisted above.
				"(SELECT COUNT(*) FROM {$events} WHERE {$events}.video_id = {$videos_tb}.id AND {$events}.event_type = 'view') {$direction}"
			);
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

		/*
		 * Count the whole page's views and likes in one query before anything is serialized. Both
		 * are appended attributes, so `to_array()` would otherwise run a COUNT per video per
		 * metric — measured at 22 of the 86 queries a single shortcode render cost for 11 videos.
		 */
		Video_Model::prime_counts( wp_list_pluck( $videos->items(), 'id' ) );

		$result = $videos->to_array();

		Video_Model::flush_counts();

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
	 * Reject a thumbnail_id that does not point at an image in the media library.
	 *
	 * `Video_Model::$rules` can only check the shape of the number — `required|integer|min:1` is
	 * happy with any positive integer, including one whose attachment was deleted years ago, which
	 * is how videos end up saved with a thumbnail that renders as nothing.
	 *
	 * This deliberately does NOT live in `$rules`. Model validation runs over the model's own
	 * attributes, so every internal write re-checks the *stored* value: trashing, restoring and the
	 * AI import all call `$video->update()`. A rule there would make any row already holding a dead
	 * attachment id permanently untrashable. Judging only what the caller sent keeps existing rows
	 * editable — they simply have to pick a real image before the next save goes through.
	 *
	 * A missing or zero id is left to the model so it keeps reporting in its own wording.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $code    Error code to report under, matching the caller's context.
	 * @return WP_Error|null WP_Error when the id is unusable, null when there is nothing to say.
	 */
	private function check_thumbnail_param( $request, $code ) {
		if ( ! $request->has_param( 'thumbnail_id' ) ) {
			return null;
		}

		$thumbnail_id = (int) $request->get_param( 'thumbnail_id' );

		if ( $thumbnail_id < 1 || wp_attachment_is_image( $thumbnail_id ) ) {
			return null;
		}

		$attachment = get_post( $thumbnail_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			$message = __( 'That thumbnail is no longer in the media library. Choose or upload another image.', 'vidshop-for-woocommerce' );
		} else {
			$message = __( 'The thumbnail has to be an image file.', 'vidshop-for-woocommerce' );
		}

		return new WP_Error(
			$code,
			'' !== $e->getMessage() ? $e->getMessage() : __( 'Validation failed', 'vidshop-for-woocommerce' ),
			array(
				'status' => 400,
				'errors' => array(
					'thumbnail_id' => array( $message ),
				),
			)
		);
	}

	/**
	 * Create item
	 */
	public function create_item( $request ) {
		$thumbnail_error = $this->check_thumbnail_param( $request, 'video_creation_failed' );

		if ( $thumbnail_error ) {
			return $thumbnail_error;
		}

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
				'' !== $e->getMessage() ? $e->getMessage() : __( 'Validation failed', 'vidshop-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => method_exists( $e, 'errors' ) ? $e->errors() : array(),
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

		$thumbnail_error = $this->check_thumbnail_param( $request, 'video_update_failed' );

		if ( $thumbnail_error ) {
			return $thumbnail_error;
		}

		try {
			$params = $request->get_params();

			/*
			 * `settings` is a JSON blob several features write into, so a caller that only knows
			 * about one key must not erase the others. Merging server-side also means a partial
			 * update cannot lose a key the client never fetched.
			 */
			if ( array_key_exists( 'settings', $params ) && is_array( $params['settings'] ) ) {
				$existing           = is_array( $video->settings ) ? $video->settings : array();
				$params['settings'] = array_merge( $existing, $params['settings'] );
			}

			$video->update( $params );

			/*
			 * Only touch the product links when the caller actually said something about them.
			 * Unconditionally syncing meant any PUT that omitted `products` — a rename, a status
			 * change, anything partial — silently detached every product and left the video
			 * un-shoppable with no error.
			 */
			if ( array_key_exists( 'products', $params ) ) {
				$video->products()->sync( (array) $params['products'] );
			}

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
				'' !== $e->getMessage() ? $e->getMessage() : __( 'Validation failed', 'vidshop-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => method_exists( $e, 'errors' ) ? $e->errors() : array(),
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
			self::purge_analytics( $video->id );
			$result = $video->delete();

			if ( $result ) {
				return new WP_REST_Response( null, 204 );
			}

			return new WP_Error( 'video_delete_failed', __( 'Failed to delete video', 'vidshop-for-woocommerce' ), array( 'status' => 500 ) );
		} else {
			// Move to trash, stamping who did it and when — the Trash screen reports both, and
			// `updated_at` moves on every later write so it cannot answer either question.
			try {
				$video->update(
					array(
						'status'     => 'trash',
						'deleted_at' => current_time( 'mysql' ),
						'deleted_by' => get_current_user_id(),
					)
				);
				return new WP_REST_Response( $video, 200 );
			} catch ( Validation_Exception $e ) {
				return new WP_Error(
					'video_trash_failed',
					__( 'Failed to move video to trash', 'vidshop-for-woocommerce' ),
					array(
						'status' => 500,
						'errors' => method_exists( $e, 'errors' ) ? $e->errors() : array(),
					)
				);
			}
		}
	}

	/**
	 * Permanently delete trashed videos.
	 *
	 * With `ids` it deletes that subset; without, it empties the trash entirely. Only rows already
	 * in the trash are touched — a stray id pointing at a live video is ignored rather than
	 * silently destroying it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function empty_trash( $request ) {
		$ids   = $request->get_param( 'ids' );
		$query = Video_Model::query()->where( 'status', 'trash' );

		if ( ! empty( $ids ) ) {
			$id_list = array_filter( array_map( 'absint', explode( ',', $ids ) ) );

			if ( empty( $id_list ) ) {
				return new WP_REST_Response( array( 'deleted' => 0 ), 200 );
			}

			$query->where_in( 'id', $id_list );
		}

		$deleted = 0;

		foreach ( $query->get() as $video ) {
			$video->products()->sync( array() );
			$video->products_stats()->query()->delete();
			self::purge_analytics( $video->id );

			if ( $video->delete() ) {
				$deleted++;
			}
		}

		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * Remove the analytics a permanently deleted video leaves behind.
	 *
	 * `vsfw_video_events` and `vsfw_video_view_time` both carry `video_id`, and neither was being
	 * cleared — so the likes, views and checkout counts of a deleted video kept feeding every
	 * site-wide figure, and the rows accumulated forever.
	 *
	 * The action lets Pro drop its tag pivot in the same breath; it fires before the row goes, so a
	 * listener can still read the video.
	 *
	 * @param int $video_id Video being deleted.
	 * @return void
	 */
	protected static function purge_analytics( $video_id ) {
		global $wpdb;

		$video_id = (int) $video_id;

		foreach ( array( 'vsfw_video_events', 'vsfw_video_view_time' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'video_id' => $video_id ), array( '%d' ) );
		}

		/**
		 * Fires before a video row is permanently deleted, once its own analytics are gone.
		 *
		 * @param int $video_id The video being deleted.
		 */
		do_action( 'vsfw_video_permanently_deleted', $video_id );
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
			// Restoring clears the deletion stamp — the row is live again, so "deleted on" would
			// be stale the moment it comes back.
			$video->update(
				array(
					'status'     => 'draft',
					'deleted_at' => null,
					'deleted_by' => null,
				)
			);
			return new WP_REST_Response( $video, 200 );
		} catch ( Validation_Exception $e ) {
			return new WP_Error(
				'video_restore_failed',
				__( 'Failed to restore video', 'vidshop-for-woocommerce' ),
				array(
					'status' => 500,
					'errors' => method_exists( $e, 'errors' ) ? $e->errors() : array(),
				)
			);
		}
	}
}
