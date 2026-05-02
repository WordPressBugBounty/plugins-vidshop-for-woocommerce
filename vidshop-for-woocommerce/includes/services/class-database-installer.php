<?php
/**
 * Database installer service.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

use VSFW\Database\Tables\Videos_Table;
use VSFW\Database\Tables\Video_Product_Relationship_Table;
use VSFW\Database\Tables\Video_Product_Stats_Table;
use VSFW\Database\Tables\Video_Events_Table;
use VSFW\Database\Tables\Video_Sessions_Table;
use VSFW\Database\Tables\Video_View_Time_Table;
use VSFW\Interfaces\Database_Installer as Database_Installer_Interface;

/**
 * Database installer service.
 */
class Database_Installer implements Database_Installer_Interface {

	/**
	 * Tables to install.
	 *
	 * @var array
	 */
	protected $tables = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tables = array(
			new Videos_Table(),
			new Video_Product_Relationship_Table(),
			new Video_Product_Stats_Table(),
			new Video_Sessions_Table(),
			new Video_Events_Table(),
			new Video_View_Time_Table(),
		);
	}

	/**
	 * Install all tables.
	 *
	 * @return void
	 */
	public function install_tables() {
		foreach ( $this->tables as $table ) {
			$table->install();
		}
	}

	/**
	 * Check if all tables exist.
	 *
	 * @return bool
	 */
	public function tables_exist() {
		foreach ( $this->tables as $table ) {
			if ( ! $table->exists() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get missing tables.
	 *
	 * @return array
	 */
	public function get_missing_tables() {
		$missing = array();

		foreach ( $this->tables as $table ) {
			if ( ! $table->exists() ) {
				$missing[] = $table->get_full_table_name();
			}
		}

		return $missing;
	}
}
