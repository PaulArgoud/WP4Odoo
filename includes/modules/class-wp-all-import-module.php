<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP All Import Module — intercepts CSV/XML imports for Odoo sync.
 *
 * Meta-module (no own entity types): hooks into WP All Import's
 * proprietary `pmxi_saved_post` event and routes imported records
 * to the correct module's sync queue. This ensures Odoo sync even
 * when WP All Import's "speed optimization" disables standard
 * WordPress hooks.
 *
 * The queue's dedup mechanism (SELECT…FOR UPDATE) safely handles
 * double-enqueue if standard hooks also fire.
 *
 * Requires WP All Import to be active.
 * No exclusive group — coexists with all modules.
 *
 * @package WP4Odoo
 * @since   3.1.0
 */
class WP_All_Import_Module extends \WP4Odoo\Module_Base {

	use WPAI_Hooks;

	protected const PLUGIN_MIN_VERSION  = '4.0';
	protected const PLUGIN_TESTED_UP_TO = '4.9';

	/**
	 * Odoo models: empty — meta-module, no own entity types.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [];

	/**
	 * Default field mappings: empty — meta-module.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [];

	/**
	 * Static routing table: post_type => [module_id, entity_type].
	 *
	 * Maps WordPress post types to the WP4Odoo module and entity type
	 * that handles sync for that CPT. Only post types with known
	 * WP4Odoo modules are listed.
	 *
	 * For `product`: routes to WooCommerce only. Specialized modules
	 * (WC Subscriptions, WC Bookings, WC Bundle BOM) filter by product
	 * type in their own hooks. Use the `wp4odoo_wpai_routing_table`
	 * filter to add custom routes.
	 *
	 * @var array<string, array{string, string}>
	 */
	private const ROUTING_TABLE = [
		'product'            => [ 'woocommerce', 'product' ],
		'download'           => [ 'edd', 'download' ],
		'wc_membership_plan' => [ 'memberships', 'plan' ],
		'memberpressproduct' => [ 'memberpress', 'plan' ],
		'simple-pay'         => [ 'simplepay', 'form' ],
		'give_forms'         => [ 'givewp', 'form' ],
		'campaign'           => [ 'charitable', 'campaign' ],
		'sfwd-courses'       => [ 'learndash', 'course' ],
		'groups'             => [ 'learndash', 'group' ],
		'llms_course'        => [ 'lifterlms', 'course' ],
		'llms_membership'    => [ 'lifterlms', 'membership' ],
		'tribe_events'       => [ 'events_calendar', 'event' ],
		'tribe_rsvp_tickets' => [ 'events_calendar', 'ticket' ],
		'sa_invoice'         => [ 'sprout_invoices', 'invoice' ],
		'sa_payment'         => [ 'sprout_invoices', 'payment' ],
		'wprm_recipe'        => [ 'wprm', 'recipe' ],
		'wps_products'       => [ 'shopwp', 'product' ],
		'job_listing'        => [ 'job_manager', 'job' ],
	];

	/**
	 * Per-import-run counters: [import_id => count].
	 *
	 * @var array<int, int>
	 */
	private static array $import_counts = [];

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wpai', 'WP All Import', $client_provider, $entity_map, $settings );
	}

	/**
	 * Boot the module: register WP All Import hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'PMXI_VERSION' ) && ! class_exists( 'PMXI_Plugin' ) ) {
			$this->logger->warning( __( 'WP All Import module enabled but WP All Import is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Sync direction: push only (intercepts WP imports, routes to push queue).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Get default settings.
	 *
	 * No custom settings — module toggle is handled by Module_Registry.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin' );
	}

	/**
	 * Get external dependency status for WP All Import.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			static::detect(),
			'WP All Import'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'PMXI_VERSION' ) ? PMXI_VERSION : '';
	}

	/**
	 * Get the routing table (with filter for extensibility).
	 *
	 * @return array<string, array{string, string}>
	 */
	public function get_routing_table(): array {
		/**
		 * Filter the WP All Import post type routing table.
		 *
		 * Allows adding custom post type → module/entity routes.
		 * Each entry maps a WordPress post type to a [module_id, entity_type] pair.
		 *
		 * @since 3.1.0
		 *
		 * @param array<string, array{string, string}> $routes Post type routing table.
		 */
		return apply_filters( 'wp4odoo_wpai_routing_table', self::ROUTING_TABLE );
	}

	/**
	 * Check if a target module is enabled in settings.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function is_target_module_enabled( string $module_id ): bool {
		return $this->settings_repo->is_module_enabled( $module_id );
	}

	/**
	 * Get the entity map repository reference.
	 *
	 * Used by WPAI_Hooks trait for cross-module entity_map lookups.
	 *
	 * @return \WP4Odoo\Entity_Map_Repository
	 */
	public function get_entity_map_ref(): \WP4Odoo\Entity_Map_Repository {
		return $this->entity_map;
	}

	// ─── Import counters ────────────────────────────────────

	/**
	 * Increment the import counter for a given import ID.
	 *
	 * @param int $import_id Import ID.
	 * @return void
	 */
	public static function increment_import_count( int $import_id ): void {
		self::$import_counts[ $import_id ] = ( self::$import_counts[ $import_id ] ?? 0 ) + 1;
	}

	/**
	 * Get the import count for a given import ID.
	 *
	 * @param int $import_id Import ID.
	 * @return int
	 */
	public static function get_import_count( int $import_id ): int {
		return self::$import_counts[ $import_id ] ?? 0;
	}

	/**
	 * Reset import counts (for testing).
	 *
	 * @return void
	 */
	public static function reset_import_counts(): void {
		self::$import_counts = [];
	}

	// ─── Data access (unused — meta-module) ─────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * Not used — this meta-module has no entity types.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return [];
	}
}
