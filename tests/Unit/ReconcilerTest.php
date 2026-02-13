<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;
use WP4Odoo\Reconciler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Reconciler.
 */
class ReconcilerTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_cache']      = [];
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => true, 'level' => 'debug' ];
	}

	private function make_reconciler( \Closure $client_fn ): Reconciler {
		return new Reconciler(
			new Entity_Map_Repository(),
			$client_fn,
			new Logger( 'reconcile', wp4odoo_test_settings() )
		);
	}

	private function make_client( array $existing_ids ): \Closure {
		return static fn() => new class( $existing_ids ) {
			/** @var array<int> */
			private array $ids;

			/** @param array<int> $ids */
			public function __construct( array $ids ) {
				$this->ids = $ids;
			}

			/**
			 * @param string $model  Odoo model name.
			 * @param array  $domain Search domain.
			 * @return array<int>
			 */
			public function search( string $model, array $domain ): array {
				// Extract the 'in' values from domain [['id', 'in', [...]]]
				$requested = $domain[0][2] ?? [];
				return array_values( array_intersect( $requested, $this->ids ) );
			}
		};
	}

	public function test_no_mappings_returns_empty(): void {
		// Empty entity map (wpdb returns null for get_results).
		$this->wpdb->get_results_return = [];

		$reconciler = $this->make_reconciler( $this->make_client( [] ) );
		$result     = $reconciler->reconcile( 'crm', 'contact', 'res.partner' );

		$this->assertSame( 0, $result['checked'] );
		$this->assertEmpty( $result['orphaned'] );
		$this->assertSame( 0, $result['fixed'] );
	}

	public function test_detects_orphaned_mappings(): void {
		// Simulate 3 mappings, only Odoo ID 10 and 30 exist.
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 1, 'odoo_id' => 10, 'sync_hash' => '' ],
			(object) [ 'wp_id' => 2, 'odoo_id' => 20, 'sync_hash' => '' ],
			(object) [ 'wp_id' => 3, 'odoo_id' => 30, 'sync_hash' => '' ],
		];

		$reconciler = $this->make_reconciler( $this->make_client( [ 10, 30 ] ) );
		$result     = $reconciler->reconcile( 'crm', 'contact', 'res.partner' );

		$this->assertSame( 3, $result['checked'] );
		$this->assertCount( 1, $result['orphaned'] );
		$this->assertSame( 2, $result['orphaned'][0]['wp_id'] );
		$this->assertSame( 20, $result['orphaned'][0]['odoo_id'] );
		$this->assertSame( 0, $result['fixed'] );
	}

	public function test_fix_removes_orphaned_mappings(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 1, 'odoo_id' => 10, 'sync_hash' => '' ],
			(object) [ 'wp_id' => 2, 'odoo_id' => 20, 'sync_hash' => '' ],
		];

		// Only Odoo ID 10 exists → wp_id=2 is orphaned.
		$reconciler = $this->make_reconciler( $this->make_client( [ 10 ] ) );
		$result     = $reconciler->reconcile( 'crm', 'contact', 'res.partner', true );

		$this->assertCount( 1, $result['orphaned'] );
		$this->assertSame( 1, $result['fixed'] );

		// Verify a delete query was executed (WP_DB_Stub tracks calls).
		$delete_calls = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'delete' === $c['method']
		);
		$this->assertNotEmpty( $delete_calls );
	}

	public function test_odoo_error_aborts_safely(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 1, 'odoo_id' => 10, 'sync_hash' => '' ],
		];

		$client_fn = static fn() => new class {
			/**
			 * @param string $model  Odoo model name.
			 * @param array  $domain Search domain.
			 * @return never
			 */
			public function search( string $model, array $domain ): array {
				throw new \RuntimeException( 'Connection failed' );
			}
		};

		$reconciler = $this->make_reconciler( $client_fn );
		$result     = $reconciler->reconcile( 'crm', 'contact', 'res.partner', true );

		$this->assertSame( 1, $result['checked'] );
		$this->assertEmpty( $result['orphaned'] );
		$this->assertSame( 0, $result['fixed'] );
	}

	public function test_all_mappings_valid_returns_no_orphans(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 1, 'odoo_id' => 10, 'sync_hash' => '' ],
			(object) [ 'wp_id' => 2, 'odoo_id' => 20, 'sync_hash' => '' ],
		];

		$reconciler = $this->make_reconciler( $this->make_client( [ 10, 20 ] ) );
		$result     = $reconciler->reconcile( 'crm', 'contact', 'res.partner' );

		$this->assertSame( 2, $result['checked'] );
		$this->assertEmpty( $result['orphaned'] );
		$this->assertSame( 0, $result['fixed'] );
	}
}
