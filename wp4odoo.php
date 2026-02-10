<?php
/**
 * Plugin Name: WordPress For Odoo
 * Plugin URI: https://github.com/PaulArgoud/wordpress-for-odoo
 * Description: A comprehensive, modular WordPress connector for Odoo ERP. Supports CRM, Sales & Invoicing, and WooCommerce synchronization across multiple Odoo versions (17+).
 * Version: 1.4.0
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
define( 'WP4ODOO_VERSION', '1.4.0' );
define( 'WP4ODOO_PLUGIN_FILE', __FILE__ );
define( 'WP4ODOO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP4ODOO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP4ODOO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP4ODOO_MIN_ODOO_VERSION', 17 );

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
     * Plugin modules registry.
     *
     * @var array
     */
    private array $modules = [];

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
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Load required files.
     */
    private function load_dependencies(): void {
        // Core API
        require_once WP4ODOO_PLUGIN_DIR . 'includes/api/interface-transport.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-client.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-jsonrpc.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-xmlrpc.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-auth.php';

        // Core classes
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-logger.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-entity-map-repository.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-queue-repository.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-partner-service.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-base.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-engine.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-field-mapper.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-queue-manager.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-query-service.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        // Modules
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-lead-manager.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-refiner.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-crm-module.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-portal-manager.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-sales-module.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-variant-handler.php';
        require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-woocommerce-module.php';

        // Admin
        if ( is_admin() ) {
            require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-admin.php';
            require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-admin-ajax.php';
            require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
        }
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
        $this->create_tables();
        $this->set_default_options();

        if ( ! wp_next_scheduled( 'wp4odoo_scheduled_sync' ) ) {
            wp_schedule_event( time(), 'wp4odoo_five_minutes', 'wp4odoo_scheduled_sync' );
        }

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

        $this->register_modules();

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

    /**
     * Register built-in modules.
     */
    private function register_modules(): void {
        $this->register_module( 'crm', new WP4Odoo\Modules\CRM_Module() );

        // WooCommerce and Sales are mutually exclusive.
        // When WooCommerce is active and the woocommerce module is enabled,
        // Sales_Module is not loaded (WooCommerce_Module replaces it).
        $wc_active  = class_exists( 'WooCommerce' );
        $wc_enabled = get_option( 'wp4odoo_module_woocommerce_enabled', false );

        if ( $wc_active && $wc_enabled ) {
            $this->register_module( 'woocommerce', new WP4Odoo\Modules\WooCommerce_Module() );
        } else {
            $this->register_module( 'sales', new WP4Odoo\Modules\Sales_Module() );

            if ( $wc_active ) {
                // Register WC module (disabled) so it appears in admin UI.
                $this->register_module( 'woocommerce', new WP4Odoo\Modules\WooCommerce_Module() );
            }
        }

        // Allow third-party modules
        do_action( 'wp4odoo_register_modules', $this );
    }

    /**
     * Register a module.
     *
     * @param string                      $id     Module identifier.
     * @param WP4Odoo\Module_Base $module Module instance.
     */
    public function register_module( string $id, WP4Odoo\Module_Base $module ): void {
        $this->modules[ $id ] = $module;

        $enabled = get_option( 'wp4odoo_module_' . $id . '_enabled', false );
        if ( $enabled ) {
            $module->boot();
        }
    }

    /**
     * Get a registered module.
     *
     * @param string $id Module identifier.
     * @return WP4Odoo\Module_Base|null
     */
    public function get_module( string $id ): ?WP4Odoo\Module_Base {
        return $this->modules[ $id ] ?? null;
    }

    /**
     * Get all registered modules.
     *
     * @return array
     */
    public function get_modules(): array {
        return $this->modules;
    }

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
     * Create database tables.
     */
    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_sync_queue (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            module VARCHAR(50) NOT NULL,
            direction ENUM('wp_to_odoo','odoo_to_wp') NOT NULL,
            entity_type VARCHAR(100) NOT NULL,
            wp_id BIGINT(20) UNSIGNED DEFAULT NULL,
            odoo_id BIGINT(20) UNSIGNED DEFAULT NULL,
            action ENUM('create','update','delete') NOT NULL DEFAULT 'update',
            payload LONGTEXT DEFAULT NULL,
            priority TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
            status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
            error_message TEXT DEFAULT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            processed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_priority (status, priority, scheduled_at),
            KEY idx_module_entity (module, entity_type),
            KEY idx_wp_id (wp_id),
            KEY idx_odoo_id (odoo_id)
        ) $charset_collate;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_entity_map (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            module VARCHAR(50) NOT NULL,
            entity_type VARCHAR(100) NOT NULL,
            wp_id BIGINT(20) UNSIGNED NOT NULL,
            odoo_id BIGINT(20) UNSIGNED NOT NULL,
            odoo_model VARCHAR(100) NOT NULL,
            sync_hash VARCHAR(64) DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_unique_mapping (module, entity_type, wp_id, odoo_id),
            KEY idx_wp_lookup (entity_type, wp_id),
            KEY idx_odoo_lookup (odoo_model, odoo_id)
        ) $charset_collate;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_logs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
            module VARCHAR(50) DEFAULT NULL,
            message TEXT NOT NULL,
            context LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_level_date (level, created_at),
            KEY idx_module (module)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wp4odoo_db_version', WP4ODOO_VERSION );
    }

    /**
     * Set default plugin options.
     */
    private function set_default_options(): void {
        $defaults = [
            'wp4odoo_connection' => [
                'url'      => '',
                'database' => '',
                'username' => '',
                'api_key'  => '',
                'protocol' => 'jsonrpc', // jsonrpc or xmlrpc
                'timeout'  => 30,
            ],
            'wp4odoo_sync_settings' => [
                'direction'      => 'bidirectional', // wp_to_odoo, odoo_to_wp, bidirectional
                'conflict_rule'  => 'newest_wins',   // newest_wins, odoo_wins, wp_wins
                'batch_size'     => 50,
                'sync_interval'  => 'wp4odoo_five_minutes',
                'auto_sync'      => false,
            ],
            'wp4odoo_log_settings' => [
                'enabled'        => true,
                'level'          => 'info',
                'retention_days' => 30,
            ],
            'wp4odoo_module_crm_enabled'         => false,
            'wp4odoo_module_sales_enabled'        => false,
            'wp4odoo_module_woocommerce_enabled'  => false,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                update_option( $key, $value );
            }
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
