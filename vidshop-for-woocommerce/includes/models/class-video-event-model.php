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
	 * @return int
	 */
	public static function get_total_likes( $start_date, $end_date, $video_id = null ) {
		global $wpdb;

		$query = static::query()->where( 'event_type', 'like' );

		// Join with sessions table
		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );

		// Add date range condition only if dates are provided - use prepared statement
		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.started_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		// Add video filter if provided
		if ( $video_id ) {
			$query->where( 'video_id', absint( $video_id ) );
		}

		return $query->count();
	}

	/**
	 * Get unique likes count for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @return int
	 */
	public static function get_unique_likes( $start_date, $end_date, $video_id = null ) {
		global $wpdb;

		$query = static::query()->where( 'event_type', 'like' );

		// Join with sessions table
		$sessions_table = ( new Video_Session_Model() )->get_full_table_name();
		$events_table   = ( new static() )->get_full_table_name();

		$query->join_raw( "JOIN {$sessions_table} s ON {$events_table}.session_id = s.id" );

		// Add date range condition only if dates are provided - use prepared statement
		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->where_raw( $wpdb->prepare( 's.started_at BETWEEN %s AND %s', $start_date, $end_date ) );
		}

		// Add video filter if provided
		if ( $video_id ) {
			$query->where( 'video_id', absint( $video_id ) );
		}

		return $query->count_distinct( 's.visitor_id' );
	}
}
