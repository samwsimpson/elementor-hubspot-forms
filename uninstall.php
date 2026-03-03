<?php
/**
 * Fired when the plugin is uninstalled.
 * Cleans up options and transients. Generated templates are preserved
 * since they contain user-styled content.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'ehsf_hubspot_access_token' );
delete_option( 'ehsf_hubspot_portal_id' );
delete_option( 'ehsf_submission_log' );

// Remove license options.
delete_option( 'ehsf_license_key' );
delete_option( 'ehsf_license_status' );
delete_option( 'ehsf_license_next_check' );
delete_option( 'ehsf_license_expires_at' );

// Clean up any remaining transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ehsf_form_data_%' OR option_name LIKE '_transient_timeout_ehsf_form_data_%'"
);
