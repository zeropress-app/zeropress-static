<?php
/** Bounded publication requests with durable recovery.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Coordinates private artifacts and one possible final reference dispatch. */
final class Publication_Session {
	/** Fresh, bounded database state.
	 *
	 * @var Publication_Store
	 */
	private Publication_Store $store;
	/** One original request budget, never reset.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/** Active job bound to this worker.
	 *
	 * @var string
	 */
	private string $id = '';

	/**
	 * Initialize without performing remote work or creating files.
	 *
	 * @param Resource_Budget $budget Original request memory and time guard.
	 */
	public function __construct( ?Resource_Budget $budget = null ) {
		$this->store  = new Publication_Store();
		$this->budget = $budget ?? Resource_Budget::from_request();
	}

	/**
	 * Validate opaque request IDs before they become storage keys.
	 *
	 * @param mixed $value Value to validate or stream.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private static function identifier( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $value ) ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
		return $value;
	}

	/** Enforce the same administrator boundary for PHP callers and AJAX.
	 *
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private static function authorize(): void {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			throw new Failure( 'FORBIDDEN', 403 );
		}
	}

	/**
	 * Return only safe progress; status does not resume a job or renew approval.
	 *
	 * @param string $id Opaque server-issued job identifier.
	 * @param string $request_id Optional originating request ID for a lost response.
	 */
	public function status( string $id = '', string $request_id = '' ): array {
		self::authorize();
		$this->store->reload();
		if ( '' !== $id ) {
			self::identifier( $id );
		}
		if ( '' !== $request_id ) {
			self::identifier( $request_id );
			foreach ( array_reverse( $this->store->data['history'] ) as $entry ) {
				if ( $entry['request_id'] === $request_id && get_current_user_id() === $entry['owner'] ) {
					return $entry['result'] + array( 'revision' => $this->store->data['revision'] );
				}
			}
		}
		$job = $this->store->data['job'];
		if ( $job && ( '' === $id || $id === $job['id'] ) ) {
			if ( get_current_user_id() !== $job['owner'] ) {
				return array( 'state' => 'busy' );
			}
			$state       = ! empty( $job['intent'] ) ? 'unknown' : ( ! empty( $job['cancel_requested'] ) ? 'cancel_pending' : ( time() > $job['expires'] ? 'expired' : ( ! empty( $job['paused'] ) ? 'paused' : 'active' ) ) );
			$interrupted = 'memory' === ( $job['backend'] ?? '' ) && empty( $job['intent'] ) && 'active' === $state && ! Memory_Lock::busy();
			if ( $interrupted ) {
				$state = 'paused';
			}
			$artifacts = $job['artifacts'] ?? array();
			$confirmed = $job['confirmed'] ?? array();
			$result    = array(
				'backend'               => $job['backend'] ?? 'files',
				'state'                 => $state,
				'job_id'                => $job['id'],
				'revision'              => $this->store->data['revision'],
				'phase'                 => $job['phase'],
				'expires'               => $job['expires'],
				'target'                => $job['target'],
				'completed'             => count( $confirmed ),
				'total'                 => count( $artifacts ),
				'verification_complete' => ! empty( $job['verify_complete'] ),
				'next_after_ms'         => (int) ceil( max( 0, $this->store->data['not_before'] - microtime( true ) ) * 1000 ),
				'next_operation'        => ! empty( $job['intent'] ) ? 'publication_verify' : ( 'ready' === $job['phase'] ? 'publication_finalize' : 'publication_advance' ),
				'can_continue'          => empty( $job['legacy'] ) && 'capturing' !== $job['phase'] && in_array( $state, array( 'active', 'paused' ), true ),
			);
			if ( $interrupted ) {
				$result['code'] = 'MEMORY_PUBLICATION_INTERRUPTED';
			}
			if ( ! empty( $job['error'] ) ) {
				$result['code']    = $job['error'];
				$result['details'] = $job['details'] ?? array();
			}
			if ( ! empty( $job['intent'] ) ) {
				$result['commit_url'] = $this->commit_url( $job, $job['intent']['candidate'] );
			}
			return $result;
		}
		foreach ( array_reverse( $this->store->data['history'] ) as $entry ) {
			if ( $entry['id'] === $id && get_current_user_id() === $entry['owner'] ) {
				return $entry['result'] + array( 'revision' => $this->store->data['revision'] );
			}
		}
		return array( 'state' => 'idle' );
	}

	/**
	 * Request cancellation without unlinking files held by a live worker.
	 *
	 * @param string $id Opaque server-issued job identifier.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function cancel( string $id ): array {
		self::authorize();
		$id = self::identifier( $id );
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$this->store->reload();
			$job = $this->store->data['job'];
			if ( ! $job || $job['id'] !== $id ) {
				return $this->status( $id );
			}
			$this->owner( $job );
			Export_Storage::recover_once( $this->budget );
			if ( ! empty( $job['intent'] ) ) {
				return $this->status( $id );
			}
			$this->store->data['job']['cancel_requested'] = true;
			try {
				$this->store->save();
				break;
			} catch ( Failure $error ) {
				if ( 'PUBLICATION_CONFLICT' !== $error->reason ) {
					throw $error;
				}
			}
		}
		try {
			$this->store->acquire();
			if ( ( $this->store->data['job']['id'] ?? '' ) === $id && empty( $this->store->data['job']['intent'] ) ) {
				return $this->store->finish( 'cancelled' );
			}
		} catch ( Failure $error ) {
			if ( ! in_array( $error->reason, array( 'PUBLICATION_BUSY', 'PUBLICATION_STORAGE_UNAVAILABLE' ), true ) ) {
				throw $error;
			}
			// The durable cancellation prevents any subsequent remote mutation,
			// even if the private directory disappeared. Cleanup can be retried.
			if ( 'PUBLICATION_STORAGE_UNAVAILABLE' === $error->reason ) {
				$this->store->reload();
				if ( ( $this->store->data['job']['id'] ?? '' ) === $id && empty( $this->store->data['job']['intent'] ) ) {
					return $this->store->finish( 'cancelled' );
				}
			}
		}
		return $this->status( $id );
	}

	/**
	 * Start only from a currently valid signed receipt and explicit publication choice.
	 *
	 * @param string $receipt Signed, expiring preparation approval.
	 * @param bool   $republish Whether unchanged data was explicitly approved for republication.
	 * @param string $request_id Client idempotency identifier.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 * @throws \Throwable If an unexpected error interrupts the request.
	 */
	public function start( string $receipt, bool $republish, string $request_id ): array {
		if ( ! Publication_Runtime::active() ) {
			return Publication_Runtime::run( fn() => $this->start( $receipt, $republish, $request_id ) );
		}
		self::authorize();
		self::identifier( $request_id );
		$fingerprint = hash( 'sha256', $receipt . ( $republish ? ':republish' : ':publish' ) );
		$this->store->acquire();
		$this->prune();
		foreach ( $this->store->data['history'] as $entry ) {
			if ( $entry['request_id'] === $request_id && get_current_user_id() === $entry['owner'] ) {
				if ( $entry['fingerprint'] !== $fingerprint ) {
					throw new Failure( 'PUBLICATION_INVALID', 409 );
				}
				return $entry['result'];
			}
		}
		list( $settings, $prepared ) = Publisher::receipt( $receipt );
		if ( empty( $prepared['snapshot'] ) ) {
			throw new Failure( 'CONNECTION_REQUIRED' );
		}
		$job = $this->store->data['job'];
		if ( $job ) {
			$this->owner( $job );
			if ( $job['request_id'] !== $request_id || $job['fingerprint'] !== $fingerprint ) {
				throw new Failure( 'PUBLICATION_ACTIVE', 409 );
			}
			if ( 'memory' === ( $job['backend'] ?? '' ) ) {
				if ( empty( $job['intent'] ) ) {
					return $this->store->finish( 'failed', array( 'code' => 'MEMORY_PUBLICATION_INTERRUPTED' ) );
				}
				return $this->status( $job['id'] );
			}
			if ( 'capturing' !== $job['phase'] ) {
				return $this->status( $job['id'] );
			}
		} else {
			$job                      = $this->new_job( $prepared, $request_id, $fingerprint, $republish );
			$this->store->data['job'] = $job;
			$this->store->save();
		}
		$this->id = $job['id'];
		if ( Publication_Runtime::memory() ) {
			return $this->publish_memory( $receipt, $republish );
		}
		try {
			$now                = $republish ? time() : $prepared['generated_at'];
			list( , , $export ) = Publisher::validate( $receipt, $this->budget, $republish, true, $now );
			if ( $republish ) {
				if ( $prepared['snapshot']['data_hash'] !== $export['data_hash'] ) {
					throw new Failure( 'PREPARATION_INVALID', 409 );
				}
				$hash = empty( $export['bundle'] ) ? $export['wxr']->blob_sha() : GitHub::blob_sha( $export['bundle']->manifest );
				$key  = empty( $export['bundle'] ) ? 'wxr' : 'manifest';
				if ( $hash === $prepared['snapshot']['files'][ $key ] ) {
					Publisher::close_export( $export );
					$this->budget->require_time( 1 );
					usleep( 1000000 );
					$now                = time();
					list( , , $export ) = Publisher::validate( $receipt, $this->budget, true, true, $now );
				}
			}
			$job['generated_at']      = $now;
			$job['data_hash']         = $export['data_hash'];
			$job['plan']              = Publisher::plan( $export, $this->budget );
			$job['mode']              = $job['plan']['format'];
			$job['artifacts']         = ( new Publication_Snapshot( $this->store, $this->budget ) )->capture( $export, $job );
			$job['seal']              = Publication_Snapshot::seal( $job );
			$job['phase']             = 'inspecting';
			$this->store->data['job'] = $job;
			$this->store->save();
			return $this->status( $job['id'] );
		} catch ( \Throwable $error ) {
			$this->store->finish( 'failed', array( 'code' => $error instanceof Failure ? $error->reason : 'INTERNAL_ERROR' ) );
			throw $error;
		} finally {
			if ( isset( $export ) ) {
				Publisher::close_export( $export );
			}
		}
	}

	/** Complete a single publication request, preserving its durable final-write fence.
	 *
	 * @param string $receipt Signed approval.
	 * @param bool   $republish Explicit unchanged republication choice.
	 * @return array Safe terminal result or read-only verification state.
	 */
	private function publish_memory( string $receipt, bool $republish ): array {
		$this->store->data['job']['legacy'] = true;
		$this->store->data['job']['phase']  = 'publishing';
		$this->store->save();
		$this->budget->reserve_checkpoint( 2, null );
		try {
			$result = Publisher::publish_once( $receipt, $republish, $this->budget, null, $this->observer() );
			if ( 'unknown' === $result['state'] ) {
				return $this->status( $this->id );
			}
			return $this->store->finish( $result['state'], isset( $result['commit_url'] ) ? array( 'commit_url' => $result['commit_url'] ) : array() );
		} catch ( \Throwable $error ) {
			// Never clear a recorded intent on an unexpected failure or lost DB lock.
			$this->store->reload();
			$code = $error instanceof Failure ? $error->reason : 'INTERNAL_ERROR';
			if ( ! empty( $this->store->data['job']['intent'] ) && GitHub::uncertain( $error ) ) {
				return $this->status( $this->id );
			}
			return $this->store->finish( 'failed', array( 'code' => $code ) );
		}
	}

	/**
	 * Bind a fresh job to the preparation without storing credentials or bodies.
	 *
	 * @param array  $prepared Validated prepared value.
	 * @param string $request_id Client idempotency identifier.
	 * @param string $fingerprint Validated fingerprint value.
	 * @param bool   $republish Whether unchanged data was explicitly approved for republication.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function new_job( array $prepared, string $request_id, string $fingerprint, bool $republish ): array {
		if ( count( $this->store->data['history'] ) >= 64 ) {
			throw new Failure( 'PUBLICATION_BUSY', 409 );
		}
		return array(
			'backend'           => Publication_Runtime::backend(),
			'id'                => bin2hex( random_bytes( 16 ) ),
			'request_id'        => $request_id,
			'fingerprint'       => $fingerprint,
			'owner'             => get_current_user_id(),
			'started_at'        => time(),
			'expires'           => $prepared['expires'],
			'settings_revision' => $prepared['revision'],
			'target'            => array(
				'repository' => $prepared['snapshot']['repository'],
				'branch'     => $prepared['snapshot']['branch'],
				'visibility' => $prepared['snapshot']['visibility'] ?? null,
			),
			'approved'          => $prepared['snapshot'],
			'phase'             => 'capturing',
			'republish'         => $republish,
			'confirmed'         => array(),
			'inspection'        => array(),
			'requests'          => array(),
			'retries'           => 0,
			'rebases'           => 0,
		);
	}

	/** Reap known expired/cancelled work; never erase a possible final write. */
	private function prune(): void {
		// A worker may exit after recording completion but before deleting its bodies.
		// Retry only journal-owned terminal directories while holding the site lock.
		foreach ( $this->store->data['history'] as $entry ) {
			$this->store->purge( $entry['id'] );
		}
		$this->store->data['history'] = array_values( array_filter( $this->store->data['history'], static fn( $item ) => $item['until'] >= time() ) );
		$job                          = $this->store->data['job'];
		if ( $job && empty( $job['intent'] ) && ( time() > $job['expires'] || ! empty( $job['cancel_requested'] ) ) ) {
			$this->store->finish( empty( $job['cancel_requested'] ) ? 'expired' : 'cancelled' );
		} elseif ( $job && ! empty( $job['intent'] ) && time() > $job['expires'] ) {
			$this->store->purge( $job['id'] );
		}
		// Keep the most recent twenty summaries when approval no longer needs them.
		// Count changes after pruning each entry.
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
		while ( count( $this->store->data['history'] ) > 20 ) {
			$found = false;
			foreach ( $this->store->data['history'] as $key => $entry ) {
				if ( ( $entry['protected_until'] ?? $entry['until'] ) < time() ) {
					unset( $this->store->data['history'][ $key ] );
					$this->store->data['history'] = array_values( $this->store->data['history'] );
					$found                        = true;
					break;
				}
			}
			if ( ! $found ) {
				break;
			}
		}
		$this->store->save();
	}

	/**
	 * Match the original owner; a job ID does not grant access.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function owner( array $job ): void {
		if ( get_current_user_id() !== $job['owner'] ) {
			throw new Failure( 'PUBLICATION_BUSY', 409 );
		}
	}

	/**
	 * Read unchanged configuration before remote work. Verification never retargets.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @param bool  $verify Whether this is read-only uncertainty reconciliation.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function settings( array $job, bool $verify = false ): array {
		$this->owner( $job );
		$settings = Settings::read();
		if ( $settings['repository'] !== $job['target']['repository'] || $settings['branch'] !== $job['target']['branch'] || ( ! $verify && $settings['revision'] !== $job['settings_revision'] ) ) {
			throw new Failure( 'SETTINGS_CHANGED', 409 );
		}
		if ( ! $verify && time() > $job['expires'] ) {
			throw new Failure( 'PREPARATION_EXPIRED', 409 );
		}
		if ( ! $verify && ! empty( $job['cancel_requested'] ) ) {
			throw new Failure( 'PUBLICATION_CANCELLED', 409 );
		}
		return $settings;
	}

	/** Journal immediately before transport; the same hook protects legacy writes.
	 *
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function observer(): \Closure {
		return function ( string $event, string $method, ?array $context, float $timeout ): void {
			$this->store->check_lock();
			$this->store->reload();
			$job = $this->store->data['job'];
			if ( ! $job || $job['id'] !== $this->id ) {
				if ( 'after' === $event ) {
					return;
				}
				throw new Failure( 'PUBLICATION_CONFLICT', 409 );
			}
			if ( 'after' === $event ) {
				$this->store->data['not_before'] = microtime( true ) + 1;
				$this->store->save();
				return;
			}
			$this->settings( $job, ! empty( $job['intent'] ) && 'GET' === $method );
			if ( 'GET' === $method ) {
				return;
			}
			if ( ! empty( $job['intent'] ) ) {
				throw new Failure( 'PUBLICATION_VERIFY_REQUIRED', 409 );
			}
			$wait = $this->store->data['not_before'] - microtime( true );
			if ( $wait > 0 ) {
				if ( empty( $job['legacy'] ) ) {
					throw new Failure( 'PUBLICATION_WAIT', 409 );
				}
				$this->budget->require_time( $wait );
				usleep( (int) ceil( $wait * 1000000 ) );
			}
			if ( 'PATCH' === $method ) {
				$this->store->data['job']['intent']     = $context;
				$this->store->data['job']['phase']      = 'verifying';
				$this->store->data['job']['inspection'] = array();
			}
			$this->store->data['not_before'] = microtime( true ) + $timeout + 1;
			$this->store->save();
		};
	}

	/**
	 * Advance one bounded step. Duplicate/reordered requests never choose the step.
	 *
	 * @param string $operation Allowlisted server operation.
	 * @param string $id Opaque server-issued job identifier.
	 * @param string $request_id Client idempotency identifier.
	 * @param int    $revision Last server revision observed by the client.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	public function advance( string $operation, string $id, string $request_id, int $revision ): array {
		if ( ! Publication_Runtime::active() ) {
			return Publication_Runtime::run( fn() => $this->advance( $operation, $id, $request_id, $revision ) );
		}
		self::authorize();
		$this->id = self::identifier( $id );
		self::identifier( $request_id );
		$verify = 'publication_verify' === $operation;
		try {
			$this->store->acquire();
		} catch ( Failure $error ) {
			if ( 'PUBLICATION_BUSY' === $error->reason ) {
				return $this->status( $id ) + array( 'busy' => true );
			}
			// Read-only reconciliation can survive lost temp storage; DB CAS still
			// serializes checkpoints and no final mutation can be issued here.
			if ( ! $verify || empty( $this->store->data['job']['intent'] ) || ! in_array( $error->reason, array( 'PUBLICATION_STORAGE_UNAVAILABLE', 'PUBLICATION_BACKEND_CHANGED' ), true ) ) {
				throw $error;
			}
			$this->store->acquire_verification();
		}
		$job = $this->store->data['job'];
		if ( ! $job || $job['id'] !== $id ) {
			return $this->status( $id );
		}
		$this->owner( $job );
		$fingerprint = hash( 'sha256', $operation . '|' . $id . '|' . $revision );
		if ( isset( $job['requests'][ $request_id ] ) ) {
			if ( $job['requests'][ $request_id ] !== $fingerprint ) {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
			return $this->status( $id );
		}
		if ( $revision !== $this->store->data['revision'] || ( ! empty( $job['intent'] ) && ! $verify ) ) {
			return $this->status( $id );
		}
		if ( ! $verify && ! empty( $job['legacy'] ) ) {
			return $this->status( $id );
		}
		if ( ! $verify && empty( $job['intent'] ) ) {
			Export_Storage::recover_once( $this->budget );
		}
		if ( empty( $job['intent'] ) && ( ! empty( $job['cancel_requested'] ) || time() > $job['expires'] ) ) {
			return $this->store->finish( empty( $job['cancel_requested'] ) ? 'expired' : 'cancelled' );
		}
		if ( ( $verify && empty( $job['intent'] ) ) || ( 'publication_finalize' === $operation && 'ready' !== $job['phase'] ) ) {
			return $this->status( $id );
		}
		if ( $this->store->data['not_before'] > microtime( true ) ) {
			return $this->status( $id );
		}
		if ( count( $job['requests'] ) >= 1024 ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
		$this->store->data['job']['requests'][ $request_id ] = $fingerprint;
		$this->store->data['job']['paused']                  = false;
		$this->store->data['job']['verify_complete']         = false;
		unset( $this->store->data['job']['error'], $this->store->data['job']['details'] );
		$this->store->save();
		$this->budget->reserve_checkpoint( 2, 'publication_finalize' === $operation ? null : 15 );
		$github = null;
		try {
			$settings = $this->settings( $job, $verify );
			$github   = new GitHub( $settings, $this->budget, $this->observer() );
			if ( ! $verify ) {
				$github->expect_visibility( $job['target']['visibility'] ?? null );
			}
			if ( $verify ) {
				if ( empty( $job['intent'] ) ) {
					throw new Failure( 'PUBLICATION_INVALID', 409 );
				}
				$step                                   = $github->inspect_step( $job['target']['branch'], $job['inspection'] );
				$this->store->data['job']['inspection'] = $step;
				if ( 'done' === $step['phase'] ) {
					if ( GitHub::confirms( $step['snapshot'], $job['intent'] ) ) {
						return $this->store->finish( 'published', array( 'commit_url' => $this->commit_url( $job, $job['intent']['candidate'] ) ) );
					}
					$this->store->data['job']['inspection']      = array();
					$this->store->data['job']['paused']          = true;
					$this->store->data['job']['verify_complete'] = true;
				}
			} elseif ( 'publication_finalize' === $operation ) {
				return $this->finalize( $job, $settings, $github );
			} elseif ( 'publication_advance' === $operation ) {
				$this->step( $job, $github );
			} else {
				throw new Failure( 'PUBLICATION_INVALID', 409 );
			}
			if ( null === $this->store->data['job'] ) {
				return $this->status( $id );
			}
			$this->store->data['job']['retries'] = 0;
			$this->store->save();
			return $this->status( $id );
		} catch ( \Throwable $error ) {
			return $this->failed_step( $error, $github, $operation );
		}
	}

	/**
	 * Require all managed commitments to remain those approved by the user.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @param array $snapshot Validated immutable GitHub target metadata.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function accept_target( array $job, array $snapshot ): void {
		$approved = $job['approved'];
		if ( $snapshot['repository'] !== $approved['repository'] || $snapshot['files'] !== $approved['files'] || $snapshot['marker'] !== $approved['marker'] ) {
			throw new Failure( 'TARGET_CHANGED', 409 );
		}
	}

	/**
	 * One publication step, with a visibility check before writes; never a ref update.
	 *
	 * @param array  $job Server-owned immutable approval and current progress.
	 * @param GitHub $github Validated github value.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function step( array $job, GitHub $github ): void {
		( new Publication_Snapshot( $this->store, $this->budget ) )->validate_storage( $job );
		switch ( $job['phase'] ) {
			case 'inspecting':
				$inspection                             = $github->inspect_step( $job['target']['branch'], $job['inspection'] );
				$this->store->data['job']['inspection'] = $inspection;
				if ( 'done' !== $inspection['phase'] ) {
					break;
				}
				$snapshot = $inspection['snapshot'];
				$this->accept_target( $job, $snapshot );
				if ( ! $job['republish'] && $snapshot['data_hash'] === $job['data_hash'] && $snapshot['format'] === $job['plan']['format'] ) {
					// A no-op still confirms the destination the user reviewed.
					$github->visibility();
					$this->store->finish( 'unchanged' );
					return;
				}
				$confirmed = $job['confirmed'];
				foreach ( $job['artifacts'] as $index => $artifact ) {
					$sha = 'part' === $artifact['kind'] ? ( $snapshot['parts'][ $artifact['path'] ] ?? null ) : ( $snapshot['files'][ $artifact['kind'] ] ?? null );
					if ( $sha === $artifact['git'] ) {
						$confirmed[ $index ] = $sha;
					}
				}
				$this->store->data['job']['confirmed']  = $confirmed;
				$this->store->data['job']['snapshot']   = $snapshot;
				$this->store->data['job']['inspection'] = array();
				$this->store->data['job']['phase']      = 'uploading';
				break;
			case 'uploading':
				foreach ( $job['artifacts'] as $index => $artifact ) {
					if ( ( $job['confirmed'][ $index ] ?? null ) === $artifact['git'] ) {
						continue;
					}
					$body = ( new Publication_Snapshot( $this->store, $this->budget ) )->contents( $job, $artifact );
					$github->upload_artifact( $body, $artifact['git'], 'bundle' === $job['plan']['format'] );
					unset( $body );
					$this->store->data['job']['confirmed'][ $index ] = $artifact['git'];
					return;
				}
				$this->store->data['job']['phase'] = 'assembly_head';
				break;
			case 'assembly_head':
				if ( $github->head( $job['target']['branch'] ) !== $job['snapshot']['head'] ) {
					$this->rebase( $job );
				} else {
					$this->store->data['job']['phase'] = 'bundle' === $job['plan']['format'] ? 'parts_tree' : 'root_tree';
				}
				break;
			case 'parts_tree':
				$entries = array();
				foreach ( $job['artifacts'] as $artifact ) {
					if ( 'part' === $artifact['kind'] ) {
						$entries[] = self::entry( basename( $artifact['path'] ), $artifact['git'] );
					}
				}
				$this->store->data['job']['parts_tree'] = $github->create_tree( $entries );
				$this->store->data['job']['phase']      = 'root_tree';
				break;
			case 'root_tree':
				list( $entries, $files )               = self::tree_plan( $job );
				$this->store->data['job']['root_tree'] = $github->create_tree( $entries, $job['snapshot']['tree'] );
				$this->store->data['job']['files']     = $files;
				$this->store->data['job']['message']   = self::commit_message( $job, $files );
				$this->store->data['job']['phase']     = 'commit';
				break;
			case 'commit':
				$this->store->data['job']['candidate'] = $github->create_commit( $job['snapshot']['head'], $job['root_tree'], $job['message'] );
				$this->store->data['job']['phase']     = 'candidate';
				break;
			case 'candidate':
				$github->verify_candidate( $job['candidate'], $job['snapshot']['head'], $job['root_tree'], $job['message'] );
				$this->store->data['job']['phase'] = 'ready';
				break;
			case 'ready':
				break;
			default:
				throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
	}

	/**
	 * Rebuild only after a full new metadata inspection, with a finite race budget.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function rebase( array $job ): void {
		if ( $job['rebases'] >= 3 ) {
			throw new Failure( 'TARGET_CHANGED', 409 );
		}
		$this->store->data['job']['rebases']    = $job['rebases'] + 1;
		$this->store->data['job']['inspection'] = array();
		$this->store->data['job']['phase']      = 'inspecting';
		unset( $this->store->data['job']['candidate'], $this->store->data['job']['root_tree'] );
	}

	/**
	 * Validate source and HEAD in the same request that may send the one PATCH.
	 *
	 * @param array  $job Server-owned immutable approval and current progress.
	 * @param array  $settings Current validated plugin settings.
	 * @param GitHub $github Validated github value.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 */
	private function finalize( array $job, array $settings, GitHub $github ): array {
		if ( 'ready' !== $job['phase'] ) {
			throw new Failure( 'PUBLICATION_INVALID', 409 );
		}
		( new Publication_Snapshot( $this->store, $this->budget ) )->validate_storage( $job );
		$export = ( new Exporter( $this->budget ) )->generate_files( $settings, $job['generated_at'], $job['plan']['format'] );
		try {
			if ( $export['data_hash'] !== $job['data_hash'] || Publisher::plan( $export, $this->budget ) !== $job['plan'] ) {
				throw new Failure( 'CONTENT_CHANGED', 409 );
			}
		} finally {
			Publisher::close_export( $export );
		}
		unset( $export );
		if ( $github->head( $job['target']['branch'] ) !== $job['snapshot']['head'] ) {
			$this->rebase( $job );
			$this->store->save();
			return $this->status( $job['id'] );
		}
		$this->budget->require_time( 2 );
		$github->update_reference( $job['target']['branch'], $job['candidate'], $job['snapshot']['head'], $job['files'], $job['data_hash'] );
		// No post-success time check: confirmed publication stays successful.
		return $this->store->finish( 'published', array( 'commit_url' => $this->commit_url( $job, $job['candidate'] ) ) );
	}

	/**
	 * Interpret failures without ever retrying a possibly dispatched final write.
	 *
	 * @param \Throwable $error Observed transport or local checkpoint failure.
	 * @param GitHub     $github Validated github value.
	 * @param string     $operation Allowlisted server operation.
	 */
	private function failed_step( \Throwable $error, ?GitHub $github, string $operation ): array {
		$this->store->reload();
		$job = $this->store->data['job'];
		if ( ! $job || $job['id'] !== $this->id ) {
			return $this->status( $this->id );
		}
		$code    = $error instanceof Failure ? $error->reason : 'INTERNAL_ERROR';
		$details = $error instanceof Failure ? $error->details : array();
		if ( ! empty( $job['intent'] ) ) {
			if ( $github && $github->reference_confirmed() ) {
				return array(
					'state'      => 'published',
					'job_id'     => $job['id'],
					'commit_url' => $this->commit_url( $job, $job['intent']['candidate'] ),
				);
			}
			$this->store->data['job']['verify_complete'] = 'publication_verify' === $operation;
			if ( 'publication_finalize' === $operation && $github && $github->reference_sent() && ! GitHub::uncertain( $error ) ) {
				return $this->store->finish(
					'failed',
					array(
						'code'    => $code,
						'details' => $details,
					)
				);
			}
			$this->store->data['job']['inspection'] = array();
			$this->store->data['job']['paused']     = true;
		} elseif ( 'PUBLICATION_WAIT' === $code || 'PUBLICATION_CONFLICT' === $code ) {
			return $this->status( $this->id );
		} elseif ( in_array( $code, array( 'GITHUB_UNAVAILABLE', 'GITHUB_RATE_LIMITED', 'REQUEST_TIME_LIMIT_EXCEEDED' ), true ) && 'publication_advance' === $operation && $job['retries'] < 3 ) {
			$this->store->data['job']['retries'] = $job['retries'] + 1;
			$this->store->data['job']['paused']  = true;
		} else {
			return $this->store->finish(
				'PUBLICATION_CANCELLED' === $code ? 'cancelled' : 'failed',
				array(
					'code'    => $code,
					'details' => $details,
				)
			);
		}
		$this->store->data['job']['error']   = $code;
		$this->store->data['job']['details'] = $details;
		if ( 'GITHUB_RATE_LIMITED' === $code ) {
			$this->store->data['not_before'] = max( $this->store->data['not_before'], microtime( true ) + max( 60 * ( 2 ** min( 3, $job['retries'] ) ), $details['retry_after_seconds'] ?? 0 ) );
		}
		$this->store->save();
		return $this->status( $this->id );
	}

	/**
	 * A Git entry for the exact owned path.
	 *
	 * @param string $path Private path or owned Git path.
	 * @param string $sha Validated Git object SHA.
	 * @param bool   $tree Validated tree SHA or entries.
	 */
	private static function entry( string $path, ?string $sha, bool $tree = false ): array {
		return array(
			'path' => $path,
			'mode' => $tree ? '040000' : '100644',
			'type' => $tree ? 'tree' : 'blob',
			'sha'  => $sha,
		);
	}

	/**
	 * Keep current root entry and commitment ordering for the existing proof.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 */
	private static function tree_plan( array $job ): array {
		$files   = array();
		$entries = array();
		foreach ( $job['artifacts'] as $artifact ) {
			if ( 'part' !== $artifact['kind'] ) {
				$files[ $artifact['kind'] ] = $artifact['git'];
			}
		}
		if ( 'bundle' === $job['plan']['format'] ) {
			$files   = array(
				'manifest' => $files['manifest'],
				'base'     => $files['base'],
				'parts'    => $job['parts_tree'],
			);
			$entries = array( self::entry( Wxr_Bundle::MANIFEST, $files['manifest'] ), self::entry( GitHub::FILES['base'], $files['base'] ), self::entry( Wxr_Bundle::DIRECTORY, $files['parts'], true ) );
			if ( isset( $job['snapshot']['files']['wxr'] ) ) {
				$entries[] = self::entry( GitHub::FILES['wxr'], null );
			}
		} else {
			$entries = GitHub::single_entries( $files, $job['snapshot'] );
		}
		return array( $entries, $files );
	}

	/**
	 * Reuse the existing strict trailer contract byte for byte.
	 *
	 * @param array $job Server-owned immutable approval and current progress.
	 * @param array $files Expected managed file commitments.
	 */
	private static function commit_message( array $job, array $files ): string {
		$title   = $job['republish'] ? 'chore(publish): republish WordPress site data' : 'chore(publish): update WordPress site data';
		$bundle  = 'bundle' === $job['plan']['format'];
		$message = $title . "\n\nZeroPress-Publisher: " . ( $bundle ? 'wordpress-wxr-bundle-v1' : 'wordpress-wxr-v1' ) . "\nZeroPress-Data-Hash: sha256:" . $job['data_hash'];
		$labels  = $bundle ? array(
			'manifest' => 'Manifest-Blob',
			'parts'    => 'Parts-Tree',
			'base'     => 'Base-Blob',
		) : array(
			'wxr'  => 'WXR-Blob',
			'base' => 'Base-Blob',
		);
		foreach ( $labels as $key => $label ) {
			$message .= "\nZeroPress-" . $label . '-SHA: ' . $files[ $key ];
		}
		return $message;
	}

	/**
	 * Build a URL only from the recorded validated repository and commit.
	 *
	 * @param array  $job Server-owned immutable approval and current progress.
	 * @param string $sha Validated Git object SHA.
	 */
	private function commit_url( array $job, string $sha ): string {
		return 'https://github.com/' . $job['target']['repository'] . '/commit/' . $sha;
	}

	/**
	 * Preserve the one-request API while sharing its gate, pacing and uncertainty journal.
	 *
	 * @param string               $receipt Signed, expiring preparation approval.
	 * @param bool                 $republish Whether unchanged data was explicitly approved for republication.
	 * @param Resource_Budget      $budget Original request memory and time guard.
	 * @param Publication_Progress $progress Optional legacy progress reporter.
	 * @throws Failure If validation, transport or a durable checkpoint fails.
	 * @throws \Throwable If an unexpected error interrupts the request.
	 */
	public static function legacy( string $receipt, bool $republish, Resource_Budget $budget, ?Publication_Progress $progress ): array {
		if ( ! Publication_Runtime::active() ) {
			return Publication_Runtime::run( fn() => self::legacy( $receipt, $republish, $budget, $progress ) );
		}
		if ( Publication_Runtime::memory() ) {
			return ( new self( $budget ) )->start( $receipt, $republish, bin2hex( random_bytes( 16 ) ) );
		}
		Publisher::receipt( $receipt );
		self::authorize();
		$self = new self( $budget );
		$self->store->acquire();
		$self->prune();
		if ( $self->store->data['job'] ) {
			throw new Failure( 'PUBLICATION_ACTIVE', 409 );
		}
		list( , $prepared ) = Publisher::receipt( $receipt );
		if ( empty( $prepared['snapshot'] ) ) {
			throw new Failure( 'CONNECTION_REQUIRED' );
		}
		$job                      = $self->new_job( $prepared, bin2hex( random_bytes( 16 ) ), hash( 'sha256', $receipt ), $republish );
		$job['legacy']            = true;
		$self->id                 = $job['id'];
		$self->store->data['job'] = $job;
		$self->store->save();
		try {
			$result = Publisher::publish_once( $receipt, $republish, $budget, $progress, $self->observer() );
			if ( 'unknown' !== $result['state'] ) {
				$self->store->finish( $result['state'], isset( $result['commit_url'] ) ? array( 'commit_url' => $result['commit_url'] ) : array() );
			}
			return $result;
		} catch ( \Throwable $error ) {
			if ( isset( $result ) && 'published' === $result['state'] ) {
				return $result;
			}
			$self->store->reload();
			if ( ! empty( $self->store->data['job']['intent'] ) && GitHub::uncertain( $error ) ) {
				return array(
					'state'      => 'unknown',
					'commit_url' => $self->commit_url( $job, $self->store->data['job']['intent']['candidate'] ),
				);
			}
			$self->store->finish( 'failed', array( 'code' => $error instanceof Failure ? $error->reason : 'INTERNAL_ERROR' ) );
			throw $error;
		}
	}
}
