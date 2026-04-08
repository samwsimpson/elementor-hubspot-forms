<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private HubSpot_API $api;

	public function __construct( HubSpot_API $api ) {
		$this->api = $api;
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the settings page under Settings menu.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'HubSpot Forms', 'ehsf' ),
			__( 'HubSpot Forms', 'ehsf' ),
			'manage_options',
			'ehsf-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue admin assets only on our settings page.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_ehsf-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ehsf-admin',
			EHSF_PLUGIN_URL . 'admin/css/admin.css',
			[],
			EHSF_VERSION
		);

		wp_enqueue_script(
			'ehsf-admin',
			EHSF_PLUGIN_URL . 'admin/js/admin.js',
			[ 'jquery' ],
			EHSF_VERSION,
			true
		);

		wp_localize_script( 'ehsf-admin', 'ehsfAdmin', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ehsf_admin_nonce' ),
			'is_pro'   => License::is_pro(),
			'strings'  => [
				'connecting'         => __( 'Connecting...', 'ehsf' ),
				'connected'          => __( 'Connected!', 'ehsf' ),
				'fetching'           => __( 'Fetching form from HubSpot...', 'ehsf' ),
				'generating'         => __( 'Generating Elementor template...', 'ehsf' ),
				'confirm_delete'     => __( 'Are you sure you want to delete this template?', 'ehsf' ),
				'activating'         => __( 'Activating license...', 'ehsf' ),
				'deactivating'       => __( 'Deactivating...', 'ehsf' ),
				'confirm_deactivate' => __( 'Deactivate your Pro license on this site?', 'ehsf' ),
			],
		] );
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$portal_id    = $this->api->get_portal_id();
		$is_connected = ! empty( $portal_id ) && ! empty( $this->api->get_access_token() );
		$is_pro       = License::is_pro();

		?>
		<div class="wrap ehsf-wrap">
			<h1>
				<?php esc_html_e( 'Elementor HubSpot Forms', 'ehsf' ); ?>
				<?php if ( $is_pro ) : ?>
					<span class="ehsf-pro-badge"><?php esc_html_e( 'Pro', 'ehsf' ); ?></span>
				<?php endif; ?>
				<a href="https://www.kumokodo.ai/support" target="_blank" rel="noopener" class="page-title-action ehsf-support-link">
					<span class="dashicons dashicons-sos" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Contact Support', 'ehsf' ); ?>
				</a>
			</h1>

			<?php if ( ! $is_connected ) : ?>
			<!-- How It Works (shown before connection) -->
			<div class="ehsf-card ehsf-how-it-works">
				<h2><?php esc_html_e( 'How It Works', 'ehsf' ); ?></h2>
				<ol class="ehsf-steps">
					<li>
						<strong><?php esc_html_e( 'Connect HubSpot', 'ehsf' ); ?></strong>
						<span><?php esc_html_e( 'Enter your Private App token below to link your HubSpot account.', 'ehsf' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Paste Embed Code', 'ehsf' ); ?></strong>
						<span><?php esc_html_e( 'Copy the embed code from any HubSpot form and paste it here.', 'ehsf' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Preview & Generate', 'ehsf' ); ?></strong>
						<span><?php esc_html_e( 'Review the mapped fields, then click to create an Elementor template.', 'ehsf' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Style & Publish', 'ehsf' ); ?></strong>
						<span><?php esc_html_e( 'Open the template in Elementor to style it, then insert it into any page. Submissions go straight to HubSpot automatically.', 'ehsf' ); ?></span>
					</li>
				</ol>
			</div>
			<?php endif; ?>

			<!-- Section 1: Connection -->
			<div class="ehsf-card">
				<h2><?php esc_html_e( 'HubSpot Connection', 'ehsf' ); ?></h2>

				<?php if ( $is_connected ) : ?>
					<div class="ehsf-status ehsf-status--connected">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php printf(
							esc_html__( 'Connected to HubSpot portal %s', 'ehsf' ),
							'<strong>' . esc_html( $portal_id ) . '</strong>'
						); ?>
					</div>
					<button type="button" class="button" id="ehsf-disconnect">
						<?php esc_html_e( 'Disconnect', 'ehsf' ); ?>
					</button>
				<?php else : ?>
					<p><?php esc_html_e( 'Connect your HubSpot account to get started.', 'ehsf' ); ?></p>
					<p class="description">
						<?php esc_html_e( 'You\'ll need a HubSpot Private App Access Token. To create one:', 'ehsf' ); ?>
						<br>
						<?php esc_html_e( '1. In HubSpot, go to Settings > Integrations > Private Apps', 'ehsf' ); ?>
						<br>
						<?php esc_html_e( '2. Click "Create a private app", give it a name', 'ehsf' ); ?>
						<br>
						<?php esc_html_e( '3. On the Scopes tab, enable "forms" (under Marketing)', 'ehsf' ); ?>
						<br>
						<?php esc_html_e( '4. Click "Create app", then copy the Access Token', 'ehsf' ); ?>
					</p>
					<div class="ehsf-connect-row">
						<input type="password" id="ehsf-token" class="regular-text"
						       placeholder="pat-na1-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
						<button type="button" class="button button-primary" id="ehsf-connect">
							<?php esc_html_e( 'Connect', 'ehsf' ); ?>
						</button>
					</div>
					<div id="ehsf-connect-status" class="ehsf-status-message"></div>
				<?php endif; ?>
			</div>

			<?php if ( $is_connected ) : ?>
			<!-- Section 2: Form Generator -->
			<div class="ehsf-card">
				<h2><?php esc_html_e( 'Generate Form from HubSpot', 'ehsf' ); ?></h2>
				<p><?php esc_html_e( 'Paste your HubSpot form embed code below to auto-generate an Elementor form with all fields mapped.', 'ehsf' ); ?></p>
				<details class="ehsf-help-toggle">
					<summary><?php esc_html_e( 'Where do I find the embed code?', 'ehsf' ); ?></summary>
					<ol class="description">
						<li><?php esc_html_e( 'In HubSpot, go to Marketing > Forms', 'ehsf' ); ?></li>
						<li><?php esc_html_e( 'Click on the form you want to use', 'ehsf' ); ?></li>
						<li><?php esc_html_e( 'Click "Share" (top right), then "Embed code"', 'ehsf' ); ?></li>
						<li><?php esc_html_e( 'Copy the entire code snippet and paste it below', 'ehsf' ); ?></li>
					</ol>
				</details>

				<?php if ( ! $is_pro ) : ?>
					<?php
					$form_count = $this->count_generated_forms();
					$remaining  = max( 0, 3 - $form_count );
					?>
					<p class="ehsf-form-limit-notice">
						<?php printf(
							esc_html__( 'Free plan: %1$d of 3 forms used (%2$d remaining).', 'ehsf' ),
							$form_count,
							$remaining
						); ?>
						<a href="https://kumokodo.ai/wpplugins" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'ehsf' ); ?></a>
					</p>
				<?php endif; ?>

				<label for="ehsf-embed-code" class="ehsf-label">
					<?php esc_html_e( 'HubSpot Form Embed Code', 'ehsf' ); ?>
				</label>
				<textarea id="ehsf-embed-code" rows="5" class="large-text code"
				          placeholder='<script charset="utf-8" type="text/javascript" src="//js.hsforms.net/forms/embed/v2.js"></script>&#10;<script>&#10;  hbspt.forms.create({&#10;    portalId: "12345678",&#10;    formId: "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"&#10;  });&#10;</script>'></textarea>
				<button type="button" class="button button-primary" id="ehsf-fetch-preview">
					<?php esc_html_e( 'Fetch & Preview', 'ehsf' ); ?>
				</button>
				<span id="ehsf-fetch-status" class="ehsf-status-message"></span>

				<!-- Preview area (populated by JS) -->
				<div id="ehsf-preview" style="display:none;">
					<h3>
						<?php esc_html_e( 'Form Preview', 'ehsf' ); ?>:
						<span id="ehsf-form-name"></span>
					</h3>
					<p class="description">
						<?php esc_html_e( 'These fields will be created in your Elementor form:', 'ehsf' ); ?>
					</p>
					<table class="widefat striped" id="ehsf-fields-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Field ID', 'ehsf' ); ?></th>
								<th><?php esc_html_e( 'Label', 'ehsf' ); ?></th>
								<th><?php esc_html_e( 'HubSpot Type', 'ehsf' ); ?></th>
								<th><?php esc_html_e( 'Elementor Type', 'ehsf' ); ?></th>
								<th><?php esc_html_e( 'Required', 'ehsf' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
					<p>
						<button type="button" class="button button-primary button-hero" id="ehsf-create-template">
							<?php esc_html_e( 'Create Elementor Template', 'ehsf' ); ?>
						</button>
					</p>
				</div>

				<!-- Result area -->
				<div id="ehsf-result" style="display:none;">
					<div class="notice notice-success inline">
						<p>
							<strong><?php esc_html_e( 'Template created successfully!', 'ehsf' ); ?></strong>
						</p>
						<p>
							<a id="ehsf-edit-link" href="#" target="_blank" class="button button-primary">
								<?php esc_html_e( 'Edit in Elementor', 'ehsf' ); ?> &rarr;
							</a>
						</p>
						<p class="description"><?php esc_html_e( 'Next steps:', 'ehsf' ); ?></p>
						<ol class="description">
							<li><?php esc_html_e( 'Click "Edit in Elementor" to style the form (colors, fonts, spacing, etc.)', 'ehsf' ); ?></li>
							<li><?php esc_html_e( 'To add the form to a page: edit the page in Elementor, add a Template widget, and select this template — or use the shortcode from the table below.', 'ehsf' ); ?></li>
							<li><?php esc_html_e( 'That\'s it! Submissions will be sent to HubSpot automatically.', 'ehsf' ); ?></li>
						</ol>
					</div>
				</div>
			</div>

			<!-- Section 3: Generated Forms List -->
			<div class="ehsf-card">
				<h2><?php esc_html_e( 'Your Generated Forms', 'ehsf' ); ?></h2>
				<?php $this->render_generated_forms_table(); ?>
			</div>

			<!-- Section 4: Submission Log -->
			<div class="ehsf-card">
				<h2><?php esc_html_e( 'Recent Submissions', 'ehsf' ); ?></h2>
				<?php $this->render_submission_log(); ?>
			</div>

			<?php if ( ! $is_pro ) : ?>
			<!-- Pro Upgrade Teaser -->
			<div class="ehsf-card ehsf-pro-teaser">
				<h2><?php esc_html_e( 'Unlock Pro Features', 'ehsf' ); ?></h2>
				<div class="ehsf-pro-features">
					<div class="ehsf-pro-feature">
						<span class="dashicons dashicons-chart-bar"></span>
						<h3><?php esc_html_e( 'Form Analytics', 'ehsf' ); ?></h3>
						<p><?php esc_html_e( 'Submission counts, success rates, and 30-day trend charts for every form.', 'ehsf' ); ?></p>
					</div>
					<div class="ehsf-pro-feature">
						<span class="dashicons dashicons-forms"></span>
						<h3><?php esc_html_e( 'Unlimited Forms', 'ehsf' ); ?></h3>
						<p><?php esc_html_e( 'Remove the 3-form limit. Generate as many forms as you need.', 'ehsf' ); ?></p>
					</div>
					<div class="ehsf-pro-feature">
						<span class="dashicons dashicons-randomize"></span>
						<h3><?php esc_html_e( 'Advanced Submissions', 'ehsf' ); ?></h3>
						<p><?php esc_html_e( 'Custom redirects, email notifications, and webhook forwarding after form submissions.', 'ehsf' ); ?></p>
					</div>
					<div class="ehsf-pro-feature">
						<span class="dashicons dashicons-list-view"></span>
						<h3><?php esc_html_e( 'Bulk Generation', 'ehsf' ); ?></h3>
						<p><?php esc_html_e( 'Fetch all your HubSpot forms and generate Elementor templates in one click.', 'ehsf' ); ?></p>
					</div>
					<div class="ehsf-pro-feature">
						<span class="dashicons dashicons-admin-settings"></span>
						<h3><?php esc_html_e( 'Multi-Step Forms', 'ehsf' ); ?></h3>
						<p><?php esc_html_e( 'Auto-generate multi-step Elementor forms from HubSpot field groups.', 'ehsf' ); ?></p>
					</div>
					<div class="ehsf-pro-feature">
						<span class="dashicons dashicons-admin-users"></span>
						<h3><?php esc_html_e( 'CRM Pre-fill', 'ehsf' ); ?></h3>
						<p><?php esc_html_e( 'Auto-populate fields for returning HubSpot contacts.', 'ehsf' ); ?></p>
					</div>
				</div>
				<p class="ehsf-pro-cta">
					<a href="https://kumokodo.ai/wpplugins" target="_blank" class="button button-primary button-hero">
						<?php esc_html_e( 'Upgrade to Pro', 'ehsf' ); ?> &rarr;
					</a>
				</p>
			</div>
			<?php endif; ?>

			<?php endif; ?>

			<!-- License Section (always visible) -->
			<div class="ehsf-card">
				<h2><?php esc_html_e( 'License', 'ehsf' ); ?></h2>
				<?php $this->render_license_section(); ?>
			</div>

			<!-- Need Help? Footer -->
			<p class="ehsf-support-footer">
				<?php
				printf(
					/* translators: %s: support link */
					esc_html__( 'Need help or ran into a problem? %s and we\'ll take a look.', 'ehsf' ),
					'<a href="https://www.kumokodo.ai/support" target="_blank" rel="noopener"><strong>' . esc_html__( 'Contact KumoKodo support', 'ehsf' ) . '</strong></a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the license activation/deactivation section.
	 */
	private function render_license_section(): void {
		$license_info = License::get_license_info();
		$is_pro       = License::is_pro();

		if ( $is_pro ) : ?>
			<div class="ehsf-status ehsf-status--connected">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Pro license is active.', 'ehsf' ); ?>
				<?php if ( ! empty( $license_info['expires_at'] ) ) : ?>
					<span class="ehsf-license-expires">
						<?php printf(
							esc_html__( 'Expires: %s', 'ehsf' ),
							esc_html( date_i18n( get_option( 'date_format' ), strtotime( $license_info['expires_at'] ) ) )
						); ?>
					</span>
				<?php endif; ?>
			</div>
			<button type="button" class="button" id="ehsf-deactivate-license">
				<?php esc_html_e( 'Deactivate License', 'ehsf' ); ?>
			</button>
		<?php else : ?>
			<p><?php esc_html_e( 'Enter your Pro license key to unlock all features.', 'ehsf' ); ?></p>
			<div class="ehsf-connect-row">
				<input type="text" id="ehsf-license-key" class="regular-text"
				       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
				<button type="button" class="button button-primary" id="ehsf-activate-license">
					<?php esc_html_e( 'Activate', 'ehsf' ); ?>
				</button>
			</div>
			<div id="ehsf-license-status" class="ehsf-status-message"></div>
			<p class="description">
				<?php esc_html_e( 'Don\'t have a license?', 'ehsf' ); ?>
				<a href="https://kumokodo.ai/wpplugins" target="_blank"><?php esc_html_e( 'Get Pro', 'ehsf' ); ?> &rarr;</a>
			</p>
		<?php endif;
	}

	/**
	 * Render the table of previously generated templates.
	 */
	private function render_generated_forms_table(): void {
		$templates = get_posts( [
			'post_type'      => 'elementor_library',
			'meta_key'       => '_ehsf_hubspot_form_id',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
		] );

		if ( empty( $templates ) ) {
			echo '<p>' . esc_html__( 'No forms generated yet. Paste a HubSpot embed code above to get started.', 'ehsf' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Template Name', 'ehsf' ) . '</th>';
		echo '<th>' . esc_html__( 'HubSpot Form', 'ehsf' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'ehsf' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'ehsf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $templates as $template ) {
			$form_name = get_post_meta( $template->ID, '_ehsf_hubspot_form_name', true );
			$form_id   = get_post_meta( $template->ID, '_ehsf_hubspot_form_id', true );
			$edit_url  = admin_url( 'post.php?post=' . $template->ID . '&action=elementor' );

			echo '<tr>';
			echo '<td>' . esc_html( $template->post_title ) . '</td>';
			echo '<td>' . esc_html( $form_name ) . ' <code class="ehsf-small-code">' . esc_html( substr( $form_id, 0, 8 ) ) . '...</code></td>';
			echo '<td><code>[elementor-template id="' . esc_attr( $template->ID ) . '"]</code></td>';
			echo '<td>';
			echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small" target="_blank">' . esc_html__( 'Edit in Elementor', 'ehsf' ) . '</a> ';
			echo '<button type="button" class="button button-small ehsf-delete-template" data-id="' . esc_attr( $template->ID ) . '">' . esc_html__( 'Delete', 'ehsf' ) . '</button>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the submission log table.
	 */
	private function render_submission_log(): void {
		$log = get_option( 'ehsf_submission_log', [] );

		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No submissions logged yet.', 'ehsf' ) . '</p>';
			return;
		}

		// Show most recent first.
		$log = array_reverse( $log );

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'ehsf' ) . '</th>';
		echo '<th>' . esc_html__( 'Form', 'ehsf' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ehsf' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'ehsf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			$status_class = $entry['success'] ? 'ehsf-log-success' : 'ehsf-log-error';
			$status_text  = $entry['success'] ? __( 'Success', 'ehsf' ) : __( 'Failed', 'ehsf' );

			echo '<tr>';
			echo '<td>' . esc_html( $entry['time'] ) . '</td>';
			echo '<td><code>' . esc_html( substr( $entry['form_guid'], 0, 8 ) ) . '...</code></td>';
			echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_text ) . '</span></td>';
			echo '<td>';
			if ( $entry['success'] ) {
				echo esc_html( 'HTTP ' . $entry['status_code'] );
			} else {
				echo esc_html( $entry['error'] );
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Count the number of plugin-generated forms.
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
