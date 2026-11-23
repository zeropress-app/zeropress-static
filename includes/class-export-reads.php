<?php
/** Bounded, generation-local reads without whole-object metadata cache loading.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

// All identifiers/projections below are internal; SQL payloads are admitted separately.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

/** Supplies existing WordPress helpers with admitted data, not partial shared caches. */
final class Export_Reads {
	private const DOCUMENT_META = array( '_thumbnail_id', '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_genesis_description' );
	private const MEDIA_META    = array( '_wp_attached_file', '_wp_attachment_image_alt', '_wp_attachment_metadata' );
	private const MEDIA_COLUMNS = array( 'post_type', 'post_status', 'post_password', 'post_title', 'post_modified_gmt', 'guid' );
	/**
	 * Active scope for nested core helpers.
	 *
	 * @var self|null
	 */
	private static ?self $active = null;
	/**
	 * Private raw values by metadata type, object and key.
	 *
	 * @var array
	 */
	private array $metadata = array();
	/**
	 * Private ordered term IDs for the current batch.
	 *
	 * @var array
	 */
	private array $relationships = array();
	/**
	 * Scope-owned filter callbacks, removed even on failure.
	 *
	 * @var array
	 */
	private array $hooks = array();

	/**
	 * Share the unchanged request memory and time budget.
	 *
	 * @param Resource_Budget $budget Shared request budget.
	 */
	public function __construct( private Resource_Budget $budget ) {}

	/**
	 * Enter a request-local scope; nested site metadata calls share its deadline.
	 *
	 * @param callable             $callback Operation within the read scope.
	 * @param Resource_Budget|null $budget Shared request budget.
	 * @throws Failure If admission or source consistency fails.
	 */
	public static function scope( callable $callback, ?Resource_Budget $budget = null ): mixed {
		if ( self::$active ) {
			return $callback( self::$active );
		}
		$reader       = new self( $budget ?? Resource_Budget::from_request() );
		self::$active = $reader;
		foreach ( array( 'post', 'user', 'term' ) as $type ) {
			$hook = static fn( $check, $id, $key, $single ) => $reader->meta( $check, $type, (int) $id, $key, (bool) $single );
			$reader->hooks[ 'get_' . $type . '_metadata' ] = $hook;
			add_filter( 'get_' . $type . '_metadata', $hook, PHP_INT_MAX, 4 );
		}
		add_filter( 'terms_pre_query', array( $reader, 'term_query' ), PHP_INT_MAX, 2 );
		try {
			return $callback( $reader );
		} finally {
			foreach ( $reader->hooks as $name => $hook ) {
				remove_filter( $name, $hook, PHP_INT_MAX );
			}
			remove_filter( 'terms_pre_query', array( $reader, 'term_query' ), PHP_INT_MAX );
			self::$active = null;
			$reader->clear();
		}
	}

	/**
	 * Release private batch maps and the last DB result.
	 */
	public function clear(): void {
		$this->metadata      = array();
		$this->relationships = array();
		global $wpdb;
		$wpdb->flush();
	}

	/**
	 * Only fixed numeric identifiers may enter dynamically built IN lists.
	 *
	 * @param array $ids Positive source object IDs.
	 */
	private function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );
	}

	/**
	 * Execute an admitted query and reject database failures.
	 *
	 * @param string $sql Internal numeric selection SQL.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function rows( string $sql ): array {
		global $wpdb;
		$this->budget->check();
		$rows = $wpdb->get_results( $sql );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new Failure( 'SOURCE_READ_FAILED', 503 );
		}
		return $rows;
	}

	/**
	 * Measure the connection UTF-8 byte representation.
	 *
	 * @param string $column Internal column identifier.
	 */
	private static function bytes( string $column ): string {
		return 'COALESCE(OCTET_LENGTH(CONVERT(' . $column . ' USING utf8mb4)),0)';
	}

	/**
	 * Fetch only complete admitted rows (or explicitly private projections).
	 *
	 * @param string $table Internal table or guarded derived table.
	 * @param string $primary Internal primary-key column.
	 * @param array  $ids Positive source object IDs.
	 * @param array  $columns Complete admitted columns or private projection.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function records( string $table, string $primary, array $ids, array $columns ): array {
		$result = array();
		foreach ( array_chunk( $this->ids( $ids ), 100 ) as $window ) {
			$size      = implode( '+', array_map( self::bytes( ... ), $columns ) );
			$inventory = $this->rows( "SELECT $primary AS zpsp_id,($size) AS zpsp_bytes FROM $table WHERE $primary IN (" . implode( ',', $window ) . ')' );
			foreach ( $this->windows( $inventory, 'zpsp_bytes' ) as $batch ) {
				$bounds = array();
				foreach ( $batch as $entry ) {
					$bounds[ (int) $entry->zpsp_id ] = (int) $entry->zpsp_bytes;
				}
				$limit  = 'CASE ' . $primary . ' ' . implode( ' ', array_map( static fn( $id, $bytes ) => "WHEN $id THEN $bytes", array_keys( $bounds ), $bounds ) ) . ' END';
				$guard  = "($size)<=($limit)";
				$fields = implode( ',', array_map( static fn( $column ) => "CASE WHEN $guard THEN $column ELSE NULL END AS $column", $columns ) );
				$rows   = $this->rows( "SELECT $primary,($guard) AS zpsp_admitted,$fields FROM $table WHERE $primary IN (" . implode( ',', array_keys( $bounds ) ) . ') /* zpsp_aux_payload */' );
				if ( count( $rows ) !== count( $bounds ) ) {
					throw new Failure( 'CONTENT_CHANGED', 409 );
				}
				foreach ( $rows as $row ) {
					if ( ! $row->zpsp_admitted ) {
						throw new Failure( 'CONTENT_CHANGED', 409 );
					}
					unset( $row->zpsp_admitted );
					$result[ (int) $row->$primary ] = $row;
				}
			}
		}
		return $result;
	}

	/**
	 * Bound both batch bytes and row/map overhead; a singleton is never truncated.
	 *
	 * @param array  $inventory Bounded numeric size descriptors.
	 * @param string $field Descriptor byte-cost field.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function windows( array $inventory, string $field ): \Generator {
		$batch = array();
		$cost  = 0;
		foreach ( $inventory as $entry ) {
			$next = (int) $entry->$field + 512;
			if ( $batch && ( $cost + $next > Content_Reader::BATCH_BYTES || count( $batch ) >= 100 ) ) {
				yield from $this->admit_window( $batch, $field );
				$batch = array();
				$cost  = 0;
			}
			$batch[] = $entry;
			$cost   += $next;
		}
		if ( $batch ) {
			yield from $this->admit_window( $batch, $field );
		}
	}

	/**
	 * Split an aggregate that no longer fits live headroom; never drop a singleton.
	 *
	 * @param array  $batch Candidate descriptor window.
	 * @param string $field Descriptor byte-cost field.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function admit_window( array $batch, string $field ): \Generator {
		$cost = array_sum( array_map( static fn( $entry ) => (int) $entry->$field + 512, $batch ) );
		try {
			$this->budget->check( $cost );
		} catch ( Failure $error ) {
			if ( 'MEMORY_LIMIT_EXCEEDED' !== $error->reason || count( $batch ) < 2 ) {
				throw $error;
			}
			foreach ( array_chunk( $batch, (int) ceil( count( $batch ) / 2 ) ) as $half ) {
				yield from $this->admit_window( $half, $field );
			}
			return;
		}
		yield $batch;
	}

	/**
	 * Resolve fixed internal metadata identifiers.
	 *
	 * @param string $type WordPress metadata type.
	 */
	private function meta_table( string $type ): array {
		global $wpdb;
		return array( $wpdb->{$type . 'meta'}, $type . '_id', 'user' === $type ? 'umeta_id' : 'meta_id' );
	}

	/**
	 * Inventory the first exact-case key only, not every key/value on the object.
	 *
	 * @param string $type WordPress metadata type.
	 * @param array  $ids Positive source object IDs.
	 * @param array  $keys Exact metadata keys.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function meta_inventory( string $type, array $ids, array $keys ): array {
		global $wpdb;
		if ( ! $ids || ! $keys ) {
			return array();
		}
		list( $table, $object, $primary ) = $this->meta_table( $type );
		$quoted                           = implode( ',', array_map( static fn( $key ) => $wpdb->prepare( '%s', $key ), $keys ) );
		$where                            = "$object IN (" . implode( ',', $ids ) . ") AND BINARY meta_key IN ($quoted)";
		$first                            = "SELECT MIN($primary) AS first_id FROM $table FORCE INDEX ($object) WHERE $where GROUP BY $object,BINARY meta_key";
		$size                             = self::bytes( 'm.meta_value' );
		$maximum                          = count( $ids ) * count( $keys );
		$this->budget->check( $maximum * 512 );
		$rows = $this->rows( "SELECT m.$primary AS zpsp_id,m.$object AS object_id,m.meta_key,($size) AS zpsp_bytes FROM ($first) chosen STRAIGHT_JOIN $table m ON chosen.first_id=m.$primary ORDER BY m.$primary LIMIT " . ( $maximum + 1 ) );
		if ( count( $rows ) > $maximum ) {
			throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
		}
		return $rows;
	}

	/**
	 * Load admitted metadata without hydrating concurrently enlarged values.
	 *
	 * @param string $type WordPress metadata type.
	 * @param array  $inventory Bounded numeric size descriptors.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function meta_payload( string $type, array $inventory ): array {
		global $wpdb;
		list( $table, $object, $primary ) = $this->meta_table( $type );
		$result                           = array();
		foreach ( $this->windows( $inventory, 'zpsp_bytes' ) as $batch ) {
			$conditions = array();
			foreach ( $batch as $entry ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed internal column identifiers are interpolated; all values are placeholders.
				$conditions[] = $wpdb->prepare( "WHEN $primary=%d THEN ($object=%d AND BINARY meta_key=%s AND " . self::bytes( 'meta_value' ) . '<=%d)', $entry->zpsp_id, $entry->object_id, $entry->meta_key, $entry->zpsp_bytes );
			}
			$guard = 'CASE ' . implode( ' ', $conditions ) . ' ELSE 0 END';
			$rows  = $this->rows( "SELECT $primary AS zpsp_id,($guard) AS zpsp_admitted,CASE WHEN ($guard) THEN meta_value ELSE NULL END AS meta_value FROM $table WHERE $primary IN (" . implode( ',', array_map( static fn( $row ) => (int) $row->zpsp_id, $batch ) ) . ') /* zpsp_meta_payload */' );
			if ( count( $rows ) !== count( $batch ) ) {
				throw new Failure( 'CONTENT_CHANGED', 409 );
			}
			foreach ( $rows as $row ) {
				if ( ! $row->zpsp_admitted ) {
					throw new Failure( 'CONTENT_CHANGED', 409 );
				}
				$result[ (int) $row->zpsp_id ] = $row->meta_value;
			}
		}
		return $result;
	}

	/**
	 * Preserve first-value and missing-key semantics in a private map.
	 *
	 * @param string $type WordPress metadata type.
	 * @param array  $ids Positive source object IDs.
	 * @param array  $keys Exact metadata keys.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function preload_meta( string $type, array $ids, array $keys ): void {
		foreach ( array_chunk( $this->ids( $ids ), 100 ) as $window ) {
			$inventory = $this->meta_inventory( $type, $window, $keys );
			$values    = $this->meta_payload( $type, $inventory );
			// Detect new/deleted/replaced first rows, including a formerly absent key.
			$identity = static fn( $rows ) => array_map( static fn( $row ) => array( $row->zpsp_id, $row->object_id, $row->meta_key ), $rows );
			if ( $identity( $inventory ) !== $identity( $this->meta_inventory( $type, $window, $keys ) ) ) {
				throw new Failure( 'CONTENT_CHANGED', 409 );
			}
			foreach ( $window as $id ) {
				foreach ( $keys as $key ) {
					$this->metadata[ $type ][ $id ][ $key ] = array();
				}
			}
			foreach ( $inventory as $entry ) {
				$this->metadata[ $type ][ (int) $entry->object_id ][ $entry->meta_key ] = array( $values[ (int) $entry->zpsp_id ] );
			}
		}
	}

	/**
	 * Complete/multi-value compatibility read; never seed the shared meta cache.
	 *
	 * @param string $type WordPress metadata type.
	 * @param int    $id Source object ID.
	 * @param string $key Exact metadata key, or empty for all keys.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function all_meta( string $type, int $id, string $key ): array {
		global $wpdb;
		list( $table, $object, $primary ) = $this->meta_table( $type );
		$where                            = $wpdb->prepare( '%i=%d', $object, $id );
		if ( '' !== $key ) {
			$where .= $wpdb->prepare( ' AND BINARY meta_key=%s', $key );
		}
		$size  = self::bytes( 'meta_value' ) . '+' . self::bytes( 'meta_key' );
		$count = $this->rows( "SELECT COUNT(*) AS n,COALESCE(SUM($size),0) AS bytes FROM $table WHERE $where" )[0];
		$this->budget->check( (int) $count->bytes + (int) $count->n * 512 );
		$inventory = $this->rows( "SELECT $primary AS zpsp_id,$object AS object_id,meta_key,($size) AS zpsp_bytes FROM $table WHERE $where ORDER BY $primary LIMIT " . ( (int) $count->n + 1 ) );
		if ( count( $inventory ) !== (int) $count->n ) {
			throw new Failure( 'CONTENT_CHANGED', 409 );
		}
		$values = $this->meta_payload( $type, $inventory );
		$after  = $this->rows( "SELECT $primary AS zpsp_id FROM $table WHERE $where ORDER BY $primary LIMIT " . ( (int) $count->n + 1 ) );
		if ( array_map( static fn( $r ) => $r->zpsp_id, $inventory ) !== array_map( static fn( $r ) => $r->zpsp_id, $after ) ) {
			throw new Failure( 'CONTENT_CHANGED', 409 );
		}
		$result = array();
		foreach ( $inventory as $row ) {
			$result[ $row->meta_key ][] = $values[ (int) $row->zpsp_id ];
		}
		return $result;
	}

	/**
	 * A final metadata filter supplies the ordinary storage fallback only.
	 *
	 * @param mixed  $check Earlier metadata filter result.
	 * @param string $type WordPress metadata type.
	 * @param int    $id Source object ID.
	 * @param string $key Exact metadata key, or empty for all keys.
	 * @param bool   $single Whether one value is requested.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function meta( mixed $check, string $type, int $id, string $key, bool $single ): mixed {
		$this->budget->check();
		if ( null !== $check ) {
			return $check;
		}
		// Core uses this same filter for metadata_exists(), whose return contract
		// differs from get_metadata_raw(). Inspect only bounded frames, never arguments.
		$exists = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Core shares a filter with two return contracts; inspect six frames without argument data.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ) as $frame ) {
			if ( 'metadata_exists' === ( $frame['function'] ?? '' ) && ! isset( $frame['class'] ) ) {
				$exists = true;
				break;
			}
		}
		if ( '' === $key ) {
			$all = $this->all_meta( $type, $id, '' );
			if ( $exists ) {
				return isset( $all[''] );
			}
			return $single ? array( $all ) : $all;
		}
		if ( ! $single ) {
			$rows = $this->all_meta( $type, $id, $key )[ $key ] ?? array();
		} else {
			if ( ! isset( $this->metadata[ $type ][ $id ][ $key ] ) ) {
				$this->preload_meta( $type, array( $id ), array( $key ) );
			}
			$rows = $this->metadata[ $type ][ $id ][ $key ];
		}
		if ( $exists ) {
			return (bool) $rows;
		}
		if ( ! $rows ) {
			$default = get_metadata_default( $type, $id, $key, $single );
			$this->budget->check();
			return $single ? array( $default ) : $default;
		}
		return array_map( fn( $value ) => null === $value ? null : Serialized_Metadata::decode( $value, $this->budget ), $rows );
	}

	/**
	 * Byte/count-bounded metadata windows retain the source document order.
	 *
	 * @param array $posts Admitted complete WordPress posts.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function documents( array $posts ): \Generator {
		$costs = array();
		foreach ( $this->meta_inventory( 'post', array_map( static fn( $p ) => (int) $p->ID, $posts ), self::DOCUMENT_META ) as $row ) {
			$costs[ (int) $row->object_id ] = ( $costs[ (int) $row->object_id ] ?? 0 ) + (int) $row->zpsp_bytes + 512;
		}
		foreach ( $this->relationship_costs( array_map( static fn( $p ) => (int) $p->ID, $posts ) ) as $id => $bytes ) {
			$costs[ $id ] = ( $costs[ $id ] ?? 0 ) + $bytes;
		}
		$inventory = array_map(
			static fn( $p ) => (object) array(
				'post'  => $p,
				'bytes' => strlen( $p->post_content ) + strlen( $p->post_excerpt ) + ( $costs[ (int) $p->ID ] ?? 0 ),
			),
			$posts
		);
		foreach ( $this->windows( $inventory, 'bytes' ) as $batch ) {
			$this->clear();
			$items = array_map( static fn( $row ) => $row->post, $batch );
			$ids   = array_map( static fn( $p ) => (int) $p->ID, $items );
			$this->preload_meta( 'post', $ids, self::DOCUMENT_META );
			$this->prime_relationships( $ids );
			$this->prime_parents( $items );
			yield $items;
		}
	}

	/**
	 * Read only eligible attachment fields and selected metadata in source order.
	 *
	 * @param array $ids Positive source object IDs.
	 * @param bool  $details Whether featured-image metadata is needed.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function attachments( array $ids, bool $details = true ): \Generator {
		global $wpdb;
		$columns = $details ? self::MEDIA_COLUMNS : array( 'post_type', 'post_status', 'post_password', 'guid' );
		$keys    = $details ? self::MEDIA_META : array( '_wp_attached_file' );
		foreach ( array_chunk( $this->ids( $ids ), 100 ) as $window ) {
			$this->clear();
			$rows   = $this->records( $wpdb->posts, 'ID', $window, $columns );
			$rows   = array_filter( $rows, static fn( $post ) => 'attachment' === $post->post_type && in_array( $post->post_status, array( 'inherit', 'publish' ), true ) && '' === $post->post_password );
			$window = array_values( array_filter( $window, static fn( $id ) => isset( $rows[ $id ] ) ) );

			$costs = array();
			foreach ( $this->meta_inventory( 'post', $window, $keys ) as $row ) {
				$costs[ (int) $row->object_id ] = ( $costs[ (int) $row->object_id ] ?? 0 ) + (int) $row->zpsp_bytes + 512;
			}
			$inventory = array_map(
				static fn( $id ) => (object) array(
					'id'    => $id,
					'bytes' => $costs[ $id ] ?? 0,
				),
				$window
			);
			foreach ( $this->windows( $inventory, 'bytes' ) as $batch ) {
				$this->clear();
				$selected = array_map( static fn( $row ) => $row->id, $batch );
				$this->preload_meta( 'post', $selected, $keys );
				$ordered = array();
				foreach ( $selected as $id ) {
					if ( isset( $rows[ $id ] ) ) {
						$ordered[ $id ] = $rows[ $id ];
					}
				}
				yield $ordered;
			}
		}
	}

	/**
	 * Complete canonical posts are required only by core helper dependencies.
	 *
	 * @param array $ids Positive source object IDs.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function prime_posts( array $ids ): array {
		global $wpdb;
		$posts = $this->records( $wpdb->posts, 'ID', $ids, Content_Reader::COLUMNS );
		foreach ( $posts as $id => $row ) {
			$row = sanitize_post( $row, 'raw' );
			wp_cache_set( $id, $row, 'posts' );
			$posts[ $id ] = new \WP_Post( $row );
		}
		return $posts;
	}

	/**
	 * Admit complete parent rows before core permalink helpers traverse them.
	 *
	 * @param array $posts Admitted complete WordPress posts.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function prime_parents( array $posts ): void {
		$seen = array();
		while ( $posts ) {
			$ids = $this->ids( array_map( static fn( $p ) => (int) $p->post_parent, $posts ) );
			$ids = array_values( array_diff( $ids, array_keys( $seen ) ) );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$seen[ $id ] = true;
			}
			$posts = $this->prime_posts( $ids );
		}
	}

	/**
	 * Same core URL calculation, without loading an attachment's unused body.
	 *
	 * @param object $post Admitted private attachment projection.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function attachment_url( object $post ): string|false {
		global $pagenow;
		$url  = '';
		$file = get_post_meta( $post->ID, '_wp_attached_file', true );
		if ( $file ) {
			$uploads = wp_get_upload_dir();
			if ( $uploads && false === $uploads['error'] ) {
				if ( str_starts_with( $file, $uploads['basedir'] ) ) {
					$url = str_replace( $uploads['basedir'], $uploads['baseurl'], $file );
				} elseif ( str_contains( $file, 'wp-content/uploads' ) ) {
					$url = trailingslashit( $uploads['baseurl'] . '/' . _wp_get_attachment_relative_path( $file ) ) . wp_basename( $file );
				} else {
					$url = $uploads['baseurl'] . "/$file";
				}
			}
		}
		if ( ! $url ) {
			$url = apply_filters( 'get_the_guid', $post->guid, (int) $post->ID );
		}
		if ( is_ssl() && ! is_admin() && 'wp-login.php' !== $pagenow ) {
			$url = set_url_scheme( $url );
		}
		$url = apply_filters( 'wp_get_attachment_url', $url, (int) $post->ID );
		$this->budget->check( is_string( $url ) ? strlen( $url ) : 0 );
		return $url ? $url : false;
	}

	/**
	 * A numeric-only query is counted, limited, and checked before any row hydration.
	 *
	 * @param string $sql Internal numeric selection SQL.
	 * @param array  $fields Allowed numeric result columns.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function numeric_query( string $sql, array $fields ): array {
		$count = (int) $this->rows( "SELECT COUNT(*) AS n FROM ($sql) zpsp_selection" )[0]->n;
		$this->budget->check( ( $count + 1 ) * 512 );
		// Keep the original ORDER BY and offset while capping a concurrently growing set.
		$bounded = preg_replace_callback( '/\bLIMIT\s+(?:(\d+)\s*,\s*)?(\d+)\s*$/i', static fn( $m ) => 'LIMIT ' . ( '' !== $m[1] ? $m[1] . ',' : '' ) . min( (int) $m[2], $count + 1 ), $sql, -1, $replaced );
		if ( ! $replaced ) {
			$bounded .= ' LIMIT ' . ( $count + 1 );
		}
		$rows = $this->rows( $bounded );
		if ( count( $rows ) !== $count ) {
			throw new Failure( 'CONTENT_CHANGED', 409 );
		}
		foreach ( $rows as $row ) {
			if ( array_diff( array_keys( (array) $row ), $fields ) ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
		}
		return $rows;
	}

	/**
	 * Prime complete canonical term rows, never a partial shared object.
	 *
	 * @param array $ids Positive source object IDs.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function term_records( array $ids ): array {
		global $wpdb;
		// The canonical full WP_Term row is small in the native schema, but still guard it.
		$table = "(SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent,tt.count FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id) zpsp_terms";
		$rows  = $this->records( $table, 'term_id', $ids, array( 'name', 'slug', 'term_group', 'term_taxonomy_id', 'taxonomy', 'description', 'parent', 'count' ) );
		foreach ( $rows as $id => $row ) {
			wp_cache_set( $id, $row, 'terms' );
		}
		return $rows;
	}

	/**
	 * Read a guarded term through the existing core filter.
	 *
	 * @param int    $id Source object ID.
	 * @param string $taxonomy Expected taxonomy.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function term( int $id, string $taxonomy ): mixed {
		if ( false === wp_cache_get( $id, 'terms' ) ) {
			$this->term_records( array( $id ) );
		}
		return get_term( $id, $taxonomy );
	}

	/**
	 * At most one numeric descriptor per object; no term list is hydrated here.
	 *
	 * @param array $ids Positive source object IDs.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function relationship_costs( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$size = self::bytes( 't.name' ) . '+' . self::bytes( 't.slug' ) . '+' . self::bytes( 'tt.description' );
		$rows = $this->rows( "SELECT tr.object_id,SUM($size+1024) AS bytes FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN $wpdb->terms t ON tt.term_id=t.term_id WHERE tr.object_id IN (" . implode( ',', $ids ) . ") AND tt.taxonomy IN ('category','post_tag') GROUP BY tr.object_id" );
		return array_column( $rows, 'bytes', 'object_id' );
	}

	/**
	 * Load bounded relationship lists and complete terms for one batch.
	 *
	 * @param array $ids Positive source object IDs.
	 * @throws Failure If admission or source consistency fails.
	 */
	private function prime_relationships( array $ids ): void {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$sql       = "SELECT tr.object_id,tt.term_id FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN $wpdb->terms t ON tt.term_id=t.term_id WHERE tr.object_id IN (" . implode( ',', $ids ) . ") AND tt.taxonomy IN ('category','post_tag') ORDER BY t.name ASC";
		$relations = $this->numeric_query( $sql, array( 'object_id', 'term_id' ) );
		$terms     = $this->term_records( array_map( static fn( $r ) => (int) $r->term_id, $relations ) );
		foreach ( $ids as $id ) {
			$this->relationships[ $id ] = array(
				'category' => array(),
				'post_tag' => array(),
			);
		}
		foreach ( $relations as $row ) {
			$term = $terms[ (int) $row->term_id ] ?? null;
			if ( ! $term || ! isset( $this->relationships[ (int) $row->object_id ][ $term->taxonomy ] ) ) {
				throw new Failure( 'CONTENT_CHANGED', 409 );
			}
			$this->relationships[ (int) $row->object_id ][ $term->taxonomy ][] = (int) $row->term_id;
		}
		// Equal term names have no defined DB tie order. Compare membership, not
		// incidental sort order; the original first selection still drives output.
		$identity = static function ( $rows ) {
			$pairs = array_map( static fn( $row ) => $row->object_id . ':' . $row->term_id, $rows );
			sort( $pairs, SORT_STRING );
			return $pairs;
		};
		if ( $identity( $relations ) !== $identity( $this->numeric_query( $sql, array( 'object_id', 'term_id' ) ) ) ) {
			throw new Failure( 'CONTENT_CHANGED', 409 );
		}
		$seen = array_fill_keys( array_keys( $terms ), true );
		while ( $terms ) {
			$parents = array_values( array_diff( $this->ids( array_map( static fn( $t ) => (int) $t->parent, $terms ) ), array_keys( $seen ) ) );
			if ( ! $parents ) {
				break;
			}
			$terms = $this->term_records( $parents );
			foreach ( $parents as $id ) {
				$seen[ $id ] = true;
			}
		}
	}

	/**
	 * Keep core's original per-object filters while using batch data for ordinary queries.
	 *
	 * @param mixed          $pre Earlier term query short-circuit result.
	 * @param \WP_Term_Query $query Original WordPress term query.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function term_query( mixed $pre, \WP_Term_Query $query ): mixed {
		$this->budget->check();
		if ( null !== $pre || 'count' === $query->query_vars['fields'] ) {
			return $pre;
		}
		$args = $query->query_vars;
		$sql  = $query->request;
		if ( ! preg_match( '/^SELECT\s+(?:DISTINCT\s+)?t\.term_id(?:\s*,\s*tr\.object_id)?\s+FROM\s/i', $sql ) || $args['child_of'] || $args['pad_counts'] || ( $args['hierarchical'] && $args['hide_empty'] ) ) {
			throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
		}
		$objects    = $this->ids( (array) $args['object_ids'] );
		$taxonomies = (array) $args['taxonomy'];
		$native     = 1 === count( $objects ) && 1 === count( $taxonomies ) && isset( $this->relationships[ $objects[0] ][ $taxonomies[0] ] ) && 'name' === $args['orderby'] && 'ASC' === strtoupper( $args['order'] ) && ! $args['number'] && ! $args['offset'] && ! $args['include'] && ! $args['exclude'] && ! $args['exclude_tree'] && ! $args['search'] && ! $args['name'] && ! $args['slug'] && ! $args['parent'] && empty( $args['term_taxonomy_id'] ) && empty( $args['meta_query'] ) && empty( $args['meta_key'] );
		foreach ( array( 'parse_term_query', 'pre_get_terms', 'get_terms_args', 'get_terms_orderby', 'terms_clauses', 'get_terms_fields', 'list_terms_exclusions', 'get_terms_defaults' ) as $hook ) {
			$native = $native && ! has_filter( $hook );
		}
		// Core normalizes number/offset to 0 and empty name/slug/taxonomy IDs to arrays.
		// Their effective empty values are already checked above.
		foreach ( $query->query_var_defaults as $name => $default ) {
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Core normalizes equivalent numeric strings and integers in query arguments.
			if ( ! in_array( $name, array( 'taxonomy', 'object_ids', 'fields', 'hierarchical', 'hide_empty', 'cache_results', 'update_term_meta_cache', 'number', 'offset', 'name', 'slug', 'term_taxonomy_id' ), true ) && ( $args[ $name ] ?? $default ) != $default ) {
				$native = false;
			}
		}
		if ( $native ) {
			$selected = array_map(
				static fn( $id ) => (object) array(
					'term_id'   => $id,
					'object_id' => $objects[0],
				),
				$this->relationships[ $objects[0] ][ $taxonomies[0] ]
			);
		} else {
			$selected = $this->numeric_query( $sql, array( 'term_id', 'object_id' ) );
			$this->term_records( array_map( static fn( $r ) => (int) $r->term_id, $selected ) );
		}
		$terms = array();
		foreach ( $selected as $row ) {
			$term = get_term( (int) $row->term_id );
			if ( ! $term instanceof \WP_Term ) {
				throw new Failure( 'CONTENT_CHANGED', 409 );
			}
			if ( 'all_with_object_id' === $args['fields'] && isset( $row->object_id ) ) {
				$term            = clone $term;
				$term->object_id = (int) $row->object_id;
			}
			$terms[] = $term;
		}
		$this->budget->check();
		switch ( $args['fields'] ) {
			case 'all':
			case 'all_with_object_id':
				return $terms;
			case 'ids':
				return array_map( static fn( $t ) => (int) $t->term_id, $terms );
			case 'names':
				return wp_list_pluck( $terms, 'name' );
			case 'slugs':
				return wp_list_pluck( $terms, 'slug' );
			case 'id=>parent':
				return wp_list_pluck( $terms, 'parent', 'term_id' );
			case 'id=>name':
				return wp_list_pluck( $terms, 'name', 'term_id' );
			case 'id=>slug':
				return wp_list_pluck( $terms, 'slug', 'term_id' );
			default:
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
		}
	}

	/**
	 * Guard menu selection/rows before wp_setup_nav_menu_item invokes core helpers.
	 *
	 * @param object $menu Selected menu term.
	 * @throws Failure If admission or source consistency fails.
	 */
	public function menu_items( object $menu ): array|false {
		global $wpdb;
		$callback = function ( $pre, $query ) use ( $wpdb ) {
			if ( 'nav_menu_item' !== $query->get( 'post_type' ) ) {
				return $pre;
			}
			if ( null !== $pre ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
			$sql = preg_replace( '/^SELECT\s+(?:DISTINCT\s+)?' . preg_quote( $wpdb->posts, '/' ) . '\.\*\s+FROM\s/i', "SELECT $wpdb->posts.ID FROM ", $query->request, 1, $replaced );
			if ( ! $replaced ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
			$ids   = array_map( static fn( $r ) => (int) $r->ID, $this->numeric_query( $sql, array( 'ID' ) ) );
			$posts = $this->prime_posts( $ids );
			$this->preload_meta( 'post', $ids, array( '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_type', '_menu_item_target', '_menu_item_url', '_menu_item_classes', '_menu_item_xfn' ) );
			$targets = array();
			foreach ( $ids as $id ) {
				if ( 'post_type' === get_post_meta( $id, '_menu_item_type', true ) ) {
					$targets[] = (int) get_post_meta( $id, '_menu_item_object_id', true );
				}
			}
			$target_posts = $this->prime_posts( $targets );
			$this->prime_relationships( array_keys( $target_posts ) );
			$this->prime_parents( $target_posts );
			$after_ids = array_map( static fn( $r ) => (int) $r->ID, $this->numeric_query( $sql, array( 'ID' ) ) );
			if ( count( $ids ) !== count( $posts ) || $ids !== $after_ids ) {
				throw new Failure( 'CONTENT_CHANGED', 409 );
			}
			return array_map( static fn( $id ) => $posts[ $id ], $ids );
		};
		add_filter( 'posts_pre_query', $callback, PHP_INT_MAX, 2 );
		try {
			return wp_get_nav_menu_items(
				$menu,
				array(
					'post_status'            => 'publish',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'update_menu_item_cache' => false,
				)
			);
		} finally {
			remove_filter( 'posts_pre_query', $callback, PHP_INT_MAX );
		}
	}
}
