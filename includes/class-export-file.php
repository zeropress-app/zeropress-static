<?php
/**
 * Request-owned disk storage for an export, never published as a temporary URL.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Checked temporary-file I/O with bounded replay and explicit string materialization. */
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

	/**
	 * Open a registered private file, without an uploads-directory fallback.
	 *
	 * @param Resource_Budget $budget Request limits.
	 * @throws Failure If temporary storage is unavailable.
	 */
	public function __construct( Resource_Budget $budget ) {
		$this->budget = $budget;
		$this->budget->check();
		$this->storage = Export_Storage::allocate( $budget );
		$this->stream  = $this->storage->stream();
	}

	/**
	 * Append one XML record or a bounded replay buffer, handling short writes.
	 *
	 * @param string $value Bytes to append.
	 * @throws Failure If storage cannot accept every byte.
	 */
	public function write( string $value ): void {
		$this->require_open();
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
		if ( ! is_resource( $this->stream ) ) {
			throw new Failure( 'INTERNAL_ERROR', 500 );
		}
	}

	/** Close and remove the request's temporary file; safe to call repeatedly. */
	public function close(): void {
		$this->storage?->close();
		$this->storage = null;
		$this->stream  = null;
	}

	/** Release handles if a caller exits without an explicit close. */
	public function __destruct() {
		$this->close();
	}
}
