<?php
/**
 * Video Session Model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Video_Sessions_Table;

/**
 * Video Session Model
 */
class Video_Session_Model extends Model {

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
		'session_token',
		'visitor_id',
		'user_id',
		'storefront_id',
		'started_at',
		'last_activity',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'            => 'integer',
		'user_id'       => 'integer',
		'storefront_id' => 'integer',
	);

	/**
	 * Indicates if the model has an updated at column
	 *
	 * @var bool
	 */
	public $timestamps = false;

	/**
	 * Get the table instance
	 */
	protected function get_table_instance() {
		return new Video_Sessions_Table();
	}

	/**
	 * Generate a unique session token
	 *
	 * @return string
	 */
	public static function generate_session_token() {
		return 'vs_' . wp_generate_uuid4();
	}

	/**
	 * Create or get existing session
	 *
	 * @param string $visitor_id    The visitor ID.
	 * @param int    $user_id       The user ID (optional).
	 * @param int    $storefront_id The storefront the session belongs to (0 = legacy shortcode).
	 * @return Video_Session_Model The session model instance.
	 */
	public static function create_session( $visitor_id, $user_id = null, $storefront_id = 0 ) {
		$session_token = self::generate_session_token();

		return static::create(
			array(
				'session_token' => $session_token,
				'visitor_id'    => $visitor_id,
				'user_id'       => $user_id,
				'storefront_id' => (int) $storefront_id,
				'started_at'    => current_time( 'mysql' ),
				'last_activity' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Update session activity
	 *
	 * @return bool
	 */
	public function update_activity() {
		$this->last_activity = current_time( 'mysql' );
		return $this->save();
	}

	/**
	 * User relationship
	 */
	public function user() {
		return $this->belongs_to( User_Model::class, 'user_id', 'ID' );
	}

	/**
	 * Events relationship
	 */
	public function events() {
		return $this->has_many( Video_Event_Model::class, 'session_id', 'id' );
	}

	/**
	 * View time relationship
	 */
	public function view_time() {
		return $this->has_one( Video_View_Time_Model::class, 'session_id', 'id' );
	}

	/**
	 * Get total sessions count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int
	 */
	public static function get_total_sessions( $start_date, $end_date, $video_id = null, $storefront_id = null ) {
		global $wpdb;

		$query = static::query();

		if ( null !== $storefront_id ) {
			$query->where( 'storefront_id', (int) $storefront_id );
		}

		if ( $video_id ) {
			// Join with events table to filter by video_id
			$events_table   = ( new Video_Event_Model() )->get_full_table_name();
			$sessions_table = ( new static() )->get_full_table_name();

			$query->join_raw( "JOIN {$events_table} e ON {$sessions_table}.id = e.session_id" );
			// Use prepared statement for video_id
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'e.video_id = %d', absint( $video_id ) ) );

			// Add date range condition only if dates are provided
			if ( $start_date && $end_date ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query->where_raw( $wpdb->prepare( "{$sessions_table}.started_at BETWEEN %s AND %s", $start_date, $end_date ) );
			}

			return $query->count_distinct( "{$sessions_table}.id" );
		} else {
			// Add date range condition only if dates are provided
			if ( $start_date && $end_date ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query->where_raw( $wpdb->prepare( 'started_at BETWEEN %s AND %s', $start_date, $end_date ) );
			}

			return $query->count();
		}
	}

	/**
	 * Get unique sessions count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int
	 */
	public static function get_unique_sessions( $start_date, $end_date, $video_id = null, $storefront_id = null ) {
		global $wpdb;

		$query = static::query();

		if ( null !== $storefront_id ) {
			$query->where( 'storefront_id', (int) $storefront_id );
		}

		if ( $video_id ) {
			// Join with events table to filter by video_id
			$events_table   = ( new Video_Event_Model() )->get_full_table_name();
			$sessions_table = ( new static() )->get_full_table_name();

			$query->join_raw( "JOIN {$events_table} e ON {$sessions_table}.id = e.session_id" );
			// Use prepared statement for video_id
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'e.video_id = %d', absint( $video_id ) ) );

			// Add date range condition only if dates are provided
			if ( $start_date && $end_date ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query->where_raw( $wpdb->prepare( "{$sessions_table}.started_at BETWEEN %s AND %s", $start_date, $end_date ) );
			}

			return $query->count_distinct( "{$sessions_table}.visitor_id" );
		} else {
			// Add date range condition only if dates are provided
			if ( $start_date && $end_date ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query->where_raw( $wpdb->prepare( 'started_at BETWEEN %s AND %s', $start_date, $end_date ) );
			}

			return $query->count_distinct( 'visitor_id' );
		}
	}

	/**
	 * Get per-day session counts (a session == a storefront view) for a date range.
	 *
	 * Days with no sessions are absent from the result — callers zero-fill.
	 *
	 * @param string $start_date    Start datetime (Y-m-d H:i:s).
	 * @param string $end_date      End datetime (Y-m-d H:i:s).
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return array Rows of { day: 'Y-m-d', views: int }.
	 */
	public static function get_daily_views( $start_date, $end_date, $storefront_id = null ) {
		global $wpdb;

		$query = static::query()->select_raw( 'DATE(started_at) AS day, COUNT(*) AS views' );

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 'started_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			$query->where( 'storefront_id', (int) $storefront_id );
		}

		return $query->group_by( 'day' )->order_by_raw( 'day ASC' )->get_raw();
	}

	/**
	 * Get top videos by view event count
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $limit      Number of results to return.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return array
	 */
	public static function get_top_videos( $start_date, $end_date, $limit = 5, $storefront_id = null ) {
		global $wpdb;

		// Get video IDs with their view counts using eloquent Query Builder
		$events_table   = ( new Video_Event_Model() )->get_full_table_name();
		$sessions_table = ( new static() )->get_full_table_name();

		$query = Video_Event_Model::query()
			->select_raw( 'video_id, COUNT(*) as view_count' )
			->join_raw( "JOIN {$sessions_table} s ON s.id = {$events_table}.session_id" );

		// Add date range condition only if dates are provided
		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.started_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		if ( null !== $storefront_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.storefront_id = %d', (int) $storefront_id ) );
		}

		$video_view_counts = $query
			->where( 'event_type', 'view' )
			->group_by( 'video_id' )
			->order_by_raw( 'view_count DESC' )
			->limit( $limit )
			->get();

		// Get the video models in the correct order
		$video_models = array();
		foreach ( $video_view_counts as $count_data ) {
			$video = Video_Model::find( $count_data->video_id );
			if ( $video ) {
				$video_models[] = $video;
			}
		}

		return $video_models;
	}
}
