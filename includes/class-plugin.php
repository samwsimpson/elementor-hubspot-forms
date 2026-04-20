<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static ?Plugin $instance = null;

	public HubSpot_API $hubspot_api;
	public Admin $admin;
	public Form_Generator $form_generator;
	public Ajax_Handler $ajax_handler;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_components();
		$this->register_hooks();
	}

	private function load_dependencies(): void {
		require_once EHSF_PLUGIN_DIR . 'includes/class-license.php';
		require_once EHSF_PLUGIN_DIR . 'includes/class-hubspot-api.php';
		require_once EHSF_PLUGIN_DIR . 'includes/class-admin.php';
		require_once EHSF_PLUGIN_DIR . 'includes/class-form-generator.php';
		require_once EHSF_PLUGIN_DIR . 'includes/class-ajax-handler.php';
		// class-form-action.php is loaded later in register_form_action()
		// because it extends Action_Base which isn't available until Elementor Pro's forms module loads.

		// Pro modules are loaded conditionally when license is active.
		// Future: require_once pro/ files here when they are built.
	}

	private function init_components(): void {
		License::init();
		$this->hubspot_api    = new HubSpot_API();
		$this->form_generator = new Form_Generator( $this->hubspot_api );
		$this->ajax_handler   = new Ajax_Handler( $this->hubspot_api, $this->form_generator );
		$this->admin          = new Admin( $this->hubspot_api );
	}

	private function register_hooks(): void {
		add_action(
			'elementor_pro/forms/actions/register',
			[ $this, 'register_form_action' ]
		);

		add_action(
			'elementor/frontend/widget/before_render',
			[ $this, 'tag_hubspot_forms' ]
		);
	}

	/**
	 * Register the HubSpot Submit form action with Elementor Pro.
	 */
	public function register_form_action( $registrar ): void {
		require_once EHSF_PLUGIN_DIR . 'includes/class-form-action.php';
		$registrar->register( new Form_Action() );
	}

	/**
	 * Tag Elementor forms that use the HubSpot Submit action so the frontend
	 * can scope dataLayer / analytics listeners to them.
	 */
	public function tag_hubspot_forms( $widget ): void {
		if ( $widget->get_name() !== 'form' ) {
			return;
		}

		$settings       = $widget->get_settings_for_display();
		$submit_actions = (array) ( $settings['submit_actions'] ?? [] );

		if ( ! in_array( 'hubspot_submit', $submit_actions, true ) ) {
			return;
		}

		$widget->add_render_attribute( 'form', [
			'class'                  => 'ehsf-hubspot-form',
			'data-ehsf-form-guid'    => $settings['ehsf_form_guid'] ?? '',
		] );
	}
}
