<?php
/**
 * REST API module
 *
 * @package VidShop
 */

namespace VSFW\REST_API;

use VSFW\REST_API\V1\Videos_Controller;
use VSFW\REST_API\V1\Video_Tracking_Controller;
use VSFW\REST_API\V1\Analytics_Controller;
use VSFW\REST_API\V1\Products_Controller;
use VSFW\REST_API\V1\Settings_Controller;
use VSFW\Dependency_Resolver;
use VSFW\Service_Container;

/**
 * REST API module
 */
class REST_API_Module {

	/**
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver
	 */
	private $resolver;

	/**
	 * Service container instance.
	 *
	 * @var Service_Container
	 */
	private $container;

	/**
	 * Controller classes.
	 *
	 * @var array
	 */
	private $controllers = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->container = Service_Container::instance();
		$this->resolver  = new Dependency_Resolver( $this->container );
		$this->register_controllers();
		$this->init();
	}

	/**
	 * Register controller classes
	 */
	private function register_controllers() {
		$this->controllers = array(
			Videos_Controller::class,
			Video_Tracking_Controller::class,
			Analytics_Controller::class,
			Products_Controller::class,
			Settings_Controller::class,
		);
	}

	/**
	 * Initialize the REST API.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public function register_rest_routes() {
		foreach ( $this->controllers as $controller_class ) {
			// Use dependency resolver to instantiate controller with its dependencies
			$dependencies = $this->resolver->resolve_dependencies( $controller_class );
			$reflection   = new \ReflectionClass( $controller_class );
			$controller   = $reflection->newInstanceArgs( $dependencies );

			$controller->register_routes();
		}
	}
}
