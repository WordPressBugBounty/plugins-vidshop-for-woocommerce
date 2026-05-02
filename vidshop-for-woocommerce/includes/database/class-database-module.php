<?php
/**
 * Database Module for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Database;

use VSFW\Interfaces\Database_Installer;

/**
 * Database module class.
 */
class Database_Module {

	/**
	 * Option name storing the DB schema version we installed against.
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
		// Register activation hook for table installation.
		register_activation_hook( VSFW_PLUGIN_FILE, array( $this, 'install_tables' ) );

		// Check for schema upgrades on load (cheap when already current).
		add_action( 'plugins_loaded', array( $this, 'maybe_install_tables' ), 20 );
	}

	/**
	 * Install database tables and stamp the current version.
	 */
	public function install_tables() {
		$this->installer->install_tables();
		update_option( self::DB_VERSION_OPTION, VSFW_VERSION, false );
	}

	/**
	 * Maybe install tables if missing, or stamp the version if outdated.
	 *
	 * Fast-path: when the stored version matches the plugin version we skip
	 * the SHOW TABLES probe entirely — this is the hot path on every
	 * request once a user is up to date.
	 */
	public function maybe_install_tables() {
		$installed = get_option( self::DB_VERSION_OPTION );

		// Hot path — up to date, nothing to do.
		if ( $installed === VSFW_VERSION ) {
			return;
		}

		// Either first boot after an update (existing users upgrading to a
		// version that adds new tables), or a fresh install where the
		// activation hook didn't run yet (e.g. must-use-style bootstrap).
		if ( ! $this->installer->tables_exist() ) {
			$this->installer->install_tables();
		}

		update_option( self::DB_VERSION_OPTION, VSFW_VERSION, false );
	}
}
