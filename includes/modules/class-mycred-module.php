<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * myCRED Module — bidirectional point sync, push-only badges.
 *
 * Syncs myCRED point balances to Odoo loyalty cards (loyalty.card model)
 * bidirectionally, and badge types to Odoo product.template as push-only.
 *
 * Important limitations communicated to users:
 * - Only point balances are synced — transaction log is NOT sent to Odoo.
 * - Syncs with a single Odoo loyalty program (configured by ID in settings).
 * - Badge types are pushed as service products (no pull).
 * - The Odoo Loyalty module must be installed (Community edition, v16+) for points.
 * - Points are integers in myCRED but floats in Odoo — values are rounded on pull.
 *
 * Requires myCRED to be active.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class MyCRED_Module extends Module_Base {

	use MyCRED_Hooks;
	use Loyalty_Card_Resolver;

	/** @since 3.6.0 */
	protected string $exclusive_group = 'gamification';

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '2.7';

	/**
	 * Sync direction: bidirectional (points bidi, badge push-only).
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
		'points' => 'loyalty.card',
		'badge'  => 'product.template',
	];

	/**
	 * Default field mappings.
	 *
	 * Points mappings are identity (pre-formatted by handler via push override).
	 * Badge mappings are handled by handler format method.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'points' => [
			'partner_id' => 'partner_id',
			'program_id' => 'program_id',
			'points'     => 'points',
		],
		'badge'  => [
			'title'       => 'name',
			'description' => 'description_sale',
		],
	];

	/**
	 * myCRED data handler.
	 *
	 * @var MyCRED_Handler
	 */
	private MyCRED_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'mycred', 'myCRED', $client_provider, $entity_map, $settings );
		$this->handler = new MyCRED_Handler( $this->logger );
	}

	/**
	 * Boot the module: register myCRED hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'mycred' ) ) {
			$this->logger->warning( __( 'myCRED module enabled but myCRED is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_points'] ) ) {
			add_action( 'mycred_update_user_balance', $this->safe_callback( [ $this, 'on_points_change' ] ), 10, 4 );
		}

		if ( ! empty( $settings['sync_badges'] ) ) {
			add_action( 'mycred_after_badge_assign', $this->safe_callback( [ $this, 'on_badge_earned' ] ), 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_points'     => true,
			'pull_points'     => true,
			'sync_badges'     => true,
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
			'sync_points'     => [
				'label'       => __( 'Sync point balances', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push myCRED point balances to Odoo loyalty cards. Only balances are synced — transaction log stays in WordPress.', 'wp4odoo' ),
			],
			'pull_points'     => [
				'label'       => __( 'Pull point updates from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull loyalty card point changes from Odoo back to myCRED.', 'wp4odoo' ),
			],
			'sync_badges'     => [
				'label'       => __( 'Sync badge types', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push myCRED badge types to Odoo as service products.', 'wp4odoo' ),
			],
			'odoo_program_id' => [
				'label'       => __( 'Odoo Loyalty Program ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'The ID of the Odoo loyalty program to sync points with. Create a Loyalty program in Odoo first, then enter its ID here.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( function_exists( 'mycred' ), 'myCRED' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'myCRED_VERSION' ) ? myCRED_VERSION : '';
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

	/**
	 * Get the default myCRED points type slug.
	 *
	 * @return string
	 */
	private function get_default_points_type(): string {
		return 'mycred_default';
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'points' => $this->handler->load_points( $wp_id, $this->get_default_points_type() ),
			'badge'  => $this->handler->load_badge( $wp_id ),
			default  => [],
		};
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Push a myCRED entity to Odoo.
	 *
	 * For 'points': custom find-or-create loyalty.card logic via Loyalty_Card_Resolver.
	 * For badge: delegate to parent.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'points' !== $entity_type ) {
			return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
		}

		if ( 'delete' === $action ) {
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

		$data = $this->handler->load_points( $wp_id, $this->get_default_points_type() );
		if ( empty( $data ) ) {
			return \WP4Odoo\Sync_Result::failure(
				'No points data to push.',
				\WP4Odoo\Error_Type::Permanent
			);
		}

		$partner_id = $this->resolve_partner_from_user( $wp_id );
		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for myCRED user.', [ 'user_id' => $wp_id ] );
			return \WP4Odoo\Sync_Result::failure(
				'Partner resolution failed.',
				\WP4Odoo\Error_Type::Transient
			);
		}

		$odoo_values = $this->handler->format_loyalty_card( (int) ( $data['points'] ?? 0 ), $partner_id, $program_id );
		return $this->resolve_or_create_card( 'points', $wp_id, $partner_id, $program_id, $odoo_values, $odoo_id );
	}

	// ─── Pull override ────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Badge is push-only — skip pull. Points respect pull_points setting.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'badge' === $entity_type ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		$settings = $this->get_settings();
		if ( empty( $settings['pull_points'] ) ) {
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
		if ( 'points' === $entity_type ) {
			return [
				'points' => (int) round( (float) ( $odoo_data['points'] ?? 0 ) ),
			];
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return int The WordPress entity ID on success, 0 on failure.
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'points' === $entity_type ) {
			$points = (int) ( $data['points'] ?? 0 );
			return $this->handler->save_points( $wp_id, $points, $this->get_default_points_type() );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Point balances cannot be deleted — they are always 0 or more.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// ─── Odoo Mapping ─────────────────────────────────────

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * For points, handled in push_to_odoo override.
	 * For badge, delegates to handler format method.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'badge' === $entity_type ) {
			return $this->handler->format_badge_product( $wp_data );
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Deduplication ────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Badge dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'badge' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
