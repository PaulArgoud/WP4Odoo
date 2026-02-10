<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin orchestrator: menu, assets, plugin action link.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Admin {

	/**
	 * Settings page instance.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * Hook suffix returned by add_menu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		new Admin_Ajax();
		$this->settings_page = new Settings_Page();

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . WP4ODOO_PLUGIN_BASENAME, [ $this, 'add_settings_link' ] );
	}

	/**
	 * Register the top-level admin menu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_menu_page(
			__( 'Odoo Connector', 'wp4odoo' ),
			__( 'Odoo Connector', 'wp4odoo' ),
			'manage_options',
			'wp4odoo',
			[ $this->settings_page, 'render' ],
			'dashicons-randomize',
			80
		);
	}

	/**
	 * Enqueue admin CSS and JS on the plugin page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wp4odoo-admin',
			WP4ODOO_PLUGIN_URL . 'admin/css/admin.css',
			[],
			WP4ODOO_VERSION
		);

		wp_enqueue_script(
			'wp4odoo-admin',
			WP4ODOO_PLUGIN_URL . 'admin/js/admin.js',
			[ 'jquery' ],
			WP4ODOO_VERSION,
			true
		);

		wp_localize_script( 'wp4odoo-admin', 'wp4odooAdmin', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wp4odoo_admin' ),
			'i18n'    => [
				'testing'          => __( 'Testing...', 'wp4odoo' ),
				'connectionOk'     => __( 'Connection successful!', 'wp4odoo' ),
				'connectionFailed' => __( 'Connection failed.', 'wp4odoo' ),
				'copied'           => __( 'Copied!', 'wp4odoo' ),
				'confirmPurge'     => __( 'Delete all old log entries?', 'wp4odoo' ),
				'confirmCleanup'   => __( 'Delete completed/failed jobs older than 7 days?', 'wp4odoo' ),
				'confirmCancel'    => __( 'Cancel this job?', 'wp4odoo' ),
				'noResults'        => __( 'No results.', 'wp4odoo' ),
				'settingsSaved'    => __( 'Settings saved.', 'wp4odoo' ),
				'settingsFailed'   => __( 'Failed to save settings.', 'wp4odoo' ),
				'confirmBulkImport' => __( 'Import all products from Odoo? This will enqueue sync jobs for all Odoo products.', 'wp4odoo' ),
				'confirmBulkExport' => __( 'Export all products to Odoo? This will enqueue sync jobs for all WooCommerce products.', 'wp4odoo' ),
			],
		] );
	}

	/**
	 * Add "Settings" link on the plugins list page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified links.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wp4odoo' ) ),
			__( 'Settings', 'wp4odoo' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}
