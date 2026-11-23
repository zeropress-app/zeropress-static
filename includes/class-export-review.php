<?php
/** Bounded, short-lived read-only views of prepared menu and media records.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Review data never supplies content or approval to a publication. */
final class Export_Review {

	public const PAGE_SIZE   = 50;
	private const PAGE_BYTES = 65536;
	private const MAX_BYTES  = 16 * MB_IN_BYTES;
	private const PREFIX     = 'zpsp_publish_review_';
	/**
	 * Random namespace, private to one preparation.
	 *
	 * @var string
	 */
	private string $id;
	/**
	 * Cache deadline, never extended by reads.
	 *
	 * @var int
	 */
	private int $expires;
	/**
	 * Optional details must not impose another export size limit.
	 *
	 * @var bool
	 */
	private bool $available = true;
	/**
	 * Serialized bytes across all pages.
	 *
	 * @var int
	 */
	private int $bytes = 0;
	/**
	 * One bounded pending page per record kind.
	 *
	 * @var array
	 */
	private array $pending = array(
		'menus' => array(),
		'media' => array(),
	);
	/**
	 * Pending encoded sizes.
	 *
	 * @var array
	 */
	private array $sizes = array(
		'menus' => 0,
		'media' => 0,
	);
	/**
	 * Completed page counts.
	 *
	 * @var array
	 */
	private array $pages = array(
		'menus' => 0,
		'media' => 0,
	);
	/**
	 * Record counts, separate from the number of source menus.
	 *
	 * @var array
	 */
	private array $counts = array(
		'menus' => 0,
		'media' => 0,
	);
	/**
	 * At most 50 selected menu summaries.
	 *
	 * @var array
	 */
	private array $menus = array();
	/**
	 * Encoded menu-summary allowance.
	 *
	 * @var int
	 */
	private int $menu_bytes = 0;

	/**
	 * Share the existing preparation limits.
	 *
	 * @param Resource_Budget $budget Shared preparation budget.
	 */
	public function __construct( private Resource_Budget $budget ) {
		$this->id      = bin2hex( random_bytes( 16 ) );
		$this->expires = time() + 600;
	}

	/**
	 * Collect a bounded source-menu summary.
	 *
	 * @param array $menu Source menu and its output IDs.
	 */
	public function menu( array $menu ): void {
		if ( $this->available ) {
			$this->menu_bytes += strlen( $menu['name'] ) * 6 + array_sum( array_map( 'strlen', $menu['slots'] ) ) * 6 + 512;
			if ( $this->menu_bytes > self::PAGE_BYTES - 4096 ) {
				$this->available = false;
				return;
			}
			$this->budget->check( $this->menu_bytes );
			$this->menus[] = $menu;
		}
	}

	/** Record prepared display values, without reading media again.
	 *
	 * @param string $kind Menus or media.
	 * @param array  $row Allowlisted display fields.
	 */
	public function record( string $kind, array $row ): void {
		if ( ! $this->available ) {
			return;
		}
		$size = 1024;
		foreach ( $row as $value ) {
			$size += is_string( $value ) ? strlen( $value ) : 32;
		}
		if ( $size > self::PAGE_BYTES ) {
			$this->available = false;
			return;
		}
		$this->budget->check( $size );
		$json = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) + 1024 > self::PAGE_BYTES ) {
			$this->available = false;
			return;
		}
		if ( $this->sizes[ $kind ] + strlen( $json ) + 1024 > self::PAGE_BYTES ) {
			$this->flush( $kind );
		}
		$this->pending[ $kind ][] = $json;
		$this->sizes[ $kind ]    += strlen( $json ) + 1;
		++$this->counts[ $kind ];
		if ( count( $this->pending[ $kind ] ) === self::PAGE_SIZE ) {
			$this->flush( $kind );
		}
	}

	/**
	 * Persist one complete display page.
	 *
	 * @param string $kind Page kind.
	 */
	private function flush( string $kind ): void {
		if ( ! $this->pending[ $kind ] ) {
			return;
		}
		$offset = $this->counts[ $kind ] - count( $this->pending[ $kind ] );
		$json   = '{"offset":' . $offset . ',"items":[' . implode( ',', $this->pending[ $kind ] ) . ']}';
		$this->put( $kind . '_' . $this->pages[ $kind ], $json );
		++$this->pages[ $kind ];
		$this->pending[ $kind ] = array();
		$this->sizes[ $kind ]   = 0;
	}

	/** Store only bounded JSON and authenticate its namespace and contents.
	 *
	 * @param string $part Manifest or numbered page.
	 * @param string $json Bounded metadata, never post bodies or credentials.
	 */
	private function put( string $part, string $json ): void {
		if ( ! $this->available ) {
			return;
		}
		$this->bytes += strlen( $json );
		if ( strlen( $json ) > self::PAGE_BYTES || $this->bytes > self::MAX_BYTES || time() >= $this->expires ) {
			$this->available = false;
			return;
		}
		$this->budget->check( strlen( $json ) * 2 );
		$key   = self::PREFIX . $this->id . '_' . $part;
		$value = array(
			'json' => $json,
			'seal' => hash_hmac( 'sha256', $key . "\0" . $json, Settings::key( 'export-review' ) ),
		);
		if ( ! set_transient( $key, $value, max( 1, $this->expires - time() ) ) ) {
			$this->available = false;
		}
	}

	/** Finish only after successful preparation. Partial caches expire without a receipt.
	 *
	 * @param array $receipt Server-owned preparation binding.
	 * @return string|null Identifier sealed into the preparation receipt.
	 */
	public function finish( array $receipt ): ?string {
		$this->flush( 'menus' );
		$this->flush( 'media' );
		if ( ! $this->available ) {
			return null;
		}
		$manifest  = array_intersect_key( $receipt, array_flip( array( 'user', 'revision', 'hash', 'backend' ) ) );
		$manifest += array(
			'expires' => $this->expires,
			'pages'   => $this->pages,
			'counts'  => $this->counts,
			'menus'   => $this->menus,
		);
		$this->budget->check( $this->menu_bytes + 4096 );
		$json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return null;
		}
		$this->put( 'manifest', $json );
		return $this->available ? $this->id : null;
	}

	/**
	 * Reject page coercion and out-of-range input.
	 *
	 * @param mixed $page Zero-based page from a JSON request.
	 * @throws Failure If the page is invalid.
	 */
	public static function page_number( mixed $page ): int {
		if ( ! is_int( $page ) || $page < 0 || $page > 1000000 ) {
			throw new Failure( 'INVALID_REQUEST' );
		}
		return $page;
	}

	/** Read one immutable page without regenerating WXR or querying current media.
	 *
	 * @param string          $receipt Signed preparation receipt.
	 * @param string          $kind Menus or media.
	 * @param int             $page Zero-based page.
	 * @param Resource_Budget $budget Read request budget.
	 * @throws Failure If approval, cache authentication or resource checks fail.
	 */
	public static function read( string $receipt, string $kind, int $page, Resource_Budget $budget ): array {
		$budget->check();
		self::page_number( $page );
		if ( ! in_array( $kind, array( 'menus', 'media' ), true ) ) {
			throw new Failure( 'INVALID_REQUEST' );
		}
		list( , $binding ) = Publisher::receipt( $receipt );
		$id                = $binding['review'] ?? '';
		if ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) {
			throw new Failure( 'REVIEW_UNAVAILABLE', 410 );
		}
		$manifest = self::get( $id, 'manifest', $budget );
		foreach ( array( 'user', 'revision', 'hash', 'backend' ) as $key ) {
			if ( ( $manifest[ $key ] ?? null ) !== $binding[ $key ] ) {
				throw new Failure( 'REVIEW_UNAVAILABLE', 410 );
			}
		}
		if ( time() >= $manifest['expires'] ) {
			throw new Failure( 'REVIEW_UNAVAILABLE', 410 );
		}
		$pages = $manifest['pages'][ $kind ];
		if ( $page >= max( 1, $pages ) ) {
			throw new Failure( 'INVALID_REQUEST' );
		}
		$data = $pages ? self::get( $id, $kind . '_' . $page, $budget ) : array(
			'offset' => 0,
			'items'  => array(),
		);
		$budget->check();
		return $data + array(
			'page'  => $page,
			'pages' => $pages,
			'total' => $manifest['counts'][ $kind ],
			'menus' => 'menus' === $kind ? $manifest['menus'] : array(),
		);
	}

	/**
	 * Authenticate a bounded cache entry before decoding.
	 *
	 * @param string          $id Receipt-bound namespace.
	 * @param string          $part Manifest or page name.
	 * @param Resource_Budget $budget Shared read limits.
	 * @throws Failure If the cache is absent, oversized or unauthenticated.
	 */
	private static function get( string $id, string $part, Resource_Budget $budget ): array {
		$budget->check( self::PAGE_BYTES * 2 );
		$key   = self::PREFIX . $id . '_' . $part;
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! is_string( $value['json'] ?? null ) || strlen( $value['json'] ) > self::PAGE_BYTES ||
			! is_string( $value['seal'] ?? null ) || ! hash_equals( hash_hmac( 'sha256', $key . "\0" . $value['json'], Settings::key( 'export-review' ) ), $value['seal'] ) ) {
			throw new Failure( 'REVIEW_UNAVAILABLE', 410 );
		}
		$result = json_decode( $value['json'], true );
		if ( ! is_array( $result ) ) {
			throw new Failure( 'REVIEW_UNAVAILABLE', 410 );
		}
		return $result;
	}

	/** Keep indentation bounded even for malformed/cyclic menu parents.
	 *
	 * @param int   $id Item ID.
	 * @param array $parents IDs admitted into this menu.
	 */
	public static function depth( int $id, array $parents ): int {
		$seen  = array( $id => true );
		$depth = 0;
		while ( ! empty( $parents[ $id ] ) && isset( $parents[ $parents[ $id ] ] ) && $depth < 10 ) {
			$id = (int) $parents[ $id ];
			if ( isset( $seen[ $id ] ) ) {
				break;
			}
			$seen[ $id ] = true;
			++$depth;
		}
		return $depth;
	}
}
