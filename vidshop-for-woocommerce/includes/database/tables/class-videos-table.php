<?php
/**
 * Videos table
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * Videos table
 */
class Videos_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'vsfw_videos';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "
            CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thumbnail_id BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            type VARCHAR(255) NOT NULL,
            source_url TEXT DEFAULT NULL,
            video_id BIGINT DEFAULT NULL,
            settings TEXT DEFAULT NULL,
            status VARCHAR(255) DEFAULT 'published',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY type (type),
            KEY status (status),
            KEY created_at (created_at),
            KEY created_by (created_by)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
