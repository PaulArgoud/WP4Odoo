<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Push/pull orchestration for Module_Base.
 *
 * Extracted from Module_Base to reduce class size. Contains the three
 * main sync entry points called by the Sync_Engine: push_to_odoo(),
 * push_batch_creates(), and pull_from_odoo().
 *
 * Mixed into Module_Base and accesses its protected properties/methods
 * through $this.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
trait Sync_Orchestrator {

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Called by the Sync_Engine when processing a wp_to_odoo job.
	 *
	 * @param string $entity_type The entity type (e.g., 'product').
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		$model = $this->get_odoo_model( $entity_type );

		try {
			if ( 'delete' === $action ) {
				if ( $odoo_id > 0 ) {
					$this->client()->unlink( $model, [ $odoo_id ] );
					$this->remove_mapping( $entity_type, $wp_id );
					$this->logger->info( 'Deleted Odoo record.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				}
				return Sync_Result::success( $odoo_id );
			}

			$wp_data                  = ! empty( $payload ) ? $payload : $this->load_wp_data( $entity_type, $wp_id );
			$wp_data['_wp_entity_id'] = $wp_id;
			$odoo_values              = $this->map_to_odoo( $entity_type, $wp_data );
			$odoo_values              = $this->maybe_inject_company_id( $odoo_values, $model );

			if ( empty( $odoo_values ) ) {
				$this->logger->warning( 'No data to push.', compact( 'entity_type', 'wp_id' ) );
				return Sync_Result::failure( 'No data to push.', Error_Type::Permanent );
			}

			$new_hash = $this->generate_sync_hash( $odoo_values );

			// Check for existing mapping (might have been created since enqueue).
			if ( 'create' === $action || 0 === $odoo_id ) {
				$existing_odoo_id = $this->get_mapping( $entity_type, $wp_id );
				if ( $existing_odoo_id ) {
					$odoo_id = $existing_odoo_id;
					$action  = 'update';
				}
			}

			if ( 'update' === $action && $odoo_id > 0 ) {
				$this->client()->write( $model, [ $odoo_id ], $odoo_values );
				if ( ! $this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash ) ) {
					$this->logger->error( 'Mapping save failed after Odoo update.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
					return Sync_Result::failure( 'Mapping save failed after Odoo update.', Error_Type::Transient, $odoo_id );
				}
				$this->logger->info( 'Updated Odoo record.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
			} else {
				// Advisory lock prevents TOCTOU race: two concurrent workers
				// both searching Odoo and finding nothing, then both creating.
				// Same proven pattern as Partner_Service.
				$lock_name = 'wp4odoo_push_' . md5( $this->id . ':' . $entity_type . ':' . $wp_id );
				$locked    = $this->acquire_push_lock( $lock_name );

				if ( ! $locked ) {
					return Sync_Result::failure(
						__( 'Push lock timeout — will retry.', 'wp4odoo' ),
						Error_Type::Transient
					);
				}

				try {
					// Re-check mapping under lock (another process may have
					// completed the create between our initial check and lock acquisition).
					$this->entity_map()->invalidate_key( $this->id, $entity_type, $wp_id );
					$existing_odoo_id = $this->get_mapping( $entity_type, $wp_id );
					if ( $existing_odoo_id ) {
						$this->client()->write( $model, [ $existing_odoo_id ], $odoo_values );
						$this->save_mapping( $entity_type, $wp_id, $existing_odoo_id, $new_hash );
						$this->logger->info( 'Dedup lock: found mapping after lock, switched to update.', compact( 'entity_type', 'wp_id' ) );
						return Sync_Result::success( $existing_odoo_id );
					}

					// Dedup: search Odoo for an existing record before creating.
					$dedup_domain = $this->get_dedup_domain( $entity_type, $odoo_values );
					if ( ! empty( $dedup_domain ) ) {
						$existing = $this->client()->search( $model, $dedup_domain, 0, 1 );
						if ( ! empty( $existing ) ) {
							$odoo_id = $existing[0];
							$this->logger->info( 'Dedup: found existing Odoo record, switching to update.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
							$this->client()->write( $model, [ $odoo_id ], $odoo_values );
							$this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash );
							return Sync_Result::success( $odoo_id );
						}
					}

					$odoo_id = $this->client()->create( $model, $odoo_values );
					$saved   = $this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash );
					if ( ! $saved ) {
						$this->logger->error( 'Mapping save failed after Odoo create.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
						return Sync_Result::failure( 'Mapping save failed after Odoo create.', Error_Type::Transient, $odoo_id );
					}
					$this->logger->info( 'Created Odoo record.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				} finally {
					$this->release_push_lock();
				}
			}

			return Sync_Result::success( $odoo_id );
		} catch ( \InvalidArgumentException $e ) {
			return Sync_Result::failure( $e->getMessage(), Error_Type::Permanent, $odoo_id > 0 ? $odoo_id : null );
		} catch ( \RuntimeException $e ) {
			// Classify: connection/server errors are transient; validation/access errors are permanent.
			$error_type = self::classify_exception( $e );
			return Sync_Result::failure( $e->getMessage(), $error_type, $odoo_id > 0 ? $odoo_id : null );
		}
	}

	/**
	 * Batch-create multiple entities in a single Odoo API call.
	 *
	 * Loads data and maps values for each item, then calls create_batch()
	 * on the Odoo client. Falls back to individual push_to_odoo() calls
	 * if the batch call fails.
	 *
	 * @param string                                   $entity_type Entity type.
	 * @param array<int, array{wp_id: int, payload: array<string, mixed>}> $items Items to create.
	 * @return array<int, Sync_Result> Results indexed by wp_id.
	 */
	public function push_batch_creates( string $entity_type, array $items ): array {
		$model       = $this->get_odoo_model( $entity_type );
		$results     = [];
		$values_list = [];
		$item_map    = []; // Ordered list of items that passed validation.

		// Batch lookup: single DB query instead of N individual get_mapping() calls.
		$wp_ids            = array_column( $items, 'wp_id' );
		$existing_mappings = $this->entity_map->get_odoo_ids_batch( $this->id, $entity_type, $wp_ids );

		foreach ( $items as $item ) {
			$wp_id = $item['wp_id'];

			// Skip if already mapped (might have been created since enqueue).
			if ( isset( $existing_mappings[ $wp_id ] ) ) {
				$results[ $wp_id ] = Sync_Result::success( $existing_mappings[ $wp_id ] );
				continue;
			}

			$wp_data                  = ! empty( $item['payload'] ) ? $item['payload'] : $this->load_wp_data( $entity_type, $wp_id );
			$wp_data['_wp_entity_id'] = $wp_id;
			$odoo_values              = $this->map_to_odoo( $entity_type, $wp_data );
			$odoo_values              = $this->maybe_inject_company_id( $odoo_values, $model );

			if ( empty( $odoo_values ) ) {
				$results[ $wp_id ] = Sync_Result::failure( 'No data to push.', Error_Type::Permanent );
				continue;
			}

			$values_list[] = $odoo_values;
			$item_map[]    = [
				'wp_id' => $wp_id,
				'hash'  => $this->generate_sync_hash( $odoo_values ),
			];
		}

		if ( empty( $values_list ) ) {
			return $results;
		}

		// Advisory lock prevents duplicate batch creation when concurrent
		// workers process the same module+entity group simultaneously.
		// Individual push_to_odoo() has Push_Lock, but batch creates bypass
		// that path, so they need their own lock.
		$lock = new Advisory_Lock( 'wp4odoo_batch_' . $this->id . '_' . $model );

		if ( ! $lock->acquire() ) {
			$this->logger->warning(
				'Batch lock timeout — falling back to individual creates.',
				[
					'entity_type' => $entity_type,
					'count'       => count( $values_list ),
				]
			);

			foreach ( $item_map as $entry ) {
				if ( ! isset( $results[ $entry['wp_id'] ] ) ) {
					$results[ $entry['wp_id'] ] = $this->push_to_odoo( $entity_type, 'create', $entry['wp_id'] );
				}
			}

			return $results;
		}

		try {
			$ids = $this->client()->create_batch( $model, $values_list );

			foreach ( $ids as $i => $odoo_id ) {
				$wp_id = $item_map[ $i ]['wp_id'];
				$hash  = $item_map[ $i ]['hash'];
				$this->save_mapping( $entity_type, $wp_id, $odoo_id, $hash );
				$results[ $wp_id ] = Sync_Result::success( $odoo_id );
			}

			$this->logger->info(
				'Batch created Odoo records.',
				[
					'entity_type' => $entity_type,
					'count'       => count( $ids ),
				]
			);
		} catch ( \Throwable $e ) {
			// Fallback: process individually (push_to_odoo has its own Push_Lock).
			$this->logger->warning(
				'Batch create failed, falling back to individual creates.',
				[
					'entity_type' => $entity_type,
					'error'       => $e->getMessage(),
				]
			);

			foreach ( $item_map as $entry ) {
				if ( ! isset( $results[ $entry['wp_id'] ] ) ) {
					$results[ $entry['wp_id'] ] = $this->push_to_odoo( $entity_type, 'create', $entry['wp_id'] );
				}
			}
		} finally {
			$lock->release();
		}

		return $results;
	}

	/**
	 * Pull an Odoo entity into WordPress.
	 *
	 * Called by the Sync_Engine when processing an odoo_to_wp job.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo entity ID.
	 * @param int    $wp_id       WordPress ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): Sync_Result {
		$this->mark_importing();

		try {
			$model = $this->get_odoo_model( $entity_type );

			if ( 'delete' === $action ) {
				if ( 0 === $wp_id ) {
					$wp_id = $this->get_wp_mapping( $entity_type, $odoo_id ) ?? 0;
				}
				if ( $wp_id > 0 ) {
					$this->delete_wp_data( $entity_type, $wp_id );
					$this->remove_mapping( $entity_type, $wp_id );
					$this->logger->info( 'Deleted WP entity from Odoo signal.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				}
				return Sync_Result::success( $wp_id );
			}

			// Fetch fresh data from Odoo.
			$records = $this->client()->read( $model, [ $odoo_id ] );

			if ( empty( $records ) ) {
				$this->logger->warning( 'Odoo record not found during pull.', compact( 'entity_type', 'odoo_id' ) );
				return Sync_Result::failure( 'Odoo record not found during pull.', Error_Type::Permanent );
			}

			$odoo_data = $records[0];
			$wp_data   = $this->map_from_odoo( $entity_type, $odoo_data );

			/**
			 * Filter WordPress data after mapping from Odoo.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $wp_data     The mapped WordPress data.
			 * @param array  $odoo_data   The raw Odoo record data.
			 * @param string $entity_type The entity type.
			 */
			$wp_data = apply_filters( "wp4odoo_map_from_odoo_{$this->id}_{$entity_type}", $wp_data, $odoo_data, $entity_type );

			// Find existing WP entity.
			if ( 0 === $wp_id ) {
				$wp_id = $this->get_wp_mapping( $entity_type, $odoo_id ) ?? 0;
			}

			$wp_id = $this->save_wp_data( $entity_type, $wp_data, $wp_id );

			if ( $wp_id > 0 ) {
				/**
				 * Fires after a WordPress entity has been saved during an Odoo pull.
				 *
				 * Allows meta-modules (e.g. ACF) to write additional data
				 * that requires the WP entity ID to exist.
				 *
				 * @since 3.1.0
				 *
				 * @param int    $wp_id       The saved WordPress entity ID.
				 * @param array  $wp_data     The mapped WordPress data.
				 * @param string $entity_type The entity type.
				 * @param array  $odoo_data   The raw Odoo record data.
				 */
				do_action( "wp4odoo_after_save_{$this->id}_{$entity_type}", $wp_id, $wp_data, $entity_type, $odoo_data );

				$new_hash = $this->generate_sync_hash( $odoo_data );
				$this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash );

				// Accumulate for batch translation flush if module provides translatable fields.
				if ( ! empty( $this->get_translatable_fields( $entity_type ) ) ) {
					$this->accumulate_pull_translation( $model, $odoo_id, $wp_id );
				}

				$this->logger->info( 'Pulled from Odoo.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				return Sync_Result::success( $wp_id );
			}

			$this->logger->error( 'Failed to save WP data during pull.', compact( 'entity_type', 'odoo_id' ) );
			return Sync_Result::failure( 'Failed to save WP data during pull.', Error_Type::Transient );
		} finally {
			$this->clear_importing();
		}
	}

	/**
	 * Cached company ID for the current request.
	 *
	 * Avoids repeated calls to client()->get_company_id() during batch
	 * processing (200 jobs = 200 redundant calls without caching).
	 * Reset to null on switch_to_blog() via clear_importing().
	 *
	 * @var int|null Null = not yet resolved, 0 = no multi-company, >0 = company ID.
	 */
	private ?int $cached_company_id = null;

	/**
	 * Inject company_id into Odoo values when multi-company is active.
	 *
	 * Some Odoo models (sale.order, account.move, product.template) have
	 * a company_id field that must be set explicitly on create for proper
	 * multi-company isolation. The context-level allowed_company_ids
	 * handles filtering, but record-level company_id ensures the created
	 * record is assigned to the correct company.
	 *
	 * Skips injection when the schema cache confirms the model does not
	 * have a company_id field (e.g. res.currency, product.category).
	 *
	 * Modules can override this by setting company_id in their map_to_odoo().
	 *
	 * @param array  $odoo_values The mapped Odoo field values.
	 * @param string $model       The Odoo model name.
	 * @return array The values with company_id injected if applicable.
	 */
	private function maybe_inject_company_id( array $odoo_values, string $model ): array {
		if ( isset( $odoo_values['company_id'] ) ) {
			return $odoo_values;
		}

		// Skip injection if the schema cache confirms the model lacks company_id.
		// When the cache is empty (API unreachable), we err on the side of injecting
		// — Odoo silently ignores unknown fields on write in most configurations.
		$fields = Schema_Cache::get_fields( fn() => $this->client(), $model );
		if ( ! empty( $fields ) && ! isset( $fields['company_id'] ) ) {
			return $odoo_values;
		}

		if ( null === $this->cached_company_id ) {
			try {
				$this->cached_company_id = $this->client()->get_company_id();
			} catch ( \Throwable $e ) {
				$this->logger->warning(
					'Could not retrieve company_id from Odoo.',
					[ 'error' => $e->getMessage() ]
				);
				$this->cached_company_id = 0;
			}
		}

		if ( $this->cached_company_id > 0 ) {
			$odoo_values['company_id'] = $this->cached_company_id;
		}

		return $odoo_values;
	}
}
