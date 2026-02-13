<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Schema_Cache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Schema_Cache.
 */
class SchemaCacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_wp_transients'] = [];
		Schema_Cache::flush();
	}

	public function test_returns_empty_when_client_throws(): void {
		$client_fn = static fn() => new class {
			/** @return never */
			public function fields_get( string $model, array $attrs = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				throw new \RuntimeException( 'Connection failed' );
			}
		};

		$result = Schema_Cache::get_fields( $client_fn, 'product.template' );

		$this->assertSame( [], $result );
	}

	public function test_caches_in_memory(): void {
		$call_count = 0;
		$client_fn  = static function () use ( &$call_count ) {
			return new class( $call_count ) {
				private int $counter;

				public function __construct( int &$counter ) {
					$this->counter = &$counter;
				}

				public function fields_get( string $model, array $attrs = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					++$this->counter;
					return [ 'name' => [ 'type' => 'char' ], 'list_price' => [ 'type' => 'float' ] ];
				}
			};
		};

		$first  = Schema_Cache::get_fields( $client_fn, 'product.template' );
		$second = Schema_Cache::get_fields( $client_fn, 'product.template' );

		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'list_price', $first );
		$this->assertSame( $first, $second );
		// Client should only be called once (memory cache hit on second call).
		$this->assertSame( 1, $call_count );
	}

	public function test_caches_in_transient(): void {
		// Pre-populate transient.
		$key  = 'wp4odoo_schema_' . md5( 'sale.order' );
		$data = [ 'name' => [ 'type' => 'char' ], 'amount_total' => [ 'type' => 'monetary' ] ];
		set_transient( $key, $data, 3600 );

		// Client should never be called.
		$client_fn = static fn() => throw new \RuntimeException( 'Should not be called' );

		$result = Schema_Cache::get_fields( $client_fn, 'sale.order' );

		$this->assertSame( $data, $result );
	}

	public function test_flush_clears_memory(): void {
		$fields    = [ 'name' => [ 'type' => 'char' ] ];
		$client_fn = static fn() => new class( $fields ) {
			private array $f;

			public function __construct( array $f ) {
				$this->f = $f;
			}

			public function fields_get( string $model, array $attrs = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return $this->f;
			}
		};

		Schema_Cache::get_fields( $client_fn, 'res.partner' );
		Schema_Cache::flush();

		// After flush, transient still exists so it should be read from there.
		$result = Schema_Cache::get_fields( $client_fn, 'res.partner' );
		$this->assertSame( $fields, $result );
	}

	public function test_returns_empty_when_fields_get_returns_empty(): void {
		$client_fn = static fn() => new class {
			public function fields_get( string $model, array $attrs = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return [];
			}
		};

		$result = Schema_Cache::get_fields( $client_fn, 'unknown.model' );

		$this->assertSame( [], $result );
	}
}
