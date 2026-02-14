<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memberships Module — sync WooCommerce Memberships with Odoo membership module.
 *
 * Syncs membership plans as Odoo products (membership: True) and user memberships
 * as membership.membership_line records. Bidirectional: plans ↔ Odoo, memberships
 * push + status/date updates from Odoo.
 *
 * Requires the WooCommerce Memberships plugin (SkyVerge/Woo) to be active.
 * Can coexist with the WooCommerce and CRM modules.
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class Memberships_Module extends Module_Base {

	use Membership_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.20';
	protected const PLUGIN_TESTED_UP_TO = '1.27';

	protected string $exclusive_group = 'memberships';
	protected int $exclusive_priority = 20;

	/**
	 * Sync direction: bidirectional (plans ↔, memberships ↔ status updates).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * Plans are pushed as Odoo product.product with membership: True.
	 * User memberships are pushed as membership.membership_line.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'plan'       => 'product.product',
		'membership' => 'membership.membership_line',
	];

	/**
	 * Default field mappings.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'plan'       => [
			'plan_name'  => 'name',
			'list_price' => 'list_price',
			'membership' => 'membership',
		],
		'membership' => [
			'partner_id'    => 'partner_id',
			'membership_id' => 'membership_id',
			'date_from'     => 'date_from',
			'date_to'       => 'date_to',
			'date_cancel'   => 'date_cancel',
			'state'         => 'state',
			'member_price'  => 'member_price',
		],
	];

	/**
	 * Membership data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Membership_Handler
	 */
	private Membership_Handler $membership_handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'memberships', 'WC Memberships', $client_provider, $entity_map, $settings );
		$this->membership_handler = new Membership_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WC Memberships hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'wc_memberships' ) ) {
			$this->logger->warning( __( 'Memberships module enabled but WooCommerce Memberships is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		// User membership created.
		add_action( 'wc_memberships_user_membership_created', $this->safe_callback( [ $this, 'on_membership_created' ] ), 10, 2 );

		// Status changed.
		add_action( 'wc_memberships_user_membership_status_changed', $this->safe_callback( [ $this, 'on_membership_status_changed' ] ), 10, 3 );

		// Membership saved (catch-all for meta changes).
		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'wc_memberships_user_membership_saved', $this->safe_callback( [ $this, 'on_membership_saved' ] ), 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_plans'       => true,
			'sync_memberships' => true,
			'pull_plans'       => true,
			'pull_memberships' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_plans'       => [
				'label'       => __( 'Sync membership plans', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WooCommerce membership plans to Odoo.', 'wp4odoo' ),
			],
			'sync_memberships' => [
				'label'       => __( 'Sync user memberships', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push user memberships to Odoo membership lines.', 'wp4odoo' ),
			],
			'pull_plans'       => [
				'label'       => __( 'Pull membership plans', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo membership products into WooCommerce plans.', 'wp4odoo' ),
			],
			'pull_memberships' => [
				'label'       => __( 'Pull membership updates', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo membership line status/date updates into WooCommerce.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WooCommerce Memberships.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( function_exists( 'wc_memberships' ), 'WooCommerce Memberships' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WC_MEMBERSHIPS_VERSION' ) ? WC_MEMBERSHIPS_VERSION : '';
	}

	// ─── Pull override ──────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Plans are fully pulled. Memberships support status/date updates
	 * only — deletion is not supported (memberships originate in WC).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$settings = $this->get_settings();

		if ( 'plan' === $entity_type && empty( $settings['pull_plans'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'membership' === $entity_type ) {
			if ( empty( $settings['pull_memberships'] ) ) {
				return \WP4Odoo\Sync_Result::success();
			}
			if ( 'delete' === $action ) {
				$this->logger->info( 'Membership deletion from Odoo not supported — memberships originate in WooCommerce.', [ 'odoo_id' => $odoo_id ] );
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'plan'       => $this->membership_handler->parse_plan_from_odoo( $odoo_data ),
			'membership' => $this->membership_handler->parse_membership_from_odoo( $odoo_data ),
			default      => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'plan'       => $this->membership_handler->save_plan( $data, $wp_id ),
			'membership' => $this->membership_handler->save_membership_from_odoo( $data, $wp_id ),
			default      => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Plans can be deleted. Memberships cannot (they originate in WC).
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress post ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'plan' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		return false;
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For membership lines, ensures the plan is synced first.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'membership' === $entity_type && 'delete' !== $action ) {
			$this->ensure_plan_synced( $wp_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * For memberships, resolves partner_id and membership_id to Odoo IDs.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		$data = match ( $entity_type ) {
			'plan'       => $this->membership_handler->load_plan( $wp_id ),
			'membership' => $this->load_membership_data( $wp_id ),
			default      => [],
		};

		return $data;
	}

	/**
	 * Load and resolve a user membership with Odoo references.
	 *
	 * @param int $membership_id WC user membership ID.
	 * @return array<string, mixed>
	 */
	private function load_membership_data( int $membership_id ): array {
		$data = $this->membership_handler->load_membership( $membership_id );

		if ( empty( $data ) ) {
			return [];
		}

		// Resolve WP user → Odoo partner.
		$user_id = $data['user_id'] ?? 0;
		unset( $data['user_id'] );

		$data['partner_id'] = $this->resolve_partner_from_user( $user_id );

		if ( empty( $data['partner_id'] ) ) {
			$this->logger->warning( 'Cannot resolve partner for membership.', [ 'membership_id' => $membership_id ] );
			return [];
		}

		// Resolve WC plan → Odoo product.product ID.
		$plan_id = $data['plan_id'] ?? 0;
		unset( $data['plan_id'] );

		if ( $plan_id > 0 ) {
			$data['membership_id'] = $this->get_mapping( 'plan', $plan_id );
		}

		if ( empty( $data['membership_id'] ) ) {
			$this->logger->warning( 'Cannot resolve Odoo product for membership plan.', [ 'plan_id' => $plan_id ] );
			return [];
		}

		// Resolve member_price from plan's product.
		$membership = wc_memberships_get_user_membership( $membership_id );
		if ( $membership ) {
			$product_id = $membership->get_product_id();
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$data['member_price'] = (float) $product->get_regular_price();
				}
			}
		}

		return $data;
	}

	// ─── Plan sync ──────────────────────────────────────────

	/**
	 * Ensure the membership plan is synced to Odoo before pushing a membership line.
	 *
	 * @param int $membership_id WC user membership ID.
	 * @return void
	 */
	private function ensure_plan_synced( int $membership_id ): void {
		$membership = wc_memberships_get_user_membership( $membership_id );
		if ( ! $membership ) {
			return;
		}

		$plan_id      = $membership->get_plan_id();
		$odoo_plan_id = $this->get_mapping( 'plan', $plan_id );

		if ( $odoo_plan_id ) {
			return;
		}

		// Plan not yet in Odoo — push it synchronously.
		$this->logger->info( 'Auto-pushing membership plan before membership line.', [ 'plan_id' => $plan_id ] );
		parent::push_to_odoo( 'plan', 'create', $plan_id );
	}
}
