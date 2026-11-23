<?php
/**
 * Request-owned bounded storage for an export, never published as a temporary URL.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Checked file or memory I/O with bounded replay and explicit string materialization. */
final class Export_File {
	/** Replay buffer size in bytes. */
	private const CHUNK_BYTES = 65536;
	/**
	 * Lifetime-locked handle; registered ownership permits recovery after worker exit.
	 *
	 * @var resource|null
	 */
	private $stream;
	/** Registered owner retained through the last read.
	 *
	 * @var Export_Storage|null
	 */
	private ?Export_Storage $storage = null;
	/**
	 * Successfully written bytes.
	 *
	 * @var int
	 */
	private int $size = 0;
	/**
	 * Shared request limits.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/** Whether this artifact is confined to request memory.
	 *
	 * @var bool
	 */
	private bool $memory = false;
	/** Retain download exclusion through the last response byte.
	 *
	 * @var Memory_Lock|null
	 */
	private ?Memory_Lock $operation_lock = null;

	/** Transfer a download's operation lifetime to its artifact.
	 *
	 * @param Memory_Lock|null $lock Request-owned exclusion.
	 */
	public function retain_lock( ?Memory_Lock $lock ): void {
		$lock?->check();
		$this->operation_lock = $lock;
	}

	/**
	 * Open the selected private file or bounded memory stream.
	 *
	 * @param Resource_Budget $budget Request limits.
	 * @throws Failure If temporary storage is unavailable.
	 */
	public function __construct( Resource_Budget $budget ) {
		$this->budget = $budget;
		$this->budget->check();
		$this->memory = Publication_Runtime::memory();
		if ( $this->memory ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- PHP-only memory stream; never creates a file.
			$this->stream = fopen( 'php://memory', 'w+b' );
			if ( ! is_resource( $this->stream ) ) {
				throw new Failure( 'MEMORY_LIMIT_EXCEEDED', 503 );
			}
		} else {
			$this->storage = Export_Storage::allocate( $budget );
			$this->stream  = $this->storage->stream();
		}
	}

	/**
	 * Append one XML record or a bounded replay buffer, handling short writes.
	 *
	 * @param string $value Bytes to append.
	 * @throws Failure If storage cannot accept every byte.
	 */
	public function write( string $value ): void {
		$this->require_open();
		if ( $this->memory ) {
			Publication_Runtime::check_size( $this->size + strlen( $value ) );
		}
		$this->budget->check( strlen( $value ) );
		if ( 0 !== fseek( $this->stream, 0, SEEK_END ) ) {
			throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
		}
		$offset = 0;
		$length = strlen( $value );
		while ( $offset < $length ) {
			$this->budget->check( min( self::CHUNK_BYTES, $length - $offset ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- Private handle; checked short writes and controlled errors.
			$written = @fwrite( $this->stream, substr( $value, $offset, self::CHUNK_BYTES ) );
			if ( false === $written || 0 === $written ) {
				throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
			}
			$offset     += $written;
			$this->size += $written;
		}
	}

	/**
	 * Replay without loading the complete file. Consumers must run sequentially.
	 *
	 * @return \Generator<string>
	 * @throws Failure If a read is incomplete or a request limit is reached.
	 */
	public function chunks(): \Generator {
		yield from $this->range_chunks( 0, $this->size );
	}

	/**
	 * Replay one checked view; consumers must run sequentially.
	 *
	 * @param int $offset Starting byte offset in the spool.
	 * @param int $length Number of bytes to read.
	 * @return \Generator
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	public function range_chunks( int $offset, int $length ): \Generator {
		$this->require_open();
		if ( $offset < 0 || $length < 0 || $offset > $this->size || $length > $this->size - $offset ) {
			throw new Failure( 'INTERNAL_ERROR', 500 );
		}
		if ( 0 === $offset ) {
			$this->rewind();
		} elseif ( 0 !== fseek( $this->stream, $offset ) ) {
			throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
		}
		$remaining = $length;
		while ( $remaining > 0 ) {
			$this->budget->check( min( self::CHUNK_BYTES, $remaining ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.PHP.NoSilencedErrors.Discouraged -- Private bounded handle.
			$chunk = @fread( $this->stream, min( self::CHUNK_BYTES, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
			}
			$remaining -= strlen( $chunk );
			yield $chunk;
		}
	}

	/**
	 * Materialize one view, never the aggregate spool.
	 *
	 * @param int $offset Starting byte offset in the spool.
	 * @param int $length Number of bytes to read.
	 * @return string
	 */
	public function range_contents( int $offset, int $length ): string {
		$this->budget->check( $length );
		$value = '';
		foreach ( $this->range_chunks( $offset, $length ) as $chunk ) {
			$value .= $chunk;
		}
		return $value;
	}

	/**
	 * Materialize only at a string-only API boundary, with full-size headroom.
	 *
	 * @return string
	 * @throws Failure If storage or the request budget cannot supply the contents.
	 */
	public function contents(): string {
		$this->budget->check( $this->size );
		$this->rewind();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Convert I/O errors into a safe failure.
		$content = @stream_get_contents( $this->stream );
		if ( false === $content || strlen( $content ) !== $this->size ) {
			throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
		}
		return $content;
	}

	/** Return the byte count, including the final newline. */
	public function size(): int {
		return $this->size;
	}

	/** Count exact JSON request bytes over bounded buffers, including split UTF-8 sequences. */
	public function request_size(): int {
		$size = strlen( '{"content":"","encoding":"utf-8"}' );
		$tail = '';
		foreach ( $this->chunks() as $chunk ) {
			$size += Wxr_Bundle::escaped_size( $tail . $chunk, false ) - Wxr_Bundle::escaped_size( $tail, false );
			$tail  = substr( $tail . $chunk, -2 );
		}
		return $size;
	}

	/** Calculate Git's blob hash without materializing the contents. */
	public function blob_sha(): string {
		$hash = hash_init( 'sha1' );
		hash_update( $hash, 'blob ' . $this->size . "\0" );
		foreach ( $this->chunks() as $chunk ) {
			hash_update( $hash, $chunk );
		}
		return hash_final( $hash );
	}

	/**
	 * Rewind a private handle and reject access after closure.
	 *
	 * @throws Failure If the handle cannot be rewound.
	 */
	private function rewind(): void {
		$this->require_open();
		if ( ! rewind( $this->stream ) ) {
			throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
		}
	}

	/**
	 * Require a live handle before an operation.
	 *
	 * @throws Failure If already closed.
	 */
	private function require_open(): void {
		$this->operation_lock?->check();
		if ( ! is_resource( $this->stream ) ) {
			throw new Failure( 'INTERNAL_ERROR', 500 );
		}
	}

	/** Close and remove the request's temporary file; safe to call repeatedly. */
	public function close(): void {
		if ( $this->memory && is_resource( $this->stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close our PHP-only memory stream.
			fclose( $this->stream );
		}
		$this->storage?->close();
		$this->storage        = null;
		$this->stream         = null;
		$this->operation_lock = null;
	}

	/** Release handles if a caller exits without an explicit close. */
	public function __destruct() {
		$this->close();
	}
}
