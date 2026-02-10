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

		$result = Odoo_Auth::test_connection( $url, $database, $username, $api_key, $protocol );

		wp_send_json_success( $result );
	}

	/**
	 * Dismiss the onboarding setup notice.
	 *
	 * @return void
	 */
	public function dismiss_onboarding(): void {
		$this->verify_request();

		update_option( 'wp4odoo_onboarding_dismissed', true );

		wp_send_json_success();
	}

	/**
	 * Dismiss the setup checklist.
	 *
	 * @return void
	 */
	public function dismiss_checklist(): void {
		$this->verify_request();

		update_option( 'wp4odoo_checklist_dismissed', true );

		wp_send_json_success();
	}

	/**
	 * Confirm that webhooks have been configured in Odoo.
	 *
	 * @return void
	 */
	public function confirm_webhooks(): void {
		$this->verify_request();

		update_option( 'wp4odoo_checklist_webhooks_confirmed', true );

		wp_send_json_success( [
			'message' => __( 'Webhooks marked as configured.', 'wp4odoo' ),
		] );
	}
}
