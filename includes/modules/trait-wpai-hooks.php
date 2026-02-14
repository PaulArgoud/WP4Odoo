<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP All Import hook callbacks.
 *
 * Intercepts records saved via WP All Import and routes them
 * to the appropriate module's sync queue. Ensures imported data
 * reaches Odoo even when WP All Import's "speed optimization"
 * disables standard WordPress hooks.
 *
 * Composed into WP_All_Import_Module via `use WPAI_Hooks`.
 *
 * @package WP4Odoo
 * @since   3.1.0
 */
trait WPAI_Hooks {

	/**
	 * Register WP All Import hooks.
	 *
	 * Called from boot() after plugin detection.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'pmxi_saved_post', $this->safe_callback( [ $this, 'on_post_saved' ] ), 10, 3 );
		add_action( 'pmxi_after_xml_import', $this->safe_callback( [ $this, 'on_import_complete' ] ), 10, 2 );
	}

	/**
	 * Handle WP All Import post saved event.
	 *
	 * Routes the saved post to the correct module's sync queue
	 * based on post type → module/entity mapping.
	 *
	 * @param int    $post_id   The saved post ID.
	 * @param object $xml_node  The SimpleXMLElement for this record.
	 * @param bool   $is_update Whether this is an update (vs create).
	 * @return void
	 */
	public function on_post_saved( int $post_id, $xml_node, bool $is_update ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		if ( ! $post_type ) {
			return;
		}

		$routing = $this->get_routing_table();

		if ( ! isset( $routing[ $post_type ] ) ) {
			$this->logger->debug( "WPAI: skipping unrouted post type '{$post_type}'.", [ 'post_id' => $post_id ] );
			return;
		}

		[ $module_id, $entity_type ] = $routing[ $post_type ];

		if ( ! $this->is_target_module_enabled( $module_id ) ) {
			$this->logger->debug( "WPAI: target module '{$module_id}' is disabled, skipping.", [ 'post_id' => $post_id ] );
			return;
		}

		// Cross-module entity_map lookup to determine create vs update.
		$odoo_id = $this->get_entity_map_ref()->get_odoo_id( $module_id, $entity_type, $post_id );
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( $module_id, $entity_type, $action, $post_id, $odoo_id ?? 0 );

		// Track count for import summary.
		$import_id = function_exists( 'wp_all_import_get_import_id' )
			? (int) wp_all_import_get_import_id()
			: 0;

		if ( $import_id > 0 ) {
			WP_All_Import_Module::increment_import_count( $import_id );
		}
	}

	/**
	 * Handle WP All Import import completion.
	 *
	 * Logs a summary of how many records were enqueued for Odoo sync.
	 *
	 * @param int    $import_id The import ID.
	 * @param object $import    The import object.
	 * @return void
	 */
	public function on_import_complete( int $import_id, $import ): void {
		$count = WP_All_Import_Module::get_import_count( $import_id );

		if ( $count > 0 ) {
			$this->logger->info(
				sprintf(
					/* translators: 1: import ID, 2: number of records */
					__( 'WP All Import #%1$d: enqueued %2$d records for Odoo sync.', 'wp4odoo' ),
					$import_id,
					$count
				),
				[
					'import_id' => $import_id,
					'count'     => $count,
				]
			);
		}
	}
}
