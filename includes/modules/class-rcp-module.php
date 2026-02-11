<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restrict Content Pro Module — push memberships to Odoo accounting.
 *
 * Syncs RCP membership levels as Odoo membership products (product.product),
 * payments as invoices (account.move), and user memberships as
 * membership lines (membership.membership_line). Push-only (WP → Odoo).
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
class RCP_Module extends Module_Base {

	use RCP_Hooks;

	protected string $exclusive_group = 'memberships';
	protected int $exclusive_priority = 12;

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
			add_action( 'rcp_edit_subscription_level', [ $this, 'on_level_saved' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_payments'] ) ) {
			add_action( 'rcp_create_payment', [ $this, 'on_payment_created' ], 10, 2 );
		}

		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'rcp_membership_post_activate', [ $this, 'on_membership_activated' ], 10, 1 );
			add_action( 'rcp_transition_membership_status', [ $this, 'on_membership_status_change' ], 10, 3 );
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

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures the level is synced before payments and memberships.
	 * Auto-posts invoices for completed payments.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( in_array( $entity_type, [ 'payment', 'membership' ], true ) && 'delete' !== $action ) {
			$this->ensure_level_synced( $wp_id, $entity_type );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result->succeeded() && 'payment' === $entity_type && 'create' === $action ) {
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
			'level'      => $this->handler->load_level( $wp_id ),
			'payment'    => $this->load_payment_data( $wp_id ),
			'membership' => $this->load_membership_data( $wp_id ),
			default      => [],
		};
	}

	/**
	 * Load and resolve a payment with Odoo references.
	 *
	 * Resolves user → partner and level → Odoo product ID.
	 *
	 * @param int $payment_id RCP payment ID.
	 * @return array<string, mixed>
	 */
	private function load_payment_data( int $payment_id ): array {
		$payments = new \RCP_Payments();
		$payment  = $payments->get_payment( $payment_id );
		if ( ! $payment ) {
			return [];
		}

		// Resolve user → partner.
		$user_id = (int) ( $payment->user_id ?? 0 );
		if ( $user_id <= 0 ) {
			$this->logger->warning( 'RCP payment has no user.', [ 'payment_id' => $payment_id ] );
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find user for RCP payment.',
				[
					'payment_id' => $payment_id,
					'user_id'    => $user_id,
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
			$this->logger->warning( 'Cannot resolve partner for RCP payment.', [ 'payment_id' => $payment_id ] );
			return [];
		}

		// Resolve level → Odoo product ID.
		$level_id      = (int) ( $payment->object_id ?? 0 );
		$level_odoo_id = 0;
		if ( $level_id > 0 ) {
			$level_odoo_id = $this->get_mapping( 'level', $level_id ) ?? 0;
		}

		if ( ! $level_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for RCP payment level.', [ 'level_id' => $level_id ] );
			return [];
		}

		return $this->handler->load_payment( $payment_id, $partner_id, $level_odoo_id );
	}

	/**
	 * Load and resolve a membership with Odoo references.
	 *
	 * @param int $membership_id RCP membership ID.
	 * @return array<string, mixed>
	 */
	private function load_membership_data( int $membership_id ): array {
		$data = $this->handler->load_membership( $membership_id );

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
			$this->logger->warning( 'Cannot resolve partner for RCP membership.', [ 'membership_id' => $membership_id ] );
			return [];
		}

		// Resolve level → Odoo product.product ID.
		$level_id = $data['level_id'] ?? 0;
		unset( $data['level_id'] );

		if ( $level_id > 0 ) {
			$data['membership_id'] = $this->get_mapping( 'level', $level_id );
		}

		if ( empty( $data['membership_id'] ) ) {
			$this->logger->warning( 'Cannot resolve Odoo product for RCP membership level.', [ 'level_id' => $level_id ] );
			return [];
		}

		// Resolve member_price from level.
		$level = rcp_get_membership_level( $level_id );
		if ( $level ) {
			$recurring = (float) ( $level->recurring_amount ?? 0 );
			$price     = $recurring > 0 ? $recurring : (float) ( $level->initial_amount ?? 0 );
			if ( $price > 0 ) {
				$data['member_price'] = $price;
			}
		}

		return $data;
	}

	// ─── Level sync ─────────────────────────────────────────

	/**
	 * Ensure the RCP level is synced to Odoo before pushing a dependent entity.
	 *
	 * @param int    $wp_id       Entity ID (payment or membership).
	 * @param string $entity_type 'payment' or 'membership'.
	 * @return void
	 */
	private function ensure_level_synced( int $wp_id, string $entity_type ): void {
		$level_id = 0;

		if ( 'payment' === $entity_type ) {
			$level_id = $this->handler->get_level_id_for_payment( $wp_id );
		} elseif ( 'membership' === $entity_type ) {
			$level_id = $this->handler->get_level_id_for_membership( $wp_id );
		}

		if ( $level_id <= 0 ) {
			return;
		}

		$odoo_level_id = $this->get_mapping( 'level', $level_id );
		if ( $odoo_level_id ) {
			return;
		}

		// Level not yet in Odoo — push it synchronously.
		$this->logger->info( 'Auto-pushing RCP level before dependent entity.', [ 'level_id' => $level_id ] );
		parent::push_to_odoo( 'level', 'create', $level_id );
	}

	// ─── Invoice auto-posting ───────────────────────────────

	/**
	 * Auto-post an invoice in Odoo for completed payments.
	 *
	 * @param int $payment_id RCP payment ID.
	 * @return void
	 */
	private function maybe_auto_post_invoice( int $payment_id ): void {
		$settings = $this->get_settings();
		if ( empty( $settings['auto_post_invoices'] ) ) {
			return;
		}

		$payments = new \RCP_Payments();
		$payment  = $payments->get_payment( $payment_id );
		if ( ! $payment || 'complete' !== ( $payment->status ?? '' ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'payment', $payment_id );
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
				'Auto-posted RCP invoice in Odoo.',
				[
					'payment_id' => $payment_id,
					'odoo_id'    => $odoo_id,
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Could not auto-post RCP invoice.',
				[
					'payment_id' => $payment_id,
					'odoo_id'    => $odoo_id,
					'error'      => $e->getMessage(),
				]
			);
		}
	}
}
