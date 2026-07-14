<?php
/**
 * Video Product Stats Table
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * Video Product Stats Table
 */
class Video_Product_Stats_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'vsfw_video_product_stats';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            video_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            storefront_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            views BIGINT UNSIGNED DEFAULT 0,
            add_to_cart_count BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY video_id (video_id),
            KEY product_id (product_id),
            KEY storefront_id (storefront_id)
        ) {$wpdb->get_charset_collate()};";
	}

	/**
	 * Migration: add the `storefront_id` column (+ its index) to an existing table.
	 *
	 * Idempotent — probes the live schema first, so it's safe to re-run and never touches existing rows.
	 * Counters are keyed per (video_id, product_id, storefront_id); 0 = a legacy attribute shortcode.
	 */
	public function add_storefront_id_column() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'storefront_id'" );
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD storefront_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER product_id" );
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
