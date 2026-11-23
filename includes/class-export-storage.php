<?php
/**
 * Registered private export files and bounded recovery after worker termination.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

// Exclusive creation, inode checks and locks require native local handles.
// Errors are checked and translated without exposing host paths.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

/** Disposable bytes only; never reads or modifies the publication journal. */
final class Export_Storage {
	private const MAX_METADATA = 2048;
	private const QUANTUM_NS   = 50000000;
	private const BUCKETS      = '0123456789abcdef';
	/** Whether this PHP request has attempted opportunistic recovery.
	 *
	 * @var bool
	 */
	private static bool $recovered = false;
	/** Private storage namespace.
	 *
	 * @var string
	 */
	private string $root;
	/** Site binding, independent of GitHub settings.
	 *
	 * @var string
	 */
	private string $site;
	/** Purpose-specific authentication key.
	 *
	 * @var string
	 */
	private string $key;
	/** Open root lock, held only during metadata transitions.
	 *
	 * @var resource|null
	 */
	private $lock = null;
	/** Verified root registration.
	 *
	 * @var array
	 */
	private array $registration = array();
	/** Export handle retaining its lifetime lock.
	 *
	 * @var resource|null
	 */
	private $body = null;
	/** Verified export registration.
	 *
	 * @var array
	 */
	private array $entry = array();
	/** Exact path, derived only from a server-issued identifier.
	 *
	 * @var string
	 */
	private string $directory = '';

	/** Resolve a separate site/key namespace without creating files.
	 *
	 * @throws Failure If a private local path is unavailable.
	 */
	private function __construct() {
		global $wpdb;
		$base       = Private_Filesystem::base( 'EXPORT_STORAGE_UNAVAILABLE' );
		$wordpress  = realpath( ABSPATH );
		$this->site = hash( 'sha256', ( false === $wordpress ? ABSPATH : $wordpress ) . '|' . $wpdb->prefix );
		$this->key  = Settings::key( 'export-storage' );
		$this->root = $base . '/zeropress-export-' . substr( $this->site, 0, 32 ) . '-' . substr( hash( 'sha256', $this->key ), 0, 16 );
		Private_Filesystem::outside_public( $this->root, 'EXPORT_STORAGE_UNAVAILABLE' );
	}

	/** Open and register one locked export; no body bytes precede registration.
	 *
	 * @param Resource_Budget $budget Original request limits.
	 * @return self
	 * @throws Failure If allocation, registration or the budget fails.
	 */
	public static function allocate( Resource_Budget $budget ): self {
		if ( Publication_Runtime::memory() ) {
			throw new Failure( 'MEMORY_SINGLE_REQUIRED', 409 );
		}
		$budget->check();
		$self    = new self();
		$created = array();
		try {
			$self->open_root( true );
			$until = hrtime( true ) + self::QUANTUM_NS;
			while ( ! $self->lock_root() ) {
				$budget->check();
				if ( hrtime( true ) >= $until ) {
					self::fail();
				}
				usleep( 1000 );
			}
			$budget->check();
			$id              = bin2hex( random_bytes( 16 ) );
			$bucket          = $self->root . '/v1/' . $id[0];
			$parent          = self::identity( self::stat( $bucket, true ) );
			$self->directory = $bucket . '/' . $id;
			if ( ! @mkdir( $self->directory, 0700 ) ) {
				self::fail();
			}
			$directory       = self::identity( self::stat( $self->directory, true ) );
			$self->body      = self::create( $self->directory . '/body' );
			$created['body'] = self::identity( self::stat( $self->directory . '/body' ) );
			self::matches( $created['body'], fstat( $self->body ) );
			if ( ! flock( $self->body, LOCK_EX | LOCK_NB ) ) {
				self::fail();
			}
			$self->entry = array(
				'version'   => 1,
				'site'      => $self->site,
				'id'        => $id,
				'root'      => $self->registration['root'],
				'tree'      => $self->registration['tree'],
				'bucket'    => $parent,
				'directory' => $directory,
				'body'      => $created['body'],
			);
			$self->write_record( $self->directory, $self->entry, $created );
			if ( $self->read_entry( $self->directory ) !== $self->entry ) {
				self::fail();
			}
			$budget->check();
			return $self;
		} catch ( \Throwable $error ) {
			// Only this still-locked creator knows the uncommitted file identities.
			if ( is_resource( $self->body ) ) {
				fclose( $self->body );
				$self->body = null;
			}
			if ( isset( $directory ) ) {
				try {
					self::matches( $directory, self::stat( $self->directory, true ) );
					foreach ( array_reverse( $created, true ) as $name => $identity ) {
						self::matches( $identity, self::stat( $self->directory . '/' . $name ) );
						@unlink( $self->directory . '/' . $name );
					}
					@rmdir( $self->directory );
				} catch ( Failure $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the original failure and leave unverified files.
					// Never repair or follow a replaced path during failure cleanup.
				}
			}
			$self->entry = array();
			throw $error;
		} finally {
			$self->unlock_root();
		}
	}

	/** Internal handle; Export_File retains this owner until the last read.
	 *
	 * @return resource
	 */
	public function stream() {
		return $this->body;
	}

	/** Idempotent normal release; cleanup cannot replace a publication result. */
	public function close(): void {
		if ( ! is_resource( $this->body ) ) {
			return;
		}
		try {
			if ( $this->lock_root() && $this->read_entry( $this->directory ) === $this->entry ) {
				self::matches( $this->entry['body'], self::stat( $this->directory . '/body' ) );
				self::matches( $this->entry['body'], fstat( $this->body ) );
				fclose( $this->body );
				$this->body = null;
				$this->remove( $this->directory, $this->entry );
			}
		} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Optional cleanup must not replace the caller's result.
			// Keep verifiable metadata for recovery; unrelated entries are untouched.
		} finally {
			if ( is_resource( $this->body ) ) {
				fclose( $this->body );
			}
			$this->body = null;
			$this->unlock_root();
		}
	}

	/** Run at most one recovery pass from an authorized operation in a request.
	 *
	 * @param Resource_Budget $budget Original request limits.
	 * @return array Bounded internal diagnostics, never paths or content.
	 */
	public static function recover_once( Resource_Budget $budget ): array {
		if ( Publication_Runtime::memory() ) {
			return self::recover( $budget );
		}
		if ( self::$recovered ) {
			return array();
		}
		self::$recovered = true;
		return self::recover( $budget );
	}

	/** Lifecycle hook; no journal access, remote work or lock takeover. */
	public static function cleanup(): void {
		self::recover_once( Resource_Budget::from_request() );
	}

	/** Bounded primitive, also used by storage tests; product entry points use once.
	 *
	 * @param Resource_Budget $budget Original request limits.
	 * @return array Internal counters.
	 */
	public static function recover( Resource_Budget $budget ): array {
		$result = array(
			'examined'   => 0,
			'removed'    => 0,
			'bytes'      => 0,
			'active'     => 0,
			'unverified' => 0,
			'deferred'   => 0,
			'errors'     => 0,
		);
		if ( Publication_Runtime::memory() ) {
			return $result;
		}
		$self  = null;
		$until = hrtime( true ) + self::QUANTUM_NS;
		try {
			$budget->check( self::MAX_METADATA );
			$budget->require_time( 2.05 );
			$self = new self();
			if ( ! $self->open_root( false ) ) {
				return $result;
			}
			if ( ! $self->lock_root() ) {
				++$result['deferred'];
				return $result;
			}
			$cursor = $self->cursor();
			for ( $offset = 0; $offset < 4; ++$offset ) {
				$bucket = $self->root . '/v1/' . self::BUCKETS[ ( $cursor + $offset ) % 16 ];
				self::stat( $bucket, true );
				$directory = @opendir( $bucket );
				if ( false === $directory ) {
					self::fail();
				}
				try {
					$count = 0;
					while ( false !== ( $name = readdir( $directory ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Stream a bounded directory without materializing its contents.
						if ( '.' === $name || '..' === $name ) {
							continue;
						}
						if ( hrtime( true ) >= $until || $result['removed'] >= 8 || $count >= 16 ) {
							++$result['deferred'];
							break;
						}
						$budget->require_time( 2.05 );
						++$count;
						++$result['examined'];
						$handle = null;
						try {
							if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $name ) || basename( $bucket ) !== $name[0] ) {
								self::fail();
							}
							$path  = $bucket . '/' . $name;
							$entry = $self->read_entry( $path );
							$bytes = 0;
							if ( self::exists( $path . '/body' ) ) {
								$stat = self::stat( $path . '/body' );
								self::matches( $entry['body'], $stat );
								$handle = @fopen( $path . '/body', 'r+b' );
								if ( ! is_resource( $handle ) ) {
									self::fail();
								}
								self::matches( $entry['body'], fstat( $handle ) );
								if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
									++$result['active'];
									continue;
								}
								$bytes = fstat( $handle )['size'];
							}
							if ( $self->read_entry( $path ) !== $entry ) {
								self::fail();
							}
							if ( is_resource( $handle ) ) {
								fclose( $handle );
								$handle = null;
							}
							$self->remove( $path, $entry );
							++$result['removed'];
							$result['bytes'] += $bytes;
						} catch ( Failure $ignored ) {
							++$result['unverified'];
						} finally {
							if ( is_resource( $handle ) ) {
								fclose( $handle );
							}
						}
					}
				} finally {
					closedir( $directory );
				}
				if ( hrtime( true ) >= $until || $result['removed'] >= 8 ) {
					break;
				}
			}
		} catch ( \Throwable $ignored ) {
			++$result['errors'];
		} finally {
			$self?->unlock_root();
		}
		return $result;
	}

	/** Resolve/validate stable root controls; recovery never creates a namespace.
	 *
	 * @param bool $create Whether allocation may initialize it.
	 * @return bool Whether the root exists.
	 * @throws Failure If ownership or storage cannot be verified.
	 */
	private function open_root( bool $create ): bool {
		if ( ! self::exists( $this->root ) ) {
			if ( ! $create ) {
				return false;
			}
			if ( @mkdir( $this->root, 0700 ) ) {
				$this->initialize();
			}
		}
		// Another creator may still be committing the root marker. Wait boundedly,
		// without adopting or repairing an incomplete namespace.
		if ( $create ) {
			$until = hrtime( true ) + self::QUANTUM_NS;
			while ( ! self::exists( $this->root . '/owner.json' ) && hrtime( true ) < $until ) {
				usleep( 1000 );
			}
		}
		$root               = self::identity( self::stat( $this->root, true ) );
		$this->registration = $this->read_record( $this->root . '/owner.json' );
		if ( array_keys( $this->registration ) !== array( 'version', 'site', 'root', 'lock', 'tree', 'cursor' ) || 1 !== $this->registration['version'] || $this->site !== $this->registration['site'] || $root !== $this->registration['root'] ) {
			self::fail();
		}
		$this->validate_root();
		$this->lock = @fopen( $this->root . '/lock', 'r+b' );
		if ( ! is_resource( $this->lock ) ) {
			self::fail();
		}
		self::matches( $this->registration['lock'], fstat( $this->lock ) );
		return true;
	}

	/** Initialize once; a killed unregistered initialization is never adopted.
	 *
	 * @throws Failure If private controls cannot be committed.
	 */
	private function initialize(): void {
		$root = self::identity( self::stat( $this->root, true ) );
		$lock = self::create( $this->root . '/lock' );
		try {
			if ( ! flock( $lock, LOCK_EX | LOCK_NB ) || ! @mkdir( $this->root . '/v1', 0700 ) ) {
				self::fail();
			}
			foreach ( str_split( self::BUCKETS ) as $bucket ) {
				if ( ! @mkdir( $this->root . '/v1/' . $bucket, 0700 ) ) {
					self::fail();
				}
			}
			$cursor = self::create( $this->root . '/cursor' );
			try {
				self::write_all( $cursor, '0' );
			} finally {
				fclose( $cursor );
			}
			$record  = array(
				'version' => 1,
				'site'    => $this->site,
				'root'    => $root,
				'lock'    => self::identity( self::stat( $this->root . '/lock' ) ),
				'tree'    => self::identity( self::stat( $this->root . '/v1', true ) ),
				'cursor'  => self::identity( self::stat( $this->root . '/cursor' ) ),
			);
			$created = array();
			$this->write_record( $this->root, $record, $created );
		} finally {
			fclose( $lock );
		}
	}

	/** Verify immutable root controls before and after acquiring their lock.
	 *
	 * @throws Failure If a root/lock replacement is detected.
	 */
	private function validate_root(): void {
		self::matches( $this->registration['root'], self::stat( $this->root, true ) );
		self::matches( $this->registration['lock'], self::stat( $this->root . '/lock' ) );
		self::matches( $this->registration['tree'], self::stat( $this->root . '/v1', true ) );
		self::matches( $this->registration['cursor'], self::stat( $this->root . '/cursor' ) );
		if ( $this->read_record( $this->root . '/owner.json' ) !== $this->registration ) {
			self::fail();
		}
	}

	/** Nonblocking metadata gate, never the publication lock.
	 *
	 * @return bool Whether the gate was acquired.
	 * @throws Failure If the namespace changed.
	 */
	private function lock_root(): bool {
		if ( ! is_resource( $this->lock ) ) {
			return false;
		}
		$this->validate_root();
		self::matches( $this->registration['lock'], fstat( $this->lock ) );
		if ( ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			return false;
		}
		$this->validate_root();
		return true;
	}

	/** Release our gate handle's lock without removing its stable inode. */
	private function unlock_root(): void {
		if ( is_resource( $this->lock ) ) {
			flock( $this->lock, LOCK_UN );
		}
	}

	/** Read and advance a bounded traversal hint, not ownership evidence.
	 *
	 * @return int Initial bucket.
	 * @throws Failure If the cursor cannot safely be read/written.
	 */
	private function cursor(): int {
		$handle = @fopen( $this->root . '/cursor', 'r+b' );
		if ( ! is_resource( $handle ) ) {
			self::fail();
		}
		try {
			self::matches( $this->registration['cursor'], fstat( $handle ) );
			$text = fread( $handle, 2 );
			if ( ! is_string( $text ) || ! preg_match( '/\A[a-f0-9]\z/', $text ) || 0 !== fseek( $handle, 0 ) ) {
				self::fail();
			}
			$start = strpos( self::BUCKETS, $text );
			self::write_all( $handle, self::BUCKETS[ ( $start + 4 ) % 16 ] );
			return $start;
		} finally {
			fclose( $handle );
		}
	}

	/** Read ownership and exact contents without walking an arbitrary subtree.
	 *
	 * @param string $path Internally derived entry directory.
	 * @return array Verified registration.
	 * @throws Failure If ownership, parent identity or entry contents differ.
	 */
	private function read_entry( string $path ): array {
		$this->validate_root();
		$directory = self::stat( $path, true );
		$handle    = @opendir( $path );
		if ( false === $handle ) {
			self::fail();
		}
		try {
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- The third non-dot entry is immediately rejected.
				if ( ! in_array( $name, array( '.', '..', 'body', 'owner.json' ), true ) ) {
					self::fail();
				}
			}
		} finally {
			closedir( $handle );
		}
		$record = $this->read_record( $path . '/owner.json' );
		if ( array_keys( $record ) !== array( 'version', 'site', 'id', 'root', 'tree', 'bucket', 'directory', 'body' ) || 1 !== $record['version'] || $record['site'] !== $this->site || basename( $path ) !== $record['id'] || $record['root'] !== $this->registration['root'] || $record['tree'] !== $this->registration['tree'] || ! is_array( $record['body'] ) ) {
			self::fail();
		}
		self::matches( $record['directory'], $directory );
		self::matches( $record['bucket'], self::stat( dirname( $path ), true ) );
		return $record;
	}

	/** Delete only a verified, unused entry with the root gate held.
	 *
	 * @param string $path Exact owned directory.
	 * @param array  $entry Previously verified record.
	 * @throws Failure If any identity changes or unlink fails.
	 */
	private function remove( string $path, array $entry ): void {
		if ( $this->read_entry( $path ) !== $entry ) {
			self::fail();
		}
		if ( self::exists( $path . '/body' ) ) {
			self::matches( $entry['body'], self::stat( $path . '/body' ) );
			if ( ! @unlink( $path . '/body' ) ) {
				self::fail();
			}
		}
		if ( ! @unlink( $path . '/owner.json' ) ) {
			self::fail();
		}
		@rmdir( $path );
	}

	/** Bounded authenticated metadata; no body reads or deserialization of objects.
	 *
	 * @param string $path Expected control file.
	 * @return array Verified record.
	 * @throws Failure If metadata is missing, excessive or invalid.
	 */
	private function read_record( string $path ): array {
		$stat = self::stat( $path );
		if ( $stat['size'] < 1 || $stat['size'] > self::MAX_METADATA ) {
			self::fail();
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! is_resource( $handle ) ) {
			self::fail();
		}
		try {
			self::matches( self::identity( $stat ), fstat( $handle ) );
			$text = stream_get_contents( $handle, self::MAX_METADATA + 1 );
		} finally {
			fclose( $handle );
		}
		$value = is_string( $text ) && strlen( $text ) <= self::MAX_METADATA ? json_decode( $text, true, 8 ) : null;
		if ( ! is_array( $value ) || array_keys( $value ) !== array( 'data', 'mac' ) || ! is_array( $value['data'] ) || ! is_string( $value['mac'] ) || ! hash_equals( $this->signature( $value['data'] ), $value['mac'] ) ) {
			self::fail();
		}
		self::matches( self::identity( $stat ), self::stat( $path ) );
		return $value['data'];
	}

	/** Commit small ownership metadata; track newly created identities for failure cleanup.
	 *
	 * @param string $path Owned directory.
	 * @param array  $record Signed data.
	 * @param array  $created Exact identities created by this worker.
	 * @throws Failure If the record cannot be committed.
	 */
	private function write_record( string $path, array $record, array &$created ): void {
		$text = wp_json_encode(
			array(
				'data' => $record,
				'mac'  => $this->signature( $record ),
			)
		);
		if ( ! is_string( $text ) || strlen( $text ) > self::MAX_METADATA ) {
			self::fail();
		}
		$handle                   = self::create( $path . '/owner.pending' );
		$created['owner.pending'] = self::identity( self::stat( $path . '/owner.pending' ) );
		try {
			self::write_all( $handle, $text );
			if ( ! fflush( $handle ) ) {
				self::fail();
			}
		} finally {
			fclose( $handle );
		}
		if ( self::exists( $path . '/owner.json' ) || ! @rename( $path . '/owner.pending', $path . '/owner.json' ) ) {
			self::fail();
		}
		$created['owner.json'] = $created['owner.pending'];
		unset( $created['owner.pending'] );
	}

	/** Authenticate metadata in this site's exact storage namespace.
	 *
	 * @param array $data Bounded metadata.
	 * @return string Hex HMAC.
	 */
	private function signature( array $data ): string {
		return hash_hmac( 'sha256', $this->root . "\0" . wp_json_encode( $data ), $this->key );
	}

	/** Open a new private file without changing permissions of an existing path.
	 *
	 * @param string $path Exact new pathname.
	 * @return resource
	 * @throws Failure If exclusive creation fails.
	 */
	private static function create( string $path ) {
		$mask = umask( 0077 );
		try {
			$handle = @fopen( $path, 'x+b' );
		} finally {
			umask( $mask );
		}
		if ( ! is_resource( $handle ) ) {
			self::fail();
		}
		return $handle;
	}

	/** Checked small metadata writes, including short writes.
	 *
	 * @param resource $handle Open control file.
	 * @param string   $text Bounded bytes.
	 * @throws Failure If storage rejects a write.
	 */
	private static function write_all( $handle, string $text ): void {
		while ( '' !== $text ) {
			$n = @fwrite( $handle, $text );
			if ( false === $n || 0 === $n ) {
				self::fail();
			}
			$text = substr( $text, $n );
		}
	}

	/** Stat without following links or accepting broad permissions.
	 *
	 * @param string $path Expected private path.
	 * @param bool   $directory Whether a directory is required.
	 * @return array Native stat data.
	 * @throws Failure If type, link count, permissions or ownership differ.
	 */
	private static function stat( string $path, bool $directory = false ): array {
		return Private_Filesystem::stat( $path, $directory, 'EXPORT_STORAGE_UNAVAILABLE' );
	}

	/** Exclude changing size/mtime and directory link counts from identity.
	 *
	 * @param array $stat Checked stat.
	 * @return array Immutable identity fields.
	 */
	private static function identity( array $stat ): array {
		return array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'mode' ) ) );
	}

	/** Compare an opened handle or pathname to its registered identity.
	 *
	 * @param array $expected Registered identity.
	 * @param mixed $actual Current native stat.
	 * @throws Failure If the inode, permissions, owner or file link count changed.
	 */
	private static function matches( array $expected, $actual ): void {
		if ( ! is_array( $actual ) || self::identity( $actual ) !== $expected || ( 0100000 === ( $actual['mode'] & 0170000 ) && 1 !== $actual['nlink'] ) ) {
			self::fail();
		}
	}

	/** Include dangling links in existence checks.
	 *
	 * @param string $path Local pathname.
	 * @return bool
	 */
	private static function exists( string $path ): bool {
		clearstatcache( true, $path );
		return false !== @lstat( $path );
	}

	/** Uniform safe storage failure.
	 *
	 * @throws Failure Always.
	 */
	private static function fail(): void {
		throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
	}

	/** Release handles; never delete the stable root lock inode. */
	public function __destruct() {
		$this->close();
		if ( is_resource( $this->lock ) ) {
			fclose( $this->lock );
		}
	}
}
