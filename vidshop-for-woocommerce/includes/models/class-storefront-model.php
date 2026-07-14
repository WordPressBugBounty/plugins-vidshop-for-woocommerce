<?php
/**
 * Storefront model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;
use VSFW\Database\Tables\Storefronts_Table;

/**
 * Storefront model.
 *
 * A saved shortcode configuration rendered by [vidshop id="123"]. The display
 * config is stored as a JSON string in the `config` column.
 *
 * Note on `config`: the base Model's `array` cast does not decode a JSON *string*
 * read back from the DB (it assumes an already-decoded value), and `$wpdb->insert`
 * does not serialize arrays. So `config` is kept as a raw JSON string on the model
 * and (de)serialized explicitly — encoded by the controller before save, decoded
 * via {@see get_config_array()} on read.
 */
class Storefront_Model extends Model {

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
		'name',
		'config',
		'status',
		'created_by',
	);

	/**
	 * The attributes that should be cast to native types.
	 *
	 * `config` is intentionally NOT cast (see class docblock).
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'         => 'integer',
		'created_by' => 'integer',
	);

	/**
	 * The accessors to append to the model's array form.
	 *
	 * @var array
	 */
	protected $appends = array(
		'shortcode',
	);

	/**
	 * The validation rules.
	 *
	 * @var array
	 */
	public $rules = array(
		'name'       => 'required|string|max:191',
		'config'     => 'required|string',
		'status'     => 'required|string|in:published,draft,trash',
		'created_by' => 'required|integer',
	);

	/**
	 * Get the table instance.
	 *
	 * @return Storefronts_Table
	 */
	protected function get_table_instance() {
		return new Storefronts_Table();
	}

	/**
	 * Decode the stored JSON config into an array.
	 *
	 * @return array
	 */
	public function get_config_array() {
		$raw = $this->get_attribute( 'config' );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Shortcode accessor — the ready-to-paste [vidshop id="X"] string.
	 *
	 * @return string
	 */
	public function getShortcodeAttribute() {
		$id = $this->get_key();

		return $id ? sprintf( '[vidshop id="%d"]', $id ) : '';
	}
}
