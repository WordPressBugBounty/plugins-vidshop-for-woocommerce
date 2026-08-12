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
            stat_date DATE NOT NULL DEFAULT '1970-01-01',
            views BIGINT UNSIGNED DEFAULT 0,
            add_to_cart_count BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY video_id (video_id),
            KEY product_id (product_id),
            KEY storefront_id (storefront_id),
            KEY stat_date (stat_date)
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

	/**
	 * Make the natural key unique, so two concurrent first-views cannot both insert.
	 *
	 * The counters are maintained read-modify-write — find the row, add one, save — with nothing
	 * stopping two requests from reading the same value and both writing back the same increment.
	 * Worse, when neither found a row both inserted one, and from then on `first_or_new()` picked
	 * whichever came first while the other sat stranded and its total was double-counted by the
	 * SUM() reports. The add-to-cart endpoint is public and unrate-limited, so the collision is
	 * reachable from outside.
	 *
	 * Duplicates are folded into one row before the constraint goes on, otherwise the ALTER fails
	 * on any site that already has them.
	 *
	 * @return void
	 */
	public function add_unique_key() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$index_names = wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' );

		// The dated key supersedes this one, so a site that already has it is done.
		if ( in_array( 'video_product_storefront', $index_names, true )
			|| in_array( 'video_product_storefront_date', $index_names, true ) ) {
			return;
		}

		/*
		 * Merge any existing duplicates: keep the lowest id per triple and carry the others' totals
		 * onto it, so the constraint can be applied without losing counts.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"UPDATE {$table} keep
			 JOIN (
				SELECT MIN(id) AS keep_id, video_id, product_id, storefront_id,
					   SUM(views) AS all_views, SUM(add_to_cart_count) AS all_cart
				FROM {$table}
				GROUP BY video_id, product_id, storefront_id
				HAVING COUNT(*) > 1
			 ) merged ON merged.keep_id = keep.id
			 SET keep.views = merged.all_views, keep.add_to_cart_count = merged.all_cart"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE dupe FROM {$table} dupe
			 JOIN (
				SELECT MIN(id) AS keep_id, video_id, product_id, storefront_id
				FROM {$table}
				GROUP BY video_id, product_id, storefront_id
				HAVING COUNT(*) > 1
			 ) merged
			 ON dupe.video_id = merged.video_id
			 AND dupe.product_id = merged.product_id
			 AND dupe.storefront_id = merged.storefront_id
			 AND dupe.id <> merged.keep_id"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY video_product_storefront (video_id, product_id, storefront_id)" );
	}

	/**
	 * Migration: add `stat_date` and key the counters per day.
	 *
	 * One row held a pairing's whole lifetime, stamped with the day it was first seen, so dated
	 * reports read that date instead of when the activity happened and Product Opens showed 0 for
	 * any range starting later. Old rows are backfilled to their first-seen day — nothing recorded
	 * the real dates, so the history can't be split now. Idempotent.
	 *
	 * @return void
	 */
	public function add_stat_date_column() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'stat_date'" );

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD stat_date DATE NOT NULL DEFAULT '1970-01-01' AFTER storefront_id" );

			// Bank existing counters on the day their pairing was first seen.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "UPDATE {$table} SET stat_date = DATE(created_at) WHERE created_at IS NOT NULL" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$index_names = wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' );

		if ( ! in_array( 'stat_date', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD KEY stat_date (stat_date)" );
		}

		// The old three-column key has to go: it would still collapse two days onto one row.
		if ( in_array( 'video_product_storefront', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX video_product_storefront" );
		}

		if ( ! in_array( 'video_product_storefront_date', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY video_product_storefront_date (video_id, product_id, storefront_id, stat_date)" );
		}
	}
}
