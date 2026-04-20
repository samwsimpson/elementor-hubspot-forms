<?php
/**
 * Plugin Name: Elementor HubSpot Forms
 * Plugin URI:  https://kumokodo.ai/wpplugins
 * Description: Auto-generate Elementor Pro forms from HubSpot form embed codes. Paste your embed code, get a fully styled form that submits to HubSpot.
 * Version:     2.0.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      KumoKodo.ai
 * Author URI:  https://kumokodo.ai/wpplugins
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ehsf
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EHSF_VERSION', '2.0.4' );
define( 'EHSF_PLUGIN_FILE', __FILE__ );
define( 'EHSF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EHSF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EHSF_MIN_ELEMENTOR_VERSION', '3.5.0' );
define( 'EHSF_MIN_ELEMENTOR_PRO_VERSION', '3.5.0' );

/**
 * Plugin update checker — checks GitHub releases for new versions.
 */
if ( is_admin() ) {
	require_once EHSF_PLUGIN_DIR . 'vendor/plugin-update-checker.php';
	$ehsf_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/samwsimpson/elementor-hubspot-forms/',
		__FILE__,
		'elementor-hubspot-forms'
	);
	$ehsf_update_checker->setBranch( 'main' );
	$ehsf_update_checker->getVcsApi()->enableReleaseAssets();
}

/**
 * Initialize the plugin after all plugins have loaded.
 */
function ehsf_init() {
	// Check Elementor is active.
	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', 'ehsf_notice_missing_elementor' );
		return;
	}

	// Check Elementor Pro is active (Form widget is Pro-only).
	if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) && ! class_exists( '\ElementorPro\Plugin' ) ) {
		add_action( 'admin_notices', 'ehsf_notice_missing_elementor_pro' );
		return;
	}

	// Check minimum Elementor version.
	if ( ! version_compare( ELEMENTOR_VERSION, EHSF_MIN_ELEMENTOR_VERSION, '>=' ) ) {
		add_action( 'admin_notices', 'ehsf_notice_elementor_version' );
		return;
	}

	require_once EHSF_PLUGIN_DIR . 'includes/class-plugin.php';
	\EHSF\Plugin::instance();
}
add_action( 'plugins_loaded', 'ehsf_init' );

/**
 * Admin notice: Elementor not installed/active.
 */
function ehsf_notice_missing_elementor() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Elementor HubSpot Forms requires Elementor to be installed and activated.', 'ehsf' );
	echo '</p></div>';
}

/**
 * Admin notice: Elementor Pro not installed/active.
 */
function ehsf_notice_missing_elementor_pro() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Elementor HubSpot Forms requires Elementor Pro to be installed and activated (the Form widget is a Pro feature).', 'ehsf' );
	echo '</p></div>';
}

/**
 * Admin notice: Elementor version too old.
 */
function ehsf_notice_elementor_version() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	printf(
		esc_html__( 'Elementor HubSpot Forms requires Elementor version %s or higher.', 'ehsf' ),
		esc_html( EHSF_MIN_ELEMENTOR_VERSION )
	);
	echo '</p></div>';
}
