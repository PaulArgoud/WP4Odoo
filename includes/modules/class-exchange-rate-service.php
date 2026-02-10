<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exchange Rate Service — fetch and cache Odoo currency rates.
 *
 * Reads active exchange rates from Odoo's `res.currency` model,
 * caches them in a WordPress transient, and provides a conversion
 * method. All rates are relative to the company currency (rate 1.0).
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class Exchange_Rate_Service {

	/**
	 * WordPress transient key for cached rates.
	 *
	 * @var string
	 */
	private const TRANSIENT_KEY = 'wp4odoo_exchange_rates';

	/**
	 * Cache time-to-live in seconds.
	 *
	 * @var int
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure returning the Odoo_Client.
	 *
	 * @var \Closure
	 */
	private \Closure $client_fn;

	/**
	 * Constructor.
	 *
	 * @param Logger   $logger    Logger instance.
	 * @param \Closure $client_fn Closure returning \WP4Odoo\API\Odoo_Client.
	 */
	public function __construct( Logger $logger, \Closure $client_fn ) {
		$this->logger    = $logger;
		$this->client_fn = $client_fn;
	}

	/**
	 * Convert an amount from one currency to another.
	 *
	 * Returns null if conversion is not possible (missing rates, zero rate,
	 * or Odoo connection failure). The caller should fall back to skip
	 * behavior when null is returned.
	 *
	 * @param float  $amount        The amount to convert.
	 * @param string $from_currency ISO 4217 source currency code.
	 * @param string $to_currency   ISO 4217 target currency code.
	 * @return float|null Converted amount rounded to 2 decimals, or null on failure.
	 */
	public function convert( float $amount, string $from_currency, string $to_currency ): ?float {
		if ( $from_currency === $to_currency ) {
			return $amount;
		}

		$rates = $this->get_rates();

		if ( empty( $rates ) ) {
			$this->logger->warning( 'Exchange rates not available, cannot convert.' );
			return null;
		}

		if ( ! isset( $rates[ $from_currency ] ) ) {
			$this->logger->warning(
				'Exchange rate not found for source currency.',
				[ 'currency' => $from_currency ]
			);
			return null;
		}

		if ( ! isset( $rates[ $to_currency ] ) ) {
			$this->logger->warning(
				'Exchange rate not found for target currency.',
				[ 'currency' => $to_currency ]
			);
			return null;
		}

		$rate_from = (float) $rates[ $from_currency ];
		$rate_to   = (float) $rates[ $to_currency ];

		if ( $rate_from <= 0.0 ) {
			$this->logger->warning(
				'Invalid exchange rate (zero or negative).',
				[
					'currency' => $from_currency,
					'rate'     => $rate_from,
				]
			);
			return null;
		}

		return round( $amount * ( $rate_to / $rate_from ), 2 );
	}

	/**
	 * Get cached exchange rates, or fetch from Odoo on cache miss.
	 *
	 * @return array<string, float> Currency code => rate (relative to company currency).
	 */
	public function get_rates(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		return $this->fetch_rates();
	}

	/**
	 * Fetch exchange rates from Odoo and cache them.
	 *
	 * @return array<string, float> Currency code => rate.
	 */
	private function fetch_rates(): array {
		try {
			$client  = ( $this->client_fn )();
			$records = $client->search_read(
				'res.currency',
				[ [ 'active', '=', true ] ],
				[ 'name', 'rate' ]
			);
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'Failed to fetch exchange rates from Odoo.',
				[ 'error' => $e->getMessage() ]
			);
			return [];
		}

		if ( empty( $records ) ) {
			return [];
		}

		$rates = [];
		foreach ( $records as $record ) {
			$code = (string) ( $record['name'] ?? '' );
			$rate = (float) ( $record['rate'] ?? 0.0 );

			if ( '' !== $code && $rate > 0.0 ) {
				$rates[ $code ] = $rate;
			}
		}

		if ( ! empty( $rates ) ) {
			set_transient( self::TRANSIENT_KEY, $rates, self::CACHE_TTL );

			$this->logger->info(
				'Fetched exchange rates from Odoo.',
				[ 'currency_count' => count( $rates ) ]
			);
		}

		return $rates;
	}
}
