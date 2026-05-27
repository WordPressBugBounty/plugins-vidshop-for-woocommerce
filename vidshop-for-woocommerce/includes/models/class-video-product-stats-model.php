<?php
/**
 * Video Products Stats Model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Video_Product_Stats_Table;

class Video_Product_Stats_Model extends Model {

	/**
	 * The primary key for the model
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * The attributes that are mass assignable
	 *
	 * @var array
	 */
	protected $fillable = array(
		'video_id',
		'product_id',
		'views',
		'add_to_cart_count',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'         => 'integer',
		'video_id'   => 'integer',
		'product_id' => 'integer',
	);

	/**
	 * Indicates if the model has an updated at column
	 *
	 * @var bool
	 */
	public $update_timestamp = false;

	/**
	 * Get the table instance
	 */
	protected function get_table_instance() {
		return new Video_Product_Stats_Table();
	}

	/**
	 * Increment view count for a product in a video
	 *
	 * @param int $video_id   The video ID.
	 * @param int $product_id The product ID.
	 * @return Video_Product_Stats_Model The stats model instance.
	 */
	public static function increment_view( $video_id, $product_id ) {
		$stats = static::first_or_new(
			array(
				'video_id'   => $video_id,
				'product_id' => $product_id,
			),
			array(
				'views'             => 0,
				'add_to_cart_count' => 0,
			)
		);

		$stats->views = (int) $stats->views + 1;
		$stats->save();

		return $stats;
	}

	/**
	 * Increment add to cart count for a product in a video
	 *
	 * @param int $video_id   The video ID.
	 * @param int $product_id The product ID.
	 * @return Video_Product_Stats_Model The stats model instance.
	 */
	public static function increment_add_to_cart( $video_id, $product_id ) {
		$stats = static::first_or_new(
			array(
				'video_id'   => $video_id,
				'product_id' => $product_id,
			),
			array(
				'views'             => 0,
				'add_to_cart_count' => 0,
			)
		);

		$stats->add_to_cart_count = (int) $stats->add_to_cart_count + 1;
		$stats->save();

		return $stats;
	}

	/**
	 * Get total number of views for a product
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return int The total number of views.
	 */
	public static function get_total_views( $start_date, $end_date ) {
		global $wpdb;

		$query = static::query();

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'created_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		return $query->sum( 'views' );
	}

	/**
	 * Get total number of add to cart events for a product
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return int The total number of add to cart events.
	 */
	public static function get_total_add_to_cart( $start_date, $end_date ) {
		global $wpdb;

		$query = static::query();

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'created_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		return $query->sum( 'add_to_cart_count' );
	}

	/**
	 * Get top 5 products by add to cart count
	 *
	 * @return array Array of product IDs and their add to cart counts.
	 */
	public static function get_top_added_to_cart_products() {
		// select_raw (not select) — the column sanitizer drops aggregate expressions like
		// `SUM(...) as alias`, which would leave the alias missing from SELECT and break the ORDER BY.
		return static::query()
			->select_raw( 'product_id, SUM(add_to_cart_count) AS total_add_to_cart, SUM(views) AS total_views' )
			->group_by( 'product_id' )
			->order_by( 'total_add_to_cart', 'DESC' )
			->limit( 5 )
			->get_raw();
	}

	/**
	 * Get products for a video
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   The video ID.
	 * @return array Array of product IDs and their add to cart counts.
	 */
	public static function get_products( $start_date, $end_date, $video_id ) {
		global $wpdb;

		$query = static::query();

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'created_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		return $query->where( 'video_id', '=', absint( $video_id ) )->get();
	}

	/**
	 * Get products analytics for a video (all time)
	 *
	 * @param int $video_id The video ID.
	 * @return array Array of products with their analytics data.
	 */
	public static function get_video_products_analytics( $video_id ) {
		// select_raw (not select) — aggregate expressions are stripped by the column sanitizer,
		// which would silently return product_id only (no totals). See get_top_added_to_cart_products.
		return static::query()
			->select_raw( 'product_id, SUM(views) AS total_views, SUM(add_to_cart_count) AS total_add_to_cart' )
			->where( 'video_id', '=', $video_id )
			->group_by( 'product_id' )
			->get_raw();
	}
}
