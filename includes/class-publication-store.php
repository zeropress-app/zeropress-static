<?php
/** Durable publication metadata and per-site exclusion.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

// Native I/O is required for exclusive handles, inode checks and private modes.
// All results are checked; suppress filesystem warnings to avoid exposing paths.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

/** A bounded, non-autoloaded journal. Transients are never authoritative. */
final class Publication_Store {
	const OPTION    = 'zpsp_publication_v1';
	const MAX_BYTES = 262144;
	/** Current server-owned control record.
	 *
	 * @var array
	 */
	public array $data;
	/** Exact database value used for CAS.
	 *
	 * @var string|null
	 */
	private ?string $raw;
	/** Last read record, for merging a concurrent cancellation only.
	 *
	 * @var array
	 */
	private array $baseline;
	/** Stable per-site lock handle.
	 *
	 * @var resource|null
	 */
	private $lock;
	/** Validated private storage root.
	 *
	 * @var string
	 */
	public string $root = '';

	/** Read fresh database state, bypassing object caches. */
	public function __construct() {
		$this->reload();
	}

	/** Load the authoritative option, never treating malformed/unknown state as empty.
	 *
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function reload(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( $wpdb->last_error ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		if ( null !== $raw && strlen( $raw ) > self::MAX_BYTES ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
		$value = null === $raw ? array(
			'version'    => 1,
			'revision'   => 0,
			'job'        => null,
			'history'    => array(),
			'not_before' => 0,
		) : json_decode( $raw, true );
		if ( ! is_array( $value ) || 1 !== ( $value['version'] ?? null ) || ! is_int( $value['revision'] ?? null ) || ! is_array( $value['history'] ?? null ) ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}

		if ( ! array_key_exists( 'job', $value ) ||
			( null !== $value['job'] && ! is_array( $value['job'] ) ) || $value['revision'] < 0 || count( $value['history'] ) > 64 ||
			! isset( $value['not_before'] ) || ! is_numeric( $value['not_before'] ) || ! is_finite( (float) $value['not_before'] ) ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
		if ( null !== $value['job'] ) {
			$job = $value['job'];
			if ( ! is_string( $job['id'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $job['id'] ) ||
				! is_int( $job['owner'] ?? null ) || ! is_int( $job['expires'] ?? null ) ||
				! is_array( $job['requests'] ?? null ) || ! is_array( $job['target'] ?? null ) ||
				! is_string( $job['phase'] ?? null ) || ! is_string( $job['settings_revision'] ?? null ) ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
			if ( isset( $job['intent'] ) && ( ! is_array( $job['intent'] ) ||
				! is_string( $job['intent']['candidate'] ?? null ) || ! preg_match( '/\A[a-f0-9]{40}\z/', $job['intent']['candidate'] ) ||
				! is_array( $job['intent']['files'] ?? null ) || ! is_string( $job['intent']['hash'] ?? null ) ) ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
		}
		$this->raw      = $raw;
		$this->data     = $value;
		$this->baseline = $value;
	}

	/** Atomically save a bounded record; preserve cancellation received during HTTP.
	 *
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function save(): void {
		global $wpdb;
		$next     = $this->data;
		$old      = $this->raw;
		$baseline = $this->baseline;
		$this->reload();
		if ( $old !== $this->raw ) {
			$prior   = $baseline;
			$current = $this->data;
			unset( $prior['revision'], $current['revision'], $prior['job']['cancel_requested'], $current['job']['cancel_requested'] );
			if ( $prior !== $current || empty( $this->data['job']['cancel_requested'] ) ) {
				throw new Failure( 'PUBLICATION_CONFLICT', 409 );
			}
			if ( $next['job'] ) {
				if ( ! empty( $next['job']['intent'] ) && empty( $baseline['job']['intent'] ) ) {
					throw new Failure( 'PUBLICATION_CANCELLED', 409 );
				}
				$next['job']['cancel_requested'] = true;
			}
		}
		$next['revision'] = $this->data['revision'] + 1;
		$encoded          = wp_json_encode( $next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		if ( null === $this->raw ) {
			$ok = add_option( self::OPTION, $encoded, '', false );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $encoded, self::OPTION, $this->raw ) );
		}
		if ( ! $ok ) {
			throw new Failure( 'PUBLICATION_CONFLICT', 409 );
		}
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$this->raw      = $encoded;
		$this->data     = $next;
		$this->baseline = $next;
	}

	/** Acquire local cross-worker exclusion without a timestamp-based takeover.
	 *
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function acquire(): void {
		if ( is_resource( $this->lock ) ) {
			$this->reload();
			return;
		}
		global $wpdb;
		$base = realpath( sys_get_temp_dir() );
		if ( false === $base ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		$root = $base . '/zeropress-publication-' . substr( hash( 'sha256', ABSPATH . '|' . $wpdb->prefix ), 0, 32 );
		// A host can select a private shared path; every choice still passes these checks.
		$root = apply_filters( 'zeropress_static_publication_directory', $root );
		if ( ! is_string( $root ) || ! str_starts_with( $root, '/' ) || str_contains( $root, "\0" ) || is_link( $root ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		$parent = realpath( dirname( $root ) );
		if ( false === $parent || in_array( basename( $root ), array( '.', '..', '' ), true ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		$root    = $parent . '/' . basename( $root );
		$uploads = wp_upload_dir( null, false );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'], isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '' ) as $public ) {
			$public = $public ? realpath( $public ) : false;
			if ( $public && ( $root === $public || str_starts_with( $root, rtrim( $public, '/' ) . '/' ) ) ) {
				throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
			}
		}
		$this->root = $root;
		if ( ! is_dir( $root ) ) {
			if ( ! empty( $this->data['job'] ) || ! @mkdir( $root, 0700 ) ) {
				throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
			}
		}
		$directory = self::private_stat( $root, true );
		$path      = $root . '/lock';
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			if ( ! empty( $this->data['job'] ) ) {
				throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
			}
			$mask = umask( 0077 );
			try {
				$created = @fopen( $path, 'x+b' );
			} finally {
				umask( $mask );
			}
			if ( is_resource( $created ) ) {
				fclose( $created );
			}
		}
		$stat       = self::private_stat( $path );
		$this->lock = @fopen( $path, 'r+b' );
		if ( ! is_resource( $this->lock ) || ! self::same_file( $stat, fstat( $this->lock ) ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		if ( ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $this->lock );
			$this->lock = null;
			throw new Failure( 'PUBLICATION_BUSY', 409 );
		}
		$this->reload();
		$identity = array( $root, $directory['dev'], $directory['ino'], $stat['dev'], $stat['ino'] );
		if ( isset( $this->data['storage'] ) && $this->data['storage'] !== $identity && ! empty( $this->data['job'] ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		if ( ( $this->data['storage'] ?? null ) !== $identity ) {
			$this->data['storage'] = $identity;
			$this->save();
		}
	}

	/**
	 * Require private ordinary files/directories, rejecting aliases and permissive modes.
	 *
	 * @param string $path Private path or owned Git path.
	 * @param bool   $directory Private directory path, or directory validation flag.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public static function private_stat( string $path, bool $directory = false ): array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		$type = $directory ? 0040000 : 0100000;
		if ( ! $stat || ( $stat['mode'] & 0170000 ) !== $type || ( $stat['mode'] & 0777 ) !== ( $directory ? 0700 : 0600 ) || ( ! $directory && 1 !== $stat['nlink'] ) || ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $stat['uid'] ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		return $stat;
	}

	/**
	 * Compare an opened handle with the validated pathname.
	 *
	 * @param array $a Validated path stat.
	 * @param mixed $b Opened-handle stat to compare.
	 */
	public static function same_file( array $a, $b ): bool {
		return is_array( $b ) && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'] && $a['mode'] === $b['mode'] && $a['nlink'] === $b['nlink'];
	}

	/**
	 * Delete only known snapshot entries; never recurse through arbitrary paths.
	 *
	 * @param string $id Opaque server-issued job identifier.
	 */
	public function purge( string $id ): void {
		if ( ! is_resource( $this->lock ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) {
			return;
		}
		foreach ( array( $id, 'capture-' . $id ) as $name ) {
			$path = $this->root . '/' . $name;
			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}
			try {
				self::private_stat( $path, true );
				foreach ( scandir( $path ) as $file ) {
					if ( preg_match( '/\Aa-[0-9]{3}\z/', $file ) && ! is_dir( $path . '/' . $file ) ) {
						@unlink( $path . '/' . $file );
					}
				}
				@rmdir( $path );
			} catch ( Failure $ignored ) {
				// Never relax private-path checks for cleanup.
				continue;
			}
		}
	}

	/**
	 * Finish without forgetting unexpired request IDs or the shared cooldown.
	 *
	 * @param string $state Validated metadata inspection checkpoint.
	 * @param array  $extra Safe terminal result fields.
	 */
	public function finish( string $state, array $extra = array() ): array {
		$job                     = $this->data['job'];
		$result                  = array_merge(
			array(
				'state'  => $state,
				'job_id' => $job['id'],
			),
			$extra
		);
		$this->data['history'][] = array(
			'owner'           => $job['owner'],
			'id'              => $job['id'],
			'request_id'      => $job['request_id'],
			'fingerprint'     => $job['fingerprint'],
			'protected_until' => $job['expires'],
			'until'           => max( time() + DAY_IN_SECONDS, $job['expires'] ),
			'result'          => $result,
		);
		$this->data['job']       = null;
		$this->save();
		$this->purge( $job['id'] );
		return $result + array( 'revision' => $this->data['revision'] );
	}

	/**
	 * Stop unfinished work during deactivation/uninstall without erasing uncertainty.
	 *
	 * No remote work, no lock takeover and no removal of the stable lock inode.
	 */
	public static function cancel_and_cleanup(): void {
		try {
			$store = new self();
			if ( empty( $store->data['job'] ) && empty( $store->data['history'] ) ) {
				return;
			}
			if ( ! empty( $store->data['job'] ) && empty( $store->data['job']['intent'] ) ) {
				$store->data['job']['cancel_requested'] = true;
				$store->save();
			}
			$store->acquire();
			foreach ( $store->data['history'] as $entry ) {
				$store->purge( $entry['id'] );
			}
			if ( ! empty( $store->data['job']['intent'] ) ) {
				$store->purge( $store->data['job']['id'] );
			} elseif ( ! empty( $store->data['job'] ) ) {
				$store->finish( 'cancelled' );
			}
		} catch ( Failure $error ) {
			// An active worker or unavailable private path must retain the journal.
			return;
		}
	}

	/** Release only our handle; retain the stable lock inode. */
	public function __destruct() {
		if ( is_resource( $this->lock ) ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
		}
	}
}
