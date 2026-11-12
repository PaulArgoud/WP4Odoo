<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Error_Type;
use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Project Manager Module — sync projects, tasks, and timesheets with Odoo.
 *
 * Syncs WP Project Manager (weDevs) projects as Odoo project.project,
 * tasks as project.task, and timesheet entries as account.analytic.line.
 *
 * Bidirectional for projects and tasks. Timesheets are push-only
 * (they originate in WordPress).
 *
 * Timesheet push requires hr.employee resolution: the WordPress user is
 * matched to an Odoo employee by email. If no employee is found, the
 * timesheet sync returns a Transient failure for retry.
 *
 * No mutual exclusivity with other modules.
 *
 * Requires WP Project Manager by weDevs to be active.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class Project_Manager_Module extends Module_Base {

	use Project_Manager_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '2.8';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'project'   => 'project.project',
		'task'      => 'project.task',
		'timesheet' => 'account.analytic.line',
	];

	/**
	 * Default field mappings.
	 *
	 * Timesheet data is pre-formatted by handler (identity pass-through).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'project'   => [
			'title'       => 'name',
			'description' => 'description',
		],
		'task'      => [
			'title'       => 'name',
			'description' => 'description',
			'due_date'    => 'date_deadline',
		],
		'timesheet' => [
			'name'        => 'name',
			'project_id'  => 'project_id',
			'task_id'     => 'task_id',
			'employee_id' => 'employee_id',
			'unit_amount' => 'unit_amount',
			'date'        => 'date',
		],
	];

	/**
	 * Project Manager data handler.
	 *
	 * @var Project_Manager_Handler
	 */
	private Project_Manager_Handler $handler;

	/**
	 * Cached employee ID lookups: email → Odoo hr.employee ID.
	 *
	 * @var array<string, int>
	 */
	private array $employee_cache = [];

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'project_manager', 'WP Project Manager', $client_provider, $entity_map, $settings );
		$this->handler = new Project_Manager_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP Project Manager hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_hooks();
	}

	/**
	 * Sync direction: bidirectional (projects/tasks ↔, timesheets →).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_projects'   => true,
			'sync_tasks'      => true,
			'sync_timesheets' => true,
			'pull_projects'   => true,
			'pull_tasks'      => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_projects'   => [
				'label'       => __( 'Sync projects', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP projects to Odoo as project.project records.', 'wp4odoo' ),
			],
			'sync_tasks'      => [
				'label'       => __( 'Sync tasks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP tasks to Odoo as project.task records.', 'wp4odoo' ),
			],
			'sync_timesheets' => [
				'label'       => __( 'Sync timesheets', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push time entries to Odoo as analytic lines (requires Timesheet module).', 'wp4odoo' ),
			],
			'pull_projects'   => [
				'label'       => __( 'Pull projects', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo projects into WP Project Manager.', 'wp4odoo' ),
			],
			'pull_tasks'      => [
				'label'       => __( 'Pull tasks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo project tasks into WP Project Manager.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			defined( 'CPM_VERSION' ) || class_exists( 'WeDevs\PM\Core\WP\WP_Project_Manager' ) || ( defined( 'PM_VERSION' ) && class_exists( 'WeDevs\PM\Core\Config\Config' ) ),
			'WP Project Manager'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		if ( defined( 'CPM_VERSION' ) ) {
			return (string) CPM_VERSION;
		}

		// Project Manager 3.x+ renamed the constant.
		return defined( 'PM_VERSION' ) ? (string) PM_VERSION : '';
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Tasks: ensures parent project is synced first.
	 * Timesheets: ensures task is synced and resolves employee.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		// Probe Odoo for project module.
		if ( 'delete' !== $action && ! $this->has_project_model() ) {
			$this->logger->info( 'project.project not available — Project module not installed in Odoo.' );
			return Sync_Result::success();
		}

		if ( 'task' === $entity_type && 'delete' !== $action ) {
			$project_id = $this->handler->get_project_id_for_task( $wp_id );
			if ( $project_id > 0 ) {
				$this->ensure_entity_synced( 'project', $project_id );
			}
		}

		if ( 'timesheet' === $entity_type && 'delete' !== $action ) {
			$ts      = $this->handler->load_timesheet( $wp_id );
			$task_id = $ts['task_id'] ?? 0;
			if ( $task_id > 0 ) {
				$this->ensure_entity_synced( 'task', $task_id );
			}
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Pull override ────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Timesheets are push-only. Projects and tasks gated on settings.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): Sync_Result {
		if ( 'timesheet' === $entity_type ) {
			$this->logger->info( 'Timesheet pull not supported — timesheets originate in WordPress.', [ 'odoo_id' => $odoo_id ] );
			return Sync_Result::success();
		}

		$settings = $this->get_settings();

		if ( 'project' === $entity_type && empty( $settings['pull_projects'] ) ) {
			return Sync_Result::success();
		}

		if ( 'task' === $entity_type && empty( $settings['pull_tasks'] ) ) {
			return Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'project'   => $this->handler->load_project( $wp_id ),
			'task'      => $this->load_task_data( $wp_id ),
			'timesheet' => $this->load_timesheet_data( $wp_id ),
			default     => [],
		};
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'project' => $this->handler->parse_project_from_odoo( $odoo_data ),
			'task'    => $this->handler->parse_task_from_odoo( $odoo_data ),
			default   => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Timesheets bypass standard mapping — the data is pre-formatted.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array<string, mixed>
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'timesheet' === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'project' => $this->handler->save_project( $data, $wp_id ),
			'task'    => $this->handler->save_task( $data, $wp_id ),
			default   => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return match ( $entity_type ) {
			'project' => $this->handler->delete_project( $wp_id ),
			'task'    => $this->handler->delete_task( $wp_id ),
			default   => false,
		};
	}

	// ─── Deduplication ────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'project' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		if ( 'task' === $entity_type && ! empty( $odoo_values['name'] ) && ! empty( $odoo_values['project_id'] ) ) {
			return [
				[ 'name', '=', $odoo_values['name'] ],
				[ 'project_id', '=', $odoo_values['project_id'] ],
			];
		}

		return [];
	}

	// ─── Internal helpers ─────────────────────────────────

	/**
	 * Check whether Odoo has the project.project model.
	 *
	 * @return bool
	 */
	private function has_project_model(): bool {
		return $this->has_odoo_model( Odoo_Model::ProjectProject, 'wp4odoo_has_project_project' );
	}

	/**
	 * Load and format task data with project Odoo ID.
	 *
	 * @param int $task_id Task ID.
	 * @return array<string, mixed>
	 */
	private function load_task_data( int $task_id ): array {
		$task = $this->handler->load_task( $task_id );
		if ( empty( $task ) ) {
			return [];
		}

		$project_id = $task['project_id'] ?? 0;
		if ( $project_id > 0 ) {
			$project_odoo_id = $this->entity_map()->get_odoo_id( $this->get_id(), 'project', $project_id );
			if ( $project_odoo_id ) {
				$task['project_id'] = $project_odoo_id;
			}
		}

		return $task;
	}

	/**
	 * Load and format timesheet data with Odoo IDs.
	 *
	 * Resolves the task → Odoo task ID, project → Odoo project ID,
	 * and user → Odoo hr.employee ID.
	 *
	 * @param int $entry_id Timesheet entry ID.
	 * @return array<string, mixed>
	 */
	private function load_timesheet_data( int $entry_id ): array {
		$ts = $this->handler->load_timesheet( $entry_id );
		if ( empty( $ts ) ) {
			return [];
		}

		$task_id = $ts['task_id'] ?? 0;
		$user_id = $ts['user_id'] ?? 0;

		// Resolve task → Odoo IDs.
		$task_odoo_id    = 0;
		$project_odoo_id = 0;
		if ( $task_id > 0 ) {
			$task_odoo_id = $this->entity_map()->get_odoo_id( $this->get_id(), 'task', $task_id ) ?? 0;

			$project_id = $this->handler->get_project_id_for_task( $task_id );
			if ( $project_id > 0 ) {
				$project_odoo_id = $this->entity_map()->get_odoo_id( $this->get_id(), 'project', $project_id ) ?? 0;
			}
		}

		// Resolve user → hr.employee.
		$employee_id = $this->resolve_employee( $user_id );

		// Get task name for the description.
		$task_data       = $task_id > 0 ? $this->handler->load_task( $task_id ) : [];
		$ts['task_name'] = $task_data['title'] ?? __( 'Time entry', 'wp4odoo' );

		return $this->handler->format_timesheet( $ts, $task_odoo_id, $project_odoo_id, $employee_id );
	}

	/**
	 * Resolve a WordPress user ID to an Odoo hr.employee ID.
	 *
	 * Searches Odoo for an employee whose related user login matches the
	 * WordPress user's email address. Caches results per request.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Odoo hr.employee ID, or 0 if not found.
	 */
	private function resolve_employee( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return 0;
		}

		$email = $user->user_email;

		if ( isset( $this->employee_cache[ $email ] ) ) {
			return $this->employee_cache[ $email ];
		}

		try {
			$client  = $this->client();
			$results = $client->search_read(
				Odoo_Model::HrEmployee->value,
				[ [ 'work_email', '=', $email ] ],
				[ 'id' ],
				0,
				1
			);

			$id = ! empty( $results[0]['id'] ) ? (int) $results[0]['id'] : 0;
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to resolve employee for timesheet.',
				[
					'email' => $email,
					'error' => $e->getMessage(),
				]
			);
			$id = 0;
		}

		$this->employee_cache[ $email ] = $id;
		return $id;
	}
}
