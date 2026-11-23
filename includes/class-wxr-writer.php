<?php
/** Ordered WXR record storage shared by single and split output.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Spools complete items before the channel metadata is known. */
final class Wxr_Writer {
	private const PREFIX = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n";
	private const SUFFIX = "</channel>\n</rss>\n";
	/**
	 * Shared request limits.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/**
	 * Complete ordered item records.
	 *
	 * @var Export_File|Wxr_Record_Store
	 */
	private Export_File|Wxr_Record_Store $items;
	/**
	 * Selected single, auto or bundle transport.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Open private record storage.
	 *
	 * @param Resource_Budget $budget Shared request limits.
	 * @param string          $mode Single, automatic or established bundle transport.
	 * @throws Failure If memory mode cannot provide the requested format.
	 */
	public function __construct( Resource_Budget $budget, string $mode = 'single' ) {
		if ( Publication_Runtime::memory() && 'single' !== $mode ) {
			throw new Failure( 'MEMORY_SINGLE_REQUIRED', 409 );
		}
		$this->budget = $budget;
		$this->mode   = $mode;
		$this->items  = 'single' === $mode ? new Export_File( $budget ) : new Wxr_Record_Store( $budget );
	}

	/**
	 * Store one complete item with its exporter identity and existing section.
	 *
	 * @param string $xml Complete generated XML record.
	 * @param int    $id WordPress post ID.
	 * @param string $section Documents, menus or attachments.
	 */
	public function item( string $xml, int $id, string $section = 'documents' ): void {
		if ( $this->items instanceof Wxr_Record_Store ) {
			$this->items->append( $xml, 'items', $id, $section );
		} else {
			$this->items->write( $xml );
		}
	}

	/**
	 * Replay the canonical sequence; physical cuts never enter the logical hash.
	 *
	 * @param iterable $channel Header followed by structured channel records.
	 * @param string   $date Legacy channel pubDate element.
	 * @param string   $base Exact serialized base settings.
	 * @param int|null $time Export timestamp in seconds.
	 * @return array
	 * @throws \Throwable If regeneration or storage fails.
	 * @throws Failure If a protection check fails.
	 */
	public function finish( iterable $channel, string $date, string $base, ?int $time = null ): array {
		$wxr      = null;
		$bundle   = null;
		$metadata = null;
		try {
			$hash = hash_init( 'sha256' );
			hash_update( $hash, "zeropress-wordpress-wxr-v1\0" . self::PREFIX );
			$logical_bytes = strlen( self::PREFIX . $date . self::SUFFIX );
			if ( $this->items instanceof Export_File ) {
				$wxr = new Export_File( $this->budget );
				$wxr->write( self::PREFIX );
				$wxr->write( $date );
			} else {
				$metadata = new Wxr_Record_Store( $this->budget );
			}
			$prefix = null;
			foreach ( $channel as $record ) {
				if ( null === $prefix ) {
					$prefix = self::PREFIX . $record;
					hash_update( $hash, $record );
					$logical_bytes += strlen( $record );
					$wxr?->write( $record );
				} elseif ( $metadata ) {
					$metadata->append( $record['xml'], $record['kind'], $record['id'], 'channel' );
				} else {
					$wxr->write( $record['xml'] );
					hash_update( $hash, $record['xml'] );
					$logical_bytes += strlen( $record['xml'] );
				}
			}
			if ( null === $prefix ) {
				throw new Failure( 'INTERNAL_ERROR', 500 );
			}
			if ( $metadata ) {
				$stable = new Wxr_Part_Plan( $this->budget, $prefix, self::SUFFIX );
				$legacy = new Wxr_Part_Plan( $this->budget, $prefix, self::SUFFIX, false );
				foreach ( array( $metadata, $this->items ) as $store ) {
					foreach ( $store->records() as $record ) {
						$stable->record( $record );
						$legacy->record( $record );
					}
				}
				$plan = $stable->finish() ?? $legacy->finish();
				unset( $stable, $legacy );
				if ( null === $plan ) {
					throw new Failure( 'WXR_BUNDLE_LIMIT_EXCEEDED', 413 );
				}
				$bundle  = new Wxr_Bundle( $this->budget, $prefix, self::SUFFIX );
				$ordinal = 0;
				$part    = 0;
				foreach ( array( $metadata, $this->items ) as $store ) {
					foreach ( $store->records() as $record ) {
						$boundary = $ordinal > 0 && $ordinal === $plan[ $part ]['end'];
						if ( $boundary ) {
							++$part;
						}
						$xml = $store->contents( $record );
						hash_update( $hash, $xml );
						$logical_bytes += strlen( $xml );
						$bundle->record( $xml, $record['kind'], $boundary );
						++$ordinal;
						unset( $xml );
					}
				}
			} else {
				foreach ( $this->items->chunks() as $xml ) {
					$wxr->write( $xml );
					hash_update( $hash, $xml );
					$logical_bytes += strlen( $xml );
				}
				$wxr->write( self::SUFFIX );
			}
			hash_update( $hash, self::SUFFIX . "\0" . $base );
			$digest = hash_final( $hash );
			if ( Publication_Runtime::memory() ) {
				Publication_Runtime::check_size( $logical_bytes + strlen( $base ) );
			}
			if ( $bundle ) {
				$bundle->finish( $base, $digest, $time ?? time() );
				$this->verify_plan( $bundle, $plan, $ordinal );
				if ( 'auto' === $this->mode && $logical_bytes <= Wxr_Bundle::MAX_FILE && $bundle->single_request_size( $date ) <= Wxr_Bundle::MAX_FILE ) {
					try {
						$this->budget->check( $bundle->single_request_size( $date ) );
						$wxr = $bundle->single_file( $date, strlen( self::PREFIX ) );
					} catch ( Failure $error ) {
						if ( 'MEMORY_LIMIT_EXCEEDED' !== $error->reason ) {
							throw $error;
						}
					}
					if ( $wxr ) {
						$bundle->close();
						$bundle = null;
					}
				}
			}
			return array(
				'wxr'       => $wxr,
				'bundle'    => $bundle,
				'wxr_bytes' => $logical_bytes,
				'data_hash' => $digest,
			);
		} catch ( \Throwable $error ) {
			$wxr?->close();
			$bundle?->close();
			throw $error;
		} finally {
			$metadata?->close();
			$this->close();
		}
	}

	/**
	 * Recheck emitted sizes and counts, without trusting descriptor data alone.
	 *
	 * @param Wxr_Bundle $bundle Actual serialized parts.
	 * @param array      $plan Selected bounded plan.
	 * @param int        $ordinal Replayed record count.
	 * @throws Failure If replay differs from planning.
	 */
	private function verify_plan( Wxr_Bundle $bundle, array $plan, int $ordinal ): void {
		if ( count( $bundle->parts ) !== count( $plan ) || $ordinal !== $plan[ count( $plan ) - 1 ]['end'] ) {
			throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
		}
		foreach ( $bundle->parts as $i => $part ) {
			foreach ( array( 'bytes', 'request_bytes', 'records' ) as $key ) {
				if ( $part[ $key ] !== $plan[ $i ][ $key ] ) {
					throw new Failure( 'EXPORT_STORAGE_UNAVAILABLE', 500 );
				}
			}
		}
	}

	/** Close request-owned scratch handles; safe to call repeatedly. */
	public function close(): void {
		$this->items->close();
	}
}
