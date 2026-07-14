<?php
/**
 * Database Module for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Database;

use VSFW\Interfaces\Database_Installer;
use VSFW\Database\Tables\Videos_Table;
use VSFW\Database\Tables\Ai_Generations_Table;
use VSFW\Database\Tables\Storefronts_Table;
use VSFW\Database\Tables\Video_Sessions_Table;
use VSFW\Database\Tables\Video_Product_Stats_Table;

/**
 * Database module class.
 */
class Database_Module {

	/**
	 * Option storing the plugin version (VSFW_VERSION) the site's DB schema was last brought up to.
	 * Compared against the running VSFW_VERSION on load to decide what to run; stamped to VSFW_VERSION
	 * afterwards. Absent on pre-versioning installs — treated as "needs the full install".
	 */
	const DB_VERSION_OPTION = 'vsfw_db_version';

	/**
	 * Database installer.
	 *
	 * @var Database_Installer
	 */
	private $installer;

	/**
	 * Constructor.
	 *
	 * @param Database_Installer $installer Database installer.
	 */
	public function __construct( Database_Installer $installer ) {
		$this->installer = $installer;
		$this->init();
	}

	/**
	 * Initialize the database module.
	 */
	private function init() {
		// Fresh install: create every table at the current schema.
		register_activation_hook( VSFW_PLUGIN_FILE, array( $this, 'install_tables' ) );

		// Existing installs: bring the schema up to the running plugin version on load (the activation
		// hook does NOT fire on a plugin update, so this is how schema changes reach users who upgrade).
		add_action( 'plugins_loaded', array( $this, 'maybe_install_tables' ), 20 );
	}

	/**
	 * Fresh install (activation only): create every table at the CURRENT schema, then stamp the version.
	 *
	 * `dbDelta` builds each table complete — all columns + keys — so a fresh DB needs no migrations after
	 * this. The per-version ALTER steps in {@see maybe_install_tables()} are for EXISTING installs only;
	 * running them here would be redundant (the column/key they add already exists on a fresh table).
	 */
	public function install_tables() {
		$this->installer->install_tables();
		update_option( self::DB_VERSION_OPTION, VSFW_VERSION, false );
	}

	/**
	 * On load, bring an existing install's schema up to the running plugin version, then stamp it.
	 *
	 * Keyed off the PLUGIN version (VSFW_VERSION) stored in {@see DB_VERSION_OPTION}:
	 *   - already at/ahead of VSFW_VERSION → nothing to do;
	 *   - NO stamp ('0' — a pre-versioning or partial install) → we can't know what exists, so install
	 *     the full table set (idempotent dbDelta only creates what's missing);
	 *   - then apply the targeted, idempotent deltas, each gated by the plugin version that introduced
	 *     it (independent `if`s, so they're cumulative — a very old install runs all of them). A no-stamp
	 *     install runs them too: they add the bits dbDelta can't be trusted with (e.g. the ai_call_id
	 *     UNIQUE key) and are no-ops where already applied.
	 *
	 * This is the ONLY path that reaches users who UPDATED the plugin — WordPress does not fire the
	 * activation hook on an update — so a newly-added TABLE must be ensured here too (via the table's own
	 * idempotent `install()`), not just column ALTERs, or it never lands on updated installs.
	 */
	public function maybe_install_tables() {
		// '0' = never stamped (pre-versioning). Lower than any real plugin version, so the migrations run.
		$installed = get_option( self::DB_VERSION_OPTION, '0' );

		// Hot path — schema already at (or ahead of) the running plugin version.
		if ( version_compare( $installed, VSFW_VERSION, '>=' ) ) {
			return;
		}

		// No stamp: we don't know which tables exist → build the full set (idempotent).
		if ( '0' === $installed ) {
			$this->installer->install_tables();
		}

		// 1.3.0 — the AI feature added two columns to vsfw_videos (origin, ai_call_id) and the
		// vsfw_ai_generations table. Each delta is explicit + idempotent: a new column = the table's own
		// add_*_column() ALTER; a new table = its own install(). (Missing `origin` here broke AI imports.)
		if ( version_compare( $installed, '1.3.0', '<' ) ) {
			$videos = new Videos_Table();
			$videos->add_origin_column();
			$videos->add_ai_call_id_column();
			( new Ai_Generations_Table() )->install();
		}

		// 1.4.0 — vsfw_ai_generations gains failure_code + failure_reason so the banner can branch
		// the merchant message on the cloud's typed failure code (soft "content_blocked" vs firm
		// "content_blocked_all_models" when every model rejected). Without these columns the cloud
		// keeps reporting the codes but the plugin has nowhere to store them.
		if ( version_compare( $installed, '1.4.0', '<' ) ) {
			( new Ai_Generations_Table() )->add_failure_code_columns();
		}

		// 1.5.0 — the Storefronts feature adds the vsfw_storefronts table (saved shortcode
		// configs rendered by [vidshop id="123"]). A new TABLE must be ensured here via its own
		// idempotent install() so installs that UPDATED the plugin get it (the activation hook
		// doesn't fire on update); dbDelta only creates it when missing. Per-storefront analytics
		// also add a storefront_id column to the sessions + product-stats tables.
		if ( version_compare( $installed, '1.5.0', '<' ) ) {
			( new Storefronts_Table() )->install();
			( new Video_Sessions_Table() )->add_storefront_id_column();
			( new Video_Product_Stats_Table() )->add_storefront_id_column();
		}

		update_option( self::DB_VERSION_OPTION, VSFW_VERSION, false );
	}
}
