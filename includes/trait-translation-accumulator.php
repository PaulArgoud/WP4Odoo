<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation accumulator for batch pull operations.
 *
 * Accumulates (odoo_id → wp_id) pairs during batch pull, keyed by
 * Odoo model. Flushed at end of batch via flush_pull_translations().
 *
 * Expects the using class to provide:
 * - Logger $logger                   (property)
 * - array  $odoo_models              (property)
 * - Translation_Service translation_service()  (method, from Module_Helpers)
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
trait Translation_Accumulator {

	/**
	 * Translation buffer for pull operations.
	 *
	 * Accumulates (odoo_id → wp_id) pairs during batch pull, keyed by
	 * Odoo model. Flushed at end of batch via flush_pull_translations().
	 *
	 * @var array<string, array<int, int>>
	 */
	protected array $translation_buffer = [];

	/**
	 * Maximum entries per model in the translation buffer.
	 *
	 * Prevents unbounded memory growth during large pull batches.
	 * When exceeded, buffer is flushed early before accumulating more.
	 */
	private const TRANSLATION_BUFFER_MAX = 5000;

	/**
	 * Get translatable fields for a given entity type.
	 *
	 * Override in subclasses to enable automatic pull translation support.
	 * Keys are Odoo field names, values are WordPress field names.
	 * Return empty array to skip translation (default).
	 *
	 * @param string $entity_type Entity type.
	 * @return array<string, string> Odoo field => WP field map.
	 */
	protected function get_translatable_fields( string $entity_type ): array {
		return [];
	}

	/**
	 * Accumulate a pulled record for batch translation at end of batch.
	 *
	 * @param string $odoo_model Odoo model name.
	 * @param int    $odoo_id    Odoo record ID.
	 * @param int    $wp_id      WordPress entity ID.
	 * @return void
	 */
	protected function accumulate_pull_translation( string $odoo_model, int $odoo_id, int $wp_id ): void {
		if ( isset( $this->translation_buffer[ $odoo_model ] )
			&& count( $this->translation_buffer[ $odoo_model ] ) >= self::TRANSLATION_BUFFER_MAX ) {
			$this->flush_pull_translations();
		}
		$this->translation_buffer[ $odoo_model ][ $odoo_id ] = $wp_id;
	}

	/**
	 * Flush accumulated pull translations.
	 *
	 * Called by Sync_Engine after each batch. For each Odoo model in the
	 * buffer, fetches translations for all accumulated records and applies
	 * them to the corresponding WordPress entities via Translation_Service.
	 *
	 * @return void
	 */
	public function flush_pull_translations(): void {
		if ( empty( $this->translation_buffer ) ) {
			return;
		}

		$ts = $this->translation_service();
		if ( ! $ts->is_available() ) {
			$this->translation_buffer = [];
			return;
		}

		// Resolve entity type from Odoo model (reverse lookup).
		$model_to_entity = array_flip( $this->odoo_models );

		foreach ( $this->translation_buffer as $odoo_model => $odoo_wp_map ) {
			$entity_type = $model_to_entity[ $odoo_model ] ?? '';
			if ( '' === $entity_type ) {
				unset( $this->translation_buffer[ $odoo_model ] );
				continue;
			}

			$field_map = $this->get_translatable_fields( $entity_type );
			if ( empty( $field_map ) ) {
				unset( $this->translation_buffer[ $odoo_model ] );
				continue;
			}

			try {
				$ts->pull_translations_batch(
					$odoo_model,
					$odoo_wp_map,
					array_keys( $field_map ),
					$field_map,
					$entity_type,
					fn( int $wp_id, array $data, string $lang ) => $this->apply_pull_translation( $wp_id, $data, $lang ),
					[]
				);

				// Only remove from buffer after successful flush.
				unset( $this->translation_buffer[ $odoo_model ] );

				$this->logger->info(
					'Flushed pull translations.',
					[
						'entity_type' => $entity_type,
						'count'       => count( $odoo_wp_map ),
					]
				);
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'Pull translation flush failed; entries retained in buffer.',
					[
						'entity_type' => $entity_type,
						'count'       => count( $odoo_wp_map ),
						'error'       => $e->getMessage(),
					]
				);
			}
		}
	}

	/**
	 * Apply a translated value to a WordPress entity.
	 *
	 * Default implementation is a no-op. Override for entities that support
	 * translations (post fields via wp_update_post, taxonomy terms, etc.).
	 *
	 * @param int                    $wp_id WordPress entity ID.
	 * @param array<string, string>  $data  WP field => translated value.
	 * @param string                 $lang  Language code.
	 * @return void
	 */
	protected function apply_pull_translation( int $wp_id, array $data, string $lang ): void {
		// Default: no-op. Modules override if they have a Translation_Adapter.
	}
}
