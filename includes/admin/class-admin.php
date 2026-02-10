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

		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_setup_notice' ] );
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
				'loading'           => __( 'Loading...', 'wp4odoo' ),
				'lastSync'          => __( 'Last sync: %s', 'wp4odoo' ),
				'cancel'            => __( 'Cancel', 'wp4odoo' ),
				'statusPending'     => __( 'Pending', 'wp4odoo' ),
				'statusProcessing'  => __( 'Processing', 'wp4odoo' ),
				'statusCompleted'   => __( 'Completed', 'wp4odoo' ),
				'statusFailed'      => __( 'Failed', 'wp4odoo' ),
			],
		] );
	}

	/**
	 * Redirect to settings page after first activation.
	 *
	 * Consumes the transient set by activate(). Skips bulk activations,
	 * AJAX requests and WP-CLI context.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'wp4odoo_activated' ) ) {
			return;
		}

		delete_transient( 'wp4odoo_activated' );

		// Skip when activating multiple plugins at once.
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		// Skip AJAX and CLI contexts.
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp4odoo' ) );
		exit;
	}

	/**
	 * Show a setup notice when the connection is not yet configured.
	 *
	 * Displayed on all admin pages until dismissed or configured.
	 *
	 * @return void
	 */
	public function maybe_show_setup_notice(): void {
		if ( get_option( 'wp4odoo_onboarding_dismissed' ) ) {
			return;
		}

		$connection = get_option( 'wp4odoo_connection', [] );
		if ( ! empty( $connection['url'] ) ) {
			return;
		}

		$settings_url = esc_url( admin_url( 'admin.php?page=wp4odoo' ) );

		printf(
			'<div class="notice notice-info is-dismissible wp4odoo-setup-notice" data-nonce="%s">'
			. '<p><strong>%s</strong> %s <a href="%s">%s</a></p>'
			. '</div>',
			esc_attr( wp_create_nonce( 'wp4odoo_admin' ) ),
			esc_html__( 'WordPress For Odoo', 'wp4odoo' ),
			esc_html__( 'is almost ready! Configure your Odoo connection to get started.', 'wp4odoo' ),
			$settings_url,
			esc_html__( 'Go to settings', 'wp4odoo' )
		);

		// Inline dismiss script — lightweight, no need to enqueue admin.js everywhere.
		?>
		<script>
		(function(){
			document.addEventListener('click', function(e) {
				var notice = e.target.closest('.wp4odoo-setup-notice');
				if ( notice && e.target.closest('.notice-dismiss') ) {
					var xhr = new XMLHttpRequest();
					xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.send('action=wp4odoo_dismiss_onboarding&_ajax_nonce=' + notice.dataset.nonce);
				}
			});
		})();
		</script>
		<?php
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
