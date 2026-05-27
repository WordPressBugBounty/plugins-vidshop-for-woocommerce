<?php
/**
 * AI Controller — cloud connect + status.
 *
 * Nonce + `manage_options` guarded proxy between the wp-admin React app and the cloud
 * connection service. The browser NEVER sees the cloud token; these endpoints delegate
 * to {@see \VSFW\Services\Cloud_Connection}, which holds it server-side.
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\Services\Cloud_Connection;
use VSFW\Services\Ai_Generation_Service;
use VSFW\Services\Ai_Reconciler;
use WP_REST_Server;
use WP_REST_Response;

/**
 * AI Controller.
 */
class Ai_Controller extends REST_Controller {

	/**
	 * Cloud connection service.
	 *
	 * @var Cloud_Connection
	 */
	protected $connection;

	/**
	 * Generation service.
	 *
	 * @var Ai_Generation_Service
	 */
	protected $generation;

	/**
	 * Reconciler service.
	 *
	 * @var Ai_Reconciler
	 */
	protected $reconciler;

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'ai';

	/**
	 * Constructor.
	 *
	 * @param Cloud_Connection      $connection Cloud connection service.
	 * @param Ai_Generation_Service $generation Generation service.
	 * @param Ai_Reconciler         $reconciler Reconciler service.
	 */
	public function __construct( Cloud_Connection $connection, Ai_Generation_Service $generation, Ai_Reconciler $reconciler ) {
		$this->connection = $connection;
		$this->generation = $generation;
		$this->reconciler = $reconciler;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$private = array( $this, 'check_private_permission' );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connect/request-code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'request_code' ),
				'permission_callback' => $private,
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connect/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect_verify' ),
				'permission_callback' => $private,
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
					),
					'code'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connect/pro',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect_pro' ),
				'permission_callback' => $private,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connection',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => $private,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $private,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'options' ),
				'permission_callback' => $private,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => $private,
				'args'                => array(
					'product_id'     => array(
						'required' => true,
						'type'     => 'integer',
					),
					'extra_prompt'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'duration'       => array( 'type' => 'integer' ),
					'audio'          => array( 'type' => 'string' ),
					'template'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'allow_no_image' => array( 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/generations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'generations' ),
				'permission_callback' => $private,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/generations/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_generation' ),
				'permission_callback' => $private,
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * POST /ai/connect/request-code — send a login code to the email.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function request_code( $request ) {
		$result = $this->connection->request_code( $request->get_param( 'email' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /ai/connect/verify — verify the code, connect (free tier), store the token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function connect_verify( $request ) {
		$result = $this->connection->connect_free( $request->get_param( 'email' ), $request->get_param( 'code' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /ai/connect/pro — connect using the Freemius license (Pro).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function connect_pro( $request ) {
		$result = $this->connection->connect_pro();
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * DELETE /ai/connection — disconnect (local clear).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function disconnect( $request ) {
		$this->connection->disconnect();
		return new WP_REST_Response( array( 'connected' => false ) );
	}

	/**
	 * GET /ai/status — connection + cached usage snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function status( $request ) {
		return new WP_REST_Response( $this->connection->status_payload() );
	}

	/**
	 * GET /ai/options — generation options (durations + credit costs, audio modes).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function options( $request ) {
		$result = $this->connection->get_generation_options();
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /ai/generate — push the product image, sync, and start a generation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function generate( $request ) {
		$result = $this->generation->generate(
			array(
				'product_id'     => (int) $request->get_param( 'product_id' ),
				'extra_prompt'   => (string) $request->get_param( 'extra_prompt' ),
				'duration'       => (int) $request->get_param( 'duration' ),
				'audio'          => (string) $request->get_param( 'audio' ),
				'template'       => (string) $request->get_param( 'template' ),
				'allow_no_image' => (bool) $request->get_param( 'allow_no_image' ),
			)
		);
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	/**
	 * GET /ai/generations — run a reconcile pass, then return the in-progress + recent snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function generations( $request ) {
		$this->reconciler->reconcile();
		return new WP_REST_Response( $this->reconciler->get_snapshot() );
	}

	/**
	 * POST /ai/generations/dismiss — hide a finished/failed generation from the banner (persisted
	 * server-side, so it stays dismissed across browsers + a cleared localStorage).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function dismiss_generation( $request ) {
		$this->reconciler->dismiss( (int) $request->get_param( 'id' ) );
		return new WP_REST_Response( array( 'dismissed' => true ) );
	}
}
