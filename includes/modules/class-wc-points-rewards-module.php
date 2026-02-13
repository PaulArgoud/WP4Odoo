<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Points & Rewards Module — bidirectional point balance sync.
 *
 * Syncs WC Points & Rewards point balances to Odoo loyalty cards
 * (loyalty.card model) within a single configured Odoo loyalty program.
 *
 * Important limitations communicated to users:
 * - Only point balances are synced — WC transaction history is NOT sent to Odoo.
 * - Syncs with a single Odoo loyalty program (configured by ID in settings).
 * - Earning and redemption rules are configured independently in each system.
 * - The Odoo Loyalty module must be installed (Community edition, v16+).
 * - Points are integers in WC but floats in Odoo — values are rounded on pull.
 *
 * Requires WooCommerce + WooCommerce Points & Rewards to be active.
 * Independent module — coexists with the WooCommerce module.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class WC_Points_Rewards_Module extends Module_Base {

	use WC_Points_Rewards_Hooks;

	/**
	 * Sync direction: bidirectional for balances.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'balance' => 'loyalty.card',
	];

	/**
	 * Default field mappings.
	 *
	 * Balance mappings are identity (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'balance' => [
			'partner_id' => 'partner_id',
			'program_id' => 'program_id',
			'points'     => 'points',
		],
	];

	/**
	 * WC Points & Rewards data handler.
	 *
	 * @var WC_Points_Rewards_Handler
	 */
	private WC_Points_Rewards_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_points_rewards', 'WC Points & Rewards', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Points_Rewards_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WC Points & Rewards hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WC_Points_Rewards_Manager' ) ) {
			$this->logger->warning( __( 'WC Points & Rewards module enabled but WooCommerce Points & Rewards is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_balances'] ) ) {
			add_action( 'wc_points_rewards_after_increase_points', [ $this, 'on_points_change' ], 10, 1 );
			add_action( 'wc_points_rewards_after_reduce_points', [ $this, 'on_points_change' ], 10, 1 );
			add_action( 'wc_points_rewards_after_set_points_balance', [ $this, 'on_points_change' ], 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 0,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_balances'   => [
				'label'       => __( 'Sync point balances', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WC Points & Rewards balances to Odoo loyalty cards. Only balances are synced — transaction history stays in WooCommerce.', 'wp4odoo' ),
			],
			'pull_balances'   => [
				'label'       => __( 'Pull balance updates from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull loyalty card point changes from Odoo back to WooCommerce.', 'wp4odoo' ),
			],
			'odoo_program_id' => [
				'label'       => __( 'Odoo Loyalty Program ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'The ID of the Odoo loyalty program to sync with. Create a Loyalty program in Odoo first, then enter its ID here.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'WC_Points_Rewards_Manager' ), 'WooCommerce Points & Rewards' );
	}

	// ─── Model detection ──────────────────────────────────

	/**
	 * Check whether Odoo has the loyalty.program model.
	 *
	 * @return bool
	 */
	private function has_loyalty_model(): bool {
		return $this->has_odoo_model( Odoo_Model::LoyaltyProgram, 'wp4odoo_has_loyalty_program' );
	}

	/**
	 * Get the configured Odoo loyalty program ID.
	 *
	 * @return int Program ID (0 if not configured).
	 */
	private function get_program_id(): int {
		$settings = $this->get_settings();

		return (int) ( $settings['odoo_program_id'] ?? 0 );
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo loyalty card balance to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress user ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$settings = $this->get_settings();
		if ( empty( $settings['pull_balances'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo loyalty.card data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'balance' === $entity_type ) {
			return $this->handler->parse_balance_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	/**
	 * Save pulled balance data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       WordPress user ID.
	 * @return int The user ID on success, 0 on failure.
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'balance' === $entity_type ) {
			return $this->handler->save_balance( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Balances cannot be deleted — they are always 0 or more.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress user ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WC points balance to Odoo.
	 *
	 * Custom push logic: find-or-create a loyalty.card by partner_id + program_id,
	 * then write the current point balance. Does NOT use parent::push_to_odoo()
	 * because the standard create/update flow doesn't fit the loyalty.card pattern
	 * (search by composite key, not by entity map alone).
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress user ID.
	 * @param int    $odoo_id     Odoo loyalty.card ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'delete' === $action ) {
			// Loyalty cards are not deleted — they persist in Odoo.
			return \WP4Odoo\Sync_Result::success( $odoo_id );
		}

		if ( ! $this->has_loyalty_model() ) {
			$this->logger->info( 'loyalty.program not available — skipping points push.', [ 'user_id' => $wp_id ] );
			return \WP4Odoo\Sync_Result::success();
		}

		$program_id = $this->get_program_id();
		if ( $program_id <= 0 ) {
			$this->logger->warning( 'Odoo Loyalty Program ID not configured — skipping points push.', [ 'user_id' => $wp_id ] );
			return \WP4Odoo\Sync_Result::failure(
				__( 'Odoo Loyalty Program ID not configured.', 'wp4odoo' ),
				\WP4Odoo\Error_Type::Permanent
			);
		}

		// Load WC data.
		$data = $this->handler->load_balance( $wp_id );
		if ( empty( $data ) ) {
			return \WP4Odoo\Sync_Result::failure(
				'No balance data to push.',
				\WP4Odoo\Error_Type::Permanent
			);
		}

		// Resolve partner.
		$partner_id = $this->resolve_partner_from_user( $wp_id );
		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for WC Points user.', [ 'user_id' => $wp_id ] );
			return \WP4Odoo\Sync_Result::failure(
				'Partner resolution failed.',
				\WP4Odoo\Error_Type::Transient
			);
		}

		// Resolve or create the Odoo loyalty.card.
		return $this->resolve_or_create_card( $wp_id, $partner_id, $program_id, $data, $odoo_id );
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress user ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'balance' === $entity_type ) {
			return $this->handler->load_balance( $wp_id );
		}

		return [];
	}

	// ─── Loyalty card resolution ───────────────────────────

	/**
	 * Resolve or create an Odoo loyalty.card for a user.
	 *
	 * Lookup order:
	 * 1. Check entity_map for existing mapping.
	 * 2. Search Odoo by partner_id + program_id.
	 * 3. Create a new loyalty.card.
	 * Then write the current points balance.
	 *
	 * @param int                  $wp_id      WordPress user ID.
	 * @param int                  $partner_id Odoo partner ID.
	 * @param int                  $program_id Odoo loyalty.program ID.
	 * @param array<string, mixed> $data       Balance data.
	 * @param int                  $odoo_id    Known Odoo loyalty.card ID (0 if unknown).
	 * @return \WP4Odoo\Sync_Result
	 */
	private function resolve_or_create_card( int $wp_id, int $partner_id, int $program_id, array $data, int $odoo_id ): \WP4Odoo\Sync_Result {
		$client      = $this->client();
		$odoo_values = $this->handler->format_balance_for_odoo( $data, $program_id, $partner_id );

		// 1. Check entity_map.
		if ( $odoo_id <= 0 ) {
			$odoo_id = $this->get_mapping( 'balance', $wp_id ) ?? 0;
		}

		// 2. Search Odoo if not mapped.
		if ( $odoo_id <= 0 ) {
			try {
				$ids = $client->search(
					'loyalty.card',
					[
						[ 'partner_id', '=', $partner_id ],
						[ 'program_id', '=', $program_id ],
					],
					0,
					1
				);

				if ( ! empty( $ids ) ) {
					$odoo_id = (int) $ids[0];
					$this->save_mapping( 'balance', $wp_id, $odoo_id );
					$this->logger->info(
						'Found existing Odoo loyalty card.',
						[
							'user_id' => $wp_id,
							'card_id' => $odoo_id,
						]
					);
				}
			} catch ( \Exception $e ) {
				$this->logger->error( 'Loyalty card search failed.', [ 'error' => $e->getMessage() ] );
				return \WP4Odoo\Sync_Result::failure( $e->getMessage(), \WP4Odoo\Error_Type::Transient );
			}
		}

		try {
			if ( $odoo_id > 0 ) {
				// Update existing card — only write points (partner/program don't change).
				$client->write( 'loyalty.card', [ $odoo_id ], [ 'points' => $odoo_values['points'] ] );
				$this->save_mapping( 'balance', $wp_id, $odoo_id );
				$this->logger->info(
					'Updated Odoo loyalty card points.',
					[
						'user_id' => $wp_id,
						'card_id' => $odoo_id,
						'points'  => $odoo_values['points'],
					]
				);
			} else {
				// 3. Create new card.
				$odoo_id = $client->create( 'loyalty.card', $odoo_values );
				$this->save_mapping( 'balance', $wp_id, $odoo_id );
				$this->logger->info(
					'Created Odoo loyalty card.',
					[
						'user_id' => $wp_id,
						'card_id' => $odoo_id,
						'points'  => $odoo_values['points'],
					]
				);
			}
		} catch ( \Exception $e ) {
			$this->logger->error( 'Loyalty card push failed.', [ 'error' => $e->getMessage() ] );
			return \WP4Odoo\Sync_Result::failure( $e->getMessage(), \WP4Odoo\Error_Type::Transient );
		}

		return \WP4Odoo\Sync_Result::success( $odoo_id );
	}
}
