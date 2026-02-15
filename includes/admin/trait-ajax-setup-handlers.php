<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

use WP4Odoo\API\Odoo_Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for connection testing and onboarding.
 *
 * Used by Admin_Ajax via trait composition.
 *
 * @package WP4Odoo
 * @since   1.9.6
 */
trait Ajax_Setup_Handlers {

	/**
	 * Test Odoo connection with provided credentials.
	 *
	 * When the connection succeeds, also probes Odoo for all models
	 * required by registered plugin modules and reports any that are
	 * missing (i.e., the corresponding Odoo app is not installed).
	 *
	 * @return void
	 */
	public function test_connection(): void {
		$this->verify_request();

		$url      = $this->get_post_field( 'url', 'url' ) ?: null;
		$database = $this->get_post_field( 'database' ) ?: null;
		$username = $this->get_post_field( 'username' ) ?: null;
		$api_key  = $this->get_post_field( 'api_key' ) ?: null;
		$protocol = $this->get_post_field( 'protocol' ) ?: 'jsonrpc';

		// If api_key is empty, use the stored one.
		if ( empty( $api_key ) ) {
			$stored  = Odoo_Auth::get_credentials();
			$api_key = $stored['api_key'] ?: null;
		}

		// Collect all unique Odoo model names from registered modules.
		$check_models = $this->collect_module_models();

		$result = Odoo_Auth::test_connection( $url, $database, $username, $api_key, $protocol, $check_models );

		// Store the Odoo version for display and compat report links.
		if ( ! empty( $result['version'] ) ) {
			update_option( 'wp4odoo_odoo_version', sanitize_text_field( $result['version'] ) );
		}

		// Add human-readable warning for missing models.
		if ( ! empty( $result['models']['missing'] ) ) {
			$result['model_warning'] = $this->format_missing_model_warning( $result['models']['missing'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Collect all unique Odoo model names from registered modules.
	 *
	 * @return array<int, string>
	 */
	private function collect_module_models(): array {
		$models = [];

		foreach ( \WP4Odoo_Plugin::instance()->get_modules() as $module ) {
			foreach ( $module->get_odoo_models() as $model_name ) {
				$models[] = $model_name;
			}
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Dismiss the onboarding setup notice.
	 *
	 * @return void
	 */
	public function dismiss_onboarding(): void {
		$this->verify_request();

		wp4odoo()->settings()->dismiss_onboarding();

		wp_send_json_success();
	}

	/**
	 * Dismiss the setup checklist.
	 *
	 * @return void
	 */
	public function dismiss_checklist(): void {
		$this->verify_request();

		wp4odoo()->settings()->dismiss_checklist();

		wp_send_json_success();
	}

	/**
	 * Confirm that webhooks have been configured in Odoo.
	 *
	 * @return void
	 */
	public function confirm_webhooks(): void {
		$this->verify_request();

		wp4odoo()->settings()->confirm_webhooks();

		wp_send_json_success(
			[
				'message' => __( 'Webhooks marked as configured.', 'wp4odoo' ),
			]
		);
	}
}
