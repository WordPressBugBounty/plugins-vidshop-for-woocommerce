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
            storefront_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY visitor_id (visitor_id),
            KEY user_id (user_id),
            KEY storefront_id (storefront_id),
            KEY last_activity (last_activity)
        ) {$wpdb->get_charset_collate()};";
	}

	/**
	 * Migration: add the `storefront_id` column (+ its index) to an existing table.
	 *
	 * Idempotent — probes the live schema first, so it's safe to re-run and never touches existing rows.
	 * One session == one storefront render, so this column scopes views/likes/watch-time per storefront
	 * (0 = a legacy attribute shortcode with no saved storefront).
	 */
	public function add_storefront_id_column() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'storefront_id'" );
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD storefront_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$indexes     = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$index_names = wp_list_pluck( $indexes, 'Key_name' );
		if ( ! in_array( 'storefront_id', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD KEY storefront_id (storefront_id)" );
		}
	}
}
