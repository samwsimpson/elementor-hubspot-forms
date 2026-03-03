<?php
namespace EHSF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class License {

	private const API_BASE = 'https://api.lemonsqueezy.com/v1/licenses';

	private static ?bool $is_pro_cache = null;

	/**
	 * Check if the current site has an active pro license.
	 * Result is cached for the duration of the PHP request.
	 */
	public static function is_pro(): bool {
		if ( null !== self::$is_pro_cache ) {
			return self::$is_pro_cache;
		}

		$status = get_option( 'ehsf_license_status', '' );
		self::$is_pro_cache = ( 'active' === $status );
		return self::$is_pro_cache;
	}

	/**
	 * Initialize the license system. Called on admin_init.
	 */
	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_revalidate' ] );
	}

	/**
	 * Activate a license key with LemonSqueezy.
	 *
	 * @return array{success: bool, error?: string}
	 */
	public static function activate( string $license_key ): array {
		$response = wp_remote_post( self::API_BASE . '/activate', [
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'license_key'   => $license_key,
				'instance_name' => self::get_instance_name(),
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['activated'] ) ) {
			$error = $body['error'] ?? 'License activation failed.';
			return [ 'success' => false, 'error' => $error ];
		}

		// Store license data.
		self::save_license_key( $license_key );
		update_option( 'ehsf_license_status', 'active' );
		update_option( 'ehsf_license_instance_id', $body['instance']['id'] ?? '' );
		update_option( 'ehsf_license_next_check', time() + DAY_IN_SECONDS );

		if ( ! empty( $body['license_key']['expires_at'] ) ) {
			update_option( 'ehsf_license_expires_at', $body['license_key']['expires_at'] );
		}

		// Reset the static cache.
		self::$is_pro_cache = true;

		return [ 'success' => true ];
	}

	/**
	 * Deactivate the license key with LemonSqueezy.
	 *
	 * @return array{success: bool, error?: string}
	 */
	public static function deactivate(): array {
		$license_key = self::get_license_key();
		$instance_id = get_option( 'ehsf_license_instance_id', '' );

		if ( ! empty( $license_key ) && ! empty( $instance_id ) ) {
			wp_remote_post( self::API_BASE . '/deactivate', [
				'headers' => [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				'body' => [
					'license_key' => $license_key,
					'instance_id' => $instance_id,
				],
				'timeout' => 15,
			] );
		}

		// Clear all license data regardless of API response.
		delete_option( 'ehsf_license_key' );
		delete_option( 'ehsf_license_status' );
		delete_option( 'ehsf_license_instance_id' );
		delete_option( 'ehsf_license_next_check' );
		delete_option( 'ehsf_license_expires_at' );

		self::$is_pro_cache = false;

		return [ 'success' => true ];
	}

	/**
	 * Re-validate the license if the check interval has passed.
	 * Called on admin_init — runs at most once per day.
	 */
	public static function maybe_revalidate(): void {
		$status = get_option( 'ehsf_license_status', '' );
		if ( 'active' !== $status ) {
			return;
		}

		$next_check = (int) get_option( 'ehsf_license_next_check', 0 );
		if ( time() < $next_check ) {
			return;
		}

		$license_key = self::get_license_key();
		$instance_id = get_option( 'ehsf_license_instance_id', '' );

		if ( empty( $license_key ) ) {
			update_option( 'ehsf_license_status', 'inactive' );
			self::$is_pro_cache = false;
			return;
		}

		$body_params = [ 'license_key' => $license_key ];
		if ( ! empty( $instance_id ) ) {
			$body_params['instance_id'] = $instance_id;
		}

		$response = wp_remote_post( self::API_BASE . '/validate', [
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body'    => $body_params,
			'timeout' => 15,
		] );

		// Schedule next check regardless of outcome (avoid hammering API on error).
		update_option( 'ehsf_license_next_check', time() + DAY_IN_SECONDS );

		if ( is_wp_error( $response ) ) {
			// Network error — keep current status, try again tomorrow.
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['valid'] ) ) {
			update_option( 'ehsf_license_status', 'active' );
			self::$is_pro_cache = true;
		} else {
			update_option( 'ehsf_license_status', 'inactive' );
			self::$is_pro_cache = false;
		}
	}

	/**
	 * Get the current license status for display.
	 *
	 * @return array{status: string, expires_at: string}
	 */
	public static function get_license_info(): array {
		return [
			'status'     => get_option( 'ehsf_license_status', '' ),
			'expires_at' => get_option( 'ehsf_license_expires_at', '' ),
		];
	}

	/**
	 * Get the site identifier used for license activation.
	 */
	private static function get_instance_name(): string {
		$url = get_site_url();
		// Strip protocol and trailing slash for a clean identifier.
		return preg_replace( '#^https?://#', '', rtrim( $url, '/' ) );
	}

	/**
	 * Encrypt and store the license key.
	 */
	private static function save_license_key( string $key ): void {
		if ( function_exists( 'openssl_encrypt' ) && defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$enc_key   = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
			$iv        = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 16 );
			$encrypted = openssl_encrypt( $key, 'AES-256-CBC', $enc_key, 0, $iv );
			if ( false !== $encrypted ) {
				update_option( 'ehsf_license_key', base64_encode( $encrypted ) );
				return;
			}
		}
		update_option( 'ehsf_license_key', base64_encode( $key ) );
	}

	/**
	 * Retrieve and decrypt the stored license key.
	 */
	private static function get_license_key(): string {
		$stored = get_option( 'ehsf_license_key', '' );
		if ( empty( $stored ) ) {
			return '';
		}

		$decoded = base64_decode( $stored, true );
		if ( false === $decoded ) {
			return '';
		}

		if ( function_exists( 'openssl_decrypt' ) && defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$enc_key   = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
			$iv        = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 16 );
			$decrypted = openssl_decrypt( $decoded, 'AES-256-CBC', $enc_key, 0, $iv );
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		return $decoded;
	}
}
