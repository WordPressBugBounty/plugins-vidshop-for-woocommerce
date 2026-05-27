<?php
/**
 * Service Container for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW;

use VSFW\Traits\Singleton;
use VSFW\Services\WooCommerce;
use VSFW\Interfaces\WooCommerce as WooCommerce_Interface;
use VSFW\Services\Database_Installer;
use VSFW\Interfaces\Database_Installer as Database_Installer_Interface;
use VSFW\Services\Settings;
use VSFW\Interfaces\Settings as Settings_Interface;
use VSFW\Services\Cloud_Client;
use VSFW\Services\Cloud_Connection;
use VSFW\Services\Ai_Generation_Service;
use VSFW\Services\Ai_Reconciler;

/**
 * Simple service container with dependency injection.
 */
class Service_Container {
	use Singleton;

	/**
	 * Registered services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Service instances.
	 *
	 * @var array
	 */
	private $instances = array();

	/**
	 * Interface to implementation mapping.
	 *
	 * @var array
	 */
	private $interfaces = array();

	/**
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->resolver = new Dependency_Resolver( $this );
		$this->register_default_services();
	}

	/**
	 * Register a service.
	 *
	 * @param string $name Service name.
	 * @param string $class Service class.
	 * @param bool   $singleton Whether to treat as singleton.
	 */
	public function register( $name, $class, $singleton = true ) {
		$this->services[ $name ] = array(
			'class'     => $class,
			'singleton' => $singleton,
		);
	}

	/**
	 * Bind an interface to an implementation.
	 *
	 * @param string $interface Interface name.
	 * @param string $implementation Implementation class or service name.
	 */
	public function bind( $interface, $implementation ) {
		$this->interfaces[ $interface ] = $implementation;
	}

	/**
	 * Get a service instance.
	 *
	 * @param string $name Service name.
	 * @return mixed Service instance.
	 * @throws \Exception If service not found or circular dependency detected.
	 */
	public function get( $name ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			throw new \Exception( "Service '{$name}' not found." );
		}

		$service = $this->services[ $name ];

		// Return existing instance if singleton
		if ( $service['singleton'] && isset( $this->instances[ $name ] ) ) {
			return $this->instances[ $name ];
		}

		// Resolve dependencies
		$dependencies = $this->resolver->resolve_dependencies( $service['class'] );

		// Create instance
		$reflection = new \ReflectionClass( $service['class'] );
		$instance   = $reflection->newInstanceArgs( $dependencies );

		// Store instance if singleton
		if ( $service['singleton'] ) {
			$this->instances[ $name ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if service is registered.
	 *
	 * @param string $name Service name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->services[ $name ] );
	}

	/**
	 * Register default services.
	 */
	private function register_default_services() {
		$this->register( 'woocommerce', WooCommerce::class, true );
		$this->bind( WooCommerce_Interface::class, WooCommerce::class );

		$this->register( 'database_installer', Database_Installer::class, true );
		$this->bind( Database_Installer_Interface::class, Database_Installer::class );

		$this->register( 'settings', Settings::class, true );
		$this->bind( Settings_Interface::class, Settings::class );

		// Cloud integration (WPCreatix-AI). Concrete classes resolved by type-hint.
		$this->register( 'cloud_client', Cloud_Client::class, true );
		$this->register( 'cloud_connection', Cloud_Connection::class, true );
		$this->register( 'ai_generation_service', Ai_Generation_Service::class, true );
		$this->register( 'ai_reconciler', Ai_Reconciler::class, true );

		// Allow developers to register services.
		do_action( 'svfw_register_services', $this );
	}
}
