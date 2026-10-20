<?php
/**
 * Optional public REST access policy for the WordPress backend.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Restrict unauthenticated HTTP requests after WordPress REST authentication. */
final class Rest_Access {

	/** Register after core's OPTIONS handling and other pre-dispatch responses. */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'restrict' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_send_nocache_headers', array( self::class, 'no_cache' ) );
	}

	/**
	 * Check the resolved route and effective method, including embedded requests.
	 *
	 * Local rest_do_request() calls outside an HTTP REST request keep their normal
	 * behavior. During HTTP serving, WordPress checks authentication before dispatch
	 * and clears cookie users without a REST nonce before this filter runs.
	 *
	 * @param mixed            $result Earlier response or error.
	 * @param \WP_REST_Server  $server REST server.
	 * @param \WP_REST_Request $request Resolved REST request.
	 * @return mixed Earlier result or an access error.
	 */
	public static function restrict( $result, $server, $request ) {
		if ( ! wp_is_serving_rest_request() || is_wp_error( $result ) || is_user_logged_in() ) {
			return $result;
		}
		$settings = Settings::read();
		if ( ! $settings['restrict_public_rest_api'] ) {
			return $result;
		}
		// Check parsed parameter names, including PHP's decoding and key normalization.
		// The internal permalink rewrite is not a client-supplied GET or POST field.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- This rejects public routing overrides; no value is used.
		if ( array_key_exists( 'rest_route', $_GET ) || array_key_exists( 'rest_route', $_POST ) ) {
			return new \WP_Error( 'zpsp_rest_route_override', __( 'Client-supplied REST route overrides are disabled.', 'zeropress-static' ), array( 'status' => 400 ) );
		}
		$routes = array(
			'/wp/v2/comments' => array( 'GET', 'HEAD', 'POST', 'OPTIONS' ),
			'/' . Comments::API_NAMESPACE . '/comments/policy' => array( 'GET', 'HEAD', 'OPTIONS' ),
			'/' . Comments::API_NAMESPACE . '/comments/challenge' => array( 'POST', 'OPTIONS' ),
		);
		$route  = rtrim( $request->get_route(), '/' );
		if ( $settings['comments_enabled'] && isset( $routes[ $route ] ) && in_array( $request->get_method(), $routes[ $route ], true ) ) {
			return $result;
		}
		return new \WP_Error( 'zpsp_rest_api_restricted', __( 'Public access to this REST API is restricted.', 'zeropress-static' ), array( 'status' => 403 ) );
	}

	/**
	 * Keep HTTP responses from being reused across access-policy changes.
	 *
	 * @param bool $send Whether WordPress will send no-cache headers.
	 * @return bool
	 */
	public static function no_cache( bool $send ): bool {
		return $send || ( wp_is_serving_rest_request() && Settings::read()['restrict_public_rest_api'] );
	}
}
