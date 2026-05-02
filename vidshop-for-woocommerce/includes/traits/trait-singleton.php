<?php
/**
 * Singleton class.
 *
 * @since 1.0.0
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Traits;

/**
 * Singleton class.
 */
trait Singleton {

	/**
	 * Instance of the class.
	 *
	 * @var static
	 */
	private static $instance;

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return static
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}
}
