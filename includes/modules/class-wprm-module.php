<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Recipe Maker Module — push recipes to Odoo as products.
 *
 * Syncs WPRM recipes as Odoo service products (product.product).
 * Single entity type, push-only (WP → Odoo).
 *
 * No mutual exclusivity with other modules.
 *
 * Requires the WP Recipe Maker plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class WPRM_Module extends Module_Base {

	use WPRM_Hooks;

	/**
	 * Module identifier.
	 *
	 * @var string
	 */
	protected string $id = 'wprm';

	/**
	 * Human-readable module name.
	 *
	 * @var string
	 */
	protected string $name = 'WP Recipe Maker';

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
		'recipe' => 'product.product',
	];

	/**
	 * Default field mappings.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'recipe' => [
			'recipe_name' => 'name',
			'description' => 'description_sale',
			'list_price'  => 'list_price',
			'type'        => 'type',
		],
	];

	/**
	 * WPRM data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WPRM_Handler
	 */
	private WPRM_Handler $handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( $client_provider, $entity_map, $settings );
		$this->handler = new WPRM_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WPRM hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WPRM_VERSION' ) ) {
			$this->logger->warning( __( 'WP Recipe Maker module enabled but WP Recipe Maker is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_recipes'] ) ) {
			add_action( 'save_post_wprm_recipe', [ $this, 'on_recipe_save' ], 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_recipes' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_recipes' => [
				'label'       => __( 'Sync recipes', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP Recipe Maker recipes to Odoo as service products.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP Recipe Maker.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		if ( ! defined( 'WPRM_VERSION' ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'WP Recipe Maker must be installed and activated to use this module.', 'wp4odoo' ),
					],
				],
			];
		}

		return [
			'available' => true,
			'notices'   => [],
		];
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
			'recipe' => $this->handler->load_recipe( $wp_id ),
			default  => [],
		};
	}
}
