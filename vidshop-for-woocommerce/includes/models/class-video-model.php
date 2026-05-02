<?php
/**
 * Video model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Videos_Table;

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
		'settings',
		'status',
		'created_by',
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
		'created_by'   => 'integer',
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
	);

	/**
	 * The validation rules
	 *
	 * @var array
	 */
	public $rules = array(
		'title'        => 'required|string|max:255',
		'type'         => 'required|string|in:media_library,custom',
		'source_url'   => 'required_if:type,custom|mimes:mp4,mov,avi,wmv,flv,mpeg,mpg,m4v,webm,ogg,ogv,mkv,m3u8,3gp,3g2,ts,m2ts',
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
	 * Get total views
	 *
	 * @return int
	 */
	public function getTotalViewsAttribute() {
		return $this->events()->query()->where( 'event_type', '=', 'view' )->count();
	}

	/**
	 * Get total likes
	 *
	 * @return int
	 */
	public function getTotalLikesAttribute() {
		return $this->events()->query()->where( 'event_type', '=', 'like' )->count();
	}
}
