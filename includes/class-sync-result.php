<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object returned by push_to_odoo() and pull_from_odoo().
 *
 * Replaces the bare `bool` return with structured information:
 * success/failure, the affected entity ID, an optional message,
 * and an error classification for retry strategy.
 *
 * @package WP4Odoo
 * @since   2.3.0
 */
final class Sync_Result {

	/**
	 * @param bool            $success    Whether the operation succeeded.
	 * @param int|null        $entity_id  The created/updated Odoo or WP entity ID (null if not applicable).
	 * @param string          $message    Human-readable context (empty on success, error description on failure).
	 * @param Error_Type|null $error_type Error classification (null on success).
	 */
	private function __construct(
		private readonly bool $success,
		private readonly ?int $entity_id,
		private readonly string $message,
		private readonly ?Error_Type $error_type,
	) {}

	/**
	 * Create a success result.
	 *
	 * @param int|null $entity_id The Odoo or WP entity ID (null if not applicable).
	 * @param string   $message   Optional context message.
	 * @return self
	 */
	public static function success( ?int $entity_id = null, string $message = '' ): self {
		return new self( true, $entity_id, $message, null );
	}

	/**
	 * Create a failure result.
	 *
	 * @param string     $message    Error description.
	 * @param Error_Type $error_type Error classification (defaults to Transient for backward compat).
	 * @param int|null   $entity_id  Optional entity ID created before the failure (e.g., Odoo record created but mapping save failed).
	 * @return self
	 */
	public static function failure( string $message, Error_Type $error_type = Error_Type::Transient, ?int $entity_id = null ): self {
		return new self( false, $entity_id, $message, $error_type );
	}

	/**
	 * Whether the sync operation succeeded.
	 *
	 * @return bool
	 */
	public function succeeded(): bool {
		return $this->success;
	}

	/**
	 * Get the affected entity ID (Odoo ID for push, WP ID for pull).
	 *
	 * @return int|null Null when the operation has no associated entity.
	 */
	public function get_entity_id(): ?int {
		return $this->entity_id;
	}

	/**
	 * Get the human-readable message.
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Get the error classification (null on success).
	 *
	 * @return Error_Type|null
	 */
	public function get_error_type(): ?Error_Type {
		return $this->error_type;
	}
}
