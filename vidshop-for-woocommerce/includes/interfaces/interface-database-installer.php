<?php
/**
 * Interface for Database Installer.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Interfaces;

/**
 * Interface for Database Installer.
 */
interface Database_Installer {

	/**
	 * Install all tables.
	 *
	 * @return void
	 */
	public function install_tables();

	/**
	 * Check if all tables exist.
	 *
	 * @return bool
	 */
	public function tables_exist();

	/**
	 * Get missing tables.
	 *
	 * @return array
	 */
	public function get_missing_tables();
}
