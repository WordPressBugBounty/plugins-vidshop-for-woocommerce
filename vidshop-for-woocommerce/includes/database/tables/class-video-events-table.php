<?php
/**
 * Video events table.
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

class Video_Events_Table extends Table {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public function get_table_name() {
		return 'vsfw_video_events';
	}

	/**
	 * Get schema.
	 *
	 * @return string
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			session_id BIGINT UNSIGNED NOT NULL,
			video_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY event_type (event_type),
            KEY session_id (session_id),
            KEY video_id (video_id)
        ) {$wpdb->get_charset_collate()};";
	}
}
