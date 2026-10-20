<?php
/**
 * Revisioned settings and encrypted credentials.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Settings stored only in this site's options. */
final class Settings {
	const OPTION = 'zeropress_static_settings';

	/** Read defaults without changing the database. */
	public static function read(): array {
		$value = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $value ) ? $value : array() );
	}

	/** Default values for missing settings. */
	private static function defaults(): array {
		return array(
			'revision'                => 'unconfigured',
			'repository'              => '',
			'branch'                  => 'main',
			'public_url'              => '',
			'allow_indexing'          => false,
			'media_rules'             => array(),
			'menu_mapping'            => array(),
			'search_enabled'          => true,
			'feed_enabled'            => true,
			'archive_enabled'         => true,
			'copyright_text'          => '',
			'attribution'             => true,
			'expose_generator'        => true,
			'newsletter_enabled'      => false,
			'newsletter_title'        => '',
			'newsletter_description'  => '',
			'newsletter_button_label' => '',
			'newsletter_signup_url'   => '',
			'newsletter_embed_url'    => '',
			'comments_enabled'        => false,
			'comments_api_url'        => '',
			'anonymous_comments'      => false,
			'credential'              => '',
			'comment_verification'    => 'pow',
			'turnstile_site_key'      => '',
			'turnstile_credential'    => '',
		);
	}

	/**
	 * Return settings safe to send to the administrator's browser.
	 *
	 * @param array $settings Settings snapshot; credentials remain server-side.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function public_view( ?array $settings = null ): array {
		$settings                         = array_merge( self::defaults(), $settings ?? self::read() );
		$settings['has_token']            = '' !== $settings['credential'];
		$settings['has_turnstile_secret'] = '' !== $settings['turnstile_credential'];
		$settings['menu_mapping']         = (object) ( $settings['menu_mapping'] ?? array() );
		unset( $settings['credential'], $settings['turnstile_credential'] );
		return $settings;
	}

	/**
	 * Normalize a submitted settings draft, preserving registered credentials.
	 *
	 * @param array  $input Submitted values validated by this service.
	 * @param array  $current Current revisioned settings.
	 * @param string $section Optional panel scope; other saved values are retained.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function draft( array $input, ?array $current = null, ?string $section = null ): array {
		$current = array_merge( self::defaults(), $current ?? self::read() );
		if ( ! isset( $input['revision'] ) || $input['revision'] !== $current['revision'] ) {
			throw new Failure( 'SETTINGS_CHANGED', 409 );
		}
		if ( null !== $section ) {
			$sections = array(
				'site'        => array( 'public_url', 'allow_indexing' ),
				'media'       => array( 'media_rules' ),
				'menus'       => array( 'menu_mapping' ),
				'discovery'   => array( 'search_enabled', 'feed_enabled', 'archive_enabled' ),
				'information' => array( 'copyright_text', 'attribution', 'expose_generator' ),
				'newsletter'  => array( 'newsletter_enabled', 'newsletter_title', 'newsletter_description', 'newsletter_button_label', 'newsletter_signup_url', 'newsletter_embed_url' ),
				'comments'    => array( 'comments_enabled', 'comments_api_url', 'anonymous_comments' ),
				'turnstile'   => array( 'comment_verification', 'turnstile_keys' ),
				'github'      => array( 'repository', 'branch' ),
			);
			if ( ! isset( $sections[ $section ] ) || array_diff( array_keys( $input ), array_merge( array( 'revision' ), $sections[ $section ] ) ) ) {
				throw new Failure( 'INVALID_SETTINGS' );
			}
			// Merge only this panel into the same revision used by the atomic save.
			$input = array_merge( $current, $input );
		}
		$input      = array_merge( self::defaults(), $input );
		$newsletter = array( 'newsletter_enabled' => self::boolean( $input, 'newsletter_enabled' ) );
		foreach ( array(
			'title'        => 200,
			'description'  => 2000,
			'button_label' => 200,
			'signup_url'   => 2048,
			'embed_url'    => 2048,
		) as $key => $limit ) {
			$name   = 'newsletter_' . $key;
			$value  = self::string( $input, $name );
			$reason = 'INVALID_' . strtoupper( $name );
			$length = preg_match_all( '/./us', $value );
			if ( false === $length || $length > $limit ) {
				// Field-specific codes are serialized as JSON, never rendered as HTML.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new Failure( $reason );
			}
			$newsletter[ $name ] = str_ends_with( $key, '_url' ) ? self::newsletter_url( $value, $reason ) : $value;
		}
		if ( $newsletter['newsletter_enabled'] && '' === $newsletter['newsletter_signup_url'] && '' === $newsletter['newsletter_embed_url'] ) {
			throw new Failure( 'NEWSLETTER_URL_REQUIRED' );
		}
		$repository = self::repository( self::string( $input, 'repository' ) );
		$branch     = self::string( $input, 'branch' );
		self::branch( $branch );
		$public_url       = self::origin( self::string( $input, 'public_url' ) );
		$api_url          = self::api_url( self::string( $input, 'comments_api_url' ) );
		$comments_enabled = self::boolean( $input, 'comments_enabled' );
		if ( $comments_enabled && '' === $api_url ) {
			throw new Failure( 'COMMENTS_URL_REQUIRED' );
		}
		$mapping = Menus::normalize( $input['menu_mapping'] ?? array() );
		if ( null === $section || 'menus' === $section ) {
			Menus::selected( $mapping );
		}
		$rules = Media_Rewriter::normalize( $input['media_rules'] ?? array() );
		$mode  = $input['comment_verification'] ?? $current['comment_verification'];
		if ( ! in_array( $mode, array( 'pow', 'turnstile' ), true ) ) {
			throw new Failure( 'INVALID_SETTINGS' );
		}
		$site_key             = $current['turnstile_site_key'];
		$turnstile_credential = $current['turnstile_credential'];
		if ( array_key_exists( 'turnstile_keys', $input ) ) {
			$keys = $input['turnstile_keys'];
			if ( ! is_array( $keys ) || array_diff( array_keys( $keys ), array( 'site_key', 'secret_key' ) ) ) {
				throw new Failure( 'INVALID_SETTINGS' );
			}
			$site_key = self::string( $keys, 'site_key' );
			$secret   = self::string( $keys, 'secret_key' );
			if ( '' === $site_key ) {
				throw new Failure( 'TURNSTILE_SITE_KEY_REQUIRED' );
			}
			if ( ! preg_match( '/\A[A-Za-z0-9_-]{1,1024}\z/', $site_key ) ) {
				throw new Failure( 'INVALID_TURNSTILE_SETTINGS' );
			}
			if ( '' === $secret ) {
				throw new Failure( 'TURNSTILE_SECRET_REQUIRED' );
			}
			if ( strlen( $secret ) > 1024 || preg_match( '/[\x00-\x20\x7f]/', $secret ) ) {
				throw new Failure( 'INVALID_TURNSTILE_SECRET' );
			}
			$turnstile_credential = self::encrypt( $secret, 'turnstile-secret' );
		}
		if ( 'turnstile' === $mode && ( '' === $site_key || '' === $turnstile_credential ) ) {
			throw new Failure( 'TURNSTILE_SETTINGS_REQUIRED' );
		}
		if ( $comments_enabled && self::boolean( $input, 'anonymous_comments' ) && '' === $public_url ) {
			throw new Failure( 'COMMENT_SITE_URL_REQUIRED' );
		}
		return array_merge(
			$newsletter,
			array(
				'revision'             => $current['revision'],
				'repository'           => $repository,
				'branch'               => $branch,
				'public_url'           => $public_url,
				'allow_indexing'       => self::boolean( $input, 'allow_indexing' ),
				'media_rules'          => $rules,
				'menu_mapping'         => $mapping,
				'search_enabled'       => self::boolean( $input, 'search_enabled' ),
				'feed_enabled'         => self::boolean( $input, 'feed_enabled' ),
				'archive_enabled'      => self::boolean( $input, 'archive_enabled' ),
				'copyright_text'       => self::string( $input, 'copyright_text' ),
				'attribution'          => self::boolean( $input, 'attribution' ),
				'expose_generator'     => self::boolean( $input, 'expose_generator' ),
				'comments_enabled'     => $comments_enabled,
				'comments_api_url'     => $api_url,
				'anonymous_comments'   => self::boolean( $input, 'anonymous_comments' ),
				'credential'           => $current['credential'],
				'comment_verification' => $mode,
				'turnstile_site_key'   => $site_key,
				'turnstile_credential' => $turnstile_credential,
			)
		);
	}

	/**
	 * Save with a compare-and-swap, including the encrypted credential.
	 *
	 * @param array  $input Submitted values validated by this service.
	 * @param string $section Optional panel scope.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function save( array $input, ?string $section = null ): array {
		$stored  = get_option( self::OPTION, false );
		$current = self::read();
		return self::persist( self::draft( $input, $current, $section ), $stored );
	}

	/**
	 * Register a token only when no token is stored.
	 *
	 * @param array $input Expected revision and a new token.
	 * @throws Failure If registration is invalid or conflicts with saved settings.
	 */
	public static function register_token( array $input ): array {
		list( $stored, $current ) = self::credential_state( $input, array( 'revision', 'token' ) );
		if ( '' !== $current['credential'] ) {
			throw new Failure( 'TOKEN_ALREADY_REGISTERED', 409 );
		}
		$token = self::string( $input, 'token' );
		if ( '' === $token ) {
			throw new Failure( 'TOKEN_REQUIRED' );
		}
		if ( strlen( $token ) > 1024 || preg_match( '/[\x00-\x20\x7f]/', $token ) ) {
			throw new Failure( 'INVALID_TOKEN' );
		}
		$current['credential'] = self::encrypt( $token );
		return self::persist( $current, $stored );
	}

	/**
	 * Delete only the locally stored token.
	 *
	 * @param array $input Expected settings revision.
	 * @throws Failure If the token is absent or the settings changed.
	 */
	public static function delete_token( array $input ): array {
		list( $stored, $current ) = self::credential_state( $input, array( 'revision' ) );
		if ( '' === $current['credential'] ) {
			throw new Failure( 'TOKEN_NOT_REGISTERED', 409 );
		}
		$current['credential'] = '';
		return self::persist( $current, $stored );
	}

	/**
	 * Delete the saved Turnstile pair and disable its use in one write.
	 *
	 * @param array $input Expected settings revision.
	 * @throws Failure If the keys are absent or the settings changed.
	 */
	public static function delete_turnstile_keys( array $input ): array {
		list( $stored, $current ) = self::credential_state( $input, array( 'revision' ) );
		if ( '' === $current['turnstile_site_key'] && '' === $current['turnstile_credential'] ) {
			throw new Failure( 'TURNSTILE_KEYS_MISSING', 409 );
		}
		$current['turnstile_site_key']   = '';
		$current['turnstile_credential'] = '';
		$current['comment_verification'] = 'pow';
		return self::persist( $current, $stored );
	}

	/**
	 * Read the snapshot for a credential-only mutation.
	 *
	 * @param array $input Submitted credential operation values.
	 * @param array $keys Allowed input keys.
	 * @throws Failure If the input or revision is invalid.
	 */
	private static function credential_state( array $input, array $keys ): array {
		if ( array_diff( array_keys( $input ), $keys ) ) {
			throw new Failure( 'INVALID_REQUEST' );
		}
		$stored  = get_option( self::OPTION, false );
		$current = self::read();
		if ( ( $input['revision'] ?? null ) !== $current['revision'] ) {
			throw new Failure( 'SETTINGS_CHANGED', 409 );
		}
		return array( $stored, $current );
	}

	/**
	 * Atomically persist a complete settings snapshot.
	 *
	 * @param array       $next Validated next state.
	 * @param array|false $stored Previously stored option used for comparison.
	 * @throws Failure If another request changed the option or the write failed.
	 */
	private static function persist( array $next, $stored ): array {
		global $wpdb;
		$next['revision'] = wp_generate_uuid4();
		if ( false === $stored ) {
			$saved = add_option( self::OPTION, $next, '', false );
		} else {
			// Atomic revision protection is not provided by update_option().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$saved = 1 === $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $next ),
					self::OPTION,
					maybe_serialize( $stored )
				)
			);
			wp_cache_delete( self::OPTION, 'options' );
		}
		if ( ! $saved ) {
			throw new Failure( 'SETTINGS_CHANGED', 409 );
		}
		return self::public_view( $next );
	}

	/**
	 * Normalize only github.com repository URLs or owner/repository pairs.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function repository( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, 'https://github.com/' ) ) {
			$value = substr( $value, strlen( 'https://github.com/' ) );
		}
		$value = rtrim( $value, '/' );
		$value = preg_replace( '/\.git$/', '', $value );
		if ( ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_.-]{1,100}\z/', $value ) ||
			in_array( explode( '/', $value )[1], array( '.', '..' ), true ) ) {
			throw new Failure( 'INVALID_REPOSITORY' );
		}
		return $value;
	}

	/**
	 * Validate a branch name, never a tag or commit selector.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function branch( string $value ): string {
		if ( strlen( $value ) > 255 || '' === $value || '@' === $value ||
			preg_match( '/[\x00-\x20\x7f~^:?*\[\\\\]/', $value ) ||
			str_contains( $value, '..' ) || str_contains( $value, '@{' ) ||
			str_ends_with( $value, '.' ) || str_starts_with( $value, 'refs/' ) ) {
			throw new Failure( 'INVALID_BRANCH' );
		}
		foreach ( explode( '/', $value ) as $segment ) {
			if ( '' === $segment || str_starts_with( $segment, '.' ) || str_ends_with( $segment, '.lock' ) ) {
				throw new Failure( 'INVALID_BRANCH' );
			}
		}
		return $value;
	}

	/**
	 * Parse a public URL without credentials, query, fragment or ambiguous characters.
	 *
	 * @param string $value Original input value.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function url( string $value ): array {
		if ( strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) {
			throw new Failure( 'INVALID_URL' );
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) ||
			empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			throw new Failure( 'INVALID_URL' );
		}
		return $parts;
	}

	/**
	 * Validate a newsletter navigation URL while retaining query and fragment.
	 *
	 * @param string $value Optional HTTP(S) or root-relative URL.
	 * @param string $reason Field-specific validation error.
	 * @throws Failure If the URL does not satisfy the Preview Data contract.
	 */
	private static function newsletter_url( string $value, string $reason ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( str_contains( $value, '\\' ) || preg_match( '/[\s\p{Cc}]|%(?![0-9a-f]{2})/iu', $value ) || str_starts_with( $value, '//' ) ) {
			// Field-specific codes are serialized as JSON, never rendered as HTML.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Failure( $reason );
		}
		$relative = str_starts_with( $value, '/' );
		$parts    = wp_parse_url( $relative ? 'https://newsletter.invalid' . $value : $value );
		if ( ! is_array( $parts ) || ! preg_match( '#\Ahttps?://#i', $relative ? 'https://newsletter.invalid' : $value ) ||
			empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			preg_match( '/[<>^|%]/', $parts['host'] ) ) {
			// Field-specific codes are serialized as JSON, never rendered as HTML.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Failure( $reason );
		}
		foreach ( explode( '/', $parts['path'] ?? '/' ) as $segment ) {
			if ( in_array( rawurldecode( $segment ), array( '.', '..' ), true ) ) {
				// Field-specific codes are serialized as JSON, never rendered as HTML.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new Failure( $reason );
			}
		}
		return $value;
	}

	/**
	 * Normalize an optional site origin.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function origin( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$parts = self::url( $value );
		if ( ! in_array( $parts['path'] ?? '', array( '', '/' ), true ) ) {
			throw new Failure( 'INVALID_SITE_URL' );
		}
		$port         = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
		$default_port = 'https' === $parts['scheme'] ? 443 : 80;
		return $parts['scheme'] . '://' . strtolower( $parts['host'] ) . ( $port !== $default_port ? ':' . $port : '' );
	}

	/**
	 * Validate an explicit WordPress REST API base.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function api_url( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$parts = self::url( $value );
		if ( ! str_ends_with( rtrim( $parts['path'] ?? '', '/' ), '/wp-json/wp/v2' ) ) {
			throw new Failure( 'INVALID_COMMENTS_URL' );
		}
		return rtrim( $value, '/' );
	}

	/**
	 * Read a string without silently coercing objects or arrays.
	 *
	 * @param array  $input Submitted values validated by this service.
	 * @param string $key Allowlisted field name.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function string( array $input, string $key ): string {
		$value = $input[ $key ] ?? '';
		if ( ! is_string( $value ) ) {
			throw new Failure( 'INVALID_SETTINGS' );
		}
		return trim( $value );
	}

	/**
	 * JSON settings use booleans, not truthy strings.
	 *
	 * @param array  $input Submitted values validated by this service.
	 * @param string $key Allowlisted field name.
	 * @return bool
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function boolean( array $input, string $key ): bool {
		$value = $input[ $key ] ?? false;
		if ( ! is_bool( $value ) ) {
			throw new Failure( 'INVALID_SETTINGS' );
		}
		return $value;
	}

	/**
	 * Derive independent encryption and preparation-signing keys.
	 *
	 * @param string $purpose Domain separation label.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function key( string $purpose ): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), 32, 'zeropress-static/v1/' . $purpose );
	}

	/**
	 * Encrypt a credential with authenticated encryption.
	 *
	 * @param string $token Plaintext credential used only on the server.
	 * @param string $purpose Encryption key purpose.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function encrypt( string $token, string $purpose = 'github-token' ): string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			throw new Failure( 'ENCRYPTION_UNAVAILABLE', 503 );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $token, 'aes-256-gcm', self::key( $purpose ), OPENSSL_RAW_DATA, $iv, $tag, 'v1', 16 );
		if ( false === $cipher ) {
			throw new Failure( 'ENCRYPTION_UNAVAILABLE', 503 );
		}
		return 'v1:' . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt only on the server immediately before a GitHub request.
	 *
	 * @param array $settings Settings snapshot; credentials remain server-side.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function token( array $settings ): string {
		return self::decrypt( $settings['credential'], 'github-token' );
	}

	/**
	 * Read the separate Turnstile secret.
	 *
	 * @param array $settings Settings snapshot.
	 * @return string
	 */
	public static function turnstile_secret( array $settings ): string {
		return self::decrypt( $settings['turnstile_credential'], 'turnstile-secret' );
	}

	/**
	 * Decode an authenticated, purpose-separated credential.
	 *
	 * @param string $value Encrypted envelope.
	 * @param string $purpose Encryption key purpose.
	 * @return string
	 * @throws Failure When the credential cannot be read.
	 */
	private static function decrypt( string $value, string $purpose ): string {
		if ( '' === $value ) {
			throw new Failure( 'TOKEN_REQUIRED' );
		}
		if ( ! function_exists( 'openssl_decrypt' ) || ! str_starts_with( $value, 'v1:' ) ) {
			throw new Failure( 'TOKEN_UNREADABLE' );
		}
		$bytes = base64_decode( substr( $value, 3 ), true );
		if ( false === $bytes || strlen( $bytes ) < 29 ) {
			throw new Failure( 'TOKEN_UNREADABLE' );
		}
		$plain = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', self::key( $purpose ), OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), 'v1' );
		if ( false === $plain ) {
			throw new Failure( 'TOKEN_UNREADABLE' );
		}
		return $plain;
	}
}
