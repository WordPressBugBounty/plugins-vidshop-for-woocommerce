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

	/**
	 * One composite index for the way this table is actually queried.
	 *
	 * `video_id`, `event_type` and `created_at` are filtered together by every per-video series and
	 * count, but existed only as three separate single-column indexes — MySQL picks one and filters
	 * the rest by scanning. `created_at` had no index at all, which is what the daily charts range
	 * over.
	 *
	 * @return void
	 */
	public function add_analytics_indexes() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$index_names = wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' );

		if ( ! in_array( 'video_type_created', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX video_type_created (video_id, event_type, created_at)" );
		}
	}
}
