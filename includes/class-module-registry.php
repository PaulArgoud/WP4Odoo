<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages module registration, declarative mutual exclusivity, and lifecycle.
 *
 * Modules declare their own exclusive group and priority via properties.
 * The registry enforces that only one module per group is booted (highest
 * priority wins). All modules are registered for admin UI visibility.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
class Module_Registry {

	/**
	 * Registered modules.
	 *
	 * @var array<string, Module_Base>
	 */
	private array $modules = [];

	/**
	 * Module IDs that have been booted.
	 *
	 * @var string[]
	 */
	private array $booted = [];

	/**
	 * Version warnings collected during registration.
	 *
	 * Keyed by module ID, each value is an array of notice arrays.
	 *
	 * @var array<string, array<array{type: string, message: string}>>
	 */
	private array $version_warnings = [];

	/**
	 * Plugin instance (for third-party hook compatibility).
	 *
	 * @var \WP4Odoo_Plugin
	 */
	private \WP4Odoo_Plugin $plugin;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings;

	/**
	 * Constructor.
	 *
	 * @param \WP4Odoo_Plugin    $plugin   Plugin instance.
	 * @param Settings_Repository $settings Settings repository.
	 */
	public function __construct( \WP4Odoo_Plugin $plugin, Settings_Repository $settings ) {
		$this->plugin   = $plugin;
		$this->settings = $settings;
	}

	/**
	 * Register all built-in modules.
	 *
	 * Mutual exclusivity is driven by each module's $exclusive_group
	 * and $exclusive_priority properties. The registry simply registers
	 * every module whose external dependency is met.
	 *
	 * @return void
	 */
	public function register_all(): void {
		$client_provider = fn() => $this->plugin->client();
		$entity_map      = new Entity_Map_Repository();
		$settings        = $this->settings;

		// CRM — always available.
		$this->register( 'crm', new Modules\CRM_Module( $client_provider, $entity_map, $settings ) );

		// Declarative module registry: [ id, class, detection_callback ].
		// Modules with null detection are always registered.
		// phpcs:disable Squiz.PHP.CommentedOutCode.Found
		$module_defs = [
			// Commerce group (WC > EDD > Sales).
			[ 'woocommerce', Modules\WooCommerce_Module::class, fn() => class_exists( 'WooCommerce' ) ],
			[ 'edd', Modules\EDD_Module::class, fn() => class_exists( 'Easy_Digital_Downloads' ) ],
			[ 'sales', Modules\Sales_Module::class, null ],

			// Membership group.
			[ 'memberships', Modules\Memberships_Module::class, fn() => class_exists( 'WooCommerce' ) ],
			[ 'memberpress', Modules\MemberPress_Module::class, fn() => defined( 'MEPR_VERSION' ) ],
			[ 'pmpro', Modules\PMPro_Module::class, fn() => defined( 'PMPRO_VERSION' ) ],
			[ 'rcp', Modules\RCP_Module::class, fn() => function_exists( 'rcp_get_membership' ) ],

			// Independent modules.
			[ 'givewp', Modules\GiveWP_Module::class, fn() => defined( 'GIVE_VERSION' ) ],
			[ 'charitable', Modules\Charitable_Module::class, fn() => class_exists( 'Charitable' ) ],
			[ 'simplepay', Modules\SimplePay_Module::class, fn() => defined( 'SIMPLE_PAY_VERSION' ) ],
			[ 'wprm', Modules\WPRM_Module::class, fn() => defined( 'WPRM_VERSION' ) ],
			[ 'amelia', Modules\Amelia_Module::class, fn() => defined( 'AMELIA_VERSION' ) ],
			[ 'bookly', Modules\Bookly_Module::class, fn() => class_exists( 'Bookly\Lib\Plugin' ) ],
			[ 'learndash', Modules\LearnDash_Module::class, fn() => defined( 'LEARNDASH_VERSION' ) ],
			[ 'tutorlms', Modules\TutorLMS_Module::class, fn() => defined( 'TUTOR_VERSION' ) ],
			[ 'lifterlms', Modules\LifterLMS_Module::class, fn() => defined( 'LLMS_VERSION' ) ],
			[ 'wc_subscriptions', Modules\WC_Subscriptions_Module::class, fn() => class_exists( 'WC_Subscriptions' ) ],
			[ 'wc_points_rewards', Modules\WC_Points_Rewards_Module::class, fn() => class_exists( 'WC_Points_Rewards' ) ],
			[ 'wc_bookings', Modules\WC_Bookings_Module::class, fn() => class_exists( 'WC_Product_Booking' ) ],
			[ 'events_calendar', Modules\Events_Calendar_Module::class, fn() => class_exists( 'Tribe__Events__Main' ) ],
			[ 'job_manager', Modules\Job_Manager_Module::class, fn() => defined( 'JOB_MANAGER_VERSION' ) ],
			[ 'wc_bundle_bom', Modules\WC_Bundle_BOM_Module::class, fn() => class_exists( 'WC_Bundles' ) || class_exists( 'WC_Composite_Products' ) ],

			// Invoicing group.
			[ 'sprout_invoices', Modules\Sprout_Invoices_Module::class, fn() => class_exists( 'SI_Invoice' ) ],
			[ 'wp_invoice', Modules\WP_Invoice_Module::class, fn() => class_exists( 'WPI_Invoice' ) ],

			// E-commerce group (secondary).
			[ 'ecwid', Modules\Ecwid_Module::class, fn() => defined( 'ECWID_PLUGIN_DIR' ) ],
			[ 'shopwp', Modules\ShopWP_Module::class, fn() => defined( 'SHOPWP_PLUGIN_DIR' ) ],
			[ 'crowdfunding', Modules\Crowdfunding_Module::class, fn() => function_exists( 'wpneo_crowdfunding_init' ) ],

			// Helpdesk group.
			[ 'awesome_support', Modules\Awesome_Support_Module::class, fn() => defined( 'WPAS_VERSION' ) ],
			[ 'supportcandy', Modules\SupportCandy_Module::class, fn() => defined( 'WPSC_VERSION' ) ],

			// Affiliate.
			[ 'affiliatewp', Modules\AffiliateWP_Module::class, fn() => function_exists( 'affiliate_wp' ) ],

			// Marketing CRM.
			[ 'fluentcrm', Modules\FluentCRM_Module::class, fn() => defined( 'FLUENTCRM' ) ],

			// Funnel / Sales pipeline.
			[ 'funnelkit', Modules\FunnelKit_Module::class, fn() => defined( 'WFFN_VERSION' ) ],

			// Gamification / Loyalty.
			[ 'gamipress', Modules\GamiPress_Module::class, fn() => defined( 'GAMIPRESS_VERSION' ) ],

			// Community.
			[ 'buddyboss', Modules\BuddyBoss_Module::class, fn() => defined( 'BP_VERSION' ) ],

			// Meta-modules (enrich other modules, no own entity types).
			[ 'acf', Modules\ACF_Module::class, fn() => class_exists( 'ACF' ) || defined( 'ACF_MAJOR_VERSION' ) ],
			[ 'wpai', Modules\WP_All_Import_Module::class, fn() => defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin' ) ],
		];
		// phpcs:enable

		foreach ( $module_defs as [ $id, $class, $detect ] ) {
			if ( null === $detect || $detect() ) {
				$this->register( $id, new $class( $client_provider, $entity_map, $settings ) );
			}
		}

		// Forms module — aggregate detection (any of 7 form plugins).
		$forms_active = class_exists( 'GFAPI' )
			|| function_exists( 'wpforms' )
			|| defined( 'WPCF7_VERSION' )
			|| defined( 'FLUENTFORM' )
			|| class_exists( 'FrmAppHelper' )
			|| class_exists( 'Ninja_Forms' )
			|| defined( 'FORMINATOR_VERSION' );
		if ( $forms_active ) {
			$this->register( 'forms', new Modules\Forms_Module( $client_provider, $entity_map, $settings ) );
		}

		// Allow third-party modules (closures and shared entity map available as arguments).
		do_action( 'wp4odoo_register_modules', $this->plugin, $client_provider, $entity_map );
	}

	/**
	 * Register a single module. Boots it if enabled and no higher-priority
	 * module in the same exclusive group is already booted.
	 *
	 * @param string      $id     Module identifier.
	 * @param Module_Base $module Module instance.
	 * @return void
	 */
	public function register( string $id, Module_Base $module ): void {
		$this->modules[ $id ] = $module;

		if ( ! $this->settings->is_module_enabled( $id ) ) {
			return;
		}

		// Version / dependency check: prevents boot for missing or too-old plugins.
		$dep = $module->get_dependency_status();
		if ( ! $dep['available'] ) {
			return;
		}
		if ( ! empty( $dep['notices'] ) ) {
			$this->version_warnings[ $id ] = $dep['notices'];
		}

		// Exclusive group check: skip boot if a higher-priority module is already booted.
		$group = $module->get_exclusive_group();
		if ( '' !== $group && $this->has_booted_in_group( $group, $module->get_exclusive_priority() ) ) {
			return;
		}

		$module->boot();
		$this->booted[] = $id;
	}

	/**
	 * Get a registered module.
	 *
	 * @param string $id Module identifier.
	 * @return Module_Base|null
	 */
	public function get( string $id ): ?Module_Base {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return array<string, Module_Base>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Get the number of booted modules.
	 *
	 * @return int
	 */
	public function get_booted_count(): int {
		return count( $this->booted );
	}

	/**
	 * Get version warnings collected during module registration.
	 *
	 * @return array<string, array<array{type: string, message: string}>>
	 */
	public function get_version_warnings(): array {
		return $this->version_warnings;
	}

	/**
	 * Get the currently booted module ID for a given exclusive group.
	 *
	 * @param string $group Exclusive group name.
	 * @return string|null Module ID, or null if none booted in this group.
	 */
	public function get_active_in_group( string $group ): ?string {
		foreach ( $this->booted as $booted_id ) {
			$module = $this->modules[ $booted_id ];
			if ( $module->get_exclusive_group() === $group ) {
				return $booted_id;
			}
		}
		return null;
	}

	/**
	 * Get all enabled module IDs that conflict with a given module.
	 *
	 * Returns enabled modules in the same exclusive group (excluding the module itself).
	 *
	 * @param string $module_id Module identifier.
	 * @return string[] Conflicting module IDs.
	 */
	public function get_conflicts( string $module_id ): array {
		$module = $this->modules[ $module_id ] ?? null;
		if ( ! $module ) {
			return [];
		}

		$group = $module->get_exclusive_group();
		if ( '' === $group ) {
			return [];
		}

		$conflicts = [];
		foreach ( $this->modules as $id => $other ) {
			if ( $id === $module_id ) {
				continue;
			}
			if ( $other->get_exclusive_group() === $group
				&& $this->settings->is_module_enabled( $id ) ) {
				$conflicts[] = $id;
			}
		}

		return $conflicts;
	}

	/**
	 * Check if any module in the given exclusive group is already booted
	 * with equal or higher priority.
	 *
	 * @param string $group    Exclusive group name.
	 * @param int    $priority Priority of the module being registered.
	 * @return bool True if a competing module is already booted.
	 */
	private function has_booted_in_group( string $group, int $priority ): bool {
		foreach ( $this->booted as $booted_id ) {
			$booted_module = $this->modules[ $booted_id ];
			if ( $booted_module->get_exclusive_group() === $group
				&& $booted_module->get_exclusive_priority() >= $priority ) {
				return true;
			}
		}
		return false;
	}
}
