<?php
/**
 * Per-IP quotas for public comment requests.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Atomic fixed-minute counters shared by all PHP workers. */
final class Comment_Rate_Limit {

	const PREFIX = 'zpsp_comment_rate_';
	const LIMITS = array(
		'challenge' => 60,
		'write'     => 5,
	);

	/**
	 * Count an attempt before issuing or verifying a proof.
	 *
	 * @param string   $scope Challenge or write quota.
	 * @param string   $ip Server-provided client address.
	 * @param int|null $now Unix time; defaults to the current time.
	 * @return \WP_Error|null Error when the request cannot proceed.
	 */
	public static function check( string $scope, string $ip, ?int $now = null ): ?\WP_Error {
		global $wpdb;
		if ( ! isset( self::LIMITS[ $scope ] ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return self::unavailable();
		}
		$address = inet_pton( $ip );
		// Equivalent IPv6 spellings and IPv4-mapped addresses share one quota.
		if ( str_starts_with( $address, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$address = substr( $address, 12 );
		}
		$now     = $now ?? time();
		$expires = ( intdiv( $now, 60 ) + 1 ) * 60;
		$digest  = hash_hmac( 'sha256', $address, Settings::key( 'comment-rate-limit' ) );
		$name    = self::PREFIX . sprintf( '%012d', $expires ) . '_' . $scope . '_' . $digest;
		// The unique option name admits only one initial request per bucket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$created = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')", $name ) );
		if ( false === $created ) {
			return self::unavailable();
		}
		if ( 0 === $created ) {
			// The condition and increment are atomic; no read/modify/write cache race.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
					$name,
					self::LIMITS[ $scope ]
				)
			);
			if ( false === $updated ) {
				return self::unavailable();
			}
			if ( 0 === $updated ) {
				return new \WP_Error(
					'zpsp_comment_rate_limited',
					__( 'Too many comment requests. Please try again shortly.', 'zeropress-static' ),
					array(
						'status'      => 429,
						'retry_after' => $expires - $now,
					)
				);
			}
		} else {
			// Cleanup is bounded and runs only when a new bucket is created.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name >= %s AND option_name < %s ORDER BY option_name LIMIT 100", self::PREFIX, self::PREFIX . sprintf( '%012d', $now + 1 ) ) );
		}
		return null;
	}

	/**
	 * Fail closed without disclosing addresses or database errors.
	 *
	 * @return \WP_Error Availability error.
	 */
	private static function unavailable(): \WP_Error {
		return new \WP_Error(
			'zpsp_comment_rate_limit_unavailable',
			__( 'Comment request limiting is temporarily unavailable. Please try again later.', 'zeropress-static' ),
			array( 'status' => 503 )
		);
	}
}
