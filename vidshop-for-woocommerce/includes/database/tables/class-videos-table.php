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
            ai_call_id BIGINT UNSIGNED DEFAULT NULL,
            settings TEXT DEFAULT NULL,
            status VARCHAR(255) DEFAULT 'published',
            origin VARCHAR(20) NOT NULL DEFAULT 'manual',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY type (type),
            KEY status (status),
            KEY origin (origin),
            KEY created_at (created_at),
            KEY created_by (created_by),
            UNIQUE KEY ai_call_id (ai_call_id)
        ) {$wpdb->get_charset_collate()};
        ";
	}

	/**
	 * Migration: add the ai_call_id column and its UNIQUE key to an existing table.
	 *
	 * Idempotent — each step probes the live schema first, so it's safe to re-run and never touches
	 * existing rows. dbDelta reliably adds the column on a changed table but can't be trusted to add the
	 * key, so both are applied explicitly here. The UNIQUE key backs the AI-import dedup guard.
	 */
	public function add_ai_call_id_column() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'ai_call_id'" );
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD ai_call_id BIGINT UNSIGNED DEFAULT NULL AFTER video_id" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$indexes     = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$index_names = wp_list_pluck( $indexes, 'Key_name' );
		if ( ! in_array( 'ai_call_id', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY ai_call_id (ai_call_id)" );
		}
	}

	/**
	 * Migration: add the `origin` column (+ its index) to an existing table.
	 *
	 * Idempotent — probes the live schema first, so it's safe to re-run and never touches existing rows.
	 * `origin` marks how a video was created ('manual' default, 'wpcreatix_ai' for AI imports); the AI
	 * importer writes it, so an install that predates the column can't import a generated video until
	 * this runs (the "Unknown column 'origin'" import failure).
	 */
	public function add_origin_column() {
		global $wpdb;
		$table = $this->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'origin'" );
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD origin VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER status" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$indexes     = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$index_names = wp_list_pluck( $indexes, 'Key_name' );
		if ( ! in_array( 'origin', $index_names, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$table} ADD KEY origin (origin)" );
		}
	}
}
