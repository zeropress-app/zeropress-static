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
}
