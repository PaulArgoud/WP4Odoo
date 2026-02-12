<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\API\Odoo_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helper methods for Module_Base.
 *
 * Extracted from Module_Base to keep the base class focused on
 * push/pull orchestration, entity mapping, and field mapping.
 *
 * These helpers are mixed into Module_Base via `use Module_Helpers;`
 * and access its protected properties/methods through $this.
 *
 * @package WP4Odoo
 * @since   2.8.0
 */
trait Module_Helpers {

	/**
	 * Lazy Partner_Service instance.
	 *
	 * @var Partner_Service|null
	 */
	private ?Partner_Service $partner_service_instance = null;

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
	 * Auto-post an invoice in Odoo after successful creation.
	 *
	 * Checks the module setting, retrieves the Odoo mapping, and calls
	 * `account.move.action_post`. Logs success or failure.
	 *
	 * @param string $setting_key Settings key to check (e.g., 'auto_post_invoices').
	 * @param string $entity_type Entity type for mapping lookup.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return void
	 */
	protected function auto_post_invoice( string $setting_key, string $entity_type, int $wp_id ): void {
		$settings = $this->get_settings();
		if ( empty( $settings[ $setting_key ] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( $entity_type, $wp_id );
		if ( ! $odoo_id ) {
			return;
		}

		try {
			$this->client()->execute(
				'account.move',
				'action_post',
				[ [ $odoo_id ] ]
			);
			$this->logger->info(
				'Auto-posted invoice in Odoo.',
				[
					'entity_type' => $entity_type,
					'wp_id'       => $wp_id,
					'odoo_id'     => $odoo_id,
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Could not auto-post invoice.',
				[
					'entity_type' => $entity_type,
					'wp_id'       => $wp_id,
					'odoo_id'     => $odoo_id,
					'error'       => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Ensure a parent entity is synced to Odoo before pushing a dependent entity.
	 *
	 * Checks the entity mapping; if the parent is not yet synced, pushes it
	 * synchronously via `Module_Base::push_to_odoo()`.
	 *
	 * @param string $entity_type Parent entity type (e.g., 'course', 'level').
	 * @param int    $wp_id       WordPress ID of the parent entity.
	 * @return void
	 */
	protected function ensure_entity_synced( string $entity_type, int $wp_id ): void {
		if ( $wp_id <= 0 ) {
			return;
		}

		$existing = $this->get_mapping( $entity_type, $wp_id );
		if ( $existing ) {
			return;
		}

		$this->logger->info(
			"Auto-pushing {$entity_type} before dependent entity.",
			[ 'wp_id' => $wp_id ]
		);
		$this->push_to_odoo( $entity_type, 'create', $wp_id );
	}

	// ─── Utility helpers ───────────────────────────────────

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

	/**
	 * Get or create the Partner_Service instance (lazy).
	 *
	 * Used by any module that needs to resolve WordPress users or
	 * guest emails to Odoo res.partner records.
	 *
	 * @return Partner_Service
	 */
	protected function partner_service(): Partner_Service {
		if ( null === $this->partner_service_instance ) {
			$this->partner_service_instance = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		}

		return $this->partner_service_instance;
	}

	/**
	 * Check whether an external plugin dependency is available.
	 *
	 * Helper for get_dependency_status() — returns a standard
	 * available/notices array. Modules call this with their own check.
	 *
	 * @param bool   $is_available Whether the dependency is met.
	 * @param string $plugin_name  Human-readable plugin name for the notice.
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	protected function check_dependency( bool $is_available, string $plugin_name ): array {
		if ( ! $is_available ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => sprintf(
							/* translators: %s: plugin name */
							__( '%s must be installed and activated to use this module.', 'wp4odoo' ),
							$plugin_name
						),
					],
				],
			];
		}

		return [
			'available' => true,
			'notices'   => [],
		];
	}
}
