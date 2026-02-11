<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memberships Module — push WooCommerce Memberships to Odoo membership module.
 *
 * Syncs membership plans as Odoo products (membership: True) and user memberships
 * as membership.membership_line records. Push-only (WC → Odoo).
 *
 * Requires the WooCommerce Memberships plugin (SkyVerge/Woo) to be active.
 * Can coexist with the WooCommerce and CRM modules.
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class Memberships_Module extends Module_Base {

	use Membership_Hooks;

	protected string $id   = 'memberships';
	protected string $name = 'Memberships';

	/**
	 * Sync direction: Memberships module only pushes to Odoo.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
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
	 * Lazy Partner_Service instance.
	 *
	 * @var Partner_Service|null
	 */
	private ?Partner_Service $partner_service = null;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map ) {
		parent::__construct( $client_provider, $entity_map );
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
		add_action( 'wc_memberships_user_membership_created', [ $this, 'on_membership_created' ], 10, 2 );

		// Status changed.
		add_action( 'wc_memberships_user_membership_status_changed', [ $this, 'on_membership_status_changed' ], 10, 3 );

		// Membership saved (catch-all for meta changes).
		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'wc_memberships_user_membership_saved', [ $this, 'on_membership_saved' ], 10, 2 );
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
		];
	}

	/**
	 * Get external dependency status for WooCommerce Memberships.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		if ( ! function_exists( 'wc_memberships' ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'WooCommerce Memberships must be installed and activated to use this module.', 'wp4odoo' ),
					],
				],
			];
		}

		return [
			'available' => true,
			'notices'   => [],
		];
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
	 * @return bool True on success.
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): bool {
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

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$data['partner_id'] = $this->partner_service()->get_or_create(
					$user->user_email,
					[ 'name' => $user->display_name ],
					$user_id
				);
			}
		}

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

	/**
	 * Get or create the Partner_Service instance.
	 *
	 * @return Partner_Service
	 */
	private function partner_service(): Partner_Service {
		if ( null === $this->partner_service ) {
			$this->partner_service = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		}

		return $this->partner_service;
	}
}
