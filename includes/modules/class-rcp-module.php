<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restrict Content Pro Module — push memberships to Odoo accounting.
 *
 * Syncs RCP membership levels as Odoo membership products (product.product),
 * payments as invoices (account.move), and user memberships as
 * membership lines (membership.membership_line). Push-only (WP -> Odoo).
 *
 * Each payment automatically creates an invoice in Odoo,
 * eliminating manual recurring accounting entries.
 *
 * RCP v3.0+ stores data in custom DB tables accessed via object classes:
 * - RCP_Membership — user membership records
 * - RCP_Customer — customer wrapping WP user
 * - RCP_Payments — payment record operations
 *
 * Requires the Restrict Content Pro plugin (v3.0+) to be active. Mutually
 * exclusive with MemberPress, PMPro, and WC Memberships modules (same Odoo models).
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class RCP_Module extends Membership_Module_Base {

	use RCP_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.4';
	protected const PLUGIN_TESTED_UP_TO = '3.5';

	protected int $exclusive_priority = 12;

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'level'      => 'product.product',
		'payment'    => 'account.move',
		'membership' => 'membership.membership_line',
	];

	/**
	 * Default field mappings.
	 *
	 * Payment mappings are identity (WP key = Odoo key) because
	 * load_payment() returns pre-formatted Odoo data.
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
		'payment'    => [
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
	 * RCP data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var RCP_Handler
	 */
	private RCP_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'rcp', 'Restrict Content Pro', $client_provider, $entity_map, $settings );
		$this->handler = new RCP_Handler( $this->logger );
	}

	/**
	 * Boot the module: register RCP hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'rcp_get_membership' ) ) {
			$this->logger->warning( __( 'RCP module enabled but Restrict Content Pro is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_levels'] ) ) {
			add_action( 'rcp_edit_subscription_level', $this->safe_callback( [ $this, 'on_level_saved' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_payments'] ) ) {
			add_action( 'rcp_create_payment', $this->safe_callback( [ $this, 'on_payment_created' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'rcp_membership_post_activate', $this->safe_callback( [ $this, 'on_membership_activated' ] ), 10, 1 );
			add_action( 'rcp_transition_membership_status', $this->safe_callback( [ $this, 'on_membership_status_change' ] ), 10, 3 );
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
			'sync_payments'      => true,
			'sync_memberships'   => true,
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
			'sync_levels'        => [
				'label'       => __( 'Sync membership levels', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push RCP levels to Odoo as membership products.', 'wp4odoo' ),
			],
			'sync_payments'      => [
				'label'       => __( 'Sync payments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed payments to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_memberships'   => [
				'label'       => __( 'Sync memberships', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push user membership status to Odoo membership lines.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed payments.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Restrict Content Pro.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( function_exists( 'rcp_get_membership' ), 'Restrict Content Pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'RCP_PLUGIN_VERSION' ) ? RCP_PLUGIN_VERSION : '';
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
		return 'payment';
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
		return $this->handler->load_payment( $wp_id, $partner_id, $level_odoo_id );
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
		$payments = new \RCP_Payments();
		$payment  = $payments->get_payment( $wp_id );
		if ( ! $payment ) {
			return [ 0, 0 ];
		}
		return [ (int) ( $payment->user_id ?? 0 ), (int) ( $payment->object_id ?? 0 ) ];
	}

	/**
	 * @inheritDoc
	 */
	protected function get_level_id_for_entity( int $wp_id, string $entity_type ): int {
		if ( 'payment' === $entity_type ) {
			return $this->handler->get_level_id_for_payment( $wp_id );
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
		$payments = new \RCP_Payments();
		$payment  = $payments->get_payment( $wp_id );
		return $payment && 'complete' === ( $payment->status ?? '' );
	}

	/**
	 * @inheritDoc
	 */
	protected function resolve_member_price( int $level_id ): float {
		$level = rcp_get_membership_level( $level_id );
		if ( ! $level ) {
			return 0.0;
		}
		$recurring = (float) ( $level->recurring_amount ?? 0 );
		return $recurring > 0 ? $recurring : (float) ( $level->initial_amount ?? 0 );
	}
}
