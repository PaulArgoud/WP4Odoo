<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages module registration, declarative mutual exclusivity, and lifecycle.
 *
 * Modules declare their own exclusive group via properties. The registry
 * enforces that only one module per group is booted — the first module
 * registered in a group wins. Registration order (driven by the declarative
 * list in register_all()) determines precedence. All modules are registered
 * for admin UI visibility regardless of boot status.
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
	 * Deferred modules: detected but not yet instantiated.
	 *
	 * Modules that pass detection but are disabled are stored here to
	 * avoid unnecessary class loading. Materialized on demand via get()
	 * or all().
	 *
	 * @since 3.6.0
	 * @var array<string, class-string<Module_Base>>
	 */
	private array $deferred = [];

	/**
	 * Module IDs that failed to materialize.
	 *
	 * Prevents repeated instantiation attempts for modules whose
	 * constructor throws. Checked by get() to return null immediately.
	 *
	 * @since 3.8.0
	 * @var array<string, true>
	 */
	private array $failed = [];

	/**
	 * Client provider closure for deferred instantiation.
	 *
	 * @since 3.6.0
	 * @var \Closure|null
	 */
	private ?\Closure $client_provider = null;

	/**
	 * Entity map repository for deferred instantiation.
	 *
	 * @since 3.6.0
	 * @var Entity_Map_Repository|null
	 */
	private ?Entity_Map_Repository $entity_map = null;

	/**
	 * Client provider closure.
	 *
	 * Returns the shared Odoo_Client instance. Decouples the registry
	 * from the plugin singleton.
	 *
	 * @since 3.8.0
	 * @var \Closure(): \WP4Odoo\API\Odoo_Client
	 */
	private \Closure $client_factory;

	/**
	 * Plugin instance (passed to third-party hook for backward compatibility).
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
	 * @param \WP4Odoo_Plugin    $plugin         Plugin instance (for third-party hook compatibility).
	 * @param Settings_Repository $settings       Settings repository.
	 * @param \Closure|null       $client_factory Returns the Odoo_Client. Defaults to $plugin->client().
	 */
	public function __construct( \WP4Odoo_Plugin $plugin, Settings_Repository $settings, ?\Closure $client_factory = null ) {
		$this->plugin         = $plugin;
		$this->settings       = $settings;
		$this->client_factory = $client_factory ?? fn() => $plugin->client();
	}

	/**
	 * Register all built-in modules.
	 *
	 * Mutual exclusivity is driven by each module's $exclusive_group
	 * property. The first module registered in a group wins — subsequent
	 * modules in the same group are blocked. Registration order in this
	 * list determines precedence.
	 *
	 * @return void
	 */
	public function register_all(): void {
		$client_provider = $this->client_factory;
		$entity_map      = new Entity_Map_Repository();
		$settings        = $this->settings;

		$this->client_provider = $client_provider;
		$this->entity_map      = $entity_map;

		// CRM — always available.
		$this->register( 'crm', new Modules\CRM_Module( $client_provider, $entity_map, $settings ) );

		// Declarative module registry: [ id, class, detection_callback ].
		// Modules with null detection are always registered.
		// phpcs:disable Squiz.PHP.CommentedOutCode.Found
		$module_defs = [
			// Commerce group (WC > EDD > Sales).
			[ 'woocommerce', Modules\WooCommerce_Module::class ],
			[ 'edd', Modules\EDD_Module::class ],
			[ 'sales', Modules\Sales_Module::class ],

			// Membership group.
			[ 'memberships', Modules\Memberships_Module::class ],
			[ 'memberpress', Modules\MemberPress_Module::class ],
			[ 'pmpro', Modules\PMPro_Module::class ],
			[ 'rcp', Modules\RCP_Module::class ],

			// Independent modules.
			[ 'givewp', Modules\GiveWP_Module::class ],
			[ 'charitable', Modules\Charitable_Module::class ],
			[ 'simplepay', Modules\SimplePay_Module::class ],
			[ 'wprm', Modules\WPRM_Module::class ],
			[ 'amelia', Modules\Amelia_Module::class ],
			[ 'bookly', Modules\Bookly_Module::class ],
			[ 'fluent_booking', Modules\Fluent_Booking_Module::class ],
			[ 'jet_appointments', Modules\Jet_Appointments_Module::class ],
			[ 'jet_booking', Modules\Jet_Booking_Module::class ],
			[ 'project_manager', Modules\Project_Manager_Module::class ],
			[ 'learndash', Modules\LearnDash_Module::class ],
			[ 'tutorlms', Modules\TutorLMS_Module::class ],
			[ 'lifterlms', Modules\LifterLMS_Module::class ],
			[ 'wc_subscriptions', Modules\WC_Subscriptions_Module::class ],
			[ 'wc_b2b', Modules\WC_B2B_Module::class ],
			[ 'wc_points_rewards', Modules\WC_Points_Rewards_Module::class ],
			[ 'wc_bookings', Modules\WC_Bookings_Module::class ],
			[ 'events_calendar', Modules\Events_Calendar_Module::class ],
			[ 'mec', Modules\MEC_Module::class ],
			[ 'fooevents', Modules\FooEvents_Module::class ],
			[ 'job_manager', Modules\Job_Manager_Module::class ],
			[ 'wc_bundle_bom', Modules\WC_Bundle_BOM_Module::class ],
			[ 'wc_addons', Modules\WC_Addons_Module::class ],
			[ 'jeero_configurator', Modules\Jeero_Configurator_Module::class ],

			// WooCommerce extensions — advanced stock/shipping/returns.
			[ 'wc_inventory', Modules\WC_Inventory_Module::class ],
			[ 'wc_shipping', Modules\WC_Shipping_Module::class ],
			[ 'wc_returns', Modules\WC_Returns_Module::class ],
			[ 'wc_rental', Modules\WC_Rental_Module::class ],

			// Invoicing group.
			[ 'sprout_invoices', Modules\Sprout_Invoices_Module::class ],
			[ 'wp_invoice', Modules\WP_Invoice_Module::class ],

			// E-commerce group (secondary).
			[ 'surecart', Modules\SureCart_Module::class ],
			[ 'ecwid', Modules\Ecwid_Module::class ],
			[ 'shopwp', Modules\ShopWP_Module::class ],
			[ 'crowdfunding', Modules\Crowdfunding_Module::class ],

			// Marketplace group.
			[ 'dokan', Modules\Dokan_Module::class ],
			[ 'wcfm', Modules\WCFM_Module::class ],
			[ 'wc_vendors', Modules\WC_Vendors_Module::class ],

			// Helpdesk group.
			[ 'awesome_support', Modules\Awesome_Support_Module::class ],
			[ 'supportcandy', Modules\SupportCandy_Module::class ],
			[ 'fluent_support', Modules\Fluent_Support_Module::class ],

			// Affiliate.
			[ 'affiliatewp', Modules\AffiliateWP_Module::class ],

			// Marketing CRM.
			[ 'fluentcrm', Modules\FluentCRM_Module::class ],
			[ 'mailpoet', Modules\MailPoet_Module::class ],
			[ 'mc4wp', Modules\MC4WP_Module::class ],

			// Funnel / Sales pipeline.
			[ 'funnelkit', Modules\FunnelKit_Module::class ],

			// Gamification / Loyalty.
			[ 'gamipress', Modules\GamiPress_Module::class ],
			[ 'mycred', Modules\MyCRED_Module::class ],

			// Community.
			[ 'buddyboss', Modules\BuddyBoss_Module::class ],
			[ 'ultimate_member', Modules\Ultimate_Member_Module::class ],

			// HR.
			[ 'wperp', Modules\WPERP_Module::class ],

			// CRM (WP ERP) — separate from the core CRM module.
			[ 'wperp_crm', Modules\WPERP_CRM_Module::class ],

			// Accounting (WP ERP).
			[ 'wperp_accounting', Modules\WPERP_Accounting_Module::class ],

			// LMS.
			[ 'learnpress', Modules\LearnPress_Module::class ],
			[ 'sensei', Modules\Sensei_Module::class ],

			// Field Service — always registered; detection is Odoo-side (Enterprise).
			[ 'field_service', Modules\Field_Service_Module::class ],

			// Knowledge — always registered; detection is Odoo-side.
			[ 'knowledge', Modules\Knowledge_Module::class ],

			// Documents — WP Document Revisions / WP Download Manager.
			[ 'documents', Modules\Documents_Module::class ],

			// Generic CPT mapping.
			[ 'jetengine', Modules\JetEngine_Module::class ],

			// Meta-modules (enrich other modules, no own entity types).
			[ 'acf', Modules\ACF_Module::class ],
			[ 'jetengine_meta', Modules\JetEngine_Meta_Module::class ],
			[ 'wpai', Modules\WP_All_Import_Module::class ],

			// Aggregate modules — compound detection (any of N plugins).
			[ 'food_ordering', Modules\Food_Ordering_Module::class ],
			[ 'survey_quiz', Modules\Survey_Quiz_Module::class ],
			[ 'forms', Modules\Forms_Module::class ],
		];
		// phpcs:enable

		foreach ( $module_defs as [ $id, $class ] ) {
			if ( $class::detect() ) {
				if ( $this->settings->is_module_enabled( $id ) ) {
					$this->register( $id, new $class( $client_provider, $entity_map, $settings ) );
				} else {
					$this->deferred[ $id ] = $class;
				}
			}
		}

		// Allow third-party modules (closures and shared entity map available as arguments).
		do_action( 'wp4odoo_register_modules', $this->plugin, $client_provider, $entity_map );
	}

	/**
	 * Register a single module. Boots it if enabled and no other module
	 * in the same exclusive group is already booted.
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

		// Required modules check: skip boot if a required module is not booted.
		$required = $module->get_required_modules();
		if ( ! empty( $required ) ) {
			$missing = array_diff( $required, $this->booted );
			if ( ! empty( $missing ) ) {
				$this->version_warnings[ $id ][] = [
					'type'    => 'warning',
					'message' => sprintf(
						/* translators: 1: module name, 2: comma-separated list of required module IDs */
						__( '%1$s requires the following modules to be active: %2$s.', 'wp4odoo' ),
						$module->get_name(),
						implode( ', ', $missing )
					),
				];
				return;
			}
		}

		// Exclusive group check: skip boot if any module in the group is already booted.
		$group = $module->get_exclusive_group();
		if ( '' !== $group && $this->has_booted_in_group( $group ) ) {
			$active_id                       = $this->get_active_in_group( $group );
			$this->version_warnings[ $id ][] = [
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: 1: blocked module name, 2: active module ID, 3: exclusive group name */
					__( '%1$s was not started because "%2$s" is already active in the "%3$s" group. Only one module per group can run at a time.', 'wp4odoo' ),
					$module->get_name(),
					$active_id,
					$group
				),
			];
			return;
		}

		// Emit deprecation warning (module still boots for backward compatibility).
		if ( $module->is_deprecated() ) {
			$this->version_warnings[ $id ][] = [
				'type'    => 'warning',
				'message' => $module->get_deprecated_notice(),
			];
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
		if ( isset( $this->modules[ $id ] ) ) {
			return $this->modules[ $id ];
		}
		if ( isset( $this->deferred[ $id ] ) && ! isset( $this->failed[ $id ] ) ) {
			$this->materialize( $id );
			return $this->modules[ $id ] ?? null;
		}
		return null;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return array<string, Module_Base>
	 */
	public function all(): array {
		$this->materialize_all();
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
		$this->materialize_all();
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
	 * Instantiate a single deferred module.
	 *
	 * @since 3.6.0
	 *
	 * @param string $id Module identifier.
	 * @return void
	 */
	private function materialize( string $id ): void {
		if ( null === $this->client_provider || null === $this->entity_map ) {
			return; // @codeCoverageIgnore
		}

		$class = $this->deferred[ $id ];
		unset( $this->deferred[ $id ] );

		try {
			$this->modules[ $id ] = new $class( $this->client_provider, $this->entity_map, $this->settings );
		} catch ( \Throwable $e ) {
			// Track the failure so get() returns null immediately
			// without attempting re-instantiation.
			$this->failed[ $id ] = true;

			$this->version_warnings[ $id ][] = [
				'type'    => 'error',
				'message' => sprintf(
					/* translators: 1: module class name, 2: error message */
					__( 'Module failed to load (%1$s): %2$s', 'wp4odoo' ),
					$class,
					$e->getMessage()
				),
			];

			$logger = Logger::for_channel( 'core' );
			$logger->error(
				'Failed to instantiate deferred module.',
				[
					'module' => $id,
					'class'  => $class,
					'error'  => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Instantiate all deferred modules.
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	private function materialize_all(): void {
		foreach ( array_keys( $this->deferred ) as $id ) {
			$this->materialize( $id );
		}
	}

	/**
	 * Check if any module in the given exclusive group is already booted.
	 *
	 * Only one module per exclusive group may boot. The first module to boot
	 * in a group wins — subsequent modules in the same group are blocked
	 * regardless of their priority. Registration order (driven by the
	 * declarative list in register_all()) determines which module boots first.
	 *
	 * @param string $group Exclusive group name.
	 * @return bool True if any module in this group is already booted.
	 */
	private function has_booted_in_group( string $group ): bool {
		foreach ( $this->booted as $booted_id ) {
			$booted_module = $this->modules[ $booted_id ];
			if ( $booted_module->get_exclusive_group() === $group ) {
				return true;
			}
		}
		return false;
	}
}
