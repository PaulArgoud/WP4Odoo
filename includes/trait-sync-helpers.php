<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\I18n\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync utility helpers for Module_Base.
 *
 * Provides synthetic ID encoding/decoding, push_entity() queue helper,
 * Translation_Service access, Many2one field resolution, and general
 * utility methods (delete post, log unsupported entity).
 *
 * Mixed into Module_Base via Module_Helpers and accesses its
 * protected properties/methods through $this.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
trait Sync_Helpers {

	/**
	 * Lazy Translation_Service instance.
	 *
	 * @var Translation_Service|null
	 */
	private ?Translation_Service $translation_service_instance = null;

	// ─── Synthetic ID helpers ──────────────────────────────

	/**
	 * Multiplier for synthetic ID encoding (user_id * MULTIPLIER + entity_id).
	 *
	 * @var int
	 */
	private const SYNTHETIC_ID_MULTIPLIER = 1_000_000;

	/**
	 * Encode two IDs into a single synthetic ID.
	 *
	 * Used by LMS modules (LearnDash, LifterLMS) to represent enrollment
	 * as a single integer (user_id * 1M + course_id) for the entity map.
	 *
	 * @param int $primary_id   Primary ID (e.g. user_id).
	 * @param int $secondary_id Secondary ID (e.g. course_id). Must be < 1,000,000.
	 * @return int Synthetic ID.
	 * @throws \OverflowException If secondary_id >= 1,000,000.
	 */
	public static function encode_synthetic_id( int $primary_id, int $secondary_id ): int {
		if ( $secondary_id >= self::SYNTHETIC_ID_MULTIPLIER ) {
			throw new \OverflowException(
				sprintf( 'Secondary ID %d exceeds synthetic ID multiplier %d.', $secondary_id, self::SYNTHETIC_ID_MULTIPLIER )
			);
		}

		return $primary_id * self::SYNTHETIC_ID_MULTIPLIER + $secondary_id;
	}

	/**
	 * Decode a synthetic ID into its two components.
	 *
	 * @param int $synthetic_id Synthetic ID to decode.
	 * @return array{int, int} [ primary_id, secondary_id ].
	 */
	public static function decode_synthetic_id( int $synthetic_id ): array {
		return [
			intdiv( $synthetic_id, self::SYNTHETIC_ID_MULTIPLIER ),
			$synthetic_id % self::SYNTHETIC_ID_MULTIPLIER,
		];
	}

	// ─── Push helpers ──────────────────────────────────────

	/**
	 * Guard + map + push an entity to the sync queue.
	 *
	 * Common pattern used by hook traits: checks the settings toggle,
	 * resolves the existing Odoo mapping, and queues a create or update.
	 * Uses $this->id as the module identifier and the injectable
	 * Queue_Manager instance via $this->queue().
	 *
	 * @param string $entity_type Entity type (e.g., 'level', 'payment').
	 * @param string $setting_key Settings toggle key (e.g., 'sync_levels').
	 * @param int    $wp_id       WordPress entity ID.
	 * @return void
	 */
	protected function push_entity( string $entity_type, string $setting_key, int $wp_id ): void {
		if ( ! $this->should_sync( $setting_key ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		$this->queue()->enqueue_push( $this->id, $entity_type, $action, $wp_id, $odoo_id );
	}

	// ─── Translation helpers ─────────────────────────────

	/**
	 * Get or create the Translation_Service instance (lazy).
	 *
	 * Used by any module that needs to push translated field values
	 * to Odoo (WPML/Polylang). Returns the same instance on each call.
	 *
	 * @return Translation_Service
	 */
	protected function translation_service(): Translation_Service {
		if ( null === $this->translation_service_instance ) {
			$this->translation_service_instance = new Translation_Service( fn() => $this->client(), $this->settings_repo );
		}

		return $this->translation_service_instance;
	}

	// ─── Field resolution helpers ────────────────────────

	/**
	 * Resolve a single field from an Odoo Many2one value.
	 *
	 * Reads the related record and returns the requested field value.
	 * Useful for converting Many2one [id, "Name"] references to scalar values
	 * (e.g., country_id → ISO code, state_id → name).
	 *
	 * @param mixed  $many2one_value The Many2one value from Odoo ([id, "Name"] or false).
	 * @param string $model          The Odoo model to read from (e.g., 'res.country').
	 * @param string $field          The field to extract (e.g., 'code').
	 * @return string|null The field value, or null if unresolvable.
	 */
	protected function resolve_many2one_field( mixed $many2one_value, string $model, string $field ): ?string {
		$id = Field_Mapper::many2one_to_id( $many2one_value );

		if ( null === $id ) {
			return null;
		}

		$records = $this->client()->read( $model, [ $id ], [ $field ] );

		if ( empty( $records ) || ! isset( $records[0] ) ) {
			return null;
		}

		if ( ! empty( $records[0][ $field ] ) ) {
			return (string) $records[0][ $field ];
		}

		return null;
	}

	// ─── Utility helpers ─────────────────────────────────

	/**
	 * Delete a WordPress post (force delete, bypass Trash).
	 *
	 * @param int $wp_id Post ID.
	 * @return bool True on success.
	 */
	protected function delete_wp_post( int $wp_id ): bool {
		$result = wp_delete_post( $wp_id, true );
		return false !== $result && null !== $result;
	}

	/**
	 * Log a warning for an unsupported entity type operation.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $operation   Operation attempted (load, save, delete).
	 * @return void
	 */
	protected function log_unsupported_entity( string $entity_type, string $operation ): void {
		$this->logger->warning(
			"{$this->name}: {$operation} not implemented for entity type '{$entity_type}'.",
			[ 'entity_type' => $entity_type ]
		);
	}
}
