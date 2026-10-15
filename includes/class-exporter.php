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
	/**
	 * Rewriter.
	 *
	 * @var Media_Rewriter
	 */
	private Media_Rewriter $rewriter;
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
	 * Attachments.
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
	/**
	 * Public object counts.
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
	 */
	public function __construct( ?Resource_Budget $budget = null ) {
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
		$this->budget->check();
		$this->rewriter = new Media_Rewriter( $settings['media_rules'] );
		$items          = '';
		$page           = 1;
		do {
			$this->budget->check( strlen( $items ) );
			$query = new \WP_Query(
				array(
					'post_type'           => array( 'post', 'page' ),
					'post_status'         => 'publish',
					'has_password'        => false,
					'posts_per_page'      => 100,
					'paged'               => $page++,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'suppress_filters'    => false,
				)
			);
			$posts = $query->posts;
			foreach ( $posts as $post ) {
				if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
					continue;
				}
				$this->public_ids[ $post->ID ] = $post->post_type;
				++$this->counts[ 'post' === $post->post_type ? 'posts' : 'pages' ];
				$this->budget->check( strlen( $items ) + strlen( $post->post_content ) + strlen( $post->post_excerpt ) );
				$items .= $this->document( $post );
				$this->budget->check( strlen( $items ) );
			}
			$batch_count = count( $posts );
			unset( $query, $posts, $post );
		} while ( 100 === $batch_count );

		$menu_data = $this->menus( Menus::normalize( $settings['menu_mapping'] ?? array() ) );
		$this->budget->check( strlen( $items ) + strlen( $menu_data['items'] ) );
		$items .= $menu_data['items'];
		ksort( $this->attachments, SORT_NUMERIC );
		foreach ( array_keys( $this->attachments ) as $id ) {
			$this->budget->check( strlen( $items ) );
			$items .= $this->attachment( $id );
			$this->budget->check( strlen( $items ) );
		}
		$source  = untrailingslashit( home_url( '/' ) );
		$site    = self::site_metadata();
		$channel = $this->element( 'title', $site['title'] )
			. $this->element( 'description', $site['description'] )
			. $this->element( 'link', $source )
			. $this->element( 'language', $site['locale'] )
			. $this->element( 'wp:wxr_version', '1.2' )
			. $this->element( 'wp:base_site_url', $source )
			. $this->element( 'wp:base_blog_url', $source );
		ksort( $this->authors, SORT_NUMERIC );
		foreach ( $this->authors as $id => $name ) {
			$channel .= "<wp:author>\n"
				. $this->element( 'wp:author_id', (string) $id )
				. $this->element( 'wp:author_login', 'author-' . $id )
				. $this->element( 'wp:author_display_name', $name ) . "</wp:author>\n";
		}
		ksort( $this->categories, SORT_NUMERIC );
		foreach ( $this->categories as $term ) {
			$parent   = $term->parent ? get_term( $term->parent, 'category' ) : null;
			$channel .= "<wp:category>\n" . $this->element( 'wp:term_id', (string) $term->term_id )
				. $this->element( 'wp:category_nicename', $term->slug )
				. $this->element( 'wp:cat_name', self::display_text( $term->name ) )
				. $this->element( 'wp:category_parent', $parent instanceof \WP_Term ? $parent->slug : '' )
				. $this->element( 'wp:category_description', self::html_text( $term->description ) ) . "</wp:category>\n";
		}
		ksort( $this->tags, SORT_NUMERIC );
		foreach ( $this->tags as $term ) {
			$channel .= "<wp:tag>\n" . $this->element( 'wp:term_id', (string) $term->term_id )
				. $this->element( 'wp:tag_slug', $term->slug ) . $this->element( 'wp:tag_name', self::display_text( $term->name ) )
				. $this->element( 'wp:tag_description', self::html_text( $term->description ) ) . "</wp:tag>\n";
		}
		$this->budget->check( strlen( $channel ) + strlen( $menu_data['terms'] ) + strlen( $items ) );
		$body = $channel . $menu_data['terms'] . $items;
		unset( $channel, $menu_data, $items );
		$prefix = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n";
		$wxr    = $prefix . $this->element( 'pubDate', gmdate( 'D, d M Y H:i:s +0000', $now ?? time() ) ) . $body . "</channel>\n</rss>\n";
		$base   = self::json( $this->base( $settings ) );
		$this->budget->check( strlen( $wxr ) + strlen( $base ) );
		$hash = hash_init( 'sha256' );
		hash_update( $hash, "zeropress-wordpress-wxr-v1\0" . $prefix );
		hash_update( $hash, $body );
		hash_update( $hash, "</channel>\n</rss>\n\0" );
		hash_update( $hash, $base );
		unset( $body );
		return array(
			'wxr'              => $wxr,
			'base'             => $base,
			'data_hash'        => hash_final( $hash ),
			'counts'           => $this->counts,
			'bytes'            => array(
				'wxr'  => strlen( $wxr ),
				'base' => strlen( $base ),
			),
			'rewritten_values' => $this->rewriter->replacements,
		);
	}

	/**
	 * Export one public post or page using allowlisted fields.
	 *
	 * @param \WP_Post $post WordPress post object.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function document( \WP_Post $post ): string {
		$user                                = get_userdata( $post->post_author );
		$this->authors[ $post->post_author ] = $user ? self::display_text( $user->display_name ) : __( 'Unknown author', 'zeropress-static' );
		$xml                                 = "<item>\n" . $this->element( 'title', self::display_text( $post->post_title ) )
			. $this->element( 'link', get_permalink( $post ) )
			. $this->element( 'dc:creator', 'author-' . $post->post_author )
			. $this->element( 'content:encoded', $this->rewriter->content( $post->post_content ) )
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
				$this->attachments[ (int) $id ] = true;
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
			$parent = get_term( $term->parent, 'category' );
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
						$this->attachments[ (int) $block['attrs'][ $key ] ] = true;
					}
				}
				foreach ( (array) ( $block['attrs']['ids'] ?? array() ) as $id ) {
					if ( is_numeric( $id ) ) {
						$this->attachments[ (int) $id ] = true;
					}
				}
			}
			$this->block_attachments( $block['innerBlocks'] );
		}
	}

	/**
	 * Export only required attachment metadata.
	 *
	 * @param int $id WordPress object ID.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function attachment( int $id ): string {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password ) {
			return '';
		}
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return '';
		}
		Settings::url( strtok( $url, '?#' ) );
		$xml      = "<item>\n" . $this->element( 'title', self::display_text( $post->post_title ) )
			. $this->element( 'wp:post_id', (string) $id )
			. $this->element( 'wp:post_type', 'attachment' )
			. $this->element( 'wp:status', 'inherit' )
			. $this->element( 'wp:post_modified_gmt', $post->post_modified_gmt )
			. $this->element( 'wp:attachment_url', $this->rewriter->url( $url ) );
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
	 * @param array $mapping Output-to-source menu mapping.
	 */
	private function menus( array $mapping ): array {
		$terms = '';
		$items = '';
		$menus = Menus::selected( $mapping );
		foreach ( $menus as $menu ) {
			$entries = wp_get_nav_menu_items( $menu, array( 'post_status' => 'publish' ) );
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
			$terms .= "<wp:term>\n" . $this->element( 'wp:term_id', (string) $menu->term_id )
				. $this->element( 'wp:term_taxonomy', 'nav_menu' )
				. $this->element( 'wp:term_slug', $menu->slug ) . $this->element( 'wp:term_name', self::display_text( $menu->name ) ) . "</wp:term>\n";
			$ids    = array_fill_keys( array_map( static fn( $entry ) => $entry->ID, $entries ), true );
			usort( $entries, static fn( $a, $b ) => $a->menu_order === $b->menu_order ? ( $a->ID <=> $b->ID ) : ( $a->menu_order <=> $b->menu_order ) );
			foreach ( $entries as $entry ) {
				$items .= "<item>\n" . $this->element( 'title', self::display_text( $entry->title ) )
					. $this->element( 'wp:post_id', (string) $entry->ID ) . $this->element( 'wp:post_type', 'nav_menu_item' )
					. $this->element( 'wp:status', 'publish' ) . $this->element( 'wp:menu_order', (string) $entry->menu_order )
					. '<category domain="nav_menu" nicename="' . self::attribute( $menu->slug ) . '">' . self::cdata( self::display_text( $menu->name ) ) . "</category>\n";
				foreach ( array(
					'_menu_item_menu_item_parent' => isset( $ids[ $entry->menu_item_parent ] ) ? $entry->menu_item_parent : 0,
					'_menu_item_object'           => $entry->object,
					'_menu_item_object_id'        => $entry->object_id,
					'_menu_item_target'           => $entry->target,
					'_menu_item_type'             => $entry->type,
					'_menu_item_url'              => $entry->url,
				) as $key => $value ) {
					$items .= $this->meta( $key, (string) $value );
				}
				$items .= "</item>\n";
				$this->budget->check( strlen( $items ) );
			}
		}
		return array(
			'terms' => $terms,
			'items' => $items,
		);
	}

	/** Read WordPress site values shared by the settings summary and export. */
	public static function site_metadata(): array {
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
		$css = $settings['custom_css_content'] ?? '';
		if ( ! empty( $settings['custom_css_enabled'] ) && '' !== trim( $css ) ) {
			$base['custom_css'] = array( 'content' => $css );
		}
		foreach ( array( 'head_end', 'body_end' ) as $slot ) {
			$html = $settings[ 'custom_html_' . $slot ] ?? '';
			if ( ! empty( $settings[ 'custom_html_' . $slot . '_enabled' ] ) && '' !== trim( $html ) ) {
				$base['custom_html'][ $slot ] = $html;
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
