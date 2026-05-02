<?php
/**
 * User model
 *
 * @package VidShop
 */

namespace VSFW\Models;

use VSFW\Abstracts\Model;

/**
 * User model
 */
class User_Model extends Model {

	/**
	 * The table name
	 *
	 * @var string
	 */
	protected $table_name = 'users';

	/**
	 * The primary key for the model
	 *
	 * @var string
	 */
	protected $primary_key = 'ID';

	/**
	 * The attributes that should be visible in arrays
	 *
	 * @var array
	 */
	protected $visible = array(
		'ID',
		'user_login',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'display_name',
	);

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array(
		'ID' => 'integer',
	);
}
