<?php
/**
 * Minimal attachment evidence for the existing WXR importer.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Retains featured images and enough URLs to preserve all-or-nothing inference. */
final class Media_References {

	/** Featured attachment URLs, keyed by source ID.
	 *
	 * @var array
	 */
	private array $featured = array();
	/** Up to two different raw uploads prefixes and their first records.
	 *
	 * @var array
	 */
	private array $prefixes = array();
	/** Up to two prefixes already represented by featured images.
	 *
	 * @var array
	 */
	private array $featured_prefixes = array();
	/** URLs outside the conservative grouping rules.
	 *
	 * @var array
	 */
	private array $ungrouped = array();
	/** First record known not to support uploads-prefix inference.
	 *
	 * @var array|null
	 */
	private ?array $no_prefix = null;
	/** Whether a featured image already prevents inference.
	 *
	 * @var bool
	 */
	private bool $featured_no_prefix = false;

	/**
	 * Share the export's allocation and deadline checks.
	 *
	 * @param Resource_Budget $budget Request resource budget.
	 */
	public function __construct( private Resource_Budget $budget ) {}

	/**
	 * Observe every admitted, rewritten URL before selecting export records.
	 *
	 * @param int    $id Attachment ID.
	 * @param string $url Rewritten URL, not a downloaded resource.
	 * @param bool   $featured Whether a published document uses this featured image.
	 */
	public function add( int $id, string $url, bool $featured ): void {
		$this->budget->check( strlen( $url ) + 512 );
		if ( $featured ) {
			$this->featured[ $id ] = $url;
		}
		$prefix = self::prefix( $url );
		if ( null === $prefix ) {
			if ( ! $featured ) {
				$this->ungrouped[ $id ] = $url;
			}
		} elseif ( '' === $prefix ) {
			$this->featured_no_prefix = $this->featured_no_prefix || $featured;
			$this->no_prefix        ??= array( $id, $url );
		} else {
			if ( $featured && count( $this->featured_prefixes ) < 2 ) {
				$this->featured_prefixes[ $prefix ] = true;
			}
			if ( count( $this->prefixes ) < 2 && ! isset( $this->prefixes[ $prefix ] ) ) {
				$this->prefixes[ $prefix ] = array( $id, $url );
			}
		}
	}

	/**
	 * Return real attachment IDs in the original numeric order; never invent media.
	 *
	 * @return array<int,string>
	 */
	public function selected(): array {
		$this->budget->check( ( count( $this->featured ) + count( $this->ungrouped ) + 2 ) * 256 );
		$selected = $this->featured + $this->ungrouped;
		if ( ! $this->featured_no_prefix ) {
			if ( null !== $this->no_prefix ) {
				$selected[ $this->no_prefix[0] ] = $this->no_prefix[1];
			} else {
				$represented = $this->featured_prefixes;
				foreach ( $this->prefixes as $prefix => list( $id, $url ) ) {
					if ( count( $represented ) < 2 && ! isset( $represented[ $prefix ] ) ) {
						$selected[ $id ]        = $url;
						$represented[ $prefix ] = true;
					}
				}
			}
		}
		ksort( $selected, SORT_NUMERIC );
		return $selected;
	}

	/**
	 * Group only URLs whose path tails cannot change the importer's URL admission.
	 *
	 * This is not a second URL validator. Identical raw prefixes either both pass
	 * the importer's parser or both fail. Two different prefixes, or one URL with
	 * no default uploads prefix, preserve its refusal to infer a common prefix.
	 * Keep unusual spellings individually rather than guessing how JS parses them.
	 *
	 * @param string $url Rewritten URL.
	 * @return string|null Raw prefix, empty for no prefix, null for ungrouped input.
	 */
	private static function prefix( string $url ): ?string {
		if ( 0 !== preg_match( '/[\p{Z}\p{C}\s\\\\]|%(?![0-9a-f]{2})/iu', $url ) ||
			! preg_match( '~^https?://[^/?#]+/~i', $url, $match ) ) {
			return null;
		}
		$start  = strlen( $match[0] ) - 1;
		$path   = substr( $url, $start, strcspn( $url, '?#', $start ) );
		$marker = '/wp-content/uploads/';
		$offset = strpos( $path, $marker );
		if ( false === $offset || $offset + strlen( $marker ) >= strlen( $path ) ) {
			return '';
		}
		return substr( $url, 0, $start + $offset + strlen( $marker ) );
	}
}
