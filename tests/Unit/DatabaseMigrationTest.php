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

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] );
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

		$this->assertSame( 8, $applied );
		$this->assertSame( 8, Database_Migration::get_schema_version() );
	}

	public function test_run_migrations_skips_already_applied(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 5;

		// Migrations 6, 7, and 8 should run.
		$this->wpdb->get_col_return     = [ 'id', 'module', 'entity_type' ];
		$this->wpdb->get_results_return = [];

		$applied = Database_Migration::run_migrations();

		$this->assertSame( 3, $applied );
		$this->assertSame( 8, Database_Migration::get_schema_version() );
	}

	public function test_run_migrations_returns_zero_when_up_to_date(): void {
		$GLOBALS['_wp_options'][ Database_Migration::OPT_SCHEMA_VERSION ] = 8;

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
		// Index already exists (for migration 8 idempotency).
		$this->wpdb->get_results_return = [ (object) [ 'Key_name' => 'idx_stale_recovery', 'Column_name' => 'blog_id' ] ];

		Database_Migration::run_migrations();

		$query_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => 'query' === $c['method'] )
		);

		// No ALTER TABLE should be issued since columns already exist.
		$alter_calls = array_filter(
			$query_calls,
			fn( $c ) => str_contains( $c['args'][0], 'ALTER TABLE' )
		);

		$this->assertEmpty( $alter_calls, 'Migrations 6-7 should skip ALTER when columns exist.' );
	}

	// ─── get_migrations() coverage ─────────────────────────

	public function test_migrations_are_sequential_from_1_to_8(): void {
		$method     = new \ReflectionMethod( Database_Migration::class, 'get_migrations' );
		$migrations = $method->invoke( null );

		$this->assertCount( 8, $migrations );
		for ( $i = 1; $i <= 8; $i++ ) {
			$this->assertArrayHasKey( $i, $migrations, "Migration $i should exist." );
			// Callbacks are [class, 'migration_N'] arrays pointing to private static methods.
			$this->assertIsArray( $migrations[ $i ] );
			$this->assertSame( Database_Migration::class, $migrations[ $i ][0] );
			$this->assertSame( "migration_{$i}", $migrations[ $i ][1] );
		}
	}
}
