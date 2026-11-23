<?php
/** UTF-8 publication policy; never changes saved WordPress content.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Per-document body checks and bounded preparation diagnostics. */
final class Content_Body {

	const WARNING_BYTES = 262144;
	const LIMIT_BYTES   = 4194304;

	/**
	 * Number of large documents.
	 *
	 * @var int
	 */
	private int $count = 0;
	/**
	 * Largest accepted body in bytes.
	 *
	 * @var int
	 */
	private int $maximum = 0;
	/**
	 * Bounded public post IDs.
	 *
	 * @var array
	 */
	private array $examples = array();

	/**
	 * Check bytes before reading or serializing a complete body.
	 *
	 * @param int $bytes UTF-8 body bytes.
	 * @param int $id Public post ID.
	 * @throws Failure If the body exceeds the limit.
	 */
	public static function check( int $bytes, int $id ): void {
		if ( $bytes > self::LIMIT_BYTES ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Numeric diagnostics are JSON encoded by the caller.
			throw new Failure(
				'CONTENT_BODY_TOO_LARGE',
				413,
				array(
					'post_id'          => $id,
					'body_bytes'       => $bytes,
					'body_limit_bytes' => self::LIMIT_BYTES,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Record once per eligible document, using its largest representation.
	 *
	 * @param int $bytes UTF-8 body bytes.
	 * @param int $id Public post ID.
	 */
	public function record( int $bytes, int $id ): void {
		self::check( $bytes, $id );
		if ( $bytes < self::WARNING_BYTES ) {
			return;
		}
		++$this->count;
		$this->maximum = max( $this->maximum, $bytes );
		if ( count( $this->examples ) < 20 ) {
			$this->examples[] = $id;
		}
	}

	/** No bodies/titles/paths enter diagnostics or signed content hashes. */
	public function warnings(): array {
		return $this->count ? array(
			array(
				'code'             => 'CONTENT_BODY_LARGE',
				'count'            => $this->count,
				'max_body_bytes'   => $this->maximum,
				'affected'         => $this->examples,
				'omitted_examples' => $this->count - count( $this->examples ),
			),
		) : array();
	}
}
