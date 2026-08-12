<?php
/**
 * Video model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Videos_Table;
use VSFW\Database\Tables\Video_Product_Relationship_Table;

/**
 * Video model
 */
class Video_Model extends Model {

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
		'title',
		'type',
		'source_url',
		'thumbnail_id',
		'video_id',
		'ai_call_id',
		'settings',
		'status',
		'origin',
		'created_by',
		'deleted_at',
		'deleted_by',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'           => 'integer',
		'thumbnail_id' => 'integer',
		'video_id'     => 'integer',
		'ai_call_id'   => 'integer',
		'created_by'   => 'integer',
		'deleted_by'   => 'integer',
		'settings'     => 'array',
	);

	/**
	 * The accessors to append to the model's array form
	 *
	 * @var array
	 */
	protected $appends = array(
		'thumbnail_url',
		'source_url',
		'total_views',
		'total_likes',
		'duration',
	);

	/**
	 * The validation rules.
	 *
	 * `source_url` accepts every extension `wp_get_mime_types()` maps to a `video/*` type, plus the
	 * three streaming containers (m3u8, ts, m2ts) that WordPress will not store as an upload but a
	 * CDN serves happily. A file from the media library is only checked for being an attachment id,
	 * so anything narrower here would refuse by URL what the picker accepts by click.
	 *
	 * @var array
	 */
	public $rules = array(
		'title'        => 'required|string|max:255',
		'type'         => 'required|string|in:media_library,custom',
		'source_url'   => 'required_if:type,custom|mimes:mp4,m4v,mov,qt,avi,divx,wmv,wmx,wm,asf,asx,flv,mpeg,mpg,mpe,ogg,ogv,webm,mkv,3gp,3gpp,3g2,3gp2,m3u8,ts,m2ts',
		'thumbnail_id' => 'required|integer|min:1',
		'video_id'     => 'required_if:type,media_library|integer',
		'settings'     => 'array',
		'status'       => 'required|string|in:published,draft,trash',
		'created_by'   => 'required|integer',
	);

	/**
	 * Get the table instance
	 */
	protected function get_table_instance() {
		return new Videos_Table();
	}

	/**
	 * Products relationship
	 */
	public function products() {
		return $this->belongs_to_many( Product_Model::class, 'vsfw_video_product_relationship', 'video_id', 'product_id' );
	}

	/**
	 * User relationship
	 */
	public function user() {
		return $this->belongs_to( User_Model::class, 'created_by', 'ID' );
	}

	/**
	 * The user who moved the video to trash.
	 *
	 * Separate from user() — the creator and the deleter are frequently different people, and the
	 * Trash screen reports the latter.
	 */
	public function deleter() {
		return $this->belongs_to( User_Model::class, 'deleted_by', 'ID' );
	}

	/**
	 * Video events relationship
	 */
	public function events() {
		return $this->has_many( Video_Event_Model::class, 'video_id', 'id' );
	}

	/**
	 * Video products stats relationship
	 */
	public function products_stats() {
		return $this->has_many( Video_Product_Stats_Model::class, 'video_id', 'id' );
	}

	/**
	 * Video sessions relationship (through events)
	 * Since sessions no longer have video_id, we get sessions through events
	 */
	public function sessions() {
		return $this->belongs_to_many(
			Video_Session_Model::class,
			'vsfw_video_events',
			'video_id',
			'session_id'
		);
	}

	/**
	 * Count published videos.
	 *
	 * @return int
	 */
	public static function count_published() {
		return (int) static::query()->where( 'status', 'published' )->count();
	}

	/**
	 * Count the distinct WooCommerce products featured across all videos.
	 *
	 * Counts the pivot table rather than the videos, so a product linked to five videos still
	 * counts once — the dashboard reports catalogue coverage, not link count.
	 *
	 * @return int
	 */
	public static function count_featured_products() {
		global $wpdb;

		$table = ( new Video_Product_Relationship_Table() )->get_full_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from our own schema; no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table}" );
	}

	/**
	 * Get thumbnail URL
	 *
	 * @return string|null
	 */
	public function getThumbnailUrlAttribute() {
		if ( ! $this->thumbnail_id ) {
			return null;
		}

		$attachment_url = wp_get_attachment_url( $this->thumbnail_id );
		return $attachment_url ?: null;
	}

	/**
	 * Get source URL
	 *
	 * @return string|null
	 */
	public function getSourceUrlAttribute() {
		if ( ! isset( $this->source_url ) ) {
			return null;
		}

		return $this->type === 'media_library' ? wp_get_attachment_url( $this->video_id ) : $this->source_url;
	}

	/**
	 * Counts primed for a whole page of videos, keyed `"{video_id}:{event_type}"`.
	 *
	 * @var array<string, int>|null
	 */
	protected static $count_cache = null;

	/**
	 * Count every listed video's views and likes in one query.
	 *
	 * These two accessors are in `$appends`, so serializing a video runs two `COUNT(*)`s — and the
	 * list serializes a page at a time. Measured on the front-end shortcode, which asks for 100:
	 * eleven videos cost 86 queries, twenty-two of them this pair. It grew a query per video per
	 * count, on every page view carrying the shortcode.
	 *
	 * @param int[] $video_ids The page's videos.
	 * @return void
	 */
	public static function prime_counts( array $video_ids ) {
		global $wpdb;

		$video_ids = array_values( array_unique( array_map( 'intval', $video_ids ) ) );

		if ( empty( $video_ids ) ) {
			static::$count_cache = array();
			return;
		}

		$events       = ( new Video_Event_Model() )->get_full_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $video_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT video_id, event_type, COUNT(*) AS total
				 FROM {$events}
				 WHERE video_id IN ({$placeholders}) AND event_type IN ('view', 'like')
				 GROUP BY video_id, event_type",
				$video_ids
			)
		);

		$cache = array();

		foreach ( (array) $rows as $row ) {
			$cache[ (int) $row->video_id . ':' . $row->event_type ] = (int) $row->total;
		}

		static::$count_cache = $cache;
	}

	/** Forgets the primed counts, so a later request cannot read another page's numbers. */
	public static function flush_counts() {
		static::$count_cache = null;
	}

	/**
	 * Read one primed count, falling back to its own query when nothing primed it.
	 *
	 * @param string $event_type `view` or `like`.
	 * @return int
	 */
	protected function counted( $event_type ) {
		$key = (int) $this->id . ':' . $event_type;

		if ( is_array( static::$count_cache ) ) {
			return isset( static::$count_cache[ $key ] ) ? static::$count_cache[ $key ] : 0;
		}

		return $this->events()->query()->where( 'event_type', '=', $event_type )->count();
	}

	/**
	 * Get total views
	 *
	 * @return int
	 */
	public function getTotalViewsAttribute() {
		return $this->counted( 'view' );
	}

	/**
	 * Get total likes
	 *
	 * @return int
	 */
	public function getTotalLikesAttribute() {
		return $this->counted( 'like' );
	}

	/**
	 * Get the runtime in whole seconds.
	 *
	 * Duration is not stored on the videos table. For media-library videos WordPress already read
	 * it at upload time (`wp_read_video_metadata()` writes `length` into the attachment meta), and
	 * AI imports carry the requested length on their generation row as a fallback for the rare
	 * attachment whose metadata WordPress could not parse. Externally hosted videos (`type=custom`)
	 * have no readable duration, so they return null and the UI shows a dash.
	 *
	 * @return int|null Seconds, or null when unknown.
	 */
	public function getDurationAttribute() {
		if ( 'media_library' === $this->type && $this->video_id ) {
			$meta = wp_get_attachment_metadata( $this->video_id );

			if ( is_array( $meta ) && ! empty( $meta['length'] ) ) {
				return (int) $meta['length'];
			}
		}

		if ( $this->ai_call_id ) {
			// `videos.ai_call_id` holds the cloud video id, which is what the generations row is
			// keyed on too — not that row's local primary key.
			$generation = Ai_Generation_Model::query()
				->where( 'ai_call_id', '=', (int) $this->ai_call_id )
				->first();

			if ( $generation && $generation->duration_s ) {
				return (int) $generation->duration_s;
			}
		}

		return null;
	}
}
