<?php
/**
 * Video Sessions Table
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * Video Sessions Table
 */
class Video_Sessions_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'vsfw_video_sessions';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			session_token VARCHAR(64) UNIQUE NOT NULL,
            visitor_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY visitor_id (visitor_id),
            KEY user_id (user_id),
            KEY last_activity (last_activity)
        ) {$wpdb->get_charset_collate()};";
	}
}
