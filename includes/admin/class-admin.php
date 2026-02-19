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
		add_action( 'admin_notices', [ $this, 'maybe_show_cron_warning' ] );
		add_action( 'admin_notices', [ $this, 'show_backup_warning' ] );
		add_action( 'admin_notices', [ $this, 'show_version_warnings' ] );
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

		wp_localize_script(
			'wp4odoo-admin',
			'wp4odooAdmin',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp4odoo_setup' ),
				'nonces'  => [
					'setup'   => wp_create_nonce( 'wp4odoo_setup' ),
					'monitor' => wp_create_nonce( 'wp4odoo_monitor' ),
					'module'  => wp_create_nonce( 'wp4odoo_module' ),
				],
				'i18n'    => [
					'testing'            => __( 'Testing...', 'wp4odoo' ),
					'connectionOk'       => __( 'Connection successful!', 'wp4odoo' ),
					'connectionFailed'   => __( 'Connection failed.', 'wp4odoo' ),
					'copied'             => __( 'Copied!', 'wp4odoo' ),
					'confirmPurge'       => __( 'Delete all old log entries?', 'wp4odoo' ),
					'confirmCleanup'     => __( 'Delete completed/failed jobs older than 7 days?', 'wp4odoo' ),
					'confirmCancel'      => __( 'Cancel this job?', 'wp4odoo' ),
					'noResults'          => __( 'No results.', 'wp4odoo' ),
					'settingsSaved'      => __( 'Settings saved.', 'wp4odoo' ),
					'settingsFailed'     => __( 'Failed to save settings.', 'wp4odoo' ),
					'confirmBulkImport'  => __( 'Import all products from Odoo? This will enqueue sync jobs for all Odoo products.', 'wp4odoo' ),
					'confirmBulkExport'  => __( 'Export all products to Odoo? This will enqueue sync jobs for all WooCommerce products.', 'wp4odoo' ),
					'loading'            => __( 'Loading...', 'wp4odoo' ),
					/* translators: %s: formatted date/time of the last synchronization */
					'lastSync'           => __( 'Last sync: %s', 'wp4odoo' ),
					'cancel'             => __( 'Cancel', 'wp4odoo' ),
					'statusPending'      => __( 'Pending', 'wp4odoo' ),
					'statusProcessing'   => __( 'Processing', 'wp4odoo' ),
					'statusCompleted'    => __( 'Completed', 'wp4odoo' ),
					'statusFailed'       => __( 'Failed', 'wp4odoo' ),
					'detectingLanguages' => __( 'Detecting languages...', 'wp4odoo' ),
					'defaultLang'        => __( 'default', 'wp4odoo' ),
					'confirmBackup'      => __( 'Have you backed up your WordPress and Odoo databases?', 'wp4odoo' ),
					'dirWpToOdoo'        => __( 'WP → Odoo', 'wp4odoo' ),
					'serverError'        => __( 'Server communication error.', 'wp4odoo' ),
					'unknownError'       => __( 'Unknown error.', 'wp4odoo' ),
					'completed'          => __( 'completed', 'wp4odoo' ),
					'remove'             => __( 'Remove', 'wp4odoo' ),
					'dirOdooToWp'        => __( 'Odoo → WP', 'wp4odoo' ),
				],
			]
		);
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
		$settings = wp4odoo()->settings();

		if ( $settings->is_onboarding_dismissed() ) {
			return;
		}

		$connection = $settings->get_connection();
		if ( ! empty( $connection['url'] ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wp4odoo' );

		printf(
			'<div class="notice notice-info is-dismissible wp4odoo-setup-notice" data-nonce="%s">'
			. '<p><strong>%s</strong> %s <a href="%s">%s</a></p>'
			. '</div>',
			esc_attr( wp_create_nonce( 'wp4odoo_setup' ) ),
			esc_html__( 'WordPress For Odoo', 'wp4odoo' ),
			esc_html__( 'is almost ready! Configure your Odoo connection to get started.', 'wp4odoo' ),
			esc_url( $settings_url ),
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
	 * Show a warning when WP-Cron has not fired recently.
	 *
	 * Only displayed on the plugin settings page to avoid noise.
	 *
	 * @return void
	 */
	public function maybe_show_cron_warning(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'wp4odoo' !== $_GET['page'] ) {
			return;
		}

		$warning = wp4odoo()->settings()->get_cron_warning();
		if ( '' === $warning ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'WordPress For Odoo', 'wp4odoo' ),
			esc_html( $warning )
		);
	}

	/**
	 * Show a backup warning on the plugin settings page.
	 *
	 * Reminds users to back up WordPress + Odoo databases before sync.
	 *
	 * @return void
	 */
	public function show_backup_warning(): void {
		$screen = get_current_screen();
		if ( ! $screen || $this->hook_suffix !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning wp4odoo-backup-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Back up your databases before any synchronization.', 'wp4odoo' ),
			esc_html__( 'WP4Odoo is designed and tested with care, but WordPress and Odoo (along with their respective modules) evolve at their own pace — a third-party update can introduce unexpected incompatibilities. A full backup (WordPress + Odoo) allows you to roll back if anything goes wrong.', 'wp4odoo' )
		);
	}

	/**
	 * Show version compatibility warnings on the plugin settings page.
	 *
	 * Collects warnings from Module_Registry for modules running on
	 * untested third-party plugin versions and renders them.
	 *
	 * @return void
	 */
	public function show_version_warnings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'wp4odoo' !== $_GET['page'] ) {
			return;
		}

		$warnings = wp4odoo()->module_registry()->get_version_warnings();
		if ( empty( $warnings ) ) {
			return;
		}

		$messages = [];
		foreach ( $warnings as $module_id => $notices ) {
			foreach ( $notices as $notice ) {
				if ( 'warning' !== $notice['type'] ) {
					continue;
				}

				// TESTED_UP_TO notices include plugin_name/plugin_version for the report URL.
				/** @var array{type: string, message: string, plugin_name?: string, plugin_version?: string} $notice */
				$report_url = '';
				if ( isset( $notice['plugin_name'], $notice['plugin_version'] ) ) {
					$report_url = self::build_compat_report_url(
						$module_id,
						$notice['plugin_name'],
						$notice['plugin_version']
					);
				}
				$messages[] = [
					'text'       => $notice['message'],
					'report_url' => $report_url,
				];
			}
		}

		if ( empty( $messages ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'WordPress For Odoo — Untested plugin versions detected:', 'wp4odoo' );
		echo '</strong></p><ul>';
		foreach ( $messages as $msg ) {
			echo '<li>' . esc_html( $msg['text'] );
			if ( strlen( $msg['report_url'] ) > 0 ) {
				printf(
					' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $msg['report_url'] ),
					esc_html__( 'Report compatibility', 'wp4odoo' )
				);
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Build a pre-filled compatibility report URL for untested plugin versions.
	 *
	 * @param string $module_id      Module identifier.
	 * @param string $plugin_name    Third-party plugin name.
	 * @param string $plugin_version Detected plugin version.
	 * @return string Filterable report URL.
	 */
	public static function build_compat_report_url( string $module_id, string $plugin_name, string $plugin_version ): string {
		$form_id  = 34221;
		$base_url = 'https://paul.argoud.net/wp4odoo-plugin-wordpress-odoo-synchronisation-woocommerce/';

		// Field 14 is a text input — accepts any module name via URL prefill.
		$module      = wp4odoo()->module_registry()->get( $module_id );
		$module_name = $module ? $module->get_name() : $module_id;

		$params = [
			"wpf{$form_id}_14" => $module_name,
			"wpf{$form_id}_2" => WP4ODOO_VERSION,
			"wpf{$form_id}_3" => $plugin_name . ' ' . $plugin_version,
			"wpf{$form_id}_6" => get_bloginfo( 'version' ),
			"wpf{$form_id}_7" => PHP_VERSION,
		];

		// Field 9 is a number field — send the major version (e.g. 17).
		$odoo_version = (string) get_option( 'wp4odoo_odoo_version', '' );
		if ( '' !== $odoo_version ) {
			$major = (int) $odoo_version;
			if ( $major > 0 ) {
				$params[ "wpf{$form_id}_9" ] = (string) $major;
			}
		}

		$url = $base_url . '?' . http_build_query( $params ) . '#forms';

		/**
		 * Filters the compatibility report URL shown in version warnings.
		 *
		 * @param string $url            Pre-filled form URL.
		 * @param string $module_id      Module identifier.
		 * @param string $plugin_name    Third-party plugin name.
		 * @param string $plugin_version Detected plugin version.
		 */
		return (string) apply_filters( 'wp4odoo_compat_report_url', $url, $module_id, $plugin_name, $plugin_version );
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
