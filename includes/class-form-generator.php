<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Form_Generator {

	private HubSpot_API $api;

	/**
	 * HubSpot fieldType => Elementor field_type mapping.
	 */
	private const FIELD_TYPE_MAP = [
		'single_line_text'    => 'text',
		'multi_line_text'     => 'textarea',
		'email'               => 'email',
		'phone'               => 'tel',
		'dropdown'            => 'select',
		'radio'               => 'radio',
		'single_checkbox'     => 'acceptance',
		'multiple_checkboxes' => 'checkbox',
		'date'                => 'date',
		'number'              => 'number',
		'file'                => 'upload',
		// Legacy v2 type names (in case API returns them).
		'text'                => 'text',
		'textarea'            => 'textarea',
		'select'              => 'select',
		'booleancheckbox'     => 'acceptance',
		'checkbox'            => 'checkbox',
		'phonenumber'         => 'tel',
	];

	/**
	 * Property names that should map to the email field type.
	 */
	private const EMAIL_PROPERTY_NAMES = [ 'email', 'hs_email', 'work_email' ];

	public function __construct( HubSpot_API $api ) {
		$this->api = $api;
	}

	/**
	 * Extract a flat array of field info from a HubSpot form definition.
	 *
	 * @param array $form_data Full HubSpot form definition from the API.
	 * @return array[]
	 */
	public static function extract_fields( array $form_data ): array {
		$fields = [];

		// v3 uses fieldGroups, v2 uses formFieldGroups.
		$groups = $form_data['fieldGroups'] ?? $form_data['formFieldGroups'] ?? [];

		foreach ( $groups as $group ) {
			$group_fields = $group['fields'] ?? [];
			$field_count  = count( $group_fields );
			$width        = $field_count > 0 ? (string) floor( 100 / $field_count ) : '100';

			foreach ( $group_fields as $field ) {
				$hs_type = $field['fieldType'] ?? $field['type'] ?? 'single_line_text';
				$name    = $field['name'] ?? '';

				if ( empty( $name ) ) {
					continue;
				}

				// Determine Elementor type.
				$el_type = self::FIELD_TYPE_MAP[ $hs_type ] ?? 'text';

				// Override: single_line_text with email property name → email.
				if ( 'text' === $el_type && in_array( strtolower( $name ), self::EMAIL_PROPERTY_NAMES, true ) ) {
					$el_type = 'email';
				}

				// Extract options for select/radio/checkbox.
				$options = [];
				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( $field['options'] as $opt ) {
						$options[] = [
							'label' => $opt['label'] ?? $opt['value'] ?? '',
							'value' => $opt['value'] ?? $opt['label'] ?? '',
						];
					}
				}

				$fields[] = [
					'name'           => $name,
					'label'          => $field['label'] ?? $name,
					'hs_type'        => $hs_type,
					'el_type'        => $el_type,
					'required'       => (bool) ( $field['required'] ?? false ),
					'placeholder'    => $field['placeholder'] ?? '',
					'default_value'  => $field['defaultValue'] ?? '',
					'description'    => $field['description'] ?? '',
					'options'        => $options,
					'hidden'         => (bool) ( $field['hidden'] ?? false ),
					'object_type_id' => $field['objectTypeId'] ?? '0-1',
					'width'          => $width,
				];
			}
		}

		return $fields;
	}

	/**
	 * Create an Elementor template with a pre-configured form widget.
	 *
	 * @return array{success: bool, template_id?: int, edit_url?: string, error?: string}
	 */
	public function create_template( string $portal_id, string $form_id, array $form_data ): array {
		$form_name = $form_data['name'] ?? 'HubSpot Form';
		$fields    = self::extract_fields( $form_data );

		if ( empty( $fields ) ) {
			return [ 'success' => false, 'error' => 'No fields found in the HubSpot form definition.' ];
		}

		$el_fields     = $this->build_elementor_fields( $fields );
		$elementor_data = $this->build_elementor_data( $el_fields, $portal_id, $form_id, $form_name );

		$post_id = wp_insert_post( [
			'post_type'    => 'elementor_library',
			'post_title'   => sprintf( 'HubSpot: %s', sanitize_text_field( $form_name ) ),
			'post_status'  => 'publish',
			'post_content' => '',
			'meta_input'   => [
				'_elementor_edit_mode'     => 'builder',
				'_elementor_template_type' => 'page',
				'_elementor_version'       => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.20.0',
				'_elementor_pro_version'   => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '3.20.0',
				'_elementor_data'          => wp_slash( wp_json_encode( $elementor_data ) ),
				'_elementor_css'           => '',
				'_ehsf_hubspot_form_id'    => sanitize_text_field( $form_id ),
				'_ehsf_hubspot_portal_id'  => sanitize_text_field( $portal_id ),
				'_ehsf_hubspot_form_name'  => sanitize_text_field( $form_name ),
			],
		] );

		if ( is_wp_error( $post_id ) ) {
			return [ 'success' => false, 'error' => $post_id->get_error_message() ];
		}

		wp_set_object_terms( $post_id, 'page', 'elementor_library_type' );

		// Clear Elementor CSS cache so the template renders fresh.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );

		return [
			'success'     => true,
			'template_id' => $post_id,
			'edit_url'    => $edit_url,
		];
	}

	/**
	 * Convert extracted fields into Elementor form_fields repeater items.
	 */
	private function build_elementor_fields( array $fields ): array {
		$el_fields = [];
		$index     = 0;

		foreach ( $fields as $field ) {
			$el_field = [
				'_id'         => 'field_' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
				'custom_id'   => sanitize_key( $field['name'] ),
				'field_type'  => $field['hidden'] ? 'hidden' : $field['el_type'],
				'field_label' => sanitize_text_field( $field['label'] ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ),
				'required'    => $field['required'] ? 'yes' : '',
				'width'       => $field['width'] ?? '100',
				'field_value' => sanitize_text_field( $field['default_value'] ),
			];

			// Add options for select, radio, checkbox.
			if ( in_array( $field['el_type'], [ 'select', 'radio', 'checkbox' ], true ) && ! empty( $field['options'] ) ) {
				$option_strings = [];
				foreach ( $field['options'] as $opt ) {
					$label = sanitize_text_field( $opt['label'] );
					$value = sanitize_text_field( $opt['value'] );
					if ( $value === $label ) {
						$option_strings[] = $value;
					} else {
						$option_strings[] = $value . '|' . $label;
					}
				}
				$el_field['field_options'] = implode( "\n", $option_strings );
			}

			// For acceptance fields, set the acceptance text.
			if ( 'acceptance' === $field['el_type'] ) {
				$el_field['acceptance_text'] = sanitize_text_field( $field['description'] ?: $field['label'] );
			}

			$el_fields[] = $el_field;
			$index++;
		}

		return $el_fields;
	}

	/**
	 * Build the complete _elementor_data JSON structure.
	 */
	private function build_elementor_data(
		array $el_fields,
		string $portal_id,
		string $form_id,
		string $form_name
	): array {
		return [
			[
				'id'       => $this->generate_element_id(),
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => [
					'content_width' => 'boxed',
				],
				'elements' => [
					[
						'id'         => $this->generate_element_id(),
						'elType'     => 'widget',
						'widgetType' => 'form',
						'isInner'    => false,
						'settings'   => [
							'form_name'            => sanitize_text_field( $form_name ),
							'form_fields'          => $el_fields,
							'submit_actions'       => [ 'hubspot_submit' ],
							'ehsf_portal_id'       => sanitize_text_field( $portal_id ),
							'ehsf_form_guid'       => sanitize_text_field( $form_id ),
							'ehsf_object_type_id'  => '0-1',
							'button_text'          => __( 'Submit', 'ehsf' ),
							'button_size'          => 'sm',
							'button_width'         => '100',
						],
						'elements' => [],
					],
				],
			],
		];
	}

	/**
	 * Generate a random 7-character hex ID matching Elementor's format.
	 */
	private function generate_element_id(): string {
		return substr( md5( wp_generate_uuid4() ), 0, 7 );
	}
}
