<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HubSpot_API {

	private const API_BASE          = 'https://api.hubapi.com';
	private const FORMS_SUBMIT_BASE = 'https://api.hsforms.com/submissions/v3/integration/submit';

	/**
	 * Get the stored and decrypted access token.
	 */
	public function get_access_token(): string {
		$encrypted = get_option( 'ehsf_hubspot_access_token', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}
		return $this->decrypt_token( $encrypted );
	}

	/**
	 * Encrypt and store the access token.
	 */
	public function save_access_token( string $token ): void {
		$encrypted = $this->encrypt_token( $token );
		update_option( 'ehsf_hubspot_access_token', $encrypted );
	}

	/**
	 * Get the stored portal ID.
	 */
	public function get_portal_id(): string {
		return get_option( 'ehsf_hubspot_portal_id', '' );
	}

	/**
	 * Validate an access token and auto-detect the portal ID.
	 *
	 * @return array{success: bool, portal_id?: string, error?: string}
	 */
	public function validate_and_connect( string $token ): array {
		$response = wp_remote_get(
			self::API_BASE . '/account-info/v3/details',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return [
				'success' => false,
				'error'   => $body['message'] ?? 'Authentication failed (HTTP ' . $code . ')',
			];
		}

		$portal_id = (string) ( $body['portalId'] ?? '' );
		if ( empty( $portal_id ) ) {
			return [ 'success' => false, 'error' => 'Could not detect portal ID from account info.' ];
		}

		$this->save_access_token( $token );
		update_option( 'ehsf_hubspot_portal_id', $portal_id );

		return [ 'success' => true, 'portal_id' => $portal_id ];
	}

	/**
	 * Fetch a HubSpot form definition.
	 *
	 * @param string $form_id The HubSpot form GUID.
	 * @return array{success: bool, data?: array, error?: string}
	 */
	public function get_form_definition( string $form_id ): array {
		$token = $this->get_access_token();
		if ( empty( $token ) ) {
			return [ 'success' => false, 'error' => 'No access token configured. Please connect to HubSpot first.' ];
		}

		$response = wp_remote_get(
			self::API_BASE . '/marketing/v3/forms/' . urlencode( $form_id ),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return [
				'success' => false,
				'error'   => $body['message'] ?? 'Failed to fetch form definition (HTTP ' . $code . ')',
			];
		}

		return [ 'success' => true, 'data' => $body ];
	}

	/**
	 * Submit form data to HubSpot (public endpoint, no auth required).
	 *
	 * @param string $portal_id
	 * @param string $form_guid
	 * @param array  $fields  Array of ['objectTypeId' => '0-1', 'name' => ..., 'value' => ...]
	 * @param array  $context ['pageUri' => ..., 'pageName' => ..., 'hutk' => ..., 'ipAddress' => ...]
	 * @return array{success: bool, status_code: int, error?: string}
	 */
	public function submit_form( string $portal_id, string $form_guid, array $fields, array $context ): array {
		$url = self::FORMS_SUBMIT_BASE . '/' . $portal_id . '/' . $form_guid;

		$body = [ 'fields' => $fields ];

		// Only include non-empty context values.
		$filtered_context = array_filter( $context );
		if ( ! empty( $filtered_context ) ) {
			$body['context'] = $filtered_context;
		}

		$response = wp_remote_post( $url, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success'     => false,
				'status_code' => 0,
				'error'       => $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return [ 'success' => true, 'status_code' => $code ];
		}

		$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_msg = $resp_body['message'] ?? '';

		// Include individual field errors if present.
		if ( ! empty( $resp_body['errors'] ) && is_array( $resp_body['errors'] ) ) {
			$details = [];
			foreach ( $resp_body['errors'] as $err ) {
				$details[] = $err['message'] ?? '';
			}
			$error_msg = implode( '; ', array_filter( $details ) ) ?: $error_msg;
		}

		return [
			'success'     => false,
			'status_code' => $code,
			'error'       => $error_msg ?: 'Submission failed (HTTP ' . $code . ')',
		];
	}

	/**
	 * Parse a HubSpot embed code to extract portalId and formId.
	 *
	 * Supports both embed formats:
	 *   - Legacy: hbspt.forms.create({ portalId: "...", formId: "..." })
	 *   - Current: <div data-portal-id="..." data-form-id="..."></div>
	 *
	 * @param string $embed_code Raw embed code pasted by the user.
	 * @return array{portal_id?: string, form_id?: string, error?: string}
	 */
	public static function parse_embed_code( string $embed_code ): array {
		$portal_id = '';
		$form_id   = '';

		// Try current format first: data-portal-id / data-form-id attributes.
		if ( preg_match( '/data-portal-id\s*=\s*["\'](\d+)["\']/', $embed_code, $m ) ) {
			$portal_id = $m[1];
		}
		if ( preg_match( '/data-form-id\s*=\s*["\']([a-f0-9\-]{36})["\']/', $embed_code, $m ) ) {
			$form_id = $m[1];
		}

		// Fallback to legacy format: hbspt.forms.create({ portalId: ..., formId: ... }).
		if ( empty( $portal_id ) ) {
			if ( preg_match( '/portalId\s*:\s*["\']?(\d+)["\']?/', $embed_code, $m ) ) {
				$portal_id = $m[1];
			}
		}
		if ( empty( $form_id ) ) {
			if ( preg_match( '/formId\s*:\s*["\']([a-f0-9\-]{36})["\']/', $embed_code, $m ) ) {
				$form_id = $m[1];
			}
		}

		if ( empty( $portal_id ) || empty( $form_id ) ) {
			return [
				'error' => 'Could not extract portalId and formId from the embed code. Please paste the full HubSpot form embed code from HubSpot (either the current data-attribute format or the legacy hbspt.forms.create format).',
			];
		}

		return [
			'portal_id' => $portal_id,
			'form_id'   => $form_id,
		];
	}

	/**
	 * Encrypt a token using AES-256-CBC with WordPress salts.
	 */
	private function encrypt_token( string $token ): string {
		if ( function_exists( 'openssl_encrypt' ) && defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
			$iv  = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 16 );
			$encrypted = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );
			if ( false !== $encrypted ) {
				return base64_encode( $encrypted );
			}
		}
		// Fallback: base64 only (not secure, but functional).
		return base64_encode( $token );
	}

	/**
	 * Decrypt a stored token.
	 */
	private function decrypt_token( string $encrypted ): string {
		$decoded = base64_decode( $encrypted, true );
		if ( false === $decoded ) {
			return '';
		}

		if ( function_exists( 'openssl_decrypt' ) && defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
			$iv  = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 16 );
			$decrypted = openssl_decrypt( $decoded, 'AES-256-CBC', $key, 0, $iv );
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		// Fallback: assume it was base64-only.
		return $decoded;
	}
}
