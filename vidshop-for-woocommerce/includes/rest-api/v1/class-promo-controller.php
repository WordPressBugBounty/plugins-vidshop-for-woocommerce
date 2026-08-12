<?php
/**
 * Promo Controller
 *
 * Persists a per-user dismissal for the in-app cross-promo of our other plugins, so a merchant who
 * has said "no" is never shown the dashboard card again. Stored in user meta (not a cookie or
 * transient) so the choice follows the user across devices and survives cache clears.
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Promo Controller
 */
class Promo_Controller extends REST_Controller {

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'promo';

	/**
	 * User-meta key holding the in-app "Generate with AI" dashboard banner dismissal.
	 *
	 * @var string
	 */
	const AI_BANNER_DISMISSED_META = 'vsfw_ai_banner_dismissed';

	/**
	 * Prefix for the per-sibling cross-promo dismissal meta key. Each WPCreatix sibling plugin gets
	 * its own key (`vsfw_promo_dismissed_{slug}`) so dismissing one never hides the others.
	 *
	 * @var string
	 */
	const DISMISSED_META_PREFIX = 'vsfw_promo_dismissed_';

	/**
	 * WordPress.org slugs of the sibling WPCreatix plugins VidShop cross-promotes. Kept in sync with
	 * the portfolio registry in {@see \VSFW\Admin\Admin_Loader}.
	 *
	 * @var string[]
	 */
	const SIBLING_SLUGS = array( 'wpcreatix-ai-sales-agent', 'media-sweep' );

	/**
	 * Builds the per-sibling dismissal user-meta key for a given plugin slug. Shared with the admin
	 * loader so the read and the write agree on the key.
	 *
	 * @param string $slug WordPress.org plugin slug.
	 * @return string
	 */
	public static function dismissed_meta_key( $slug ) {
		return self::DISMISSED_META_PREFIX . $slug;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'dismiss' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'promo' => array(
							'type'    => 'string',
							'enum'    => array_merge( self::SIBLING_SLUGS, array( 'ai' ) ),
							'default' => 'ai',
						),
					),
				),
			)
		);
	}

	/**
	 * Marks a promo as dismissed for the current user.
	 *
	 * Accepts either a sibling plugin slug (the "More from WPCreatix" cross-promo card, persisted under
	 * its own per-slug meta key) or `ai` (the in-app "Generate with AI" dashboard banner). An unknown
	 * value is a no-op rather than an error.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function dismiss( $request ) {
		$promo = (string) $request->get_param( 'promo' );

		if ( 'ai' === $promo ) {
			update_user_meta( get_current_user_id(), self::AI_BANNER_DISMISSED_META, 1 );
		} elseif ( in_array( $promo, self::SIBLING_SLUGS, true ) ) {
			update_user_meta( get_current_user_id(), self::dismissed_meta_key( $promo ), 1 );
		}

		return new WP_REST_Response( array( 'dismissed' => true ) );
	}
}
