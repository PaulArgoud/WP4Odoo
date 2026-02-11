<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberPress Module — push recurring subscriptions to Odoo accounting.
 *
 * Syncs MemberPress plans as Odoo membership products (product.product),
 * transactions as invoices (account.move), and subscriptions as
 * membership lines (membership.membership_line). Push-only (WP → Odoo).
 *
 * Each recurring payment automatically creates an invoice in Odoo,
 * eliminating manual recurring accounting entries.
 *
 * Requires the MemberPress plugin to be active. Mutually exclusive
 * with the WC Memberships module (same Odoo models).
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class MemberPress_Module extends Module_Base {

	use MemberPress_Hooks;

	/**
	 * Module identifier.
	 *
	 * @var string
	 */
	protected string $id = 'memberpress';

	/**
	 * Human-readable module name.
	 *
	 * @var string
	 */
	protected string $name = 'MemberPress';

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'plan'         => 'product.product',
		'transaction'  => 'account.move',
		'subscription' => 'membership.membership_line',
	];

	/**
	 * Default field mappings.
	 *
	 * Transaction mappings are identity (WP key = Odoo key) because
	 * load_transaction() returns pre-formatted Odoo data. Module_Base::map_to_odoo()
	 * only renames keys without type conversion, so invoice_line_ids tuples pass intact.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'plan'         => [
			'plan_name'  => 'name',
			'list_price' => 'list_price',
			'membership' => 'membership',
			'type'       => 'type',
		],
		'transaction'  => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'subscription' => [
			'partner_id'    => 'partner_id',
			'membership_id' => 'membership_id',
			'date_from'     => 'date_from',
			'date_to'       => 'date_to',
			'state'         => 'state',
			'member_price'  => 'member_price',
		],
	];

	/**
	 * MemberPress data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var MemberPress_Handler
	 */
	private MemberPress_Handler $handler;

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
		$this->handler = new MemberPress_Handler( $this->logger );
	}

	/**
	 * Boot the module: register MemberPress hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'MEPR_VERSION' ) ) {
			$this->logger->warning( __( 'MemberPress module enabled but MemberPress is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_plans'] ) ) {
			add_action( 'save_post_memberpressproduct', [ $this, 'on_plan_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_transactions'] ) ) {
			add_action( 'mepr-txn-store', [ $this, 'on_transaction_store' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_subscriptions'] ) ) {
			add_action( 'mepr_subscription_transition_status', [ $this, 'on_subscription_status_change' ], 10, 3 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_plans'         => true,
			'sync_transactions'  => true,
			'sync_subscriptions' => true,
			'auto_post_invoices' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_plans'         => [
				'label'       => __( 'Sync membership plans', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push MemberPress plans to Odoo as membership products.', 'wp4odoo' ),
			],
			'sync_transactions'  => [
				'label'       => __( 'Sync transactions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed transactions to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_subscriptions' => [
				'label'       => __( 'Sync subscriptions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push subscription status to Odoo membership lines.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed transactions.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for MemberPress.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		if ( ! defined( 'MEPR_VERSION' ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'MemberPress must be installed and activated to use this module.', 'wp4odoo' ),
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
	 * Ensures the plan is synced before transactions and subscriptions.
	 * Auto-posts invoices for completed transactions.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return bool True on success.
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): bool {
		if ( in_array( $entity_type, [ 'transaction', 'subscription' ], true ) && 'delete' !== $action ) {
			$this->ensure_plan_synced( $wp_id, $entity_type );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result && 'transaction' === $entity_type && 'create' === $action ) {
			$this->maybe_auto_post_invoice( $wp_id );
		}

		return $result;
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'plan'         => $this->handler->load_plan( $wp_id ),
			'transaction'  => $this->load_transaction_data( $wp_id ),
			'subscription' => $this->load_subscription_data( $wp_id ),
			default        => [],
		};
	}

	/**
	 * Load and resolve a transaction with Odoo references.
	 *
	 * Resolves user → partner and product → plan Odoo ID.
	 *
	 * @param int $txn_id MemberPress transaction ID.
	 * @return array<string, mixed>
	 */
	private function load_transaction_data( int $txn_id ): array {
		$txn = new \MeprTransaction( $txn_id );
		if ( ! $txn->id ) {
			return [];
		}

		// Resolve user → partner.
		$user_id = (int) $txn->user_id;
		if ( $user_id <= 0 ) {
			$this->logger->warning( 'Transaction has no user.', [ 'txn_id' => $txn_id ] );
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find user for transaction.',
				[
					'txn_id'  => $txn_id,
					'user_id' => $user_id,
				]
			);
			return [];
		}

		$partner_id = $this->partner_service()->get_or_create(
			$user->user_email,
			[ 'name' => $user->display_name ],
			$user_id
		);

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for transaction.', [ 'txn_id' => $txn_id ] );
			return [];
		}

		// Resolve product → plan Odoo ID.
		$product_id   = (int) $txn->product_id;
		$plan_odoo_id = 0;
		if ( $product_id > 0 ) {
			$plan_odoo_id = $this->get_mapping( 'plan', $product_id ) ?? 0;
		}

		if ( ! $plan_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for transaction plan.', [ 'product_id' => $product_id ] );
			return [];
		}

		return $this->handler->load_transaction( $txn_id, $partner_id, $plan_odoo_id );
	}

	/**
	 * Load and resolve a subscription with Odoo references.
	 *
	 * Same resolution pattern as Memberships_Module::load_membership_data().
	 *
	 * @param int $sub_id MemberPress subscription ID.
	 * @return array<string, mixed>
	 */
	private function load_subscription_data( int $sub_id ): array {
		$data = $this->handler->load_subscription( $sub_id );

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
			$this->logger->warning( 'Cannot resolve partner for subscription.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		// Resolve plan → Odoo product.product ID.
		$plan_id = $data['plan_id'] ?? 0;
		unset( $data['plan_id'] );

		if ( $plan_id > 0 ) {
			$data['membership_id'] = $this->get_mapping( 'plan', $plan_id );
		}

		if ( empty( $data['membership_id'] ) ) {
			$this->logger->warning( 'Cannot resolve Odoo product for subscription plan.', [ 'plan_id' => $plan_id ] );
			return [];
		}

		// Resolve member_price from plan.
		$product = new \MeprProduct( $plan_id );
		$price   = $product->get_price();
		if ( $price ) {
			$data['member_price'] = (float) $price;
		}

		return $data;
	}

	// ─── Plan sync ──────────────────────────────────────────

	/**
	 * Ensure the MemberPress plan is synced to Odoo before pushing a dependent entity.
	 *
	 * @param int    $wp_id       Entity ID (transaction or subscription).
	 * @param string $entity_type 'transaction' or 'subscription'.
	 * @return void
	 */
	private function ensure_plan_synced( int $wp_id, string $entity_type ): void {
		$product_id = 0;

		if ( 'transaction' === $entity_type ) {
			$txn        = new \MeprTransaction( $wp_id );
			$product_id = (int) $txn->product_id;
		} elseif ( 'subscription' === $entity_type ) {
			$sub        = new \MeprSubscription( $wp_id );
			$product_id = (int) $sub->product_id;
		}

		if ( $product_id <= 0 ) {
			return;
		}

		$odoo_plan_id = $this->get_mapping( 'plan', $product_id );
		if ( $odoo_plan_id ) {
			return;
		}

		// Plan not yet in Odoo — push it synchronously.
		$this->logger->info( 'Auto-pushing MemberPress plan before dependent entity.', [ 'product_id' => $product_id ] );
		parent::push_to_odoo( 'plan', 'create', $product_id );
	}

	// ─── Invoice auto-posting ───────────────────────────────

	/**
	 * Auto-post an invoice in Odoo for completed transactions.
	 *
	 * @param int $txn_id MemberPress transaction ID.
	 * @return void
	 */
	private function maybe_auto_post_invoice( int $txn_id ): void {
		$settings = $this->get_settings();
		if ( empty( $settings['auto_post_invoices'] ) ) {
			return;
		}

		$txn = new \MeprTransaction( $txn_id );
		if ( 'complete' !== $txn->status ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'transaction', $txn_id );
		if ( ! $odoo_id ) {
			return;
		}

		try {
			$this->client()->execute(
				'account.move',
				'action_post',
				[ [ $odoo_id ] ]
			);
			$this->logger->info(
				'Auto-posted invoice in Odoo.',
				[
					'txn_id'  => $txn_id,
					'odoo_id' => $odoo_id,
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Could not auto-post invoice.',
				[
					'txn_id'  => $txn_id,
					'odoo_id' => $odoo_id,
					'error'   => $e->getMessage(),
				]
			);
		}
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
