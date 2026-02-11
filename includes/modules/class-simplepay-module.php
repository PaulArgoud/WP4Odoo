<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
class SimplePay_Module extends Dual_Accounting_Module_Base {

	use SimplePay_Hooks;

	/**
	 * Module identifier.
	 *
	 * @var string
	 */

	/**
	 * Human-readable module name.
	 *
	 * @var string
	 */

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
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'simplepay', 'WP Simple Pay', $client_provider, $entity_map, $settings );
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
		return $this->check_dependency( defined( 'SIMPLE_PAY_VERSION' ), 'WP Simple Pay' );
	}

	// ─── Dual_Accounting_Module_Base abstracts ──────────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_child_entity_type(): string {
		return 'payment';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_parent_entity_type(): string {
		return 'form';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_child_cpt(): string {
		return 'wp4odoo_spay';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_email_meta_key(): string {
		return '_spay_email';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_parent_meta_key(): string {
		return '_spay_form_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_validate_setting_key(): string {
		return 'auto_validate_payments';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_validate_status(): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_donor_name( int $wp_id ): string {
		return (string) get_post_meta( $wp_id, '_spay_name', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_parent( int $wp_id ): array {
		return $this->handler->load_form( $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_child( int $wp_id, int $partner_id, int $parent_odoo_id, bool $use_donation_model ): array {
		return $this->handler->load_payment( $wp_id, $partner_id, $parent_odoo_id, $use_donation_model );
	}
}
