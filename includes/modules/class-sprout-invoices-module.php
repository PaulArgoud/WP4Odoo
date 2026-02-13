<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprout Invoices Module — bidirectional invoice and payment sync with Odoo.
 *
 * Syncs SI invoices as Odoo account.move (with invoice_line_ids One2many tuples)
 * and payments as Odoo account.payment. Bidirectional.
 *
 * Client → WP user → Odoo partner resolution via Partner_Service.
 * Auto-posts completed invoices via action_post.
 *
 * Mutually exclusive with WP-Invoice (same Odoo models).
 *
 * Requires the Sprout Invoices plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class Sprout_Invoices_Module extends Module_Base {

	use Sprout_Invoices_Hooks;

	protected string $exclusive_group = 'invoicing';
	protected int $exclusive_priority = 10;

	/**
	 * Sync direction: bidirectional.
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
		'invoice' => 'account.move',
		'payment' => 'account.payment',
	];

	/**
	 * Default field mappings.
	 *
	 * Identity mappings because load_invoice() / load_payment() return
	 * pre-formatted Odoo data. invoice_line_ids tuples pass through intact.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'invoice' => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'invoice_date_due' => 'invoice_date_due',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'payment' => [
			'partner_id'   => 'partner_id',
			'amount'       => 'amount',
			'date'         => 'date',
			'payment_type' => 'payment_type',
			'ref'          => 'ref',
		],
	];

	/**
	 * Sprout Invoices data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Sprout_Invoices_Handler
	 */
	private Sprout_Invoices_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'sprout_invoices', 'Sprout Invoices', $client_provider, $entity_map, $settings );
		$this->handler = new Sprout_Invoices_Handler( $this->logger );
	}

	/**
	 * Boot the module: register Sprout Invoices hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'SI_Invoice' ) ) {
			$this->logger->warning( __( 'Sprout Invoices module enabled but Sprout Invoices is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_invoices'] ) ) {
			add_action( 'save_post_sa_invoice', [ $this, 'on_invoice_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_payments'] ) ) {
			add_action( 'si_new_payment', [ $this, 'on_payment' ], 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_invoices'      => true,
			'sync_payments'      => true,
			'auto_post_invoices' => true,
			'pull_invoices'      => true,
			'pull_payments'      => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_invoices'      => [
				'label'       => __( 'Sync invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Sprout Invoices invoices to Odoo as account moves.', 'wp4odoo' ),
			],
			'sync_payments'      => [
				'label'       => __( 'Sync payments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Sprout Invoices payments to Odoo.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed invoices.', 'wp4odoo' ),
			],
			'pull_invoices'      => [
				'label'       => __( 'Pull invoices from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull invoice changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
			'pull_payments'      => [
				'label'       => __( 'Pull payments from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull payment changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Sprout Invoices.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'SI_Invoice' ), 'Sprout Invoices' );
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures the invoice is synced before payments. Auto-posts completed invoices.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		// Ensure invoice is synced before payment.
		if ( 'payment' === $entity_type && 'delete' !== $action ) {
			$invoice_id = $this->handler->get_invoice_id_for_payment( $wp_id );
			if ( $invoice_id > 0 ) {
				$this->ensure_entity_synced( 'invoice', $invoice_id );
			}
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		// Auto-post invoice for completed invoices.
		if ( $result->succeeded() && 'invoice' === $entity_type && 'create' === $action ) {
			$post = get_post( $wp_id );
			if ( $post && 'complete' === $post->post_status ) {
				$this->auto_post_invoice( 'auto_post_invoices', 'invoice', $wp_id );
			}
		}

		return $result;
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Checks pull settings and delegates to parent for actual processing.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): Sync_Result {
		$settings = $this->get_settings();

		if ( 'invoice' === $entity_type && empty( $settings['pull_invoices'] ) ) {
			return Sync_Result::success();
		}

		if ( 'payment' === $entity_type && empty( $settings['pull_payments'] ) ) {
			return Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'invoice' => $this->handler->parse_invoice_from_odoo( $odoo_data ),
			'payment' => $this->handler->parse_payment_from_odoo( $odoo_data ),
			default   => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
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
		return match ( $entity_type ) {
			'invoice' => $this->handler->save_invoice( $data, $wp_id ),
			'payment' => $this->handler->save_payment( $data, $wp_id ),
			default   => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress post ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'invoice' === $entity_type || 'payment' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		return false;
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
			'invoice' => $this->load_invoice_data( $wp_id ),
			'payment' => $this->load_payment_data( $wp_id ),
			default   => [],
		};
	}

	/**
	 * Load and resolve an invoice with partner.
	 *
	 * @param int $wp_id Invoice post ID.
	 * @return array<string, mixed>
	 */
	private function load_invoice_data( int $wp_id ): array {
		$partner_id = $this->resolve_invoice_partner( $wp_id );
		if ( ! $partner_id ) {
			return [];
		}
		return $this->handler->load_invoice( $wp_id, $partner_id );
	}

	/**
	 * Load and resolve a payment with partner and invoice reference.
	 *
	 * @param int $wp_id Payment post ID.
	 * @return array<string, mixed>
	 */
	private function load_payment_data( int $wp_id ): array {
		$invoice_id = $this->handler->get_invoice_id_for_payment( $wp_id );
		if ( $invoice_id <= 0 ) {
			$this->logger->warning( 'Payment has no related invoice.', [ 'payment_id' => $wp_id ] );
			return [];
		}

		$partner_id = $this->resolve_invoice_partner( $invoice_id );
		if ( ! $partner_id ) {
			return [];
		}

		return $this->handler->load_payment( $wp_id, $partner_id );
	}

	/**
	 * Resolve the Odoo partner for an SI invoice via its client.
	 *
	 * Chain: invoice → _client_id → _user_id → WP user → Partner_Service.
	 *
	 * @param int $invoice_id Invoice post ID.
	 * @return int Odoo partner ID, or 0 on failure.
	 */
	private function resolve_invoice_partner( int $invoice_id ): int {
		$client_id = $this->handler->get_client_id( $invoice_id );
		if ( $client_id <= 0 ) {
			$this->logger->warning( 'Invoice has no client.', [ 'invoice_id' => $invoice_id ] );
			return 0;
		}

		$user_id    = (int) get_post_meta( $client_id, '_user_id', true );
		$partner_id = $this->resolve_partner_from_user( $user_id );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for invoice client.', [ 'client_id' => $client_id ] );
			return 0;
		}

		return $partner_id;
	}
}
