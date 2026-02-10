<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

use WP4Odoo\API\Odoo_Auth;
use WP4Odoo\Query_Service;
use WP4Odoo\Sync_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page: tabs, forms, Settings API, data rendering.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Settings_Page {

	/**
	 * Available tabs.
	 *
	 * @var array<string, string>
	 */
	private const TABS = [
		'connection' => 'Connection',
		'sync'       => 'Synchronization',
		'modules'    => 'Modules',
		'queue'      => 'Queue',
		'logs'       => 'Logs',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register settings with the WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wp4odoo_connection_group',
			'wp4odoo_connection',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_connection' ],
			]
		);

		register_setting(
			'wp4odoo_sync_group',
			'wp4odoo_sync_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_sync_settings' ],
			]
		);

		register_setting(
			'wp4odoo_sync_group',
			'wp4odoo_log_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_log_settings' ],
			]
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $active_tab, self::TABS ) ) {
			$active_tab = 'connection';
		}

		include WP4ODOO_PLUGIN_DIR . 'admin/views/page-settings.php';
	}

	/**
	 * Render the setup checklist if not yet completed or dismissed.
	 *
	 * @return void
	 */
	public function render_setup_checklist(): void {
		if ( get_option( 'wp4odoo_checklist_dismissed' ) ) {
			return;
		}

		$connection = get_option( 'wp4odoo_connection', [] );

		$steps = [
			[
				'done'   => ! empty( $connection['url'] ) && ! empty( $connection['api_key'] ),
				'label'  => __( 'Connect to Odoo', 'wp4odoo' ),
				'tab'    => 'connection',
				'action' => '',
			],
			[
				'done'   => $this->has_any_module_enabled(),
				'label'  => __( 'Enable a module', 'wp4odoo' ),
				'tab'    => 'modules',
				'action' => '',
			],
			[
				'done'   => (bool) get_option( 'wp4odoo_checklist_webhooks_confirmed' ),
				'label'  => __( 'Configure webhooks in Odoo', 'wp4odoo' ),
				'tab'    => '',
				'action' => 'wp4odoo_confirm_webhooks',
			],
		];

		$done    = count( array_filter( $steps, fn( $s ) => $s['done'] ) );
		$total   = count( $steps );
		$percent = (int) round( ( $done / $total ) * 100 );

		// Auto-dismiss when all steps are done.
		if ( $done === $total ) {
			update_option( 'wp4odoo_checklist_dismissed', true );
			return;
		}

		include WP4ODOO_PLUGIN_DIR . 'admin/views/partial-checklist.php';
	}

	/**
	 * Check whether at least one module is enabled.
	 *
	 * @return bool
	 */
	private function has_any_module_enabled(): bool {
		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		foreach ( $modules as $id => $module ) {
			if ( get_option( 'wp4odoo_module_' . $id . '_enabled' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the tab navigation bar.
	 *
	 * @param string $active_tab The currently active tab slug.
	 * @return void
	 */
	public function render_tabs( string $active_tab ): void {
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $slug => $label ) {
			$url   = admin_url( 'admin.php?page=wp4odoo&tab=' . $slug );
			$class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	// ─── Connection tab ───────────────────────────────────────

	/**
	 * Render the Connection tab.
	 *
	 * @return void
	 */
	public function render_tab_connection(): void {
		$credentials = Odoo_Auth::get_credentials();
		$token       = get_option( 'wp4odoo_webhook_token', '' );
		$webhook_url = rest_url( 'wp4odoo/v1/webhook' );

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-connection.php';
	}

	/**
	 * Sanitize connection settings.
	 *
	 * Handles the special case of preserving the existing encrypted API key
	 * when the password field is submitted empty.
	 *
	 * @param array $input Raw form input.
	 * @return array Sanitized values ready for storage.
	 */
	public function sanitize_connection( array $input ): array {
		$existing = get_option( 'wp4odoo_connection', [] );

		$sanitized = [
			'url'      => esc_url_raw( $input['url'] ?? '' ),
			'database' => sanitize_text_field( $input['database'] ?? '' ),
			'username' => sanitize_text_field( $input['username'] ?? '' ),
			'api_key'  => '',
			'protocol' => in_array( $input['protocol'] ?? '', [ 'jsonrpc', 'xmlrpc' ], true )
				? $input['protocol']
				: 'jsonrpc',
			'timeout'  => max( 5, min( 120, absint( $input['timeout'] ?? 30 ) ) ),
		];

		if ( ! empty( $input['api_key'] ) ) {
			$sanitized['api_key'] = Odoo_Auth::encrypt( $input['api_key'] );
		} elseif ( ! empty( $existing['api_key'] ) ) {
			$sanitized['api_key'] = $existing['api_key'];
		}

		return $sanitized;
	}

	// ─── Sync tab ─────────────────────────────────────────────

	/**
	 * Render the Sync tab.
	 *
	 * @return void
	 */
	public function render_tab_sync(): void {
		$sync_settings = get_option( 'wp4odoo_sync_settings', [] );
		$log_settings  = get_option( 'wp4odoo_log_settings', [] );

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-sync.php';
	}

	/**
	 * Sanitize sync settings.
	 *
	 * @param array $input Raw form input.
	 * @return array
	 */
	public function sanitize_sync_settings( array $input ): array {
		$valid_directions = [ 'bidirectional', 'wp_to_odoo', 'odoo_to_wp' ];
		$valid_conflicts  = [ 'newest_wins', 'odoo_wins', 'wp_wins' ];
		$valid_intervals  = [ 'wp4odoo_five_minutes', 'wp4odoo_fifteen_minutes' ];

		return [
			'direction'     => in_array( $input['direction'] ?? '', $valid_directions, true )
				? $input['direction'] : 'bidirectional',
			'conflict_rule' => in_array( $input['conflict_rule'] ?? '', $valid_conflicts, true )
				? $input['conflict_rule'] : 'newest_wins',
			'batch_size'    => max( 1, min( 500, absint( $input['batch_size'] ?? 50 ) ) ),
			'sync_interval' => in_array( $input['sync_interval'] ?? '', $valid_intervals, true )
				? $input['sync_interval'] : 'wp4odoo_five_minutes',
			'auto_sync'     => ! empty( $input['auto_sync'] ),
		];
	}

	/**
	 * Sanitize log settings.
	 *
	 * @param array $input Raw form input.
	 * @return array
	 */
	public function sanitize_log_settings( array $input ): array {
		$valid_levels = [ 'debug', 'info', 'warning', 'error', 'critical' ];

		return [
			'enabled'        => ! empty( $input['enabled'] ),
			'level'          => in_array( $input['level'] ?? '', $valid_levels, true )
				? $input['level'] : 'info',
			'retention_days' => max( 1, min( 365, absint( $input['retention_days'] ?? 30 ) ) ),
		];
	}

	// ─── Modules tab ──────────────────────────────────────────

	/**
	 * Render the Modules tab.
	 *
	 * @return void
	 */
	public function render_tab_modules(): void {
		$plugin  = \WP4Odoo_Plugin::instance();
		$modules = $plugin->get_modules();

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-modules.php';
	}

	// ─── Queue tab ────────────────────────────────────────────

	/**
	 * Render the Queue tab.
	 *
	 * @return void
	 */
	public function render_tab_queue(): void {
		$stats    = Sync_Engine::get_stats();
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 30;
		$jobs     = Query_Service::get_queue_jobs( $page, $per_page );

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-queue.php';
	}

	// ─── Logs tab ─────────────────────────────────────────────

	/**
	 * Render the Logs tab.
	 *
	 * @return void
	 */
	public function render_tab_logs(): void {
		$page     = isset( $_GET['log_paged'] ) ? max( 1, absint( $_GET['log_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 50;
		$filters  = [];
		$log_data = Query_Service::get_log_entries( $filters, $page, $per_page );

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-logs.php';
	}

}
