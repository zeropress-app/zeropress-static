<?php
/**
 * Short-lived, administrator-scoped publication stage feedback.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Advisory status only; the original publish response determines the outcome. */
final class Publication_Progress {

	const PREFIX = 'zpsp_publish_';
	const STAGES = array( 'validating', 'inspecting', 'uploading', 'committing', 'verifying' );

	/**
	 * Transient key for this administrator and publication attempt.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Bind an attempt identifier to the current authenticated user.
	 *
	 * @param string $id Random identifier created for this publication attempt.
	 * @throws Failure If the attempt identifier is invalid.
	 */
	public function __construct( string $id ) {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) {
			throw new Failure( 'INVALID_REQUEST' );
		}
		$this->key = self::PREFIX . get_current_user_id() . '_' . $id;
	}

	/**
	 * Record a stage when processing enters it, without affecting publication.
	 *
	 * @param string $stage Allowlisted stage identifier.
	 */
	public function update( string $stage ): void {
		if ( ! in_array( $stage, self::STAGES, true ) ) {
			return;
		}
		try {
			set_transient( $this->key, $stage, 10 * MINUTE_IN_SECONDS );
		} catch ( \Throwable $ignored ) {
			// Status storage is optional and must never interrupt the publishing request.
			return;
		}
	}

	/** Read only an allowlisted stage; missing or unavailable status is normal. */
	public function read(): ?string {
		try {
			$stage = get_transient( $this->key );
			return in_array( $stage, self::STAGES, true ) ? $stage : null;
		} catch ( \Throwable $ignored ) {
			return null;
		}
	}

	/** Remove status after any terminal outcome; interrupted requests expire. */
	public function clear(): void {
		try {
			delete_transient( $this->key );
		} catch ( \Throwable $ignored ) {
			// The transient still expires if the cache cannot remove it now.
			return;
		}
	}
}
