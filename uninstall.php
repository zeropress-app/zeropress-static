<?php
/** Remove only this plugin's settings and temporary verification records.
 *
 * @package ZeroPressStatic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'zeropress_static_settings' );

// Only non-autoloaded, short-lived replay records owned by this plugin.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'zpsp_comment_used_' ) . '%' ) );
