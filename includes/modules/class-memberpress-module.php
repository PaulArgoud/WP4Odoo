<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberPress Module — push recurring subscriptions to Odoo accounting.
 *
 * Syncs MemberPress plans as Odoo membership products (product.product),
 * transactions as invoices (account.move), and subscriptions as
 * membership lines (membership.membership_line). Push-only (WP -> Odoo).
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
class MemberPress_Module extends Membership_Module_Base {

	use MemberPress_Hooks;

	protected int $exclusive_priority = 10;

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
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'memberpress', 'MemberPress', $client_provider, $entity_map, $settings );
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
		return $this->check_dependency( defined( 'MEPR_VERSION' ), 'MemberPress' );
	}

	// ─── Membership_Module_Base abstract implementations ───

	/**
	 * @inheritDoc
	 */
	protected function get_level_entity_type(): string {
		return 'plan';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_payment_entity_type(): string {
		return 'transaction';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_membership_entity_type(): string {
		return 'subscription';
	}

	/**
	 * @inheritDoc
	 */
	protected function handler_load_level( int $wp_id ): array {
		return $this->handler->load_plan( $wp_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function handler_load_payment( int $wp_id, int $partner_id, int $level_odoo_id ): array {
		return $this->handler->load_transaction( $wp_id, $partner_id, $level_odoo_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function handler_load_membership( int $wp_id ): array {
		return $this->handler->load_subscription( $wp_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_payment_user_and_level( int $wp_id ): array {
		$txn = new \MeprTransaction( $wp_id );
		if ( ! $txn->id ) {
			return [ 0, 0 ];
		}
		return [ (int) $txn->user_id, (int) $txn->product_id ];
	}

	/**
	 * @inheritDoc
	 */
	protected function get_level_id_for_entity( int $wp_id, string $entity_type ): int {
		if ( 'transaction' === $entity_type ) {
			$txn = new \MeprTransaction( $wp_id );
			return (int) $txn->product_id;
		}

		if ( 'subscription' === $entity_type ) {
			$sub = new \MeprSubscription( $wp_id );
			return (int) $sub->product_id;
		}

		return 0;
	}

	/**
	 * @inheritDoc
	 */
	protected function is_payment_complete( int $wp_id ): bool {
		$txn = new \MeprTransaction( $wp_id );
		return 'complete' === $txn->status;
	}

	/**
	 * @inheritDoc
	 */
	protected function resolve_member_price( int $level_id ): float {
		$product = new \MeprProduct( $level_id );
		$price   = $product->get_price();
		return $price ? (float) $price : 0.0;
	}
}
