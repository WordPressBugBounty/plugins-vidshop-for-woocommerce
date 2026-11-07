<?php
/**
 * WPCreatix Bridge wiring: feature counters and the server-rendered newsletter card and data sharing panel.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Bridge;

use VSFW\Models\Video_Model;
use VSFW\Models\Storefront_Model;
use VSFW\Models\Ai_Generation_Model;

/**
 * Connects VidShop to the bundled bridge client in lib/bridge-client/.
 */
class Bridge_Module {

	/**
	 * Product slug registered with the bridge client.
	 *
	 * @var string
	 */
	const SLUG = 'vidshop-for-woocommerce';

	/**
	 * Id of the hidden holder the admin app adopts the newsletter card and panel from.
	 *
	 * @var string
	 */
	const PARK_ID = 'vsfw-bridge-park';

	/**
	 * Hook the feature counters (loaded on every request so cron reports see them).
	 */
	public function __construct() {
		add_filter( 'wpcreatix_bridge_client_features', array( $this, 'features' ), 10, 2 );
		add_filter( 'wpcreatix_bridge_client_ask', array( $this, 'ask' ), 10, 2 );
	}

	/**
	 * Never show the opt-in screen when VidShop Pro is active; Pro users already answered through Freemius.
	 *
	 * @param bool   $ask  Whether to ask.
	 * @param string $slug Product slug.
	 * @return bool
	 */
	public function ask( $ask, $slug ) {
		return self::SLUG === $slug && defined( 'VIDSHOP_PRO_VERSION' ) ? false : $ask;
	}

	/**
	 * Feature counters for our slug; the client buckets the numbers.
	 *
	 * @param array<string,int> $features Counters so far.
	 * @param string            $slug     Product slug being reported.
	 * @return array<string,int>
	 */
	public function features( $features, $slug ) {
		if ( self::SLUG !== $slug ) {
			return $features;
		}

		$features = is_array( $features ) ? $features : array();

		$features['videos']      = self::safe_count( array( Video_Model::class, 'count' ) );
		$features['storefronts'] = self::safe_count( array( Storefront_Model::class, 'count' ) );
		$features['generations'] = self::safe_count( array( Ai_Generation_Model::class, 'count' ) );

		return $features;
	}

	/**
	 * Print the newsletter card and the data sharing panel in a hidden holder for the admin app to move into place.
	 *
	 * @return void
	 */
	public static function render_park() {
		echo '<div id="' . esc_attr( self::PARK_ID ) . '" hidden>';
		do_action( 'wpcreatix_bridge_client_newsletter', self::SLUG );
		do_action( 'wpcreatix_bridge_client_settings', self::SLUG );
		echo '</div>';
	}

	/**
	 * Run a count callback, returning 0 when the table is missing or the query fails.
	 *
	 * @param callable $counter Count callback.
	 * @return int
	 */
	private static function safe_count( $counter ) {
		try {
			return max( 0, (int) call_user_func( $counter ) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
