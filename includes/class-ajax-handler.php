<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax_Handler {

	private HubSpot_API $api;
	private Form_Generator $generator;

	public function __construct( HubSpot_API $api, Form_Generator $generator ) {
		$this->api       = $api;
		$this->generator = $generator;

		add_action( 'wp_ajax_ehsf_connect', [ $this, 'handle_connect' ] );
		add_action( 'wp_ajax_ehsf_disconnect', [ $this, 'handle_disconnect' ] );
		add_action( 'wp_ajax_ehsf_fetch_form', [ $this, 'handle_fetch_form' ] );
		add_action( 'wp_ajax_ehsf_create_template', [ $this, 'handle_create_template' ] );
		add_action( 'wp_ajax_ehsf_delete_template', [ $this, 'handle_delete_template' ] );
		add_action( 'wp_ajax_ehsf_activate_license', [ $this, 'handle_activate_license' ] );
		add_action( 'wp_ajax_ehsf_deactivate_license', [ $this, 'handle_deactivate_license' ] );
	}

	/**
	 * Security checks common to all handlers.
	 */
	private function verify_request(): void {
		check_ajax_referer( 'ehsf_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}
	}

	/**
	 * AJAX: Validate token and store credentials.
	 */
	public function handle_connect(): void {
		$this->verify_request();

		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => 'Please enter an access token.' ] );
		}

		$result = $this->api->validate_and_connect( $token );

		if ( $result['success'] ) {
			wp_send_json_success( [ 'portal_id' => $result['portal_id'] ] );
		} else {
			wp_send_json_error( [ 'message' => $result['error'] ] );
		}
	}

	/**
	 * AJAX: Disconnect (clear stored credentials).
	 */
	public function handle_disconnect(): void {
		$this->verify_request();

		delete_option( 'ehsf_hubspot_access_token' );
		delete_option( 'ehsf_hubspot_portal_id' );

		wp_send_json_success();
	}

	/**
	 * AJAX: Parse embed code, fetch form definition, return preview data.
	 */
	public function handle_fetch_form(): void {
		$this->verify_request();

		$embed_code = wp_unslash( $_POST['embed_code'] ?? '' );

		if ( empty( $embed_code ) ) {
			wp_send_json_error( [ 'message' => 'Please paste a HubSpot form embed code.' ] );
		}

		// Parse the embed code.
		$parsed = HubSpot_API::parse_embed_code( $embed_code );

		if ( isset( $parsed['error'] ) ) {
			wp_send_json_error( [ 'message' => $parsed['error'] ] );
		}

		// Fetch form definition from HubSpot.
		$form_result = $this->api->get_form_definition( $parsed['form_id'] );

		if ( ! $form_result['success'] ) {
			wp_send_json_error( [ 'message' => $form_result['error'] ] );
		}

		$form_data = $form_result['data'];

		// Extract fields for preview.
		$fields = Form_Generator::extract_fields( $form_data );

		// Cache the full form data for the create step.
		set_transient(
			'ehsf_form_data_' . $parsed['form_id'],
			$form_data,
			HOUR_IN_SECONDS
		);

		wp_send_json_success( [
			'portal_id' => $parsed['portal_id'],
			'form_id'   => $parsed['form_id'],
			'form_name' => $form_data['name'] ?? 'Untitled Form',
			'fields'    => $fields,
		] );
	}

	/**
	 * AJAX: Generate the Elementor template.
	 */
	public function handle_create_template(): void {
		$this->verify_request();

		// Enforce the 3-form limit for free users.
		if ( ! License::is_pro() ) {
			$count = $this->count_generated_forms();
			if ( $count >= 3 ) {
				wp_send_json_error( [
					'message' => 'The free version supports up to 3 forms. Upgrade to Pro for unlimited forms.',
					'upgrade' => true,
				] );
			}
		}

		$portal_id = sanitize_text_field( wp_unslash( $_POST['portal_id'] ?? '' ) );
		$form_id   = sanitize_text_field( wp_unslash( $_POST['form_id'] ?? '' ) );

		if ( empty( $portal_id ) || empty( $form_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing portal ID or form ID.' ] );
		}

		// Retrieve cached form data.
		$form_data = get_transient( 'ehsf_form_data_' . $form_id );

		if ( empty( $form_data ) ) {
			wp_send_json_error( [
				'message' => 'Form data has expired. Please click "Fetch & Preview" again.',
			] );
		}

		// Generate the Elementor template.
		$result = $this->generator->create_template( $portal_id, $form_id, $form_data );

		if ( ! $result['success'] ) {
			wp_send_json_error( [ 'message' => $result['error'] ] );
		}

		// Clean up the transient.
		delete_transient( 'ehsf_form_data_' . $form_id );

		wp_send_json_success( [
			'template_id' => $result['template_id'],
			'edit_url'    => $result['edit_url'],
		] );
	}

	/**
	 * AJAX: Delete a generated template.
	 */
	public function handle_delete_template(): void {
		$this->verify_request();

		$template_id = absint( $_POST['template_id'] ?? 0 );

		if ( ! $template_id ) {
			wp_send_json_error( [ 'message' => 'Invalid template ID.' ] );
		}

		// Verify it's one of our generated templates.
		$hubspot_form_id = get_post_meta( $template_id, '_ehsf_hubspot_form_id', true );

		if ( empty( $hubspot_form_id ) ) {
			wp_send_json_error( [ 'message' => 'This template was not generated by this plugin.' ] );
		}

		$deleted = wp_delete_post( $template_id, true );

		if ( ! $deleted ) {
			wp_send_json_error( [ 'message' => 'Failed to delete the template.' ] );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Activate a license key.
	 */
	public function handle_activate_license(): void {
		$this->verify_request();

		$license_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );

		if ( empty( $license_key ) ) {
			wp_send_json_error( [ 'message' => 'Please enter a license key.' ] );
		}

		$result = License::activate( $license_key );

		if ( $result['success'] ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => $result['error'] ] );
		}
	}

	/**
	 * AJAX: Deactivate the license.
	 */
	public function handle_deactivate_license(): void {
		$this->verify_request();

		License::deactivate();
		wp_send_json_success();
	}

	/**
	 * Count the number of plugin-generated Elementor templates.
	 */
	private function count_generated_forms(): int {
		$query = new \WP_Query( [
			'post_type'      => 'elementor_library',
			'meta_key'       => '_ehsf_hubspot_form_id',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		] );

		return $query->found_posts;
	}
}
