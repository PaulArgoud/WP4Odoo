<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Returns Module — bidirectional refund/credit note sync,
 * push-only for return pickings.
 *
 * Syncs WC refunds as Odoo credit notes (account.move with move_type=out_refund)
 * and optionally creates return stock.picking entries in Odoo.
 *
 * Supports optional integration with YITH WooCommerce Return & Warranty
 * and ReturnGO for enhanced return request workflows.
 *
 * Requires WooCommerce to be active.
 * Independent module — coexists with the WooCommerce module.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class WC_Returns_Module extends Module_Base {

	use WC_Returns_Hooks;

	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '11.3';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'refund'         => 'account.move',
		'return_picking' => 'stock.picking',
	];

	/**
	 * Fields renamed in newer Odoo versions (`move_ids_without_package` → `move_ids` on stock.picking in Odoo 19+).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $odoo_field_renames = [
		'return_picking' => [ 'move_ids_without_package' => 'move_ids' ],
	];

	/**
	 * Default field mappings.
	 *
	 * Refund and return_picking data is pre-formatted by the handler,
	 * so mappings are identity pass-through.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'refund'         => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'return_picking' => [
			'origin'                   => 'origin',
			'picking_type_id'          => 'picking_type_id',
			'location_id'              => 'location_id',
			'location_dest_id'         => 'location_dest_id',
			'move_ids_without_package' => 'move_ids_without_package',
		],
	];

	/**
	 * Returns data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_Returns_Handler
	 */
	private WC_Returns_Handler $handler;

	/**
	 * Partner service for customer resolution.
	 *
	 * @var Partner_Service
	 */
	private Partner_Service $partner_service;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_returns', 'WooCommerce Returns', $client_provider, $entity_map, $settings );
		$this->partner_service = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		$this->handler         = new WC_Returns_Handler( $this->logger, $this->partner_service, $this->entity_map() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Sync direction: bidirectional for refunds, push-only for return pickings.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register WC refund hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->logger->warning( __( 'WC Returns module enabled but WooCommerce is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_refunds'] ) ) {
			add_action( 'woocommerce_refund_created', $this->safe_callback( [ $this, 'on_refund_created' ] ), 10, 2 );
		}

		// YITH WooCommerce Return & Warranty.
		if ( ! empty( $settings['yith_hooks'] ) && defined( 'YITH_WRMA_VERSION' ) ) {
			add_action( 'ywrma_after_approve_request', $this->safe_callback( [ $this, 'on_yith_return_approved' ] ), 10, 1 );
		}

		// ReturnGO.
		if ( ! empty( $settings['returngo_hooks'] ) && defined( 'RETURNGO_VERSION' ) ) {
			add_action( 'returngo_return_created', $this->safe_callback( [ $this, 'on_returngo_return' ] ), 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_refunds'         => true,
			'pull_refunds'         => true,
			'sync_return_pickings' => false,
			'auto_post_refund'     => true,
			'yith_hooks'           => true,
			'returngo_hooks'       => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_refunds'         => [
				'label'       => __( 'Sync refunds', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WooCommerce refunds to Odoo as credit notes.', 'wp4odoo' ),
			],
			'pull_refunds'         => [
				'label'       => __( 'Pull credit notes', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull credit notes from Odoo and create WooCommerce refunds.', 'wp4odoo' ),
			],
			'sync_return_pickings' => [
				'label'       => __( 'Sync return pickings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create return stock pickings in Odoo when a refund is processed.', 'wp4odoo' ),
			],
			'auto_post_refund'     => [
				'label'       => __( 'Auto-post credit notes', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm credit notes in Odoo after creation.', 'wp4odoo' ),
			],
			'yith_hooks'           => [
				'label'       => __( 'YITH Return hooks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Hook into YITH WooCommerce Return & Warranty for enhanced return workflows.', 'wp4odoo' ),
			],
			'returngo_hooks'       => [
				'label'       => __( 'ReturnGO hooks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Hook into ReturnGO for automated return processing.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WC_VERSION' ) ? WC_VERSION : '';
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For refunds: auto-post credit note on success.
	 * For return_pickings: only push if setting enabled.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		// Auto-post credit notes in Odoo.
		if ( $result->succeeded() && 'refund' === $entity_type && 'create' === $action ) {
			$this->auto_post_invoice( 'auto_post_refund', 'refund', $wp_id );
		}

		return $result;
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only refunds (credit notes) can be pulled. Return pickings are push-only.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'return_picking' === $entity_type ) {
			$this->logger->info(
				'Return picking pull not supported — push-only.',
				[ 'odoo_id' => $odoo_id ]
			);
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'refund' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_refunds'] ) ) {
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'refund'         => $this->handler->load_refund( $wp_id ),
			'return_picking' => $this->handler->load_return_picking( $wp_id ),
			default          => [],
		};
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'refund' === $entity_type ) {
			return $this->handler->parse_refund_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
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
		if ( 'refund' === $entity_type ) {
			return $this->handler->save_refund( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Refunds and return pickings cannot be deleted from Odoo side.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Refunds dedup by credit note ref.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'refund' === $entity_type && ! empty( $odoo_values['ref'] ) ) {
			return [
				[ 'ref', '=', $odoo_values['ref'] ],
				[ 'move_type', '=', 'out_refund' ],
			];
		}

		return [];
	}
}
