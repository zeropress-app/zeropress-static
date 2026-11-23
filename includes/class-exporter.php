<?php
/**
 * Allowlisted public WXR export; does not execute content filters.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Builds the two public build inputs from the current WordPress database. */
final class Exporter {
	/**
	 * Shared request resource budget.
	 *
	 * @var Resource_Budget
	 */
	private Resource_Budget $budget;
	/** Generation-local auxiliary readers.
	 *
	 * @var Export_Reads
	 */
	private Export_Reads $reads;
	/**
	 * Per-export size diagnostics.
	 *
	 * @var Content_Body
	 */
	private Content_Body $body_policy;
	/**
	 * Rewriter.
	 *
	 * @var Media_Rewriter
	 */
	private Media_Rewriter $rewriter;
	/**
	 * Parsed WordPress home address for matching visitor links.
	 *
	 * @var array
	 */
	private array $source_site;
	/**
	 * Optional static site origin.
	 *
	 * @var string
	 */
	private string $public_url;
	/**
	 * Authors.
	 *
	 * @var array
	 */
	private array $authors = array();
	/**
	 * Categories.
	 *
	 * @var array
	 */
	private array $categories = array();
	/**
	 * Tags.
	 *
	 * @var array
	 */
	private array $tags = array();
	/**
	 * Referenced attachment IDs; true for featured images, false for body-only media.
	 *
	 * @var array
	 */
	private array $attachments = array();
	/**
	 * Public ids.
	 *
	 * @var array
	 */
	private array $public_ids = array();
	/** Optional preparation-time URL projection.
	 *
	 * @var Menu_Destinations|null
	 */
	private ?Menu_Destinations $destinations = null;
	/** Menu records captured for preparation, before source cache cleanup.
	 *
	 * @var array
	 */
	private array $review_entries = array();
	/** Public object counts.
	 *
	 * @var array
	 */
	private array $counts = array(
		'posts' => 0,
		'pages' => 0,
		'media' => 0,
		'menus' => 0,
	);

	/**
	 * Share the caller's resource budget across generation and publication.
	 *
	 * @param Resource_Budget|null $budget Request resource budget.
	 * @param Export_Review|null   $review Optional preparation-only display collector.
	 */
	public function __construct( ?Resource_Budget $budget = null, private ?Export_Review $review = null ) {
		$this->budget = $budget ?? Resource_Budget::from_request();
	}

	/**
	 * Create bounded public WXR and deterministic build settings.
	 *
	 * @param array $settings Settings snapshot; credentials remain server-side.
	 * @param int   $now Optional fixed export time for reproducible tests.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function generate( array $settings, ?int $now = null ): array {
		$export = $this->generate_files( $settings, $now );
		$file   = $export['wxr'];
		try {
			$export['wxr'] = $file->contents();
			return $export;
		} finally {
			$file->close();
		}
	}

	/**
	 * Generate request-owned WXR storage; callers must close returned file or bundle handles.
	 *
	 * @param array  $settings Validated settings snapshot.
	 * @param int    $now Optional fixed generation time.
	 * @param string $mode Single, automatic or established bundle transport.
	 * @return array
	 */
	public function generate_files( array $settings, ?int $now = null, string $mode = 'single' ): array {
		if ( ! Publication_Runtime::active() ) {
			return Publication_Runtime::run( fn() => $this->generate_files( $settings, $now, $mode ) );
		}
		$this->budget->check();
		$writer    = new Wxr_Writer( $this->budget, $mode );
		$runtime   = wp_cache_supports( 'flush_runtime' );
		$suspended = wp_suspend_cache_addition();
		if ( ! $runtime ) {
			// Do not fall back to flushing a shared Redis/Memcached cache.
			wp_suspend_cache_addition( true );
		}
		try {
			return Export_Reads::scope(
				function ( Export_Reads $reads ) use ( $settings, $now, $writer ) {
					$this->reads = $reads;
					return $this->write_export( $settings, $now, $writer );
				},
				$this->budget
			);
		} finally {
			$writer->close();
			$this->release_cache();
			wp_suspend_cache_addition( $suspended );
		}
	}

	/**
	 * Release only the request's runtime cache when its implementation supports it.
	 */
	private function release_cache(): void {
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Extract records independently of how the writer stores complete items.
	 *
	 * @param array      $settings Validated settings snapshot.
	 * @param int|null   $now Optional fixed generation time.
	 * @param Wxr_Writer $writer Output owner.
	 * @return array
	 */
	private function write_export( array $settings, ?int $now, Wxr_Writer $writer ): array {
		$source               = untrailingslashit( home_url( '/' ) );
		$source_site          = wp_parse_url( $source );
		$this->source_site    = is_array( $source_site ) ? $source_site : array();
		$this->public_url     = $settings['public_url'];
		$this->rewriter       = new Media_Rewriter( $settings['media_rules'], $this->budget );
		$this->authors        = array();
		$this->categories     = array();
		$this->tags           = array();
		$this->attachments    = array();
		$this->public_ids     = array();
		$this->counts         = array_fill_keys( array( 'posts', 'pages', 'media', 'menus' ), 0 );
		$this->body_policy    = new Content_Body();
		$this->destinations   = null;
		$this->review_entries = array();
		if ( $this->review ) {
			$entries = array();
			foreach ( Menus::selected( Menus::normalize( $settings['menu_mapping'] ?? array() ) ) as $menu ) {
				$items                                  = $this->reads->menu_items( $menu );
				$this->review_entries[ $menu->term_id ] = is_array( $items ) ? $items : array();
				$entries                                = array_merge( $entries, $this->review_entries[ $menu->term_id ] );
			}
			$this->destinations = new Menu_Destinations( $settings['public_url'], $this->budget, $entries );
		}
		foreach ( ( new Content_Reader( $this->budget ) )->pages() as $page ) {
			foreach ( $this->reads->documents( $page ) as $posts ) {
				foreach ( $posts as $post ) {
					if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
						continue; }
					$this->public_ids[ $post->ID ] = $post->post_type;
					++$this->counts[ 'post' === $post->post_type ? 'posts' : 'pages' ];
					$this->budget->check( strlen( $post->post_content ) + strlen( $post->post_excerpt ) );
					$writer->item( $this->document( $post ), (int) $post->ID );
				}
				unset( $posts, $post );
				$this->release_cache();
			}
			unset( $page );
		}

		$menu_terms = $this->menus( Menus::normalize( $settings['menu_mapping'] ?? array() ), $writer );
		$this->release_cache();
		$this->write_media( $writer );
		$base   = self::json( $this->base( $settings ) );
		$source = '' !== $this->public_url ? $this->public_url : $source;
		$this->budget->check( strlen( $base ) );
		$result = $writer->finish(
			$this->channel( $source, $menu_terms ),
			$this->element( 'pubDate', gmdate( 'D, d M Y H:i:s +0000', $now ?? time() ) ),
			$base,
			$now
		);
		return array_merge(
			$result,
			array(
				'base'             => $base,
				'counts'           => $this->counts,
				'bytes'            => array(
					'wxr'  => $result['wxr_bytes'],
					'base' => strlen( $base ),
				),
				'rewritten_values' => $this->rewriter->replacements,
				'warnings'         => $this->body_policy->warnings(),
			)
		);
	}

	/**
	 * Yield channel metadata in the original order, before item records.
	 *
	 * @param string $source Public site URL.
	 * @param array  $menu_terms Menu taxonomy XML.
	 * @return \Generator<string>
	 */
	private function channel( string $source, array $menu_terms ): \Generator {
		$site = self::site_metadata();
		yield $this->element( 'title', $site['title'] )
			. $this->element( 'description', $site['description'] )
			. $this->element( 'link', $source )
			. $this->element( 'language', $site['locale'] )
			. $this->element( 'wp:wxr_version', '1.2' )
			. $this->element( 'wp:base_site_url', $source )
			. $this->element( 'wp:base_blog_url', $source );
		ksort( $this->authors, SORT_NUMERIC );
		foreach ( $this->authors as $id => $name ) {
			yield array(
				'kind' => 'authors',
				'id'   => (int) $id,
				'xml'  => "<wp:author>\n"
									. $this->element( 'wp:author_id', (string) $id )
									. $this->element( 'wp:author_login', 'author-' . $id )
									. $this->element( 'wp:author_display_name', $name ) . "</wp:author>\n",
			);
		}
		ksort( $this->categories, SORT_NUMERIC );
		foreach ( $this->categories as $term ) {
			$parent = $term->parent ? $this->reads->term( (int) $term->parent, 'category' ) : null;
			yield array(
				'kind' => 'categories',
				'id'   => (int) $term->term_id,
				'xml'  => "<wp:category>\n" . $this->element( 'wp:term_id', (string) $term->term_id )
					. $this->element( 'wp:category_nicename', $term->slug )
					. $this->element( 'wp:cat_name', self::display_text( $term->name ) )
					. $this->element( 'wp:category_parent', $parent instanceof \WP_Term ? $parent->slug : '' )
					. $this->element( 'wp:category_description', self::html_text( $term->description ) ) . "</wp:category>\n",
			);
		}
		ksort( $this->tags, SORT_NUMERIC );
		foreach ( $this->tags as $term ) {
			yield array(
				'kind' => 'tags',
				'id'   => (int) $term->term_id,
				'xml'  => "<wp:tag>\n" . $this->element( 'wp:term_id', (string) $term->term_id )
					. $this->element( 'wp:tag_slug', $term->slug ) . $this->element( 'wp:tag_name', self::display_text( $term->name ) )
					. $this->element( 'wp:tag_description', self::html_text( $term->description ) ) . "</wp:tag>\n",
			);
		}
		yield from $menu_terms;
	}

	/**
	 * Export one public post or page using allowlisted fields.
	 *
	 * @param \WP_Post $post WordPress post object.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function document( \WP_Post $post ): string {
		Content_Body::check( strlen( $post->post_content ), (int) $post->ID );
		$content = $this->rewriter->content( $post->post_content );
		$this->body_policy->record( max( strlen( $post->post_content ), strlen( $content ) ), (int) $post->ID );
		$user                                = get_userdata( $post->post_author );
		$this->authors[ $post->post_author ] = $user ? self::display_text( $user->display_name ) : __( 'Unknown author', 'zeropress-static' );
		$link                                = $this->site_link( get_permalink( $post ) );
		$this->destinations?->observe( $post, $link );
		$xml = "<item>\n" . $this->element( 'title', self::display_text( $post->post_title ) )
			. $this->element( 'link', $link )
			. $this->element( 'dc:creator', 'author-' . $post->post_author )
			. $this->element( 'content:encoded', $content )
			. $this->element( 'excerpt:encoded', self::html_text( $this->rewriter->content( $post->post_excerpt ) ) );
		foreach ( array(
			'post_id'           => $post->ID,
			'post_type'         => $post->post_type,
			'status'            => 'publish',
			'post_name'         => $post->post_name,
			'post_parent'       => $post->post_parent,
			'post_date'         => $post->post_date,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified_gmt' => $post->post_modified_gmt,
			'comment_status'    => $post->comment_status,
		) as $key => $value ) {
			$xml .= $this->element( 'wp:' . $key, (string) $value );
		}
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				throw new Failure( 'SOURCE_READ_FAILED', 503 );
			}
			$terms = false === $terms ? array() : $terms;
			usort( $terms, static fn( $a, $b ) => $a->term_id <=> $b->term_id );
			foreach ( $terms as $term ) {
				$this->budget->check( strlen( $term->name ) + strlen( $term->slug ) );
				if ( 'category' === $taxonomy ) {
					$this->add_category( $term );
				} else {
					$this->tags[ $term->term_id ] = $term;
				}
				$xml .= '<category domain="' . $taxonomy . '" nicename="' . self::attribute( $term->slug ) . '">' . self::cdata( self::display_text( $term->name ) ) . "</category>\n";
			}
		}
		$thumbnail = get_post_thumbnail_id( $post );
		if ( $thumbnail ) {
			$this->attachments[ $thumbnail ] = true;
			$xml                            .= $this->meta( '_thumbnail_id', (string) $thumbnail );
		}
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$this->attachments[ (int) $id ] ??= false;
			}
		}
		$this->block_attachments( parse_blocks( $post->post_content ) );
		foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_genesis_description' ) as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) && '' !== $value ) {
				$xml .= $this->meta( $key, self::display_text( $value ) );
			}
		}
		return $xml . "</item>\n";
	}

	/**
	 * Project a WordPress visitor link onto the root-hosted static site.
	 *
	 * @param string $url Post, page or menu URL.
	 * @return string
	 */
	private function site_link( string $url ): string {
		return Menu_Destinations::site_link( $url, $this->public_url, $this->source_site );
	}

	/**
	 * Collect the category and its ancestors once.
	 *
	 * @param \WP_Term $term WordPress taxonomy term.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function add_category( \WP_Term $term ): void {
		if ( isset( $this->categories[ $term->term_id ] ) ) {
			return;
		}
		$this->categories[ $term->term_id ] = $term;
		if ( $term->parent ) {
			$parent = $this->reads->term( (int) $term->parent, 'category' );
			if ( $parent instanceof \WP_Term ) {
				$this->add_category( $parent );
			}
		}
	}

	/**
	 * Collect attachment IDs referenced by supported media blocks.
	 *
	 * @param array $blocks Parsed WordPress blocks.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function block_attachments( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], array( 'core/image', 'core/audio', 'core/video', 'core/file', 'core/cover', 'core/media-text', 'core/gallery' ), true ) ) {
				foreach ( array( 'id', 'mediaId' ) as $key ) {
					if ( ! empty( $block['attrs'][ $key ] ) && is_numeric( $block['attrs'][ $key ] ) ) {
						$this->attachments[ (int) $block['attrs'][ $key ] ] ??= false;
					}
				}
				foreach ( (array) ( $block['attrs']['ids'] ?? array() ) as $id ) {
					if ( is_numeric( $id ) ) {
						$this->attachments[ (int) $id ] ??= false;
					}
				}
			}
			$this->block_attachments( $block['innerBlocks'] );
		}
	}

	/**
	 * Inspect all referenced URLs, then hydrate only selected featured images.
	 *
	 * @param Wxr_Writer $writer Complete-item output owner.
	 * @throws Failure If a selected featured image disappears during generation.
	 */
	private function write_media( Wxr_Writer $writer ): void {
		ksort( $this->attachments, SORT_NUMERIC );
		$references    = new Media_References( $this->budget );
		$featured_urls = array();
		foreach ( $this->reads->attachments( array_keys( $this->attachments ), false ) as $batch ) {
			foreach ( $batch as $id => $post ) {
				$url = $this->reads->attachment_url( $post );
				if ( ! $url ) {
					continue;
				}
				// Preserve admission even for a URL whose record will be omitted.
				Settings::url( strtok( $url, '?#' ) );
				$references->add( (int) $id, $this->rewriter->url( $url ), $this->attachments[ $id ] );
				if ( $this->attachments[ $id ] ) {
					$featured_urls[ $id ] = $url;
				}
			}
			unset( $batch, $post );
			$this->release_cache();
		}
		$selected = $references->selected();
		unset( $references );
		$featured = array_keys( array_filter( array_intersect_key( $this->attachments, $selected ) ) );
		$details  = $this->featured_media( $featured );
		foreach ( $selected as $id => $url ) {
			$this->budget->check();
			if ( $this->attachments[ $id ] ) {
				if ( ! $details->valid() || $id !== $details->key() ||
					$this->reads->attachment_url( $details->current() ) !== $featured_urls[ $id ] ) {
					throw new Failure( 'CONTENT_CHANGED', 409 );
				}
				$xml = $this->attachment( $id, $details->current(), $url );
			} else {
				// A real attachment URL preserves inference without unused metadata.
				$xml = "<item>\n" . $this->element( 'wp:post_id', (string) $id )
					. $this->element( 'wp:post_type', 'attachment' )
					. $this->element( 'wp:status', 'inherit' )
					. $this->element( 'wp:attachment_url', $url ) . "</item>\n";
				++$this->counts['media'];
			}
			$writer->item( $xml, $id, 'attachments' );
			if ( '' !== $xml ) {
				$this->review?->record(
					'media',
					array(
						'id'       => $id,
						'url'      => $url,
						'featured' => $this->attachments[ $id ],
					)
				);
			}
			if ( $this->attachments[ $id ] ) {
				$details->next();
			}
		}
	}

	/**
	 * Keep featured metadata scoped to the current admitted batch.
	 *
	 * @param array $ids Selected featured attachment IDs.
	 * @return \Generator<int,object>
	 */
	private function featured_media( array $ids ): \Generator {
		foreach ( $this->reads->attachments( $ids ) as $batch ) {
			yield from $batch;
			unset( $batch );
			$this->release_cache();
		}
	}

	/**
	 * Export only required attachment metadata.
	 *
	 * @param int    $id WordPress object ID.
	 * @param object $post Admitted private attachment projection.
	 * @param string $url Previously admitted and rewritten URL.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function attachment( int $id, object $post, string $url ): string {
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password ) {
			return '';
		}
		$xml      = "<item>\n" . $this->element( 'title', self::display_text( $post->post_title ) )
			. $this->element( 'wp:post_id', (string) $id )
			. $this->element( 'wp:post_type', 'attachment' )
			. $this->element( 'wp:status', 'inherit' )
			. $this->element( 'wp:post_modified_gmt', $post->post_modified_gmt )
			. $this->element( 'wp:attachment_url', $url );
		$metadata = wp_get_attachment_metadata( $id );
		if ( is_array( $metadata ) ) {
			$allowed = array();
			foreach ( array( 'width', 'height' ) as $dimension ) {
				if ( isset( $metadata[ $dimension ] ) && is_numeric( $metadata[ $dimension ] ) && (int) $metadata[ $dimension ] > 0 ) {
					$allowed[ $dimension ] = (int) $metadata[ $dimension ];
				}
			}
			if ( self::relative_media_path( $metadata['file'] ?? null ) ) {
				$allowed['file'] = $metadata['file'];
			}
			// WXR uses PHP serialization; serialize only the allowlisted original fields.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WXR requires serialized scalar metadata.
			$xml .= $this->meta( '_wp_attachment_metadata', serialize( $allowed ) );
		}
		$file = get_post_meta( $id, '_wp_attached_file', true );
		$alt  = get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( self::relative_media_path( $file ) ) {
			$xml .= $this->meta( '_wp_attached_file', $file );
		}
		$xml .= $this->meta( '_wp_attachment_image_alt', is_string( $alt ) ? self::display_text( $alt ) : '' );
		++$this->counts['media'];
		return $xml . "</item>\n";
	}

	/**
	 * Exclude local absolute paths and non-scalar metadata.
	 *
	 * @param mixed $path Attachment-relative path.
	 * @return bool
	 */
	private static function relative_media_path( mixed $path ): bool {
		return is_string( $path ) && '' !== $path && ! str_starts_with( $path, '/' ) &&
			! preg_match( '/[\\\\:\x00-\x1f\x7f]/', $path ) &&
			! in_array( '..', explode( '/', $path ), true );
	}

	/**
	 * Export selected classic menus, excluding private content targets.
	 *
	 * @param array      $mapping Output-to-source menu mapping.
	 * @param Wxr_Writer $writer Complete-item output owner.
	 * @return array Menu taxonomy XML records.
	 */
	private function menus( array $mapping, Wxr_Writer $writer ): array {
		$terms = array();
		$menus = Menus::selected( $mapping );
		foreach ( $menus as $menu ) {
			$entries = $this->review_entries[ $menu->term_id ] ?? $this->reads->menu_items( $menu );
			$entries = array_filter(
				is_array( $entries ) ? $entries : array(),
				function ( $entry ) {
					if ( 'publish' !== $entry->post_status || ( 'custom' === $entry->type && empty( $entry->url ) ) ) {
						return false;
					}
					if ( 'post_type' === $entry->type ) {
						return isset( $this->public_ids[ $entry->object_id ] );
					}
					if ( 'taxonomy' === $entry->type ) {
						return 'category' === $entry->object ? isset( $this->categories[ $entry->object_id ] ) :
						( 'post_tag' === $entry->object && isset( $this->tags[ $entry->object_id ] ) );
					}
					return 'custom' === $entry->type;
				}
			);
			++$this->counts['menus'];
			$terms[] = array(
				'kind' => 'terms',
				'id'   => (int) $menu->term_id,
				'xml'  => "<wp:term>\n" . $this->element( 'wp:term_id', (string) $menu->term_id )
					. $this->element( 'wp:term_taxonomy', 'nav_menu' )
					. $this->element( 'wp:term_slug', $menu->slug ) . $this->element( 'wp:term_name', self::display_text( $menu->name ) ) . "</wp:term>\n",
			);
			$ids     = array_fill_keys( array_map( static fn( $entry ) => $entry->ID, $entries ), true );
			$parents = array();
			if ( $this->review ) {
				$this->review->menu( Menus::review_menu( $menu, array_keys( $mapping, (string) $menu->term_id, true ), count( $entries ) ) );
				foreach ( $entries as $entry ) {
					$parents[ $entry->ID ] = isset( $ids[ $entry->menu_item_parent ] ) ? (int) $entry->menu_item_parent : 0;
				}
			}
			usort( $entries, static fn( $a, $b ) => $a->menu_order === $b->menu_order ? ( $a->ID <=> $b->ID ) : ( $a->menu_order <=> $b->menu_order ) );
			foreach ( $entries as $entry ) {
				$item = "<item>\n" . $this->element( 'title', self::display_text( $entry->title ) )
					. $this->element( 'wp:post_id', (string) $entry->ID ) . $this->element( 'wp:post_type', 'nav_menu_item' )
					. $this->element( 'wp:status', 'publish' ) . $this->element( 'wp:menu_order', (string) $entry->menu_order )
					. '<category domain="nav_menu" nicename="' . self::attribute( $menu->slug ) . '">' . self::cdata( self::display_text( $menu->name ) ) . "</category>\n";
				foreach ( array(
					'_menu_item_menu_item_parent' => isset( $ids[ $entry->menu_item_parent ] ) ? $entry->menu_item_parent : 0,
					'_menu_item_object'           => $entry->object,
					'_menu_item_object_id'        => $entry->object_id,
					'_menu_item_target'           => $entry->target,
					'_menu_item_type'             => $entry->type,
					'_menu_item_url'              => $this->site_link( $entry->url ),
				) as $key => $value ) {
					$item .= $this->meta( $key, (string) $value );
				}
				$writer->item( $item . "</item>\n", (int) $entry->ID, 'menus' );
				$this->review?->record(
					'menus',
					array(
						'menu'       => (int) $menu->term_id,
						'id'         => (int) $entry->ID,
						'parent'     => $parents[ $entry->ID ],
						'label'      => self::display_text( $entry->title ),
						'url'        => $this->destinations?->url( $entry, 'category' === $entry->object ? ( $this->categories[ $entry->object_id ] ?? null ) : ( 'post_tag' === $entry->object ? ( $this->tags[ $entry->object_id ] ?? null ) : null ) ),
						'new_window' => '_blank' === $entry->target,
						'depth'      => Export_Review::depth( (int) $entry->ID, $parents ),
					)
				);
				unset( $item );
			}
		}
		return $terms;
	}

	/** Read WordPress site values shared by the settings summary and export. */
	public static function site_metadata(): array {
		return Export_Reads::scope(
			static function ( Export_Reads $reads ): array {
				$reads->prime_posts( array( (int) get_option( 'site_icon' ) ) );
				$site = array(
					'title'          => self::display_text( get_option( 'blogname' ) ),
					'description'    => self::display_text( get_option( 'blogdescription' ) ),
					'locale'         => str_replace( '_', '-', get_locale() ),
					'timezone'       => wp_timezone_string(),
					'posts_per_page' => max( 1, (int) get_option( 'posts_per_page', 10 ) ),
					'favicon'        => array(),
				);
				foreach ( array(
					'icon'             => 512,
					'apple_touch_icon' => 180,
				) as $key => $size ) {
					$url = get_site_icon_url( $size );
					if ( '' !== $url ) {
						$site['favicon'][ $key ] = $url;
					}
				}
				return $site;
			}
		);
	}

	/**
	 * Create the importer settings owned by this plugin.
	 *
	 * @param array $settings Settings snapshot; credentials remain server-side.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function base( array $settings ): array {
		$source_api = untrailingslashit( home_url( '/' ) ) . '/wp-json/wp/v2';
		$site       = self::site_metadata();
		$base       = array(
			'$schema'  => 'https://www.schemastore.org/zeropress-wxr-import-base-0.7.json',
			'version'  => '0.7',
			'import'   => array( 'menu_mapping' => (object) Menus::normalize( $settings['menu_mapping'] ?? array() ) ),
			'site'     => array(
				'title'            => $site['title'],
				'description'      => $site['description'],
				'url'              => $settings['public_url'],
				'locale'           => $site['locale'],
				'timezone'         => $site['timezone'],
				'posts_per_page'   => $site['posts_per_page'],
				'robots'           => array( 'allow_indexing' => $settings['allow_indexing'] ),
				'search'           => array( 'enabled' => $settings['search_enabled'] ?? true ),
				'feed'             => array( 'enabled' => $settings['feed_enabled'] ?? true ),
				'archive'          => array( 'enabled' => $settings['archive_enabled'] ?? true ),
				'footer'           => array( 'attribution' => $settings['attribution'] ?? true ),
				'expose_generator' => $settings['expose_generator'] ?? true,
				'date_style'       => 'medium',
				'time_style'       => 'none',
			),
			'comments' => array(
				'enabled'      => $settings['comments_enabled'],
				'provider'     => 'wordpress',
				'api_base_url' => '' !== $settings['comments_api_url'] ? $settings['comments_api_url'] : $source_api,
				'per_page'     => 50,
				'order'        => 'desc',
				'threading'    => array(
					'enabled'   => true,
					'max_depth' => 2,
				),
			),
		);
		$copyright  = $settings['copyright_text'] ?? '';
		if ( '' !== $copyright ) {
			$base['site']['footer']['copyright_text'] = $copyright;
		}
		$base['newsletter'] = array( 'enabled' => $settings['newsletter_enabled'] ?? false );
		if ( $base['newsletter']['enabled'] ) {
			foreach ( array( 'title', 'description', 'button_label', 'signup_url', 'embed_url' ) as $key ) {
				$value = $settings[ 'newsletter_' . $key ] ?? '';
				if ( '' !== $value ) {
					$base['newsletter'][ $key ] = $value;
				}
			}
		}
		foreach ( $site['favicon'] as $key => $url ) {
			$base['site']['favicon'][ $key ] = $this->rewriter->url( $url );
		}
		return $base;
	}

	/**
	 * Decode one HTML-entity layer for a plain-text display field.
	 *
	 * WordPress stores several names escaped for HTML. Preview Data expects text;
	 * the renderer escapes it for its output context. Never apply this to body
	 * HTML, URLs, slugs or serialized metadata, or recursively decode literals.
	 *
	 * @param string $value WordPress display value.
	 * @return string
	 */
	private static function display_text( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Convert an HTML-capable summary to the importer's plain-text field.
	 *
	 * Strip actual tags before decoding so escaped code remains literal text.
	 *
	 * @param string $value WordPress excerpt or taxonomy description.
	 * @return string
	 */
	private static function html_text( string $value ): string {
		return self::display_text( wp_strip_all_tags( $value ) );
	}

	/**
	 * Serialize one allowlisted WXR element.
	 *
	 * @param string $tag Allowlisted XML element name.
	 * @param string $text Original text.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function element( string $tag, string $text ): string {
		$this->budget->check( strlen( $text ) );
		return '<' . $tag . '>' . self::cdata( $text ) . '</' . $tag . ">\n";
	}

	/**
	 * Serialize one allowlisted WXR metadata pair.
	 *
	 * @param string $key Allowlisted field name.
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function meta( string $key, string $value ): string {
		return "<wp:postmeta>\n" . $this->element( 'wp:meta_key', $key ) . $this->element( 'wp:meta_value', $value ) . "</wp:postmeta>\n";
	}

	/**
	 * Preserve XML text including CDATA terminators.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function cdata( string $value ): string {
		$value = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', wp_check_invalid_utf8( $value, true ) );
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $value ) . ']]>';
	}

	/**
	 * Escape an XML attribute.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function attribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Serialize deterministic two-space JSON with a final newline.
	 *
	 * @param array $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function json( array $value ): string {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new Failure( 'EXPORT_FAILED', 503 );
		}
		// PHP's JSON formatter uses four spaces; the shared build input uses two.
		return preg_replace_callback( '/^ +/m', static fn( $m ) => str_repeat( ' ', strlen( $m[0] ) / 2 ), $json ) . "\n";
	}
}
