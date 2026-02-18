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

	use CLI_Queue_Commands;
	use CLI_Module_Commands;

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
	 * Switch to the specified blog in multisite.
	 *
	 * Call before operations that should target a specific site.
	 * Returns true if a switch was made (caller should call
	 * restore_current_blog() after the operation).
	 *
	 * @param array<string, mixed> $assoc_args Associative args from WP-CLI.
	 * @return bool True if switch_to_blog() was called.
	 */
	private function maybe_switch_blog( array $assoc_args ): bool {
		if ( ! is_multisite() || ! isset( $assoc_args['blog_id'] ) ) {
			return false;
		}

		$blog_id = absint( $assoc_args['blog_id'] );
		if ( 0 === $blog_id ) {
			\WP_CLI::error( __( '--blog_id must be a positive integer.', 'wp4odoo' ) );
		}

		if ( ! get_blog_details( $blog_id ) ) {
			/* translators: %d: blog ID */
			\WP_CLI::error( sprintf( __( 'Blog ID %d does not exist.', 'wp4odoo' ), $blog_id ) );
		}

		switch_to_blog( $blog_id );
		// Flush API credentials cache so they re-read from the target site's options.
		Odoo_Auth::flush_credentials_cache();
		// Flush WP object cache to avoid stale data from the previous blog
		// leaking into Entity_Map_Repository, Settings_Repository, or Logger
		// (all of which use get_option() / object cache under the hood).
		wp_cache_flush();

		return true;
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
		/* translators: %s: plugin version number */
		\WP_CLI::line( sprintf( __( 'WordPress For Odoo v%s', 'wp4odoo' ), WP4ODOO_VERSION ) );
		\WP_CLI::line( str_repeat( '─', 40 ) );

		// Connection.
		if ( $connected ) {
			\WP_CLI::success(
				sprintf(
					/* translators: 1: Odoo URL, 2: database name, 3: protocol */
					__( 'Connected to %1$s (db: %2$s, protocol: %3$s)', 'wp4odoo' ),
					$credentials['url'],
					$credentials['database'],
					$credentials['protocol']
				)
			);
		} else {
			\WP_CLI::warning( __( 'Not configured — no Odoo URL set.', 'wp4odoo' ) );
		}

		// Queue stats.
		$stats = Queue_Manager::get_stats();
		\WP_CLI::line( '' );
		\WP_CLI::line( __( 'Queue:', 'wp4odoo' ) );
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
			/* translators: %s: timestamp of last completed sync */
			\WP_CLI::line( sprintf( __( 'Last completed: %s', 'wp4odoo' ), $stats['last_completed_at'] ) );
		}

		// Modules.
		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		\WP_CLI::line( '' );
		\WP_CLI::line( __( 'Modules:', 'wp4odoo' ) );
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
			\WP_CLI::error( __( 'No Odoo connection configured. Go to Odoo Connector settings first.', 'wp4odoo' ) );
		}

		/* translators: %s: Odoo server URL */
		\WP_CLI::line( sprintf( __( 'Testing connection to %s...', 'wp4odoo' ), $credentials['url'] ) );

		$result = Odoo_Auth::test_connection(
			$credentials['url'],
			$credentials['database'],
			$credentials['username'],
			$credentials['api_key'],
			$credentials['protocol']
		);

		if ( $result['success'] ) {
			/* translators: %d: Odoo user ID */
			\WP_CLI::success( sprintf( __( 'Connection successful! UID: %d', 'wp4odoo' ), $result['uid'] ?? 0 ) );
		} else {
			/* translators: %s: error message */
			\WP_CLI::error( sprintf( __( 'Connection failed: %s', 'wp4odoo' ), $result['message'] ) );
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
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * [--blog_id=<id>]
	 * : Target a specific site in multisite (switches blog context).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo sync run
	 *     wp wp4odoo sync run --dry-run
	 *     wp wp4odoo sync run --yes
	 *     wp wp4odoo sync run --blog_id=3
	 *
	 * @subcommand sync
	 * @when after_wp_load
	 */
	public function sync( array $args, array $assoc_args = [] ): void {
		$switched = $this->maybe_switch_blog( $assoc_args );
		$sub      = $args[0] ?? 'run';

		if ( 'run' !== $sub ) {
			if ( $switched ) {
				restore_current_blog();
			}
			/* translators: %s: subcommand name */
			\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Usage: wp wp4odoo sync run', 'wp4odoo' ), $sub ) );
		}

		$dry_run = isset( $assoc_args['dry-run'] );

		\WP_CLI::warning(
			__( 'Back up your WordPress and Odoo databases before running sync operations.', 'wp4odoo' )
		);

		if ( ! $dry_run ) {
			\WP_CLI::confirm(
				__( 'Process the sync queue now?', 'wp4odoo' ),
				$assoc_args
			);
		}

		if ( $dry_run ) {
			\WP_CLI::line( __( 'Processing sync queue (dry-run mode)...', 'wp4odoo' ) );
		} else {
			\WP_CLI::line( __( 'Processing sync queue...', 'wp4odoo' ) );
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

		if ( $switched ) {
			restore_current_blog();
		}

		if ( $dry_run ) {
			/* translators: %d: number of jobs */
			\WP_CLI::success( sprintf( __( '%d job(s) would be processed (dry-run).', 'wp4odoo' ), $processed ) );
		} else {
			/* translators: %d: number of jobs */
			\WP_CLI::success( sprintf( __( '%d job(s) processed.', 'wp4odoo' ), $processed ) );
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
			'retry'   => $this->queue_retry( $assoc_args ),
			'cleanup' => $this->queue_cleanup( $assoc_args ),
			'cancel'  => $this->queue_cancel( isset( $args[1] ) ? (int) $args[1] : 0 ),
			/* translators: %s: subcommand name */
			default   => \WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: stats, list, retry, cleanup, cancel', 'wp4odoo' ), $sub ) ),
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
	 * [--blog_id=<id>]
	 * : Target a specific site in multisite (switches blog context).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo reconcile crm contact
	 *     wp wp4odoo reconcile woocommerce product --fix
	 *     wp wp4odoo reconcile woocommerce product --fix --yes
	 *     wp wp4odoo reconcile crm contact --blog_id=3
	 *
	 * @subcommand reconcile
	 * @when after_wp_load
	 */
	public function reconcile( array $args, array $assoc_args = [] ): void {
		$switched    = $this->maybe_switch_blog( $assoc_args );
		$module_id   = $args[0] ?? '';
		$entity_type = $args[1] ?? '';

		if ( empty( $module_id ) || empty( $entity_type ) ) {
			\WP_CLI::error( __( 'Usage: wp wp4odoo reconcile <module> <entity_type> [--fix]', 'wp4odoo' ) );
		}

		$module = \WP4Odoo_Plugin::instance()->get_module( $module_id );

		if ( null === $module ) {
			/* translators: %s: module identifier */
			\WP_CLI::error( sprintf( __( 'Module "%s" not found.', 'wp4odoo' ), $module_id ) );
		}

		$odoo_models = $module->get_odoo_models();

		if ( ! isset( $odoo_models[ $entity_type ] ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: entity type, 2: module identifier, 3: available entity types */
					__( 'Entity type "%1$s" not found in module "%2$s". Available: %3$s', 'wp4odoo' ),
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
				/* translators: 1: module identifier, 2: entity type, 3: Odoo model name, 4: fix mode indicator */
				__( 'Reconciling %1$s/%2$s against Odoo model %3$s%4$s...', 'wp4odoo' ),
				$module_id,
				$entity_type,
				$odoo_models[ $entity_type ],
				$fix ? ' (fix mode)' : ''
			)
		);

		$settings   = \WP4Odoo_Plugin::instance()->settings();
		$logger     = Logger::for_channel( 'reconcile', $settings );
		$reconciler = new Reconciler(
			new Entity_Map_Repository(),
			fn() => $module->get_client(),
			$logger
		);

		$result = $reconciler->reconcile( $module_id, $entity_type, $odoo_models[ $entity_type ], $fix );

		/* translators: %d: number of mappings checked */
		\WP_CLI::line( sprintf( __( 'Checked: %d mapping(s)', 'wp4odoo' ), $result['checked'] ) );
		/* translators: %d: number of orphaned mappings */
		\WP_CLI::line( sprintf( __( 'Orphaned: %d', 'wp4odoo' ), count( $result['orphaned'] ) ) );

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

		if ( $switched ) {
			restore_current_blog();
		}

		if ( $fix ) {
			/* translators: %d: number of orphaned mappings removed */
			\WP_CLI::success( sprintf( __( '%d orphaned mapping(s) removed.', 'wp4odoo' ), $result['fixed'] ) );
		} elseif ( ! empty( $result['orphaned'] ) ) {
			\WP_CLI::warning( __( 'Run with --fix to remove orphaned mappings.', 'wp4odoo' ) );
		} else {
			\WP_CLI::success( __( 'No orphans found.', 'wp4odoo' ) );
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
			/* translators: %s: subcommand name */
			default   => \WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: list, enable, disable', 'wp4odoo' ), $sub ) ),
		};
	}
}
