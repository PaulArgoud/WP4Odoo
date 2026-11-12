<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan Marketplace Module — bidirectional multi-vendor marketplace sync.
 *
 * Syncs Dokan vendors as Odoo partners (res.partner with supplier_rank),
 * sub-orders as purchase orders (purchase.order), commissions as vendor
 * bills (account.move with move_type=in_invoice), and payouts as account
 * payments (account.payment).
 *
 * Vendors are bidirectional (push + pull status). Sub-orders, commissions,
 * and payouts are push-only (WP → Odoo).
 *
 * Requires WooCommerce + Dokan to be active.
 * In the `marketplace` exclusive group (priority 10).
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class Dokan_Module extends Marketplace_Module_Base {

	use Dokan_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.7';
	protected const PLUGIN_TESTED_UP_TO = '4.2';

	/**
	 * Dokan data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Dokan_Handler
	 */
	private Dokan_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'dokan', 'Dokan', $client_provider, $entity_map, $settings );
		$this->handler = new Dokan_Handler( $this->logger );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_marketplace_name(): string {
		return 'Dokan';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_marketplace_handler(): Marketplace_Handler_Base {
		return $this->handler;
	}

	/**
	 * Get the typed handler instance.
	 *
	 * @return Dokan_Handler
	 */
	public function get_handler(): Dokan_Handler {
		return $this->handler;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_payout_description(): string {
		return __( 'Push approved withdrawal requests to Odoo as payments.', 'wp4odoo' );
	}

	/**
	 * Boot the module: register Dokan hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'dokan' ) ) {
			$this->logger->warning( __( 'Dokan module enabled but Dokan is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get external dependency status for Dokan.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( function_exists( 'dokan' ), 'Dokan' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'DOKAN_PLUGIN_VERSION' ) ? DOKAN_PLUGIN_VERSION : '';
	}

	// ─── Data loading (plugin-specific) ─────────────────────

	/**
	 * Load commission data with resolved vendor partner ID.
	 *
	 * @param int $order_id WC order ID (commission source).
	 * @return array<string, mixed>
	 */
	protected function load_commission_data( int $order_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_order( $order_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for commission.', [ 'order_id' => $order_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'order_id'  => $order_id,
					'vendor_id' => $vendor_id,
				]
			);
			return [];
		}

		$amount = $this->handler->get_admin_earning( $order_id );

		return $this->handler->load_commission( $order_id, $partner_id, $amount );
	}

	/**
	 * Load payout data with resolved vendor partner ID.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @return array<string, mixed>
	 */
	protected function load_payout_data( int $withdraw_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_withdraw( $withdraw_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for payout.', [ 'withdraw_id' => $withdraw_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'withdraw_id' => $withdraw_id,
					'vendor_id'   => $vendor_id,
				]
			);
			return [];
		}

		return $this->handler->load_payout( $withdraw_id, $partner_id );
	}

	/**
	 * Resolve the vendor user ID for a dependent entity.
	 *
	 * @param string $entity_type Entity type (sub_order, commission, payout).
	 * @param int    $wp_id       WordPress entity ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	protected function resolve_vendor_id( string $entity_type, int $wp_id ): int {
		return match ( $entity_type ) {
			'sub_order'  => $this->handler->get_vendor_id_for_order( $wp_id ),
			'commission' => $this->handler->get_vendor_id_for_order( $wp_id ),
			'payout'     => $this->handler->get_vendor_id_for_withdraw( $wp_id ),
			default      => 0,
		};
	}
}
