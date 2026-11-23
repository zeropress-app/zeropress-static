<?php
/** Remove only this plugin's settings and temporary records.
 *
 * @package ZeroPressStatic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Keep the non-secret journal: uninstalling cannot prove a final GitHub write failed.
require_once __DIR__ . '/includes/class-failure.php';
require_once __DIR__ . '/includes/class-publication-store.php';
\ZeroPress\StaticPublisher\Publication_Store::cancel_and_cleanup();
require_once __DIR__ . '/includes/class-resource-budget.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-export-storage.php';
\ZeroPress\StaticPublisher\Export_Storage::cleanup();
delete_option( 'zeropress_static_settings' );

// Only non-autoloaded, short-lived records owned by this plugin.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'zpsp_comment_used_' ) . '%',
		$wpdb->esc_like( 'zpsp_comment_rate_' ) . '%',
		$wpdb->esc_like( '_transient_zpsp_publish_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_zpsp_publish_' ) . '%'
	)
);
