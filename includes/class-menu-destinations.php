<?php
/** Display-only menu destinations matching the default WXR importer route policy.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Never changes WXR, build settings, publication approval or exported content. */
final class Menu_Destinations {
	/** Optional display metadata is bounded independently of export storage. */
	private const MAX_BYTES = 4194304;
	/** WordPress origin and installation path.
	 *
	 * @var array
	 */
	private array $source;
	/** Minimal page lineage, never bodies or metadata.
	 *
	 * @var array
	 */
	private array $pages = array();
	/** Only posts referenced by the selected menu(s).
	 *
	 * @var array
	 */
	private array $posts = array();
	/** Used post slugs for importer collision handling.
	 *
	 * @var array
	 */
	private array $slugs = array();
	/** Ordered counts preserve the importer's first-candidate tie break.
	 *
	 * @var array
	 */
	private array $votes = array();
	/** References that need a post destination.
	 *
	 * @var array
	 */
	private array $wanted;
	/** Resolved relative document URLs.
	 *
	 * @var array
	 */
	private array $urls = array();
	/** Approximate retained metadata cost, including map overhead.
	 *
	 * @var int
	 */
	private int $bytes = 0;
	/** Directory-style source links.
	 *
	 * @var int
	 */
	private int $directory = 0;
	/** Extension-style source links.
	 *
	 * @var int
	 */
	private int $extension = 0;
	/** Optional projection can be incomplete without blocking export.
	 *
	 * @var bool
	 */
	private bool $available = true;
	/** Lazy finalization after all source documents have been observed.
	 *
	 * @var bool
	 */
	private bool $finished = false;
	/** Only entity links need whole-input route inference.
	 *
	 * @var bool
	 */
	private bool $needs_documents;

	/** Create a display context from saved settings and menu references.
	 *
	 * @param string          $public_url Saved static origin, or empty.
	 * @param Resource_Budget $budget Existing request budget.
	 * @param array           $entries Selected WordPress menu items.
	 */
	public function __construct( private string $public_url, private Resource_Budget $budget, array $entries ) {
		$source                = wp_parse_url( untrailingslashit( home_url( '/' ) ) );
		$this->source          = is_array( $source ) ? $source : array();
		$this->needs_documents = (bool) array_filter( $entries, static fn( $entry ) => 'custom' !== $entry->type );
		$this->wanted          = array();
		foreach ( $entries as $entry ) {
			if ( 'post_type' === $entry->type ) {
				$this->wanted[ (int) $entry->object_id ] = true;
			}
		}
	}

	/**
	 * Reuse the exporter's visitor-link projection, including source subdirectories.
	 *
	 * @param string $url WordPress or custom link.
	 * @param string $public_url Saved static origin.
	 * @param array  $source Parsed WordPress site address.
	 */
	public static function site_link( string $url, string $public_url, array $source ): string {
		if ( '' === $public_url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $url;
		}
		$relative = str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' );
		if ( ! $relative ) {
			$scheme = strtolower( $parts['scheme'] ?? '' );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
				return $url;
			}
			foreach ( array( 'scheme', 'host' ) as $key ) {
				if ( strtolower( $parts[ $key ] ) !== strtolower( $source[ $key ] ?? '' ) ) {
					return $url;
				}
			}
			$default_port = 'https' === $scheme ? 443 : 80;
			if ( ( $parts['port'] ?? $default_port ) !== ( $source['port'] ?? $default_port ) ) {
				return $url;
			}
		}
		$base_path = untrailingslashit( $source['path'] ?? '' );
		$path      = $parts['path'] ?? '/';
		if ( '' !== $base_path ) {
			if ( $path !== $base_path && ! str_starts_with( $path, $base_path . '/' ) ) {
				return $url;
			}
			$path = substr( $path, strlen( $base_path ) );
		}
		return $public_url . ( '' === $path ? '/' : $path )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Observe the same eligible records and links as WXR, without retaining bodies.
	 *
	 * @param \WP_Post $post Admitted source record.
	 * @param string   $link Actual WXR link.
	 */
	public function observe( \WP_Post $post, string $link ): void {
		$this->budget->check();
		if ( ! $this->needs_documents || ! $this->available || 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! self::date( $post->post_date_gmt ) || ! self::date( $post->post_modified_gmt ) ) {
			return;
		}
		$slug = self::slug( $post->post_name );
		if ( null === $slug ) {
			$this->unavailable();
			return;
		}
		$path = $this->source_path( $link );
		if ( null !== $path && '/' !== $path ) {
			if ( str_ends_with( $path, '/' ) ) {
				++$this->directory;
			} else {
				++$this->extension;
			}
		}
		$record = array(
			'slug'   => $slug,
			'parent' => (int) $post->post_parent,
			'path'   => $path,
		);
		if ( 'page' === $post->post_type ) {
			$this->pages[ $post->ID ] = $record;
			$this->retain( strlen( $slug ) + strlen( $path ?? '' ) + 512 );
			return;
		}
		$candidate = null === $path ? null : self::post_pattern( $path, (int) $post->ID, $slug, $post->post_date );
		if ( null !== $candidate ) {
			if ( ! isset( $this->votes[ $candidate ] ) ) {
				$this->retain( strlen( $candidate ) + 128 );
			}
			$this->votes[ $candidate ] = ( $this->votes[ $candidate ] ?? 0 ) + 1;
		}
		$unique = $slug;
		if ( isset( $this->slugs[ $unique ] ) ) {
			$unique = $slug . '-' . $post->ID;
			for ( $suffix = 2; isset( $this->slugs[ $unique ] ) && $suffix < 1000; ++$suffix ) {
				$unique = $slug . '-' . $post->ID . '-' . $suffix;
			}
		}
		$this->slugs[ $unique ] = true;
		$this->retain( strlen( $unique ) + 96 );
		if ( isset( $this->wanted[ $post->ID ] ) ) {
			$record['slug']           = $unique;
			$record['gmt']            = $post->post_date_gmt;
			$this->posts[ $post->ID ] = $record;
			$this->retain( strlen( $unique ) + 512 );
		}
	}

	/**
	 * Only canonical safe slugs are projected; unfamiliar normalization stays unresolved.
	 *
	 * @param string $value WordPress slug or URL segment.
	 */
	private static function slug( string $value ): ?string {
		$value   = trim( $value );
		$decoded = rawurldecode( $value );
		if ( ! preg_match( '/%[^0-9a-f]|%[0-9a-f]?(?:$|[^0-9a-f])/i', $value ) && preg_match( '//u', $decoded ) ) {
			$value = $decoded;
		}
		if ( class_exists( '\Normalizer' ) ) {
			$value = \Normalizer::normalize( $value, \Normalizer::FORM_C );
		} elseif ( preg_match( '/\p{M}/u', $value ) ) {
			return null;
		}
		$value = preg_replace( '/[\s\/\\\\%?#\x00-\x1f\x7f]+|\.{2,}/u', '-', $value );
		$value = trim( preg_replace( '/-+/', '-', $value ?? '' ), '.-' );
		return preg_match( '/\A(?=.*[\p{L}\p{Nd}])[\p{L}\p{M}\p{Nd}._-]{1,200}\z/u', $value ) ? $value : null;
	}

	/** Source date, exactly as stored in WXR.
	 *
	 * @param string $value Source date, exactly as stored in WXR.
	 */
	private static function date( string $value ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value && (int) $date->format( 'Y' ) > 0 ? $date : null;
	}

	/** Projected WXR link with the importer's source base removed.
	 *
	 * @param string $link Absolute or root-relative projected WXR link.
	 * @param bool   $allow_relative Whether navigation may use a root-relative link.
	 */
	private function source_path( string $link, bool $allow_relative = false ): ?string {
		$source = '' !== $this->public_url ? wp_parse_url( $this->public_url ) : $this->source;
		$parts  = wp_parse_url( $link );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$relative = $allow_relative && str_starts_with( $link, '/' ) && ! str_starts_with( $link, '//' );
		if ( ! $relative ) {
			if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
				return null;
			}
			foreach ( array( 'scheme', 'host' ) as $key ) {
				if ( strtolower( $parts[ $key ] ?? '' ) !== strtolower( $source[ $key ] ?? '' ) ) {
					return null;
				}
			}
			$port = 'https' === $parts['scheme'] ? 443 : 80;
			if ( ( $parts['port'] ?? $port ) !== ( $source['port'] ?? $port ) ) {
				return null;
			}
		}
		$path = $parts['path'] ?? '/';
		$base = rtrim( $source['path'] ?? '', '/' );
		if ( '' !== $base && ( $path === $base || str_starts_with( $path, $base . '/' ) ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '' === $path ? '/' : $path;
	}

	/**
	 * Infer a candidate using the importer's identity/date token rules.
	 *
	 * @param string $path Root-relative path.
	 * @param int    $id Post ID.
	 * @param string $slug Normalized post slug.
	 * @param string $date WordPress local date.
	 */
	private static function post_pattern( string $path, int $id, string $slug, string $date ): ?string {
		$local  = self::date( $date );
		$tokens = array(
			'public_id' => (string) $id,
			'slug'      => $slug,
		);
		if ( $local ) {
			$tokens += array(
				'year'  => $local->format( 'Y' ),
				'month' => $local->format( 'm' ),
				'day'   => $local->format( 'd' ),
			);
		}
		$identity = false;
		$segments = array();
		foreach ( explode( '/', trim( $path, '/' ) ) as $raw ) {
			$normalized = self::slug( rawurldecode( $raw ) );
			$matches    = array_keys( array_filter( $tokens, static fn( $value, $key ) => ( 'slug' === $key ? $normalized : rawurldecode( $raw ) ) === $value, ARRAY_FILTER_USE_BOTH ) );
			if ( 1 < count( $matches ) ) {
				return null;
			}
			if ( $matches ) {
				$key        = $matches[0];
				$segments[] = ':' . $key;
				$identity   = $identity || in_array( $key, array( 'public_id', 'slug' ), true );
				unset( $tokens[ $key ] );
			} elseif ( null === $normalized || ( ! $local && ctype_digit( rawurldecode( $raw ) ) ) ) {
				return null;
			} else {
				$segments[] = $normalized;
			}
		}
		return $identity ? '/' . implode( '/', $segments ) : null;
	}

	/** Candidate occurrence counts in source order.
	 *
	 * @param array $votes Candidate occurrence counts in source order.
	 */
	private static function choose( array $votes ): ?string {
		if ( ! $votes ) {
			return null;
		}
		$best     = array_search( max( $votes ), $votes, true );
		$required = str_contains( $best, ':public_id' ) ? 1 : max( 2, (int) ceil( array_sum( $votes ) * 0.6 ) );
		return $votes[ $best ] >= $required ? $best : null;
	}

	/** Page ID whose exported ancestors are followed.
	 *
	 * @param int $id Page ID whose exported ancestors are followed.
	 */
	private function lineage( int $id ): ?array {
		$seen = array();
		$path = array();
		while ( isset( $this->pages[ $id ] ) ) {
			if ( isset( $seen[ $id ] ) ) {
				return null;
			}
			$seen[ $id ] = true;
			$page        = $this->pages[ $id ];
			array_unshift( $path, $page['slug'] );
			$id = $page['parent'];
			$this->budget->check();
		}
		return $path;
	}

	/** Resolve only after the whole eligible input has contributed to inference. */
	private function finish(): void {
		if ( $this->finished || ! $this->available ) {
			return;
		}
		$this->finished = true;
		$pattern        = self::choose( $this->votes ) ?? '/posts/:slug/';
		foreach ( $this->posts as $id => $post ) {
			$date              = self::date( $post['gmt'] )->setTimezone( wp_timezone() );
			$path              = strtr(
				$pattern,
				array(
					':slug'      => $post['slug'],
					':public_id' => (string) $id,
					':year'      => $date->format( 'Y' ),
					':month'     => $date->format( 'm' ),
					':day'       => $date->format( 'd' ),
				)
			);
			$this->urls[ $id ] = $this->route( $path );
		}
		$votes    = array();
		$siblings = array();
		foreach ( $this->pages as $id => $page ) {
			$this->budget->check();
			$lineage = $this->lineage( $id );
			if ( null === $lineage ) {
				$this->unavailable();
				return;
			}
			// WordPress normally guarantees distinct sibling slugs. Do not guess
			// a path if source filters or normalization break that guarantee.
			$parent = isset( $this->pages[ $page['parent'] ] ) ? $page['parent'] : 0;
			$key    = $parent . ':' . $page['slug'];
			if ( isset( $siblings[ $key ] ) ) {
				$this->unavailable();
				return;
			}
			$siblings[ $key ] = true;
			if ( null === $page['path'] ) {
				continue;
			}
			$segments = array_map( self::slug( ... ), explode( '/', trim( $page['path'], '/' ) ) );
			if ( in_array( null, $segments, true ) ) {
				continue;
			}
			$match = null;
			$last  = count( $segments ) - count( $lineage );
			for ( $start = 0; $start <= $last; ++$start ) {
				if ( array_slice( $segments, $start, count( $lineage ) ) === $lineage ) {
					$match = $start;
				}
			}
			if ( null !== $match ) {
				array_splice( $segments, $match, count( $lineage ), array( ':slug' ) );
				$candidate           = '/' . implode( '/', $segments );
				$votes[ $candidate ] = ( $votes[ $candidate ] ?? 0 ) + 1;
			}
		}
		$pattern = self::choose( $votes ) ?? '/:slug/';
		foreach ( $this->pages as $id => $page ) {
			if ( isset( $this->wanted[ $id ] ) ) {
				$path = $this->route( str_replace( ':slug', implode( '/', $this->lineage( $id ) ), $pattern ) );
				if ( $this->html_style() && str_ends_with( $path, '/index' ) ) {
					$path = substr( $path, 0, -5 );
				}
				$this->urls[ $id ] = $path;
			}
		}
		$this->posts = array();
		$this->pages = array();
		$this->votes = array();
		$this->slugs = array();
	}

	/** Directory is the importer default when no eligible path votes exist. */
	private function html_style(): bool {
		return $this->extension > 0 && $this->extension >= $this->directory;
	}

	/** Importer route before output-style normalization.
	 *
	 * @param string $path Importer route before output-style normalization.
	 */
	private function route( string $path ): string {
		$body = trim( $path, '/' );
		return '' === $body ? '/' : '/' . $body . ( $this->html_style() ? '' : '/' );
	}

	/**
	 * Return a destination, or null rather than presenting an uncertain source URL.
	 *
	 * @param object        $entry Menu item from the same source snapshot.
	 * @param \WP_Term|null $term Exported term (or guarded settings-preview lookup).
	 */
	public function url( object $entry, ?\WP_Term $term = null ): ?string {
		$this->budget->check();
		if ( 'custom' === $entry->type ) {
			$url   = self::site_link( $entry->url, $this->public_url, $this->source );
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) || str_starts_with( $url, '//' ) ) {
				return null;
			}
			$path = $this->source_path( $url, true );
			if ( null !== $path ) {
				return $this->public_url . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
			}
			if ( str_starts_with( $url, '#' ) ) {
				$url = '/' . $url;
			}
			return str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ? $this->public_url . $url : $url;
		}
		$this->finish();
		if ( ! $this->available ) {
			return null;
		}
		if ( 'post_type' === $entry->type ) {
			return isset( $this->urls[ $entry->object_id ] ) ? $this->public_url . $this->urls[ $entry->object_id ] : null;
		}
		if ( 'taxonomy' === $entry->type && $term && in_array( $entry->object, array( 'category', 'post_tag' ), true ) ) {
			$slug = self::slug( $term->slug );
			return null === $slug ? null : $this->public_url . $this->route( ( 'category' === $entry->object ? '/categories/' : '/tags/' ) . $slug );
		}
		return null;
	}

	/** Additional bounded display state.
	 *
	 * @param int $bytes Additional bounded display state.
	 */
	private function retain( int $bytes ): void {
		$this->bytes += $bytes;
		if ( $this->bytes > self::MAX_BYTES ) {
			$this->unavailable();
		} else {
			$this->budget->check( $bytes );
		}
	}

	/** Optional projection never imposes a new export limit. */
	private function unavailable(): void {
		$this->available = false;
		$this->posts     = array();
		$this->pages     = array();
		$this->votes     = array();
		$this->slugs     = array();
		$this->urls      = array();
	}
}
