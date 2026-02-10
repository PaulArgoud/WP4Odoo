<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for admin operations.
 *
 * Handler methods are organized in domain-specific traits:
 * - Ajax_Monitor_Handlers — queue management and log viewing
 * - Ajax_Module_Handlers  — module settings and bulk operations
 * - Ajax_Setup_Handlers   — connection testing and onboarding
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Admin_Ajax {

	use Ajax_Monitor_Handlers;
	use Ajax_Module_Handlers;
	use Ajax_Setup_Handlers;

	/**
	 * Constructor — registers all AJAX hooks.
	 */
	public function __construct() {
		$actions = [
			'wp4odoo_test_connection',
			'wp4odoo_retry_failed',
			'wp4odoo_cleanup_queue',
			'wp4odoo_cancel_job',
			'wp4odoo_purge_logs',
			'wp4odoo_fetch_logs',
			'wp4odoo_fetch_queue',
			'wp4odoo_queue_stats',
			'wp4odoo_toggle_module',
			'wp4odoo_save_module_settings',
			'wp4odoo_bulk_import_products',
			'wp4odoo_bulk_export_products',
			'wp4odoo_dismiss_onboarding',
			'wp4odoo_dismiss_checklist',
			'wp4odoo_confirm_webhooks',
		];

		foreach ( $actions as $action ) {
			$method = str_replace( 'wp4odoo_', '', $action );
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Verify nonce and capability. Dies on failure.
	 *
	 * @return void
	 */
	protected function verify_request(): void {
		check_ajax_referer( 'wp4odoo_admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'Permission denied.', 'wp4odoo' ),
			], 403 );
		}
	}

	/**
	 * Sanitize and return a single POST field.
	 *
	 * @param string $key  The $_POST key.
	 * @param string $type Sanitization type: 'text', 'url', 'key', 'int', 'bool'.
	 * @return string|int|bool Sanitized value.
	 */
	protected function get_post_field( string $key, string $type = 'text' ): string|int|bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return match ( $type ) {
				'int'  => 0,
				'bool' => false,
				default => '',
			};
		}

		$value = wp_unslash( $_POST[ $key ] );

		return match ( $type ) {
			'url'  => esc_url_raw( $value ),
			'key'  => sanitize_key( $value ),
			'int'  => absint( $value ),
			'bool' => ! empty( $value ),
			default => sanitize_text_field( $value ),
		};
	}
}
