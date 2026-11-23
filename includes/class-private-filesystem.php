<?php
/** Native POSIX checks for private native storage.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

// Native handles and identity checks cannot use WordPress's remote filesystem API.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
// Fixed internal failure codes, not host paths or diagnostics.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/** Keeps platform permission checks out of publication and recovery protocols. */
final class Private_Filesystem {

	/** Whether this PHP worker uses Windows filesystem semantics. */
	public static function windows(): bool {
		return 'Windows' === PHP_OS_FAMILY;
	}

	/** Resolve the optional host-owned parent; defaults remain unchanged on Unix.
	 *
	 * @param string $reason Caller-specific storage failure.
	 * @throws Failure If the private parent is unavailable.
	 */
	public static function base( string $reason ): string {
		if ( self::windows() ) {
			// Windows exports must stay in the memory backend.
			throw new Failure( $reason, 503 );
		}
		$path = defined( 'ZEROPRESS_STATIC_PRIVATE_DIR' ) ? ZEROPRESS_STATIC_PRIVATE_DIR : sys_get_temp_dir();
		if ( ! is_string( $path ) || ! self::absolute( $path ) ) {
			throw new Failure( $reason, 503 );
		}
		$real = realpath( $path );
		if ( false === $real ) {
			throw new Failure( $reason, 503 );
		}
		return $real;
	}

	/** Accept absolute POSIX paths, never wrappers or embedded NUL bytes.
	 *
	 * @param string $path Host-selected path.
	 */
	public static function absolute( string $path ): bool {
		return str_starts_with( $path, '/' ) && ! str_contains( $path, "\0" );
	}

	/** Case-sensitive containment with directory boundaries for canonical POSIX paths.
	 *
	 * @param string $path Candidate canonical path.
	 * @param string $base_path Canonical parent.
	 */
	public static function within( string $path, string $base_path ): bool {
		$path      = rtrim( $path, '/' );
		$base_path = rtrim( $base_path, '/' );
		return $path === $base_path || str_starts_with( $path, $base_path . '/' );
	}

	/** Reject public storage before creating files or opening payloads.
	 *
	 * @param string $path Canonical parent plus server-owned basename.
	 * @param string $reason Caller-specific failure.
	 * @throws Failure If storage is unsafe.
	 */
	public static function outside_public( string $path, string $reason ): void {
		$uploads = wp_upload_dir( null, false );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'], isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '' ) as $public ) {
			$public = $public ? realpath( $public ) : false;
			if ( $public && self::within( $path, $public ) ) {
				throw new Failure( $reason, 503 );
			}
		}
	}

	/** Require a private ordinary object and meaningful native identity.
	 *
	 * @param string $path Native path, never a client-supplied artifact name.
	 * @param bool   $directory Expected object type.
	 * @param string $reason Caller-specific failure.
	 * @throws Failure If storage is unsafe.
	 * @return array Native stat data; existing Unix ownership records are unchanged.
	 */
	public static function stat( string $path, bool $directory, string $reason ): array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! $stat || ( $stat['mode'] & 0170000 ) !== ( $directory ? 0040000 : 0100000 ) || ( ! $directory && 1 !== $stat['nlink'] ) ) {
			throw new Failure( $reason, 503 );
		}
		if ( self::windows() || ( $stat['mode'] & 0777 ) !== ( $directory ? 0700 : 0600 ) || ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $stat['uid'] ) ) {
			throw new Failure( $reason, 503 );
		}
		return $stat;
	}

	/** Compare the opened handle with the checked pathname.
	 *
	 * @param array $a Path stat.
	 * @param mixed $b Handle stat.
	 */
	public static function same( array $a, $b ): bool {
		return is_array( $b ) && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'] && $a['mode'] === $b['mode'] && $a['nlink'] === $b['nlink'];
	}
}
