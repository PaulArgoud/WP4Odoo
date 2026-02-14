<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Charitable Module — push donations to Odoo accounting.
 *
 * Syncs WP Charitable campaigns as Odoo service products (product.product)
 * and donations as either OCA donation records (donation.donation) or
 * core invoices (account.move), with automatic runtime detection.
 *
 * Fully supports recurring donations: each WP Charitable recurring payment
 * fires the same status transition hook, so every instalment is pushed to
 * Odoo automatically through the standard donation pipeline.
 *
 * Push-only (WP → Odoo). No mutual exclusivity with other modules.
 *
 * Requires the WP Charitable plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Charitable_Module extends Dual_Accounting_Module_Base {

	use Charitable_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.7';
	protected const PLUGIN_TESTED_UP_TO = '1.8';

	/**
	 * Odoo models by entity type.
	 *
	 * The donation model is resolved dynamically at push time:
	 * donation.donation if OCA module is detected, account.move otherwise.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'campaign' => 'product.product',
		'donation' => 'account.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Donation mappings are minimal because map_to_odoo() is overridden
	 * to pass handler-formatted data directly to Odoo.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'campaign' => [
			'form_name'  => 'name',
			'list_price' => 'list_price',
			'type'       => 'type',
		],
		'donation' => [
			'partner_id' => 'partner_id',
		],
	];

	/**
	 * WP Charitable data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Charitable_Handler
	 */
	private Charitable_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'charitable', 'WP Charitable', $client_provider, $entity_map, $settings );
		$this->handler = new Charitable_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP Charitable hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'Charitable' ) ) {
			$this->logger->warning( __( 'WP Charitable module enabled but WP Charitable is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_campaigns'] ) ) {
			add_action( 'save_post_campaign', $this->safe_callback( [ $this, 'on_campaign_save' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_donations'] ) ) {
			add_action( 'transition_post_status', $this->safe_callback( [ $this, 'on_donation_status_change' ] ), 10, 3 );
		}

		if ( class_exists( 'Charitable_Recurring' ) ) {
			$this->logger->info( __( 'WP Charitable Recurring add-on detected. Recurring payments will be synced automatically.', 'wp4odoo' ) );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_campaigns'          => true,
			'sync_donations'          => true,
			'auto_validate_donations' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_campaigns'          => [
				'label'       => __( 'Sync campaigns', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP Charitable campaigns to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_donations'          => [
				'label'       => __( 'Sync donations', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed donations to Odoo (includes recurring payments).', 'wp4odoo' ),
			],
			'auto_validate_donations' => [
				'label'       => __( 'Auto-validate donations', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically validate donations in Odoo after creation.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP Charitable.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'Charitable' ), 'WP Charitable' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'CHARITABLE_VERSION' ) ? CHARITABLE_VERSION : '';
	}

	// ─── Dual_Accounting_Module_Base abstracts ──────────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_child_entity_type(): string {
		return 'donation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_parent_entity_type(): string {
		return 'campaign';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_child_cpt(): string {
		return 'donation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_email_meta_key(): string {
		return '_charitable_donor_email';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_parent_meta_key(): string {
		return '_charitable_campaign_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_validate_setting_key(): string {
		return 'auto_validate_donations';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_validate_status(): ?string {
		return 'charitable-completed';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_parent( int $wp_id ): array {
		return $this->handler->load_campaign( $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_donor_name( int $wp_id ): string {
		return $this->handler->get_donor_name( $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_child( int $wp_id, int $partner_id, int $parent_odoo_id, bool $use_donation_model ): array {
		return $this->handler->load_donation( $wp_id, $partner_id, $parent_odoo_id, $use_donation_model );
	}
}
