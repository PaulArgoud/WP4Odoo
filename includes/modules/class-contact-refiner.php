<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Field_Mapper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact data refinement: name composition/splitting, country/state resolution.
 *
 * Registered as WordPress filter callbacks on the CRM module's
 * map_to_odoo and map_from_odoo hooks.
 *
 * @package WP4Odoo
 * @since   1.0.3
 */
class Contact_Refiner {

	/**
	 * Closure returning the Odoo_Client instance.
	 *
	 * @var \Closure
	 */
	private \Closure $client_getter;

	/**
	 * Constructor.
	 *
	 * @param \Closure $client_getter Returns an Odoo_Client instance.
	 */
	public function __construct( \Closure $client_getter ) {
		$this->client_getter = $client_getter;
	}

	/**
	 * Get the Odoo client.
	 *
	 * @return Odoo_Client
	 */
	private function client(): Odoo_Client {
		return ( $this->client_getter )();
	}

	// ─── Filter Callbacks ───────────────────────────────────

	/**
	 * Refine mapped Odoo values before pushing a contact.
	 *
	 * Composes name from first/last, resolves country/state IDs,
	 * and strips empty values.
	 *
	 * @param array  $odoo_values Mapped values.
	 * @param array  $wp_data     Original WP data.
	 * @param string $entity_type Entity type.
	 * @return array Refined Odoo values.
	 */
	public function refine_to_odoo( array $odoo_values, array $wp_data, string $entity_type ): array {
		// Compose name from first + last name.
		$first = $wp_data['first_name'] ?? '';
		$last  = $wp_data['last_name'] ?? '';
		if ( '' !== $first || '' !== $last ) {
			$odoo_values['name'] = trim( $first . ' ' . $last );
		}
		unset( $odoo_values['x_wp_first_name'], $odoo_values['x_wp_last_name'] );

		// Resolve country code to Odoo res.country ID.
		if ( ! empty( $odoo_values['country_id'] ) && is_string( $odoo_values['country_id'] ) ) {
			try {
				$code    = strtoupper( $odoo_values['country_id'] );
				$country = $this->client()->search( 'res.country', [ [ 'code', '=', $code ] ], 0, 1 );
			} catch ( \RuntimeException $e ) {
				$country = [];
			}
			$odoo_values['country_id'] = ! empty( $country ) ? (int) $country[0] : false;

			// Resolve state within that country.
			if ( ! empty( $odoo_values['state_id'] ) && is_string( $odoo_values['state_id'] ) && ! empty( $country ) ) {
				try {
					$state_name = $odoo_values['state_id'];
					$state      = $this->client()->search(
						'res.country.state',
						[ [ 'country_id', '=', (int) $country[0] ], [ 'name', 'ilike', $state_name ] ],
						0,
						1
					);
				} catch ( \RuntimeException $e ) {
					$state = [];
				}
				$odoo_values['state_id'] = ! empty( $state ) ? (int) $state[0] : false;
			} else {
				$odoo_values['state_id'] = false;
			}
		} else {
			unset( $odoo_values['country_id'], $odoo_values['state_id'] );
		}

		// Strip empty values to avoid clearing Odoo data.
		return array_filter( $odoo_values, fn( $v ) => '' !== $v && null !== $v );
	}

	/**
	 * Refine mapped WP data after pulling a contact from Odoo.
	 *
	 * Splits display_name into first/last, resolves country/state Many2one.
	 *
	 * @param array  $wp_data     Mapped WP data.
	 * @param array  $odoo_data   Raw Odoo record.
	 * @param string $entity_type Entity type.
	 * @return array Refined WP data.
	 */
	public function refine_from_odoo( array $wp_data, array $odoo_data, string $entity_type ): array {
		// Split name into first + last.
		$name = $wp_data['display_name'] ?? '';
		if ( '' !== $name && empty( $wp_data['first_name'] ) ) {
			$parts                 = explode( ' ', $name, 2 );
			$wp_data['first_name'] = $parts[0];
			$wp_data['last_name']  = $parts[1] ?? '';
		}

		// Resolve country_id Many2one to ISO code (requires API call — code not in tuple).
		if ( isset( $odoo_data['country_id'] ) ) {
			$wp_data['billing_country'] = $this->resolve_many2one_field( $odoo_data['country_id'], 'res.country', 'code' ) ?? '';
		}

		// Resolve state_id Many2one — use display name from the tuple directly (avoids extra API call).
		if ( isset( $odoo_data['state_id'] ) ) {
			$state_name               = Field_Mapper::many2one_to_name( $odoo_data['state_id'] );
			$wp_data['billing_state'] = $state_name ?? '';
		}

		// Remove temporary mapping keys.
		unset( $wp_data['x_wp_first_name'], $wp_data['x_wp_last_name'] );

		return $wp_data;
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Resolve a single field from an Odoo Many2one value.
	 *
	 * @param mixed  $many2one_value The Many2one value from Odoo.
	 * @param string $model          The Odoo model to read from.
	 * @param string $field          The field to extract.
	 * @return string|null The field value, or null if unresolvable.
	 */
	private function resolve_many2one_field( mixed $many2one_value, string $model, string $field ): ?string {
		$id = Field_Mapper::many2one_to_id( $many2one_value );

		if ( null === $id ) {
			return null;
		}

		try {
			$records = $this->client()->read( $model, [ $id ], [ $field ] );
		} catch ( \RuntimeException $e ) {
			return null;
		}

		if ( ! empty( $records[0][ $field ] ) ) {
			return (string) $records[0][ $field ];
		}

		return null;
	}
}
