<?php
/** Remove only this plugin's settings and temporary records.
 *
 * @package ZeroPressStatic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
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
