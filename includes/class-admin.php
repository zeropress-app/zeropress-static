<?php
/**
 * WordPress administration screens and authenticated actions.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Admin-only local export and GitHub publication. */
final class Admin {
	/**
	 * Screen hooks returned by WordPress, including translated parent slugs.
	 *
	 * @var array
	 */
	private static array $screen_hooks = array();

	/** Register the plugin screens and authenticated actions. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'post_submitbox_start', array( self::class, 'editor_button' ) );
		add_action( 'wp_ajax_zeropress_static', array( self::class, 'handle' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( self::class, 'plugin_links' ) );
	}

	/** Register publishing and settings in navigation order. */
	public static function menu(): void {
		$slug                           = 'zeropress-static';
		self::$screen_hooks['publish']  = add_menu_page( __( 'Publish', 'zeropress-static' ), __( 'ZeroPress', 'zeropress-static' ), 'manage_options', $slug, array( self::class, 'screen' ), 'dashicons-cloud-upload' );
		self::$screen_hooks[]           = add_submenu_page( $slug, __( 'Publish', 'zeropress-static' ), __( 'Publish', 'zeropress-static' ), 'manage_options', $slug, array( self::class, 'screen' ) );
		self::$screen_hooks['settings'] = add_submenu_page( $slug, __( 'Settings', 'zeropress-static' ), __( 'Settings', 'zeropress-static' ), 'manage_options', $slug . '-settings', array( self::class, 'settings_screen' ) );
	}

	/**
	 * Link the plugin listing to the same settings destination.
	 *
	 * @param array $links Existing plugin action links.
	 */
	public static function plugin_links( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'zeropress-static' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Resolve an admin destination without depending on the WordPress directory.
	 *
	 * @param string $page Publishing or settings.
	 */
	private static function page_url( string $page = 'publish' ): string {
		return admin_url( 'admin.php?page=zeropress-static' . ( 'publish' === $page ? '' : '-' . $page ) );
	}

	/** Whether this post editor can offer GitHub publication. */
	public static function editor_available(): bool {
		global $post;
		$screen = get_current_screen();
		if ( is_multisite() || ! current_user_can( 'manage_options' ) || ! $screen || 'post' !== $screen->base ||
			! in_array( $screen->post_type, array( 'post', 'page' ), true ) || ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		$settings = Settings::read();
		return '' !== $settings['repository'] && '' !== $settings['branch'] && '' !== $settings['credential'];
	}

	/** Render the classic editor launcher for placement beside its publishing action. */
	public static function editor_button(): void {
		if ( ! self::editor_available() || get_current_screen()->is_block_editor() ) {
			return;
		}
		?>
		<button type="button" id="zpsp-editor-open" class="button button-large" title="<?php esc_attr_e( 'Open GitHub publishing', 'zeropress-static' ); ?>" aria-label="<?php esc_attr_e( 'Open GitHub publishing', 'zeropress-static' ); ?>" aria-haspopup="dialog" disabled>GitHub</button>
		<?php
	}

	/**
	 * Load publication assets on plugin screens and eligible post editors.
	 *
	 * @param string $hook Current WordPress screen hook.
	 */
	public static function assets( string $hook ): void {
		$plugin = in_array( $hook, self::$screen_hooks, true );
		$editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && self::editor_available();
		if ( ! $plugin && ! $editor ) {
			return;
		}
		$block        = $editor && get_current_screen()->is_block_editor();
		$handle       = $editor ? 'zeropress-static-editor' : 'zeropress-static';
		$dependencies = array( 'wp-element', 'wp-components', 'zeropress-static-publication' );
		if ( $editor ) {
			$dependencies = array_merge( $dependencies, $block ? array( 'wp-editor', 'wp-plugins', 'wp-data' ) : array( 'autosave' ) );
		}
		wp_enqueue_style( 'zeropress-static', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( 'wp-components' ), VERSION );
		wp_register_script( 'zeropress-static-publication', plugins_url( 'assets/publisher.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_enqueue_script( $handle, plugins_url( $editor ? 'assets/editor.js' : 'assets/admin.js', PLUGIN_FILE ), $dependencies, VERSION, true );
		$settings = Settings::public_view();
		$config   = array(
			'url'      => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'zeropress_static' ),
			'settings' => $editor ? array_intersect_key( $settings, array_flip( array( 'repository', 'branch' ) ) ) : $settings,
			'messages' => self::messages(),
		);
		if ( $editor ) {
			$post             = get_post();
			$config['editor'] = $block ? 'block' : 'classic';
			$config['site']   = array(
				'public_url'    => $settings['public_url'],
				'wordpress_url' => home_url( '/' ),
			);
			$config['post']   = array(
				'link'   => get_permalink( $post ),
				'public' => 'publish' === $post->post_status && '' === $post->post_password,
			);
		} else {
			$config['menus'] = Menus::catalog();
		}
		wp_add_inline_script( $handle, 'var zeroPressPublisher = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * Dispatch a capability- and nonce-checked administrator request.
	 *
	 * @throws Failure If a submitted action is invalid.
	 */
	public static function handle(): void {
		nocache_headers();
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'FORBIDDEN' ), 403 );
		}
		check_ajax_referer( 'zeropress_static', 'nonce' );
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_send_json_error( array( 'code' => 'INVALID_REQUEST' ), 405 );
		}
		// JSON is validated per operation; never pass request fields to WordPress option APIs directly.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw   = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$input = strlen( $raw ) <= 2 * MB_IN_BYTES ? json_decode( $raw, true ) : null;
		try {
			if ( ! is_array( $input ) || ( isset( $input['settings'] ) && ! is_array( $input['settings'] ) ) ) {
				throw new Failure( 'INVALID_REQUEST' );
			}
			switch ( $input['operation'] ?? '' ) {
				case 'save':
					$result = Settings::save( $input['settings'] ?? array(), Settings::string( $input, 'section' ) );
					if ( 'media' === $input['section'] ) {
						$result['publishing_icons'] = (object) self::publishing_icons( $result['media_rules'] );
					}
					break;
				case 'register_token':
					$result = Settings::register_token( $input['settings'] ?? array() );
					break;
				case 'delete_token':
					$result = Settings::delete_token( $input['settings'] ?? array() );
					break;
				case 'delete_turnstile_keys':
					$result = Settings::delete_turnstile_keys( $input['settings'] ?? array() );
					break;
				case 'test':
					$settings = Settings::draft( $input['settings'] ?? array(), null, 'github' );
					$target   = ( new GitHub( $settings, Resource_Budget::from_request() ) )->inspect( $settings['branch'] );
					$result   = array(
						'repository' => $target['repository'],
						'branch'     => $target['branch'],
					);
					break;
				case 'prepare':
					$result = Publisher::prepare( Resource_Budget::from_request( true ) );
					break;
				case 'publish':
					$republish = array_key_exists( 'republish', $input ) ? $input['republish'] : false;
					if ( ! is_bool( $republish ) ) {
						throw new Failure( 'INVALID_REQUEST' );
					}
					$progress = new Publication_Progress( Settings::string( $input, 'publication_id' ) );
					$result   = Publisher::publish( Settings::string( $input, 'receipt' ), $republish, Resource_Budget::from_request( true ), $progress );
					break;
				case 'publish_progress':
					$progress = new Publication_Progress( Settings::string( $input, 'publication_id' ) );
					$result   = array( 'stage' => $progress->read() );
					break;
				case 'download':
					$file    = Settings::string( $input, 'file' );
					$content = Publisher::download( Settings::string( $input, 'receipt' ), $file, Resource_Budget::from_request( true ) );
					header( 'Content-Type: application/octet-stream' );
					header( 'X-Content-Type-Options: nosniff' );
					header( 'Content-Disposition: attachment; filename="' . GitHub::FILES[ $file ] . '"' );
					// The export is an attachment, not rendered HTML. Escaping would corrupt WXR/JSON.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $content;
					exit;
				default:
					throw new Failure( 'INVALID_REQUEST' );
			}
		} catch ( Failure $error ) {
			wp_send_json_error( array_merge( array( 'code' => $error->reason ), $error->details ? array( 'details' => $error->details ) : array() ), $error->http_status );
		} catch ( \Throwable $error ) {
			// Deliberately exclude exception messages, tokens, source content and API responses.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[ZeroPress Static] INTERNAL_ERROR' );
			wp_send_json_error( array( 'code' => 'INTERNAL_ERROR' ), 500 );
		}
		wp_send_json_success( $result );
	}

	/** Render the publishing screen. */
	public static function screen(): void {
		self::render( 'publish' );
	}

	/** Render the independent settings screen. */
	public static function settings_screen(): void {
		self::render( 'settings' );
	}

	/**
	 * Render common navigation and the selected screen.
	 *
	 * @param string $page Selected admin page.
	 */
	private static function render( string $page ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap zpsp" data-page="<?php echo esc_attr( $page ); ?>">
			<h1><?php esc_html_e( 'ZeroPress Static – Static Site Publisher', 'zeropress-static' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'ZeroPress Static', 'zeropress-static' ); ?>">
				<?php
				foreach ( array(
					'publish'  => __( 'Publish', 'zeropress-static' ),
					'settings' => __( 'Settings', 'zeropress-static' ),
				) as $id => $label ) :
					?>
					<a class="nav-tab <?php echo $page === $id ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( $id ) ); ?>" <?php echo $page === $id ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( is_multisite() ) : ?>
				<p><?php esc_html_e( 'This version supports single-site WordPress only.', 'zeropress-static' ); ?></p>
			<?php elseif ( 'settings' === $page ) : ?>
				<?php self::settings_panels(); ?>
			<?php else : ?>
				<?php self::publish_panel(); ?>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to save settings and prepare publishing data.', 'zeropress-static' ); ?></p></noscript>
		</div>
		<?php
	}

	/**
	 * Render a section's save action and adjacent feedback.
	 *
	 * @param string $section Panel identifier.
	 * @param string $label Translated save button label.
	 */
	private static function section_actions( string $section, string $label ): void {
		?>
		<div class="zpsp-actions">
			<button type="submit" class="button button-primary"><?php echo esc_html( $label ); ?></button>
			<span id="zpsp-<?php echo esc_attr( $section ); ?>-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
		</div>
		<?php
		self::feedback( $section );
	}

	/** Build media prefix examples from the WordPress upload base URL. */
	private static function media_examples(): array {
		$fallback = array( 'https://example.com/wp-content/uploads/', 'https://media.example.com/wp-content/uploads/' );
		$uploads  = wp_get_upload_dir();
		$source   = trailingslashit( $uploads['baseurl'] );
		try {
			$parts = Settings::url( $source );
		} catch ( Failure $error ) {
			return $fallback;
		}
		if ( ! str_contains( $parts['host'], '.' ) || filter_var( trim( $parts['host'], '[]' ), FILTER_VALIDATE_IP ) ) {
			return $fallback;
		}
		$target = $parts['scheme'] . '://media.' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
		return array( $source, $target );
	}

	/**
	 * Resolve the icon URLs with the saved media replacement rules.
	 *
	 * @param array $rules Validated replacement rules.
	 * @return array
	 */
	private static function publishing_icons( array $rules ): array {
		$rewriter = new Media_Rewriter( $rules );
		return array_map( array( $rewriter, 'url' ), Exporter::site_metadata()['favicon'] );
	}

	/** Render the WordPress values used when preparing publishing data. */
	private static function wordpress_information(): void {
		$site   = Exporter::site_metadata();
		$icons  = self::publishing_icons( Settings::read()['media_rules'] );
		$labels = array(
			'title'          => __( 'Site title', 'zeropress-static' ),
			'description'    => __( 'Site description', 'zeropress-static' ),
			'locale'         => __( 'Site language', 'zeropress-static' ),
			'timezone'       => __( 'Timezone', 'zeropress-static' ),
			'posts_per_page' => __( 'Posts per page', 'zeropress-static' ),
		);
		?>
		<div class="zpsp-wordpress-info">
			<h3><?php esc_html_e( 'Information from WordPress', 'zeropress-static' ); ?></h3>
			<p class="description"><?php esc_html_e( 'These values are read from WordPress when preparing publishing data.', 'zeropress-static' ); ?></p>
			<dl class="zpsp-site-details">
				<?php foreach ( $labels as $key => $label ) : ?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd data-site-value="<?php echo esc_attr( $key ); ?>"><?php echo '' === $site[ $key ] ? esc_html__( 'Not configured', 'zeropress-static' ) : esc_html( (string) $site[ $key ] ); ?></dd>
				<?php endforeach; ?>
				<dt><?php esc_html_e( 'Site icon', 'zeropress-static' ); ?></dt>
				<dd>
					<?php if ( ! empty( $site['favicon']['icon'] ) ) : ?>
						<img class="zpsp-site-icon" src="<?php echo esc_url( $site['favicon']['icon'] ); ?>" alt="<?php esc_attr_e( 'Site icon preview', 'zeropress-static' ); ?>" width="48" height="48" />
					<?php else : ?>
						<?php esc_html_e( 'Not configured', 'zeropress-static' ); ?>
					<?php endif; ?>
				</dd>
			</dl>
			<?php if ( $icons ) : ?>
				<details class="zpsp-icon-urls">
					<summary><?php esc_html_e( 'Icon URLs for publishing', 'zeropress-static' ); ?></summary>
					<dl class="zpsp-site-details">
						<?php foreach ( $icons as $key => $url ) : ?>
							<dt><?php echo 'icon' === $key ? esc_html__( 'Site icon', 'zeropress-static' ) : esc_html__( 'Apple touch icon', 'zeropress-static' ); ?></dt>
							<dd><code data-publishing-icon="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $url ); ?></code></dd>
						<?php endforeach; ?>
					</dl>
				</details>
			<?php endif; ?>
			<p class="zpsp-wordpress-links">
				<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit in WordPress settings', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				<a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Reading settings', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
			</p>
		</div>
		<?php
	}

	/** Render the settings contents and independently saved sections. */
	private static function settings_panels(): void {
		$sections = array(
			'site'           => __( 'Site', 'zeropress-static' ),
			'github-panel'   => __( 'GitHub publishing', 'zeropress-static' ),
			'media'          => __( 'Media URL replacements', 'zeropress-static' ),
			'menus'          => __( 'Menus', 'zeropress-static' ),
			'discovery'      => __( 'Search and content navigation', 'zeropress-static' ),
			'comments-panel' => __( 'Comments', 'zeropress-static' ),
			'api'            => __( 'API protection', 'zeropress-static' ),
			'newsletter'     => __( 'Newsletter connection', 'zeropress-static' ),
			'information'    => __( 'Display and credits', 'zeropress-static' ),
		);
		?>
		<nav class="zpsp-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'zeropress-static' ); ?>">
			<ul>
				<?php foreach ( $sections as $id => $label ) : ?>
					<li><a href="#zpsp-<?php echo esc_attr( $id ); ?>" data-settings-anchor><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
		self::site_panel();
		self::github_panel();
		self::media_panel();
		self::menus_panel();
		self::discovery_panel();
		self::comments_panel();
		self::api_panel();
		self::newsletter_panel();
		self::information_panel();
	}

	/** Render the site settings section. */
	private static function site_panel(): void {
		$site_help = __( 'Leave blank to omit <code>sitemap.xml</code>, <code>feed.xml</code> and the <code>&lt;link rel="canonical"&gt;</code> tag specifying the preferred page URL.', 'zeropress-static' );
		$home      = wp_parse_url( home_url( '/' ) );
		if ( is_array( $home ) && in_array( $home['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $home['host'] ) ) {
			$origin     = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$site_help .= '<br />' . sprintf(
				/* translators: %s: WordPress origin in a code element, including the scheme and any custom port. */
				__( 'Example based on this WordPress installation: %s.', 'zeropress-static' ),
				'<code>' . esc_html( $origin ) . '</code>'
			);
		}
		$site_help .= '<br />' . __( 'Enter a static site URL to allow comment writing.', 'zeropress-static' );
		?>
		<form id="zpsp-site" class="zpsp-panel" data-section="site" aria-labelledby="zpsp-site-heading">
			<fieldset disabled>
				<h2 id="zpsp-site-heading"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> <?php esc_html_e( 'Site', 'zeropress-static' ); ?></h2>
				<?php self::wordpress_information(); ?>
				<?php self::field( 'public_url', __( 'Static site URL', 'zeropress-static' ), 'url', 'https://www.example.com', $site_help ); ?>
				<?php self::check( 'allow_indexing', __( 'Allow search engine crawling', 'zeropress-static' ), __( 'When unchecked, <code>Disallow: /</code> is written to the static site\'s <code>robots.txt</code>.', 'zeropress-static' ) ); ?>
				<div class="zpsp-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save site settings', 'zeropress-static' ); ?></button>
					<span id="zpsp-site-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
				</div>
				<?php self::feedback( 'site' ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render the github settings section. */
	private static function github_panel(): void {
		$token_url = add_query_arg(
			array(
				'name'       => rawurlencode( 'ZeroPress Static - ' . Exporter::site_metadata()['title'] ),
				'contents'   => 'write',
				'expires_in' => 366,
			),
			'https://github.com/settings/personal-access-tokens/new'
		);
		?>
		<section id="zpsp-github-panel" class="zpsp-panel" aria-labelledby="zpsp-github-heading">
			<h2 id="zpsp-github-heading"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'GitHub publishing (optional)', 'zeropress-static' ); ?></h2>
			<p><a href="https://github.com/zeropress-app/zeropress-starter-wxr" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Set up a WXR starter repository', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>
			<p><?php self::help_text( __( 'Keep <code>wordpress-export.xml</code> and <code>wxr-import-base.json</code> in the repository root. Publishing to GitHub replaces these two files.', 'zeropress-static' ) ); ?></p>
			<form id="zpsp-github" class="zpsp-subsection" data-section="github" aria-labelledby="zpsp-github-target-heading">
				<fieldset disabled>
					<h3 id="zpsp-github-target-heading"><?php esc_html_e( 'Publishing target', 'zeropress-static' ); ?></h3>
					<?php self::field( 'repository', __( 'Publishing GitHub repository URL', 'zeropress-static' ), 'text', 'https://github.com/owner/repository' ); ?>
					<?php self::field( 'branch', __( 'Publishing branch', 'zeropress-static' ), 'text', '', '', true ); ?>
					<div class="zpsp-actions">
						<button id="zpsp-save-github" type="submit" class="button button-primary"><?php esc_html_e( 'Save publishing target', 'zeropress-static' ); ?></button>
						<button id="zpsp-test" type="button" class="button zpsp-icon-button" disabled><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Test connection', 'zeropress-static' ); ?></span></button>
						<a id="zpsp-repository-link" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'View saved publishing repository', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
						<span id="zpsp-github-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
					</div>
					<?php self::feedback( 'github' ); ?>
					<?php self::feedback( 'test' ); ?>
				</fieldset>
			</form>
			<form id="zpsp-token-form" class="zpsp-subsection" aria-label="<?php esc_attr_e( 'GitHub token management', 'zeropress-static' ); ?>">
				<fieldset disabled>
					<h3 id="zpsp-token-heading"><?php esc_html_e( 'GitHub token', 'zeropress-static' ); ?></h3>
					<div id="zpsp-token-unregistered" hidden>
						<?php self::field( 'token', __( 'GitHub token', 'zeropress-static' ), 'password', '', __( 'Register a GitHub token to publish.', 'zeropress-static' ), true, true ); ?>
						<div class="zpsp-token-help">
							<p><a id="zpsp-token-link" href="<?php echo esc_url( $token_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a GitHub token', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>
							<ol class="description zpsp-token-steps">
								<li><?php self::help_text( __( 'Under <strong>Repository access</strong>, choose <strong>Only select repositories</strong>.', 'zeropress-static' ) ); ?></li>
								<li>
									<?php self::help_text( __( 'Open <strong>Select repositories</strong> and choose only the repository entered in <strong>Publishing GitHub repository URL</strong> above.', 'zeropress-static' ) ); ?>
									<p id="zpsp-token-repository-hint" hidden><?php esc_html_e( 'Repository to select:', 'zeropress-static' ); ?> <code id="zpsp-token-repository"></code></p>
								</li>
								<li><?php self::help_text( __( 'Set <strong>Contents</strong> to <strong>Read and write</strong>.', 'zeropress-static' ) ); ?></li>
							</ol>
						</div>
						<div class="zpsp-actions">
							<button id="zpsp-register-token" type="submit" class="button"><?php esc_html_e( 'Register token', 'zeropress-static' ); ?></button>
							<span id="zpsp-token-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
						</div>
					</div>
					<div id="zpsp-token-registered" hidden>
						<p id="zpsp-token-state" tabindex="-1"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'A GitHub token is registered.', 'zeropress-static' ); ?></p>
						<button id="zpsp-delete-token" type="button" class="button zpsp-icon-button"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Delete saved token', 'zeropress-static' ); ?></span></button>
					</div>
					<?php self::feedback( 'token' ); ?>
				</fieldset>
			</form>
		</section>
		<?php
	}

	/** Render the media settings section. */
	private static function media_panel(): void {
		$media_examples = self::media_examples();
		?>
		<form id="zpsp-media" class="zpsp-panel" data-section="media" aria-labelledby="zpsp-media-heading">
			<fieldset disabled>
				<h2 id="zpsp-media-heading"><span class="dashicons dashicons-admin-media" aria-hidden="true"></span> <?php esc_html_e( 'Media URL replacements', 'zeropress-static' ); ?></h2>
				<p><?php esc_html_e( 'Media URLs are replaced according to your rules.', 'zeropress-static' ); ?></p>
				<div class="zpsp-media-grid">
					<div class="zpsp-media-examples">
						<p id="zpsp-media-from-example" class="description"><?php esc_html_e( 'Source URL prefix example:', 'zeropress-static' ); ?><br /><code><?php echo esc_html( $media_examples[0] ); ?></code></p>
						<p id="zpsp-media-to-example" class="description"><?php esc_html_e( 'Destination URL prefix example:', 'zeropress-static' ); ?><br /><code><?php echo esc_html( $media_examples[1] ); ?></code></p>
					</div>
					<div id="zpsp-rules"></div>
				</div>
				<button id="zpsp-add-rule" type="button" class="button zpsp-icon-button"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Add replacement', 'zeropress-static' ); ?></span></button>
				<div class="zpsp-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save media settings', 'zeropress-static' ); ?></button>
					<span id="zpsp-media-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
				</div>
				<?php self::feedback( 'media' ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render the menus settings section. */
	private static function menus_panel(): void {
		?>
		<form id="zpsp-menus" class="zpsp-panel" data-section="menus" aria-labelledby="zpsp-menus-heading">
			<fieldset disabled>
				<h2 id="zpsp-menus-heading"><span class="dashicons dashicons-menu" aria-hidden="true"></span> <?php esc_html_e( 'Menus', 'zeropress-static' ); ?></h2>
				<p><?php esc_html_e( 'Select classic WordPress menus to publish. Menu IDs connect these selections to your static site theme.', 'zeropress-static' ); ?></p>
				<p id="zpsp-menu-help" class="description"><?php esc_html_e( 'Use primary for the main navigation and footer for footer links. Custom IDs must match the menu IDs used by your theme.', 'zeropress-static' ); ?></p>
				<div id="zpsp-menu-rows"></div>
				<button id="zpsp-add-menu" type="button" class="button zpsp-icon-button"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Add custom menu', 'zeropress-static' ); ?></span></button>
				<div class="zpsp-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save menu settings', 'zeropress-static' ); ?></button>
					<span id="zpsp-menus-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
				</div>
				<?php self::feedback( 'menus' ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render the discovery settings section. */
	private static function discovery_panel(): void {
		?>
		<form id="zpsp-discovery" class="zpsp-panel" data-section="discovery" aria-labelledby="zpsp-discovery-heading">
			<fieldset disabled>
				<h2 id="zpsp-discovery-heading"><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Search and content navigation', 'zeropress-static' ); ?></h2>
				<?php self::check( 'search_enabled', __( 'Enable search', 'zeropress-static' ) ); ?>
				<?php self::check( 'feed_enabled', __( 'Generate RSS feed', 'zeropress-static' ) ); ?>
				<p id="zpsp-feed-url-help" class="description" hidden><?php esc_html_e( 'A static site URL is required to generate the feed.', 'zeropress-static' ); ?> <a href="#zpsp-site" data-settings-anchor><?php esc_html_e( 'Static site URL', 'zeropress-static' ); ?></a></p>
				<?php self::check( 'archive_enabled', __( 'Generate chronological archives', 'zeropress-static' ) ); ?>
				<p class="description"><?php esc_html_e( 'Search and archive display depends on your theme.', 'zeropress-static' ); ?></p>
				<?php self::section_actions( 'discovery', __( 'Save search and navigation settings', 'zeropress-static' ) ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render the comments settings section. */
	private static function comments_panel(): void {
		$api_help = __( 'Use an API URL that site visitors can access.', 'zeropress-static' );
		try {
			$api_url   = Settings::api_url( untrailingslashit( rest_url( 'wp/v2' ) ) );
			$api_help .= ' ' . sprintf(
				/* translators: %s: WordPress REST API URL in a code element, including any installation subdirectory. */
				__( 'This WordPress installation: %s.', 'zeropress-static' ),
				'<code>' . esc_html( $api_url ) . '</code>'
			);
		} catch ( Failure $error ) {
			$api_help .= ' ' . __( 'This installation does not use the supported <code>/wp-json/wp/v2</code> URL format. Configure a visitor-accessible API using that path; query-style REST URLs are not supported.', 'zeropress-static' );
		}
		?>
		<section id="zpsp-comments-panel" class="zpsp-panel" aria-labelledby="zpsp-comments-heading">
			<h2 id="zpsp-comments-heading"><span class="dashicons dashicons-admin-comments" aria-hidden="true"></span> <?php esc_html_e( 'Comments', 'zeropress-static' ); ?></h2>
			<form id="zpsp-comments" class="zpsp-subsection" data-section="comments" aria-labelledby="zpsp-comments-settings-heading">
				<fieldset disabled>
					<h3 id="zpsp-comments-settings-heading"><?php esc_html_e( 'Comment display and writing', 'zeropress-static' ); ?></h3>
					<?php self::check( 'comments_enabled', __( 'Show WordPress comments on the static site', 'zeropress-static' ) ); ?>
					<?php self::field( 'comments_api_url', __( 'WordPress REST API URL', 'zeropress-static' ), 'url', 'https://wp.example.com/wp-json/wp/v2', $api_help ); ?>
					<?php self::check( 'anonymous_comments', __( 'Allow comment writing on the static site', 'zeropress-static' ), __( 'To allow comment writing, save a static site URL in the Site section. Request origins are checked against this address.', 'zeropress-static' ) ); ?>
					<p><?php esc_html_e( 'When writing is off, only comments are shown. Your existing WordPress comment settings still apply.', 'zeropress-static' ); ?></p>
					<div class="zpsp-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save comment settings', 'zeropress-static' ); ?></button>
						<span id="zpsp-comments-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
					</div>
					<?php self::feedback( 'comments' ); ?>
				</fieldset>
			</form>
			<form id="zpsp-turnstile-form" class="zpsp-subsection" data-section="turnstile" aria-labelledby="zpsp-turnstile-heading">
				<fieldset disabled>
					<h3 id="zpsp-turnstile-heading"><?php esc_html_e( 'Cloudflare Turnstile', 'zeropress-static' ); ?></h3>
					<?php self::check( 'comment_verification', __( 'Use Cloudflare Turnstile', 'zeropress-static' ), __( 'Cloudflare Turnstile can help reduce automated comment submission attacks.', 'zeropress-static' ) ); ?>
					<div id="zpsp-turnstile-fields" hidden>
						<?php self::field( 'turnstile_site_key', __( 'Turnstile Site Key', 'zeropress-static' ) ); ?>
						<div id="zpsp-turnstile-editor" hidden>
							<?php self::field( 'turnstile_secret', __( 'Turnstile Secret Key', 'zeropress-static' ), 'password' ); ?>
						</div>
						<div id="zpsp-turnstile-registered" hidden>
							<p><strong><?php esc_html_e( 'Turnstile Secret Key', 'zeropress-static' ); ?></strong></p>
							<p id="zpsp-turnstile-state"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'A Turnstile Secret Key is registered.', 'zeropress-static' ); ?></p>
							<div class="zpsp-actions">
								<button id="zpsp-change-turnstile" type="button" class="button"><?php esc_html_e( 'Change keys', 'zeropress-static' ); ?></button>
								<button id="zpsp-delete-turnstile" type="button" class="button"><?php esc_html_e( 'Delete saved keys', 'zeropress-static' ); ?></button>
							</div>
						</div>
						<div class="zpsp-turnstile-help">
							<?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- External dashboard link; no remote resource is loaded. ?>
							<p><a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage Cloudflare Turnstile', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>
							<p class="description"><?php esc_html_e( 'Register the static site domain in your Turnstile widget.', 'zeropress-static' ); ?></p>
						</div>
					</div>
					<div class="zpsp-actions">
						<button id="zpsp-save-turnstile" type="submit" class="button button-primary"><?php esc_html_e( 'Save Turnstile settings', 'zeropress-static' ); ?></button>
						<button id="zpsp-cancel-turnstile" type="button" class="button" hidden><?php esc_html_e( 'Cancel', 'zeropress-static' ); ?></button>
						<span id="zpsp-turnstile-dirty" class="zpsp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'zeropress-static' ); ?></span>
					</div>
					<?php self::feedback( 'turnstile' ); ?>
				</fieldset>
			</form>
		</section>
		<?php
	}

	/** Render the immediately applied public REST policy. */
	private static function api_panel(): void {
		?>
		<form id="zpsp-api" class="zpsp-panel" data-section="api" aria-labelledby="zpsp-api-heading">
			<fieldset disabled>
				<h2 id="zpsp-api-heading"><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'API protection', 'zeropress-static' ); ?></h2>
				<?php self::check( 'restrict_public_rest_api', __( 'Restrict public REST API access', 'zeropress-static' ), __( 'Limit public REST API access to the APIs needed for ZeroPress Static comments.', 'zeropress-static' ) ); ?>
				<p class="description"><?php esc_html_e( 'Other plugins that use public REST APIs may be affected.', 'zeropress-static' ); ?></p>
				<p class="description"><?php esc_html_e( 'Settings take effect immediately after saving.', 'zeropress-static' ); ?></p>
				<p><a href="https://github.com/zeropress-app/zeropress-static#api-access" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more about API protection', 'zeropress-static' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>
				<?php self::section_actions( 'api', __( 'Save API protection settings', 'zeropress-static' ) ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render the newsletter settings section. */
	private static function newsletter_panel(): void {
		?>
		<form id="zpsp-newsletter" class="zpsp-panel" data-section="newsletter" aria-labelledby="zpsp-newsletter-heading">
			<fieldset disabled>
				<h2 id="zpsp-newsletter-heading"><span class="dashicons dashicons-email" aria-hidden="true"></span> <?php esc_html_e( 'Newsletter connection', 'zeropress-static' ); ?></h2>
				<p><?php esc_html_e( 'Sign up with a newsletter service such as Hostinger Reach, Omnisend or Mailchimp and prepare a signup page. Enter the page URL below to display a signup prompt on your static site.', 'zeropress-static' ); ?></p>
				<?php self::check( 'newsletter_enabled', __( 'Show newsletter signup', 'zeropress-static' ) ); ?>
				<div id="zpsp-newsletter-fields">
					<p class="description"><?php esc_html_e( 'Leave the title, description and button label blank to use the theme defaults.', 'zeropress-static' ); ?></p>
					<?php self::field( 'newsletter_title', __( 'Title', 'zeropress-static' ), 'text', __( 'Get the latest news by email', 'zeropress-static' ) ); ?>
					<p><label for="zpsp-newsletter_description"><?php esc_html_e( 'Description', 'zeropress-static' ); ?></label><textarea class="regular-text" rows="4" id="zpsp-newsletter_description" name="newsletter_description" placeholder="<?php esc_attr_e( 'Get new posts and updates by email.', 'zeropress-static' ); ?>"></textarea></p>
					<?php self::field( 'newsletter_button_label', __( 'Button label', 'zeropress-static' ), 'text', __( 'Subscribe', 'zeropress-static' ) ); ?>
					<p class="description"><?php self::help_text( __( 'Enter an HTTP(S) page URL or a path starting with <code>/</code>. At least one of the two URLs is required.', 'zeropress-static' ) ); ?></p>
					<?php self::field( 'newsletter_signup_url', __( 'Signup page URL', 'zeropress-static' ), 'text', '/subscribe/', __( 'Opens the signup page in a new tab.', 'zeropress-static' ) ); ?>
					<?php self::field( 'newsletter_embed_url', __( 'Embedded signup page URL', 'zeropress-static' ), 'text', 'https://newsletter.example.com/embed/', __( 'Displays the signup page in a popup on your site. Use a page URL that allows iframe embedding.', 'zeropress-static' ) ); ?>
				</div>
				<?php self::section_actions( 'newsletter', __( 'Save newsletter settings', 'zeropress-static' ) ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render the information settings section. */
	private static function information_panel(): void {
		$site_title     = Exporter::site_metadata()['title'];
		$copyright_help = sprintf(
			/* translators: 1: Site title example, 2: Copyright symbol, current year and site title example, 3: Copyright and site title example. Each example is wrapped in a code element. */
			__( 'Examples: %1$s · %2$s · %3$s', 'zeropress-static' ),
			'<code>' . esc_html( $site_title ) . '</code>',
			'<code>' . esc_html( '© ' . wp_date( 'Y' ) . ' ' . $site_title ) . '</code>',
			'<code>' . esc_html( 'Copyright ' . $site_title ) . '</code>'
		);
		$copyright_help .= '<br />' . __( 'Leave blank to omit a separate copyright or legal notice.', 'zeropress-static' );
		?>
		<form id="zpsp-information" class="zpsp-panel" data-section="information" aria-labelledby="zpsp-information-heading">
			<fieldset disabled>
				<h2 id="zpsp-information-heading"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> <?php esc_html_e( 'Display and credits', 'zeropress-static' ); ?></h2>
				<?php self::field( 'copyright_text', __( 'Copyright or legal notice', 'zeropress-static' ), 'text', '', $copyright_help ); ?>
				<?php self::check( 'attribution', __( 'Show ZeroPress attribution', 'zeropress-static' ), __( 'Displays <code>Published with ZeroPress</code> in the theme footer.', 'zeropress-static' ) ); ?>
				<?php self::check( 'expose_generator', __( 'Show ZeroPress generator information', 'zeropress-static' ), __( 'Adds <code>&lt;meta name="generator"&gt;</code> to the HTML.', 'zeropress-static' ) ); ?>
				<?php self::section_actions( 'information', __( 'Save display and credit settings', 'zeropress-static' ) ); ?>
			</fieldset>
		</form>
		<?php
	}

	/** Render saved configuration and the prepare/review/publish sequence. */
	private static function publish_panel(): void {
		$settings = Settings::public_view();
		?>
		<section class="zpsp-panel" aria-labelledby="zpsp-target-heading">
			<h2 id="zpsp-target-heading"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> <?php esc_html_e( 'Publishing destination', 'zeropress-static' ); ?></h2>
			<dl class="zpsp-target">
				<dt><?php esc_html_e( 'Static site URL', 'zeropress-static' ); ?></dt>
				<dd>
					<?php if ( in_array( wp_parse_url( $settings['public_url'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) : ?>
						<a href="<?php echo esc_url( $settings['public_url'], array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $settings['public_url'] ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
					<?php else : ?>
						<?php echo esc_html( $settings['public_url'] ? $settings['public_url'] : __( 'Not configured', 'zeropress-static' ) ); ?>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'GitHub repository', 'zeropress-static' ); ?></dt>
				<dd>
					<?php if ( $settings['repository'] ) : ?>
						<a href="<?php echo esc_url( 'https://github.com/' . implode( '/', array_map( 'rawurlencode', explode( '/', $settings['repository'] ) ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $settings['repository'] ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
					<?php else : ?>
						<?php esc_html_e( 'Not configured', 'zeropress-static' ); ?>
					<?php endif; ?>
				</dd>
				<?php if ( $settings['repository'] ) : ?>
					<dt><?php esc_html_e( 'Publishing branch', 'zeropress-static' ); ?></dt>
					<dd><?php echo esc_html( $settings['branch'] ? $settings['branch'] : __( 'Not configured', 'zeropress-static' ) ); ?></dd>
				<?php endif; ?>
			</dl>
			<p><a href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Edit publishing settings', 'zeropress-static' ); ?></a></p>
		</section>
		<section class="zpsp-panel" aria-labelledby="zpsp-publish-heading">
			<h2 id="zpsp-publish-heading"><span class="dashicons dashicons-media-code" aria-hidden="true"></span> <?php esc_html_e( 'Prepare and publish', 'zeropress-static' ); ?></h2>
			<p><?php esc_html_e( 'Only published posts and pages without a password are exported. Shortcodes and dynamic blocks are not executed.', 'zeropress-static' ); ?></p>
			<button id="zpsp-prepare" type="button" class="button zpsp-icon-button button-primary" disabled><span class="dashicons dashicons-media-code" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Prepare publishing data', 'zeropress-static' ); ?></span></button>
			<?php self::feedback( 'prepare' ); ?>
			<div id="zpsp-result" hidden>
				<h3 tabindex="-1" id="zpsp-result-heading"><?php esc_html_e( 'Review prepared data', 'zeropress-static' ); ?></h3>
				<div id="zpsp-state" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
				<p id="zpsp-summary"></p>
				<div id="zpsp-publish-actions" class="zpsp-actions">
					<button type="button" class="button zpsp-icon-button" data-download="wxr"><span class="dashicons dashicons-download" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Download WXR', 'zeropress-static' ); ?></span></button>
					<button type="button" class="button zpsp-icon-button" data-download="base"><span class="dashicons dashicons-download" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Download build settings', 'zeropress-static' ); ?></span></button>
					<button type="button" class="button zpsp-icon-button button-primary" id="zpsp-publish" disabled><span class="dashicons dashicons-upload" aria-hidden="true"></span><span class="zpsp-button-label"><?php esc_html_e( 'Publish to GitHub', 'zeropress-static' ); ?></span></button>
				</div>
				<details id="zpsp-republish-options" hidden>
					<summary><?php esc_html_e( 'Republish options', 'zeropress-static' ); ?></summary>
					<p><?php esc_html_e( 'Update the WXR generation time and create a new commit without changing the content.', 'zeropress-static' ); ?></p>
					<div id="zpsp-republish-actions" class="zpsp-actions"></div>
				</details>
				<?php self::feedback( 'result' ); ?>
				<?php self::feedback( 'download' ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Place persistent feedback next to the action that produces it.
	 *
	 * @param string $id Action identifier.
	 */
	private static function feedback( string $id ): void {
		?>
		<div id="zpsp-<?php echo esc_attr( $id ); ?>-notice" class="zpsp-feedback" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
		<?php
	}

	/**
	 * Render an escaped labelled text input.
	 *
	 * @param string $name Input field name.
	 * @param string $label Translated label.
	 * @param string $type HTML input type.
	 * @param string $placeholder Example input value.
	 * @param string $description Optional help text with limited emphasis and line breaks.
	 * @param bool   $required Whether an empty value prevents submission.
	 * @param bool   $hide_label Whether a visible heading already identifies the input.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function field( string $name, string $label, string $type = 'text', string $placeholder = '', string $description = '', bool $required = false, bool $hide_label = false ): void {
		?>
		<p><label <?php echo $hide_label ? 'class="screen-reader-text"' : ''; ?> for="zpsp-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
		<input class="regular-text" id="zpsp-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="<?php echo esc_attr( $type ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="<?php echo 'password' === $type ? 'new-password' : 'off'; ?>" spellcheck="false"
			<?php if ( $required ) : ?>
				required
			<?php endif; ?>
			<?php if ( '' !== $description ) : ?>
				aria-describedby="zpsp-<?php echo esc_attr( $name ); ?>-help"
			<?php endif; ?>
		></p>
		<?php if ( '' !== $description ) : ?>
			<p class="description" id="zpsp-<?php echo esc_attr( $name ); ?>-help"><?php self::help_text( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render an escaped checkbox label.
	 *
	 * @param string $name Input field name.
	 * @param string $label Translated label.
	 * @param string $description Optional help text with limited emphasis and line breaks.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function check( string $name, string $label, string $description = '' ): void {
		?>
		<p><label><input id="zpsp-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="checkbox"
			<?php if ( '' !== $description ) : ?>
				aria-describedby="zpsp-<?php echo esc_attr( $name ); ?>-help"
			<?php endif; ?>
		> <?php echo esc_html( $label ); ?></label></p>
		<?php if ( '' !== $description ) : ?>
			<p class="description" id="zpsp-<?php echo esc_attr( $name ); ?>-help"><?php self::help_text( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render help text with limited emphasis, line breaks and no HTML attributes.
	 *
	 * @param string $text Translated help with escaped dynamic values.
	 */
	private static function help_text( string $text ): void {
		echo wp_kses(
			$text,
			array(
				'br'     => array(),
				'code'   => array(),
				'strong' => array(),
			)
		);
	}

	/** Translate browser messages on the server. */
	private static function messages(): array {
		return array(
			'NEWSLETTER_URL_REQUIRED'         => __( 'Enter a signup page URL or an embedded signup page URL.', 'zeropress-static' ),
			'INVALID_NEWSLETTER_TITLE'        => __( 'Enter a title of up to 200 characters.', 'zeropress-static' ),
			'INVALID_NEWSLETTER_DESCRIPTION'  => __( 'Enter a description of up to 2,000 characters.', 'zeropress-static' ),
			'INVALID_NEWSLETTER_BUTTON_LABEL' => __( 'Enter a button label of up to 200 characters.', 'zeropress-static' ),
			'INVALID_NEWSLETTER_SIGNUP_URL'   => __( 'Enter a valid HTTP(S) signup page URL or site-relative path (up to 2,048 characters).', 'zeropress-static' ),
			'INVALID_NEWSLETTER_EMBED_URL'    => __( 'Enter a valid HTTP(S) embedded signup page URL or site-relative path (up to 2,048 characters).', 'zeropress-static' ),
			'menu_id'                         => __( 'Menu ID', 'zeropress-static' ),
			'menu_source'                     => __( 'WordPress menu', 'zeropress-static' ),
			'menu_none'                       => __( 'Do not publish', 'zeropress-static' ),
			'menu_remove'                     => __( 'Remove menu assignment', 'zeropress-static' ),
			'menu_missing'                    => __( 'Unavailable menu (ID {id})', 'zeropress-static' ),
			'INVALID_MENU_MAPPING'            => __( 'Use unique menu IDs starting with a lowercase letter, followed by lowercase letters, numbers, hyphens or underscores (up to 64 characters).', 'zeropress-static' ),
			'MENU_MISSING'                    => __( 'A selected WordPress menu is unavailable. Reload settings and select a menu again.', 'zeropress-static' ),
			'TURNSTILE_SITE_KEY_REQUIRED'     => __( 'Enter a Turnstile Site Key.', 'zeropress-static' ),
			'TURNSTILE_SECRET_REQUIRED'       => __( 'Enter the Secret Key for this Site Key.', 'zeropress-static' ),
			'INVALID_TURNSTILE_SECRET'        => __( 'Enter a Secret Key without spaces or line breaks.', 'zeropress-static' ),
			'INVALID_TURNSTILE_SETTINGS'      => __( 'Enter Turnstile keys without spaces or line breaks.', 'zeropress-static' ),
			'COMMENT_SITE_URL_REQUIRED'       => __( 'Save a static site URL in the Site section to allow comment writing.', 'zeropress-static' ),
			'TURNSTILE_SETTINGS_REQUIRED'     => __( 'Save a Site Key and Secret Key together to use Turnstile.', 'zeropress-static' ),
			'TURNSTILE_KEYS_MISSING'          => __( 'No Turnstile keys are saved. Reload this page to check their current status.', 'zeropress-static' ),
			'deleting_turnstile'              => __( 'Deleting keys…', 'zeropress-static' ),
			'turnstile_deleted'               => __( 'The saved keys were deleted and Cloudflare Turnstile is off.', 'zeropress-static' ),
			'turnstile_delete_title'          => __( 'Delete the saved Turnstile keys?', 'zeropress-static' ),
			'turnstile_delete_help'           => __( 'Delete the saved Site Key and Secret Key and turn off Cloudflare Turnstile.', 'zeropress-static' ),
			'turnstile_delete_confirm'        => __( 'Delete saved keys', 'zeropress-static' ),
			'editor_title'                    => __( 'ZeroPress publish', 'zeropress-static' ),
			'editor_open'                     => __( 'Open GitHub publishing', 'zeropress-static' ),
			'editor_scope'                    => __( 'Publish all saved public posts and pages.', 'zeropress-static' ),
			'editor_site'                     => __( 'View on static site', 'zeropress-static' ),
			'editor_unsaved'                  => __( 'Save your post changes before preparing or publishing.', 'zeropress-static' ),
			'editor_saving'                   => __( 'Wait for WordPress to finish saving your post.', 'zeropress-static' ),
			'editor_close'                    => __( 'Close', 'zeropress-static' ),
			'editor_prepare'                  => __( 'Prepare publishing data', 'zeropress-static' ),
			'editor_unavailable'              => __( 'Check your GitHub settings before publishing.', 'zeropress-static' ),
			'working'                         => __( 'Working…', 'zeropress-static' ),
			'saved'                           => __( 'This section is saved.', 'zeropress-static' ),
			'saved_publish'                   => __( 'This section is saved. Changes take effect after the next publication.', 'zeropress-static' ),
			'saved_comments'                  => __( 'This section is saved. Comment display changes take effect after the next publication; writing settings apply to new comment requests.', 'zeropress-static' ),
			'saved_comments_api'              => __( 'This section is saved. Comment display changes take effect after the next publication; API access and writing settings apply immediately.', 'zeropress-static' ),
			'saved_runtime'                   => __( 'This section is saved. Changes apply to new comment requests.', 'zeropress-static' ),
			'saved_api'                       => __( 'This section is saved. API protection applies immediately.', 'zeropress-static' ),
			'connected'                       => __( 'GitHub connection confirmed.', 'zeropress-static' ),
			'connected_unsaved'               => __( 'Click “Save publishing target” to save these changes.', 'zeropress-static' ),
			'registering_token'               => __( 'Registering token…', 'zeropress-static' ),
			'deleting_token'                  => __( 'Deleting token…', 'zeropress-static' ),
			'token_registered'                => __( 'The GitHub token is registered.', 'zeropress-static' ),
			'token_deleted'                   => __( 'The saved GitHub token was deleted.', 'zeropress-static' ),
			'token_delete_title'              => __( 'Delete the saved GitHub token?', 'zeropress-static' ),
			'token_delete_description'        => __( 'This removes the token stored in this plugin. Register a token again to publish to GitHub.', 'zeropress-static' ),
			'token_delete_confirm'            => __( 'Delete token', 'zeropress-static' ),
			'TOKEN_ALREADY_REGISTERED'        => __( 'A GitHub token is already registered. Reload this page to manage it.', 'zeropress-static' ),
			'TOKEN_NOT_REGISTERED'            => __( 'No GitHub token is registered. Reload this page to check its current status.', 'zeropress-static' ),
			'from'                            => __( 'Source URL prefix', 'zeropress-static' ),
			'to'                              => __( 'Destination URL prefix', 'zeropress-static' ),
			'remove'                          => __( 'Remove replacement', 'zeropress-static' ),
			'summary'                         => __( 'Posts: {posts} · Pages: {pages} · Media: {media} · Menus: {menus}', 'zeropress-static' ),
			'prepared'                        => __( 'Data prepared. Review the result below.', 'zeropress-static' ),
			'ready'                           => __( 'Ready to publish to {repository} ({branch}).', 'zeropress-static' ),
			'unchanged'                       => __( 'No changes to publish.', 'zeropress-static' ),
			'unchanged_description'           => __( 'GitHub already contains this data. You do not need to publish again.', 'zeropress-static' ),
			'download_only'                   => __( 'Data is ready to download. Configure and test GitHub to publish.', 'zeropress-static' ),
			'review_prepared'                 => __( 'Review prepared data', 'zeropress-static' ),
			'publication_result'              => __( 'Publication result', 'zeropress-static' ),
			'published_button'                => __( 'Published to GitHub', 'zeropress-static' ),
			'published'                       => __( 'Published to GitHub.', 'zeropress-static' ),
			'unknown'                         => __( 'Publication needs verification. Check the GitHub commit before preparing again; the write was not retried.', 'zeropress-static' ),
			'commit'                          => __( 'View commit', 'zeropress-static' ),
			'republish_title'                 => __( 'Publish to GitHub again', 'zeropress-static' ),
			'republish_description'           => __( 'Update the WXR generation time and create a new commit without changing the content.', 'zeropress-static' ),
			'confirm_title'                   => __( 'Publish to GitHub', 'zeropress-static' ),
			'confirm_repository'              => __( 'GitHub repository', 'zeropress-static' ),
			'confirm_branch'                  => __( 'Publishing branch', 'zeropress-static' ),
			'confirm_files'                   => __( 'Files to update', 'zeropress-static' ),
			'confirm_description'             => __( 'Both files will be updated in one commit.', 'zeropress-static' ),
			'confirm_cancel'                  => __( 'Cancel', 'zeropress-static' ),
			'confirm_close'                   => __( 'Close confirmation', 'zeropress-static' ),
			'confirmation_unavailable'        => __( 'The confirmation dialog could not be loaded. Reload this page and try again.', 'zeropress-static' ),
			'saving'                          => __( 'Saving…', 'zeropress-static' ),
			'testing'                         => __( 'Testing connection…', 'zeropress-static' ),
			'preparing'                       => __( 'Preparing data…', 'zeropress-static' ),
			'publishing'                      => __( 'Publishing to GitHub…', 'zeropress-static' ),
			'publish_validating'              => __( 'Verifying publishing data…', 'zeropress-static' ),
			'publish_inspecting'              => __( 'Checking the GitHub repository…', 'zeropress-static' ),
			'publish_uploading'               => __( 'Uploading files to GitHub…', 'zeropress-static' ),
			'publish_committing'              => __( 'Applying changes on GitHub…', 'zeropress-static' ),
			'publish_verifying'               => __( 'Verifying the GitHub publication result…', 'zeropress-static' ),
			'downloading'                     => __( 'Preparing download…', 'zeropress-static' ),
			'downloaded'                      => __( 'The download is ready. Check your browser downloads.', 'zeropress-static' ),
			'test_changed'                    => __( 'Connection details changed. Test again to check these values.', 'zeropress-static' ),
			'INVALID_SETTINGS'                => __( 'Check the values in this section.', 'zeropress-static' ),
			'INVALID_TOKEN'                   => __( 'Enter a token without spaces or line breaks.', 'zeropress-static' ),
			'INVALID_MEDIA_RULES'             => __( 'Use up to 50 complete source and destination URL pairs.', 'zeropress-static' ),
			'error'                           => __( 'The request could not be completed. Please retry.', 'zeropress-static' ),
			'SETTINGS_CHANGED'                => __( 'Settings changed in another window. Reload before saving.', 'zeropress-static' ),
			'PREPARATION_EXPIRED'             => __( 'The preparation expired or settings changed. Prepare again.', 'zeropress-static' ),
			'PREPARATION_INVALID'             => __( 'The preparation is invalid. Prepare again.', 'zeropress-static' ),
			'CONTENT_CHANGED'                 => __( 'WordPress content changed. Prepare again to include the latest data.', 'zeropress-static' ),
			'TARGET_CHANGED'                  => __( 'A target file changed on GitHub. Review the change and prepare again.', 'zeropress-static' ),
			'GITHUB_CONFLICT'                 => __( 'The GitHub branch changed concurrently. Review it and prepare again.', 'zeropress-static' ),
			'GITHUB_BRANCH_RULE'              => __( 'A GitHub branch rule rejected direct publication. Review the repository rules.', 'zeropress-static' ),
			'GITHUB_WRITE_REJECTED'           => __( 'GitHub rejected the write. Check repository permissions and branch rules.', 'zeropress-static' ),
			'GITHUB_AUTH_FAILED'              => __( 'GitHub did not accept the registered token. Delete it and register a token again, then test the connection.', 'zeropress-static' ),
			'GITHUB_PERMISSION_DENIED'        => __( 'The token cannot access this repository. Check its selected repositories and permissions.', 'zeropress-static' ),
			'GITHUB_RATE_LIMITED'             => __( 'GitHub request limit reached. Wait before trying again.', 'zeropress-static' ),
			'GITHUB_UNAVAILABLE'              => __( 'GitHub is temporarily unavailable or the request timed out.', 'zeropress-static' ),
			'TARGET_NOT_FOUND'                => __( 'Repository, branch or required files not found. Check access and the two root files.', 'zeropress-static' ),
			'TARGET_NOT_REGULAR_FILE'         => __( 'Both targets must be regular files, not directories, symlinks or submodules.', 'zeropress-static' ),
			'INVALID_URL'                     => __( 'Enter an HTTP(S) URL without credentials, a query or a fragment.', 'zeropress-static' ),
			'INVALID_SITE_URL'                => __( 'Enter the site origin without a path.', 'zeropress-static' ),
			'INVALID_COMMENTS_URL'            => __( 'The REST API URL must end in /wp-json/wp/v2.', 'zeropress-static' ),
			'COMMENTS_URL_REQUIRED'           => __( 'Enter a REST API URL that site visitors can access to enable comments.', 'zeropress-static' ),
			'INVALID_REPOSITORY'              => __( 'Enter a github.com repository URL or owner/repository.', 'zeropress-static' ),
			'INVALID_BRANCH'                  => __( 'Enter the publishing branch name. Tags and commit URLs are not supported.', 'zeropress-static' ),
			'REPOSITORY_REQUIRED'             => __( 'Enter a GitHub repository before testing the connection.', 'zeropress-static' ),
			'TOKEN_REQUIRED'                  => __( 'Register a GitHub token to test or publish.', 'zeropress-static' ),
			'TOKEN_UNREADABLE'                => __( 'The saved token cannot be decrypted. Delete it and register it again; WordPress secrets may have changed.', 'zeropress-static' ),
			'ENCRYPTION_UNAVAILABLE'          => __( 'Authenticated encryption is unavailable. Enable OpenSSL AES-256-GCM on the server before registering credentials.', 'zeropress-static' ),
			'MEDIA_RULE_CONFLICT'             => __( 'A source media prefix has conflicting destinations.', 'zeropress-static' ),
			'MEMORY_LIMIT_EXCEEDED'           => __( 'There is not enough PHP memory to complete this request. Increase the server memory allowance and prepare again.', 'zeropress-static' ),
			'memory_details'                  => __( 'PHP memory limit: {limit}. Estimated memory required: {estimated}.', 'zeropress-static' ),
			'REQUEST_TIME_LIMIT_EXCEEDED'     => __( 'The request reached its server-based time budget. Increase the server time allowance and prepare again.', 'zeropress-static' ),
			'time_details'                    => __( 'PHP time limit: {limit} s. Request budget: {budget} s. Elapsed: {elapsed} s.', 'zeropress-static' ),
		);
	}
}
