<?php
/**
 * AI generations table
 *
 * Tracks each cloud AI video generation this site requested: the cloud `ai_call_id`,
 * the WooCommerce product it was made for, its lifecycle status, and (once finished)
 * the local `vsfw_videos` row it was imported into. Powers the global AI progress
 * banner and makes import idempotent.
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * AI generations table
 */
class Ai_Generations_Table extends Table {

	/**
	 * Get table name
	 *
	 * @return string
	 */
	public function get_table_name() {
		return 'vsfw_ai_generations';
	}

	/**
	 * Get schema
	 *
	 * Status lifecycle: pending → processing → importing → imported | failed.
	 * `ai_call_id` is UNIQUE so a poll race can never import the same cloud video twice.
	 * `checked_at` is the poll throttle key (skip rows checked within the throttle window).
	 *
	 * @return string
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "
            CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ai_call_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            video_id BIGINT DEFAULT NULL,
            duration_s INT DEFAULT NULL,
            estimated_seconds INT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            checked_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY ai_call_id (ai_call_id),
            KEY status (status),
            KEY product_id (product_id),
            KEY created_by (created_by)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
