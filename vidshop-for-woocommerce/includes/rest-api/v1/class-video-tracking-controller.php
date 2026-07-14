<?php
/**
 * Video Tracking Controller
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\Models\Video_Model;
use VSFW\Models\Video_Session_Model;
use VSFW\Models\Video_Event_Model;
use VSFW\Models\Video_View_Time_Model;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Video Tracking Controller
 */
class Video_Tracking_Controller extends REST_Controller {

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'videos';

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Track batch video events and view time
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/track',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'track_videos_batch' ),
					'permission_callback' => array( $this, 'check_public_permission' ),
					'args'                => array(
						'videos'  => array(
							'required'    => true,
							'type'        => 'object',
							'description' => __( 'Object containing video data keyed by video ID.', 'vidshop-for-woocommerce' ),
						),
						'session' => array(
							'required'    => true,
							'type'        => 'object',
							'description' => __( 'Session information.', 'vidshop-for-woocommerce' ),
							'properties'  => array(
								'visitor_id' => array(
									'type'        => 'string',
									'required'    => true,
									'description' => __( 'Unique visitor ID (fingerprint).', 'vidshop-for-woocommerce' ),
								),
								'token'      => array(
									'type'        => 'string',
									'description' => __( 'Session token from previous request.', 'vidshop-for-woocommerce' ),
								),
								'storefront_id' => array(
									'type'        => 'integer',
									'description' => __( 'The saved storefront this session belongs to (0 for legacy shortcodes).', 'vidshop-for-woocommerce' ),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Track batch video events and view time
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function track_videos_batch( $request ) {
		$videos_data  = $request->get_param( 'videos' );
		$session_data = $request->get_param( 'session' );

		$visitor_id    = $session_data['visitor_id'] ?? '';
		$session_token = $session_data['token'] ?? null;
		$storefront_id = (int) ( $session_data['storefront_id'] ?? 0 );

		if ( empty( $visitor_id ) ) {
			return new WP_Error( 'missing_visitor_id', __( 'Visitor ID is required.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$session = null;
		$errors  = array();
		$user_id = null;

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
		}

		// Get or create session once per visitor (not per video)
		if ( $session_token ) {
			// Find existing session by token
			$session = Video_Session_Model::where( 'session_token', '=', $session_token )->first();
		}

		if ( ! $session ) {
			$session = Video_Session_Model::create_session( $visitor_id, $user_id, $storefront_id );
		} else {
			$session->update_activity();
		}

		// Process each video
		foreach ( $videos_data as $video_id => $video_data ) {
			$video_id      = (int) $video_id;
			$events        = $video_data['events'] ?? array();
			$watching_time = (int) ( $video_data['watching_time'] ?? 0 );

			// Validate video exists
			$video = Video_Model::find( $video_id );
			if ( ! $video ) {
				$errors[] = array(
					'video_id' => $video_id,
					'error'    => 'Video not found',
				);
				continue;
			}

			// Store events
			if ( ! empty( $events ) ) {
				foreach ( $events as $event_type ) {
					// Use firstOrCreate to prevent duplicates efficiently
					Video_Event_Model::first_or_create(
						array(
							'session_id' => $session->id,
							'video_id'   => $video_id,
							'event_type' => $event_type,
						)
					);
				}
			}

			// Update view time
			if ( $watching_time > 0 ) {
				Video_View_Time_Model::update_view_time( $session->id, $video_id, $watching_time );
			}
		}

		$response = array(
			'success' => true,
			'token'   => $session->session_token,
			'message' => __( 'Batch tracking data saved successfully.', 'vidshop-for-woocommerce' ),
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
		}

		return new WP_REST_Response( $response, 200 );
	}
}
