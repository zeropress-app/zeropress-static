<?php
/**
 * Plugin Name: ZeroPress Static
 * Description: Publish your WordPress content to a static site with ZeroPress and GitHub.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: ZeroPress
 * Author URI: https://zeropress.app/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zeropress-static
 * Domain Path: /languages
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

foreach ( array( 'failure', 'resource-budget', 'content-body', 'content-reader', 'serialized-metadata', 'export-reads', 'settings', 'media-rewriter', 'media-references', 'menus', 'export-storage', 'export-file', 'wxr-bundle', 'wxr-record-store', 'wxr-part-plan', 'wxr-writer', 'exporter', 'github', 'publisher', 'publication-progress', 'publication-store', 'publication-snapshot', 'publication-session', 'admin', 'comment-rate-limit', 'comments', 'rest-access' ) as $zeropress_static_component ) {
	require_once __DIR__ . '/includes/class-' . $zeropress_static_component . '.php';
}

add_action( 'plugins_loaded', array( Admin::class, 'register' ) );

register_deactivation_hook( __FILE__, array( Publication_Store::class, 'cancel_and_cleanup' ) );
register_deactivation_hook( __FILE__, array( Export_Storage::class, 'cleanup' ) );

Comments::register();
Rest_Access::register();
