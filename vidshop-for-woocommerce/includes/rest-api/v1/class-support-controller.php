<?php
/**
 * Support Controller
 *
 * Hands the admin the address of the hosted support form so it can be embedded in our own page.
 *
 * The address is fetched here rather than printed with the rest of the localized data because it
 * carries a timestamped signature. wp-admin loads this app once and the merchant then navigates
 * inside it, so a URL stamped at page load could be hours old by the time anyone opens Support;
 * asked for on arrival, it never is.
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Support Controller
 */
class Support_Controller extends REST_Controller {

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'support';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/form',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_form' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);
	}

	/**
	 * Returns the embeddable support form address.
	 *
	 * Empty on a free-only install, where there is no account to raise a ticket against — the admin
	 * shows the WordPress.org forum instead of an empty frame.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_form( $request ) {
		unset( $request );

		return new WP_REST_Response(
			array(
				'url' => (string) apply_filters( 'vsfw_support_form_url', '' ),
			)
		);
	}
}
