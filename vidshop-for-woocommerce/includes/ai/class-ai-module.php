<?php
/**
 * AI module — cron wiring for the generation reconciler.
 *
 * Registers the custom 1-minute cron interval, the `vsfw_ai_reconcile` handler (which runs the
 * shared reconcile pass so finished videos import even with no admin tab open), and clears the
 * schedule on deactivation. Loaded on every request: the cron interval must exist before a
 * generation schedules the event, and the handler must be hooked when wp-cron fires.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\AI;

use VSFW\Services\Ai_Reconciler;
use VSFW\Services\Cloud_Connection;

/**
 * AI module.
 */
class Ai_Module {

	/**
	 * Reconciler service.
	 *
	 * @var Ai_Reconciler
	 */
	private $reconciler;

	/**
	 * Cloud connection service.
	 *
	 * @var Cloud_Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Ai_Reconciler    $reconciler Reconciler service.
	 * @param Cloud_Connection $connection Cloud connection service.
	 */
	public function __construct( Ai_Reconciler $reconciler, Cloud_Connection $connection ) {
		$this->reconciler = $reconciler;
		$this->connection = $connection;

		add_filter( 'cron_schedules', array( Ai_Reconciler::class, 'add_cron_interval' ) );
		add_action( Ai_Reconciler::CRON_HOOK, array( $this->reconciler, 'reconcile' ) );
		add_action( 'vsfw_pro_license_activated', array( $this, 'on_pro_license_activated' ) );

		// Let the Pro add-on cheaply check whether the cloud connection is already Pro, so its
		// admin-load license-exchange catch-up can no-op once we're connected. The Pro plugin owns the
		// license truth; the free plugin owns the connection state — this filter bridges the two.
		add_filter( 'vsfw_cloud_is_pro_connected', array( $this, 'is_pro_connected' ) );

		if ( defined( 'VSFW_PLUGIN_FILE' ) ) {
			register_deactivation_hook( VSFW_PLUGIN_FILE, array( Ai_Reconciler::class, 'unschedule' ) );
		}
	}

	/**
	 * Exchange the now-active VidShop Pro license for a Pro cloud connection (free→Pro, in place).
	 * Result is best-effort: a transient failure (e.g. the cloud hasn't synced the new subscription
	 * yet) just leaves the connection as-is; the user can retry from the Settings card.
	 *
	 * @return void
	 */
	public function on_pro_license_activated() {
		if ( $this->is_pro_connected() ) {
			return; // already a Pro connection — nothing to exchange.
		}
		$this->connection->connect_pro();
	}

	/**
	 * Whether the stored cloud connection is already on the Pro plan.
	 *
	 * Doubles as the `vsfw_cloud_is_pro_connected` filter callback (so the Pro add-on can skip a
	 * redundant exchange signal) and as this module's own idempotency guard. State comes from the
	 * stored connection, not the incoming filter value.
	 *
	 * @param bool $value Incoming filter value (ignored).
	 * @return bool
	 */
	public function is_pro_connected( $value = false ) {
		$conn = $this->connection->get();
		return ! empty( $conn['plan'] ) && 'pro' === $conn['plan'];
	}
}
