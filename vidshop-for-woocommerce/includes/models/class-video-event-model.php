<?php
/**
 * Video Event Model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Video_Events_Table;

class Video_Event_Model extends Model {

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
		'event_type',
		'session_id',
		'video_id',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'         => 'integer',
		'session_id' => 'integer',
		'video_id'   => 'integer',
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
		return new Video_Events_Table();
	}

	/**
	 * Session relationship
	 */
	public function session() {
		return $this->belongs_to( Video_Session_Model::class, 'session_id', 'id' );
	}

	/**
	 * Get total likes count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int
	 */
	public static function get_total_likes( $start_date, $end_date, $video_id = null, $storefront_id = null ) {
		global $wpdb;

		$query = static::query()->where( 'event_type', 'like' );

		// Join with sessions table
		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );

		// Add date range condition only if dates are provided - use prepared statement
		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			/*
			 * Dated by when the event happened, not when its session opened. The chart already
			 * counted `created_at`, so a like recorded at 00:05 inside a session opened at 23:50
			 * landed on yesterday in the KPI card and today in the chart beside it — and sessions
			 * are explicitly expected to outlive their start day, since `last_activity` carries
			 * ON UPDATE CURRENT_TIMESTAMP.
			 */
			$query->where_raw( $wpdb->prepare( "{$events_table}.created_at BETWEEN %s AND %s", $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.storefront_id = %d', (int) $storefront_id ) );
		}

		// Add video filter if provided
		if ( $video_id ) {
			$query->where( 'video_id', absint( $video_id ) );
		}

		return $query->count();
	}

	/**
	 * Get per-day like counts for a date range.
	 *
	 * Dated by the event's created_at; joined to sessions for the storefront scope.
	 * Days with no likes are absent from the result — callers zero-fill.
	 *
	 * @param string $start_date    Start datetime (Y-m-d H:i:s).
	 * @param string $end_date      End datetime (Y-m-d H:i:s).
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return array Rows of { day: 'Y-m-d', likes: int }.
	 */
	public static function get_daily_likes( $start_date, $end_date, $storefront_id = null ) {
		global $wpdb;

		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query = static::query()
			->select_raw( "DATE({$events_table}.created_at) AS day, COUNT(*) AS likes" )
			->where( 'event_type', 'like' )
			->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( "{$events_table}.created_at BETWEEN %s AND %s", $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.storefront_id = %d', (int) $storefront_id ) );
		}

		return $query->group_by( 'day' )->order_by_raw( 'day ASC' )->get_raw();
	}

	/**
	 * Get per-day counts of one event type for a single video.
	 *
	 * The storefront-scoped `get_daily_views()` on the session model cannot answer this: sessions
	 * carry no `video_id` (see the note on `Video_Model::sessions()`), so a per-video day series has
	 * to be counted off the events themselves, which do carry one.
	 *
	 * Days with no events are absent from the result — callers zero-fill, so a quiet Tuesday draws
	 * a dip rather than disappearing from the axis.
	 *
	 * @param int    $video_id   Video to scope to.
	 * @param string $event_type Event type, `view` or `like`.
	 * @param string $start_date Start datetime (Y-m-d H:i:s).
	 * @param string $end_date   End datetime (Y-m-d H:i:s).
	 * @return array Rows of { day: 'Y-m-d', total: int }.
	 */
	public static function get_daily_for_video( $video_id, $event_type, $start_date, $end_date ) {
		global $wpdb;

		$query = static::query()
			->select_raw( 'DATE(created_at) AS day, COUNT(*) AS total' )
			->where( 'video_id', (int) $video_id )
			->where( 'event_type', $event_type );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'created_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		return $query->group_by( 'day' )->order_by_raw( 'day ASC' )->get_raw();
	}

	/**
	 * Count events of one type for a single video in a window.
	 *
	 * Used for the previous-period comparison behind the KPI deltas, where only the total matters.
	 *
	 * @param int    $video_id   Video to scope to.
	 * @param string $event_type Event type, `view` or `like`.
	 * @param string $start_date Start datetime (Y-m-d H:i:s).
	 * @param string $end_date   End datetime (Y-m-d H:i:s).
	 * @return int
	 */
	public static function count_for_video( $video_id, $event_type, $start_date, $end_date ) {
		global $wpdb;

		$query = static::query()
			->where( 'video_id', (int) $video_id )
			->where( 'event_type', $event_type );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'created_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		return (int) $query->count();
	}

	/**
	 * Count every event of one type in a window, optionally scoped to a feed.
	 *
	 * Counts occurrences, not sessions — two add-to-carts in one visit are two add-to-carts. Use
	 * `count_sessions_with_event()` where the question is how many shoppers reached a stage.
	 *
	 * @param string      $event_type    Event type.
	 * @param string|null $start_date    Range start (Y-m-d H:i:s), or null for all time.
	 * @param string|null $end_date      Range end (Y-m-d H:i:s), or null for all time.
	 * @param int|null    $storefront_id Optional feed scope.
	 * @return int
	 */
	public static function count_events( $event_type, $start_date = null, $end_date = null, $storefront_id = null ) {
		global $wpdb;

		$events_table = ( new static() )->get_full_table_name();

		$query = static::query()->where( 'event_type', $event_type );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( "{$events_table}.created_at BETWEEN %s AND %s", $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			$sessions_table = ( new Video_Session_Model() )->get_full_table_name();

			$query->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.storefront_id = %d', (int) $storefront_id ) );
		}

		return (int) $query->count();
	}

	/**
	 * Count the distinct sessions that recorded a given event.
	 *
	 * Used by the funnel's checkout stage: the number that matters there is how many shoppers
	 * reached checkout, not how many times the page was loaded.
	 *
	 * @param string      $event_type    Event type, e.g. `checkout_started`.
	 * @param string|null $start_date    Range start, or null for all time.
	 * @param string|null $end_date      Range end, or null for all time.
	 * @param int|null    $storefront_id Optional feed scope.
	 * @return int
	 */
	public static function count_sessions_with_event( $event_type, $start_date, $end_date, $storefront_id = null ) {
		global $wpdb;

		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query = static::query()
			->where( 'event_type', $event_type )
			->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			/*
			 * Dated by when the event happened, not when its session opened. The chart already
			 * counted `created_at`, so a like recorded at 00:05 inside a session opened at 23:50
			 * landed on yesterday in the KPI card and today in the chart beside it — and sessions
			 * are explicitly expected to outlive their start day, since `last_activity` carries
			 * ON UPDATE CURRENT_TIMESTAMP.
			 */
			$query->where_raw( $wpdb->prepare( "{$events_table}.created_at BETWEEN %s AND %s", $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.storefront_id = %d', (int) $storefront_id ) );
		}

		return (int) $query->count_distinct( "{$events_table}.session_id" );
	}

	/**
	 * Get like counts grouped by storefront.
	 *
	 * Likes are recorded against a session, so the storefront comes from the joined session row
	 * rather than the event itself.
	 *
	 * @param string $start_date Start datetime (Y-m-d H:i:s), or null for all time.
	 * @param string $end_date   End datetime (Y-m-d H:i:s), or null for all time.
	 * @return array Rows of { storefront_id: int, total: int }.
	 */
	public static function get_likes_by_storefront( $start_date, $end_date ) {
		global $wpdb;

		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query = static::query()
			->select_raw( 's.storefront_id AS storefront_id, COUNT(*) AS total' )
			->where( 'event_type', 'like' )
			->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" )
			->where_raw( 's.storefront_id > 0' );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( "{$events_table}.created_at BETWEEN %s AND %s", $start_date, $end_date ) );
		}

		return $query->group_by( 's.storefront_id' )->get_raw();
	}

	/**
	 * Get unique likes count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int
	 */
	public static function get_unique_likes( $start_date, $end_date, $video_id = null, $storefront_id = null ) {
		global $wpdb;

		$query = static::query()->where( 'event_type', 'like' );

		// Join with sessions table
		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );

		// Add date range condition only if dates are provided - use prepared statement
		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			/*
			 * Dated by when the event happened, not when its session opened. The chart already
			 * counted `created_at`, so a like recorded at 00:05 inside a session opened at 23:50
			 * landed on yesterday in the KPI card and today in the chart beside it — and sessions
			 * are explicitly expected to outlive their start day, since `last_activity` carries
			 * ON UPDATE CURRENT_TIMESTAMP.
			 */
			$query->where_raw( $wpdb->prepare( "{$events_table}.created_at BETWEEN %s AND %s", $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.storefront_id = %d', (int) $storefront_id ) );
		}

		// Add video filter if provided
		if ( $video_id ) {
			$query->where( 'video_id', absint( $video_id ) );
		}

		return $query->count_distinct( 's.visitor_id' );
	}
}
