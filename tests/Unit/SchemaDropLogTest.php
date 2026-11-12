<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Schema_Drop_Log;

/**
 * Unit tests for the log of fields dropped from pushes.
 */
#[CoversClass( Schema_Drop_Log::class )]
class SchemaDropLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_wp_options'] = [];
		Schema_Drop_Log::clear();
	}

	protected function tearDown(): void {
		Schema_Drop_Log::clear();
	}

	public function test_nothing_is_written_until_flush(): void {
		Schema_Drop_Log::record( 'crm', 'res.partner', 'company_name' );

		$this->assertSame( [], Schema_Drop_Log::all() );
	}

	public function test_flush_persists_counts_and_modules(): void {
		Schema_Drop_Log::record( 'crm', 'res.partner', 'company_name' );
		Schema_Drop_Log::record( 'crm', 'res.partner', 'company_name' );
		Schema_Drop_Log::record( 'wperp_crm', 'res.partner', 'company_name' );
		Schema_Drop_Log::flush();

		$all = Schema_Drop_Log::all();
		$this->assertSame( [ 'res.partner.company_name' ], array_keys( $all ) );
		$this->assertSame( 3, $all['res.partner.company_name']['count'] );
		$this->assertEqualsCanonicalizing( [ 'crm', 'wperp_crm' ], $all['res.partner.company_name']['modules'] );
	}

	public function test_flush_accumulates_across_requests(): void {
		Schema_Drop_Log::record( 'crm', 'res.partner', 'company_name' );
		Schema_Drop_Log::flush();
		Schema_Drop_Log::record( 'crm', 'res.partner', 'company_name' );
		Schema_Drop_Log::flush();

		$this->assertSame( 2, Schema_Drop_Log::all()['res.partner.company_name']['count'] );
	}

	public function test_old_entries_are_forgotten(): void {
		$GLOBALS['_wp_options'][ Schema_Drop_Log::OPTION ] = [
			'old.field' => [ 'count' => 1, 'modules' => [ 'x' ], 'last' => time() - 40 * DAY_IN_SECONDS ],
		];
		Schema_Drop_Log::record( 'crm', 'res.partner', 'company_name' );
		Schema_Drop_Log::flush();

		$this->assertSame( [ 'res.partner.company_name' ], array_keys( Schema_Drop_Log::all() ) );
	}

	public function test_the_log_is_capped(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			Schema_Drop_Log::record( 'crm', 'res.partner', 'f' . $i );
		}
		Schema_Drop_Log::flush();

		$this->assertCount( 50, Schema_Drop_Log::all() );
	}
}
