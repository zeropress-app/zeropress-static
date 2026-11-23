<?php
/** Deterministic whole-record part planning with existing size bounds.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Retains at most the bounded part inventory, never record bodies. */
final class Wxr_Part_Plan {

	const ANCHOR_BITS = 10;
	/**
	 * Shared request limits.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/**
	 * Empty document bytes.
	 *
	 * @var int
	 */
	private int $header_raw;
	/**
	 * Empty document encoded request bytes.
	 *
	 * @var int
	 */
	private int $header_wire;
	/**
	 * Whether identity and section boundaries apply.
	 *
	 * @var bool
	 */
	private bool $stable;
	/**
	 * Fixed hash selection threshold.
	 *
	 * @var int
	 */
	private int $threshold;
	/**
	 * Current document bytes.
	 *
	 * @var int
	 */
	private int $raw;
	/**
	 * Current request bytes.
	 *
	 * @var int
	 */
	private int $wire;
	/**
	 * Current per-kind counts.
	 *
	 * @var array
	 */
	private array $counts;
	/**
	 * Records in this plan.
	 *
	 * @var int
	 */
	private int $ordinal = 0;
	/**
	 * Last nonempty section.
	 *
	 * @var int
	 */
	private int $section = 0;
	/**
	 * Bounded completed part descriptors.
	 *
	 * @var array
	 */
	private array $parts = array();
	/**
	 * Candidate exceeded the unchanged part limit.
	 *
	 * @var bool
	 */
	private bool $overflow = false;
	/**
	 * Plan has been sealed.
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Plan either anchored or legacy packing from the same ordered descriptors.
	 *
	 * @param Resource_Budget $budget Shared request limits.
	 * @param string          $prefix Shared XML header.
	 * @param string          $suffix Closing XML tags.
	 * @param bool            $stable Add identity/section cuts, or retain exact legacy packing.
	 * @param int             $bits Fixed internal anchor rate; comparison candidates are 8..10.
	 * @throws Failure If the header or internal configuration is invalid.
	 */
	public function __construct( Resource_Budget $budget, string $prefix, string $suffix, bool $stable = true, int $bits = self::ANCHOR_BITS ) {
		if ( $bits < 8 || $bits > 10 ) {
			throw new Failure( 'INTERNAL_ERROR', 500 );
		}
		$this->budget      = $budget;
		$this->header_raw  = strlen( $prefix ) + strlen( $suffix );
		$this->header_wire = Wxr_Bundle::request_size( $prefix . $suffix );
		if ( $this->header_raw > Wxr_Bundle::MAX_FILE || $this->header_wire > Wxr_Bundle::MAX_FILE ) {
			throw new Failure( 'WXR_RECORD_TOO_LARGE', 413 );
		}
		$this->stable    = $stable;
		$this->threshold = 1 << ( 16 - $bits );
		$this->reset_part();
	}

	/** Reset packing state, without emitting an empty part. */
	private function reset_part(): void {
		$this->raw    = $this->header_raw;
		$this->wire   = $this->header_wire;
		$this->counts = array_fill_keys( array( 'authors', 'categories', 'tags', 'terms', 'items' ), 0 );
	}

	/** Keep bounded state even when this candidate must be abandoned. */
	private function finish_part(): void {
		if ( count( $this->parts ) >= Wxr_Bundle::MAX_PARTS ) {
			$this->overflow = true;
			$this->parts    = array();
			return;
		}
		$this->parts[] = array(
			'end'           => $this->ordinal,
			'bytes'         => $this->raw,
			'request_bytes' => $this->wire,
			'records'       => $this->counts,
		);
	}

	/**
	 * Apply cuts before the next complete record, with no minimum anchor fill.
	 *
	 * @param array $record Checked record descriptor.
	 * @throws Failure If a size or resource protection fails.
	 */
	public function record( array $record ): void {
		$this->budget->check();
		if ( $this->finished ) {
			throw new Failure( 'INTERNAL_ERROR', 500 );
		}
		if ( $this->header_raw + $record['raw'] > Wxr_Bundle::MAX_FILE || $this->header_wire + $record['wire'] > Wxr_Bundle::MAX_FILE ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only allowlisted numeric diagnostics.
			throw new Failure(
				'WXR_RECORD_TOO_LARGE',
				413,
				array(
					'file_bytes'       => $record['raw'],
					'file_limit_bytes' => Wxr_Bundle::MAX_FILE,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( $this->overflow ) {
			return;
		}
		$boundary = $this->stable && ( $record['section'] !== $this->section || $record['anchor'] < $this->threshold );
		if ( array_sum( $this->counts ) && ( $boundary || $this->raw + $record['raw'] > Wxr_Bundle::TARGET || $this->wire + $record['wire'] > Wxr_Bundle::MAX_FILE ) ) {
			$this->finish_part();
			if ( $this->overflow ) {
				return;
			}
			$this->reset_part();
		}
		$this->raw  += $record['raw'];
		$this->wire += $record['wire'];
		++$this->counts[ $record['kind'] ];
		++$this->ordinal;
		$this->section = $record['section'];
	}

	/** Return a complete plan, or null if this candidate exceeded the cap. */
	public function finish(): ?array {
		if ( ! $this->finished && ! $this->overflow ) {
			$this->finish_part();
		}
		$this->finished = true;
		return $this->overflow ? null : $this->parts;
	}
}
