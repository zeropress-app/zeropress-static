<?php
/** Private immutable artifacts shared by bounded publication requests.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

// Native I/O is required for exclusive handles, inode checks and private modes.
// All results are checked; suppress filesystem warnings to avoid exposing paths.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

/** Snapshot bodies never enter options, transients, logs or public directories. */
final class Publication_Snapshot {
	/** Locked site storage.
	 *
	 * @var Publication_Store
	 */
	private Publication_Store $store;
	/** Existing request guard.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;

	/**
	 * Bind storage and resource checks.
	 *
	 * @param Publication_Store $store Locked private snapshot store.
	 * @param Resource_Budget   $budget Original request memory and time guard.
	 */
	public function __construct( Publication_Store $store, Resource_Budget $budget ) {
		$this->store  = $store;
		$this->budget = $budget;
	}

	/**
	 * Stream-copy approved artifacts, verifying bytes before the atomic seal.
	 *
	 * @param array $export Request-owned export files and commitments.
	 * @param array $job Server-owned immutable approval and current progress.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function capture( array $export, array $job ): array {
		$id = $job['id'];
		$this->store->purge( $id );
		$path = $this->store->root . '/capture-' . $id;
		if ( ! @mkdir( $path, 0700 ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		$bundle  = $export['bundle'] ?? null;
		$total   = $bundle ? array_sum( array_column( $bundle->parts, 'bytes' ) ) + strlen( $bundle->manifest ) + strlen( $export['base'] ) : $export['wxr']->size() + strlen( $export['base'] );
		$free    = @disk_free_space( $path );
		$maximum = $bundle ? ( Wxr_Bundle::MAX_PARTS + 1 ) * Wxr_Bundle::MAX_FILE + Wxr_Bundle::MAX_MANIFEST : 200 * MB_IN_BYTES;
		if ( $total > $maximum || false === $free || $free < $total + 2 * $export['bytes']['wxr'] + 16 * MB_IN_BYTES ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		$inventory = array();
		if ( $bundle ) {
			foreach ( $bundle->parts as $part ) {
				$inventory[] = $this->copy( $path, count( $inventory ), 'part', $part['path'], $part['bytes'], $part['request_bytes'], $part['git'], $bundle->chunks( $part ) );
			}
		} else {
			$file        = $export['wxr'];
			$inventory[] = $this->copy( $path, 0, 'wxr', GitHub::FILES['wxr'], $file->size(), $file->request_size(), $file->blob_sha(), $file->chunks() );
		}
		foreach ( array( 'base' => $export['base'] ) + ( $bundle ? array( 'manifest' => $bundle->manifest ) : array() ) as $key => $body ) {
			$inventory[] = $this->copy( $path, count( $inventory ), $key, 'base' === $key ? GitHub::FILES['base'] : Wxr_Bundle::MANIFEST, strlen( $body ), Wxr_Bundle::request_size( $body ), GitHub::blob_sha( $body ), self::chunks( $body ) );
		}
		$this->budget->check();
		if ( ! @rename( $path, $this->store->root . '/' . $id ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		return $inventory;
	}

	/**
	 * Copy and hash one ordinary artifact with short-write checks.
	 *
	 * @param string     $directory Private directory path, or directory validation flag.
	 * @param int        $index Bounded artifact sequence number.
	 * @param string     $kind Allowlisted artifact kind.
	 * @param string     $path Private path or owned Git path.
	 * @param int        $bytes Expected raw byte count.
	 * @param int        $wire Expected encoded request size.
	 * @param string     $git Expected Git blob SHA.
	 * @param \Generator $chunks Bounded stream of artifact bytes.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function copy( string $directory, int $index, string $kind, string $path, int $bytes, int $wire, string $git, \Generator $chunks ): array {
		$name = sprintf( 'a-%03d', $index );
		$mask = umask( 0077 );
		try {
			$file = @fopen( $directory . '/' . $name, 'x+b' );
		} finally {
			umask( $mask );
		}
		if ( ! is_resource( $file ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		$sha  = hash_init( 'sha256' );
		$blob = hash_init( 'sha1' );
		hash_update( $blob, 'blob ' . $bytes . "\0" );
		$written = 0;
		try {
			foreach ( $chunks as $chunk ) {
				$this->budget->check( strlen( $chunk ) );
				if ( $written + strlen( $chunk ) > $bytes ) {
					throw new Failure( 'PUBLICATION_INVALID', 409 );
				}
				$offset = 0;
				$length = strlen( $chunk );
				while ( $offset < $length ) {
					$n = @fwrite( $file, substr( $chunk, $offset ) );
					if ( false === $n || 0 === $n ) {
						throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
					}
					$offset += $n;
				}
				hash_update( $sha, $chunk );
				hash_update( $blob, $chunk );
				$written += strlen( $chunk );
			}
			if ( $written !== $bytes || ! hash_equals( $git, hash_final( $blob ) ) || ! fflush( $file ) || ! fsync( $file ) ) {
				throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
			}
		} finally {
			fclose( $file );
		}
		return array(
			'file'          => $name,
			'kind'          => $kind,
			'path'          => $path,
			'bytes'         => $bytes,
			'request_bytes' => $wire,
			'git'           => $git,
			'sha256'        => hash_final( $sha ),
		);
	}

	/**
	 * Split an already-bounded settings/manifest string for checked file I/O.
	 *
	 * @param string $value Value to validate or stream.
	 */
	private static function chunks( string $value ): \Generator {
		$length = strlen( $value );
		for ( $offset = 0; $offset < $length; $offset += 65536 ) {
			yield substr( $value, $offset, 65536 );
		}
	}

	/**
	 * Authenticate artifact inventory and original approval binding, not mutable progress.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 */
	public static function seal( array $job ): string {
		$value = array();
		foreach ( array( 'id', 'owner', 'settings_revision', 'target', 'expires', 'generated_at', 'data_hash', 'plan', 'artifacts' ) as $key ) {
			$value[ $key ] = $job[ $key ];
		}
		return hash_hmac( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), Settings::key( 'publication-snapshot-v1' ) );
	}

	/**
	 * Refuse replaced or corrupt inventory even when individual files still exist.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public static function validate( array $job ): void {
		if ( ! is_string( $job['seal'] ?? null ) || ! hash_equals( self::seal( $job ), $job['seal'] ) ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
	}

	/**
	 * Refuse lost or unsafe backing files before any further publication work.
	 *
	 * Uploaded bytes are already confirmed by Git SHA; pending uploads additionally
	 * verify full content hashes from the same opened handle in contents().
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @throws Failure If private storage or the sealed inventory is unavailable.
	 */
	public function validate_storage( array $job ): void {
		self::validate( $job );
		$directory = $this->store->root . '/' . $job['id'];
		Publication_Store::private_stat( $directory, true );
		foreach ( $job['artifacts'] as $artifact ) {
			$this->budget->check();
			if ( ! preg_match( '/\Aa-[0-9]{3}\z/', $artifact['file'] ) ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
			$stat = Publication_Store::private_stat( $directory . '/' . $artifact['file'] );
			if ( $stat['size'] !== $artifact['bytes'] ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
		}
	}

	/**
	 * Load just one upload from the same verified handle, within existing allocation checks.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @param array $artifact Authenticated artifact inventory entry.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function contents( array $job, array $artifact ): string {
		self::validate( $job );
		if ( ! preg_match( '/\Aa-[0-9]{3}\z/', $artifact['file'] ) ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
		$directory = $this->store->root . '/' . $job['id'];
		Publication_Store::private_stat( $directory, true );
		$path = $directory . '/' . $artifact['file'];
		$stat = Publication_Store::private_stat( $path );
		$this->budget->check( max( $artifact['bytes'], $artifact['request_bytes'] ) );
		$file = @fopen( $path, 'rb' );
		if ( ! is_resource( $file ) ) {
			throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
		}
		try {
			if ( ! Publication_Store::same_file( $stat, fstat( $file ) ) || $stat['size'] !== $artifact['bytes'] ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
			$body = '';
			$read = 0;
			while ( $read < $artifact['bytes'] ) {
				$this->budget->check();
				$chunk = @fread( $file, min( 65536, $artifact['bytes'] - $read ) );
				if ( false === $chunk || '' === $chunk ) {
					throw new Failure( 'PUBLICATION_STORAGE_UNAVAILABLE', 503 );
				}
				$body .= $chunk;
				$read += strlen( $chunk );
			}
			if ( '' !== fread( $file, 1 ) || ! hash_equals( $artifact['sha256'], hash( 'sha256', $body ) ) || ! hash_equals( $artifact['git'], GitHub::blob_sha( $body ) ) ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
			return $body;
		} finally {
			fclose( $file );
		}
	}
}
