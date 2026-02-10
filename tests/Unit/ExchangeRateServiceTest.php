<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Exchange_Rate_Service;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Exchange_Rate_Service.
 *
 * Tests currency conversion math, transient caching,
 * Odoo rate fetching, and error handling.
 */
class ExchangeRateServiceTest extends TestCase {

	private Exchange_Rate_Service $service;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled' => true,
			'level'   => 'debug',
		];

		$this->service = $this->create_service( [
			[ 'name' => 'EUR', 'rate' => 1.0 ],
			[ 'name' => 'USD', 'rate' => 1.08 ],
			[ 'name' => 'GBP', 'rate' => 0.86 ],
			[ 'name' => 'JPY', 'rate' => 130.45 ],
		] );
	}

	// ─── Conversion Logic ────────────────────────────────────

	public function test_convert_same_currency_returns_amount(): void {
		$this->assertSame( 100.0, $this->service->convert( 100.0, 'EUR', 'EUR' ) );
	}

	public function test_convert_eur_to_usd(): void {
		$this->assertSame( 108.0, $this->service->convert( 100.0, 'EUR', 'USD' ) );
	}

	public function test_convert_usd_to_eur(): void {
		$this->assertSame( 92.59, $this->service->convert( 100.0, 'USD', 'EUR' ) );
	}

	public function test_convert_between_non_company_currencies(): void {
		// USD → GBP: 100 * (0.86 / 1.08) = 79.63
		$this->assertSame( 79.63, $this->service->convert( 100.0, 'USD', 'GBP' ) );
	}

	public function test_convert_rounds_to_two_decimals(): void {
		// EUR → JPY: 99.99 * (130.45 / 1.0) = 13043.70
		$this->assertSame( 13043.7, $this->service->convert( 99.99, 'EUR', 'JPY' ) );
	}

	// ─── Error Handling ──────────────────────────────────────

	public function test_convert_returns_null_when_rates_empty(): void {
		$service = $this->create_service( [] );
		$this->assertNull( $service->convert( 100.0, 'EUR', 'USD' ) );
	}

	public function test_convert_returns_null_when_from_currency_missing(): void {
		$service = $this->create_service( [
			[ 'name' => 'EUR', 'rate' => 1.0 ],
		] );
		$this->assertNull( $service->convert( 100.0, 'USD', 'EUR' ) );
	}

	public function test_convert_returns_null_when_to_currency_missing(): void {
		$service = $this->create_service( [
			[ 'name' => 'EUR', 'rate' => 1.0 ],
		] );
		$this->assertNull( $service->convert( 100.0, 'EUR', 'USD' ) );
	}

	public function test_convert_returns_null_when_from_rate_zero(): void {
		$GLOBALS['_wp_transients']['wp4odoo_exchange_rates'] = [
			'EUR' => 1.0,
			'USD' => 0.0,
		];
		$service = $this->create_service( [] );
		$this->assertNull( $service->convert( 100.0, 'USD', 'EUR' ) );
	}

	public function test_convert_returns_null_on_odoo_connection_failure(): void {
		$service = new Exchange_Rate_Service(
			new Logger( 'test' ),
			function () {
				throw new \RuntimeException( 'Connection failed' );
			}
		);
		$this->assertNull( $service->convert( 100.0, 'EUR', 'USD' ) );
	}

	// ─── Caching ─────────────────────────────────────────────

	public function test_get_rates_returns_cached_transient(): void {
		$cached = [
			'EUR' => 1.0,
			'CHF' => 0.95,
		];
		$GLOBALS['_wp_transients']['wp4odoo_exchange_rates'] = $cached;

		// Service has a client that would return different rates.
		// Cached transient should be used instead.
		$rates = $this->service->get_rates();
		$this->assertSame( $cached, $rates );
	}

	public function test_get_rates_fetches_and_caches_when_no_transient(): void {
		$rates = $this->service->get_rates();

		$this->assertSame( 1.0, $rates['EUR'] );
		$this->assertSame( 1.08, $rates['USD'] );

		// Verify transient was populated.
		$this->assertSame( $rates, $GLOBALS['_wp_transients']['wp4odoo_exchange_rates'] );
	}

	// ─── Fetch Filtering ─────────────────────────────────────

	public function test_fetch_skips_records_with_empty_name(): void {
		$service = $this->create_service( [
			[ 'name' => '',    'rate' => 1.0 ],
			[ 'name' => 'EUR', 'rate' => 1.0 ],
		] );
		$rates = $service->get_rates();

		$this->assertCount( 1, $rates );
		$this->assertArrayHasKey( 'EUR', $rates );
	}

	public function test_fetch_skips_records_with_zero_rate(): void {
		$service = $this->create_service( [
			[ 'name' => 'XYZ', 'rate' => 0.0 ],
			[ 'name' => 'EUR', 'rate' => 1.0 ],
		] );
		$rates = $service->get_rates();

		$this->assertCount( 1, $rates );
		$this->assertArrayNotHasKey( 'XYZ', $rates );
	}

	// ─── Helpers ─────────────────────────────────────────────

	/**
	 * Create a service with a mock client returning the given rate records.
	 *
	 * @param array $records Odoo res.currency records.
	 * @return Exchange_Rate_Service
	 */
	private function create_service( array $records ): Exchange_Rate_Service {
		$client_fn = function () use ( $records ) {
			return new class( $records ) {
				/** @var array */
				private array $records;

				public function __construct( array $records ) {
					$this->records = $records;
				}

				/**
				 * Mock search_read.
				 *
				 * @return array
				 */
				public function search_read( string $model, array $domain = [], array $fields = [] ): array {
					return $this->records;
				}
			};
		};

		return new Exchange_Rate_Service( new Logger( 'test' ), $client_fn );
	}
}
