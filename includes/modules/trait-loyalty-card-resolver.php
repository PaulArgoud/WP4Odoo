<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Error_Type;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared loyalty.card resolution logic for point-balance modules.
 *
 * Used by WC Points & Rewards and GamiPress to find-or-create an Odoo
 * loyalty.card by (partner_id, program_id) composite key. Provides the
 * `resolve_or_create_card()` method that:
 *
 * 1. Checks the entity map for an existing mapping.
 * 2. Searches Odoo by partner_id + program_id.
 * 3. Creates a new loyalty.card if not found.
 * 4. Updates the points balance on the resolved card.
 *
 * Requirements: the consuming class must extend Module_Base (provides
 * `client()`, `get_mapping()`, `save_mapping()`, `logger`, and the
 * `Error_Classification` trait).
 *
 * @package WP4Odoo
 * @since   3.3.0
 */
trait Loyalty_Card_Resolver {

	/**
	 * Resolve or create an Odoo loyalty.card for a user.
	 *
	 * @param string               $entity_type Entity type key (e.g. 'balance', 'points').
	 * @param int                  $wp_id       WordPress user ID.
	 * @param int                  $partner_id  Odoo partner ID.
	 * @param int                  $program_id  Odoo loyalty.program ID.
	 * @param array<string, mixed> $odoo_values Pre-formatted Odoo field values (must include 'points').
	 * @param int                  $odoo_id     Known Odoo loyalty.card ID (0 if unknown).
	 * @return Sync_Result
	 */
	protected function resolve_or_create_card(
		string $entity_type,
		int $wp_id,
		int $partner_id,
		int $program_id,
		array $odoo_values,
		int $odoo_id
	): Sync_Result {
		$client = $this->client();

		// 1. Check entity_map.
		if ( $odoo_id <= 0 ) {
			$odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
		}

		// 2. Search Odoo if not mapped.
		if ( $odoo_id <= 0 ) {
			try {
				$ids = $client->search(
					'loyalty.card',
					[
						[ 'partner_id', '=', $partner_id ],
						[ 'program_id', '=', $program_id ],
					],
					0,
					1
				);

				if ( ! empty( $ids ) ) {
					$odoo_id = (int) $ids[0];
					$this->save_mapping( $entity_type, $wp_id, $odoo_id );
					$this->logger->info(
						'Found existing Odoo loyalty card.',
						[
							'user_id' => $wp_id,
							'card_id' => $odoo_id,
						]
					);
				}
			} catch ( \Exception $e ) {
				$this->logger->error( 'Loyalty card search failed.', [ 'error' => $e->getMessage() ] );
				$error_type = $e instanceof \RuntimeException ? static::classify_exception( $e ) : Error_Type::Transient;
				return Sync_Result::failure( $e->getMessage(), $error_type );
			}
		}

		try {
			if ( $odoo_id > 0 ) {
				// Update existing card — only write points (partner/program don't change).
				$client->write( 'loyalty.card', [ $odoo_id ], [ 'points' => $odoo_values['points'] ] );
				$this->save_mapping( $entity_type, $wp_id, $odoo_id );
				$this->logger->info(
					'Updated Odoo loyalty card points.',
					[
						'user_id' => $wp_id,
						'card_id' => $odoo_id,
						'points'  => $odoo_values['points'],
					]
				);
			} else {
				// 3. Create new card.
				$odoo_id = $client->create( 'loyalty.card', $odoo_values );
				$this->save_mapping( $entity_type, $wp_id, $odoo_id );
				$this->logger->info(
					'Created Odoo loyalty card.',
					[
						'user_id' => $wp_id,
						'card_id' => $odoo_id,
						'points'  => $odoo_values['points'],
					]
				);
			}
		} catch ( \Exception $e ) {
			$this->logger->error( 'Loyalty card push failed.', [ 'error' => $e->getMessage() ] );
			$error_type = $e instanceof \RuntimeException ? static::classify_exception( $e ) : Error_Type::Transient;
			return Sync_Result::failure( $e->getMessage(), $error_type );
		}

		return Sync_Result::success( $odoo_id );
	}
}
