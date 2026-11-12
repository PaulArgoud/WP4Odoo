<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP ERP Module -- bidirectional sync for HR data.
 *
 * Syncs WP ERP employees as Odoo employees (hr.employee), departments as
 * Odoo departments (hr.department), and leave requests as Odoo leaves
 * (hr.leave).
 *
 * All three entity types are bidirectional (push + pull).
 *
 * WP ERP stores data in custom database tables -- the handler queries
 * them directly via $wpdb (same pattern as Amelia, Bookly).
 *
 * Independent module -- coexists with all other modules.
 *
 * Requires the WP ERP plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.2.5
 */
class WPERP_Module extends Module_Base {

	use WPERP_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.6';
	protected const PLUGIN_TESTED_UP_TO = '1.14';

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
		'employee'   => 'hr.employee',
		'department' => 'hr.department',
		'leave'      => 'hr.leave',
	];

	/**
	 * Fields renamed in newer Odoo versions (`gender` → `sex` in Odoo 19+).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $odoo_field_renames = [
		'employee' => [ 'gender' => 'sex' ],
	];

	/**
	 * Default field mappings.
	 *
	 * Employee and leave data are pre-formatted by the handler (identity
	 * pass-through in map_to_odoo). Department uses standard field mapping.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'employee'   => [
			'name'       => 'name',
			'work_email' => 'work_email',
			'job_title'  => 'job_title',
			'gender'     => 'gender',
			'birthday'   => 'birthday',
		],
		'department' => [
			'name' => 'name',
		],
		'leave'      => [
			'name'      => 'name',
			'date_from' => 'date_from',
			'date_to'   => 'date_to',
			'state'     => 'state',
		],
	];

	/**
	 * WP ERP data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WPERP_Handler
	 */
	private WPERP_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wperp', 'WP ERP', $client_provider, $entity_map, $settings );
		$this->handler = new WPERP_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP ERP hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WPERP_VERSION' ) ) {
			$this->logger->warning( __( 'WP ERP module enabled but WP ERP is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];
	}

	/**
	 * Third-party tables accessed directly via $wpdb.
	 *
	 * @return array<int, string>
	 */
	protected function get_required_tables(): array {
		return [
			'erp_hr_employees',
			'erp_hr_departments',
			'erp_hr_designations',
			'erp_hr_leaves',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_employees'   => [
				'label'       => __( 'Sync employees', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP employees to Odoo (hr.employee).', 'wp4odoo' ),
			],
			'sync_departments' => [
				'label'       => __( 'Sync departments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP departments to Odoo (hr.department).', 'wp4odoo' ),
			],
			'sync_leaves'      => [
				'label'       => __( 'Sync leaves', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP leave requests to Odoo (hr.leave).', 'wp4odoo' ),
			],
			'pull_employees'   => [
				'label'       => __( 'Pull employees from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull employee changes from Odoo back to WP ERP.', 'wp4odoo' ),
			],
			'pull_departments' => [
				'label'       => __( 'Pull departments from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull department changes from Odoo back to WP ERP.', 'wp4odoo' ),
			],
			'pull_leaves'      => [
				'label'       => __( 'Pull leaves from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull leave request changes from Odoo back to WP ERP.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WP ERP.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'WPERP_VERSION' ), 'WP ERP' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WPERP_VERSION' ) ? WPERP_VERSION : '';
	}

	// --- Deduplication ---------------------------------------------------

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Employees dedup by work_email. Departments dedup by name.
	 * Leaves have no dedup (each request is unique).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'employee' === $entity_type && ! empty( $odoo_values['work_email'] ) ) {
			return [ [ 'work_email', '=', $odoo_values['work_email'] ] ];
		}

		if ( 'department' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}

	// --- Pull override ---------------------------------------------------

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Checks pull settings per entity type before delegating to parent.
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

		if ( 'employee' === $entity_type && empty( $settings['pull_employees'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'department' === $entity_type && empty( $settings['pull_departments'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'leave' === $entity_type && empty( $settings['pull_leaves'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Delegates to handler's parse methods for each entity type.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'employee'   => $this->handler->parse_employee_from_odoo( $odoo_data ),
			'department' => $this->handler->parse_department_from_odoo( $odoo_data ),
			'leave'      => $this->handler->parse_leave_from_odoo( $odoo_data ),
			default      => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * Delegates to handler save methods.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'employee'   => $this->handler->save_employee( $data, $wp_id ),
			'department' => $this->handler->save_department( $data, $wp_id ),
			'leave'      => $this->handler->save_leave( $data, $wp_id ),
			default      => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Delegates to handler delete methods.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return match ( $entity_type ) {
			'employee'   => $this->handler->delete_employee( $wp_id ),
			'department' => $this->handler->delete_department( $wp_id ),
			'leave'      => $this->handler->delete_leave( $wp_id ),
			default      => false,
		};
	}

	// --- Push override ---------------------------------------------------

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures dependency chain:
	 * - Leaves require the employee to be synced first.
	 * - Employees require the department to be synced first.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'delete' !== $action ) {
			if ( 'leave' === $entity_type ) {
				$emp_id = $this->handler->get_employee_id_for_leave( $wp_id );
				$this->ensure_entity_synced( 'employee', $emp_id );
			}

			if ( 'employee' === $entity_type ) {
				$dept_id = $this->handler->get_department_id_for_employee( $wp_id );
				$this->ensure_entity_synced( 'department', $dept_id );
			}
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Employees and leaves bypass standard mapping -- the data is pre-formatted
	 * by the handler (identity pass-through). Departments use standard field
	 * mapping from parent.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'employee' === $entity_type || 'leave' === $entity_type ) {
			return $this->reconcile_with_schema( $entity_type, $wp_data );
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// --- Data access -----------------------------------------------------

	/**
	 * Load WordPress data for an entity.
	 *
	 * Dispatches to handler and enriches employees with partner and
	 * department resolution.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'employee' === $entity_type ) {
			return $this->load_employee_data( $wp_id );
		}

		if ( 'department' === $entity_type ) {
			return $this->handler->load_department( $wp_id );
		}

		if ( 'leave' === $entity_type ) {
			return $this->load_leave_data( $wp_id );
		}

		return [];
	}

	/**
	 * Load and enrich employee data.
	 *
	 * Resolves the WordPress user to an Odoo partner (address_home_id)
	 * and the WP ERP department to an Odoo department_id via entity_map.
	 *
	 * @param int $emp_id Employee (user) ID.
	 * @return array<string, mixed>
	 */
	private function load_employee_data( int $emp_id ): array {
		$data = $this->handler->load_employee( $emp_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve WordPress user -> Odoo partner for address_home_id.
		$user_id    = (int) ( $data['user_id'] ?? $emp_id );
		$partner_id = $this->resolve_partner_from_user( $user_id );
		if ( $partner_id ) {
			$data['address_home_id'] = $partner_id;
		}

		// Resolve WP ERP department -> Odoo department_id via entity_map.
		$dept_wp_id = (int) ( $data['department'] ?? 0 );
		if ( $dept_wp_id > 0 ) {
			$dept_odoo_id = $this->get_mapping( 'department', $dept_wp_id );
			if ( $dept_odoo_id ) {
				$data['department_id'] = $dept_odoo_id;
			}
		}

		// Remove WP-only keys not needed in Odoo.
		unset( $data['user_id'], $data['department'] );

		return $data;
	}

	/**
	 * Load and enrich leave data.
	 *
	 * Resolves employee_id from the WP ERP user_id to the Odoo employee mapping.
	 *
	 * @param int $leave_id Leave ID.
	 * @return array<string, mixed>
	 */
	private function load_leave_data( int $leave_id ): array {
		$data = $this->handler->load_leave( $leave_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve WP ERP user_id -> Odoo employee ID via entity_map.
		$emp_wp_id = (int) ( $data['employee_id'] ?? 0 );
		if ( $emp_wp_id > 0 ) {
			$emp_odoo_id = $this->get_mapping( 'employee', $emp_wp_id );
			if ( $emp_odoo_id ) {
				$data['employee_id'] = $emp_odoo_id;
			}
		}

		return $data;
	}
}
