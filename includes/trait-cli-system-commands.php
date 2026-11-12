<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\API\Odoo_Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI system commands: health report, database repair, circuit breakers, logs.
 *
 * Mirrors what the Health, Queue and Logs tabs of the admin UI offer, for
 * headless management and monitoring.
 *
 * @package WP4Odoo
 * @since   3.10.0
 */
trait CLI_System_Commands {

	// ─── health ───────────────────────────────────────────

	/**
	 * Print the health report (one row per check).
	 *
	 * Exits with status 1 when a check is in error (or in warning with --strict),
	 * so it can be used in monitoring scripts.
	 *
	 * @param array<string, mixed> $assoc_args Associative args (format, strict).
	 * @return void
	 */
	private function health_report( array $assoc_args ): void {
		$rows = [];
		$add  = static function ( string $check, string $status, string $detail ) use ( &$rows ): void {
			$rows[] = [
				'check'  => $check,
				'status' => $status,
				'detail' => $detail,
			];
		};

		$add( 'plugin', 'ok', sprintf( '%s (WordPress %s, PHP %s)', WP4ODOO_VERSION, get_bloginfo( 'version' ), PHP_VERSION ) );

		// Connection.
		$credentials  = Odoo_Auth::get_credentials();
		$odoo_version = (string) get_option( 'wp4odoo_odoo_version', '' );
		if ( empty( $credentials['url'] ) ) {
			$add( 'connection', 'warning', __( 'Not configured.', 'wp4odoo' ) );
		} else {
			$add(
				'connection',
				'ok',
				sprintf(
					'%s (%s%s)',
					$credentials['url'],
					$credentials['protocol'],
					'' !== $odoo_version ? ', Odoo ' . $odoo_version : ''
				)
			);
		}

		// Database tables.
		$missing = Database_Migration::missing_tables();
		if ( $missing ) {
			$add( 'database', 'error', sprintf( /* translators: %s: comma-separated table names */ __( 'Missing tables: %s. Run: wp wp4odoo db repair', 'wp4odoo' ), implode( ', ', $missing ) ) );
		} else {
			$add( 'database', 'ok', sprintf( /* translators: %d: schema version */ __( 'All tables present (schema version %d).', 'wp4odoo' ), Database_Migration::get_schema_version() ) );
		}

		// Queue.
		if ( ! $missing ) {
			$stats   = Queue_Manager::get_stats();
			$metrics = Queue_Manager::get_health_metrics();
			$rate    = $metrics['success_rate'];
			$status  = ( $rate < 90.0 || (int) $stats['failed'] > 0 ) ? 'warning' : 'ok';
			$add(
				'queue',
				$status,
				sprintf(
					/* translators: 1: pending, 2: processing, 3: failed, 4: success rate */
					__( '%1$d pending, %2$d processing, %3$d failed, success rate %4$.1f%%', 'wp4odoo' ),
					(int) $stats['pending'],
					(int) $stats['processing'],
					(int) $stats['failed'],
					$rate
				)
			);
		}

		// Circuit breakers.
		$cb_state    = get_option( Circuit_Breaker::OPT_CB_STATE, [] );
		$module_cb   = new Module_Circuit_Breaker( Logger::for_channel( 'cli', \WP4Odoo_Plugin::instance()->settings() ) );
		$open_module = array_keys( $module_cb->get_open_modules() );
		if ( is_array( $cb_state ) && ! empty( $cb_state['opened_at'] ) ) {
			$add( 'circuit-breaker', 'error', __( 'Open: synchronization is paused after repeated failures. Run: wp wp4odoo breaker reset', 'wp4odoo' ) );
		} elseif ( $open_module ) {
			$add( 'circuit-breaker', 'warning', sprintf( /* translators: %s: module IDs */ __( 'Open for: %s', 'wp4odoo' ), implode( ', ', $open_module ) ) );
		} else {
			$add( 'circuit-breaker', 'ok', __( 'Closed.', 'wp4odoo' ) );
		}

		// Cron.
		$next         = wp_next_scheduled( 'wp4odoo_scheduled_sync' );
		$cron_warning = \WP4Odoo_Plugin::instance()->settings()->get_cron_warning();
		if ( ! $next ) {
			$add( 'cron', 'warning', __( 'The sync event is not scheduled.', 'wp4odoo' ) );
		} elseif ( '' !== $cron_warning ) {
			$add( 'cron', 'warning', $cron_warning );
		} else {
			$add( 'cron', 'ok', sprintf( /* translators: %s: human time difference */ __( 'Next run in %s.', 'wp4odoo' ), human_time_diff( time(), max( time(), (int) $next ) ) ) );
		}

		// Plugin versions outside the tested range.
		$warnings = \WP4Odoo_Plugin::instance()->module_registry()->get_version_warnings();
		if ( $warnings ) {
			$add( 'compatibility', 'warning', sprintf( /* translators: %s: module IDs */ __( 'Plugins outside their tested version range: %s', 'wp4odoo' ), implode( ', ', array_keys( $warnings ) ) ) );
		} else {
			$add( 'compatibility', 'ok', __( 'All detected plugins are within their tested range.', 'wp4odoo' ) );
		}

		// Fields left out of pushes because the Odoo version lacks them.
		$drops = Schema_Drop_Log::all();
		if ( $drops ) {
			$add( 'skipped-fields', 'warning', sprintf( /* translators: 1: number of fields, 2: field names */ __( '%1$d field(s) not sent to Odoo: %2$s', 'wp4odoo' ), count( $drops ), implode( ', ', array_slice( array_keys( $drops ), 0, 5 ) ) ) );
		} else {
			$add( 'skipped-fields', 'ok', __( 'None.', 'wp4odoo' ) );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'check', 'status', 'detail' ] );

		$statuses = array_column( $rows, 'status' );
		if ( in_array( 'error', $statuses, true ) || ( isset( $assoc_args['strict'] ) && in_array( 'warning', $statuses, true ) ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	// ─── db ───────────────────────────────────────────────

	/**
	 * Show the state of the plugin's database tables.
	 *
	 * @return void
	 */
	private function db_status(): void {
		global $wpdb;

		$missing = Database_Migration::missing_tables();
		$rows    = [];
		foreach ( [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map', 'wp4odoo_logs' ] as $table ) {
			$rows[] = [
				'table'  => $wpdb->prefix . $table,
				'status' => in_array( $table, $missing, true ) ? 'missing' : 'present',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'table', 'status' ] );

		/* translators: 1: schema version, 2: plugin version stored in the database */
		\WP_CLI::line( sprintf( __( 'Schema version: %1$d — stored plugin version: %2$s', 'wp4odoo' ), Database_Migration::get_schema_version(), (string) get_option( Settings_Repository::OPT_DB_VERSION, '-' ) ) );

		if ( $missing ) {
			\WP_CLI::warning( __( 'Some tables are missing. Run: wp wp4odoo db repair', 'wp4odoo' ) );
		}
	}

	/**
	 * Create missing tables and apply pending migrations (safe to run repeatedly).
	 *
	 * @return void
	 */
	private function db_repair(): void {
		global $wpdb;

		Database_Migration::create_tables();
		delete_option( Database_Migration::OPT_DB_ERROR );
		delete_transient( 'wp4odoo_tables_ok' );

		$missing = Database_Migration::missing_tables();
		if ( $missing ) {
			/* translators: 1: table names, 2: database error */
			\WP_CLI::error( sprintf( __( 'Could not create: %1$s. Database error: %2$s', 'wp4odoo' ), implode( ', ', $missing ), (string) $wpdb->last_error ) );
			return;
		}

		\WP_CLI::success( __( 'All tables are present and the schema is up to date.', 'wp4odoo' ) );
	}

	// ─── breaker ──────────────────────────────────────────

	/**
	 * Show or reset the circuit breakers.
	 *
	 * @param array<int, string> $args Positional args: status|reset [module].
	 * @return void
	 */
	private function breaker_run( array $args ): void {
		$sub      = $args[0] ?? 'status';
		$settings = \WP4Odoo_Plugin::instance()->settings();
		$logger   = Logger::for_channel( 'cli', $settings );
		$module   = new Module_Circuit_Breaker( $logger );

		if ( 'status' === $sub ) {
			$state = get_option( Circuit_Breaker::OPT_CB_STATE, [] );
			$open  = is_array( $state ) && ! empty( $state['opened_at'] );
			\WP_CLI::line( $open ? __( 'Global circuit breaker: OPEN', 'wp4odoo' ) : __( 'Global circuit breaker: closed', 'wp4odoo' ) );

			$rows = [];
			foreach ( $module->get_open_modules() as $id => $module_state ) {
				$rows[] = [
					'module'    => $id,
					'failures'  => $module_state['failures'],
					'opened_at' => gmdate( 'Y-m-d H:i:s', $module_state['opened_at'] ),
				];
			}
			if ( $rows ) {
				\WP_CLI\Utils\format_items( 'table', $rows, [ 'module', 'failures', 'opened_at' ] );
			} else {
				\WP_CLI::line( __( 'No module circuit breaker is open.', 'wp4odoo' ) );
			}
			return;
		}

		if ( 'reset' === $sub ) {
			$target = isset( $args[1] ) ? sanitize_key( $args[1] ) : '';
			if ( '' !== $target ) {
				$module->reset_module( $target );
				/* translators: %s: module ID */
				\WP_CLI::success( sprintf( __( 'Circuit breaker reset for module "%s".', 'wp4odoo' ), $target ) );
				return;
			}

			( new Circuit_Breaker( $logger ) )->record_success();
			foreach ( array_keys( $module->get_open_modules() ) as $id ) {
				$module->reset_module( (string) $id );
			}
			\WP_CLI::success( __( 'All circuit breakers reset.', 'wp4odoo' ) );
			return;
		}

		/* translators: %s: subcommand name */
		\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: status, reset', 'wp4odoo' ), $sub ) );
	}

	// ─── logs ─────────────────────────────────────────────

	/**
	 * List or purge the plugin logs.
	 *
	 * @param array<int, string>   $args       Positional args: list|purge.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	private function logs_run( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'list';

		if ( 'list' === $sub ) {
			$filters  = [
				'level'     => isset( $assoc_args['level'] ) ? sanitize_key( $assoc_args['level'] ) : '',
				'module'    => isset( $assoc_args['module'] ) ? sanitize_key( $assoc_args['module'] ) : '',
				'date_from' => isset( $assoc_args['since'] ) ? sanitize_text_field( $assoc_args['since'] ) : '',
				'date_to'   => isset( $assoc_args['until'] ) ? sanitize_text_field( $assoc_args['until'] ) : '',
			];
			$page     = max( 1, (int) ( $assoc_args['page'] ?? 1 ) );
			$per_page = max( 1, min( 500, (int) ( $assoc_args['per-page'] ?? 30 ) ) );

			$data = $this->query_service->get_log_entries( $filters, $page, $per_page );
			$rows = [];
			foreach ( $data['items'] as $item ) {
				$rows[] = [
					'time'    => $item->created_at,
					'level'   => $item->level,
					'module'  => (string) $item->module,
					'message' => $item->message,
				];
			}

			\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'time', 'level', 'module', 'message' ] );
			/* translators: 1: current page, 2: total pages, 3: total entries */
			\WP_CLI::line( sprintf( __( 'Page %1$d of %2$d (%3$d entries)', 'wp4odoo' ), $data['page'], max( 1, $data['pages'] ), $data['total'] ) );
			return;
		}

		if ( 'purge' === $sub ) {
			if ( isset( $assoc_args['all'] ) ) {
				\WP_CLI::confirm( __( 'This deletes ALL log entries of this site. Continue?', 'wp4odoo' ), $assoc_args );
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wp4odoo_logs WHERE blog_id = %d", (int) get_current_blog_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$deleted = Logger::for_channel( 'cli', \WP4Odoo_Plugin::instance()->settings() )->cleanup();
			}

			/* translators: %d: number of log entries deleted */
			\WP_CLI::success( sprintf( __( '%d log entry(ies) deleted.', 'wp4odoo' ), $deleted ) );
			return;
		}

		/* translators: %s: subcommand name */
		\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: list, purge', 'wp4odoo' ), $sub ) );
	}
}
