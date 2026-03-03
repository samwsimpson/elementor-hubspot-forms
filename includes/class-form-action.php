<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ElementorPro\Modules\Forms\Classes\Action_Base;

class Form_Action extends Action_Base {

	/**
	 * Action identifier — must match the value in submit_actions
	 * of generated templates.
	 */
	public function get_name(): string {
		return 'hubspot_submit';
	}

	/**
	 * Label shown in the Elementor editor's Submit Actions dropdown.
	 */
	public function get_label(): string {
		return esc_html__( 'HubSpot Submit', 'ehsf' );
	}

	/**
	 * Register settings controls shown when this action is selected.
	 */
	public function register_settings_section( $widget ): void {
		$widget->start_controls_section(
			'ehsf_hubspot_section',
			[
				'label'     => esc_html__( 'HubSpot Submit', 'ehsf' ),
				'condition' => [
					'submit_actions' => $this->get_name(),
				],
			]
		);

		$portal_id = get_option( 'ehsf_hubspot_portal_id', '' );

		$widget->add_control(
			'ehsf_portal_id',
			[
				'label'       => esc_html__( 'HubSpot Portal ID', 'ehsf' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $portal_id,
				'description' => esc_html__( 'Your HubSpot portal (hub) ID. Auto-filled from plugin settings.', 'ehsf' ),
				'label_block' => true,
			]
		);

		$widget->add_control(
			'ehsf_form_guid',
			[
				'label'       => esc_html__( 'HubSpot Form GUID', 'ehsf' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
				'description' => esc_html__( 'The GUID of the HubSpot form to submit to. Auto-filled when using the form generator.', 'ehsf' ),
				'label_block' => true,
			]
		);

		$widget->add_control(
			'ehsf_object_type_id',
			[
				'label'       => esc_html__( 'Object Type', 'ehsf' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '0-1',
				'options'     => [
					'0-1' => esc_html__( 'Contact', 'ehsf' ),
					'0-2' => esc_html__( 'Company', 'ehsf' ),
				],
				'description' => esc_html__( 'The HubSpot object type for field submissions. Default is Contact.', 'ehsf' ),
			]
		);

		$widget->add_control(
			'ehsf_info_note',
			[
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Each form field\'s Advanced > ID must match the HubSpot property name (e.g. "email", "firstname"). The form generator sets this automatically.', 'ehsf' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			]
		);

		$widget->end_controls_section();
	}

	/**
	 * Execute the action on form submission.
	 */
	public function run( $record, $ajax_handler ): void {
		$settings = $record->get( 'form_settings' );

		$portal_id      = $settings['ehsf_portal_id'] ?? '';
		$form_guid      = $settings['ehsf_form_guid'] ?? '';
		$object_type_id = $settings['ehsf_object_type_id'] ?? '0-1';

		if ( empty( $portal_id ) || empty( $form_guid ) ) {
			return;
		}

		$raw_fields = $record->get( 'fields' );
		$hs_fields  = [];

		foreach ( $raw_fields as $field ) {
			$field_id = $field['id'] ?? '';
			$value    = $field['value'] ?? '';

			if ( empty( $field_id ) ) {
				continue;
			}

			// Skip internal Elementor meta fields.
			if ( in_array( $field_id, [ 'post_id', 'form_id', 'queried_id', 'referer_title' ], true ) ) {
				continue;
			}

			// Normalize boolean-like values for HubSpot.
			$lower_value = strtolower( trim( $value ) );
			if ( in_array( $lower_value, [ 'on', 'yes' ], true ) ) {
				$value = 'true';
			}

			$hs_fields[] = [
				'objectTypeId' => $object_type_id,
				'name'         => trim( $field_id ),
				'value'        => trim( $value ),
			];
		}

		if ( empty( $hs_fields ) ) {
			return;
		}

		// Build HubSpot context for analytics tracking.
		$context = [
			'pageUri'  => wp_get_referer() ?: home_url(),
			'pageName' => get_bloginfo( 'name' ),
		];

		// HubSpot tracking cookie.
		if ( ! empty( $_COOKIE['hubspotutk'] ) ) {
			$context['hutk'] = sanitize_text_field( wp_unslash( $_COOKIE['hubspotutk'] ) );
		}

		// Client IP for HubSpot analytics.
		$ip = $this->get_client_ip();
		if ( $ip ) {
			$context['ipAddress'] = $ip;
		}

		// Submit to HubSpot.
		$api    = new HubSpot_API();
		$result = $api->submit_form( $portal_id, $form_guid, $hs_fields, $context );

		// Log the submission.
		$this->log_submission( $portal_id, $form_guid, $result );

		if ( ! $result['success'] ) {
			$ajax_handler->add_error_message(
				esc_html__( 'There was an error submitting your information. Please try again.', 'ehsf' )
			);
		}
	}

	/**
	 * Remove sensitive settings from exported templates.
	 */
	public function on_export( $element ): array {
		unset(
			$element['settings']['ehsf_portal_id'],
			$element['settings']['ehsf_form_guid']
		);
		return $element;
	}

	/**
	 * Get the client's IP address.
	 */
	private function get_client_ip(): string {
		$headers = [
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return '';
	}

	/**
	 * Log submission result (ring buffer of last 50 entries).
	 */
	private function log_submission( string $portal_id, string $form_guid, array $result ): void {
		$log = get_option( 'ehsf_submission_log', [] );

		$log[] = [
			'time'        => current_time( 'mysql' ),
			'portal_id'   => $portal_id,
			'form_guid'   => $form_guid,
			'success'     => $result['success'],
			'status_code' => $result['status_code'] ?? 0,
			'error'       => $result['error'] ?? '',
		];

		// Keep only last 50 entries.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		update_option( 'ehsf_submission_log', $log, false );
	}
}
