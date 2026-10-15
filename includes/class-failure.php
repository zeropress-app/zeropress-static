<?php
/** Controlled errors without credentials or remote response bodies.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** A user-facing failure code. */
final class Failure extends \RuntimeException {
	/**
	 * Reason.
	 *
	 * @var string
	 */
	public string $reason;
	/**
	 * Http status.
	 *
	 * @var int
	 */
	public int $http_status;
	/**
	 * Allowlisted numeric resource diagnostics.
	 *
	 * @var array
	 */
	public array $details;
	/**
	 * Construct a safe failure.
	 *
	 * @param string $reason Stable non-sensitive error code.
	 * @param int    $http_status HTTP response status.
	 * @param array  $details Optional numeric resource diagnostics.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function __construct( string $reason, int $http_status = 400, array $details = array() ) {
		parent::__construct( $reason );
		$this->reason      = $reason;
		$this->http_status = $http_status;
		$this->details     = array_filter(
			array_intersect_key( $details, array_flip( array( 'memory_limit_bytes', 'estimated_memory_bytes', 'time_limit_seconds', 'time_budget_seconds', 'elapsed_seconds' ) ) ),
			static fn( $value ) => ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) && $value >= 0
		);
	}
}
