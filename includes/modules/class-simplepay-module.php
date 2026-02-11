<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Simple Pay Module — push Stripe payments to Odoo accounting.
 *
 * Syncs WP Simple Pay payment forms as Odoo service products (product.product)
 * and Stripe payments as either OCA donation records (donation.donation) or
 * core invoices (account.move), with automatic runtime detection.
 *
 * Fully supports recurring payments: Stripe subscription invoices are
 * captured via webhook and pushed to Odoo automatically.
 *
 * Unlike GiveWP/Charitable, WP Simple Pay does not store payments in
 * WordPress. This module creates hidden tracking posts (wp4odoo_spay)
 * from Stripe webhook data to integrate with the Module_Base architecture.
 *
 * Push-only (WP → Odoo). No mutual exclusivity with other modules.
 *
 * Requires the WP Simple Pay plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class SimplePay_Module extends Module_Base {

	use SimplePay_Hooks;
	use Dual_Accounting_Model;

	/**
	 * Module identifier.
	 *
	 * @var string
	 */
	protected string $id = 'simplepay';

	/**
	 * Human-readable module name.
	 *
	 * @var string
	 */
	protected string $name = 'WP Simple Pay';

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
	 * The payment model is resolved dynamically at push time:
	 * donation.donation if OCA module is detected, account.move otherwise.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'form'    => 'product.product',
		'payment' => 'account.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Payment mappings are minimal because map_to_odoo() is overridden
	 * to pass handler-formatted data directly to Odoo.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'form'    => [
			'form_name'  => 'name',
			'list_price' => 'list_price',
			'type'       => 'type',
		],
		'payment' => [
			'partner_id' => 'partner_id',
		],
	];

	/**
	 * WP Simple Pay data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var SimplePay_Handler
	 */
	private SimplePay_Handler $handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map ) {
		parent::__construct( $client_provider, $entity_map );
		$this->handler = new SimplePay_Handler( $this->logger );
	}

	/**
	 * Boot the module: register CPT and WP Simple Pay hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'SIMPLE_PAY_VERSION' ) ) {
			$this->logger->warning( __( 'WP Simple Pay module enabled but WP Simple Pay is not active.', 'wp4odoo' ) );
			return;
		}

		// Register the hidden tracking CPT.
		add_action( 'init', [ SimplePay_Handler::class, 'register_cpt' ] );

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_forms'] ) ) {
			add_action( 'save_post_simple-pay', [ $this, 'on_form_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_payments'] ) ) {
			add_action( 'simpay_webhook_payment_intent_succeeded', [ $this, 'on_payment_succeeded' ], 10, 2 );
			add_action( 'simpay_webhook_invoice_payment_succeeded', [ $this, 'on_invoice_payment_succeeded' ], 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_forms'             => true,
			'sync_payments'          => true,
			'auto_validate_payments' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_forms'             => [
				'label'       => __( 'Sync payment forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP Simple Pay forms to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_payments'          => [
				'label'       => __( 'Sync payments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Stripe payments to Odoo (includes recurring subscriptions).', 'wp4odoo' ),
			],
			'auto_validate_payments' => [
				'label'       => __( 'Auto-validate payments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically validate payments in Odoo after creation.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP Simple Pay.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		if ( ! defined( 'SIMPLE_PAY_VERSION' ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'WP Simple Pay must be installed and activated to use this module.', 'wp4odoo' ),
					],
				],
			];
		}

		return [
			'available' => true,
			'notices'   => [],
		];
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For payments: resolves the Odoo model dynamically (OCA donation
	 * vs core invoice), ensures the form is synced, and auto-validates.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return bool True on success.
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): bool {
		if ( 'payment' === $entity_type && 'delete' !== $action ) {
			$this->resolve_accounting_model( 'payment' );
			$this->ensure_parent_synced( $wp_id, '_spay_form_id', 'form' );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result && 'payment' === $entity_type && 'create' === $action ) {
			$this->auto_validate( 'payment', $wp_id, 'auto_validate_payments' );
		}

		return $result;
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Payments bypass standard mapping — handler pre-formats for
	 * the target Odoo model. Forms use standard field mapping.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'payment' === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
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
			'form'    => $this->handler->load_form( $wp_id ),
			'payment' => $this->load_payment_data( $wp_id ),
			default   => [],
		};
	}

	/**
	 * Load and resolve a payment with Odoo references.
	 *
	 * Reads from the tracking post (wp4odoo_spay), resolves payer email
	 * to an Odoo partner, and resolves form to an Odoo product.
	 *
	 * @param int $wp_id Tracking post ID.
	 * @return array<string, mixed>
	 */
	private function load_payment_data( int $wp_id ): array {
		$post = get_post( $wp_id );
		if ( ! $post || 'wp4odoo_spay' !== $post->post_type ) {
			return [];
		}

		// Resolve payer → partner via email.
		$payer_email = (string) get_post_meta( $wp_id, '_spay_email', true );
		if ( empty( $payer_email ) ) {
			$this->logger->warning( 'Payment has no payer email.', [ 'wp_id' => $wp_id ] );
			return [];
		}

		$payer_name = (string) get_post_meta( $wp_id, '_spay_name', true );

		$partner_id = $this->partner_service()->get_or_create(
			$payer_email,
			[ 'name' => $payer_name ?: $payer_email ],
			0
		);

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for payment.', [ 'wp_id' => $wp_id ] );
			return [];
		}

		// Resolve form → Odoo product ID.
		$form_id      = (int) get_post_meta( $wp_id, '_spay_form_id', true );
		$form_odoo_id = 0;
		if ( $form_id > 0 ) {
			$form_odoo_id = $this->get_mapping( 'form', $form_id ) ?? 0;
		}

		if ( ! $form_odoo_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo product for payment form.',
				[ 'form_id' => $form_id ]
			);
			return [];
		}

		$use_donation_model = 'donation.donation' === $this->odoo_models['payment'];

		return $this->handler->load_payment( $wp_id, $partner_id, $form_odoo_id, $use_donation_model );
	}
}
