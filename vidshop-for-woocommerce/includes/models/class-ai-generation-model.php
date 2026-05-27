<?php
/**
 * AI generation model.
 *
 * One row per cloud AI video generation this site requested. Maps the cloud `ai_call_id`
 * to the local `vsfw_videos` row it was imported into, and tracks the lifecycle status
 * (pending → processing → importing → imported | failed) used by the reconciler.
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Ai_Generations_Table;

/**
 * AI generation model
 */
class Ai_Generation_Model extends Model {

	/**
	 * The primary key for the model.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = array(
		'ai_call_id',
		'product_id',
		'title',
		'status',
		'video_id',
		'duration_s',
		'estimated_seconds',
		'error',
		'checked_at',
		'created_by',
	);

	/**
	 * The attributes that should be cast to native types.
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'                => 'integer',
		'ai_call_id'        => 'integer',
		'product_id'        => 'integer',
		'video_id'          => 'integer',
		'duration_s'        => 'integer',
		'estimated_seconds' => 'integer',
		'created_by'        => 'integer',
	);

	/**
	 * Validation rules — none; rows are written internally by the generation pipeline.
	 *
	 * @var array
	 */
	public $rules = array();

	/**
	 * Get the table instance.
	 *
	 * @return Ai_Generations_Table
	 */
	protected function get_table_instance() {
		return new Ai_Generations_Table();
	}
}
