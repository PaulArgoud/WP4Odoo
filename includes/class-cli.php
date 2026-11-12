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
	use CLI_System_Commands;
	use CLI_Config_Commands;

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

			if ( ! empty( $result['version'] ) ) {
				Odoo_Auth::remember_server_version( (string) $result['version'] );
				$hint = Odoo_Auth::protocol_hint( (string) $result['version'], (string) $credentials['protocol'] );
				if ( '' !== $hint ) {
					\WP_CLI::line( $hint );
				}
			}
		} else {
			/* translators: %s: error message */
			\WP_CLI::error( sprintf( __( 'Connection failed: %s', 'wp4odoo' ), $result['message'] ) );
		}
	}

	/**
	 * Run sync queue processing, or bulk-queue products.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : run (default), export-products or import-products.
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
	 *     wp wp4odoo sync export-products --yes   # queue every WooCommerce product for Odoo
	 *     wp wp4odoo sync import-products --yes   # queue every Odoo product for WooCommerce
	 *
	 * @subcommand sync
	 * @when after_wp_load
	 */
	public function sync( array $args, array $assoc_args = [] ): void {
		$switched = $this->maybe_switch_blog( $assoc_args );

		try {
			$sub = $args[0] ?? 'run';

			if ( 'export-products' === $sub || 'import-products' === $sub ) {
				$this->bulk_products( 'export-products' === $sub ? 'export' : 'import', $assoc_args );
				return;
			}

			if ( 'run' !== $sub ) {
				/* translators: %s: subcommand name */
				\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: run, export-products, import-products', 'wp4odoo' ), $sub ) );
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

			if ( $dry_run ) {
				/* translators: %d: number of jobs */
				\WP_CLI::success( sprintf( __( '%d job(s) would be processed (dry-run).', 'wp4odoo' ), $processed ) );
			} else {
				/* translators: %d: number of jobs */
				\WP_CLI::success( sprintf( __( '%d job(s) processed.', 'wp4odoo' ), $processed ) );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
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
		$switched = $this->maybe_switch_blog( $assoc_args );

		try {
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

			if ( $fix ) {
				/* translators: %d: number of orphaned mappings removed */
				\WP_CLI::success( sprintf( __( '%d orphaned mapping(s) removed.', 'wp4odoo' ), $result['fixed'] ) );
			} elseif ( ! empty( $result['orphaned'] ) ) {
				\WP_CLI::warning( __( 'Run with --fix to remove orphaned mappings.', 'wp4odoo' ) );
			} else {
				\WP_CLI::success( __( 'No orphans found.', 'wp4odoo' ) );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Clean up orphaned entity map entries.
	 *
	 * Removes entity_map rows where the WordPress post no longer exists.
	 * This can happen when delete sync jobs fail permanently, leaving
	 * stale mappings that will never be resolved.
	 *
	 * User-based modules (BuddyBoss, FluentCRM, etc.) are automatically
	 * excluded since their wp_id references the users table, not posts.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : orphans (default).
	 *
	 * [--module=<module>]
	 * : Only clean up a specific module (e.g. crm, woocommerce).
	 *
	 * [--dry-run]
	 * : List orphans without deleting them.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * [--blog_id=<id>]
	 * : Target a specific site in multisite (switches blog context).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo cleanup orphans --dry-run
	 *     wp wp4odoo cleanup orphans --module=crm
	 *     wp wp4odoo cleanup orphans --yes
	 *     wp wp4odoo cleanup orphans --blog_id=3
	 *
	 * @subcommand cleanup
	 * @when after_wp_load
	 *
	 * @since 3.6.0
	 */
	public function cleanup( array $args, array $assoc_args = [] ): void {
		$sub = $args[0] ?? 'orphans';

		if ( 'orphans' !== $sub ) {
			/* translators: %s: subcommand name */
			\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Usage: wp wp4odoo cleanup orphans', 'wp4odoo' ), $sub ) );
		}

		$switched = $this->maybe_switch_blog( $assoc_args );

		try {
			$dry_run = isset( $assoc_args['dry-run'] );
			$module  = isset( $assoc_args['module'] ) ? sanitize_key( $assoc_args['module'] ) : null;

			if ( ! $dry_run ) {
				\WP_CLI::confirm(
					__( 'This will permanently remove orphaned entity map entries. Continue?', 'wp4odoo' ),
					$assoc_args
				);
			}

			$entity_map = new Entity_Map_Repository();

			if ( $dry_run ) {
				/* translators: %s: module name or "all modules" */
				\WP_CLI::line( sprintf( __( 'Scanning for orphaned mappings (%s, dry-run)...', 'wp4odoo' ), $module ?? __( 'all modules', 'wp4odoo' ) ) );
			} else {
				/* translators: %s: module name or "all modules" */
				\WP_CLI::line( sprintf( __( 'Cleaning up orphaned mappings (%s)...', 'wp4odoo' ), $module ?? __( 'all modules', 'wp4odoo' ) ) );
			}

			$result = $entity_map->cleanup_orphans( $module, $dry_run );

			if ( 0 === $result['found'] ) {
				\WP_CLI::success( __( 'No orphaned mappings found.', 'wp4odoo' ) );
			} else {
				/* translators: %d: number of orphaned mappings found */
				\WP_CLI::line( sprintf( __( 'Found %d orphaned mapping(s):', 'wp4odoo' ), $result['found'] ) );

				$rows = [];
				foreach ( $result['details'] as $key => $count ) {
					[ $mod, $type ] = explode( ':', $key, 2 );
					$rows[]         = [
						'module'      => $mod,
						'entity_type' => $type,
						'orphans'     => $count,
					];
				}
				\WP_CLI\Utils\format_items( 'table', $rows, [ 'module', 'entity_type', 'orphans' ] );

				if ( $dry_run ) {
					\WP_CLI::warning( __( 'Run without --dry-run to remove orphaned mappings.', 'wp4odoo' ) );
				} else {
					/* translators: %d: number of orphaned mappings removed */
					\WP_CLI::success( sprintf( __( '%d orphaned mapping(s) removed.', 'wp4odoo' ), $result['removed'] ) );
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Manage caches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo cache flush
	 *
	 * @subcommand cache
	 * @when after_wp_load
	 *
	 * @since 3.7.0
	 */
	public function cache( array $args ): void {
		$sub = $args[0] ?? 'flush';

		match ( $sub ) {
			'flush'  => $this->cache_flush(),
			/* translators: %s: subcommand name */
			default  => \WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: flush', 'wp4odoo' ), $sub ) ),
		};
	}

	/**
	 * Flush the Odoo schema cache (memory + transients).
	 *
	 * @return void
	 */
	private function cache_flush(): void {
		$deleted = Schema_Cache::flush_all();
		I18n\Translation_Service::flush_caches();
		/* translators: %d: number of transients deleted */
		\WP_CLI::success( sprintf( __( 'All caches flushed (%d schema transient(s) deleted).', 'wp4odoo' ), $deleted ) );
	}

	/**
	 * Manage modules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo module list
	 *     wp wp4odoo module enable crm
	 *     wp wp4odoo module disable crm
	 *     wp wp4odoo module info woocommerce
	 *     wp wp4odoo module settings woocommerce
	 *     wp wp4odoo module set woocommerce sync_stock false
	 *
	 * @subcommand module
	 * @when after_wp_load
	 */
	public function module( array $args ): void {
		$sub = $args[0] ?? 'list';

		match ( $sub ) {
			'list'     => $this->module_list(),
			'enable'   => $this->module_toggle( $args[1] ?? '', true ),
			'disable'  => $this->module_toggle( $args[1] ?? '', false ),
			'info'     => $this->module_info( $args[1] ?? '' ),
			'settings' => $this->module_settings( $args[1] ?? '' ),
			'set'      => $this->module_set( $args[1] ?? '', $args[2] ?? '', $args[3] ?? '' ),
			/* translators: %s: subcommand name */
			default    => \WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: list, enable, disable, info, settings, set', 'wp4odoo' ), $sub ) ),
		};
	}

	/**
	 * Print the health report: connection, database, queue, circuit breakers, cron, compatibility.
	 *
	 * Exits with status 1 when a check is in error, so it can be used for monitoring.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--strict]
	 * : Also exit with status 1 when a check is in warning.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo health
	 *     wp wp4odoo health --format=json --strict
	 *
	 * @subcommand health
	 * @when after_wp_load
	 *
	 * @since 3.10.0
	 */
	public function health( array $args, array $assoc_args = [] ): void {
		$this->health_report( $assoc_args );
	}

	/**
	 * Inspect or repair the plugin's database tables.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo db status
	 *     wp wp4odoo db repair
	 *
	 * @subcommand db
	 * @when after_wp_load
	 *
	 * @since 3.10.0
	 */
	public function db( array $args ): void {
		$sub = $args[0] ?? 'status';

		match ( $sub ) {
			'status' => $this->db_status(),
			'repair' => $this->db_repair(),
			/* translators: %s: subcommand name */
			default  => \WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: status, repair', 'wp4odoo' ), $sub ) ),
		};
	}

	/**
	 * Show or reset the circuit breakers (global and per module).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo breaker status
	 *     wp wp4odoo breaker reset
	 *     wp wp4odoo breaker reset woocommerce
	 *
	 * @subcommand breaker
	 * @when after_wp_load
	 *
	 * @since 3.10.0
	 */
	public function breaker( array $args ): void {
		$this->breaker_run( $args );
	}

	/**
	 * List or purge the plugin logs.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : list (default) or purge.
	 *
	 * [--level=<level>]
	 * : Only entries of this level (debug, info, warning, error, critical).
	 *
	 * [--module=<module>]
	 * : Only entries of this module.
	 *
	 * [--since=<date>]
	 * : Only entries from this date (Y-m-d).
	 *
	 * [--until=<date>]
	 * : Only entries up to this date (Y-m-d).
	 *
	 * [--page=<n>] [--per-page=<n>]
	 * : Pagination (default 30 per page, max 500).
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv or yaml.
	 *
	 * [--all]
	 * : With "purge": delete every entry instead of applying the retention period.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt of "purge --all".
	 *
	 * [--blog_id=<id>]
	 * : Target a specific site in multisite.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo logs list --level=error --since=2026-11-01
	 *     wp wp4odoo logs list --module=woocommerce --format=json
	 *     wp wp4odoo logs purge
	 *     wp wp4odoo logs purge --all --yes
	 *
	 * @subcommand logs
	 * @when after_wp_load
	 *
	 * @since 3.10.0
	 */
	public function logs( array $args, array $assoc_args = [] ): void {
		$switched = $this->maybe_switch_blog( $assoc_args );

		try {
			$this->logs_run( $args, $assoc_args );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Show or change the Odoo connection settings.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : show (default) or set.
	 *
	 * [--odoo-url=<url>]
	 * : Odoo URL (not --url, which is WP-CLI's own option for the WordPress site).
	 *
	 * [--database=<name>]
	 * : Odoo database.
	 *
	 * [--username=<login>]
	 * : Odoo login.
	 *
	 * [--api-key=<key>]
	 * : Odoo API key (stored encrypted). Kept unchanged when omitted.
	 *
	 * [--protocol=<protocol>]
	 * : jsonrpc, json2 (Odoo 19+) or xmlrpc.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout (5-120).
	 *
	 * [--company-id=<id>]
	 * : Odoo company (res.company) to scope API calls to; 0 for the user's default company.
	 *
	 * [--allow-private-url]
	 * : Accept a private or local URL (refused by default).
	 *
	 * [--test]
	 * : Test the connection after saving.
	 *
	 * [--format=<format>]
	 * : Output format for "show".
	 *
	 * [--blog_id=<id>]
	 * : Target a specific site in multisite.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo connection show
	 *     wp wp4odoo connection set --odoo-url=https://erp.example.com --database=prod --username=api@example.com --api-key=xxxx --protocol=json2 --test
	 *     wp wp4odoo connection set --company-id=3
	 *
	 * @subcommand connection
	 * @when after_wp_load
	 *
	 * @since 3.10.0
	 */
	public function connection( array $args, array $assoc_args = [] ): void {
		$switched = $this->maybe_switch_blog( $assoc_args );

		try {
			$this->connection_run( $args, $assoc_args );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Show or rotate the webhook token used by Odoo to call this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp4odoo webhook show
	 *     wp wp4odoo webhook rotate --yes
	 *
	 * @subcommand webhook
	 * @when after_wp_load
	 *
	 * @since 3.10.0
	 */
	public function webhook( array $args, array $assoc_args = [] ): void {
		$this->webhook_run( $args, $assoc_args );
	}
}
