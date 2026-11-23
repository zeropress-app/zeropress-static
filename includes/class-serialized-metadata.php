<?php
/** Admit serialized metadata before PHP expands it into arrays.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** A cost gate; PHP remains the decoder for admitted scalar/array values. */
final class Serialized_Metadata {

	/**
	 * Preserve ordinary metadata decoding without constructing unbounded arrays or objects.
	 *
	 * @param string          $raw Stored metadata value.
	 * @param Resource_Budget $budget Shared request budget.
	 * @return mixed
	 * @throws Failure If the value cannot be decoded safely.
	 */
	public static function decode( string $raw, Resource_Budget $budget ): mixed {
		$budget->check( strlen( $raw ) );
		if ( ! is_serialized( $raw ) ) {
			return $raw;
		}
		$data  = trim( $raw );
		$size  = strlen( $data );
		$pos   = 0;
		$nodes = 0;
		$stack = array( 1 );
		$depth = (int) ini_get( 'unserialize_max_depth' );
		while ( $stack ) {
			$last = count( $stack ) - 1;
			if ( 0 === $stack[ $last ] ) {
				array_pop( $stack );
				if ( $stack && ( $data[ $pos++ ] ?? '' ) !== '}' ) {
					return false;
				}
				continue;
			}
			--$stack[ $last ];
			++$nodes;
			if ( 0 === $nodes % 128 || 1 === $nodes ) {
				$budget->check( $size + $nodes * 512 );
			}
			$type = $data[ $pos++ ] ?? '';
			if ( 'N' === $type ) {
				if ( ( $data[ $pos++ ] ?? '' ) !== ';' ) {
					return false;
				}
				continue;
			}
			if ( in_array( $type, array( 'O', 'C', 'E', 'r' ), true ) ) {
				throw new Failure( 'SOURCE_READ_UNBOUNDED', 409 );
			}
			if ( ( $data[ $pos++ ] ?? '' ) !== ':' ) {
				return false;
			}
			if ( in_array( $type, array( 'b', 'i', 'd', 'R' ), true ) ) {
				$end = strpos( $data, ';', $pos );
				if ( false === $end ) {
					return false;
				}
				// PHP validates scalar syntax/reference identity after cost admission.
				$pos = $end + 1;
				continue;
			}
			if ( ! in_array( $type, array( 's', 'a' ), true ) ) {
				return false;
			}
			$end = strpos( $data, ':', $pos );
			if ( false === $end || $end === $pos ) {
				return false;
			}
			$digits = substr( $data, $pos, $end - $pos );
			if ( ! ctype_digit( $digits ) ) {
				return false;
			}
			// Leading zeroes are valid PHP lengths. Bound the significant integer,
			// not the token length; all source bytes were admitted before this scan.
			$digits = ltrim( $digits, '0' );
			if ( strlen( $digits ) > 18 ) {
				return false;
			}
			$length = (int) $digits;
			$pos    = $end + 1;
			if ( 's' === $type ) {
				if ( ( $data[ $pos++ ] ?? '' ) !== '"' || $length > $size - $pos - 2 ) {
					return false;
				}
				$pos += $length;
				if ( substr( $data, $pos, 2 ) !== '";' ) {
					return false;
				}
				$pos += 2;
			} else {
				if ( ( $data[ $pos++ ] ?? '' ) !== '{' || $length > intdiv( $size, 4 ) ) {
					return false;
				}
				if ( $depth > 0 && count( $stack ) > $depth ) {
					return false;
				}
				// Account for declared entries before allocating even the scan stack.
				$budget->check( $size + ( $nodes + $length * 2 ) * 512 );
				$stack[] = $length * 2;
			}
		}
		$budget->check( $size + $nodes * 512 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Scalar/array tokens and allocation cost were admitted above; preserve WordPress metadata semantics.
		return @unserialize( $data );
	}
}
