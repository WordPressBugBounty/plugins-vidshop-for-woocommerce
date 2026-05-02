<?php
/**
 * Video View Time Table
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * Video View Time Table
 */
class Video_View_Time_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'vsfw_video_view_time';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            video_id BIGINT UNSIGNED NOT NULL,
            seconds_viewed INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY session_id (session_id),
            KEY video_id (video_id)
        ) {$wpdb->get_charset_collate()};";
	}
}
