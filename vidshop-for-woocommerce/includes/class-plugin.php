<?php
/**
 * Main Plugin Class for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW;

use VSFW\Traits\Singleton;

/**
 * Main plugin class using WordPress-friendly singleton pattern.
 */
class Plugin {

	use Singleton;

	/**
	 * Service container instance.
	 *
	 * @var Service_Container
	 */
	private $container;

	/**
	 * Module loader instance.
	 *
	 * @var Module_Loader
	 */
	private $module_loader;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container     = Service_Container::instance();
		$this->module_loader = Module_Loader::instance();
		$this->init();
	}

	/**
	 * Initialize the plugin.
	 */
	private function init() {
		// Load core services.
		$this->modules()->load_all_modules();
	}

	/**
	 * Get the service container instance.
	 *
	 * @return Service_Container
	 */
	public function services() {
		return $this->container;
	}

	/**
	 * Get the module loader instance.
	 *
	 * @return Module_Loader
	 */
	public function modules() {
		return $this->module_loader;
	}

	/**
	 * Bind an interface to an implementation.
	 *
	 * @param string $interface Interface name.
	 * @param string $implementation Implementation class or service name.
	 */
	public function bind( $interface, $implementation ) {
		$this->container->bind( $interface, $implementation );
	}
}
