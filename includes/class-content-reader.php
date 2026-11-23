<?php
/** Read complete export rows only after admitting their byte sizes.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Query-local selection plus guarded, byte-batched payload reads. */
final class Content_Reader {

	const BATCH_BYTES = 2097152;
	const COLUMNS     = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' );

	/**
	 * Keep the caller's original deadline and memory guard.
	 *
	 * @param Resource_Budget $budget Existing request budget.
	 */
	public function __construct( private Resource_Budget $budget ) {}

	/**
	 * Preserve selection filters; refuse providers that bypass bounded SQL.
	 *
	 * @param int $page Candidate window number.
	 * @throws Failure If selection bypasses bounded SQL.
	 */
	private function select( int $page ): array {
		global $wpdb;
		$query         = new \WP_Query();
		$short_circuit = static function ( $posts, $candidate ) use ( $query ) {
			if ( $candidate === $query && null !== $posts ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
			return $posts;
		};
		$request       = static function ( $sql, $candidate ) use ( $query, $wpdb ) {
			if ( $candidate === $query && ( 'ids' !== $query->get( 'fields' ) || ! preg_match( '/^\s*SELECT\s+(?:DISTINCT\s+)?' . preg_quote( $wpdb->posts, '/' ) . '\.ID\s+FROM\s/i', $sql ) || ! preg_match( '/\bLIMIT\s+\d+\s*,\s*100\s*$/i', $sql ) ) ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
			return $sql;
		};
		add_filter( 'posts_pre_query', $short_circuit, PHP_INT_MAX, 2 );
		add_filter( 'posts_request', $request, PHP_INT_MAX, 2 );
		try {
			$ids = $query->query(
				array(
					'post_type'              => array( 'post', 'page' ),
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'suppress_filters'       => false,
					'cache_results'          => false,
					'lazy_load_term_meta'    => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'fields'                 => 'ids',
				)
			);
			if ( count( $ids ) > 100 || array_filter( $ids, static fn( $id ) => ! is_int( $id ) || $id <= 0 ) || count( array_unique( $ids ) ) !== count( $ids ) ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
			return array( $ids, $query );
		} finally {
			remove_filter( 'posts_pre_query', $short_circuit, PHP_INT_MAX );
			remove_filter( 'posts_request', $request, PHP_INT_MAX );
		}
	}

	/**
	 * Guard every variable column in the same SELECT that returns its payload.
	 *
	 * @throws Failure If content grows or reads cannot remain bounded.
	 */
	public function pages(): \Generator {
		global $wpdb;
		// Convert explicitly to the UTF-8 representation consumed by the exporter.
		$size      = static fn( $column ) => 'OCTET_LENGTH(CONVERT(' . $column . ' USING utf8mb4))';
		$row_size  = implode( '+', array_map( $size, self::COLUMNS ) );
		$body_size = $size( 'post_content' );
		$eligible  = "(post_type IN ('post','page') AND post_status='publish' AND post_password='')";
		$page      = 1;
		do {
			$this->budget->check();
			list( $ids, $query ) = $this->select( $page++ );
			if ( ! $ids ) {
				break;
			}
			$inventory = $wpdb->get_results( 'SELECT ID,' . $body_size . ' AS body_bytes,(' . $row_size . ') AS row_bytes,' . $eligible . ' AS eligible FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', $ids ) . ')', OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- IDs are validated integers; identifiers/expressions are fixed.
			if ( $wpdb->last_error || count( $inventory ) !== count( $ids ) ) {
				throw new Failure( 'SOURCE_READ_FAILED', 503 );
			}
			$batches = array();
			$batch   = array();
			$bytes   = 0;
			foreach ( $ids as $id ) {
				$entry = $inventory[ $id ];
				if ( ! (int) $entry->eligible ) {
					continue;
				}
				Content_Body::check( (int) $entry->body_bytes, $id );
				$cost = (int) $entry->row_bytes + 512;
				if ( $batch && $bytes + $cost > self::BATCH_BYTES ) {
					$batches[] = $batch;
					$batch     = array();
					$bytes     = 0;
				}
				$batch[] = $id;
				$bytes  += $cost;
			}
			if ( $batch ) {
				$batches[] = $batch;
			}
			while ( $batches ) {
				$batch = array_shift( $batches );
				$bytes = array_sum( array_map( static fn( $id ) => (int) $inventory[ $id ]->row_bytes + 512, $batch ) );
				try {
					$this->budget->check( $bytes );
				} catch ( Failure $error ) {
					if ( 'MEMORY_LIMIT_EXCEEDED' !== $error->reason || count( $batch ) < 2 ) {
						throw $error;
					}
					array_unshift( $batches, ...array_chunk( $batch, (int) ceil( count( $batch ) / 2 ) ) );
					continue;
				}
				$bounds = 'CASE ID ' . implode( ' ', array_map( static fn( $id ) => 'WHEN ' . $id . ' THEN ' . (int) $inventory[ $id ]->row_bytes, $batch ) ) . ' END';
				$guard  = $eligible . ' AND ' . $body_size . '<=' . Content_Body::LIMIT_BYTES . ' AND (' . $row_size . ')<=(' . $bounds . ')';
				$fields = array_map( static fn( $column ) => 'CASE WHEN admitted.ID IS NOT NULL THEN p.' . $column . ' ELSE NULL END AS ' . $column, self::COLUMNS );
				$rows   = $wpdb->get_results( 'SELECT p.ID,' . $body_size . ' AS zpsp_body_bytes,' . $eligible . ' AS zpsp_eligible,' . implode( ',', $fields ) . ' FROM ' . $wpdb->posts . ' p LEFT JOIN (SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', $batch ) . ') AND ' . $guard . ' LIMIT 100) admitted ON admitted.ID=p.ID WHERE p.ID IN (' . implode( ',', $batch ) . ')', OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed projections and validated numeric per-row limits.
				if ( $wpdb->last_error || count( $rows ) !== count( $batch ) ) {
					throw new Failure( 'SOURCE_READ_FAILED', 503 );
				}
				$posts = array();
				foreach ( $batch as $id ) {
					$row = $rows[ $id ];
					if ( (int) $row->zpsp_eligible ) {
						Content_Body::check( (int) $row->zpsp_body_bytes, $id );
					}
					if ( null === $row->post_content ) {
						throw new Failure( 'CONTENT_CHANGED', 409 );
					}
					unset( $row->zpsp_body_bytes, $row->zpsp_eligible );
					$posts[] = new \WP_Post( sanitize_post( $row, 'raw' ) );
				}
				$query->query_vars['fields'] = '';
				$query->posts                = $posts;
				$query->post_count           = count( $posts );
				$posts                       = apply_filters_ref_array( 'posts_results', array( $posts, &$query ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve the core post-results filter after bounded row hydration.
				$posts                       = apply_filters_ref_array( 'the_posts', array( $posts, &$query ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve the core final post filter for existing plugin callbacks.
				$seen                        = array();
				if ( ! is_array( $posts ) || count( $posts ) > count( $batch ) ) {
					throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
				}
				foreach ( $posts as $post ) {
					if ( ! $post instanceof \WP_Post || ! in_array( (int) $post->ID, $batch, true ) || isset( $seen[ $post->ID ] ) ) {
						throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
					}
					$seen[ $post->ID ] = true;
				}
				yield $posts;
				$query->posts = array();
				unset( $rows, $posts, $row, $post );
			}
			$more = 100 === count( $ids );
		} while ( $more );
	}
}
