<?php
/**
 * Video product relationship table
 *
 * @package VidShop
 */

namespace VSFW\Database\Tables;

use VSFW\Abstracts\Table;

/**
 * Video product relationship table
 */
class Video_Product_Relationship_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'vsfw_video_product_relationship';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "CREATE TABLE {$table} (
                video_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (video_id, product_id),
                KEY product_id (product_id),
                KEY video_id (video_id)
            ) {$wpdb->get_charset_collate()};
        ";
	}
}
