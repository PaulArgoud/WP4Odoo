<?php
/**
 * Plugin Name: WordPress For Odoo
 * Plugin URI: https://github.com/PaulArgoud/wordpress-for-odoo
 * Description: A comprehensive, modular WordPress connector for Odoo ERP. Supports CRM, Sales & Invoicing, and WooCommerce synchronization across multiple Odoo versions (14+).
 * Version: 1.9.2
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Paul ARGOUD
 * Author URI: https://paul.argoud.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp4odoo
 * Domain Path: /languages
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WP4ODOO_VERSION', '1.9.2' );
define( 'WP4ODOO_PLUGIN_FILE', __FILE__ );
define( 'WP4ODOO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP4ODOO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP4ODOO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP4ODOO_MIN_ODOO_VERSION', 14 );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'WP4Odoo\\';
    $base_dir = WP4ODOO_PLUGIN_DIR . 'includes/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, strlen( $prefix ) );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
});

/**
 * Main plugin class.
 */
final class WP4Odoo_Plugin {

    /**
     * Singleton instance.
     *
     * @var WP4Odoo_Plugin|null
     */
    private static ?WP4Odoo_Plugin $instance = null;

    /**
     * Module registry.
     *
     * @var WP4Odoo\Module_Registry
     */
    private WP4Odoo\Module_Registry $module_registry;

    /**
     * Get singleton instance.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Bootstrap: Dependency_Loader must be loaded directly (not via autoload).
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-dependency-loader.php';
        WP4Odoo\Dependency_Loader::load();

        $this->module_registry = new WP4Odoo\Module_Registry( $this );
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void {
        register_activation_hook( WP4ODOO_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( WP4ODOO_PLUGIN_FILE, [ $this, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );
        add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Cron for sync
        add_action( 'wp4odoo_scheduled_sync', [ $this, 'run_scheduled_sync' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_intervals' ] );

        // WooCommerce HPOS compatibility
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );
    }

    /**
     * Plugin activation.
     */
    public function activate(): void {
        WP4Odoo\Database_Migration::create_tables();
        WP4Odoo\Database_Migration::set_default_options();

        if ( ! wp_next_scheduled( 'wp4odoo_scheduled_sync' ) ) {
            wp_schedule_event( time(), 'wp4odoo_five_minutes', 'wp4odoo_scheduled_sync' );
        }

        // Flag for post-activation redirect.
        set_transient( 'wp4odoo_activated', '1', 60 );

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void {
        wp_clear_scheduled_hook( 'wp4odoo_scheduled_sync' );
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin.
     */
    public function init(): void {
        load_plugin_textdomain( 'wp4odoo', false, dirname( WP4ODOO_PLUGIN_BASENAME ) . '/languages' );

        $this->module_registry->register_all();

        do_action( 'wp4odoo_init', $this );
    }

    /**
     * After all plugins loaded.
     */
    public function on_plugins_loaded(): void {
        if ( is_admin() ) {
            new WP4Odoo\Admin\Admin();
        }

        do_action( 'wp4odoo_loaded' );
    }

    // ─── Module Delegates ───────────────────────────────────

    /**
     * Register a module.
     *
     * @param string              $id     Module identifier.
     * @param WP4Odoo\Module_Base $module Module instance.
     */
    public function register_module( string $id, WP4Odoo\Module_Base $module ): void {
        $this->module_registry->register( $id, $module );
    }

    /**
     * Get a registered module.
     *
     * @param string $id Module identifier.
     * @return WP4Odoo\Module_Base|null
     */
    public function get_module( string $id ): ?WP4Odoo\Module_Base {
        return $this->module_registry->get( $id );
    }

    /**
     * Get all registered modules.
     *
     * @return array
     */
    public function get_modules(): array {
        return $this->module_registry->all();
    }

    // ─── Infrastructure ─────────────────────────────────────

    /**
     * Get the Odoo API client.
     *
     * @return WP4Odoo\API\Odoo_Client
     */
    public function client(): WP4Odoo\API\Odoo_Client {
        static $client = null;
        if ( null === $client ) {
            $client = new WP4Odoo\API\Odoo_Client();
        }
        return $client;
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void {
        $webhook_handler = new WP4Odoo\Webhook_Handler();
        $webhook_handler->register_routes();
    }

    /**
     * Run scheduled synchronization.
     */
    public function run_scheduled_sync(): void {
        $sync = new WP4Odoo\Sync_Engine();
        $sync->process_queue();
    }

    /**
     * Add custom cron intervals.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_intervals( array $schedules ): array {
        $schedules['wp4odoo_five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'wp4odoo' ),
        ];
        $schedules['wp4odoo_fifteen_minutes'] = [
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'wp4odoo' ),
        ];
        return $schedules;
    }

    /**
     * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage).
     *
     * @return void
     */
    public function declare_hpos_compatibility(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WP4ODOO_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton.' );
    }
}

/**
 * Get the main plugin instance.
 *
 * @return WP4Odoo_Plugin
 */
function wp4odoo(): WP4Odoo_Plugin {
    return WP4Odoo_Plugin::instance();
}

// Initialize
wp4odoo();

// WP-CLI commands (only loaded in CLI context).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cli.php';
    \WP_CLI::add_command( 'wp4odoo', WP4Odoo\CLI::class );
}
