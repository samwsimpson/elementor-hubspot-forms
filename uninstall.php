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

// Clean up any remaining transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ehsf_form_data_%' OR option_name LIKE '_transient_timeout_ehsf_form_data_%'"
);
