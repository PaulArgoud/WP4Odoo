<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Job Manager Module — bidirectional sync for job listings.
 *
 * Syncs WP Job Manager job listings as Odoo job positions (hr.job).
 * Single entity type, bidirectional (push + pull).
 *
 * Independent module — coexists with all other modules.
 *
 * Requires the WP Job Manager plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.10.0
 */
class Job_Manager_Module extends Module_Base {

	use Job_Manager_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '2.5';

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
		'job' => 'hr.job',
	];

	/**
	 * Default field mappings.
	 *
	 * Job data is pre-formatted by the handler (bypass standard
	 * field mapping for push). For pull, map_from_odoo() delegates
	 * to the handler's parse method.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'job' => [
			'name'              => 'name',
			'description'       => 'description',
			'state'             => 'state',
			'no_of_recruitment' => 'no_of_recruitment',
		],
	];

	/**
	 * Job Manager data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Job_Manager_Handler
	 */
	private Job_Manager_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'job_manager', 'WP Job Manager', $client_provider, $entity_map, $settings );
		$this->handler = new Job_Manager_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP Job Manager hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'JOB_MANAGER_VERSION' ) ) {
			$this->logger->warning( __( 'WP Job Manager module enabled but WP Job Manager is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_jobs'] ) ) {
			add_action( 'save_post_job_listing', $this->safe_callback( [ $this, 'on_job_save' ] ), 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_jobs' => true,
			'pull_jobs' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_jobs' => [
				'label'       => __( 'Sync job listings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push job listings to Odoo as job positions (hr.job).', 'wp4odoo' ),
			],
			'pull_jobs' => [
				'label'       => __( 'Pull jobs from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull job position changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP Job Manager.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'JOB_MANAGER_VERSION' ), 'WP Job Manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'JOB_MANAGER_VERSION' ) ? JOB_MANAGER_VERSION : '';
	}

	// ─── Translation ──────────────────────────────────────

	/**
	 * Translatable fields for jobs (name + description).
	 *
	 * @param string $entity_type Entity type.
	 * @return array<string, string> Odoo field => WP field.
	 */
	protected function get_translatable_fields( string $entity_type ): array {
		if ( 'job' === $entity_type ) {
			return [
				'name'        => 'post_title',
				'description' => 'post_content',
			];
		}

		return [];
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Jobs dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'job' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}

	// ─── Pull override ───────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Checks pull_jobs setting before delegating to parent.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$settings = $this->get_settings();

		if ( 'job' === $entity_type && empty( $settings['pull_jobs'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Delegates to handler's parse method for department resolution.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'job' === $entity_type ) {
			return $this->handler->parse_job_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
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
			'job'   => $this->handler->save_job( $data, $wp_id ),
			default => 0,
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
		if ( 'job' === $entity_type ) {
			$deleted = wp_delete_post( $wp_id, true );
			return false !== $deleted && null !== $deleted;
		}

		return false;
	}

	// ─── Push: map_to_odoo ───────────────────────────────────

	/**
	 * Map WP data to Odoo values.
	 *
	 * Job data is pre-formatted by the handler (bypasses standard
	 * field mapping — same pattern as Events Calendar events).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'job' === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Data access ─────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'job'   => $this->handler->load_job( $wp_id ),
			default => [],
		};
	}
}
