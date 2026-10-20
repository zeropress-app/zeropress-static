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
		$files = array();
		foreach ( self::FILES as $key => $path ) {
			$matches = array_values( array_filter( $tree['tree'], static fn( $entry ) => ( $entry['path'] ?? null ) === $path ) );
			if ( 1 !== count( $matches ) ) {
				throw new Failure( 'TARGET_NOT_FOUND', 404 );
			}
			$entry = $matches[0];
			if ( 'blob' !== ( $entry['type'] ?? '' ) || ! in_array( $entry['mode'] ?? '', array( '100644', '100755' ), true ) ) {
				throw new Failure( 'TARGET_NOT_REGULAR_FILE' );
			}
			$files[ $key ] = self::sha( $entry['sha'] ?? '' );
		}
		$latest    = array();
		$data_hash = null;
		foreach ( self::FILES as $key => $path ) {
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
			if ( $metadata && $metadata['wxr'] === $files['wxr'] && $metadata['base'] === $files['base'] ) {
				$data_hash = $metadata['hash'];
			}
		}
		return array(
			'repository' => $this->repository,
			'branch'     => $branch,
			'head'       => $head,
			'tree'       => $tree_sha,
			'files'      => $files,
			'data_hash'  => $data_hash,
			'latest'     => $latest,
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
	 * @throws \Throwable If transport fails before the branch update is dispatched.
	 */
	public function publish( array $snapshot, array $export, bool $republish = false, ?Publication_Progress $progress = null ): array {
		$progress?->update( 'uploading' );
		$entries = array();
		$blobs   = array();
		foreach ( self::FILES as $key => $path ) {
			$this->budget->check( strlen( $export[ $key ] ) );
			$expected = self::blob_sha( $export[ $key ] );
			$blob     = $this->request(
				'POST',
				'/git/blobs',
				array(
					'content'  => base64_encode( $export[ $key ] ),
					'encoding' => 'base64',
				)
			);
			if ( ( $blob['sha'] ?? '' ) !== $expected ) {
				throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
			}
			$blobs[ $key ] = $expected;
			$entries[]     = array(
				'path' => $path,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $expected,
			);
		}
		$progress?->update( 'committing' );
		$tree    = $this->request(
			'POST',
			'/git/trees',
			array(
				'base_tree' => $snapshot['tree'],
				'tree'      => $entries,
			)
		);
		$title   = $republish ? 'chore(publish): republish WordPress site data' : 'chore(publish): update WordPress site data';
		$message = $title . "\n\nZeroPress-Publisher: wordpress-wxr-v1\n"
			. 'ZeroPress-Data-Hash: sha256:' . $export['data_hash'] . "\n"
			. 'ZeroPress-WXR-Blob-SHA: ' . $blobs['wxr'] . "\n"
			. 'ZeroPress-Base-Blob-SHA: ' . $blobs['base'];
		$commit  = $this->request(
			'POST',
			'/git/commits',
			array(
				'message' => $message,
				'tree'    => self::sha( $tree['sha'] ?? '' ),
				'parents' => array( $snapshot['head'] ),
			)
		);
		$sha     = self::sha( $commit['sha'] ?? '' );

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
				if ( $commit_confirmed && $current['files'] === $blobs && $current['data_hash'] === $export['data_hash'] ) {
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
			$args['body']                    = wp_json_encode( $body );
			if ( false === $args['body'] ) {
				throw new Failure( 'GITHUB_RESPONSE_INVALID', 502 );
			}
			unset( $body );
		}
		$args['timeout'] = $this->budget->github_timeout();
		if ( 'PATCH' === $method ) {
			$this->ref_update_sent = true;
		}
		$response = wp_remote_request( 'https://api.github.com/repos/' . $this->repository . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new Failure( 'GITHUB_UNAVAILABLE', 503 );
		}
		$status      = wp_remote_retrieve_response_code( $response );
		$value       = json_decode( wp_remote_retrieve_body( $response ), true );
		$description = is_string( $value['message'] ?? null ) ? $value['message'] : '';
		if ( 429 === $status || ( 403 === $status && ( '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) || wp_remote_retrieve_header( $response, 'retry-after' ) ) ) ) {
			throw new Failure( 'GITHUB_RATE_LIMITED', 429 );
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
