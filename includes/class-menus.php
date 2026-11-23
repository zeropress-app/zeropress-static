<?php
/**
 * Explicit mappings from classic WordPress menus to ZeroPress menu IDs.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Validates selections independently of the active WordPress theme. */
final class Menus {
	/**
	 * Normalize output IDs and WordPress term IDs in a stable order.
	 *
	 * @param mixed $mapping Submitted mapping.
	 * @throws Failure If the mapping is malformed.
	 */
	public static function normalize( mixed $mapping ): array {
		if ( $mapping instanceof \stdClass ) {
			$mapping = (array) $mapping;
		}
		if ( ! is_array( $mapping ) || count( $mapping ) > 50 ) {
			throw new Failure( 'INVALID_MENU_MAPPING' );
		}
		foreach ( $mapping as $id => $term_id ) {
			if ( ! is_string( $id ) || ! preg_match( '/\A[a-z][a-z0-9_-]{0,63}\z/', $id ) || in_array( $id, array( 'constructor', 'prototype' ), true ) ||
				! is_string( $term_id ) || ! preg_match( '/\A[1-9][0-9]{0,19}\z/', $term_id ) ) {
				throw new Failure( 'INVALID_MENU_MAPPING' );
			}
		}
		ksort( $mapping, SORT_STRING );
		return $mapping;
	}

	/**
	 * Resolve selected classic menus, once per source, including empty menus.
	 *
	 * @param array $mapping Normalized output-to-source mapping.
	 * @throws Failure If a selected source is no longer available.
	 */
	public static function selected( array $mapping ): array {
		$catalog = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$catalog[ (string) $menu->term_id ] = $menu;
		}
		$selected = array();
		foreach ( $mapping as $term_id ) {
			if ( ! isset( $catalog[ $term_id ] ) ) {
				throw new Failure( 'MENU_MISSING' );
			}
			$selected[ $term_id ] = $catalog[ $term_id ];
		}
		ksort( $selected, SORT_NUMERIC );
		return array_values( $selected );
	}

	/** Return labels and IDs for the administrator's menu selectors. */
	public static function catalog(): array {
		return array_map(
			static fn( $menu ) => array(
				'id'   => (string) $menu->term_id,
				'name' => html_entity_decode( $menu->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			),
			wp_get_nav_menus( array( 'orderby' => 'name' ) )
		);
	}

	/**
	 * Describe a source menu for read-only inspection.
	 *
	 * @param object $menu Classic WordPress menu.
	 * @param array  $slots Selected output IDs.
	 * @param int    $count Number of reviewed items.
	 */
	public static function review_menu( object $menu, array $slots, int $count ): array {
		return array(
			'id'       => (int) $menu->term_id,
			'name'     => html_entity_decode( $menu->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'slots'    => $slots,
			'count'    => $count,
			'edit_url' => current_user_can( 'edit_theme_options' ) && ( current_theme_supports( 'menus' ) || current_theme_supports( 'widgets' ) )
				? add_query_arg(
					array(
						'action' => 'edit',
						'menu'   => (int) $menu->term_id,
					),
					admin_url( 'nav-menus.php' )
				) : '',
		);
	}

	/** Inspect the currently selected menu, including an unsaved selection.
	 *
	 * @param string          $id WordPress menu ID.
	 * @param int             $page Zero-based page.
	 * @param Resource_Budget $budget Shared lookup budget.
	 */
	public static function preview( string $id, int $page, Resource_Budget $budget ): array {
		$budget->check();
		Export_Review::page_number( $page );
		$mapping = self::normalize( array( 'preview' => $id ) );
		return Export_Reads::scope(
			static function ( Export_Reads $reads ) use ( $mapping, $page, $budget ) {
				$menu    = self::selected( $mapping )[0];
				$entries = $reads->menu_items( $menu );
				$entries = is_array( $entries ) ? $entries : array();
				$budget->check( count( $entries ) * 256 );
				usort( $entries, static fn( $a, $b ) => $a->menu_order === $b->menu_order ? $a->ID <=> $b->ID : $a->menu_order <=> $b->menu_order );
				$total = count( $entries );
				$pages = (int) ceil( $total / Export_Review::PAGE_SIZE );
				if ( $page >= max( 1, $pages ) ) {
					throw new Failure( 'INVALID_REQUEST' );
				}
				$destinations = self::destinations( $entries, $reads, $budget );
				$parents      = array();
				foreach ( $entries as $entry ) {
					$parents[ $entry->ID ] = (int) $entry->menu_item_parent;
				}
				$items = array();
				$bytes = 0;
				foreach ( array_slice( $entries, $page * Export_Review::PAGE_SIZE, Export_Review::PAGE_SIZE ) as $entry ) {
					$bytes += strlen( $entry->title ) + strlen( $entry->url ) + 512;
					$budget->check( $bytes );
					$term    = 'taxonomy' === $entry->type ? $reads->term( (int) $entry->object_id, $entry->object ) : null;
					$items[] = array(
						'menu'       => (int) $menu->term_id,
						'id'         => (int) $entry->ID,
						'parent'     => (int) $entry->menu_item_parent,
						'label'      => html_entity_decode( $entry->title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						'url'        => $destinations->url( $entry, $term instanceof \WP_Term ? $term : null ),
						'new_window' => '_blank' === $entry->target,
						'depth'      => Export_Review::depth( (int) $entry->ID, $parents ),
					);
				}
				return array(
					'items'  => $items,
					'offset' => $page * Export_Review::PAGE_SIZE,
					'page'   => $page,
					'pages'  => $pages,
					'total'  => $total,
					'menus'  => array( self::review_menu( $menu, array(), $total ) ),
				);
			},
			$budget
		);
	}

	/**
	 * Inspect eligible links through the existing bounded reader. Nothing is
	 * exported or written, and WordPress permalink filters keep their inputs.
	 *
	 * @param array           $entries Selected menu's source items.
	 * @param Export_Reads    $reads Active guarded read scope.
	 * @param Resource_Budget $budget Shared request budget (never raised here).
	 */
	private static function destinations( array $entries, Export_Reads $reads, Resource_Budget $budget ): Menu_Destinations {
		$settings = Settings::read();
		$result   = new Menu_Destinations( $settings['public_url'], $budget, $entries );
		if ( ! array_filter( $entries, static fn( $entry ) => 'custom' !== $entry->type ) ) {
			return $result;
		}
		$source    = wp_parse_url( untrailingslashit( home_url( '/' ) ) );
		$source    = is_array( $source ) ? $source : array();
		$suspended = wp_suspend_cache_addition();
		$runtime   = wp_cache_supports( 'flush_runtime' );
		if ( ! $runtime ) {
			wp_suspend_cache_addition( true );
		}
		try {
			foreach ( ( new Content_Reader( $budget ) )->pages() as $page ) {
				foreach ( $reads->documents( $page ) as $posts ) {
					foreach ( $posts as $post ) {
						$result->observe( $post, Menu_Destinations::site_link( get_permalink( $post ), $settings['public_url'], $source ) );
					}
					unset( $posts, $post );
					if ( $runtime ) {
						wp_cache_flush_runtime();
					}
				}
				unset( $page );
			}
		} finally {
			wp_suspend_cache_addition( $suspended );
		}
		return $result;
	}
}
