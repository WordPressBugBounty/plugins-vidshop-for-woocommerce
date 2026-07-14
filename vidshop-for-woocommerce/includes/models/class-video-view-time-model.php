<?php
/**
 * Video View Time Model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Video_View_Time_Table;

/**
 * Video View Time Model
 */
class Video_View_Time_Model extends Model {

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
		'session_id',
		'video_id',
		'seconds_viewed',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'             => 'integer',
		'session_id'     => 'integer',
		'video_id'       => 'integer',
		'seconds_viewed' => 'integer',
	);

	/**
	 * Get the table instance
	 */
	protected function get_table_instance() {
		return new Video_View_Time_Table();
	}

	/**
	 * Update or create a view time record
	 *
	 * @param int $session_id     The session ID.
	 * @param int $video_id       The video ID.
	 * @param int $seconds_viewed The seconds viewed to add.
	 * @return Video_View_Time_Model The view time model instance.
	 */
	public static function update_view_time( $session_id, $video_id, $seconds_viewed ) {
		$view_time = static::first_or_new(
			array(
				'session_id' => $session_id,
				'video_id'   => $video_id,
			),
			array(
				'seconds_viewed' => 0,
			)
		);

		$view_time->seconds_viewed = (int) $view_time->seconds_viewed + $seconds_viewed;
		$view_time->save();

		return $view_time;
	}

	/**
	 * Session relationship
	 */
	public function session() {
		return $this->belongs_to( Video_Session_Model::class, 'session_id', 'id' );
	}

	/**
	 * Get total view time for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return int
	 */
	public static function get_total_view_time( $start_date, $end_date, $video_id = null, $storefront_id = null ) {
		global $wpdb;
		$view_time_table = ( new static() )->get_full_table_name();
		$sessions_table  = ( new Video_Session_Model() )->get_full_table_name();

		$sql              = "SELECT SUM(vt.seconds_viewed) FROM {$view_time_table} vt
			JOIN {$sessions_table} s ON vt.session_id = s.id";
		$params           = array();
		$where_conditions = array();

		// Add date range condition only if dates are provided
		if ( $start_date && $end_date ) {
			$where_conditions[] = 's.started_at BETWEEN %s AND %s';
			$params[]           = $start_date;
			$params[]           = $end_date;
		}

		if ( $video_id ) {
			$where_conditions[] = 'vt.video_id = %d';
			$params[]           = $video_id;
		}

		if ( null !== $storefront_id ) {
			$where_conditions[] = 's.storefront_id = %d';
			$params[]           = (int) $storefront_id;
		}

		if ( ! empty( $where_conditions ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_conditions );
		}

		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Get average view time for date range
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $video_id   Optional video ID to filter by.
	 * @param int    $storefront_id Optional storefront ID to filter by.
	 * @return float
	 */
	public static function get_average_view_time( $start_date, $end_date, $video_id = null, $storefront_id = null ) {
		$total_view_time = static::get_total_view_time( $start_date, $end_date, $video_id, $storefront_id );
		$total_sessions  = Video_Session_Model::get_total_sessions( $start_date, $end_date, $video_id, $storefront_id );

		return $total_sessions > 0 ? round( $total_view_time / $total_sessions, 2 ) : 0;
	}
}
