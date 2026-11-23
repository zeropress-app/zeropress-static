<?php
/**
 * Attachment output isolated from the AJAX JSON response boundary.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Send only the completed private file; the browser verifies receipt byte counts. */
final class Download_Response {

	/**
	 * Emit bounded chunks and always release their existing owner lock.
	 * The caller must exit after this method, including interrupted transfers.
	 *
	 * @param Export_File $content Validated, completely generated attachment.
	 * @param string      $file Allowlisted logical file name.
	 * @throws Failure If validation or the original resource budget fails before output.
	 * @throws \Throwable Before any attachment bytes have been attempted.
	 */
	public static function send( Export_File $content, string $file ): void {
		$started = false;
		$bytes   = 0;
		try {
			if ( ! isset( GitHub::FILES[ $file ] ) ) {
				throw new Failure( 'INVALID_REQUEST' );
			}
			if ( headers_sent() ) {
				throw new Failure( 'DOWNLOAD_INCOMPLETE', 500 );
			}
			$preserves_bytes = in_array( strtolower( (string) ini_get( 'zlib.output_compression' ) ), array( '', '0', 'off' ), true );
			foreach ( ob_get_status( true ) as $buffer ) {
				if ( $buffer['buffer_used'] > 0 ) {
					throw new Failure( 'DOWNLOAD_INCOMPLETE', 500 );
				}
				$preserves_bytes = $preserves_bytes && 'default output handler' === $buffer['name'];
			}
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Content-Encoding:' ) ) {
					$preserves_bytes = false;
				}
			}
			// Never retain a length calculated for an earlier or transformed response.
			header_remove( 'Content-Length' );
			header( 'Content-Type: application/octet-stream' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Disposition: attachment; filename="' . GitHub::FILES[ $file ] . '"' );
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, no-transform' );
			if ( $preserves_bytes ) {
				header( 'Content-Length: ' . $content->size() );
			}
			foreach ( $content->chunks() as $chunk ) {
				// chunks() checks the original request budget before every bounded read.
				$started = true;
				$bytes  += strlen( $chunk );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Preserve attachment bytes.
				echo $chunk;
			}
		} catch ( \Throwable $error ) {
			if ( ! $started ) {
				if ( ! headers_sent() ) {
					header_remove( 'Content-Length' );
					header_remove( 'Content-Disposition' );
					header_remove( 'Content-Type' );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Existing JSON boundary handles the error.
				throw $error;
			}
			$reason = $error instanceof Failure && in_array( $error->reason, array( 'REQUEST_TIME_LIMIT_EXCEEDED', 'MEMORY_LIMIT_EXCEEDED', 'EXPORT_STORAGE_UNAVAILABLE' ), true ) ? $error->reason : 'INTERNAL_ERROR';
			// No error document may be appended, even when PHP has buffered earlier bytes.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only allowlisted codes and an integer; no content or credentials.
			error_log( '[ZeroPress Static] DOWNLOAD_INCOMPLETE ' . $reason . ' bytes_attempted=' . $bytes );
		} finally {
			$content->close();
		}
	}
}
