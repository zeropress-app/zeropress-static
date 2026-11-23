<?php
/** Connection-owned exclusion without private files or external processes.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

// Lock ownership and CAS must use the authoritative connection, never object caches.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/** Reconnection invalidates this worker; elapsed time never transfers ownership. */
final class Memory_Lock {
	/** Site-specific advisory lock name.
	 *
	 * @var string
	 */
	private string $name;
	/** Original database connection ID.
	 *
	 * @var int
	 */
	private int $connection;
	/** Whether acquisition succeeded.
	 *
	 * @var bool
	 */
	private bool $acquired = false;
	/** Reject recursive acquisition by a second operation in the same worker.
	 *
	 * @var \WeakReference|null
	 */
	private static ?\WeakReference $owner = null;

	/** Acquire once and without waiting; use only a normal, nonpersistent wpdb connection.
	 *
	 * @throws Failure If the database cannot provide exclusive ownership.
	 */
	public function __construct() {
		global $wpdb;
		if ( self::$owner && self::$owner->get() ) {
			throw new Failure( 'PUBLICATION_BUSY', 409 );
		}
		if ( 'wpdb' !== get_class( $wpdb ) || ( defined( 'DB_HOST' ) && str_starts_with( DB_HOST, 'p:' ) ) ) {
			throw new Failure( 'MEMORY_LOCK_UNAVAILABLE', 503 );
		}
		$this->name = 'zpsp-memory-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 48 );
		$id         = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( $wpdb->last_error || ! is_numeric( $id ) || (int) $id <= 0 ) {
			throw new Failure( 'MEMORY_LOCK_UNAVAILABLE', 503 );
		}
		$this->connection = (int) $id;
		$held             = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d, GET_LOCK(%s, 0), NULL)', $this->connection, $this->name ) );
		if ( '1' !== (string) $held ) {
			throw new Failure( '0' === (string) $held ? 'PUBLICATION_BUSY' : 'MEMORY_LOCK_UNAVAILABLE', '0' === (string) $held ? 409 : 503 );
		}
		$this->acquired = true;
		self::$owner    = \WeakReference::create( $this );
		$this->check();
	}

	/** Read-only liveness probe. A DB error is never proof that a worker exited.
	 *
	 * @return bool Whether a connection owns this site's operation lock.
	 * @throws Failure If ownership cannot be read.
	 */
	public static function busy(): bool {
		global $wpdb;
		$name = 'zpsp-memory-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 48 );
		$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
		if ( $wpdb->last_error ) {
			throw new Failure( 'MEMORY_LOCK_UNAVAILABLE', 503 );
		}
		return null !== $id;
	}

	/** SQL predicate travels with each write, including wpdb's reconnect/retry.
	 *
	 * @return string Server-owned, prepared SQL expression.
	 */
	public function predicate(): string {
		global $wpdb;
		return $wpdb->prepare( '(CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d)', $this->connection, $this->name, $this->connection );
	}

	/** Check before transport and state changes; never reacquire after loss.
	 *
	 * @throws Failure If this connection no longer owns the lock.
	 */
	public function check(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- predicate() prepares the server-owned connection ID and lock name.
		$value = $wpdb->get_var( 'SELECT ' . $this->predicate() );
		if ( ! $this->acquired || $wpdb->last_error || '1' !== (string) $value ) {
			throw new Failure( 'MEMORY_LOCK_LOST', 503 );
		}
	}

	/** Release our named lock only on the original connection. */
	public function __destruct() {
		if ( ! $this->acquired ) {
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d, RELEASE_LOCK(%s), NULL)', $this->connection, $this->name ) );
		$this->acquired = false;
	}
}
