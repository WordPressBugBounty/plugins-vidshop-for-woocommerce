<?php
/**
 * Product model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Models\Video_Product_Stats_Model;

/**
 * Product model
 */
class Product_Model extends Model {

	/**
	 * The table name
	 *
	 * @var string
	 */
	protected $table_name = 'posts';

	/**
	 * The primary key for the model
	 *
	 * @var string
	 */
	protected $primary_key = 'ID';

	/**
	 * Whether the model should be timestamped
	 *
	 * @var bool
	 */
	public $timestamps = false;

	/**
	 * Columns
	 */
	protected $columns = array(
		'ID',
		'post_title',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'ID' => 'integer',
	);

	/**
	 * Stats relationship
	 */
	public function stats() {
		return $this->has_many( Video_Product_Stats_Model::class, 'product_id', 'ID' );
	}
}
