<?php
/** Bounded WXR transport and private part inventory.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** One request owns the aggregate spool; views never materialize the entire export. */
final class Wxr_Bundle {
	const MARKER         = 'zeropress-wxr-input-v1.json';
	const MARKER_CONTENT = "{\"format\":\"zeropress-wxr-input\",\"version\":1,\"formats\":[\"xml\",\"bundle-v1\"]}\n";
	const MANIFEST       = 'wordpress-export.manifest.json';
	const DIRECTORY      = 'wordpress-export.parts';
	const TARGET         = 4194304;
	const MAX_FILE       = 8388608;
	const MAX_MANIFEST   = 1048576;
	const MAX_PARTS      = 256;
	/**
	 * Private aggregate spool.
	 *
	 * @var Export_File
	 */
	private Export_File $storage;
	/**
	 * Shared resource budget.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/**
	 * Shared XML header.
	 *
	 * @var string
	 */
	private string $prefix;
	/**
	 * XML closing tags.
	 *
	 * @var string
	 */
	private string $suffix;
	/**
	 * Start of the current part.
	 *
	 * @var int
	 */
	private int $start = 0;
	/**
	 * Current raw part byte count.
	 *
	 * @var int
	 */
	private int $raw = 0;
	/**
	 * Current encoded request byte count.
	 *
	 * @var int
	 */
	private int $wire = 0;
	/**
	 * Per-kind record counts.
	 *
	 * @var array
	 */
	private array $counts;
	/**
	 * Compact private part descriptors.
	 *
	 * @var array
	 */
	public array $parts = array();
	/**
	 * Serialized transport manifest.
	 *
	 * @var string
	 */
	public string $manifest = '';
	/**
	 * Exact manifest SHA-256.
	 *
	 * @var string
	 */
	public string $digest = '';

	/**
	 * Open one private aggregate spool.
	 *
	 * @param Resource_Budget $budget Shared request memory and time budget.
	 * @param string          $prefix Common XML header repeated in every part.
	 * @param string          $suffix Closing XML tags.
	 */
	public function __construct( Resource_Budget $budget, string $prefix, string $suffix ) {
		$this->budget  = $budget;
		$this->storage = new Export_File( $budget );
		$this->prefix  = $prefix;
		$this->suffix  = $suffix;
		$this->reset_part();
	}
	/**
	 * Count exact JSON string bytes without encoding a large string.
	 *
	 * @param string $text UTF-8 content to size.
	 * @param bool   $validate Whether to validate UTF-8 before counting bytes.
	 * @return int
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	public static function escaped_size( string $text, bool $validate = true ): int {
		if ( $validate && 1 !== preg_match( '//u', $text ) ) {
			throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
		}
		$bytes = strlen( $text );
		foreach ( array( '"', '\\', "\n", "\r", "\t", "\x08", "\x0c" ) as $char ) {
			$bytes += substr_count( $text, $char );
		}
		$bytes += 5 * preg_match_all( '/[\x00-\x07\x0b\x0e-\x1f]/', $text );
		$bytes += 3 * ( substr_count( $text, "\xe2\x80\xa8" ) + substr_count( $text, "\xe2\x80\xa9" ) );
		return $bytes;
	}
	/**
	 * Include the fixed UTF-8 blob JSON envelope.
	 *
	 * @param string $text UTF-8 content to size.
	 * @return int
	 */
	public static function request_size( string $text ): int {
		return self::escaped_size( $text ) + strlen( '{"content":"","encoding":"utf-8"}' );
	}
	/**
	 * Start a new complete WXR document.
	 *
	 * @return void
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	private function reset_part(): void {
		$this->start = $this->storage->size();
		$this->raw   = strlen( $this->prefix ) + strlen( $this->suffix );
		$this->wire  = self::request_size( $this->prefix . $this->suffix );
		if ( $this->raw > self::MAX_FILE || $this->wire > self::MAX_FILE ) {
			throw new Failure( 'WXR_RECORD_TOO_LARGE', 413 );
		}
		$this->counts = array_fill_keys( array( 'authors', 'categories', 'tags', 'terms', 'items' ), 0 );
		$this->storage->write( $this->prefix );
	}
	/**
	 * Append a complete record, rotating before the soft target.
	 *
	 * @param string $xml Complete generated XML record.
	 * @param string $kind Record inventory category.
	 * @param bool   $boundary Begin a planned part before this record.
	 * @return void
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	public function record( string $xml, string $kind, bool $boundary = false ): void {
		if ( '' === $xml ) {
			return;
		}
		$raw  = strlen( $xml );
		$wire = self::escaped_size( $xml );
		if ( array_sum( $this->counts ) && ( $boundary || $this->raw + $raw > self::TARGET || $this->wire + $wire > self::MAX_FILE ) ) {
			$this->finish_part();
			$this->reset_part();
		}
		if ( $this->raw + $raw > self::MAX_FILE || $this->wire + $wire > self::MAX_FILE ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Numeric diagnostics pass through the Failure whitelist and JSON response.
			throw new Failure(
				'WXR_RECORD_TOO_LARGE',
				413,
				array(
					'file_bytes'       => $raw,
					'file_limit_bytes' => self::MAX_FILE,
				)
			);
		}
		$this->storage->write( $xml );
		$this->raw  += $raw;
		$this->wire += $wire;
		++$this->counts[ $kind ];
	}
	/**
	 * Seal a bounded part and record both transport hashes.
	 *
	 * @return void
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	private function finish_part(): void {
		if ( count( $this->parts ) >= self::MAX_PARTS ) {
			throw new Failure( 'WXR_BUNDLE_LIMIT_EXCEEDED', 413 );
		}
		$this->storage->write( $this->suffix );
		$sha256 = hash_init( 'sha256' );
		$git    = hash_init( 'sha1' );
		hash_update( $git, 'blob ' . $this->raw . "\0" );
		foreach ( $this->storage->range_chunks( $this->start, $this->raw ) as $chunk ) {
			hash_update( $sha256, $chunk );
			hash_update( $git, $chunk );
		}
		$hash          = hash_final( $sha256 );
		$this->parts[] = array(
			'path'          => self::DIRECTORY . '/p-' . $hash . '.xml',
			'bytes'         => $this->raw,
			'sha256'        => $hash,
			'records'       => $this->counts,
			'offset'        => $this->start,
			'git'           => hash_final( $git ),
			'request_bytes' => $this->wire,
		);
	}
	/**
	 * Bind the exact base bytes and canonical logical digest.
	 *
	 * @param string $base Exact serialized base settings.
	 * @param string $hash Legacy logical export digest.
	 * @param int    $time Export timestamp in seconds.
	 * @return void
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	public function finish( string $base, string $hash, int $time ): void {
		$this->finish_part();
		if ( strlen( $base ) > self::MAX_FILE || self::request_size( $base ) > self::MAX_FILE ) {
			throw new Failure( 'WXR_BUNDLE_LIMIT_EXCEEDED', 413 );
		}
		$parts          = array_map( static fn( $part ) => array_intersect_key( $part, array_flip( array( 'path', 'bytes', 'sha256', 'records' ) ) ), $this->parts );
		$this->manifest = wp_json_encode(
			array(
				'format'         => 'zeropress-wxr-bundle',
				'format_version' => 1,
				'wxr_version'    => '1.2',
				'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
				'data_hash'      => $hash,
				'base'           => array(
					'path'   => 'wxr-import-base.json',
					'bytes'  => strlen( $base ),
					'sha256' => hash( 'sha256', $base ),
				),
				'parts'          => $parts,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . "\n";
		if ( strlen( $this->manifest ) > self::MAX_MANIFEST ) {
			throw new Failure( 'WXR_BUNDLE_LIMIT_EXCEEDED', 413 );
		}
		$this->digest = hash( 'sha256', $this->manifest );
	}
	/**
	 * Copy one part to persistent private storage without materializing the aggregate.
	 *
	 * @param array $part Validated split-WXR part descriptor.
	 */
	public function chunks( array $part ): \Generator {
		yield from $this->storage->range_chunks( $part['offset'], $part['bytes'] );
	}

	/**
	 * Materialize only one checked part for the HTTP client.
	 *
	 * @param array $part Verified private part descriptor.
	 * @return string
	 */
	public function contents( array $part ): string {
		$this->budget->check( max( $part['bytes'], $part['request_bytes'] ) );
		return $this->storage->range_contents( $part['offset'], $part['bytes'] );
	}
	/**
	 * Reconstruct unchanged legacy bytes for small capable exports.
	 *
	 * @param string $date Legacy channel pubDate element.
	 * @param int    $prefix_bytes Length before the shared channel header.
	 * @return Export_File
	 * @throws \Throwable If validation, storage or the request budget fails.
	 */
	public function single_file( string $date, int $prefix_bytes ): Export_File {
		$file = new Export_File( $this->budget );
		try {
			$file->write( substr( $this->prefix, 0, $prefix_bytes ) );
			$file->write( $date );
			$file->write( substr( $this->prefix, $prefix_bytes ) );
			foreach ( $this->parts as $part ) {
				foreach ( $this->storage->range_chunks( $part['offset'] + strlen( $this->prefix ), $part['bytes'] - strlen( $this->prefix . $this->suffix ) ) as $chunk ) {
					$file->write( $chunk );
				}
			}
			$file->write( $this->suffix );
			return $file;
		} catch ( \Throwable $error ) {
			$file->close();
			throw $error;
		}
	}
	/**
	 * Count the legacy request without materializing it.
	 *
	 * @param string $date Legacy channel pubDate element.
	 * @return int
	 */
	public function single_request_size( string $date ): int {
		$size = self::request_size( $this->prefix . $date . $this->suffix );
		foreach ( $this->parts as $part ) {
			$size += $part['request_bytes'] - self::request_size( $this->prefix . $this->suffix );
		}
		return $size;
	}
	/**
	 * Release the private aggregate spool.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->storage->close();
	}
	/**
	 * Ensure abandoned request-owned storage is closed.
	 */
	public function __destruct() {
		$this->close();
	}
}
