<?php
/**
 * Preparation receipts and publication preconditions.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Stateless short-lived receipts; export bodies never enter public temporary storage. */
final class Publisher {
	/**
	 * Generate data and seal its review context for ten minutes.
	 *
	 * @param Resource_Budget|null $budget Shared request resource budget.
	 * @return array
	 */
	public static function prepare( ?Resource_Budget $budget = null ): array {
		if ( ! Publication_Runtime::active() ) {
			return Publication_Runtime::run( fn() => self::prepare( $budget ) );
		}
		$lock     = Publication_Runtime::memory() ? new Memory_Lock() : null;
		$budget ??= Resource_Budget::from_request();
		Export_Storage::recover_once( $budget );
		$settings         = Settings::read();
		$generated_at     = time();
		$snapshot         = null;
		$connection_error = null;
		$error_details    = array();
		if ( '' !== $settings['repository'] && '' !== $settings['credential'] ) {
			try {
				$snapshot = ( new GitHub( $settings, $budget ) )->inspect( $settings['branch'] );
			} catch ( Failure $error ) {
				$connection_error = $error->reason;
				$error_details    = $error->details;
			}
		}
		$lock?->check();
		$mode = 'single';
		if ( $snapshot ) {
			try {
				$mode = self::mode( $snapshot, $settings['wxr_publish_format'] );
			} catch ( Failure $error ) {
				$connection_error = $error->reason;
			}
		}
		// An incompatible publishing target must not prevent ordinary XML downloads.
		$review          = new Export_Review( $budget );
		$export          = ( new Exporter( $budget, $review ) )->generate_files( $settings, $generated_at, $mode );
		$plan            = null;
		$memory_capacity = null;
		try {
			if ( $snapshot && ! $connection_error ) {
				$plan = self::plan( $export, $budget, $memory_capacity );
			} elseif ( Publication_Runtime::memory() ) {
				// Local size information is useful before a GitHub connection is configured.
				$memory_capacity = self::memory_capacity( $export );
			}
		} catch ( Failure $error ) {
			$connection_error = $error->reason;
			$error_details    = $error->details;
		} finally {
			self::close_export( $export );
		}
		// Do not issue approval after a connection change during generation.
		$lock?->check();
		// Commitments remain compact; per-part inventories never enter receipts.
		if ( $snapshot ) {
			unset( $snapshot['parts'] );
		}
		Publication_Runtime::assert_current();
		$receipt           = array(
			'backend'      => Publication_Runtime::backend(),
			'user'         => get_current_user_id(),
			'revision'     => $settings['revision'],
			'hash'         => $export['data_hash'],
			'expires'      => time() + 600,
			'generated_at' => $generated_at,
			'snapshot'     => $snapshot,
			'plan'         => $plan,
		);
		$receipt['review'] = $review->finish( $receipt );
		Publication_Runtime::assert_current();
		$lock?->check();
		$payload = base64_encode( wp_json_encode( $receipt ) );
		$result  = array(
			'runtime'          => Publication_Runtime::describe( $settings ),
			'receipt'          => $payload . '.' . hash_hmac( 'sha256', $payload, Settings::key( 'preparation' ) ),
			'counts'           => $export['counts'],
			'bytes'            => $export['bytes'],
			'memory_capacity'  => $memory_capacity,
			'rewritten_values' => $export['rewritten_values'],
			'warnings'         => $export['warnings'],
			'target'           => $snapshot ? array(
				'repository' => $snapshot['repository'],
				'branch'     => $snapshot['branch'],
				'visibility' => $snapshot['visibility'],
			) : null,
			'state'            => $plan && ! $connection_error ? ( $snapshot['data_hash'] === $export['data_hash'] && $snapshot['format'] === $plan['format'] ? 'unchanged' : 'ready' ) : 'download_only',
			'publication'      => $plan ? array(
				'format'          => $plan['format'],
				'previous_format' => $snapshot['format'],
				'part_count'      => $plan['part_count'],
			) : null,
			'connection_error' => $connection_error,
		);
		if ( $error_details ) {
			$result['connection_error_details'] = $error_details;
		}
		return $result;
	}

	/**
	 * Re-read source and configuration rather than trusting a browser's JSON.
	 *
	 * @param string          $receipt Signed preparation receipt.
	 * @param Resource_Budget $budget Shared request resource budget.
	 * @param bool            $refresh_time Whether to use the current WXR generation time.
	 * @param bool            $publication Whether to validate the publication transport.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 * @throws \Throwable If regeneration fails.
	 *
	 * @param int             $generated_at Frozen export timestamp.
	 */
	public static function validate( string $receipt, Resource_Budget $budget, bool $refresh_time = false, bool $publication = false, ?int $generated_at = null ): array {
		$budget->check();
		list( $settings, $value ) = self::receipt( $receipt );
		if ( $publication && empty( $value['snapshot'] ) ) {
			throw new Failure( 'CONNECTION_REQUIRED' );
		}
		// A signed plan fixes the actual format, including for older auto receipts.
		$mode = $publication ? ( $value['plan']['format'] ?? null ) : 'single';
		if ( ! in_array( $mode, array( 'single', 'bundle' ), true ) || ( 'bundle' === $mode && empty( $value['snapshot']['capable'] ) ) ) {
			throw new Failure( 'PREPARATION_INVALID', 409 );
		}
		Export_Storage::recover_once( $budget );
		$export = ( new Exporter( $budget ) )->generate_files( $settings, $generated_at ?? ( $refresh_time ? time() : $value['generated_at'] ), $mode );
		if ( $export['data_hash'] !== $value['hash'] ) {
			self::close_export( $export );
			throw new Failure( 'CONTENT_CHANGED', 409 );
		}
		if ( $publication ) {
			try {
				$plan = self::plan( $export, $budget );
				if ( empty( $value['plan'] ) || $plan['format'] !== $value['plan']['format'] || ( ! $refresh_time && $plan['digest'] !== $value['plan']['digest'] ) ) {
					throw new Failure( 'PREPARATION_INVALID', 409 );
				}
			} catch ( \Throwable $error ) {
				self::close_export( $export );
				throw $error;
			}
		}
		return array( $settings, $value, $export );
	}

	/**
	 * Validate a signed, user/settings-bound preparation without materializing content.
	 *
	 * @param string $receipt Signed, expiring preparation approval.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public static function receipt( string $receipt ): array {
		if ( strlen( $receipt ) > 20000 || 1 !== substr_count( $receipt, '.' ) ) {
			throw new Failure( 'PREPARATION_INVALID', 409 );
		}
		list( $payload, $signature ) = explode( '.', $receipt, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, Settings::key( 'preparation' ) ), $signature ) ) {
			throw new Failure( 'PREPARATION_INVALID', 409 );
		}
		$value    = json_decode( (string) base64_decode( $payload, true ), true );
		$settings = Settings::fresh();
		Publication_Runtime::assert_current();
		if ( ! is_array( $value ) || ( $value['user'] ?? null ) !== get_current_user_id() ||
			( $value['expires'] ?? 0 ) < time() || ( $value['revision'] ?? '' ) !== $settings['revision'] ) {
			throw new Failure( 'PREPARATION_EXPIRED', 409 );
		}
		if ( ( $value['backend'] ?? 'files' ) !== Publication_Runtime::backend() ) {
			throw new Failure( 'PREPARATION_EXPIRED', 409 );
		}
		return array( $settings, $value );
	}

	/**
	 * Regenerate and download a still-current prepared file.
	 *
	 * @param string               $receipt Signed preparation receipt.
	 * @param string               $file Allowlisted export key.
	 * @param Resource_Budget|null $budget Shared request resource budget.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function download( string $receipt, string $file, ?Resource_Budget $budget = null ): string {
		$content = self::download_file( $receipt, $file, $budget );
		try {
			return $content->contents();
		} finally {
			$content->close();
		}
	}

	/**
	 * Validate completely before exposing a download; the caller owns its handle.
	 *
	 * @param string               $receipt Signed preparation receipt.
	 * @param string               $file Allowlisted export key.
	 * @param Resource_Budget|null $budget Shared request resource budget.
	 * @return Export_File
	 * @throws Failure If validation or storage fails.
	 * @throws \Throwable If writing build settings fails.
	 */
	public static function download_file( string $receipt, string $file, ?Resource_Budget $budget = null ): Export_File {
		if ( ! Publication_Runtime::active() ) {
			return Publication_Runtime::run( fn() => self::download_file( $receipt, $file, $budget ) );
		}
		$lock     = Publication_Runtime::memory() ? new Memory_Lock() : null;
		$budget ??= Resource_Budget::from_request();
		if ( ! isset( GitHub::FILES[ $file ] ) ) {
			throw new Failure( 'INVALID_FILE' );
		}
		list( , , $export ) = self::validate( $receipt, $budget );
		if ( 'wxr' === $file ) {
			$export['wxr']->retain_lock( $lock );
			return $export['wxr'];
		}
		$export['wxr']->close();
		$content = new Export_File( $budget );
		try {
			$content->write( $export['base'] );
			$content->retain_lock( $lock );
			return $content;
		} catch ( \Throwable $error ) {
			$content->close();
			throw $error;
		}
	}

	/**
	 * Retain the legacy request contract behind the same durable publication gate.
	 *
	 * @param string               $receipt Signed, expiring preparation approval.
	 * @param bool                 $republish Whether unchanged data was explicitly approved for republication.
	 * @param Resource_Budget      $budget Original request memory and time guard.
	 * @param Publication_Progress $progress Optional legacy progress reporter.
	 */
	public static function publish( string $receipt, bool $republish = false, ?Resource_Budget $budget = null, ?Publication_Progress $progress = null ): array {
		if ( Publication_Runtime::memory() ) {
			return ( new Publication_Session( $budget ) )->start( $receipt, $republish, bin2hex( random_bytes( 16 ) ) );
		}
		return Publication_Session::legacy( $receipt, $republish, $budget ?? Resource_Budget::from_request(), $progress );
	}

	/**
	 * Revalidate preparation before updating the GitHub branch.
	 *
	 * @param string                    $receipt Signed preparation receipt.
	 * @param bool                      $republish Explicitly publish an unchanged prepared export again.
	 * @param Resource_Budget|null      $budget Shared request resource budget.
	 * @param Publication_Progress|null $progress Optional stage feedback for this attempt.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 *
	 * @param \Closure                  $observer Optional before/after transport checkpoint hook.
	 */
	public static function publish_once( string $receipt, bool $republish = false, ?Resource_Budget $budget = null, ?Publication_Progress $progress = null, ?\Closure $observer = null ): array {
		$budget ??= Resource_Budget::from_request();
		try {
			$progress?->update( 'validating' );
			list( $settings, $prepared, $export ) = self::validate( $receipt, $budget, $republish, true );
			if ( empty( $prepared['snapshot'] ) ) {
				throw new Failure( 'CONNECTION_REQUIRED' );
			}
			if ( $republish && $prepared['snapshot']['data_hash'] !== $export['data_hash'] ) {
				throw new Failure( 'PREPARATION_INVALID', 409 );
			}
			$progress?->update( 'inspecting' );
			$github = new GitHub( $settings, $budget, $observer );
			$github->expect_visibility( $prepared['snapshot']['visibility'] ?? null );
			$current = $github->inspect( $prepared['snapshot']['branch'] );
			if ( $current['repository'] !== $prepared['snapshot']['repository'] || $current['files'] !== $prepared['snapshot']['files'] || $current['marker'] !== $prepared['snapshot']['marker'] ) {
				throw new Failure( 'TARGET_CHANGED', 409 );
			}
			if ( ! $republish && $current['data_hash'] === $export['data_hash'] && $current['format'] === $prepared['plan']['format'] ) {
				return array( 'state' => 'unchanged' );
			}
			if ( $republish && ( ! empty( $export['bundle'] ) ? GitHub::blob_sha( $export['bundle']->manifest ) === ( $current['files']['manifest'] ?? null ) : $export['wxr']->blob_sha() === ( $current['files']['wxr'] ?? null ) ) ) {
				$progress?->update( 'validating' );
				// Release the previous bodies before generating their replacements.
				self::close_export( $export );
				unset( $export );
				// WXR dates have second precision. Wait once so an immediate repeat changes the file too.
				$budget->check();
				usleep( 1000000 );
				list( , , $export ) = self::validate( $receipt, $budget, true, true );
			}
			// Include unrelated intervening commits by publishing on the current verified tree.
			$guard = Publication_Runtime::memory() ? static function () use ( $receipt, $budget ): void {
				// Upload buffers have been released before the final source check.
				list( , , $checked ) = self::validate( $receipt, $budget, false, true );
				self::close_export( $checked );
			} : null;
			return $github->publish( $current, $export, $republish, $progress, $guard );
		} finally {
			if ( isset( $export ) ) {
				self::close_export( $export );
			}
			$progress?->clear();
		}
	}
	/**
	 * Resolve the saved preference without bypassing starter capability checks.
	 *
	 * @param array|null $snapshot Verified target at an immutable commit.
	 * @param string     $preference Saved automatic, single or split selection.
	 * @return string
	 * @throws Failure If the preference or starter capability is invalid.
	 */
	public static function mode( ?array $snapshot, string $preference = 'auto' ): string {
		if ( ! in_array( $preference, array( 'auto', 'single', 'bundle' ), true ) ) {
			throw new Failure( 'INVALID_SETTINGS' );
		}
		if ( Publication_Runtime::memory() ) {
			if ( 'bundle' === $preference || ( 'auto' === $preference && 'bundle' === ( $snapshot['format'] ?? null ) ) ) {
				throw new Failure( 'MEMORY_SINGLE_REQUIRED', 409 );
			}
			return 'single';
		}
		if ( 'bundle' === $preference && empty( $snapshot['capable'] ) ) {
			throw new Failure( 'WXR_SPLIT_UNSUPPORTED', 409 );
		}
		if ( 'auto' !== $preference ) {
			return $preference;
		}
		return ! empty( $snapshot['capable'] ) ? ( 'bundle' === $snapshot['format'] ? 'bundle' : 'auto' ) : 'single';
	}
	/**
	 * Check transport limits at preparation and again before publication.
	 *
	 * @param array           $export Request-owned generated artifacts.
	 * @param Resource_Budget $budget Shared request memory and time budget.
	 * @param array|null      $memory_capacity Output-only size diagnostics, also retained on a rejected plan.
	 * @return array
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	public static function plan( array $export, Resource_Budget $budget, ?array &$memory_capacity = null ): array {
		$memory_capacity = null;
		if ( Publication_Runtime::memory() ) {
			if ( ! empty( $export['bundle'] ) ) {
				throw new Failure( 'MEMORY_SINGLE_REQUIRED', 409 );
			}
			Publication_Runtime::check_size( $export['wxr']->size() + strlen( $export['base'] ) );
			$memory_capacity = self::memory_capacity( $export );
			Publication_Runtime::check_size( $memory_capacity['wxr_request_bytes'] );
			Publication_Runtime::check_size( $memory_capacity['base_request_bytes'] );
		}
		if ( ! empty( $export['bundle'] ) ) {
			$bundle = $export['bundle'];
			foreach ( $bundle->parts as $part ) {
				$budget->check( max( $part['bytes'], $part['request_bytes'] ) );
			}
			$budget->check( Wxr_Bundle::request_size( $export['base'] ) );
			$budget->check( Wxr_Bundle::request_size( $bundle->manifest ) );
			return array(
				'format'     => 'bundle',
				'part_count' => count( $bundle->parts ),
				'digest'     => $bundle->digest,
			);
		}
		if ( $export['wxr']->size() > 100 * MB_IN_BYTES || strlen( $export['base'] ) > 100 * MB_IN_BYTES ) {
			throw new Failure( 'WXR_SPLIT_REQUIRED', 413 );
		}
		try {
			$budget->check( $memory_capacity['wxr_request_bytes'] ?? $export['wxr']->request_size() );
			$budget->check( $memory_capacity['base_request_bytes'] ?? Wxr_Bundle::request_size( $export['base'] ) );
		} catch ( Failure $error ) {
			if ( 'MEMORY_LIMIT_EXCEEDED' === $error->reason ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Failure retains only allowlisted numeric diagnostics; AJAX encodes them.
				throw new Failure( 'WXR_SINGLE_MEMORY_LIMIT', 413, $error->details );
			}
			throw $error;
		}
		return array(
			'format'     => 'single',
			'part_count' => 1,
			'digest'     => hash( 'sha256', $export['wxr']->blob_sha() . GitHub::blob_sha( $export['base'] ) ),
		);
	}

	/**
	 * Measure memory-mode size limits without materializing encoded upload bodies.
	 * These diagnostics are not approval; every publication regenerates and checks its plan.
	 *
	 * @param array $export Request-owned single XML and build settings.
	 * @return array
	 */
	private static function memory_capacity( array $export ): array {
		return array(
			'limit_bytes'        => Publication_Runtime::MAX_BYTES,
			'raw_bytes'          => $export['wxr']->size() + strlen( $export['base'] ),
			'wxr_request_bytes'  => $export['wxr']->request_size(),
			'base_request_bytes' => Wxr_Bundle::request_size( $export['base'] ),
		);
	}
	/**
	 * Close every request-owned artifact.
	 *
	 * @param array $export Request-owned generated artifacts.
	 * @return void
	 */
	public static function close_export( array $export ): void {
		( $export['wxr'] ?? null )?->close();
		( $export['bundle'] ?? null )?->close();
	}
}
