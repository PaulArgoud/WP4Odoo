<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Invoice Module — push invoices to Odoo.
 *
 * Syncs WP-Invoice invoices as Odoo account.move (with invoice_line_ids
 * One2many tuples). Single entity type, push-only (WP → Odoo).
 *
 * User → Odoo partner resolution via Partner_Service.
 * Auto-posts paid invoices via action_post.
 *
 * Mutually exclusive with Sprout Invoices (same Odoo models).
 *
 * Requires the WP-Invoice plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class WP_Invoice_Module extends Module_Base {

	use WP_Invoice_Hooks;

	protected string $exclusive_group = 'invoicing';
	protected int $exclusive_priority = 5;

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
		'invoice' => 'account.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Identity mappings because load_invoice() returns pre-formatted Odoo data.
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
	];

	/**
	 * WP-Invoice data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WP_Invoice_Handler
	 */
	private WP_Invoice_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wp_invoice', 'WP-Invoice', $client_provider, $entity_map, $settings );
		$this->handler = new WP_Invoice_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP-Invoice hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WPI_Invoice' ) ) {
			$this->logger->warning( __( 'WP-Invoice module enabled but WP-Invoice is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_invoices'] ) ) {
			add_action( 'wpi_object_created', [ $this, 'on_invoice_save' ], 10, 1 );
			add_action( 'wpi_object_updated', [ $this, 'on_invoice_save' ], 10, 1 );
			add_action( 'wpi_successful_payment', [ $this, 'on_payment' ], 10, 1 );
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
			'sync_invoices'      => [
				'label'       => __( 'Sync invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP-Invoice invoices to Odoo as account moves.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for paid invoices.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP-Invoice.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'WPI_Invoice' ), 'WP-Invoice' );
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Auto-posts paid invoices after creation.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		// Auto-post invoice when paid.
		if ( $result->succeeded() && 'invoice' === $entity_type && 'create' === $action ) {
			$post = get_post( $wp_id );
			if ( $post && 'paid' === $post->post_status ) {
				$this->auto_post_invoice( 'auto_post_invoices', 'invoice', $wp_id );
			}
		}

		return $result;
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
		if ( 'invoice' !== $entity_type ) {
			return [];
		}

		return $this->load_invoice_data( $wp_id );
	}

	/**
	 * Load and resolve an invoice with partner.
	 *
	 * @param int $wp_id Invoice post ID.
	 * @return array<string, mixed>
	 */
	private function load_invoice_data( int $wp_id ): array {
		$user_data = $this->handler->get_user_data( $wp_id );

		$partner_id = 0;

		// Resolve partner from WP user.
		if ( $user_data['user_id'] > 0 ) {
			$user = get_userdata( $user_data['user_id'] );
			if ( $user ) {
				$partner_id = $this->partner_service()->get_or_create(
					$user->user_email,
					[ 'name' => $user->display_name ],
					$user_data['user_id']
				) ?? 0;
			}
		}

		// Fallback: use email from invoice data.
		if ( ! $partner_id && $user_data['email'] ) {
			$partner_id = $this->partner_service()->get_or_create(
				$user_data['email'],
				[ 'name' => $user_data['name'] ?: $user_data['email'] ]
			) ?? 0;
		}

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for WP-Invoice invoice.', [ 'wp_id' => $wp_id ] );
			return [];
		}

		return $this->handler->load_invoice( $wp_id, $partner_id );
	}
}
