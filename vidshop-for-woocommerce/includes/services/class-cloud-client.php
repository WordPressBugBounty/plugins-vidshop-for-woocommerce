<?php
/**
 * Cloud API client.
 *
 * Thin transport wrapper around the WPCreatix-AI cloud HTTP API. Knows the base URL and
 * endpoint paths, builds JSON (and optionally bearer-authed) requests via `wp_remote_*`,
 * and normalizes the cloud's error envelope ({ code, message, details }) into `WP_Error`.
 *
 * Stateless about auth: methods that need a token take it as a parameter. The token + the
 * connection state live in {@see Cloud_Connection}.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

/**
 * Cloud API client.
 */
class Cloud_Client {

	/** Auth: request a passwordless login code. */
	const PATH_LOGIN_CODE = '/api/auth/login-code';

	/** Auth: verify a login code (the cloud JWT comes back in a Set-Cookie header). */
	const PATH_VERIFY = '/api/auth/verify';

	/** Auth: exchange a cloud JWT session for a plugin access token (free tier). */
	const PATH_ISSUE_TOKEN = '/api/auth/issue-token';

	/** Auth: exchange a Freemius license for a plugin access token (Pro). */
	const PATH_EXCHANGE_LICENSE = '/api/auth/exchange-license';

	/** Auth: disconnect the calling site (revokes its cloud connection + bound tokens). */
	const PATH_DISCONNECT = '/api/auth/disconnect';

	/** Usage snapshot (plan + credit balances) for the connected site. */
	const PATH_USAGE = '/api/usage';

	/** Generation options (allowed durations + their credit costs, audio modes, default). */
	const PATH_GENERATE_OPTIONS = '/api/generate/options';

	/** Start a video generation. */
	const PATH_GENERATE = '/api/generate';

	/** Videos collection (a single video is PATH_VIDEOS . '/' . id). */
	const PATH_VIDEOS = '/api/videos';

	/** Catalog: upsert products. */
	const PATH_CATALOG_PRODUCTS = '/api/catalog/products';

	/** Catalog: upsert the store profile. */
	const PATH_CATALOG_SITE_PROFILE = '/api/catalog/site-profile';

	/** Catalog: keyless product-image upload to R2 (multipart). */
	const PATH_UPLOAD_IMAGE = '/api/catalog/upload-image';

	/** Name of the cloud session cookie carrying the JWT. */
	const SESSION_COOKIE = 'wpx_auth';

	/** Request timeout (seconds). */
	const TIMEOUT = 20;

	/** Timeout for the image upload (larger payload). */
	const UPLOAD_TIMEOUT = 60;

	/**
	 * Resolve the cloud API base URL.
	 *
	 * Override order: the `vsfw_cloud_api_base` filter wins, else the `VSFW_CLOUD_API_BASE`
	 * constant (definable in wp-config.php for local dev), else the production default.
	 *
	 * @return string Base URL without a trailing slash.
	 */
	public function base_url() {
		$default = defined( 'VSFW_CLOUD_API_BASE' ) ? VSFW_CLOUD_API_BASE : 'https://app.wpcreatix.com';
		return untrailingslashit( apply_filters( 'vsfw_cloud_api_base', $default ) );
	}

	/**
	 * Request a passwordless login code for an email (free-tier connect, step 1).
	 *
	 * @param string $email Email address.
	 * @return array|\WP_Error Decoded body on success, WP_Error otherwise.
	 */
	public function request_login_code( $email ) {
		$res = $this->request( 'POST', self::PATH_LOGIN_CODE, array( 'body' => array( 'email' => $email ) ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Verify a login code and capture the cloud JWT from the Set-Cookie header (free connect, step 2).
	 *
	 * @param string $email Email address.
	 * @param string $code  6-digit code.
	 * @return array|\WP_Error { jwt, is_new_user, user } on success, WP_Error otherwise.
	 */
	public function verify_login_code( $email, $code ) {
		$res = $this->request( 'POST', self::PATH_VERIFY, array( 'body' => array( 'email' => $email, 'code' => $code ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$jwt = $this->extract_session_cookie( $res['cookies'] );
		if ( ! $jwt ) {
			return new \WP_Error(
				'cloud_no_session',
				__( 'The cloud did not return a session. Please try again.', 'vidshop-for-woocommerce' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'jwt'         => $jwt,
			'is_new_user' => ! empty( $res['body']['is_new_user'] ),
			'user'        => isset( $res['body']['user'] ) ? $res['body']['user'] : null,
		);
	}

	/**
	 * Exchange a cloud JWT for a plugin access token bound to this site (free tier).
	 *
	 * @param string $jwt       Cloud session JWT (from verify_login_code()).
	 * @param string $site_url  Site home URL.
	 * @param string $site_name Site name.
	 * @return array|\WP_Error { access_token, connection_id, user_id, expires_at } or WP_Error.
	 */
	public function issue_token( $jwt, $site_url, $site_name ) {
		$res = $this->request(
			'POST',
			self::PATH_ISSUE_TOKEN,
			array(
				'token' => $jwt,
				'body'  => array(
					'site_url'  => $site_url,
					'site_name' => $site_name,
				),
			)
		);
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Exchange a Freemius license for a plugin access token (Pro).
	 *
	 * @param string $license_key Freemius license/secret key.
	 * @param string $site_url    Site home URL.
	 * @param string $site_name   Site name.
	 * @return array|\WP_Error { access_token, connection_id, user_id, expires_at } or WP_Error.
	 */
	public function exchange_license( $license_key, $site_url, $site_name ) {
		$res = $this->request(
			'POST',
			self::PATH_EXCHANGE_LICENSE,
			array(
				'body' => array(
					'license_key' => $license_key,
					'site_url'    => $site_url,
					'site_name'   => $site_name,
				),
			)
		);
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Disconnect the calling site on the cloud: revokes its connection + the tokens bound to it, freeing
	 * the site on the user's SaaS account. Best-effort by design — the caller still clears local state if
	 * this fails (the token may already be revoked, returning 401).
	 *
	 * @param string $token Plugin access token (vsk_...).
	 * @return array|\WP_Error Decoded body on success, WP_Error otherwise.
	 */
	public function disconnect( $token ) {
		$res = $this->request( 'POST', self::PATH_DISCONNECT, array( 'token' => $token ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Fetch the usage snapshot (plan + credit balances) for the connected site.
	 *
	 * @param string $token Plugin access token (vsk_...).
	 * @return array|\WP_Error Decoded body or WP_Error.
	 */
	public function get_usage( $token ) {
		$res = $this->request( 'GET', self::PATH_USAGE, array( 'token' => $token ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Fetch generation options (allowed durations + credit costs, audio modes, default).
	 *
	 * @param string $token Plugin access token.
	 * @return array|\WP_Error Decoded body or WP_Error.
	 */
	public function generation_options( $token ) {
		$res = $this->request( 'GET', self::PATH_GENERATE_OPTIONS, array( 'token' => $token ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Upsert the store profile (country, currency, brand) so the cloud can ground prompts.
	 *
	 * @param string $token   Plugin access token.
	 * @param array  $profile Profile fields (storeCountry, locale, currency, brandName, niche, ...).
	 * @return array|\WP_Error Decoded body or WP_Error.
	 */
	public function sync_site_profile( $token, array $profile ) {
		$res = $this->request( 'PUT', self::PATH_CATALOG_SITE_PROFILE, array( 'token' => $token, 'body' => $profile ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Upsert a batch of products (max 50) into the cloud catalog.
	 *
	 * @param string $token    Plugin access token.
	 * @param array  $products List of product arrays (wcProductId, title, imageUrl, ...).
	 * @return array|\WP_Error Decoded body or WP_Error.
	 */
	public function sync_products( $token, array $products ) {
		$res = $this->request(
			'POST',
			self::PATH_CATALOG_PRODUCTS,
			array(
				'token' => $token,
				'body'  => array( 'products' => $products ),
			)
		);
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Start a video generation. Returns immediately with a pending VideoResponse
	 * (the create response carries `estimated_seconds` for poll scheduling).
	 *
	 * @param string $token   Plugin access token.
	 * @param array  $payload Generate params (productId, durationSeconds, audioMode, template, extraPrompt, imageUrl, ...).
	 * @return array|\WP_Error VideoResponse body or WP_Error.
	 */
	public function generate( $token, array $payload ) {
		$res = $this->request( 'POST', self::PATH_GENERATE, array( 'token' => $token, 'body' => $payload ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Fetch one generated video's current state (status + URLs).
	 *
	 * @param string $token Plugin access token.
	 * @param int    $id    Cloud video id (ai_call_id).
	 * @return array|\WP_Error VideoResponse body or WP_Error.
	 */
	public function get_video( $token, $id ) {
		$res = $this->request( 'GET', self::PATH_VIDEOS . '/' . (int) $id, array( 'token' => $token ) );
		return is_wp_error( $res ) ? $res : $res['body'];
	}

	/**
	 * Upload a product image to R2 (keyless multipart push). The plugin reads the image LOCALLY and
	 * sends the bytes; the cloud writes to R2 and returns the public URL — so generation never fetches
	 * the merchant site (works on localhost / self-signed / firewalled stores).
	 *
	 * @param string $token     Plugin access token.
	 * @param string $file_path Absolute local path to the image file.
	 * @param string $mime      Image MIME type (e.g. image/jpeg).
	 * @return array|\WP_Error { url, key } on success, WP_Error otherwise.
	 */
	public function upload_image( $token, $file_path, $mime ) {
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'image_missing', __( 'The product image file could not be found.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = file_get_contents( $file_path );
		if ( false === $bytes ) {
			return new \WP_Error( 'image_unreadable', __( 'The product image file could not be read.', 'vidshop-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$boundary = wp_generate_password( 24, false );
		$filename = basename( $file_path );
		$eol      = "\r\n";

		$payload  = '--' . $boundary . $eol;
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
		$payload .= 'Content-Type: ' . $mime . $eol . $eol;
		$payload .= $bytes . $eol;
		$payload .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			$this->base_url() . self::PATH_UPLOAD_IMAGE,
			array(
				'method'  => 'POST',
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'cloud_unreachable', __( 'Could not reach the WPCreatix cloud to upload the image.', 'vidshop-for-woocommerce' ), array( 'status' => 503 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( $status < 200 || $status >= 300 ) {
			$code = $this->error_code( $body, 'image_upload_failed' );
			$msg  = isset( $body['message'] ) ? $body['message'] : __( 'The image upload failed.', 'vidshop-for-woocommerce' );
			return new \WP_Error( $code, $msg, array( 'status' => $status ) );
		}

		return $body; // { url, key }
	}

	/**
	 * Perform an HTTP request against the cloud and normalize the result.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path (begins with '/').
	 * @param array  $opts   Optional { body?: array (JSON-encoded), token?: string (Bearer) }.
	 * @return array|\WP_Error { status:int, body:array, cookies:array } on 2xx, WP_Error otherwise.
	 */
	private function request( $method, $path, array $opts = array() ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( ! empty( $opts['token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $opts['token'];
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);

		if ( isset( $opts['body'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $opts['body'] );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			// Transport-level failure (DNS, timeout, TLS) — the cloud was unreachable.
			return new \WP_Error(
				'cloud_unreachable',
				__( 'Could not reach the WPCreatix cloud. Check your connection and try again.', 'vidshop-for-woocommerce' ),
				array(
					'status' => 503,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$body   = $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( $status < 200 || $status >= 300 ) {
			// The cloud's error envelope is `{ error: <code>, message, details? }` (its DomainError shape);
			// fall back to `code` then a generic. Reading the wrong key here would collapse every cloud
			// error to a generic code and break per-code UI (e.g. the insufficient_credits upsell).
			$code    = $this->error_code( $body );
			$message = isset( $body['message'] ) ? $body['message'] : __( 'The cloud request failed.', 'vidshop-for-woocommerce' );
			return new \WP_Error(
				$code,
				$message,
				array(
					'status'  => $status,
					'details' => isset( $body['details'] ) ? $body['details'] : $body,
				)
			);
		}

		return array(
			'status'  => $status,
			'body'    => $body,
			'cookies' => wp_remote_retrieve_cookies( $response ),
		);
	}

	/**
	 * Resolve the stable error code from a decoded cloud error body.
	 *
	 * The cloud's GlobalExceptionFilter emits DomainErrors as `{ error: <code>, message, details? }`
	 * and HTTP/validation errors as `{ error: <code>, ... }` too — so `error` is the field to read.
	 * Older/edge responses may use `code`; fall back to that, then to $default.
	 *
	 * @param array  $body    Decoded JSON error body.
	 * @param string $default Code to use when neither key is present.
	 * @return string
	 */
	private function error_code( $body, $default = 'cloud_error' ) {
		if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			return $body['error'];
		}
		if ( isset( $body['code'] ) && is_string( $body['code'] ) ) {
			return $body['code'];
		}
		return $default;
	}

	/**
	 * Find the cloud session JWT among the response cookies.
	 *
	 * @param array $cookies Array of WP_Http_Cookie.
	 * @return string|null The JWT value, or null if absent.
	 */
	private function extract_session_cookie( $cookies ) {
		if ( ! is_array( $cookies ) ) {
			return null;
		}
		foreach ( $cookies as $cookie ) {
			if ( isset( $cookie->name ) && self::SESSION_COOKIE === $cookie->name ) {
				return $cookie->value;
			}
		}
		return null;
	}
}
