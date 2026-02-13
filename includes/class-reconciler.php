<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entity map reconciler — detect and fix orphaned mappings.
 *
 * Compares local entity_map entries against live Odoo records to
 * find orphans (mapped to deleted Odoo records). Optionally removes
 * orphaned mappings to prevent silent sync failures.
 *
 * @package WP4Odoo
 * @since   2.9.0
 */
class Reconciler {

	/**
	 * Maximum IDs per Odoo search batch.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Entity map repository.
	 *
	 * @var Entity_Map_Repository
	 */
	private Entity_Map_Repository $entity_map;

	/**
	 * Closure returning an Odoo_Client.
	 *
	 * @var \Closure
	 */
	private \Closure $client_fn;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Entity_Map_Repository $entity_map Entity map repository.
	 * @param \Closure              $client_fn  Closure returning an Odoo_Client.
	 * @param Logger                $logger     Logger instance.
	 */
	public function __construct( Entity_Map_Repository $entity_map, \Closure $client_fn, Logger $logger ) {
		$this->entity_map = $entity_map;
		$this->client_fn  = $client_fn;
		$this->logger     = $logger;
	}

	/**
	 * Reconcile entity mappings against live Odoo records.
	 *
	 * Loads all mappings for the given module/entity_type, batch-checks
	 * whether each mapped Odoo ID still exists, and optionally removes
	 * orphaned mappings.
	 *
	 * @param string $module      Module identifier (e.g. 'crm').
	 * @param string $entity_type Entity type (e.g. 'contact').
	 * @param string $odoo_model  Odoo model name (e.g. 'res.partner').
	 * @param bool   $fix         If true, remove orphaned mappings.
	 * @return array{checked: int, orphaned: array<array{wp_id: int, odoo_id: int}>, fixed: int}
	 */
	public function reconcile( string $module, string $entity_type, string $odoo_model, bool $fix = false ): array {
		$mappings = $this->entity_map->get_module_entity_mappings( $module, $entity_type );

		$result = [
			'checked'  => count( $mappings ),
			'orphaned' => [],
			'fixed'    => 0,
		];

		if ( empty( $mappings ) ) {
			return $result;
		}

		// Collect all Odoo IDs to check.
		$odoo_ids = [];
		foreach ( $mappings as $wp_id => $data ) {
			$odoo_ids[ $data['odoo_id'] ] = $wp_id;
		}

		// Batch-check existence in Odoo.
		$existing_ids = [];
		try {
			foreach ( array_chunk( array_keys( $odoo_ids ), self::BATCH_SIZE ) as $chunk ) {
				$found = ( $this->client_fn )()->search(
					$odoo_model,
					[ [ 'id', 'in', $chunk ] ]
				);

				foreach ( $found as $id ) {
					$existing_ids[ (int) $id ] = true;
				}
			}
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Reconciliation aborted: Odoo query failed.',
				[
					'module'      => $module,
					'entity_type' => $entity_type,
					'error'       => $e->getMessage(),
				]
			);

			return $result;
		}

		// Find orphans: mapped Odoo IDs that no longer exist.
		foreach ( $odoo_ids as $odoo_id => $wp_id ) {
			if ( ! isset( $existing_ids[ $odoo_id ] ) ) {
				$result['orphaned'][] = [
					'wp_id'   => $wp_id,
					'odoo_id' => $odoo_id,
				];
			}
		}

		// Optionally fix by removing orphaned mappings.
		if ( $fix && ! empty( $result['orphaned'] ) ) {
			foreach ( $result['orphaned'] as $orphan ) {
				$removed = $this->entity_map->remove( $module, $entity_type, $orphan['wp_id'] );
				if ( $removed ) {
					++$result['fixed'];
				}
			}

			$this->logger->info(
				'Reconciliation completed: removed orphaned mappings.',
				[
					'module'      => $module,
					'entity_type' => $entity_type,
					'orphaned'    => count( $result['orphaned'] ),
					'fixed'       => $result['fixed'],
				]
			);
		} else {
			$this->logger->info(
				'Reconciliation completed.',
				[
					'module'      => $module,
					'entity_type' => $entity_type,
					'checked'     => $result['checked'],
					'orphaned'    => count( $result['orphaned'] ),
				]
			);
		}

		return $result;
	}
}
