<?php
/**
 * Plugin Name: ZeroPress Static
 * Description: Publish your WordPress content to a static site with ZeroPress and GitHub.
 * Version: 0.7.0
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

const VERSION     = '0.7.0';
const PLUGIN_FILE = __FILE__;

foreach ( array( 'failure', 'resource-budget', 'settings', 'media-rewriter', 'menus', 'exporter', 'github', 'publisher', 'publication-progress', 'admin', 'comment-rate-limit', 'comments' ) as $zeropress_static_component ) {
	require_once __DIR__ . '/includes/class-' . $zeropress_static_component . '.php';
}

add_action( 'plugins_loaded', array( Admin::class, 'register' ) );

Comments::register();
