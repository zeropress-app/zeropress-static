<?php
/**
 * Metadata-only GitHub inspection and atomic publication.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Fixed-host GitHub client. Never reads a remote file body. */
final class GitHub {
	const FILES = array(
		'wxr'  => 'wordpress-export.xml',
		'base' => 'wxr-import-base.json',
	);
	/**
	 * Repository.
	 *
	 * @var string
	 */
	private string $repository;
	/**
	 * Token.
	 *
	 * @var string
	 */
	private string $token;
	/**
	 * Shared request resource budget.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/**
	 * Whether the final branch update has been dispatched.
	 *
	 * @var bool
	 */
	private bool $ref_update_sent = false;
	/**
	 * Whether bundle writes are paced.
	 *
	 * @var bool
	 */
	private bool $paced = false;
	/**
	 * Completion time of the previous write.
	 *
	 * @var float
	 */
	private float $last_write = 0;

	/**
	 * Initialize this service with validated input.
	 *
	 * @param array                $settings Settings snapshot; credentials remain server-side.
	 * @param Resource_Budget|null $budget Shared request resource budget.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function __construct( array $settings, ?Resource_Budget $budget = null ) {
		$this->budget     = $budget ?? Resource_Budget::from_request();
		$this->repository = Settings::repository( $settings['repository'] );
		if ( '' === $this->repository ) {
			throw new Failure( 'REPOSITORY_REQUIRED' );
		}
		$this->token = Settings::token( $settings );
	}

	/**
	 * Resolve a branch and both regular files at one immutable HEAD.
	 *
	 * @param string $branch Explicit publishing branch name.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function inspect( string $branch ): array {
		Settings::branch( $branch );
		$reference = $this->request( 'GET', '/git/ref/heads/' . rawurlencode( $branch ) );
		if ( ( $reference['ref'] ?? '' ) !== 'refs/heads/' . $branch || ( $reference['object']['type'] ?? '' ) !== 'commit' ) {
			throw new Failure( 'INVALID_BRANCH' );
		}
		$head     = self::sha( $reference['object']['sha'] ?? '' );
		$commit   = $this->request( 'GET', '/git/commits/' . $head );
		$tree_sha = self::sha( $commit['tree']['sha'] ?? '' );
		$tree     = $this->request( 'GET', '/git/trees/' . $tree_sha );
		if ( ! empty( $tree['truncated'] ) || ! is_array( $tree['tree'] ?? null ) ) {
			throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
		}
		$root = array();
		foreach ( $tree['tree'] as $entry ) {
			$path = $entry['path'] ?? '';
			if ( isset( $root[ $path ] ) ) {
				throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
			}
			$root[ $path ] = $entry;
		}
		$marker    = isset( $root[ Wxr_Bundle::MARKER ] ) ? self::regular_sha( $root[ Wxr_Bundle::MARKER ] ) : null;
		$capable   = self::blob_sha( Wxr_Bundle::MARKER_CONTENT ) === $marker;
		$is_bundle = isset( $root[ Wxr_Bundle::MANIFEST ] );
		$paths     = $is_bundle ? array(
			'manifest' => Wxr_Bundle::MANIFEST,
			'base'     => self::FILES['base'],
		) : self::FILES;
		if ( $is_bundle && ( ! $capable || isset( $root[ self::FILES['wxr'] ] ) ) ) {
			throw new Failure( 'WXR_TARGET_INVALID', 409 );
		}
		$files = array();
		$parts = array();
		foreach ( $paths as $key => $path ) {
			if ( ! isset( $root[ $path ] ) ) {
				throw new Failure( 'TARGET_NOT_FOUND', 404 );
			}
			$files[ $key ] = self::regular_sha( $root[ $path ] );
		}
		if ( isset( $root[ Wxr_Bundle::DIRECTORY ] ) ) {
			$entry = $root[ Wxr_Bundle::DIRECTORY ];
			if ( ! $is_bundle || 'tree' !== ( $entry['type'] ?? '' ) || '040000' !== ( $entry['mode'] ?? '' ) ) {
				throw new Failure( 'WXR_TARGET_INVALID', 409 );
			}
			$files['parts'] = self::sha( $entry['sha'] ?? '' );
			$subtree        = $this->request( 'GET', '/git/trees/' . $files['parts'] );
			if ( ! empty( $subtree['truncated'] ) || ! is_array( $subtree['tree'] ?? null ) || ! count( $subtree['tree'] ) || count( $subtree['tree'] ) > Wxr_Bundle::MAX_PARTS ) {
				throw new Failure( 'WXR_TARGET_INVALID', 409 );
			}
			foreach ( $subtree['tree'] as $part ) {
				$path = $part['path'] ?? '';
				if ( ! preg_match( '/\Ap-[a-f0-9]{64}\.xml\z/', $path ) || isset( $parts[ Wxr_Bundle::DIRECTORY . '/' . $path ] ) ) {
					throw new Failure( 'WXR_TARGET_INVALID', 409 );
				}
				$parts[ Wxr_Bundle::DIRECTORY . '/' . $path ] = self::regular_sha( $part );
			}
		} elseif ( $is_bundle ) {
			throw new Failure( 'WXR_TARGET_INVALID', 409 );
		}
		$latest    = array();
		$data_hash = null;
		foreach ( $paths as $key => $path ) {
			$history = $this->request(
				'GET',
				'/commits?' . http_build_query(
					array(
						'sha'      => $head,
						'path'     => $path,
						'per_page' => 1,
					),
					'',
					'&',
					PHP_QUERY_RFC3986
				)
			);
			if ( ! is_array( $history[0] ?? null ) ) {
				throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
			}
			$last           = $history[0];
			$last_sha       = self::sha( $last['sha'] ?? '' );
			$latest[ $key ] = array(
				'sha' => $last_sha,
				'url' => 'https://github.com/' . $this->repository . '/commit/' . $last_sha,
			);
			$metadata       = self::metadata( $last['commit']['message'] ?? '' );
			if ( $metadata && self::metadata_matches( $metadata, $files ) ) {
				$data_hash = $metadata['hash'];
			}
		}
		if ( $is_bundle && null === $data_hash ) {
			throw new Failure( 'WXR_TARGET_INVALID', 409 );
		}
		return array(
			'repository' => $this->repository,
			'branch'     => $branch,
			'head'       => $head,
			'tree'       => $tree_sha,
			'files'      => $files,
			'data_hash'  => $data_hash,
			'latest'     => $latest,
			'format'     => $is_bundle ? 'bundle' : 'single',
			'marker'     => $marker,
			'capable'    => $capable,
			'parts'      => $parts,
		);
	}

	/**
	 * Publish both files in a single non-forced branch update.
	 *
	 * @param array                     $snapshot Verified target at a pinned commit.
	 * @param array                     $export Generated public WXR and base settings.
	 * @param bool                      $republish Whether this commit explicitly republishes unchanged content.
	 * @param Publication_Progress|null $progress Optional stage feedback for this attempt.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function publish( array $snapshot, array $export, bool $republish = false, ?Publication_Progress $progress = null ): array {
		if ( ! empty( $export['bundle'] ) ) {
			return $this->publish_bundle( $snapshot, $export, $republish, $progress );
		}
		$progress?->update( 'uploading' );
		$blobs = array();
		foreach ( self::FILES as $key => $path ) {
			$file = $export[ $key ];
			$this->budget->check( $file instanceof Export_File ? $file->size() : strlen( $file ) );
			$expected = $file instanceof Export_File ? $file->blob_sha() : self::blob_sha( $file );
			if ( ( $snapshot['files'][ $key ] ?? null ) !== $expected ) {
				// WordPress HTTP currently requires the full UTF-8 JSON body.
				$content = $file instanceof Export_File ? $file->contents() : $file;
				$blob    = $this->request(
					'POST',
					'/git/blobs',
					array(
						'content'  => $content,
						'encoding' => 'utf-8',
					)
				);
				unset( $content );
				if ( ( $blob['sha'] ?? '' ) !== $expected ) {
					throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
				}
			}
			$blobs[ $key ] = $expected;
		}
		$entries = self::single_entries( $blobs, $snapshot );
		$title   = $republish ? 'chore(publish): republish WordPress site data' : 'chore(publish): update WordPress site data';
		$message = $title . "\n\nZeroPress-Publisher: wordpress-wxr-v1\n"
			. 'ZeroPress-Data-Hash: sha256:' . $export['data_hash'] . "\n"
			. 'ZeroPress-WXR-Blob-SHA: ' . $blobs['wxr'] . "\n"
			. 'ZeroPress-Base-Blob-SHA: ' . $blobs['base'];
		return $this->commit_publication( $snapshot, $entries, $blobs, $message, $export['data_hash'], $progress );
	}

	/**
	 * Replace a verified owned bundle and its base atomically with single XML.
	 *
	 * @param array $files    New XML and base blob commitments.
	 * @param array $snapshot Target verified by inspect(), including bundle ownership.
	 * @return array
	 */
	public static function single_entries( array $files, array $snapshot ): array {
		$entries = array();
		foreach ( self::FILES as $key => $path ) {
			$entries[] = array(
				'path' => $path,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $files[ $key ],
			);
		}
		if ( 'bundle' === $snapshot['format'] ) {
			$entries[] = array(
				'path' => Wxr_Bundle::MANIFEST,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => null,
			);
			$entries[] = array(
				'path' => Wxr_Bundle::DIRECTORY,
				'mode' => '040000',
				'type' => 'tree',
				'sha'  => null,
			);
		}
		return $entries;
	}

	/**
	 * Publish all changes through one non-forced reference update.
	 *
	 * @param array                     $snapshot Verified target at an immutable commit.
	 * @param array                     $entries Root tree changes for this generation.
	 * @param array                     $blobs Expected active file and subtree commitments.
	 * @param string                    $message Commit message with comparison trailers.
	 * @param string                    $data_hash Canonical logical export digest.
	 * @param Publication_Progress|null $progress Optional publication stage feedback.
	 * @return array
	 * @throws \Throwable If validation, storage or the request budget fails.
	 * @throws Failure If a protection check fails.
	 */
	private function commit_publication( array $snapshot, array $entries, array $blobs, string $message, string $data_hash, ?Publication_Progress $progress ): array {
		$progress?->update( 'committing' );
		$tree = $this->request(
			'POST',
			'/git/trees',
			array(
				'base_tree' => $snapshot['tree'],
				'tree'      => $entries,
			)
		);

		$commit = $this->request(
			'POST',
			'/git/commits',
			array(
				'message' => $message,
				'tree'    => self::sha( $tree['sha'] ?? '' ),
				'parents' => array( $snapshot['head'] ),
			)
		);
		$sha    = self::sha( $commit['sha'] ?? '' );

		$this->ref_update_sent = false;
		try {
			$ref = $this->request(
				'PATCH',
				'/git/refs/heads/' . rawurlencode( $snapshot['branch'] ),
				array(
					'sha'   => $sha,
					'force' => false,
				)
			);
			if ( ( $ref['object']['sha'] ?? '' ) !== $sha ) {
				throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
			}
		} catch ( \Throwable $error ) {
			// Only an uncertain write response requires reconciliation. Never repeat the write.
			if ( ! $this->ref_update_sent || ( $error instanceof Failure && ! in_array( $error->reason, array( 'GITHUB_UNAVAILABLE', 'GITHUB_RESPONSE_INVALID', 'MEMORY_LIMIT_EXCEEDED', 'REQUEST_TIME_LIMIT_EXCEEDED' ), true ) ) ) {
				throw $error;
			}
			$progress?->update( 'verifying' );
			try {
				$current          = $this->inspect( $snapshot['branch'] );
				$commit_confirmed = $current['head'] === $sha ||
					in_array( $sha, array_column( $current['latest'], 'sha' ), true );
				if ( $commit_confirmed && $current['files'] === $blobs && $current['data_hash'] === $data_hash ) {
					return $this->success( $sha );
				}
			} catch ( \Throwable $ignored ) {
				// The original outcome is still unknown; expose no external error body.
				return array(
					'state'      => 'unknown',
					'commit_url' => 'https://github.com/' . $this->repository . '/commit/' . $sha,
				);
			}
			return array(
				'state'      => 'unknown',
				'commit_url' => 'https://github.com/' . $this->repository . '/commit/' . $sha,
			);
		}
		return $this->success( $sha );
	}

	/**
	 * Describe confirmed GitHub publication.
	 *
	 * @param string $sha Validated Git object SHA.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function success( string $sha ): array {
		return array(
			'state'      => 'published',
			'commit_url' => 'https://github.com/' . $this->repository . '/commit/' . $sha,
		);
	}

	/**
	 * Strict trailers: duplicates or unrelated trailing text invalidate comparison.
	 *
	 * @param string $message Commit message containing comparison trailers.
	 * @return array|null
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function metadata( string $message ): ?array {
		if ( str_contains( $message, 'ZeroPress-Publisher: wordpress-wxr-bundle-v1' ) ) {
			foreach ( array( 'ZeroPress-Publisher', 'ZeroPress-Data-Hash', 'ZeroPress-Manifest-Blob-SHA', 'ZeroPress-Parts-Tree-SHA', 'ZeroPress-Base-Blob-SHA' ) as $key ) {
				if ( 1 !== preg_match_all( '/^' . preg_quote( $key, '/' ) . ':/m', $message ) ) {
					return null;
				}
			}
			$pattern = '/(?:\A|\n\n)ZeroPress-Publisher: wordpress-wxr-bundle-v1\nZeroPress-Data-Hash: sha256:([a-f0-9]{64})\nZeroPress-Manifest-Blob-SHA: ([a-f0-9]{40})\nZeroPress-Parts-Tree-SHA: ([a-f0-9]{40})\nZeroPress-Base-Blob-SHA: ([a-f0-9]{40})\n?\z/';
			return preg_match( $pattern, $message, $match ) ? array(
				'hash'     => $match[1],
				'manifest' => $match[2],
				'parts'    => $match[3],
				'base'     => $match[4],
			) : null;
		}
		$keys = array( 'ZeroPress-Publisher', 'ZeroPress-Data-Hash', 'ZeroPress-WXR-Blob-SHA', 'ZeroPress-Base-Blob-SHA' );
		foreach ( $keys as $key ) {
			if ( 1 !== preg_match_all( '/^' . preg_quote( $key, '/' ) . ':/m', $message ) ) {
				return null;
			}
		}
		$pattern = '/(?:\A|\n\n)ZeroPress-Publisher: wordpress-wxr-v1\nZeroPress-Data-Hash: sha256:([a-f0-9]{64})\nZeroPress-WXR-Blob-SHA: ([a-f0-9]{40})\nZeroPress-Base-Blob-SHA: ([a-f0-9]{40})\n?\z/';
		return preg_match( $pattern, $message, $match ) ? array(
			'hash' => $match[1],
			'wxr'  => $match[2],
			'base' => $match[3],
		) : null;
	}

	/**
	 * Accept only a regular Git blob.
	 *
	 * @param array $entry Git tree entry.
	 * @return string
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	private static function regular_sha( array $entry ): string {
		if ( 'blob' !== ( $entry['type'] ?? '' ) || ! in_array( $entry['mode'] ?? '', array( '100644', '100755' ), true ) ) {
			throw new Failure( 'TARGET_NOT_REGULAR_FILE' );
		}
		return self::sha( $entry['sha'] ?? '' );
	}
	/**
	 * Require trailers to bind every active commitment.
	 *
	 * @param array $metadata Parsed publication trailers.
	 * @param array $files Verified active tree commitments.
	 * @return bool
	 */
	private static function metadata_matches( array $metadata, array $files ): bool {
		unset( $metadata['hash'] );
		ksort( $metadata );
		ksort( $files );
		return $metadata === $files;
	}
	/**
	 * Reuse unchanged blobs and upload bounded parts sequentially.
	 *
	 * @param array                     $snapshot Verified target at an immutable commit.
	 * @param array                     $export Request-owned generated artifacts.
	 * @param bool                      $republish Whether unchanged content is explicitly republished.
	 * @param Publication_Progress|null $progress Optional publication stage feedback.
	 * @return array
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	private function publish_bundle( array $snapshot, array $export, bool $republish, ?Publication_Progress $progress ): array {
		if ( empty( $snapshot['capable'] ) ) {
			throw new Failure( 'WXR_SPLIT_REQUIRED', 409 );
		}
		$bundle  = $export['bundle'];
		$changed = 0;
		foreach ( $bundle->parts as $part ) {
			if ( ( $snapshot['parts'][ $part['path'] ] ?? null ) !== $part['git'] ) {
				++$changed;
			}
		}
		$base            = self::blob_sha( $export['base'] );
		$manifest        = self::blob_sha( $bundle->manifest );
		$upload_base     = ( $snapshot['files']['base'] ?? null ) !== $base;
		$upload_manifest = ( $snapshot['files']['manifest'] ?? null ) !== $manifest;
		// Part/base/manifest uploads, then subtree, root tree, commit and branch update.
		$writes = $changed + (int) $upload_base + (int) $upload_manifest + 4;
		// Only gaps between actual writes are a known minimum; network time is additional.
		$this->budget->require_time( $writes - 1 );
		$this->paced = true;
		$progress?->update( 'uploading' );
		$tree = array();
		foreach ( $bundle->parts as $part ) {
			if ( ( $snapshot['parts'][ $part['path'] ] ?? null ) !== $part['git'] ) {
				$content = $bundle->contents( $part );
				$this->upload_blob( $content, $part['git'] );
				unset( $content );
			}
			$tree[] = array(
				'path' => basename( $part['path'] ),
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $part['git'],
			);
		}
		if ( $upload_base ) {
			$this->upload_blob( $export['base'], $base );
		}
		if ( $upload_manifest ) {
			$this->upload_blob( $bundle->manifest, $manifest );
		}
		$parts   = self::sha( $this->request( 'POST', '/git/trees', array( 'tree' => $tree ) )['sha'] ?? '' );
		$files   = array(
			'manifest' => $manifest,
			'base'     => $base,
			'parts'    => $parts,
		);
		$entries = array(
			array(
				'path' => Wxr_Bundle::MANIFEST,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $manifest,
			),
			array(
				'path' => self::FILES['base'],
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $base,
			),
			array(
				'path' => Wxr_Bundle::DIRECTORY,
				'mode' => '040000',
				'type' => 'tree',
				'sha'  => $parts,
			),
		);
		if ( isset( $snapshot['files']['wxr'] ) ) {
			$entries[] = array(
				'path' => self::FILES['wxr'],
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => null,
			);
		}
		$title   = $republish ? 'chore(publish): republish WordPress site data' : 'chore(publish): update WordPress site data';
		$message = $title . "\n\nZeroPress-Publisher: wordpress-wxr-bundle-v1\nZeroPress-Data-Hash: sha256:" . $export['data_hash'] . "\nZeroPress-Manifest-Blob-SHA: " . $manifest . "\nZeroPress-Parts-Tree-SHA: " . $parts . "\nZeroPress-Base-Blob-SHA: " . $base;
		return $this->commit_publication( $snapshot, $entries, $files, $message, $export['data_hash'], $progress );
	}
	/**
	 * Verify the server response against the exact uploaded bytes.
	 *
	 * @param string $content One bounded UTF-8 blob.
	 * @param string $expected Expected Git blob SHA.
	 * @return void
	 * @throws Failure If validation, storage or the request budget fails.
	 */
	private function upload_blob( string $content, string $expected ): void {
		$bytes = Wxr_Bundle::request_size( $content );
		if ( strlen( $content ) > Wxr_Bundle::MAX_FILE || $bytes > Wxr_Bundle::MAX_FILE ) {
			throw new Failure( 'WXR_BUNDLE_LIMIT_EXCEEDED', 413 );
		}
		$this->budget->check( $bytes );
		$blob = $this->request(
			'POST',
			'/git/blobs',
			array(
				'content'  => $content,
				'encoding' => 'utf-8',
			)
		);
		if ( ( $blob['sha'] ?? '' ) !== $expected ) {
			throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
		}
	}

	/**
	 * Hash the exact Git blob representation.
	 *
	 * @param string $content Exact UTF-8 file content.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function blob_sha( string $content ): string {
		$hash = hash_init( 'sha1' );
		hash_update( $hash, 'blob ' . strlen( $content ) . "\0" );
		hash_update( $hash, $content );
		return hash_final( $hash );
	}

	/**
	 * Validate a Git object identifier.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function sha( string $value ): string {
		if ( ! preg_match( '/\A[a-f0-9]{40}\z/', $value ) ) {
			throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
		}
		return $value;
	}

	/**
	 * Send a bounded request to the fixed GitHub API host.
	 *
	 * @param string $method HTTP request method.
	 * @param string $path Repository-relative API route.
	 * @param array  $body Optional API request payload.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$this->budget->check( isset( $body['content'] ) ? strlen( $body['content'] ) : 0 );
		if ( $this->paced && 'GET' !== $method ) {
			$wait = max( 0, 1 - ( microtime( true ) - $this->last_write ) );
			$this->budget->require_time( $wait );
			if ( $wait > 0 ) {
				usleep( (int) ceil( $wait * 1000000 ) );
			}
		}
		$args = array(
			'method'              => $method,
			'redirection'         => 0,
			'limit_response_size' => 2097152,
			'headers'             => array(
				'Authorization'        => 'Bearer ' . $this->token,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'ZeroPress-Static/' . VERSION,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			try {
				// Throw before WordPress can repair invalid UTF-8: Git blobs must preserve every byte.
				$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
			}
			unset( $body );
		}
		$args['timeout'] = $this->budget->github_timeout();
		if ( 'PATCH' === $method ) {
			$this->ref_update_sent = true;
		}
		$response = wp_remote_request( 'https://api.github.com/repos/' . $this->repository . $path, $args );
		if ( 'GET' !== $method ) {
			$this->last_write = microtime( true );
		}
		if ( is_wp_error( $response ) ) {
			// A transport timeout can consume the shared deadline, not just an HTTP allowance.
			// Confirmed responses (especially PATCH success) must not be reclassified.
			$this->budget->check_time();
			throw new Failure( 'GITHUB_UNAVAILABLE', 503 );
		}
		$status      = wp_remote_retrieve_response_code( $response );
		$value       = json_decode( wp_remote_retrieve_body( $response ), true );
		$description = is_string( $value['message'] ?? null ) ? $value['message'] : '';
		if ( 429 === $status || ( 403 === $status && ( '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) || wp_remote_retrieve_header( $response, 'retry-after' ) ) ) ) {
			throw new Failure( 'GITHUB_RATE_LIMITED', 429 );
		}
		if ( 413 === $status ) {
			throw new Failure( 'GITHUB_PAYLOAD_TOO_LARGE', 413 );
		}
		if ( 401 === $status ) {
			throw new Failure( 'GITHUB_AUTH_FAILED', 403 );
		}
		if ( 403 === $status ) {
			if ( preg_match( '/protected branch|repository rule|rule violation|GH006|GH013/i', $description ) ) {
				throw new Failure( 'GITHUB_BRANCH_RULE', 403 );
			}
			throw new Failure( 'GET' === $method ? 'GITHUB_PERMISSION_DENIED' : 'GITHUB_WRITE_REJECTED', 403 );
		}
		if ( 404 === $status ) {
			throw new Failure( 'TARGET_NOT_FOUND', 404 );
		}
		if ( 409 === $status || ( 422 === $status && preg_match( '/fast.forward|reference update failed/i', $description ) ) ) {
			throw new Failure( 'GITHUB_CONFLICT', 409 );
		}
		if ( 422 === $status && preg_match( '/exceeds.*(?:file size limit|maximum.*size)|file.*too large|blob.*too large/i', $description ) ) {
			throw new Failure( 'GITHUB_PAYLOAD_TOO_LARGE', 413 );
		}
		if ( 422 === $status ) {
			throw new Failure( preg_match( '/protected branch|repository rule|rule violation|GH006|GH013/i', $description ) ? 'GITHUB_BRANCH_RULE' : 'GITHUB_WRITE_REJECTED', 409 );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new Failure( 'GITHUB_UNAVAILABLE', 503 );
		}
		if ( ! is_array( $value ) ) {
			throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
		}
		return $value;
	}
}
