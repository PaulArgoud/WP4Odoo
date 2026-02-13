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
	 * Query service instance for data retrieval.
	 *
	 * @var \WP4Odoo\Query_Service
	 */
	private \WP4Odoo\Query_Service $query_service;

	/**
	 * Mapping from Odoo model names to the Odoo app that provides them.
	 *
	 * Used in debug-mode warnings to help users identify which Odoo
	 * modules need to be installed.
	 */
	private const ODOO_MODULE_HINT = [
		'crm.lead'         => 'CRM',
		'sale.order'       => 'Sales',
		'product.template' => 'Inventory / Sales',
		'product.product'  => 'Inventory / Sales',
		'stock.quant'      => 'Inventory',
		'account.move'     => 'Invoicing',
		'loyalty.program'  => 'Loyalty',
	];

	/**
	 * Constructor — registers all AJAX hooks.
	 */
	public function __construct() {
		$this->query_service = new \WP4Odoo\Query_Service();

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
			'wp4odoo_detect_languages',
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
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'wp4odoo' ),
				],
				403
			);
		}
	}

	/**
	 * Format a human-readable warning for missing Odoo models.
	 *
	 * When WP_DEBUG is enabled, includes the exact model names and the
	 * Odoo app that provides them. Otherwise returns a generic message
	 * advising to enable debug mode.
	 *
	 * @param array<int, string> $missing Missing Odoo model names.
	 * @return string Translated warning message.
	 */
	protected function format_missing_model_warning( array $missing ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$details = [];
			foreach ( $missing as $model ) {
				$hint      = self::ODOO_MODULE_HINT[ $model ] ?? '';
				$details[] = $hint ? "{$model} ({$hint})" : $model;
			}

			return sprintf(
				/* translators: %s: comma-separated list of Odoo model names with module hints */
				__( 'Required Odoo models are not available: %s. Install the corresponding Odoo modules to use these features.', 'wp4odoo' ),
				implode( ', ', $details )
			);
		}

		return __( 'Some Odoo modules required by the enabled features are not installed on your Odoo instance. Enable WP_DEBUG for technical details.', 'wp4odoo' );
	}

	/**
	 * Sanitize and return a single POST field.
	 *
	 * @param string $key  The $_POST key.
	 * @param string $type Sanitization type: 'text', 'url', 'key', 'int', 'bool'.
	 * @return string|int|bool Sanitized value.
	 */
	protected function get_post_field( string $key, string $type = 'text' ): string|int|bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request() above.
		if ( ! isset( $_POST[ $key ] ) ) {
			return match ( $type ) {
				'int'  => 0,
				'bool' => false,
				default => '',
			};
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request() above.
		$value = wp_unslash( $_POST[ $key ] );

		return match ( $type ) {
			'url'  => $this->sanitize_url( $value ),
			'key'  => sanitize_key( $value ),
			'int'  => absint( $value ),
			'bool' => ! empty( $value ),
			default => sanitize_text_field( $value ),
		};
	}

	/**
	 * Sanitize a URL and reject private/reserved IP ranges (SSRF protection).
	 *
	 * Prevents admin-submitted URLs from targeting internal network addresses
	 * (e.g., 127.0.0.1, 10.x.x.x, 192.168.x.x, link-local, etc.).
	 *
	 * @param string $value Raw URL value.
	 * @return string Sanitized URL, or empty string if rejected.
	 */
	private function sanitize_url( string $value ): string {
		$url = esc_url_raw( $value );
		if ( '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}

		// Resolve hostname to IP for validation.
		$ip = gethostbyname( $host );

		// gethostbyname returns the hostname unchanged on failure.
		if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		// Reject private and reserved IP ranges.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}

		return $url;
	}
}
