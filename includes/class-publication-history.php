<?php
/**
 * Bounded, administrator-facing publication summaries.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Observational history; never authorizes or retries a publication. */
final class Publication_History {
	const OPTION    = 'zpsp_publication_history_v1';
	const LIMIT     = 100;
	const PAGE_SIZE = 20;
	const MAX_BYTES = 262144;

	/**
	 * Observe only creation and completion after the recovery journal is saved.
	 * In particular, do no history I/O between a final-write intent and dispatch.
	 *
	 * @param array|null $previous Previously saved job.
	 * @param array      $current Newly saved recovery journal.
	 */
	public static function checkpoint( ?array $previous, array $current ): void {
		if ( $current['job'] && ( ! $previous || $previous['id'] !== $current['job']['id'] ) ) {
			self::record( $current['job'], array( 'state' => 'pending' ), $current['revision'] );
		} elseif ( $previous && ! $current['job'] ) {
			$last = end( $current['history'] );
			if ( $last && $last['id'] === $previous['id'] ) {
				self::record( $previous, $last['result'], $current['revision'] );
			}
		}
	}

	/**
	 * Save one summary per job with independent compare-and-swap protection.
	 * Failure must not change a confirmed result or the recovery journal.
	 *
	 * @param array $job Trusted publication metadata, never stored verbatim.
	 * @param array $result Journal-confirmed result.
	 * @param int   $revision Recovery revision that produced this observation.
	 * @return bool Whether the summary was saved or already observed.
	 */
	public static function record( array $job, array $result, int $revision ): bool {
		try {
			$entry = self::summary( $job, $result, $revision );
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				list( $raw, $entries, $deleted, $floor ) = self::load();
				if ( isset( $deleted[ $entry['id'] ] ) ) {
					return true;
				}
				$rows = array_column( $entries, null, 'id' );
				if ( isset( $rows[ $entry['id'] ] ) ) {
					if ( $rows[ $entry['id'] ]['revision'] >= $revision ) {
						return true;
					}
					$entry['sequence'] = $rows[ $entry['id'] ]['sequence'];
				} elseif ( $revision <= $floor ) {
					// Pruned deletion markers must not allow older observations to reappear.
					return true;
				}
				$rows[ $entry['id'] ] = $entry;
				$rows                 = self::recent( array_values( $rows ) );
				if ( self::save( $raw, $rows, $deleted, $floor ) ) {
					return true;
				}
			}
		} catch ( \Throwable $error ) {
			// Optional history must not mask success or interfere with uncertainty recovery.
			return false;
		}
		return false;
	}

	/**
	 * Delete only a finished display summary, never recovery or remote state.
	 *
	 * @param string $id Publication identifier.
	 * @throws Failure If authorization, completion or storage cannot be confirmed.
	 */
	public static function delete( string $id ): void {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			throw new Failure( 'FORBIDDEN', 403 );
		}
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) {
			throw new Failure( 'INVALID_REQUEST' );
		}
		try {
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				list( $raw, $entries, $deleted, $floor ) = self::load();
				$journal                                 = ( new Publication_Store() )->data;
				if ( $journal['job'] && $journal['job']['id'] === $id ) {
					throw new Failure( 'HISTORY_DELETE_BLOCKED', 409 );
				}
				$rows = array_column( $entries, null, 'id' );
				if ( ! isset( $rows[ $id ] ) ) {
					return;
				}
				$entry = self::observed( $rows[ $id ], $journal );
				if ( ! $entry['can_delete'] ) {
					throw new Failure( 'HISTORY_DELETE_BLOCKED', 409 );
				}
				unset( $rows[ $id ] );
				// IDs and revisions only: removed summaries retain no user or target metadata.
				$deleted[ $id ] = max( $entry['revision'], $journal['revision'] );
				arsort( $deleted, SORT_NUMERIC );
				foreach ( array_slice( $deleted, self::LIMIT, null, true ) as $revision ) {
					$floor = max( $floor, $revision );
				}
				$deleted = array_slice( $deleted, 0, self::LIMIT, true );
				if ( self::save( $raw, array_values( $rows ), $deleted, $floor ) ) {
					return;
				}
			}
		} catch ( Failure $error ) {
			if ( 'HISTORY_DELETE_BLOCKED' === $error->reason ) {
				throw $error;
			}
			throw new Failure( 'HISTORY_UNAVAILABLE', 503 );
		} catch ( \Throwable $error ) {
			throw new Failure( 'HISTORY_UNAVAILABLE', 503 );
		}
		throw new Failure( 'HISTORY_CONFLICT', 409 );
	}

	/**
	 * Share bounded compare-and-swap writes between recording and deletion.
	 *
	 * @param string|null $raw Exact previously read option value.
	 * @param array       $entries Retained summaries.
	 * @param array       $deleted Bounded deleted IDs and observation revisions.
	 * @param int         $floor Reject older insertions after deleting an ID marker.
	 * @return bool Whether this writer won the comparison.
	 * @throws \RuntimeException If storage fails or its size limit is exceeded.
	 */
	private static function save( ?string $raw, array $entries, array $deleted, int $floor ): bool {
		$next = wp_json_encode(
			array(
				'version' => 1,
				'entries' => $entries,
				'deleted' => $deleted,
				'floor'   => $floor,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);
		if ( strlen( $next ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'Publication history size limit exceeded.' );
		}
		global $wpdb;
		if ( null === $raw ) {
			$query = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $next );
		} else {
			$query = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $next, self::OPTION, $raw );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared CAS prevents recording and deletion from losing each other's changes; caches are invalidated on success.
		$saved = $wpdb->query( $query );
		if ( false === $saved ) {
			throw new \RuntimeException( 'Publication history write failed.' );
		}
		if ( 1 === $saved ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}
		return false;
	}

	/**
	 * Resolve display and deletion eligibility from the same recovery observation.
	 *
	 * @param array $entry Stored summary.
	 * @param array $journal Read-only recovery snapshot.
	 */
	private static function observed( array $entry, array $journal ): array {
		$entry['can_delete'] = false;
		if ( $journal['job'] && $entry['id'] === $journal['job']['id'] ) {
			$entry['state']  = self::job_state( $journal['job'] );
			$entry['commit'] = '';
			return $entry;
		}
		$completed = array_column( $journal['history'], 'result', 'id' );
		if ( isset( $completed[ $entry['id'] ] ) ) {
			self::result( $entry, $completed[ $entry['id'] ] );
			self::validate( $entry );
		} elseif ( 'pending' === $entry['state'] ) {
			// A missing journal is not evidence that the GitHub update failed.
			$entry['state'] = 'unknown';
		}
		$entry['can_delete'] = in_array( $entry['state'], array( 'published', 'unchanged', 'failed', 'cancelled', 'expired' ), true );
		return $entry;
	}

	/**
	 * Read one page without changing local state or contacting GitHub.
	 * Unfinished entries use the authoritative journal, including uncertain writes.
	 *
	 * @param int $page Requested page.
	 * @return array Page data, or an unavailable marker without a fabricated empty list.
	 * @throws Failure If the caller is not a single-site administrator.
	 */
	public static function page( int $page = 1 ): array {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			throw new Failure( 'FORBIDDEN', 403 );
		}
		try {
			list( , $entries ) = self::load();
			$journal           = ( new Publication_Store() )->data;
			$job               = $journal['job'];
			$found             = false;
			foreach ( $entries as &$entry ) {
				$entry = self::observed( $entry, $journal );
				$found = $found || ( $job && $entry['id'] === $job['id'] );
			}
			unset( $entry );
			if ( $job && ! $found ) {
				$entry     = self::summary( $job, array( 'state' => 'pending' ), $journal['revision'] );
				$entry     = self::observed( $entry, $journal );
				$entries[] = $entry;
			}
			$entries = self::recent( $entries );
			$total   = count( $entries );
			$pages   = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
			$page    = max( 1, min( $pages, $page ) );
			return array(
				'available' => true,
				'entries'   => array_slice( $entries, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ),
				'total'     => $total,
				'page'      => $page,
				'pages'     => $pages,
			);
		} catch ( \Throwable $error ) {
			return array( 'available' => false );
		}
	}

	/** Read bounded, non-autoloaded summaries without trusting an option cache.
	 *
	 * @return array Exact stored value and validated summaries.
	 * @throws \RuntimeException If the independent history cannot be read safely.
	 */
	private static function load(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh value is required for CAS and read-only status rendering.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( $wpdb->last_error || ( null !== $raw && strlen( $raw ) > self::MAX_BYTES ) ) {
			throw new \RuntimeException( 'Publication history unavailable.' );
		}
		if ( null === $raw ) {
			return array( null, array(), array(), 0 );
		}
		$value = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
		if ( 1 !== ( $value['version'] ?? null ) || ! is_array( $value['entries'] ?? null ) || count( $value['entries'] ) > self::LIMIT ) {
			throw new \RuntimeException( 'Invalid publication history.' );
		}
		$ids = array();
		foreach ( $value['entries'] as $entry ) {
			self::validate( $entry );
			if ( isset( $ids[ $entry['id'] ] ) ) {
				throw new \RuntimeException( 'Duplicate publication summary.' );
			}
			$ids[ $entry['id'] ] = true;
		}
		$deleted = array_key_exists( 'deleted', $value ) ? $value['deleted'] : array();
		$floor   = array_key_exists( 'floor', $value ) ? $value['floor'] : 0;
		if ( ! is_array( $deleted ) || count( $deleted ) > self::LIMIT || ! is_int( $floor ) || $floor < 0 ) {
			throw new \RuntimeException( 'Invalid publication deletions.' );
		}
		foreach ( $deleted as $id => $revision ) {
			if ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $id ) || ! is_int( $revision ) || $revision < 1 || isset( $ids[ $id ] ) ) {
				throw new \RuntimeException( 'Invalid deleted publication.' );
			}
		}
		return array( $raw, $value['entries'], $deleted, $floor );
	}

	/** Keep only display metadata; never credentials, bodies, approvals or snapshots.
	 *
	 * @param array $job Trusted job.
	 * @param array $result Confirmed result.
	 * @param int   $revision Journal revision.
	 */
	private static function summary( array $job, array $result, int $revision ): array {
		$entry = array(
			'id'         => $job['id'] ?? '',
			'owner'      => $job['owner'] ?? 0,
			'started_at' => $job['started_at'] ?? 0,
			'repository' => $job['target']['repository'] ?? '',
			'branch'     => $job['target']['branch'] ?? '',
			'sequence'   => $revision,
			'revision'   => $revision,
		);
		self::result( $entry, $result );
		self::validate( $entry );
		return $entry;
	}

	/** Apply a journal result without accepting arbitrary link destinations.
	 *
	 * @param array $entry Summary to update.
	 * @param array $result Confirmed result.
	 */
	private static function result( array &$entry, array $result ): void {
		$entry['state']  = $result['state'] ?? '';
		$entry['code']   = is_string( $result['code'] ?? null ) && preg_match( '/\A[A-Z0-9_]{1,96}\z/', $result['code'] ) ? $result['code'] : '';
		$entry['commit'] = '';
		if ( 'published' === $entry['state'] && is_string( $result['commit_url'] ?? null ) &&
			preg_match( '~\A' . preg_quote( 'https://github.com/' . $entry['repository'] . '/commit/', '~' ) . '([a-f0-9]{40})\z~', $result['commit_url'], $match ) ) {
			$entry['commit'] = $match[1];
		}
	}

	/** Validate stored summaries before using or merging them.
	 *
	 * @param mixed $entry Stored summary.
	 * @throws \RuntimeException If fields are malformed or unbounded.
	 */
	private static function validate( $entry ): void {
		if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $entry['id'] ) ||
			! is_int( $entry['owner'] ?? null ) || $entry['owner'] < 1 || ! is_int( $entry['started_at'] ?? null ) || $entry['started_at'] < 0 ||
			! is_int( $entry['revision'] ?? null ) || $entry['revision'] < 1 || ! is_int( $entry['sequence'] ?? null ) || $entry['sequence'] < 1 ||
			! is_string( $entry['repository'] ?? null ) || ! preg_match( '~\A[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}\z~', $entry['repository'] ) ||
			! is_string( $entry['branch'] ?? null ) || '' === $entry['branch'] || strlen( $entry['branch'] ) > 1024 ||
			! in_array( $entry['state'] ?? null, array( 'pending', 'published', 'unchanged', 'failed', 'cancelled', 'expired' ), true ) ||
			! is_string( $entry['code'] ?? null ) || ! preg_match( '/\A(?:[A-Z0-9_]{1,96})?\z/', $entry['code'] ) ||
			! is_string( $entry['commit'] ?? null ) || ! preg_match( '/\A(?:[a-f0-9]{40})?\z/', $entry['commit'] ) ) {
			throw new \RuntimeException( 'Invalid publication summary.' );
		}
	}

	/** Derive display state without resuming, cancelling or verifying any work.
	 *
	 * @param array $job Current recovery job.
	 */
	private static function job_state( array $job ): string {
		if ( ! empty( $job['intent'] ) ) {
			return 'unknown';
		}
		if ( ! empty( $job['cancel_requested'] ) ) {
			return 'cancel_pending';
		}
		if ( time() > $job['expires'] ) {
			return 'expired';
		}
		return ! empty( $job['paused'] ) ? 'paused' : 'pending';
	}

	/** Retain the newest attempts, without moving a resumed job to the top.
	 *
	 * @param array $entries Summaries to order.
	 */
	private static function recent( array $entries ): array {
		usort( $entries, static fn( $a, $b ) => $b['sequence'] <=> $a['sequence'] );
		return array_slice( $entries, 0, self::LIMIT );
	}
}
