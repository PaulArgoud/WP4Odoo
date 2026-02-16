<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Readonly value object representing a sync queue job.
 *
 * Replaces untyped stdClass rows from the database with a structured,
 * type-safe representation. All properties mirror the columns of the
 * {prefix}wp4odoo_sync_queue table.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
readonly class Queue_Job {

	/**
	 * Constructor.
	 *
	 * @param int         $id             Job ID.
	 * @param string|null $correlation_id Correlation UUID for log tracing.
	 * @param string      $module         Module identifier (e.g. 'woocommerce').
	 * @param string      $direction      Sync direction ('wp_to_odoo' or 'odoo_to_wp').
	 * @param string      $entity_type    Entity type (e.g. 'product', 'order').
	 * @param int         $wp_id          WordPress entity ID.
	 * @param int         $odoo_id        Odoo entity ID.
	 * @param string      $action         Job action ('create', 'update', or 'delete').
	 * @param string|null $payload        JSON-encoded payload data.
	 * @param int         $priority       Priority (1-10, lower = higher priority).
	 * @param string      $status         Job status ('pending', 'processing', 'completed', 'failed').
	 * @param int         $attempts       Number of processing attempts.
	 * @param int         $max_attempts   Maximum allowed attempts before permanent failure.
	 * @param string|null $error_message  Last error message (null if none).
	 * @param string|null $scheduled_at   Scheduled retry datetime (null if immediate).
	 * @param string|null $processed_at   Last processing datetime (null if never processed).
	 * @param string      $created_at     Job creation datetime.
	 */
	public function __construct(
		public int $id,
		public ?string $correlation_id,
		public string $module,
		public string $direction,
		public string $entity_type,
		public int $wp_id,
		public int $odoo_id,
		public string $action,
		public ?string $payload,
		public int $priority,
		public string $status,
		public int $attempts,
		public int $max_attempts,
		public ?string $error_message,
		public ?string $scheduled_at,
		public ?string $processed_at,
		public string $created_at,
	) {}

	/**
	 * Create a Queue_Job from a database row (stdClass).
	 *
	 * @param \stdClass $row Database row from wp4odoo_sync_queue.
	 * @return self
	 */
	public static function from_row( \stdClass $row ): self {
		return new self(
			id:             (int) ( $row->id ?? 0 ),
			correlation_id: isset( $row->correlation_id ) ? (string) $row->correlation_id : null,
			module:         (string) ( $row->module ?? '' ),
			direction:      (string) ( $row->direction ?? 'wp_to_odoo' ),
			entity_type:    (string) ( $row->entity_type ?? '' ),
			wp_id:          (int) ( $row->wp_id ?? 0 ),
			odoo_id:        (int) ( $row->odoo_id ?? 0 ),
			action:         (string) ( $row->action ?? 'update' ),
			payload:        isset( $row->payload ) ? (string) $row->payload : null,
			priority:       (int) ( $row->priority ?? 5 ),
			status:         (string) ( $row->status ?? 'pending' ),
			attempts:       (int) ( $row->attempts ?? 0 ),
			max_attempts:   (int) ( $row->max_attempts ?? 3 ),
			error_message:  isset( $row->error_message ) ? (string) $row->error_message : null,
			scheduled_at:   isset( $row->scheduled_at ) ? (string) $row->scheduled_at : null,
			processed_at:   isset( $row->processed_at ) ? (string) $row->processed_at : null,
			created_at:     (string) ( $row->created_at ?? '' ),
		);
	}
}
