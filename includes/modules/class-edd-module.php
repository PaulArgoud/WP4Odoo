<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD Module — bidirectional download/order/invoice sync with Odoo.
 *
 * Uses EDD native post types for downloads and EDD 3.0+ custom tables
 * for orders. Invoices use a custom post type (shared Invoice_Helper).
 * Customer (res.partner) management is delegated to Partner_Service.
 *
 * Domain logic is split into dedicated handlers:
 * - EDD_Download_Handler — download load, save, delete
 * - EDD_Order_Handler    — order load, save, status mapping
 *
 * Mutually exclusive with WooCommerce_Module and Sales_Module:
 * only one can be active at a time (all share sale.order + product.template).
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class EDD_Module extends Module_Base {

	use EDD_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.0';
	protected const PLUGIN_TESTED_UP_TO = '3.6';

	protected string $exclusive_group = 'commerce';
	protected int $exclusive_priority = 20;

	protected array $odoo_models = [
		'download' => 'product.template',
		'order'    => 'sale.order',
		'invoice'  => 'account.move',
	];

	protected array $default_mappings = [
		'download' => [
			'post_title'   => 'name',
			'post_content' => 'description_sale',
			'_edd_price'   => 'list_price',
		],
		'order'    => [
			'total'        => 'amount_total',
			'date_created' => 'date_order',
			'status'       => 'state',
			'partner_id'   => 'partner_id',
		],
		'invoice'  => [
			'post_title'          => 'name',
			'_invoice_total'      => 'amount_total',
			'_invoice_date'       => 'invoice_date',
			'_invoice_state'      => 'state',
			'_payment_state'      => 'payment_state',
			'_wp4odoo_partner_id' => 'partner_id',
			'_invoice_currency'   => 'currency_id',
		],
	];

	/**
	 * Partner service for customer ↔ res.partner resolution.
	 *
	 * @var Partner_Service
	 */
	private Partner_Service $partner_service;

	/**
	 * Download handler for EDD download CRUD.
	 *
	 * @var EDD_Download_Handler
	 */
	private EDD_Download_Handler $download_handler;

	/**
	 * Order handler for EDD order CRUD and status mapping.
	 *
	 * @var EDD_Order_Handler
	 */
	private EDD_Order_Handler $order_handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                         $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository   $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository     $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'edd', 'Easy Digital Downloads', $client_provider, $entity_map, $settings );

		$this->partner_service  = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		$this->download_handler = new EDD_Download_Handler( $this->logger );
		$this->order_handler    = new EDD_Order_Handler( $this->logger, $this->partner_service );
	}

	/**
	 * Boot the module: register EDD hooks, invoice CPT.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			$this->logger->warning( 'EDD module enabled but Easy Digital Downloads is not active.' );
			return;
		}

		$settings = $this->get_settings();

		// Downloads.
		if ( ! empty( $settings['sync_downloads'] ) ) {
			add_action( 'save_post_download', $this->safe_callback( [ $this, 'on_download_save' ] ) );
			add_action( 'before_delete_post', $this->safe_callback( [ $this, 'on_download_delete' ] ) );
		}

		// Orders.
		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'edd_update_payment_status', $this->safe_callback( [ $this, 'on_order_status_change' ] ), 10, 3 );
		}

		// Invoices: CPT (EDD has no native invoice type).
		add_action( 'init', [ Invoice_Helper::class, 'register_cpt' ] );
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public function get_default_settings(): array {
		return [
			'sync_downloads'      => true,
			'sync_orders'         => true,
			'auto_confirm_orders' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_downloads'      => [
				'label'       => __( 'Sync downloads', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize EDD downloads with Odoo products.', 'wp4odoo' ),
			],
			'sync_orders'         => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize EDD orders with Odoo.', 'wp4odoo' ),
			],
			'auto_confirm_orders' => [
				'label'       => __( 'Auto-confirm orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm orders in Odoo when an EDD order is completed.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Easy Digital Downloads.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'Easy_Digital_Downloads' ), 'Easy Digital Downloads' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'EDD_VERSION' ) ? EDD_VERSION : '';
	}

	/**
	 * Get the sync direction for this module.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	// ─── Data Loading (delegates to handlers) ───────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'download' => $this->download_handler->load( $wp_id ),
			'order'    => $this->order_handler->load( $wp_id ),
			'invoice'  => Invoice_Helper::load( $wp_id ),
			default    => $this->unsupported_entity( $entity_type, 'load' ),
		};
	}

	// ─── Data Saving (delegates to handlers) ────────────────

	/**
	 * Save data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'download' => $this->download_handler->save( $data, $wp_id ),
			'order'    => $this->order_handler->save( $data, $wp_id ),
			'invoice'  => Invoice_Helper::save( $data, $wp_id, $this->logger ),
			default    => $this->unsupported_entity_save( $entity_type ),
		};
	}

	// ─── Data Deletion ──────────────────────────────────────

	/**
	 * Delete WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'download' === $entity_type ) {
			return $this->download_handler->delete( $wp_id );
		}

		if ( 'invoice' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		$this->log_unsupported_entity( $entity_type, 'delete' );
		return false;
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Log a warning for an unsupported entity type (load context).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $operation   Operation name.
	 * @return array Always empty.
	 */
	private function unsupported_entity( string $entity_type, string $operation ): array {
		$this->log_unsupported_entity( $entity_type, $operation );
		return [];
	}

	/**
	 * Log a warning for an unsupported entity type (save context).
	 *
	 * @param string $entity_type Entity type.
	 * @return int Always 0.
	 */
	private function unsupported_entity_save( string $entity_type ): int {
		$this->log_unsupported_entity( $entity_type, 'save' );
		return 0;
	}
}
