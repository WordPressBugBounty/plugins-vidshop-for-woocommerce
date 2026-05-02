<?php
/**
 * Validation Exception and Model Integration
 *
 * @package VidShop
 */

namespace VSFW\Utils;

/**
 * Validation Exception
 */
class Validation_Exception extends \Exception {

	/**
	 * The validator instance
	 *
	 * @var Validator
	 */
	protected $validator;

	/**
	 * Constructor
	 *
	 * @param Validator $validator
	 * @param string    $message
	 */
	public function __construct( $validator, $message = 'Validation failed' ) {
		$this->validator = $validator;
		parent::__construct( $message );
	}

	/**
	 * Get the validator instance
	 *
	 * @return Validator
	 */
	public function validator() {
		return $this->validator;
	}

	/**
	 * Get validation errors
	 *
	 * @return array
	 */
	public function errors() {
		$errors = $this->validator->errors();
		return $errors;
	}

	/**
	 * Get the first error message
	 *
	 * @return string|null
	 */
	public function first() {
		return $this->validator->first();
	}
}
