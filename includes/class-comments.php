<?php
/**
 * Runtime policy and verification for explicitly marked theme submissions.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Leaves unmarked WordPress clients and normal comment processing alone. */
final class Comments {
	const API_NAMESPACE = 'zeropress-static/v1';
	const CLIENT        = 'theme-v1';
	const ACTION        = 'zpsp_comment_create';
	const USED_PREFIX   = 'zpsp_comment_used_';

	/** Register only public policy endpoints and comment-creation hooks. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_allow_anonymous_comments', array( self::class, 'allow_anonymous' ), 10, 2 );
		add_filter( 'rest_pre_insert_comment', array( self::class, 'before_insert' ), 100, 2 );
		add_filter( 'rest_post_dispatch', array( self::class, 'no_store' ), 10, 3 );
	}

	/** Register read policy and short-lived verification issuance. */
	public static function routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/comments/policy',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'policy_request' ),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/comments/challenge',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'challenge_request' ),
			)
		);
	}

	/**
	 * Prevent storage of both successful and rejected policy responses.
	 *
	 * @param mixed            $response REST response.
	 * @param mixed            $server REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function no_store( $response, $server, $request ) {
		if ( str_starts_with( $request->get_route(), '/' . self::API_NAMESPACE . '/' ) ) {
			$response = rest_ensure_response( $response );
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * Read JSON or form fields, never query/header parameters.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private static function body( $request ): array {
		$value = $request->is_json_content_type() ? $request->get_json_params() : $request->get_body_params();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Restrict hooks to creation of a marked standard comment.
	 *
	 * @param mixed $request Request, possibly absent in another plugin's filter call.
	 * @return bool
	 */
	private static function marked( $request ): bool {
		return $request instanceof \WP_REST_Request && 'POST' === $request->get_method() &&
			'/wp/v2/comments' === rtrim( $request->get_route(), '/' ) &&
			self::CLIENT === ( self::body( $request )['zeropress_client'] ?? null );
	}

	/**
	 * Normalize an explicitly supplied public post ID.
	 *
	 * @param mixed $value Request value.
	 * @return int
	 * @throws Failure For invalid IDs.
	 */
	private static function post_id( $value ): int {
		if ( ! ( is_int( $value ) || is_string( $value ) ) || ! preg_match( '/\A[1-9][0-9]{0,14}\z/', (string) $value ) ) {
			throw new Failure( 'INVALID_COMMENT_TARGET' );
		}
		return (int) $value;
	}

	/**
	 * Read the configured origin required for active comment writing.
	 *
	 * @param array $settings Settings snapshot.
	 * @return string
	 * @throws Failure When the writing origin is unavailable.
	 */
	private static function site_origin( array $settings ): string {
		try {
			$origin = Settings::origin( $settings['public_url'] );
		} catch ( \Throwable $error ) {
			throw new Failure( 'COMMENT_SITE_URL_REQUIRED', 503 );
		}
		if ( '' === $origin ) {
			throw new Failure( 'COMMENT_SITE_URL_REQUIRED', 503 );
		}
		return $origin;
	}

	/**
	 * Match browser POST origins before issuing or verifying a proof.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param array            $settings Settings snapshot.
	 * @throws Failure When Origin is absent, malformed or not the configured site.
	 */
	private static function require_origin( $request, array $settings ): void {
		$expected = self::site_origin( $settings );
		$header   = $request->get_header( 'origin' );
		// Origin is a single serialized HTTP(S) origin; paths and lists are invalid.
		if ( ! is_string( $header ) || ! preg_match( '/\Ahttps?:\/\/(?:\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+)(?::[0-9]{1,5})?\z/', $header ) ) {
			throw new Failure( 'COMMENT_ORIGIN_INVALID', 403 );
		}
		try {
			$origin = Settings::origin( $header );
		} catch ( Failure $error ) {
			throw new Failure( 'COMMENT_ORIGIN_INVALID', 403 );
		}
		if ( $expected !== $origin ) {
			throw new Failure( 'COMMENT_ORIGIN_MISMATCH', 403 );
		}
	}

	/**
	 * Evaluate public content and native WordPress writing restrictions.
	 *
	 * @param int   $post_id Public content ID.
	 * @param array $settings Settings snapshot.
	 * @return array
	 * @throws Failure For an unavailable target or service.
	 */
	private static function policy( int $post_id, array $settings ): array {
		if ( is_multisite() ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ||
			'publish' !== $post->post_status || '' !== $post->post_password ) {
			throw new Failure( 'COMMENT_TARGET_UNAVAILABLE', 404 );
		}
		$reason = 'allowed';
		if ( ! $settings['comments_enabled'] || ! $settings['anonymous_comments'] ) {
			$reason = 'read_only';
		} elseif ( get_option( 'comment_registration' ) ) {
			$reason = 'login_required';
		} elseif ( ! comments_open( $post_id ) ) {
			$reason = 'comments_closed';
		}
		$mode = $settings['comment_verification'];
		if ( ! in_array( $mode, array( 'pow', 'turnstile' ), true ) ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
		if ( 'allowed' === $reason ) {
			self::site_origin( $settings );
			if ( 'turnstile' === $mode ) {
				self::turnstile_config( $settings );
			}
		}
		return array(
			'version'   => 1,
			'post'      => $post_id,
			'revision'  => $settings['revision'],
			'can_write' => 'allowed' === $reason,
			'reason'    => $reason,
			'mode'      => $mode,
		);
	}

	/**
	 * Policy endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function policy_request( $request ) {
		try {
			$query = $request->get_query_params();
			return new \WP_REST_Response( self::policy( self::post_id( $query['post'] ?? null ), Settings::read() ) );
		} catch ( \Throwable $error ) {
			return self::error( $error );
		}
	}

	/**
	 * Issue a signed PoW challenge or public Turnstile widget settings.
	 *
	 * @throws Failure Internally caught and returned as a safe REST error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function challenge_request( $request ) {
		try {
			$body = self::body( $request );
			if ( self::CLIENT !== ( $body['zeropress_client'] ?? null ) ) {
				throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
			}
			$settings = Settings::read();
			$policy   = self::policy( self::post_id( $body['post'] ?? null ), $settings );
			self::require_writing( $policy );
			self::require_origin( $request, $settings );
			if ( 'pow' === $policy['mode'] ) {
				$now           = time();
				$payload       = array(
					'v'          => 1,
					'post'       => $policy['post'],
					'revision'   => $settings['revision'],
					'iat'        => $now,
					'exp'        => $now + 60,
					'nonce'      => bin2hex( random_bytes( 16 ) ),
					'difficulty' => 15,
				);
				$segment       = self::base64url( wp_json_encode( $payload ) );
				$token         = 'zpc1.' . $segment . '.' . self::signature( $segment );
				$policy['pow'] = array(
					'challenge_token' => $token,
					'difficulty'      => 15,
					'expires_at'      => gmdate( 'c', $now + 60 ),
				);
			} else {
				$policy['turnstile'] = array(
					'site_key' => $settings['turnstile_site_key'],
					'action'   => self::ACTION,
				);
			}
			return new \WP_REST_Response( $policy );
		} catch ( \Throwable $error ) {
			return self::error( $error );
		}
	}

	/**
	 * Grant no additional permission to unmarked clients.
	 *
	 * @param bool  $allowed Previous filter result.
	 * @param mixed $request REST request.
	 * @return bool
	 */
	public static function allow_anonymous( $allowed, $request = null ) {
		if ( ! self::marked( $request ) ) {
			return $allowed;
		}
		try {
			return $allowed || self::policy( self::post_id( $request->get_param( 'post' ) ), Settings::read() )['can_write'];
		} catch ( \Throwable $error ) {
			return $allowed;
		}
	}

	/**
	 * Enforce policy even if another plugin grants anonymous permission.
	 *
	 * @throws Failure Internally caught and returned as a safe REST error.
	 *
	 * @param array|\WP_Error  $prepared Prepared comment.
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function before_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! self::marked( $request ) ) {
			return $prepared;
		}
		try {
			$settings = Settings::read();
			$post_id  = self::post_id( $prepared['comment_post_ID'] ?? null );
			$policy   = self::policy( $post_id, $settings );
			self::require_writing( $policy );
			self::require_origin( $request, $settings );
			$body = self::body( $request );
			if ( ( $body['zeropress_policy_revision'] ?? null ) !== $settings['revision'] ) {
				throw new Failure( 'COMMENT_POLICY_CHANGED', 409 );
			}
			$pow = null;
			if ( 'pow' === $policy['mode'] ) {
				$pow = self::verify_pow( $body, $post_id, $settings['revision'] );
			} else {
				self::verify_turnstile( $body, $settings );
			}
			// A remote verification can outlive a settings save or a content change.
			wp_cache_delete( Settings::OPTION, 'options' );
			wp_cache_delete( $post_id, 'posts' );
			$latest = Settings::read();
			if ( $settings['revision'] !== $latest['revision'] ) {
				throw new Failure( 'COMMENT_POLICY_CHANGED', 409 );
			}
			self::require_writing( self::policy( $post_id, $latest ) );
			if ( $pow ) {
				self::consume( $pow );
			}
			return $prepared;
		} catch ( \Throwable $error ) {
			return self::error( $error );
		}
	}

	/**
	 * Require explicit writing permission.
	 *
	 * @param array $policy Current policy.
	 * @throws Failure When writing is disabled.
	 */
	private static function require_writing( array $policy ): void {
		if ( ! $policy['can_write'] ) {
			throw new Failure( 'COMMENT_WRITING_DISABLED', 403 );
		}
	}

	/**
	 * Encode compact URL-safe signed segments.
	 *
	 * @param string $value Bytes.
	 * @return string
	 */
	private static function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Sign an exact payload segment with an independent key.
	 *
	 * @param string $segment Encoded payload.
	 * @return string
	 */
	private static function signature( string $segment ): string {
		return self::base64url( hash_hmac( 'sha256', 'zpc1.' . $segment, Settings::key( 'comment-challenge' ), true ) );
	}

	/**
	 * Verify a bounded solution and all signed context before database access.
	 *
	 * @param array  $body Submitted fields.
	 * @param int    $post_id Expected content ID.
	 * @param string $revision Current revision.
	 * @return array
	 * @throws Failure For invalid or expired proof.
	 */
	private static function verify_pow( array $body, int $post_id, string $revision ): array {
		$token    = $body['zeropress_challenge_token'] ?? null;
		$solution = $body['zeropress_challenge_solution'] ?? null;
		if ( ! is_string( $token ) || strlen( $token ) > 2048 || ! is_string( $solution ) ||
			! preg_match( '/\A[0-9]{1,16}\z/', $solution ) ||
			! preg_match( '/\Azpc1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})\z/', $token, $parts ) ||
			! hash_equals( self::signature( $parts[1] ), $parts[2] ) ) {
			throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
		}
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true );
		if ( ! is_array( $payload ) || 1 !== ( $payload['v'] ?? null ) || ( $payload['post'] ?? null ) !== $post_id ||
			( $payload['revision'] ?? null ) !== $revision || 15 !== ( $payload['difficulty'] ?? null ) ||
			! is_int( $payload['iat'] ?? null ) || ! is_int( $payload['exp'] ?? null ) ||
			60 !== $payload['exp'] - $payload['iat'] || $payload['iat'] > time() ||
			! is_string( $payload['nonce'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $payload['nonce'] ) ) {
			throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
		}
		if ( $payload['exp'] <= time() ) {
			throw new Failure( 'EXPIRED_COMMENT_VERIFICATION' );
		}
		$hash = hash( 'sha256', $token . '.' . $solution, true );
		if ( 0 !== ord( $hash[0] ) || 0 !== ( ord( $hash[1] ) & 254 ) ) {
			throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
		}
		return $payload;
	}

	/**
	 * Atomically consume a nonce. Options API caches are deliberately not used.
	 *
	 * @param array $payload Verified payload.
	 * @throws Failure For replay or storage failure.
	 */
	private static function consume( array $payload ): void {
		global $wpdb;
		if ( $payload['exp'] <= time() ) {
			throw new Failure( 'EXPIRED_COMMENT_VERIFICATION' );
		}
		$name = self::USED_PREFIX . sprintf( '%012d', $payload['exp'] ) . '_' . $payload['nonce'];
		// The unique option name enforces single use across concurrent connections.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')", $name ) );
		if ( false === $result ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
		if ( 1 !== $result ) {
			throw new Failure( 'COMMENT_VERIFICATION_ALREADY_USED', 409 );
		}
		// Cleanup never makes an unexpired nonce reusable. Limit work on every insertion.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name >= %s AND option_name < %s ORDER BY option_name LIMIT 100", self::USED_PREFIX, self::USED_PREFIX . sprintf( '%012d', time() ) . '_' ) );
	}

	/**
	 * Validate and decrypt Turnstile configuration without exposing secrets.
	 *
	 * @param array $settings Settings snapshot.
	 * @return string
	 * @throws Failure When verification is unavailable.
	 */
	private static function turnstile_config( array $settings ): string {
		if ( '' === $settings['turnstile_site_key'] || ! wp_parse_url( $settings['public_url'], PHP_URL_HOST ) ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
		try {
			return Settings::turnstile_secret( $settings );
		} catch ( Failure $error ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
	}

	/**
	 * Validate once with the fixed Cloudflare endpoint; never downgrade on error.
	 *
	 * @param array $body Submitted fields.
	 * @param array $settings Settings snapshot.
	 * @throws Failure On failed or unavailable verification.
	 */
	private static function verify_turnstile( array $body, array $settings ): void {
		$token = $body['zeropress_turnstile_token'] ?? null;
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 || trim( $token ) !== $token ) {
			throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
		}
		$response = wp_remote_post(
			// Opt-in verification API documented in readme.txt; no remote asset is loaded by this request.
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 8192,
				'body'                => array(
					'secret'   => self::turnstile_config( $settings ),
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
		$value = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $value ) || ! is_bool( $value['success'] ?? null ) ) {
			throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
		}
		if ( ! $value['success'] ) {
			$codes = $value['error-codes'] ?? array();
			if ( ! is_array( $codes ) || count( array_filter( $codes, 'is_string' ) ) !== count( $codes ) || array_diff( $codes, array( 'invalid-input-response', 'missing-input-response', 'timeout-or-duplicate' ) ) || ! $codes ) {
				throw new Failure( 'COMMENT_VERIFICATION_UNAVAILABLE', 503 );
			}
			throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
		}
		if ( ! is_string( $value['hostname'] ?? null ) || self::ACTION !== ( $value['action'] ?? null ) ||
			strtolower( (string) wp_parse_url( $settings['public_url'], PHP_URL_HOST ) ) !== strtolower( (string) ( $value['hostname'] ?? '' ) ) ) {
			throw new Failure( 'INVALID_COMMENT_VERIFICATION' );
		}
	}

	/**
	 * Return safe, stable errors without request values or external responses.
	 *
	 * @param \Throwable $error Internal failure.
	 * @return \WP_Error
	 */
	private static function error( \Throwable $error ): \WP_Error {
		$reason = $error instanceof Failure ? $error->reason : 'COMMENT_VERIFICATION_UNAVAILABLE';
		$status = $error instanceof Failure ? $error->http_status : 503;
		return new \WP_Error( 'zpsp_' . strtolower( $reason ), __( 'Comment writing could not be verified. Refresh the writing options and try again.', 'zeropress-static' ), array( 'status' => $status ) );
	}
}
