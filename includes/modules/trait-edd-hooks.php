<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD hook callbacks for push sync.
 *
 * Expects the using class to provide:
 * - is_importing(): bool        (from Module_Base)
 * - get_mapping(string, int): ?int (from Module_Base)
 * - logger: Logger              (from Module_Base)
 * - partner_service: Partner_Service (private property on EDD_Module)
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
trait EDD_Hooks {

	/**
	 * Handle download save (push to Odoo queue).
	 *
	 * Hooked to `save_post_download`.
	 *
	 * @param int $post_id Download post ID.
	 * @return void
	 */
	public function on_download_save( int $post_id ): void {
		if ( ! $this->should_sync( 'sync_downloads' ) ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'download', $post_id );
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'edd', 'download', $action, $post_id, $odoo_id ?? 0 );
	}

	/**
	 * Handle download deletion (push delete to Odoo queue).
	 *
	 * Hooked to `before_delete_post`.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_download_delete( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( 'download' !== get_post_type( $post_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'download', $post_id );
		if ( $odoo_id ) {
			Queue_Manager::push( 'edd', 'download', 'delete', $post_id, $odoo_id );
		}
	}

	/**
	 * Handle EDD order status change (push to Odoo queue).
	 *
	 * Hooked to `edd_update_payment_status`.
	 *
	 * @param int    $order_id   EDD order ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @return void
	 */
	public function on_order_status_change( int $order_id, string $new_status, string $old_status ): void {
		if ( $this->is_importing() ) {
			return;
		}

		// Resolve partner on order completion.
		if ( 'complete' === $new_status ) {
			$order = edd_get_order( $order_id );
			if ( $order && '' !== $order->email ) {
				$name = '';
				if ( $order->customer_id > 0 ) {
					$customer     = new \EDD_Customer();
					$customer->id = $order->customer_id;
					$name         = $customer->name;
				}

				$this->partner_service->get_or_create(
					$order->email,
					[ 'name' => $name ?: $order->email ]
				);
			}
		}

		$odoo_id = $this->get_mapping( 'order', $order_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'edd', 'order', $action, $order_id, $odoo_id );
	}
}
