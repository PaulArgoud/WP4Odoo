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
	 * Tab slug whitelist.
	 *
	 * @var array<int, string>
	 */
	private const TAB_SLUGS = [ 'connection', 'sync', 'modules', 'queue', 'logs', 'health' ];

	/**
	 * Query service instance.
	 *
	 * @var \WP4Odoo\Query_Service
	 */
	private \WP4Odoo\Query_Service $query_service;

	/**
	 * Get available tabs with translated labels.
	 *
	 * @return array<string, string>
	 */
	private function get_tabs(): array {
		return [
			'connection' => __( 'Connection', 'wp4odoo' ),
			'sync'       => __( 'Synchronization', 'wp4odoo' ),
			'modules'    => __( 'Modules', 'wp4odoo' ),
			'queue'      => __( 'Queue', 'wp4odoo' ),
			'logs'       => __( 'Logs', 'wp4odoo' ),
			'health'     => __( 'Health', 'wp4odoo' ),
		];
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->query_service = new \WP4Odoo\Query_Service();
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
		if ( ! in_array( $active_tab, self::TAB_SLUGS, true ) ) {
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
		$settings = wp4odoo()->settings();

		if ( $settings->is_checklist_dismissed() ) {
			return;
		}

		$connection = $settings->get_connection();

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
				'done'   => $settings->is_webhooks_confirmed(),
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
			$settings->dismiss_checklist();
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
		$settings = wp4odoo()->settings();
		$modules  = \WP4Odoo_Plugin::instance()->get_modules();
		foreach ( $modules as $id => $module ) {
			if ( $settings->is_module_enabled( $id ) ) {
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
		foreach ( $this->get_tabs() as $slug => $label ) {
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

	/**
	 * Render a tab by slug.
	 *
	 * Dispatches to the appropriate render_tab_{slug}() method.
	 *
	 * @param string $slug Tab slug (connection, sync, modules, queue, logs).
	 * @return void
	 */
	public function render_tab( string $slug ): void {
		$method = 'render_tab_' . $slug;
		if ( in_array( $slug, self::TAB_SLUGS, true ) && method_exists( $this, $method ) ) {
			$this->$method();
		}
	}

	// ─── Connection tab ───────────────────────────────────────

	/**
	 * Render the Connection tab.
	 *
	 * @return void
	 */
	public function render_tab_connection(): void {
		$credentials = Odoo_Auth::get_credentials();
		$token       = wp4odoo()->settings()->get_webhook_token();
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
		$existing = wp4odoo()->settings()->get_connection();

		$url = esc_url_raw( $input['url'] ?? '' );

		// SSRF protection: reject private/internal IP addresses and hostnames.
		if ( ! empty( $url ) && ! self::is_safe_url( $url ) ) {
			add_settings_error(
				'wp4odoo_connection',
				'ssrf',
				__( 'The Odoo URL must point to a public address. Private IPs and localhost are not allowed.', 'wp4odoo' ),
				'error'
			);
			$url = '';
		}

		$sanitized = [
			'url'        => $url,
			'database'   => sanitize_text_field( $input['database'] ?? '' ),
			'username'   => sanitize_text_field( $input['username'] ?? '' ),
			'api_key'    => '',
			'protocol'   => in_array( $input['protocol'] ?? '', [ 'jsonrpc', 'xmlrpc', 'json2' ], true )
				? $input['protocol']
				: 'jsonrpc',
			'timeout'    => max( 5, min( 120, absint( $input['timeout'] ?? 30 ) ) ),
			// Odoo company (res.company) used to scope every API call; 0 = the user's default company.
			'company_id' => absint( $input['company_id'] ?? 0 ),
		];

		if ( ! empty( $input['api_key'] ) ) {
			$sanitized['api_key'] = Odoo_Auth::encrypt( $input['api_key'] );
		} elseif ( ! empty( $existing['api_key'] ) ) {
			$sanitized['api_key'] = $existing['api_key'];
		}

		return $sanitized;
	}

	/**
	 * Check if a URL is safe (not pointing to private/internal networks).
	 *
	 * Delegates to Settings_Validator::is_safe_url() for reuse across
	 * Settings_Page and Network_Admin.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if the URL is safe.
	 */
	private static function is_safe_url( string $url ): bool {
		return \WP4Odoo\Settings_Validator::is_safe_url( $url );
	}

	// ─── Sync tab ─────────────────────────────────────────────

	/**
	 * Render the Sync tab.
	 *
	 * @return void
	 */
	public function render_tab_sync(): void {
		$settings      = wp4odoo()->settings();
		$sync_settings = $settings->get_sync_settings();
		$log_settings  = $settings->get_log_settings();

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

		$new_interval = in_array( $input['sync_interval'] ?? '', $valid_intervals, true )
			? $input['sync_interval'] : 'wp4odoo_five_minutes';

		// Reschedule cron if the sync interval changed.
		$current      = get_option( \WP4Odoo\Settings_Repository::OPT_SYNC_SETTINGS, [] );
		$old_interval = $current['sync_interval'] ?? 'wp4odoo_five_minutes';
		if ( $new_interval !== $old_interval ) {
			wp_clear_scheduled_hook( 'wp4odoo_scheduled_sync' );
			wp_schedule_event( time(), $new_interval, 'wp4odoo_scheduled_sync' );
		}

		return [
			'direction'     => in_array( $input['direction'] ?? '', $valid_directions, true )
				? $input['direction'] : 'bidirectional',
			'conflict_rule' => in_array( $input['conflict_rule'] ?? '', $valid_conflicts, true )
				? $input['conflict_rule'] : 'newest_wins',
			'batch_size'    => max( 1, min( 500, absint( $input['batch_size'] ?? 50 ) ) ),
			'sync_interval' => $new_interval,
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
		$stats    = \WP4Odoo\Queue_Manager::get_stats();
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 30;
		$jobs     = $this->query_service->get_queue_jobs( $page, $per_page );

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
		$log_data = $this->query_service->get_log_entries( $filters, $page, $per_page );

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-logs.php';
	}

	// ─── Health tab ────────────────────────────────────────────

	/**
	 * Render the Health tab.
	 *
	 * @return void
	 */
	public function render_tab_health(): void {
		$queue_stats    = \WP4Odoo\Queue_Manager::get_stats();
		$health_metrics = \WP4Odoo\Queue_Manager::get_health_metrics();
		$cb_state       = get_option( \WP4Odoo\Circuit_Breaker::OPT_CB_STATE, [] );

		$registry         = \WP4Odoo_Plugin::instance()->module_registry();
		$booted_count     = $registry->get_booted_count();
		$version_warnings = $registry->get_version_warnings();

		$settings     = wp4odoo()->settings();
		$cron_warning = $settings->get_cron_warning();
		$next_cron    = wp_next_scheduled( 'wp4odoo_scheduled_sync' );

		$module_cb    = new \WP4Odoo\Module_Circuit_Breaker( \WP4Odoo\Logger::for_channel( 'health', $settings ) );
		$open_modules = $module_cb->get_open_modules();

		$missing_tables = \WP4Odoo\Database_Migration::missing_tables();
		$db_error       = get_option( \WP4Odoo\Database_Migration::OPT_DB_ERROR, [] );
		$schema_drops   = \WP4Odoo\Schema_Drop_Log::all();
		$odoo_version   = (string) get_option( 'wp4odoo_odoo_version', '' );

		include WP4ODOO_PLUGIN_DIR . 'admin/views/tab-health.php';
	}
}
