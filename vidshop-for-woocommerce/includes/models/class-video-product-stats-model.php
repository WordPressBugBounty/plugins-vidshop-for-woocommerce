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
		'storefront_id',
		'stat_date',
		'views',
		'add_to_cart_count',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'            => 'integer',
		'video_id'      => 'integer',
		'product_id'    => 'integer',
		'storefront_id' => 'integer',
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
	 * Add one to today's counter, atomically.
	 *
	 * Read-modify-write — find the row, add one in PHP, save — loses increments under concurrency:
	 * two requests read 10 and both write 11. `INSERT … ON DUPLICATE KEY UPDATE` lets the database
	 * settle it, which it can now that the natural key is unique.
	 *
	 * The key includes the day, so a pairing gets one row per active day and dated reports can sum
	 * the days in their window. `current_time()` for both, so the day matches the site's clock — a
	 * UTC date would push evening activity onto the next day.
	 *
	 * @param string $column        `views` or `add_to_cart_count`.
	 * @param int    $video_id      The video ID.
	 * @param int    $product_id    The product ID.
	 * @param int    $storefront_id The storefront ID (0 = legacy shortcode).
	 * @return void
	 */
	protected static function bump( $column, $video_id, $product_id, $storefront_id ) {
		global $wpdb;

		// Never interpolated from a caller — the two literals below are the only values passed.
		if ( ! in_array( $column, array( 'views', 'add_to_cart_count' ), true ) ) {
			return;
		}

		$table = ( new static() )->get_full_table_name();
		$other = 'views' === $column ? 'add_to_cart_count' : 'views';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (video_id, product_id, storefront_id, stat_date, {$column}, {$other}, created_at)
				 VALUES (%d, %d, %d, %s, 1, 0, %s)
				 ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
				(int) $video_id,
				(int) $product_id,
				(int) $storefront_id,
				current_time( 'Y-m-d' ),
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Increment view count for a product in a video.
	 *
	 * @param int $video_id      The video ID.
	 * @param int $product_id    The product ID.
	 * @param int $storefront_id The storefront ID (0 = legacy shortcode).
	 * @return void
	 */
	public static function increment_view( $video_id, $product_id, $storefront_id = 0 ) {
		static::bump( 'views', $video_id, $product_id, $storefront_id );
	}

	/**
	 * Increment add-to-cart count for a product in a video.
	 *
	 * @param int $video_id      The video ID.
	 * @param int $product_id    The product ID.
	 * @param int $storefront_id The storefront ID (0 = legacy shortcode).
	 * @return void
	 */
	public static function increment_add_to_cart( $video_id, $product_id, $storefront_id = 0 ) {
		static::bump( 'add_to_cart_count', $video_id, $product_id, $storefront_id );
	}

	/**
	 * Get total number of product opens, optionally within a window.
	 *
	 * Bounded on `stat_date` (when it happened), not `created_at` (when the pairing was first seen).
	 * Filtering on the latter made Product Opens, and the Conversion Rate built on it, read 0 for
	 * any window starting after a product's first open.
	 *
	 * @param string $start_date    Start datetime (Y-m-d H:i:s), or null for all time.
	 * @param string $end_date      End datetime (Y-m-d H:i:s), or null for all time.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int The total number of views.
	 */
	public static function get_total_views( $start_date, $end_date, $storefront_id = null ) {
		global $wpdb;

		$query = static::query();

		if ( null !== $storefront_id ) {
			$query->where( 'storefront_id', (int) $storefront_id );
		}

		if ( $start_date && $end_date ) {
			// DATE() on the bounds so a window ending mid-day still includes that day.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'stat_date BETWEEN DATE(%s) AND DATE(%s)', $start_date, $end_date ) );
		}

		return $query->sum( 'views' );
	}

	/**
	 * Get total number of add to cart events for a product
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int The total number of add to cart events.
	 */
	public static function get_total_add_to_cart( $start_date, $end_date, $storefront_id = null ) {
		/*
		 * A window is counted off `vsfw_video_events`, where every add-to-cart is its own dated row
		 * all the way back. These counters are dated per day now too, but only for days recorded
		 * since that landed — older rows were backfilled to their first-seen day. All-time reads the
		 * counters, which hold the full lifetime including anything from before events existed.
		 */
		if ( $start_date && $end_date ) {
			return Video_Event_Model::count_events( 'add_to_cart', $start_date, $end_date, $storefront_id );
		}

		$query = static::query();

		if ( null !== $storefront_id ) {
			$query->where( 'storefront_id', (int) $storefront_id );
		}

		return $query->sum( 'add_to_cart_count' );
	}

	/**
	 * Get add-to-cart totals grouped by storefront.
	 *
	 * @param string $start_date Start datetime (Y-m-d H:i:s), or null for all time.
	 * @param string $end_date   End datetime (Y-m-d H:i:s), or null for all time.
	 * @return array Rows of { storefront_id: int, total: int }.
	 */
	public static function get_add_to_cart_by_storefront( $start_date, $end_date ) {
		global $wpdb;

		$query = static::query()
			->select_raw( 'storefront_id, SUM(add_to_cart_count) AS total' )
			->where_raw( 'storefront_id > 0' );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'stat_date BETWEEN DATE(%s) AND DATE(%s)', $start_date, $end_date ) );
		}

		return $query->group_by( 'storefront_id' )->get_raw();
	}

	/**
	 * Get the lifetime add-to-cart total for one video.
	 *
	 * @param int $video_id Video ID.
	 * @return int Total add-to-cart events across every product on that video.
	 */
	public static function get_add_to_cart_for_video( $video_id ) {
		return (int) static::query()->where( 'video_id', (int) $video_id )->sum( 'add_to_cart_count' );
	}

	/**
	 * Get top 5 products by add to cart count
	 *
	 * @param int $storefront_id Optional storefront ID to filter by.
	 * @return array Array of product IDs and their add to cart counts.
	 */
	public static function get_top_added_to_cart_products( $storefront_id = null ) {
		// select_raw (not select) — the column sanitizer drops aggregate expressions like
		// `SUM(...) as alias`, which would leave the alias missing from SELECT and break the ORDER BY.
		$query = static::query()
			->select_raw( 'product_id, SUM(add_to_cart_count) AS total_add_to_cart, SUM(views) AS total_views' );

		if ( null !== $storefront_id ) {
			$query->where( 'storefront_id', (int) $storefront_id );
		}

		return $query
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
			$query->where_raw( $wpdb->prepare( 'stat_date BETWEEN DATE(%s) AND DATE(%s)', $start_date, $end_date ) );
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
