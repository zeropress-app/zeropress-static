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
		$budget     ??= Resource_Budget::from_request();
		$settings     = Settings::read();
		$generated_at = time();
		$export       = ( new Exporter( $budget ) )->generate_files( $settings, $generated_at );
		// Preparation retains only review metadata while inspecting the remote target.
		$export['wxr']->close();
		unset( $export['wxr'], $export['base'] );
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
		$receipt = array(
			'user'         => get_current_user_id(),
			'revision'     => $settings['revision'],
			'hash'         => $export['data_hash'],
			'expires'      => time() + 600,
			'generated_at' => $generated_at,
			'snapshot'     => $snapshot,
		);
		$payload = base64_encode( wp_json_encode( $receipt ) );
		$result  = array(
			'receipt'          => $payload . '.' . hash_hmac( 'sha256', $payload, Settings::key( 'preparation' ) ),
			'counts'           => $export['counts'],
			'bytes'            => $export['bytes'],
			'rewritten_values' => $export['rewritten_values'],
			'warnings'         => $export['warnings'],
			'target'           => $snapshot ? array(
				'repository' => $snapshot['repository'],
				'branch'     => $snapshot['branch'],
			) : null,
			'state'            => $snapshot ? ( $snapshot['data_hash'] === $export['data_hash'] ? 'unchanged' : 'ready' ) : 'download_only',
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
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function validate( string $receipt, Resource_Budget $budget, bool $refresh_time = false ): array {
		$budget->check();
		Export_Storage::recover_once( $budget );
		if ( strlen( $receipt ) > 20000 || 1 !== substr_count( $receipt, '.' ) ) {
			throw new Failure( 'PREPARATION_INVALID', 409 );
		}
		list( $payload, $signature ) = explode( '.', $receipt, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, Settings::key( 'preparation' ) ), $signature ) ) {
			throw new Failure( 'PREPARATION_INVALID', 409 );
		}
		$value    = json_decode( (string) base64_decode( $payload, true ), true );
		$settings = Settings::read();
		if ( ! is_array( $value ) || ( $value['user'] ?? null ) !== get_current_user_id() ||
			( $value['expires'] ?? 0 ) < time() || ( $value['revision'] ?? '' ) !== $settings['revision'] ) {
			throw new Failure( 'PREPARATION_EXPIRED', 409 );
		}
		$export = ( new Exporter( $budget ) )->generate_files( $settings, $refresh_time ? time() : $value['generated_at'] );
		if ( $export['data_hash'] !== $value['hash'] ) {
			$export['wxr']->close();
			throw new Failure( 'CONTENT_CHANGED', 409 );
		}
		return array( $settings, $value, $export );
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
		$budget ??= Resource_Budget::from_request();
		Export_Storage::recover_once( $budget );
		if ( ! isset( GitHub::FILES[ $file ] ) ) {
			throw new Failure( 'INVALID_FILE' );
		}
		list( , , $export ) = self::validate( $receipt, $budget );
		if ( 'wxr' === $file ) {
			return $export['wxr'];
		}
		$export['wxr']->close();
		$content = new Export_File( $budget );
		try {
			$content->write( $export['base'] );
			return $content;
		} catch ( \Throwable $error ) {
			$content->close();
			throw $error;
		}
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
	 */
	public static function publish( string $receipt, bool $republish = false, ?Resource_Budget $budget = null, ?Publication_Progress $progress = null ): array {
		$budget ??= Resource_Budget::from_request();
		Export_Storage::recover_once( $budget );
		try {
			$progress?->update( 'validating' );
			list( $settings, $prepared, $export ) = self::validate( $receipt, $budget, $republish );
			if ( empty( $prepared['snapshot'] ) ) {
				throw new Failure( 'CONNECTION_REQUIRED' );
			}
			if ( $republish && $prepared['snapshot']['data_hash'] !== $export['data_hash'] ) {
				throw new Failure( 'PREPARATION_INVALID', 409 );
			}
			$progress?->update( 'inspecting' );
			$github  = new GitHub( $settings, $budget );
			$current = $github->inspect( $prepared['snapshot']['branch'] );
			if ( $current['repository'] !== $prepared['snapshot']['repository'] || $current['files'] !== $prepared['snapshot']['files'] ) {
				throw new Failure( 'TARGET_CHANGED', 409 );
			}
			if ( ! $republish && $current['data_hash'] === $export['data_hash'] ) {
				return array( 'state' => 'unchanged' );
			}
			if ( $republish && $export['wxr']->blob_sha() === $current['files']['wxr'] ) {
				$progress?->update( 'validating' );
				// Release the previous bodies before generating their replacements.
				$export['wxr']->close();
				unset( $export );
				// WXR dates have second precision. Wait once so an immediate repeat changes the file too.
				$budget->check();
				usleep( 1000000 );
				list( , , $export ) = self::validate( $receipt, $budget, true );
			}
			// Include unrelated intervening commits by publishing on the current verified tree.
			return $github->publish( $current, $export, $republish, $progress );
		} finally {
			if ( isset( $export['wxr'] ) ) {
				$export['wxr']->close();
			}
			$progress?->clear();
		}
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
