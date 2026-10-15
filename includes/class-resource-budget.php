<?php
/**
 * Server-derived resource checks shared by one publishing request.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Cooperative checks; hosting limits may still terminate a request externally. */
final class Resource_Budget {

	/**
	 * Actual PHP memory limit in bytes, or -1 for unlimited.
	 *
	 * @var int
	 */
	private int $memory_limit;
	/**
	 * PHP execution-time setting in seconds.
	 *
	 * @var int
	 */
	private int $time_limit;
	/**
	 * Request start, including WordPress bootstrap.
	 *
	 * @var float
	 */
	private float $started;
	/**
	 * Clock used for elapsed wall time.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;
	/**
	 * Current allocated PHP memory.
	 *
	 * @var \Closure
	 */
	private \Closure $memory_usage;

	/**
	 * Create a budget from measured server settings.
	 *
	 * @param int           $memory_limit Memory limit in bytes.
	 * @param int           $time_limit Execution-time setting in seconds.
	 * @param float         $started Request start time.
	 * @param \Closure|null $clock Clock, optionally supplied by a test.
	 * @param \Closure|null $memory_usage Memory reader, optionally supplied by a test.
	 */
	public function __construct( int $memory_limit, int $time_limit, float $started, ?\Closure $clock = null, ?\Closure $memory_usage = null ) {
		$this->memory_limit = $memory_limit;
		$this->time_limit   = $time_limit;
		$this->started      = $started;
		$this->clock        = $clock ?? static fn() => microtime( true );
		$this->memory_usage = $memory_usage ?? static fn() => memory_get_usage( true );
	}

	/**
	 * Read effective limits; only authorized export actions request admin memory.
	 *
	 * @param bool $raise_memory Whether to request WordPress's admin memory allowance.
	 * @return self
	 */
	public static function from_request( bool $raise_memory = false ): self {
		if ( $raise_memory ) {
			wp_raise_memory_limit( 'admin' );
		}
		return new self(
			wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ),
			(int) ini_get( 'max_execution_time' ),
			isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true )
		);
	}

	/**
	 * Check time and conservative headroom before allocation or serialization.
	 *
	 * @param int $bytes Data about to be processed, in bytes.
	 * @throws Failure If the effective resource budget is exhausted.
	 */
	public function check( int $bytes = 0 ): void {
		$this->remaining();
		if ( $this->memory_limit > 0 ) {
			// Headroom for copies, XML/JSON encoding and the error response, not a file-size cap.
			$estimated = ( $this->memory_usage )() + $bytes * 6 + 16 * MB_IN_BYTES;
			if ( $estimated > $this->memory_limit ) {
				// Numeric diagnostics are encoded as JSON at the administrator endpoint.
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new Failure(
					'MEMORY_LIMIT_EXCEEDED',
					503,
					array(
						'memory_limit_bytes'     => $this->memory_limit,
						'estimated_memory_bytes' => $estimated,
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
	}

	/**
	 * Return remaining wall time, or null when PHP has no execution-time limit.
	 *
	 * @return float|null
	 * @throws Failure If the request has reached its soft deadline.
	 */
	private function remaining(): ?float {
		if ( $this->time_limit <= 0 ) {
			return null;
		}
		$budget  = max( 0, $this->time_limit - 5 );
		$elapsed = max( 0, ( $this->clock )() - $this->started );
		if ( $elapsed >= $budget ) {
			// Numeric diagnostics are encoded as JSON at the administrator endpoint.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Failure(
				'REQUEST_TIME_LIMIT_EXCEEDED',
				503,
				array(
					'time_limit_seconds'  => $this->time_limit,
					'time_budget_seconds' => $budget,
					'elapsed_seconds'     => round( $elapsed, 2 ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $budget - $elapsed;
	}

	/**
	 * Resolve a finite HTTP timeout immediately before sending a GitHub request.
	 *
	 * @return float
	 * @throws Failure If no request time remains.
	 */
	public function github_timeout(): float {
		$default = $this->remaining() ?? 30.0;
		/**
		 * Filters the timeout for one GitHub HTTP request, in seconds.
		 *
		 * A positive finite number is required. The request's remaining time always
		 * takes precedence. With unlimited PHP time the default is 30 seconds.
		 *
		 * @param float $timeout Default timeout in seconds.
		 */
		$timeout = apply_filters( 'zeropress_static_github_request_timeout', $default );
		if ( ! ( is_int( $timeout ) || is_float( $timeout ) ) || ! is_finite( (float) $timeout ) || $timeout <= 0 ) {
			$timeout = $default;
		}
		$remaining = $this->remaining();
		return null === $remaining ? (float) $timeout : min( (float) $timeout, $remaining );
	}
}
