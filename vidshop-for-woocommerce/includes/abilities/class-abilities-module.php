<?php
/**
 * Abilities module — registers the VidShop category and every ability provider.
 *
 * This is a thin adapter over the WordPress Abilities API (core 6.9+). It owns no business logic:
 * every callback a provider returns either calls an existing service method or dispatches to one of
 * the plugin's own already-registered REST routes.
 *
 * BACK-COMPAT RULE: no file in this directory may ever name core's ability class — not as a parent,
 * an import, a type hint, a return type or an `ability_class` argument. Only bare function calls
 * guarded by `function_exists()`. On WordPress 5.8 (the plugin's declared minimum) those classes and
 * functions do not exist, so autoloading this file must stay an inert class definition. A PHPUnit
 * test greps this directory for that class name permanently.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Abilities;

use VSFW\Plugin;

/**
 * Bootstrap for the VidShop abilities layer.
 */
class Abilities_Module {

	/** Ability category slug. Must match `^[a-z0-9]+(?:-[a-z0-9]+)*$`. */
	const CATEGORY = 'vidshop';

	/**
	 * Provider classes, in registration order.
	 *
	 * Each provider exposes exactly one public static method, `definitions( $module ): array`,
	 * returning a map of `ability name => $args`. `::class` resolves at compile time and does not
	 * autoload, so a provider that is not shipped yet is skipped by the `class_exists()` guard in
	 * `register_abilities()` rather than fatalling.
	 */
	const PROVIDERS = array(
		Videos_Abilities::class,
		Storefronts_Abilities::class,
		Ai_Abilities::class,
		Generation_Abilities::class,
	);

	/**
	 * Constructor.
	 *
	 * Takes no dependencies on purpose: this module is loaded on every request, and injecting
	 * services would build them all before any ability actually runs. Services are pulled lazily
	 * through `service()` instead.
	 */
	public function __construct() {
		// Registered before the feature guard so the command can explain WHY nothing registered on
		// a WordPress older than 6.9.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'vidshop abilities', array( $this, 'cli_manifest' ) );
		}

		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Both registries are lazy singletons that fire their init action exactly once, on the first
		// call to any wp_*_ability*() function. Attaching here — at plugin load time, before init —
		// is the only safe window; a listener added after the registry exists never runs.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the `vidshop` ability category.
	 *
	 * Runs on `wp_abilities_api_categories_init`, which core fires before the abilities hook. An
	 * unregistered category silently kills every ability assigned to it.
	 *
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'VidShop', 'vidshop-for-woocommerce' ),
				'description' => __( 'Read and manage shoppable videos, storefronts, analytics and AI video generation.', 'vidshop-for-woocommerce' ),
			)
		);
	}

	/**
	 * Register every ability returned by the providers.
	 *
	 * Runs on `wp_abilities_api_init`. Providers never call `wp_register_ability()` themselves —
	 * registration stays single and central so failures are visible in one place.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! wp_has_ability_category( self::CATEGORY ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf( 'The "%s" ability category is not registered, so no VidShop abilities were registered.', esc_html( self::CATEGORY ) ),
				'1.7.0'
			);
			return;
		}

		$expected_names = array();

		foreach ( self::PROVIDERS as $provider ) {
			if ( ! class_exists( $provider ) ) {
				continue;
			}

			$definitions = $provider::definitions( $this );
			if ( ! is_array( $definitions ) ) {
				continue;
			}

			foreach ( $definitions as $name => $args ) {
				wp_register_ability( $name, $args );
				$expected_names[] = $name;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assert_invariants( $expected_names );
		}
	}

	/**
	 * Build the permission callback for an ability.
	 *
	 * Always a bare boolean, never a WP_Error: the REST run controller returns a permission WP_Error
	 * verbatim to the caller. And never any side effect — a single REST `/run` request runs the
	 * permission check twice (once in the route's permission callback, once inside execute()).
	 *
	 * @param string $ability_name Ability name, passed to the capability filter.
	 * @return callable Permission callback returning bool.
	 */
	public function permission_callback( $ability_name ) {
		return static function ( $input = null ) use ( $ability_name ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- core invokes a schema-less ability's callback with zero arguments, so the default is required.
			// manage_options is a floor, not a default: the eight dispatch-backed abilities are
			// additionally gated by REST_Controller::check_private_permission(), which is not
			// filterable. Without this the filter would widen the four service-backed abilities
			// while the dispatch-backed ones kept 403ing.
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			/**
			 * Filters the capability required to run a VidShop ability.
			 *
			 * Applied ON TOP OF manage_options, never instead of it.
			 *
			 * @param string $capability   Capability name. Default 'manage_options'.
			 * @param string $ability_name Ability being checked, e.g. 'vidshop/list-videos'.
			 */
			return current_user_can( apply_filters( 'vsfw_ability_capability', 'manage_options', $ability_name ) );
		};
	}

	/**
	 * Build the `meta` array for an ability.
	 *
	 * Every flag is a real boolean — core validates `public` and `show_in_rest` with `is_bool()` and
	 * throws on `1`. `show_in_rest` is set explicitly because inheriting it from `public` is 7.1
	 * behaviour, and `mcp.public` is set explicitly because inheriting it from `meta.public` is MCP
	 * adapter 0.6.0+ behaviour; 0.5.x reads only `meta.mcp.public`.
	 *
	 * @param array $annotations Annotation flags: readonly, destructive, idempotent.
	 * @param bool  $metered     Whether running the ability spends cloud credits. Default false.
	 * @return array Meta array for wp_register_ability().
	 */
	public function meta( array $annotations, $metered = false ) {
		$readonly    = ! empty( $annotations['readonly'] );
		$destructive = ! empty( $annotations['destructive'] );
		$idempotent  = ! empty( $annotations['idempotent'] );

		return array(
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => ( ! $destructive && ! (bool) $metered ),
			),
		);
	}

	/**
	 * Run one of the plugin's own REST routes internally and return its data.
	 *
	 * Dispatching inherits the route's registered arg defaults, sanitize callbacks and its own
	 * permission callback for free, so an ability never re-implements a controller. Safe under
	 * WP-CLI too: `rest_do_request()` builds the server, which fires `rest_api_init` on demand.
	 *
	 * @param string $method HTTP method, e.g. 'GET'.
	 * @param string $route  Route path, e.g. '/vsfw/v1/videos'.
	 * @param array  $params Request parameters. Default empty.
	 * @return mixed Response data, or WP_Error when the response is an error.
	 */
	public function dispatch( $method, $route, array $params = array() ) {
		$request = new \WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		return $response->get_data();
	}

	/**
	 * Merge an ability's input over its defaults.
	 *
	 * Core applies ONLY the top-level `default` key of an input schema during normalize_input();
	 * per-property defaults inside `properties` are never applied. This is the single merge point —
	 * providers pass their own defaults here instead of repeating the check per callback.
	 *
	 * @param mixed $input    Raw ability input (may be null when the schema default is an object).
	 * @param array $defaults Default values keyed by property name.
	 * @return array Input merged over the defaults.
	 */
	public function apply_defaults( $input, array $defaults ) {
		return wp_parse_args( is_array( $input ) ? $input : array(), $defaults );
	}

	/**
	 * Resolve a registered service, lazily.
	 *
	 * Nothing is built until an ability actually runs.
	 *
	 * @param string $name Service name, e.g. 'cloud_connection'.
	 * @return mixed Service instance.
	 * @throws \Exception If the service is not registered.
	 */
	public function service( $name ) {
		return Plugin::instance()->services()->get( $name );
	}

	/**
	 * Build an error an agent can act on.
	 *
	 * EXECUTE-SIDE ONLY. Permission errors stay bare and generic because the REST run controller
	 * returns them verbatim to the HTTP caller.
	 *
	 * @param string $code    Error code, e.g. 'vsfw_not_connected'.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional data. May carry `status` (HTTP code, default 400),
	 *                        `agent_hint` (string) and
	 *                        `recovery` => array( 'ability' => ..., 'input' => ... ).
	 * @return \WP_Error
	 */
	public function agent_error( $code, $message, array $data = array() ) {
		// Core reads data['status'] for the HTTP code, and a refusal must never present as a
		// server crash: without this a caller sees 500 and blind-retries instead of reading the hint.
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 400;
		}

		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Print a table of every registered VidShop ability.
	 *
	 * Registration failures are invisible on production and the whole feature is a silent no-op
	 * below WordPress 6.9, so this is the only in-product way to see what actually registered.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp vidshop abilities
	 *     wp vidshop abilities --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 * @return void
	 */
	public function cli_manifest( $args, $assoc_args ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			\WP_CLI::warning( 'The Abilities API requires WordPress 6.9 or newer. No VidShop abilities are registered on this install.' );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$items  = array();

		foreach ( $this->get_registered_abilities() as $name => $ability ) {
			$meta        = $ability->get_meta();
			$annotations = $this->meta_array( $meta, 'annotations' );
			$mcp         = $this->meta_array( $meta, 'mcp' );

			// The filter adds a capability on top of the manage_options floor, so
			// print both when it returns something else.
			$filtered   = apply_filters( 'vsfw_ability_capability', 'manage_options', $name );
			$capability = 'manage_options' === $filtered ? 'manage_options' : 'manage_options + ' . $filtered;

			$items[] = array(
				'name'        => $name,
				'category'    => $ability->get_category(),
				'capability'  => $capability,
				'readonly'    => empty( $annotations['readonly'] ) ? 'no' : 'yes',
				'destructive' => empty( $annotations['destructive'] ) ? 'no' : 'yes',
				'idempotent'  => empty( $annotations['idempotent'] ) ? 'no' : 'yes',
				'mcp'         => empty( $mcp['public'] ) ? 'off' : 'on',
			);
		}

		if ( empty( $items ) ) {
			\WP_CLI::warning( 'No VidShop abilities are registered.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'name', 'category', 'capability', 'readonly', 'destructive', 'idempotent', 'mcp' )
		);
	}

	/**
	 * Assert the registration invariants a silent core failure would otherwise hide.
	 *
	 * Only runs under WP_DEBUG. A typo such as `callback` instead of `execute_callback` registers
	 * nothing and returns a silent null, so the first check is simply "is it there?".
	 *
	 * @param array $expected Ability names that should have registered.
	 * @return void
	 */
	private function assert_invariants( array $expected ) {
		foreach ( $expected as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Ability "%s" was not registered. Check its args array for a bad name, a missing execute_callback or an invalid meta value.', esc_html( $name ) ),
					'1.7.0'
				);
			}
		}

		foreach ( $this->get_registered_abilities() as $name => $ability ) {
			$meta        = $ability->get_meta();
			$annotations = $this->meta_array( $meta, 'annotations' );
			$destructive = ! empty( $annotations['destructive'] );

			if ( $destructive && ! empty( $annotations['idempotent'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Ability "%s" is both destructive and idempotent, which routes /run to DELETE — whose query-string input is never JSON-decoded.', esc_html( $name ) ),
					'1.7.0'
				);
			}

			if ( ! $destructive ) {
				continue;
			}

			$mcp = $this->meta_array( $meta, 'mcp' );
			if ( ! isset( $mcp['public'] ) || false !== $mcp['public'] ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Destructive ability "%s" must set meta.mcp.public to false.', esc_html( $name ) ),
					'1.7.0'
				);
			}

			$schema   = $ability->get_input_schema();
			$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

			if ( ! preg_grep( '/confirm|acknowledge/', $required ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Destructive ability "%s" must require a confirmation field in its input schema.', esc_html( $name ) ),
					'1.7.0'
				);
			}
		}
	}

	/**
	 * Get every registered ability in the `vidshop` namespace.
	 *
	 * The `namespace` filter arg is WordPress 7.1; on 6.9/7.0 it is ignored and the full registry
	 * comes back, so the prefix is re-checked here.
	 *
	 * @return array Abilities keyed by name.
	 */
	private function get_registered_abilities() {
		$abilities = wp_get_abilities( array( 'namespace' => self::CATEGORY ) );
		$prefix    = self::CATEGORY . '/';
		$matched   = array();

		foreach ( $abilities as $name => $ability ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$matched[ $name ] = $ability;
			}
		}

		return $matched;
	}

	/**
	 * Read one array-shaped key out of an ability's meta.
	 *
	 * @param array  $meta Ability meta.
	 * @param string $key  Meta key, e.g. 'annotations'.
	 * @return array The sub-array, or an empty array when absent or malformed.
	 */
	private function meta_array( array $meta, $key ) {
		return isset( $meta[ $key ] ) && is_array( $meta[ $key ] ) ? $meta[ $key ] : array();
	}
}
