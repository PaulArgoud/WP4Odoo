<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paid Memberships Pro Module — push memberships to Odoo accounting.
 *
 * Syncs PMPro membership levels as Odoo membership products (product.product),
 * payment orders as invoices (account.move), and user memberships as
 * membership lines (membership.membership_line). Push-only (WP -> Odoo).
 *
 * Each payment automatically creates an invoice in Odoo,
 * eliminating manual recurring accounting entries.
 *
 * PMPro stores data in custom DB tables (not CPTs):
 * - pmpro_membership_levels — level definitions
 * - pmpro_membership_orders — payment orders
 * - pmpro_memberships_users — user membership records
 *
 * Requires the Paid Memberships Pro plugin to be active. Mutually exclusive
 * with the MemberPress and WC Memberships modules (same Odoo models).
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class PMPro_Module extends Membership_Module_Base {

	use PMPro_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.10';
	protected const PLUGIN_TESTED_UP_TO = '3.5';

	protected int $exclusive_priority = 15;

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'level'      => 'product.product',
		'order'      => 'account.move',
		'membership' => 'membership.membership_line',
	];

	/**
	 * Default field mappings.
	 *
	 * Order mappings are identity (WP key = Odoo key) because
	 * load_order() returns pre-formatted Odoo data. Module_Base::map_to_odoo()
	 * only renames keys without type conversion, so invoice_line_ids tuples pass intact.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'level'      => [
			'level_name' => 'name',
			'list_price' => 'list_price',
			'membership' => 'membership',
			'type'       => 'type',
		],
		'order'      => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'membership' => [
			'partner_id'    => 'partner_id',
			'membership_id' => 'membership_id',
			'date_from'     => 'date_from',
			'date_to'       => 'date_to',
			'state'         => 'state',
			'member_price'  => 'member_price',
		],
	];

	/**
	 * PMPro data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var PMPro_Handler
	 */
	private PMPro_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'pmpro', 'Paid Memberships Pro', $client_provider, $entity_map, $settings );
		$this->handler = new PMPro_Handler( $this->logger );
	}

	/**
	 * Boot the module: register PMPro hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'PMPRO_VERSION' ) ) {
			$this->logger->warning( __( 'PMPro module enabled but Paid Memberships Pro is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_levels'] ) ) {
			add_action( 'pmpro_save_membership_level', $this->safe_callback( [ $this, 'on_level_saved' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'pmpro_added_order', $this->safe_callback( [ $this, 'on_order_created' ] ), 10, 1 );
			add_action( 'pmpro_updated_order', $this->safe_callback( [ $this, 'on_order_updated' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'pmpro_after_change_membership_level', $this->safe_callback( [ $this, 'on_membership_changed' ] ), 10, 3 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_levels'        => true,
			'sync_orders'        => true,
			'sync_memberships'   => true,
			'auto_post_invoices' => true,
		];
	}

	/**
	 * Third-party tables accessed directly via $wpdb.
	 *
	 * @return array<int, string>
	 */
	protected function get_required_tables(): array {
		return [
			'pmpro_memberships_users',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_levels'        => [
				'label'       => __( 'Sync membership levels', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push PMPro levels to Odoo as membership products.', 'wp4odoo' ),
			],
			'sync_orders'        => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed payment orders to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_memberships'   => [
				'label'       => __( 'Sync memberships', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push user membership status to Odoo membership lines.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed orders.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Paid Memberships Pro.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'PMPRO_VERSION' ), 'Paid Memberships Pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'PMPRO_VERSION' ) ? PMPRO_VERSION : '';
	}

	// ─── Membership_Module_Base abstract implementations ───

	/**
	 * @inheritDoc
	 */
	protected function get_level_entity_type(): string {
		return 'level';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_payment_entity_type(): string {
		return 'order';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_membership_entity_type(): string {
		return 'membership';
	}

	/**
	 * @inheritDoc
	 */
	protected function handler_load_level( int $wp_id ): array {
		return $this->handler->load_level( $wp_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function handler_load_payment( int $wp_id, int $partner_id, int $level_odoo_id ): array {
		return $this->handler->load_order( $wp_id, $partner_id, $level_odoo_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function handler_load_membership( int $wp_id ): array {
		return $this->handler->load_membership( $wp_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_payment_user_and_level( int $wp_id ): array {
		$order = new \MemberOrder( $wp_id );
		if ( ! $order->id ) {
			return [ 0, 0 ];
		}
		return [ (int) $order->user_id, (int) $order->membership_id ];
	}

	/**
	 * @inheritDoc
	 */
	protected function get_level_id_for_entity( int $wp_id, string $entity_type ): int {
		if ( 'order' === $entity_type ) {
			return $this->handler->get_level_id_for_order( $wp_id );
		}

		if ( 'membership' === $entity_type ) {
			return $this->handler->get_level_id_for_membership( $wp_id );
		}

		return 0;
	}

	/**
	 * @inheritDoc
	 */
	protected function is_payment_complete( int $wp_id ): bool {
		$order = new \MemberOrder( $wp_id );
		return 'success' === $order->status;
	}

	/**
	 * @inheritDoc
	 */
	protected function resolve_member_price( int $level_id ): float {
		$level = pmpro_getLevel( $level_id );
		if ( ! $level ) {
			return 0.0;
		}
		$billing = (float) $level->billing_amount;
		return $billing > 0 ? $billing : (float) $level->initial_payment;
	}
}
