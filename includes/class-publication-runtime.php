<?php
/** Server-selected publication backend and bounded memory exports.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** A storage failure never selects another backend. */
final class Publication_Runtime {

	const MAX_BYTES = 8 * 1024 * 1024;

	/** Backend and settings pinned for one operation, including nested calls.
	 *
	 * @var array|null
	 */
	private static ?array $operation = null;

	/** Resolve host policy without changing the saved preference.
	 *
	 * @param array|null $settings Saved settings or a validated draft.
	 */
	public static function describe( ?array $settings = null ): array {
		if ( Private_Filesystem::windows() ) {
			return array(
				'backend' => 'memory',
				'fixed'   => 'windows',
			);
		}
		if ( (bool) apply_filters( 'zeropress_static_memory_publication', false ) ) {
			return array(
				'backend' => 'memory',
				'fixed'   => 'host',
			);
		}
		$settings ??= Settings::read();
		return array(
			'backend' => $settings['publication_backend'] ?? 'files',
			'fixed'   => '',
		);
	}

	/** Whether exports use bounded PHP memory. */
	public static function memory(): bool {
		return 'memory' === self::backend();
	}

	/** Backend identity sealed into approvals and durable jobs. */
	public static function backend(): string {
		return ( self::$operation ?? self::describe() )['backend'];
	}

	/** Whether an operation already owns a stable runtime context. */
	public static function active(): bool {
		return null !== self::$operation;
	}

	/** Keep all artifacts in one operation on the same backend.
	 *
	 * @param callable $work Operation, including nested exports and HTTP calls.
	 * @return mixed Operation result.
	 */
	public static function run( callable $work ) {
		if ( self::active() ) {
			return $work();
		}
		$settings        = Settings::fresh();
		self::$operation = self::describe( $settings ) + array( 'revision' => $settings['revision'] );
		try {
			return $work();
		} finally {
			self::$operation = null;
		}
	}

	/** Reject a stale operation before issuing approval or starting publication.
	 *
	 * @throws Failure If settings or host policy changed during the operation.
	 */
	public static function assert_current(): void {
		if ( ! self::active() ) {
			return;
		}
		$settings = Settings::fresh();
		if ( $settings['revision'] !== self::$operation['revision'] || self::describe( $settings )['backend'] !== self::$operation['backend'] ) {
			throw new Failure( 'SETTINGS_CHANGED', 409 );
		}
	}

	/** Reject before allocating or serializing beyond the memory-path contract.
	 *
	 * @param int $bytes Exact bytes, not a post-count estimate.
	 * @throws Failure If the memory export limit is exceeded.
	 */
	public static function check_size( int $bytes ): void {
		if ( $bytes > self::MAX_BYTES ) {
			throw new Failure( 'MEMORY_EXPORT_TOO_LARGE', 413 );
		}
	}
}
