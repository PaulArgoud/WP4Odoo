<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Database_Migration;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Database_Migration.
 *
 * Verifies the migration runner logic (versioning, sequencing,
 * error handling) using the global test option store.
 */
class DatabaseMigrationTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		// Reset schema version to 0 so all migrations are pending.
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 0;
	}

	// ─── Schema version ────────────────────────────────────

	public function test_get_schema_version_returns_zero_by_default(): void {
		unset( $GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] );

		$this->assertSame( 0, Database_Migration::get_schema_version() );
	}

	public function test_get_schema_version_returns_stored_value(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 3;

		$this->assertSame( 3, Database_Migration::get_schema_version() );
	}

	// ─── run_migrations() ──────────────────────────────────

	public function test_run_migrations_applies_all_from_zero(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 0;

		// Stub: SHOW COLUMNS returns minimal set (no correlation_id, no last_polled_at).
		$this->wpdb->get_col_return     = [ 'id', 'module', 'entity_type' ];
		$this->wpdb->get_results_return = []; // SHOW INDEX returns no indexes.

		$applied = Database_Migration::run_migrations();

		$this->assertSame( 10, $applied );
		$this->assertSame( 10, Database_Migration::get_schema_version() );
	}

	public function test_run_migrations_skips_already_applied(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 5;

		// Migrations 6–10 should run.
		$this->wpdb->get_col_return     = [ 'id', 'module', 'entity_type' ];
		$this->wpdb->get_results_return = [];

		$applied = Database_Migration::run_migrations();

		$this->assertSame( 5, $applied );
		$this->assertSame( 10, Database_Migration::get_schema_version() );
	}

	public function test_run_migrations_returns_zero_when_up_to_date(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 10;

		$applied = Database_Migration::run_migrations();

		$this->assertSame( 0, $applied );
	}

	// ─── Migration content verification ────────────────────

	public function test_migration_1_adds_correlation_id_columns(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 0;

		// Columns without correlation_id → migration should ALTER TABLE.
		$this->wpdb->get_col_return     = [ 'id', 'module', 'entity_type' ];
		$this->wpdb->get_results_return = [];

		Database_Migration::run_migrations();

		$query_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => 'query' === $c['method'] )
		);

		$has_alter = false;
		foreach ( $query_calls as $c ) {
			if ( str_contains( $c['args'][0], 'correlation_id' ) ) {
				$has_alter = true;
				break;
			}
		}

		$this->assertTrue( $has_alter, 'Migration 1 should add correlation_id column.' );
	}

	public function test_migration_6_adds_last_polled_at_column(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 5;

		// Entity_map columns without last_polled_at.
		$this->wpdb->get_col_return = [ 'id', 'module', 'entity_type', 'wp_id', 'odoo_id' ];

		Database_Migration::run_migrations();

		$query_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => 'query' === $c['method'] )
		);

		$has_polled = false;
		$has_index  = false;
		foreach ( $query_calls as $c ) {
			if ( str_contains( $c['args'][0], 'last_polled_at' ) && str_contains( $c['args'][0], 'ADD COLUMN' ) ) {
				$has_polled = true;
			}
			if ( str_contains( $c['args'][0], 'idx_poll_detection' ) ) {
				$has_index = true;
			}
		}

		$this->assertTrue( $has_polled, 'Migration 6 should add last_polled_at column.' );
		$this->assertTrue( $has_index, 'Migration 6 should add idx_poll_detection index.' );
	}

	public function test_migration_6_is_idempotent(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 5;

		// Column already exists (including blog_id for migration 7 idempotency).
		$this->wpdb->get_col_return = [ 'id', 'blog_id', 'module', 'entity_type', 'last_polled_at' ];
		// Indexes already exist (for migrations 8–10 idempotency).
		$this->wpdb->get_results_return = [
			(object) [ 'Key_name' => 'idx_stale_recovery', 'Column_name' => 'blog_id' ],
			(object) [ 'Key_name' => 'idx_dedup_odoo', 'Column_name' => 'blog_id' ],
			(object) [ 'Key_name' => 'idx_poll_detection', 'Column_name' => 'blog_id' ],
			(object) [ 'Key_name' => 'idx_blog_cleanup', 'Column_name' => 'blog_id' ],
		];

		Database_Migration::run_migrations();

		$query_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => 'query' === $c['method'] )
		);

		// No ADD COLUMN should be issued since columns already exist.
		// (Migration 9 issues DROP KEY + ADD KEY for index rebuilds — those are expected.)
		$add_column_calls = array_filter(
			$query_calls,
			fn( $c ) => str_contains( $c['args'][0], 'ADD COLUMN' )
		);

		$this->assertEmpty( $add_column_calls, 'Migrations 6-7 should skip ADD COLUMN when columns exist.' );
	}

	// ─── get_migrations() coverage ─────────────────────────

	public function test_migrations_are_sequential_from_1_to_10(): void {
		$method     = new \ReflectionMethod( Database_Migration::class, 'get_migrations' );
		$migrations = $method->invoke( null );

		$this->assertCount( 10, $migrations );
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertArrayHasKey( $i, $migrations, "Migration $i should exist." );
			// Callbacks are [class, 'migration_N'] arrays pointing to private static methods.
			$this->assertIsArray( $migrations[ $i ] );
			$this->assertSame( Database_Migration::class, $migrations[ $i ][0] );
			$this->assertSame( "migration_{$i}", $migrations[ $i ][1] );
		}
	}

	// ─── ensure_tables() self-repair (GitHub issue #4) ─────

	/**
	 * A $wpdb whose SHOW TABLES LIKE answers from a list of existing tables.
	 *
	 * @param array<int, string> $existing Table names (with prefix) that exist.
	 */
	private function wpdb_with_tables( array $existing ): \WP_DB_Stub {
		$db = new class() extends \WP_DB_Stub {
			/** @var array<int, string> */
			public array $existing = [];
			private string $last_like = '';

			public function prepare( string $query, ...$args ): string {
				$this->last_like = (string) ( $args[0] ?? '' );
				return $query;
			}

			public function get_var( $query ) {
				return in_array( $this->last_like, $this->existing, true ) ? $this->last_like : null;
			}
		};
		$db->existing = $existing;

		return $db;
	}

	private function all_tables(): array {
		return [ 'wp_wp4odoo_sync_queue', 'wp_wp4odoo_entity_map', 'wp_wp4odoo_logs' ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] );
		$GLOBALS['_wp_is_admin']   = false;
		$GLOBALS['_wp_transients'] = [];
		unset( $GLOBALS['_wp_options'][ \WP4Odoo\Settings_Repository::OPT_DB_VERSION ], $GLOBALS['_wp_options'][ Database_Migration::OPT_DB_ERROR ] );
	}

	public function test_missing_tables_lists_what_does_not_exist(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_tables( [ 'wp_wp4odoo_logs' ] );

		$this->assertSame( [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map' ], Database_Migration::missing_tables() );
	}

	public function test_ensure_tables_does_nothing_when_current_and_not_in_admin(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_tables( [] );
		$GLOBALS['_wp_options'][ \WP4Odoo\Settings_Repository::OPT_DB_VERSION ] = WP4ODOO_VERSION;
		$created = false;

		$this->assertTrue( Database_Migration::ensure_tables( function () use ( &$created ) { $created = true; } ) );
		$this->assertFalse( $created, 'Front-end requests must not check or create tables.' );
	}

	public function test_ensure_tables_creates_tables_when_the_stored_version_differs(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_tables( [] );
		$created = 0;

		$result = Database_Migration::ensure_tables(
			function () use ( &$created, $wpdb ) {
				++$created;
				$wpdb->existing = $this->all_tables();
			}
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, $created );
		$this->assertArrayNotHasKey( Database_Migration::OPT_DB_ERROR, $GLOBALS['_wp_options'] );
	}

	public function test_ensure_tables_repairs_missing_tables_in_admin(): void {
		global $wpdb;
		$wpdb                                                                       = $this->wpdb_with_tables( [ 'wp_wp4odoo_logs' ] );
		$GLOBALS['_wp_is_admin']                                                    = true;
		$GLOBALS['_wp_options'][ \WP4Odoo\Settings_Repository::OPT_DB_VERSION ] = WP4ODOO_VERSION;
		$created                                                                    = 0;

		$result = Database_Migration::ensure_tables(
			function () use ( &$created, $wpdb ) {
				++$created;
				$wpdb->existing = $this->all_tables();
			}
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, $created );
	}

	public function test_ensure_tables_checks_only_every_twelve_hours(): void {
		global $wpdb;
		$wpdb                                                                       = $this->wpdb_with_tables( $this->all_tables() );
		$GLOBALS['_wp_is_admin']                                                    = true;
		$GLOBALS['_wp_options'][ \WP4Odoo\Settings_Repository::OPT_DB_VERSION ] = WP4ODOO_VERSION;

		$this->assertTrue( Database_Migration::ensure_tables() );
		$wpdb->calls = [];
		$this->assertTrue( Database_Migration::ensure_tables() );
		$this->assertSame( [], $wpdb->calls, 'The second call is served from the transient.' );
	}

	public function test_ensure_tables_records_the_error_when_creation_fails(): void {
		global $wpdb;
		$wpdb             = $this->wpdb_with_tables( [] );
		$wpdb->last_error = 'CREATE command denied to user';
		$GLOBALS['_wp_is_admin'] = true;

		$result = Database_Migration::ensure_tables( function () {} );

		$this->assertFalse( $result );
		$error = $GLOBALS['_wp_options'][ Database_Migration::OPT_DB_ERROR ];
		$this->assertSame( [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map', 'wp4odoo_logs' ], $error['tables'] );
		$this->assertStringContainsString( 'denied', $error['error'] );
	}

	public function test_ensure_tables_survives_a_failing_creator(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_tables( [] );

		$result = Database_Migration::ensure_tables( function () { throw new \RuntimeException( 'boom' ); } );

		$this->assertFalse( $result );
		$this->assertSame( 'boom', $GLOBALS['_wp_options'][ Database_Migration::OPT_DB_ERROR ]['error'] );
	}
}
