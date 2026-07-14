<?php
/**
 * Storefronts table
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * Storefronts table.
 *
 * A "storefront" is a saved shortcode configuration rendered by [vidshop id="123"].
 * The full display config (video selection + presentation) lives in the `config`
 * JSON column so a single stable id can key both the shortcode and its analytics.
 */
class Storefronts_Table extends Table {

	/**
	 * Get the raw table name (without prefix).
	 *
	 * @return string
	 */
	public function get_table_name() {
		return 'vsfw_storefronts';
	}

	/**
	 * Get the CREATE TABLE schema.
	 *
	 * @return string
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "
            CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            config LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY status (status),
            KEY created_by (created_by),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
