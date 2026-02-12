<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Crowdfunding Module — push crowdfunding campaigns to Odoo.
 *
 * Syncs Themeum WP Crowdfunding campaigns (WC products with wpneo_* meta)
 * as Odoo service products (product.product). Push-only (WP → Odoo).
 *
 * Campaigns are WooCommerce products with additional crowdfunding metadata
 * (funding goal, end date, minimum pledge amount). Pledges are standard
 * WC orders and can be synced by the WooCommerce module.
 *
 * Coexists with the WooCommerce module (no mutual exclusivity).
 *
 * Requires the WP Crowdfunding plugin (Themeum) and WooCommerce to be active.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class Crowdfunding_Module extends Module_Base {

	use Crowdfunding_Hooks;

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
		'campaign' => 'product.product',
	];

	/**
	 * Default field mappings.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'campaign' => [
			'campaign_name' => 'name',
			'description'   => 'description_sale',
			'list_price'    => 'list_price',
			'type'          => 'type',
		],
	];

	/**
	 * Crowdfunding data handler.
	 *
	 * @var Crowdfunding_Handler
	 */
	private Crowdfunding_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'crowdfunding', 'WP Crowdfunding', $client_provider, $entity_map, $settings );
		$this->handler = new Crowdfunding_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP Crowdfunding hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'wpneo_crowdfunding_init' ) ) {
			$this->logger->warning( __( 'WP Crowdfunding module enabled but WP Crowdfunding is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_campaigns'] ) ) {
			add_action( 'save_post_product', [ $this, 'on_campaign_save' ], 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_campaigns' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_campaigns' => [
				'label'       => __( 'Sync campaigns', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push crowdfunding campaigns to Odoo as service products.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP Crowdfunding.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( function_exists( 'wpneo_crowdfunding_init' ), 'WP Crowdfunding' );
	}

	/**
	 * Get the handler instance (used by hooks trait).
	 *
	 * @return Crowdfunding_Handler
	 */
	public function get_handler(): Crowdfunding_Handler {
		return $this->handler;
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
			'campaign' => $this->handler->load_campaign( $wp_id ),
			default    => [],
		};
	}
}
