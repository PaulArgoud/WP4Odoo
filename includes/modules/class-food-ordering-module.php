<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Food Ordering Module — restaurant order submissions → Odoo POS.
 *
 * Intercepts food orders from supported plugins, extracts order data
 * via Food_Order_Extractor, resolves/creates the customer partner,
 * and enqueues a push job to Odoo's pos.order model.
 *
 * Supported: GloriaFood, WPPizza, RestroPress.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class Food_Ordering_Module extends Module_Base {

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
		'order' => 'pos.order',
	];

	/**
	 * Fields renamed in newer Odoo versions (`note` → `internal_note` on pos.order in Odoo 18+).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $odoo_field_renames = [
		'order' => [ 'note' => 'internal_note' ],
	];

	/**
	 * Default field mappings.
	 *
	 * Order data is pre-formatted by the handler (identity pass-through).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'order' => [
			'partner_id'    => 'partner_id',
			'date_order'    => 'date_order',
			'amount_total'  => 'amount_total',
			'pos_reference' => 'pos_reference',
			'lines'         => 'lines',
			'note'          => 'note',
		],
	];

	/**
	 * Food ordering handler.
	 *
	 * @var Food_Ordering_Handler
	 */
	private Food_Ordering_Handler $handler;

	/**
	 * Food order extractor.
	 *
	 * @var Food_Order_Extractor
	 */
	private Food_Order_Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'food_ordering', 'Food Ordering', $client_provider, $entity_map, $settings );
		$this->handler   = new Food_Ordering_Handler( $this->logger );
		$this->extractor = new Food_Order_Extractor( $this->logger );
	}

	/**
	 * Boot the module: register hooks for detected food plugins.
	 *
	 * @return void
	 */
	public function boot(): void {
		$settings = $this->get_settings();

		// GloriaFood hook.
		if ( ! empty( $settings['sync_gloriafoood'] ) && defined( 'FLAVOR_FLAVOR_VERSION' ) ) {
			add_action( 'save_post_flavor_order', $this->safe_callback( [ $this, 'on_gloriafoood_order' ] ), 10, 1 );
		}

		// WPPizza hook.
		if ( ! empty( $settings['sync_wppizza'] ) && defined( 'WPPIZZA_VERSION' ) ) {
			add_action( 'wppizza_order_complete', $this->safe_callback( [ $this, 'on_wppizza_order' ] ), 10, 1 );
		}

		// RestroPress hook.
		if ( ! empty( $settings['sync_restropress'] ) && defined( 'RP_VERSION' ) ) {
			add_action( 'restropress_complete_purchase', $this->safe_callback( [ $this, 'on_restropress_order' ] ), 10, 1 );
		}
	}

	// ─── Hook Callbacks ──────────────────────────────────────

	/**
	 * Handle GloriaFood order creation.
	 *
	 * @param int $post_id Order post ID.
	 * @return void
	 */
	public function on_gloriafoood_order( int $post_id ): void {
		if ( $this->is_importing() || $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'flavor_order' !== $post->post_type ) {
			return;
		}

		$order_data = $this->extractor->extract_from_gloriafoood( $post_id );
		$this->process_order( $order_data );
	}

	/**
	 * Handle WPPizza order completion.
	 *
	 * @param int $order_id WPPizza order ID.
	 * @return void
	 */
	public function on_wppizza_order( int $order_id ): void {
		if ( $this->is_importing() || $order_id <= 0 ) {
			return;
		}

		$order_data = $this->extractor->extract_from_wppizza( $order_id );
		$this->process_order( $order_data );
	}

	/**
	 * Handle RestroPress order completion.
	 *
	 * @param int $payment_id RestroPress payment ID.
	 * @return void
	 *
	 * @since 3.7.0
	 */
	public function on_restropress_order( int $payment_id ): void {
		if ( $this->is_importing() || $payment_id <= 0 ) {
			return;
		}

		$order_data = $this->extractor->extract_from_restropress( $payment_id );
		$this->process_order( $order_data );
	}

	// ─── Settings ────────────────────────────────────────────

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_gloriafoood' => true,
			'sync_wppizza'     => true,
			'sync_restropress' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_gloriafoood' => [
				'label'       => __( 'Sync GloriaFood orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push GloriaFood orders to Odoo POS.', 'wp4odoo' ),
			],
			'sync_wppizza'     => [
				'label'       => __( 'Sync WPPizza orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WPPizza orders to Odoo POS.', 'wp4odoo' ),
			],
			'sync_restropress' => [
				'label'       => __( 'Sync RestroPress orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push RestroPress orders to Odoo POS.', 'wp4odoo' ),
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
		return defined( 'FLAVOR_FLAVOR_VERSION' ) || defined( 'WPPIZZA_VERSION' ) || defined( 'RP_VERSION' );
	}

	/**
	 * Check external dependency status.
	 *
	 * At least one supported food ordering plugin must be active.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		$plugins = [
			'GloriaFood'  => defined( 'FLAVOR_FLAVOR_VERSION' ),
			'WPPizza'     => defined( 'WPPIZZA_VERSION' ),
			'RestroPress' => defined( 'RP_VERSION' ),
		];

		$active = array_filter( $plugins );

		if ( empty( $active ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'At least one food ordering plugin must be installed and activated.', 'wp4odoo' ),
					],
				],
			];
		}

		$notices  = [];
		$inactive = array_diff_key( $plugins, $active );

		foreach ( $inactive as $name => $status ) {
			$notices[] = [
				'type'    => 'info',
				'message' => sprintf(
					/* translators: %s: plugin name */
					__( '%s is not active.', 'wp4odoo' ),
					$name
				),
			];
		}

		return [
			'available' => true,
			'notices'   => $notices,
		];
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'order' === $entity_type && ! empty( $odoo_values['pos_reference'] ) ) {
			return [ [ 'pos_reference', '=', $odoo_values['pos_reference'] ] ];
		}

		return [];
	}

	// ─── Data Access ─────────────────────────────────────────

	/**
	 * Load WordPress data for an order.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'order' !== $entity_type ) {
			return [];
		}

		$data = get_option( 'wp4odoo_food_order_' . $wp_id, [] );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return [];
		}

		return $data;
	}

	/**
	 * Map WP data to Odoo values (identity — pre-formatted).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array<string, mixed>
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		return $wp_data;
	}

	// ─── Private Helpers ─────────────────────────────────────

	/**
	 * Process an extracted food order: resolve partner, format, and enqueue.
	 *
	 * @param array<string, mixed> $order_data Normalized order data from extractor.
	 * @return void
	 */
	private function process_order( array $order_data ): void {
		if ( empty( $order_data ) ) {
			return;
		}

		// Resolve or create partner.
		$email = $order_data['partner_email'] ?? '';
		$name  = $order_data['partner_name'] ?? '';

		$partner_id = 0;
		if ( '' !== $email ) {
			$partner_id = $this->resolve_partner_from_email( $email, $name );
		}

		// Format as POS order.
		$pos_data = $this->handler->format_pos_order( $order_data, $partner_id );
		if ( empty( $pos_data ) ) {
			return;
		}

		// Store in option for later load.
		$ref_id = absint( crc32( $pos_data['pos_reference'] ?? '' ) );
		update_option( 'wp4odoo_food_order_' . $ref_id, $pos_data, false );

		Queue_Manager::push( 'food_ordering', 'order', 'create', $ref_id, null, $pos_data );

		$this->logger->info(
			'Food order enqueued for Odoo sync.',
			[
				'ref_id' => $ref_id,
				'source' => $order_data['source'] ?? '',
			]
		);
	}
}
