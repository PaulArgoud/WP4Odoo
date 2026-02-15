<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\API\Odoo_Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for WordPress For Odoo.
 *
 * Registered as `wp wp4odoo <subcommand>`.
 *
 * @package WP4Odoo
 * @since   1.9.0
 */
class CLI {

	/**
	 * Query service instance.
	 *
	 * @var \WP4Odoo\Query_Service
	 */
	private Query_Service $query_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->query_service = new Query_Service();
	}

	/**
	 * Show plugin status: connection, queue stats, modules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo status
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$credentials = Odoo_Auth::get_credentials();
		$connected   = ! empty( $credentials['url'] );

		\WP_CLI::line( '' );
		\WP_CLI::line( 'WordPress For Odoo v' . WP4ODOO_VERSION );
		\WP_CLI::line( str_repeat( '─', 40 ) );

		// Connection.
		if ( $connected ) {
			\WP_CLI::success(
				sprintf(
					'Connected to %s (db: %s, protocol: %s)',
					$credentials['url'],
					$credentials['database'],
					$credentials['protocol']
				)
			);
		} else {
			\WP_CLI::warning( 'Not configured — no Odoo URL set.' );
		}

		// Queue stats.
		$stats = Queue_Manager::get_stats();
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Queue:' );
		\WP_CLI\Utils\format_items(
			'table',
			[
				[
					'pending'    => $stats['pending'],
					'processing' => $stats['processing'],
					'completed'  => $stats['completed'],
					'failed'     => $stats['failed'],
				],
			],
			[ 'pending', 'processing', 'completed', 'failed' ]
		);

		if ( '' !== $stats['last_completed_at'] ) {
			\WP_CLI::line( 'Last completed: ' . $stats['last_completed_at'] );
		}

		// Modules.
		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Modules:' );
		$rows     = [];
		$settings = \WP4Odoo_Plugin::instance()->settings();
		foreach ( $modules as $id => $module ) {
			$rows[] = [
				'id'     => $id,
				'status' => $settings->is_module_enabled( $id ) ? 'enabled' : 'disabled',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'status' ] );
	}

	/**
	 * Test the Odoo connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo test
	 *
	 * @subcommand test
	 */
	public function test(): void {
		$credentials = Odoo_Auth::get_credentials();

		if ( empty( $credentials['url'] ) ) {
			\WP_CLI::error( 'No Odoo connection configured. Go to Odoo Connector settings first.' );
		}

		\WP_CLI::line( sprintf( 'Testing connection to %s...', $credentials['url'] ) );

		$result = Odoo_Auth::test_connection(
			$credentials['url'],
			$credentials['database'],
			$credentials['username'],
			$credentials['api_key'],
			$credentials['protocol']
		);

		if ( $result['success'] ) {
			\WP_CLI::success( sprintf( 'Connection successful! UID: %d', $result['uid'] ?? 0 ) );
		} else {
			\WP_CLI::error( sprintf( 'Connection failed: %s', $result['message'] ) );
		}
	}

	/**
	 * Run sync queue processing.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview what would be synced without making any changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo sync run
	 *     wp wp4odoo sync run --dry-run
	 *
	 * @subcommand sync
	 * @when after_wp_load
	 */
	public function sync( array $args, array $assoc_args = [] ): void {
		$sub = $args[0] ?? 'run';

		if ( 'run' !== $sub ) {
			\WP_CLI::error( sprintf( 'Unknown subcommand: %s. Usage: wp wp4odoo sync run', $sub ) );
		}

		$dry_run = isset( $assoc_args['dry-run'] );

		\WP_CLI::warning(
			__( 'Back up your WordPress and Odoo databases before running sync operations.', 'wp4odoo' )
		);

		if ( $dry_run ) {
			\WP_CLI::line( 'Processing sync queue (dry-run mode)...' );
		} else {
			\WP_CLI::line( 'Processing sync queue...' );
		}

		$engine = new Sync_Engine(
			fn( string $id ) => \WP4Odoo_Plugin::instance()->get_module( $id ),
			new Sync_Queue_Repository(),
			\WP4Odoo_Plugin::instance()->settings()
		);

		if ( $dry_run ) {
			$engine->set_dry_run( true );
		}

		$processed = $engine->process_queue();

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( '%d job(s) would be processed (dry-run).', $processed ) );
		} else {
			\WP_CLI::success( sprintf( '%d job(s) processed.', $processed ) );
		}
	}

	/**
	 * Manage the sync queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo queue stats
	 *     wp wp4odoo queue list --page=1 --per-page=20
	 *     wp wp4odoo queue retry
	 *     wp wp4odoo queue cleanup --days=7
	 *     wp wp4odoo queue cancel 42
	 *
	 * @subcommand queue
	 * @when after_wp_load
	 */
	public function queue( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'stats';

		match ( $sub ) {
			'stats'   => $this->queue_stats( $assoc_args ),
			'list'    => $this->queue_list( $assoc_args ),
			'retry'   => $this->queue_retry(),
			'cleanup' => $this->queue_cleanup( $assoc_args ),
			'cancel'  => $this->queue_cancel( isset( $args[1] ) ? (int) $args[1] : 0 ),
			default   => \WP_CLI::error( sprintf( 'Unknown subcommand: %s. Available: stats, list, retry, cleanup, cancel', $sub ) ),
		};
	}

	/**
	 * Reconcile entity mappings against live Odoo records.
	 *
	 * Checks whether mapped Odoo IDs still exist and reports orphans.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module identifier (e.g. crm, woocommerce).
	 *
	 * <entity_type>
	 * : Entity type (e.g. contact, product).
	 *
	 * [--fix]
	 * : Remove orphaned mappings (requires confirmation or --yes).
	 *
	 * [--yes]
	 * : Skip confirmation prompt for --fix.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo reconcile crm contact
	 *     wp wp4odoo reconcile woocommerce product --fix
	 *     wp wp4odoo reconcile woocommerce product --fix --yes
	 *
	 * @subcommand reconcile
	 * @when after_wp_load
	 */
	public function reconcile( array $args, array $assoc_args = [] ): void {
		$module_id   = $args[0] ?? '';
		$entity_type = $args[1] ?? '';

		if ( empty( $module_id ) || empty( $entity_type ) ) {
			\WP_CLI::error( 'Usage: wp wp4odoo reconcile <module> <entity_type> [--fix]' );
		}

		$module = \WP4Odoo_Plugin::instance()->get_module( $module_id );

		if ( null === $module ) {
			\WP_CLI::error( sprintf( 'Module "%s" not found.', $module_id ) );
		}

		$odoo_models = $module->get_odoo_models();

		if ( ! isset( $odoo_models[ $entity_type ] ) ) {
			\WP_CLI::error(
				sprintf(
					'Entity type "%s" not found in module "%s". Available: %s',
					$entity_type,
					$module_id,
					implode( ', ', array_keys( $odoo_models ) )
				)
			);
		}

		$fix = isset( $assoc_args['fix'] );

		if ( $fix && ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				__( 'This will permanently remove orphaned entity mappings. Continue?', 'wp4odoo' )
			);
		}

		\WP_CLI::line(
			sprintf(
				'Reconciling %s/%s against Odoo model %s%s...',
				$module_id,
				$entity_type,
				$odoo_models[ $entity_type ],
				$fix ? ' (fix mode)' : ''
			)
		);

		$settings   = \WP4Odoo_Plugin::instance()->settings();
		$logger     = new Logger( 'reconcile', $settings );
		$reconciler = new Reconciler(
			new Entity_Map_Repository(),
			fn() => $module->get_client(),
			$logger
		);

		$result = $reconciler->reconcile( $module_id, $entity_type, $odoo_models[ $entity_type ], $fix );

		\WP_CLI::line( sprintf( 'Checked: %d mapping(s)', $result['checked'] ) );
		\WP_CLI::line( sprintf( 'Orphaned: %d', count( $result['orphaned'] ) ) );

		if ( ! empty( $result['orphaned'] ) ) {
			$rows = [];
			foreach ( $result['orphaned'] as $orphan ) {
				$rows[] = [
					'wp_id'   => $orphan['wp_id'],
					'odoo_id' => $orphan['odoo_id'],
				];
			}
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'wp_id', 'odoo_id' ] );
		}

		if ( $fix ) {
			\WP_CLI::success( sprintf( '%d orphaned mapping(s) removed.', $result['fixed'] ) );
		} elseif ( ! empty( $result['orphaned'] ) ) {
			\WP_CLI::warning( 'Run with --fix to remove orphaned mappings.' );
		} else {
			\WP_CLI::success( 'No orphans found.' );
		}
	}

	/**
	 * Manage modules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo module list
	 *     wp wp4odoo module enable crm
	 *     wp wp4odoo module disable crm
	 *
	 * @subcommand module
	 * @when after_wp_load
	 */
	public function module( array $args ): void {
		$sub = $args[0] ?? 'list';

		match ( $sub ) {
			'list'    => $this->module_list(),
			'enable'  => $this->module_toggle( $args[1] ?? '', true ),
			'disable' => $this->module_toggle( $args[1] ?? '', false ),
			default   => \WP_CLI::error( sprintf( 'Unknown subcommand: %s. Available: list, enable, disable', $sub ) ),
		};
	}

	// ─── Queue helpers ──────────────────────────────────────

	/**
	 * Display queue statistics.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function queue_stats( array $assoc_args ): void {
		$stats  = Queue_Manager::get_stats();
		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI\Utils\format_items(
			$format,
			[
				[
					'pending'           => $stats['pending'],
					'processing'        => $stats['processing'],
					'completed'         => $stats['completed'],
					'failed'            => $stats['failed'],
					'last_completed_at' => $stats['last_completed_at'] ?: '—',
				],
			],
			[ 'pending', 'processing', 'completed', 'failed', 'last_completed_at' ]
		);
	}

	/**
	 * List queue jobs.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function queue_list( array $assoc_args ): void {
		$page     = max( 1, (int) ( $assoc_args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $assoc_args['per-page'] ?? 30 ) ) );
		$format   = $assoc_args['format'] ?? 'table';

		$data = $this->query_service->get_queue_jobs( $page, $per_page );

		if ( empty( $data['items'] ) ) {
			\WP_CLI::line( 'No jobs found.' );
			return;
		}

		$rows = [];
		foreach ( $data['items'] as $job ) {
			$rows[] = [
				'id'          => $job->id,
				'module'      => $job->module,
				'entity_type' => $job->entity_type,
				'direction'   => $job->direction,
				'action'      => $job->action,
				'status'      => $job->status,
				'attempts'    => $job->attempts . '/' . $job->max_attempts,
				'created_at'  => $job->created_at,
			];
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[
				'id',
				'module',
				'entity_type',
				'direction',
				'action',
				'status',
				'attempts',
				'created_at',
			]
		);

		\WP_CLI::line( sprintf( 'Page %d/%d (%d total)', $page, $data['pages'], $data['total'] ) );
	}

	/**
	 * Retry all failed jobs.
	 */
	private function queue_retry(): void {
		$count = Queue_Manager::retry_failed();
		\WP_CLI::success( sprintf( '%d failed job(s) retried.', $count ) );
	}

	/**
	 * Clean up old completed/failed jobs.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function queue_cleanup( array $assoc_args ): void {
		$days    = max( 1, (int) ( $assoc_args['days'] ?? 7 ) );
		$deleted = Queue_Manager::cleanup( $days );
		\WP_CLI::success( sprintf( '%d job(s) deleted (older than %d days).', $deleted, $days ) );
	}

	/**
	 * Cancel a pending job by ID.
	 *
	 * @param int $job_id Job ID.
	 */
	private function queue_cancel( int $job_id ): void {
		if ( $job_id <= 0 ) {
			\WP_CLI::error( 'Please provide a valid job ID. Usage: wp wp4odoo queue cancel <id>' );
		}

		if ( Queue_Manager::cancel( $job_id ) ) {
			\WP_CLI::success( sprintf( 'Job %d cancelled.', $job_id ) );
		} else {
			\WP_CLI::error( sprintf( 'Unable to cancel job %d (not found or not pending).', $job_id ) );
		}
	}

	// ─── Module helpers ─────────────────────────────────────

	/**
	 * List all modules with their status.
	 */
	private function module_list(): void {
		$plugin   = \WP4Odoo_Plugin::instance();
		$modules  = $plugin->get_modules();
		$settings = $plugin->settings();

		if ( empty( $modules ) ) {
			\WP_CLI::line( 'No modules registered.' );
			return;
		}

		$rows = [];
		foreach ( $modules as $id => $module ) {
			$rows[] = [
				'id'     => $id,
				'name'   => $module->get_name(),
				'status' => $settings->is_module_enabled( $id ) ? 'enabled' : 'disabled',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'name', 'status' ] );
	}

	/**
	 * Enable or disable a module.
	 *
	 * @param string $id      Module identifier.
	 * @param bool   $enabled True to enable, false to disable.
	 */
	private function module_toggle( string $id, bool $enabled ): void {
		if ( empty( $id ) ) {
			\WP_CLI::error( 'Please provide a module ID. Usage: wp wp4odoo module enable <id>' );
		}

		$plugin  = \WP4Odoo_Plugin::instance();
		$modules = $plugin->get_modules();
		if ( ! isset( $modules[ $id ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown module: %s. Use "wp wp4odoo module list" to see available modules.', $id ) );
		}

		// When enabling, check for mutual exclusivity conflicts.
		if ( $enabled ) {
			$registry  = $plugin->module_registry();
			$conflicts = $registry->get_conflicts( $id );
			if ( ! empty( $conflicts ) ) {
				\WP_CLI::warning(
					sprintf(
						'Module "%s" conflicts with currently enabled module(s): %s. They will be disabled.',
						$id,
						implode( ', ', $conflicts )
					)
				);
				foreach ( $conflicts as $conflict_id ) {
					$plugin->settings()->set_module_enabled( $conflict_id, false );
					\WP_CLI::line( sprintf( '  Disabled conflicting module: %s', $conflict_id ) );
				}
			}
		}

		$plugin->settings()->set_module_enabled( $id, $enabled );

		if ( $enabled ) {
			\WP_CLI::success( sprintf( 'Module "%s" enabled.', $id ) );
		} else {
			\WP_CLI::success( sprintf( 'Module "%s" disabled.', $id ) );
		}
	}
}
