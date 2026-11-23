<?php
/** Private ordered record bodies and bounded descriptors.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Keeps record metadata on disk rather than retaining a site-sized array. */
final class Wxr_Record_Store {

	private const KINDS       = array(
		1 => 'authors',
		'categories',
		'tags',
		'terms',
		'items',
	);
	private const SECTIONS    = array(
		1 => 'channel',
		'documents',
		'menus',
		'attachments',
	);
	private const ENTRY_BYTES = 20;
	/**
	 * Record bodies.
	 *
	 * @var Export_File
	 */
	private Export_File $body;
	/**
	 * Length, escaped length, hash prefix, kind and section.
	 *
	 * @var Export_File
	 */
	private Export_File $index;
	/**
	 * Number of stored records.
	 *
	 * @var int
	 */
	private int $count = 0;
	/**
	 * Last nonempty section.
	 *
	 * @var int
	 */
	private int $section = 0;

	/**
	 * Open request-owned storage.
	 *
	 * @param Resource_Budget $budget Shared request limits.
	 */
	public function __construct( Resource_Budget $budget ) {
		$this->body  = new Export_File( $budget );
		$this->index = new Export_File( $budget );
	}

	/**
	 * Store a record with its known exporter identity, never an ID parsed from XML.
	 *
	 * @param string $xml Complete serialized record.
	 * @param string $kind Public inventory kind.
	 * @param int    $id WordPress object ID; author zero is allowed by existing exports.
	 * @param string $section Existing ordered export section.
	 * @throws Failure If metadata, record size or storage is invalid.
	 */
	public function append( string $xml, string $kind, int $id, string $section ): void {
		if ( '' === $xml ) {
			return;
		}
		$kind_code    = array_search( $kind, self::KINDS, true );
		$section_code = array_search( $section, self::SECTIONS, true );
		if ( false === $kind_code || false === $section_code || $id < 0 || ( 0 === $id && 'authors' !== $kind )
			|| $section_code < $this->section || ( ( 5 === $kind_code ) !== ( $section_code > 1 ) ) ) {
			throw new Failure( 'INTERNAL_ERROR', 500 );
		}
		$raw  = strlen( $xml );
		$wire = Wxr_Bundle::escaped_size( $xml );
		if ( $raw > Wxr_Bundle::MAX_FILE || $wire + Wxr_Bundle::request_size( '' ) > Wxr_Bundle::MAX_FILE ) {
			throw new Failure( 'WXR_RECORD_TOO_LARGE', 413 );
		}
		$key    = $kind . "\0" . ( 'terms' === $kind ? "nav_menu\0" : '' ) . $id;
		$hash   = hash( 'sha256', "zeropress-wxr-boundary-v1\0" . $key, true );
		$anchor = unpack( 'n', substr( $hash, 0, 2 ) )[1];
		$this->body->write( $xml );
		$this->index->write( pack( 'JJnCC', $raw, $wire, $anchor, $kind_code, $section_code ) );
		++$this->count;
		$this->section = $section_code;
	}

	/**
	 * Replay checked descriptors, handling arbitrary short reads.
	 *
	 * @return \Generator
	 * @throws Failure If a descriptor or the body inventory is incomplete.
	 */
	public function records(): \Generator {
		$pending = '';
		$offset  = 0;
		$count   = 0;
		$section = 0;
		foreach ( $this->index->chunks() as $chunk ) {
			$chunk    = $pending . $chunk;
			$complete = strlen( $chunk ) - strlen( $chunk ) % self::ENTRY_BYTES;
			$pending  = substr( $chunk, $complete );
			for ( $position = 0; $position < $complete; $position += self::ENTRY_BYTES ) {
				$record = unpack( 'Jraw/Jwire/nanchor/Ckind/Csection', substr( $chunk, $position, self::ENTRY_BYTES ) );
				if ( $record['raw'] <= 0 || $record['raw'] > Wxr_Bundle::MAX_FILE
					|| $record['wire'] < $record['raw'] || $record['wire'] > Wxr_Bundle::MAX_FILE
					|| ! isset( self::KINDS[ $record['kind'] ], self::SECTIONS[ $record['section'] ] )
					|| ( ( 5 === $record['kind'] ) !== ( $record['section'] > 1 ) )
					|| $record['section'] < $section || $record['raw'] > $this->body->size() - $offset ) {
					throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
				}
				$section          = $record['section'];
				$record['kind']   = self::KINDS[ $record['kind'] ];
				$record['offset'] = $offset;
				$offset          += $record['raw'];
				++$count;
				yield $record;
			}
		}
		if ( '' !== $pending || $offset !== $this->body->size() || $count !== $this->count ) {
			throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
		}
	}

	/**
	 * Read one already bounded record for serialization and hashing.
	 *
	 * @param array $record Descriptor yielded by records().
	 * @return string
	 */
	public function contents( array $record ): string {
		return $this->body->range_contents( $record['offset'], $record['raw'] );
	}

	/** Close both private handles on every exit. */
	public function close(): void {
		$this->body->close();
		$this->index->close();
	}
}
