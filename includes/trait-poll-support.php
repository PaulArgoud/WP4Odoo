<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron polling support for modules without real-time hooks.
 *
 * Compares current entity data against entity_map records using
 * SHA-256 hashes to detect creates, updates, and deletions.
 * Used by WP-Cron polling modules (Bookly, Ecwid).
 *
 * Requires the using class to provide:
 * - $this->id (string module ID)
 * - $this->entity_map() (Entity_Map_Repository)
 * - $this->generate_sync_hash(array) (hash generation)
 *
 * @package WP4Odoo
 * @since   3.2.5
 */
trait Poll_Support {

	/**
	 * Detect changes in a set of entities via hash comparison and enqueue sync jobs.
	 *
	 * Compares current entity data against entity_map records using SHA-256
	 * hashes to detect creates, updates, and deletions. Uses targeted loading
	 * (IN-clause) instead of full table scans, and last_polled_at timestamps
	 * for deletion detection.
	 *
	 * @param string            $entity_type Entity type (e.g. 'service', 'product').
	 * @param array<int, array> $items       Current items from the data source.
	 * @param string            $id_field    Name of the ID field in each item.
	 * @return void
	 */
	protected function poll_entity_changes( string $entity_type, array $items, string $id_field = 'id' ): void {
		$poll_start = current_time( 'mysql', true );

		// Extract WP IDs from items for targeted loading.
		$wp_ids = [];
		foreach ( $items as $item ) {
			$wp_ids[] = (int) ( $item[ $id_field ] ?? 0 );
		}

		// Targeted loading: only fetch mappings for the current items' IDs
		// instead of loading all module/entity_type rows (up to 50 000).
		$existing = $this->entity_map()->get_mappings_for_wp_ids( $this->id, $entity_type, $wp_ids );

		$seen_ids = [];

		foreach ( $items as $item ) {
			$wp_id      = (int) ( $item[ $id_field ] ?? 0 );
			$seen_ids[] = $wp_id;

			$hash_data = $item;
			unset( $hash_data[ $id_field ] );
			$hash = $this->generate_sync_hash( $hash_data );

			if ( ! isset( $existing[ $wp_id ] ) ) {
				Queue_Manager::push( $this->id, $entity_type, 'create', $wp_id );
			} elseif ( $existing[ $wp_id ]['sync_hash'] !== $hash ) {
				Queue_Manager::push( $this->id, $entity_type, 'update', $wp_id, $existing[ $wp_id ]['odoo_id'] );
			}
		}

		// Mark all seen items as polled for deletion detection.
		$this->entity_map()->mark_polled( $this->id, $entity_type, $seen_ids, $poll_start );

		// Detect deletions: mappings that were previously polled but NOT
		// seen this cycle. Pre-migration rows (last_polled_at IS NULL) are
		// excluded to avoid false positives during bootstrapping.
		$stale = $this->entity_map()->get_stale_poll_mappings( $this->id, $entity_type, $poll_start );
		foreach ( $stale as $wp_id => $map ) {
			Queue_Manager::push( $this->id, $entity_type, 'delete', $wp_id, $map['odoo_id'] );
		}
	}
}
