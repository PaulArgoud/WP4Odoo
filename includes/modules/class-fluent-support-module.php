<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluent Support Module — sync support tickets with Odoo.
 *
 * Syncs Fluent Support tickets as Odoo helpdesk tickets
 * (helpdesk.ticket — Enterprise) or project tasks
 * (project.task — Community fallback).
 *
 * Bidirectional: tickets are pushed to Odoo, status updates
 * are pulled from Odoo (stage → WP status via keyword heuristic).
 *
 * Requires Fluent Support to be active.
 * Exclusive group: helpdesk (priority 20 — after Awesome Support and SupportCandy).
 *
 * @package WP4Odoo
 * @since   3.7.0
 */
class Fluent_Support_Module extends Helpdesk_Module_Base {

	use Fluent_Support_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.7';
	protected const PLUGIN_TESTED_UP_TO = '1.8';

	/**
	 * Odoo models by entity type.
	 *
	 * helpdesk.ticket may fall back to project.task at runtime
	 * via get_odoo_model() override in the base class.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'ticket' => 'helpdesk.ticket',
	];

	/**
	 * Default field mappings.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'ticket' => [
			'name'        => 'name',
			'description' => 'description',
			'partner_id'  => 'partner_id',
			'priority'    => 'priority',
		],
	];

	/**
	 * Fluent Support data handler.
	 *
	 * @var Fluent_Support_Handler
	 */
	private Fluent_Support_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'fluent_support', 'Fluent Support', $client_provider, $entity_map, $settings );
		$this->handler = new Fluent_Support_Handler( $this->logger );
	}

	/**
	 * Boot the module: register Fluent Support hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'FLUENT_SUPPORT_VERSION' ) ) {
			$this->logger->warning( __( 'Fluent Support module enabled but Fluent Support is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 0,
			'odoo_project_id' => 0,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_tickets'    => [
				'label'       => __( 'Sync tickets', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Fluent Support tickets to Odoo (helpdesk.ticket or project.task).', 'wp4odoo' ),
			],
			'pull_tickets'    => [
				'label'       => __( 'Pull ticket status', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull ticket status changes from Odoo back to Fluent Support.', 'wp4odoo' ),
			],
			'odoo_team_id'    => [
				'label'       => __( 'Helpdesk Team ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Odoo Helpdesk team ID (Enterprise). Leave 0 if using project.task fallback.', 'wp4odoo' ),
			],
			'odoo_project_id' => [
				'label'       => __( 'Project ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Odoo Project ID (Community fallback). Used when helpdesk.ticket is unavailable.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'FLUENT_SUPPORT_VERSION' );
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'Fluent Support' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'FLUENT_SUPPORT_VERSION' ) ? FLUENT_SUPPORT_VERSION : '';
	}

	// ─── Helpdesk_Module_Base abstracts ─────────────────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_closed_status(): string {
		return 'closed';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_ticket( int $ticket_id ): array {
		return $this->handler->load_ticket( $ticket_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_save_ticket_status( int $ticket_id, string $wp_status ): bool {
		return $this->handler->save_ticket_status( $ticket_id, $wp_status );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_parse_ticket_from_odoo( array $odoo_data, bool $is_helpdesk ): array {
		return $this->handler->parse_ticket_from_odoo( $odoo_data, $is_helpdesk );
	}
}
