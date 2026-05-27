<?php
/**
 * Cloud connection service.
 *
 * Owns the plugin's connection to the WPCreatix-AI cloud: the access token (vsk_),
 * connection identity, and plan/usage. Stores the token server-side (option, NOT
 * autoloaded, never exposed to the browser) and orchestrates the connect flows
 * (free OTP, Pro license) on top of {@see Cloud_Client}.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

/**
 * Cloud connection service.
 */
class Cloud_Connection {

	/** Option storing the connection (token + identity). Not autoloaded. */
	const OPTION = 'vsfw_cloud_connection';

	/** Transient caching the usage snapshot. */
	const USAGE_TRANSIENT = 'vsfw_cloud_usage';

	/** Option stamping the last auto re-exchange (401 recovery cooldown). */
	const REEXCHANGE_STAMP = 'vsfw_cloud_last_reexchange';

	/** Usage cache TTL (seconds). */
	const USAGE_TTL = 60;

	/** Minimum seconds between auto re-exchanges (anti-loop; the cloud throttles exchange to 10/min). */
	const REEXCHANGE_COOLDOWN = 60;

	/** Transient caching the generation options (durations + costs); these rarely change. */
	const GEN_OPTIONS_TRANSIENT = 'vsfw_cloud_gen_options';

	/**
	 * Cloud API client.
	 *
	 * @var Cloud_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Cloud_Client $client Cloud API client.
	 */
	public function __construct( Cloud_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Generation options (allowed durations + credit costs, audio modes), cached for an hour.
	 * The UI reads durations/costs from here so its labels never drift from what the cloud charges.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|\WP_Error
	 */
	public function get_generation_options( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::GEN_OPTIONS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$options = $this->authed_request(
			function ( $token ) {
				return $this->client->generation_options( $token );
			}
		);

		if ( ! is_wp_error( $options ) ) {
			set_transient( self::GEN_OPTIONS_TRANSIENT, $options, HOUR_IN_SECONDS );
		}

		return $options;
	}

	/**
	 * Get the stored connection.
	 *
	 * @return array Connection data, or empty array when not connected.
	 */
	public function get() {
		$conn = get_option( self::OPTION );
		return is_array( $conn ) ? $conn : array();
	}

	/**
	 * Lightweight connection identity for localizing into the admin page (NO cloud call).
	 *
	 * Read straight from the stored option so every React surface knows the connection state on
	 * first paint (seeds the `vsfw/admin` store) without a `/ai/status` round-trip. Usage balances
	 * are intentionally excluded — those are fetched lazily where actually shown.
	 *
	 * @return array { connected:bool, plan:string|null, account_email:string|null }
	 */
	public static function localized_identity() {
		$conn = get_option( self::OPTION );
		$conn = is_array( $conn ) ? $conn : array();

		return array(
			'connected'     => ! empty( $conn['access_token'] ),
			'plan'          => isset( $conn['plan'] ) ? $conn['plan'] : null,
			'account_email' => isset( $conn['account_email'] ) ? $conn['account_email'] : null,
		);
	}

	/**
	 * Whether the site is connected to the cloud.
	 *
	 * @return bool
	 */
	public function is_connected() {
		$conn = $this->get();
		return ! empty( $conn['access_token'] );
	}

	/**
	 * Get the plugin access token.
	 *
	 * @return string|null
	 */
	public function get_token() {
		$conn = $this->get();
		return isset( $conn['access_token'] ) ? $conn['access_token'] : null;
	}

	/**
	 * Whether the connected cloud account is on the FREE plan (vs a paid VidShop Pro plan). Set at
	 * connect time (`connect_free` stores 'free', `connect_pro` stores 'pro'). Pro — and any unknown
	 * value — return false, so a paying customer is never gated by the local one-free-trial rule.
	 *
	 * @return bool
	 */
	public function is_free_plan() {
		$conn = $this->get();
		return isset( $conn['plan'] ) && 'free' === $conn['plan'];
	}

	/**
	 * Request a passwordless login code (free-tier connect, step 1).
	 *
	 * @param string $email Email address.
	 * @return array|\WP_Error { status:'sent' } or WP_Error.
	 */
	public function request_code( $email ) {
		return $this->client->request_login_code( $email );
	}

	/**
	 * Complete a free-tier connect: verify the code, exchange for a token, store it.
	 *
	 * @param string $email Email address.
	 * @param string $code  6-digit login code.
	 * @return array|\WP_Error { connected, is_new_user, plan, ... } or WP_Error.
	 */
	public function connect_free( $email, $code ) {
		$verified = $this->client->verify_login_code( $email, $code );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$issued = $this->client->issue_token( $verified['jwt'], $this->site_url(), $this->site_name() );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$this->store( $issued, 'free', $email );
		return $this->status_payload( array( 'is_new_user' => $verified['is_new_user'] ) );
	}

	/**
	 * Complete a Pro connect using the Freemius license provided by the Pro plugin.
	 *
	 * @return array|\WP_Error { connected, plan, ... } or WP_Error when no license is available.
	 */
	public function connect_pro() {
		$license = apply_filters( 'vsfw_cloud_license_key', null );
		if ( empty( $license ) ) {
			return new \WP_Error(
				'no_license',
				__( 'No VidShop Pro license is available on this site.', 'vidshop-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$issued = $this->client->exchange_license( $license, $this->site_url(), $this->site_name() );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$this->store( $issued, 'pro', null );
		return $this->status_payload();
	}

	/**
	 * Disconnect this site. Revokes the connection on the cloud FIRST (so disconnecting in WP admin also
	 * frees the site on the user's SaaS account + kills the bound token — mirroring the SaaS-UI's own
	 * disconnect), then drops the local token + cached usage. The cloud call is best-effort: a failure or
	 * unreachable cloud must never block the local disconnect, so its result is ignored and the local
	 * clear below always runs (it's what the admin sees).
	 *
	 * @return void
	 */
	public function disconnect() {
		$token = $this->get_token();
		if ( $token ) {
			$this->client->disconnect( $token );
		}

		delete_option( self::OPTION );
		delete_transient( self::USAGE_TRANSIENT );
	}

	/**
	 * Build the status payload for the UI: connection + (cached) usage.
	 *
	 * @param array $extra Extra fields to merge (e.g. is_new_user on first connect).
	 * @return array
	 */
	public function status_payload( array $extra = array() ) {
		if ( ! $this->is_connected() ) {
			return array( 'connected' => false );
		}

		$conn  = $this->get();
		$usage = $this->get_usage();

		$payload = array(
			'connected'     => true,
			'account_email' => isset( $conn['account_email'] ) ? $conn['account_email'] : null,
			'plan'          => isset( $conn['plan'] ) ? $conn['plan'] : null,
			'usage'         => is_wp_error( $usage ) ? null : $usage,
		);

		return array_merge( $payload, $extra );
	}

	/**
	 * Get the usage snapshot, cached for USAGE_TTL seconds.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|\WP_Error
	 */
	public function get_usage( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::USAGE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$usage = $this->authed_request(
			function ( $token ) {
				return $this->client->get_usage( $token );
			}
		);

		if ( ! is_wp_error( $usage ) ) {
			set_transient( self::USAGE_TRANSIENT, $usage, self::USAGE_TTL );
		}

		return $usage;
	}

	/**
	 * Bust the cached usage snapshot (call after a generation completes / after checkout).
	 *
	 * @return void
	 */
	public function flush_usage_cache() {
		delete_transient( self::USAGE_TRANSIENT );
	}

	/**
	 * Base URL of the cloud DASHBOARD (web app), for plain "Manage usage" / "add credits" links.
	 *
	 * Deliberately NOT an auto-login link: the plugin must not bridge a WordPress admin into the
	 * connected cloud account (any site admin could otherwise reach another person's billing, and an
	 * open-source plugin can't be a real trust boundary). The user signs in to the cloud themselves —
	 * the cloud's own auth is the boundary. Override with `VSFW_CLOUD_WEB_BASE` (wp-config) or the
	 * `vsfw_cloud_web_base` filter; defaults to production. Static so it can be localized cheaply.
	 *
	 * @return string Web base URL without a trailing slash.
	 */
	public static function dashboard_base_url() {
		$default = defined( 'VSFW_CLOUD_WEB_BASE' ) ? VSFW_CLOUD_WEB_BASE : 'https://app.wpcreatix.com';
		return untrailingslashit( apply_filters( 'vsfw_cloud_web_base', $default ) );
	}

	/**
	 * Run an authed cloud call, recovering once from a revoked-token 401 by re-exchanging a Pro
	 * license (when available). Never loops: one re-exchange per call, gated by a cooldown.
	 *
	 * @param callable $call fn( string $token ): array|\WP_Error
	 * @return array|\WP_Error
	 */
	public function authed_request( callable $call ) {
		$token = $this->get_token();
		if ( ! $token ) {
			return new \WP_Error(
				'not_connected',
				__( 'Not connected to the WPCreatix cloud.', 'vidshop-for-woocommerce' ),
				array( 'status' => 401 )
			);
		}

		$result = $call( $token );
		if ( ! $this->is_unauthorized( $result ) ) {
			return $result;
		}

		// 401 on a previously-working token → attempt a single silent Pro re-exchange.
		if ( ! $this->can_reexchange() ) {
			return $result;
		}

		$reexchanged = $this->connect_pro();
		if ( is_wp_error( $reexchanged ) ) {
			// No license / re-exchange failed → surface the original 401 so the UI prompts a reconnect.
			return $result;
		}

		$this->stamp_reexchange();
		$token = $this->get_token();
		return $token ? $call( $token ) : $result;
	}

	/**
	 * Persist the issued token + identity. Stored with autoload disabled (the token is server-only).
	 *
	 * @param array       $issued Cloud response { access_token, connection_id, user_id }.
	 * @param string      $plan   'free' | 'pro'.
	 * @param string|null $email  Account email (known on the free OTP path; null for Pro).
	 * @return void
	 */
	private function store( $issued, $plan, $email = null ) {
		$conn = array(
			'access_token'  => isset( $issued['access_token'] ) ? $issued['access_token'] : '',
			'connection_id' => isset( $issued['connection_id'] ) ? $issued['connection_id'] : null,
			'user_id'       => isset( $issued['user_id'] ) ? $issued['user_id'] : null,
			'account_email' => $email,
			'plan'          => $plan,
			'connected_at'  => current_time( 'mysql', true ),
		);

		// delete + add to guarantee autoload='no' even when the option already exists.
		delete_option( self::OPTION );
		add_option( self::OPTION, $conn, '', 'no' );
		delete_transient( self::USAGE_TRANSIENT );
	}

	/**
	 * Whether $result is a cloud 401 unauthorized WP_Error.
	 *
	 * @param mixed $result Result to inspect.
	 * @return bool
	 */
	private function is_unauthorized( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		$data = $result->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && 401 === (int) $data['status'];
	}

	/**
	 * Whether enough time has passed since the last auto re-exchange (anti-loop).
	 *
	 * @return bool
	 */
	private function can_reexchange() {
		$last = (int) get_option( self::REEXCHANGE_STAMP, 0 );
		return ( time() - $last ) >= self::REEXCHANGE_COOLDOWN;
	}

	/**
	 * Stamp the time of the last auto re-exchange.
	 *
	 * @return void
	 */
	private function stamp_reexchange() {
		update_option( self::REEXCHANGE_STAMP, time(), false );
	}

	/**
	 * This site's home URL (the cloud normalizes it to a canonical host).
	 *
	 * @return string
	 */
	private function site_url() {
		return home_url();
	}

	/**
	 * This site's name.
	 *
	 * @return string
	 */
	private function site_name() {
		return get_bloginfo( 'name' );
	}
}
