<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles bulk product import/export operations.
 *
 * Encapsulates the common pattern: fetch IDs, check mappings, enqueue jobs.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
final class Bulk_Handler {

	/**
	 * Odoo API client.
	 *
	 * @var Odoo_Client
	 */
	private Odoo_Client $client;

	/**
	 * Entity map repository.
	 *
	 * @var Entity_Map_Repository
	 */
	private Entity_Map_Repository $entity_map;

	/**
	 * Constructor.
	 *
	 * @param Odoo_Client           $client     Odoo API client.
	 * @param Entity_Map_Repository $entity_map Entity map repository.
	 */
	public function __construct( Odoo_Client $client, Entity_Map_Repository $entity_map ) {
		$this->client     = $client;
		$this->entity_map = $entity_map;
	}

	/**
	 * Chunk size for paginated bulk operations.
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * Import all products from Odoo into WooCommerce via the queue.
	 *
	 * Fetches Odoo product IDs in paginated chunks and uses batch
	 * entity_map lookups to determine create vs update actions.
	 *
	 * @return array{enqueued: int, total: int, message: string}
	 */
	public function import_products(): array {
		$total    = $this->client->search_count( 'product.template', [] );
		$enqueued = 0;
		$offset   = 0;

		do {
			$odoo_ids = $this->client->search( 'product.template', [], $offset, self::CHUNK_SIZE );

			if ( empty( $odoo_ids ) ) {
				break;
			}

			$odoo_ids = array_map( 'intval', $odoo_ids );
			$fetched  = count( $odoo_ids );
			$map      = $this->entity_map->get_wp_ids_batch( 'woocommerce', 'product', $odoo_ids );

			foreach ( $odoo_ids as $odoo_id ) {
				$wp_id  = $map[ $odoo_id ] ?? 0;
				$action = $wp_id ? 'update' : 'create';

				Queue_Manager::pull( 'woocommerce', 'product', $action, $odoo_id, $wp_id, [], 5 );
				++$enqueued;
			}

			$offset += self::CHUNK_SIZE;
		} while ( $fetched >= self::CHUNK_SIZE );

		if ( 0 === $enqueued ) {
			return [
				'enqueued' => 0,
				'total'    => $total,
				'message'  => __( 'No products found in Odoo.', 'wp4odoo' ),
			];
		}

		return [
			'enqueued' => $enqueued,
			'total'    => $total,
			'message'  => sprintf(
				/* translators: 1: number of products enqueued, 2: total number of products */
				__( '%1$d product(s) enqueued for import (%2$d found in Odoo).', 'wp4odoo' ),
				$enqueued,
				$total
			),
		];
	}

	/**
	 * Export all WooCommerce products to Odoo via the queue.
	 *
	 * Fetches WooCommerce product IDs in paginated chunks and uses batch
	 * entity_map lookups to determine create vs update actions.
	 *
	 * @return array{enqueued: int, total: int, message: string}
	 */
	public function export_products(): array {
		$enqueued = 0;
		$page     = 1;

		do {
			$product_ids = wc_get_products(
				[
					'limit'  => self::CHUNK_SIZE,
					'page'   => $page,
					'return' => 'ids',
				]
			);

			if ( empty( $product_ids ) ) {
				break;
			}

			$product_ids = array_map( 'intval', $product_ids );
			$fetched     = count( $product_ids );
			$map         = $this->entity_map->get_odoo_ids_batch( 'woocommerce', 'product', $product_ids );

			foreach ( $product_ids as $wp_id ) {
				$odoo_id = $map[ $wp_id ] ?? 0;
				$action  = $odoo_id ? 'update' : 'create';

				Queue_Manager::push( 'woocommerce', 'product', $action, $wp_id, $odoo_id );
				++$enqueued;
			}

			++$page;
		} while ( $fetched >= self::CHUNK_SIZE );

		if ( 0 === $enqueued ) {
			return [
				'enqueued' => 0,
				'total'    => 0,
				'message'  => __( 'No WooCommerce products found.', 'wp4odoo' ),
			];
		}

		return [
			'enqueued' => $enqueued,
			'total'    => $enqueued,
			'message'  => sprintf(
				/* translators: %d: number of products enqueued */
				__( '%d product(s) enqueued for export.', 'wp4odoo' ),
				$enqueued
			),
		];
	}
}
