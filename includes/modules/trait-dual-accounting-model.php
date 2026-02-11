<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared logic for modules using the dual Odoo accounting model.
 *
 * Provides OCA donation.donation detection, auto-validation, parent
 * entity sync, and Partner_Service access. Used by GiveWP, Charitable,
 * and SimplePay modules.
 *
 * Expects the using class to extend Module_Base, providing:
 * - client(): Odoo_Client       (from Module_Base)
 * - get_settings(): array        (from Module_Base)
 * - get_mapping(): ?int          (from Module_Base)
 * - logger: Logger               (from Module_Base)
 * - odoo_models: array           (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
trait Dual_Accounting_Model {

	/**
	 * Lazy Partner_Service instance.
	 *
	 * @var Partner_Service|null
	 */
	private ?Partner_Service $partner_service = null;

	/**
	 * Cached OCA donation model detection result.
	 *
	 * @var bool|null
	 */
	private ?bool $donation_model_detected = null;

	/**
	 * Check whether the OCA donation.donation model exists in Odoo.
	 *
	 * Probes Odoo's ir.model registry. Result cached in a transient
	 * (1 hour) and in-memory for the request.
	 *
	 * @return bool
	 */
	private function has_donation_model(): bool {
		if ( null !== $this->donation_model_detected ) {
			return $this->donation_model_detected;
		}

		$cached = get_transient( 'wp4odoo_has_donation_model' );
		if ( false !== $cached ) {
			$this->donation_model_detected = (bool) $cached;
			return $this->donation_model_detected;
		}

		try {
			$count  = $this->client()->search_count(
				'ir.model',
				[ [ 'model', '=', 'donation.donation' ] ]
			);
			$result = $count > 0;
		} catch ( \Exception $e ) {
			$result = false;
		}

		set_transient( 'wp4odoo_has_donation_model', $result ? 1 : 0, HOUR_IN_SECONDS );
		$this->donation_model_detected = $result;

		return $result;
	}

	/**
	 * Resolve the Odoo model for an accounting entity at runtime.
	 *
	 * Sets $this->odoo_models[$entity_key] to donation.donation if the
	 * OCA module is detected, or account.move otherwise.
	 *
	 * @param string $entity_key Entity key in $odoo_models (e.g., 'donation' or 'payment').
	 * @return void
	 */
	private function resolve_accounting_model( string $entity_key ): void {
		if ( $this->has_donation_model() ) {
			$this->odoo_models[ $entity_key ] = 'donation.donation';
		} else {
			$this->odoo_models[ $entity_key ] = 'account.move';
		}
	}

	/**
	 * Ensure a parent entity (form/campaign) is synced before pushing a child.
	 *
	 * Reads the parent ID from post meta and pushes it synchronously if
	 * no Odoo mapping exists yet.
	 *
	 * @param int    $wp_id              Child entity WordPress ID.
	 * @param string $meta_key           Post meta key containing the parent WP ID.
	 * @param string $parent_entity_type Parent entity type (e.g., 'form', 'campaign').
	 * @return void
	 */
	private function ensure_parent_synced( int $wp_id, string $meta_key, string $parent_entity_type ): void {
		$parent_id = (int) get_post_meta( $wp_id, $meta_key, true );
		if ( $parent_id <= 0 ) {
			return;
		}

		$odoo_parent_id = $this->get_mapping( $parent_entity_type, $parent_id );
		if ( $odoo_parent_id ) {
			return;
		}

		$this->logger->info(
			sprintf( 'Auto-pushing %s before child entity.', $parent_entity_type ),
			[ $parent_entity_type . '_id' => $parent_id ]
		);
		parent::push_to_odoo( $parent_entity_type, 'create', $parent_id );
	}

	/**
	 * Auto-validate an accounting entity in Odoo after creation.
	 *
	 * For OCA donation.donation: calls validate().
	 * For core account.move: calls action_post.
	 *
	 * @param string      $entity_key      Entity key in $odoo_models.
	 * @param int         $wp_id           WordPress entity ID.
	 * @param string      $setting_key     Settings key for the auto-validate toggle.
	 * @param string|null $required_status Required WP post status, or null to skip check.
	 * @return void
	 */
	private function auto_validate( string $entity_key, int $wp_id, string $setting_key, ?string $required_status = null ): void {
		$settings = $this->get_settings();
		if ( empty( $settings[ $setting_key ] ) ) {
			return;
		}

		if ( null !== $required_status && get_post_status( $wp_id ) !== $required_status ) {
			return;
		}

		$odoo_id = $this->get_mapping( $entity_key, $wp_id );
		if ( ! $odoo_id ) {
			return;
		}

		$model  = $this->odoo_models[ $entity_key ];
		$method = 'donation.donation' === $model ? 'validate' : 'action_post';

		try {
			$this->client()->execute(
				$model,
				$method,
				[ [ $odoo_id ] ]
			);
			$this->logger->info(
				'Auto-validated entity in Odoo.',
				[
					'wp_id'   => $wp_id,
					'odoo_id' => $odoo_id,
					'model'   => $model,
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Could not auto-validate entity.',
				[
					'wp_id'   => $wp_id,
					'odoo_id' => $odoo_id,
					'error'   => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Get or create the Partner_Service instance.
	 *
	 * @return Partner_Service
	 */
	private function partner_service(): Partner_Service {
		if ( null === $this->partner_service ) {
			$this->partner_service = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		}

		return $this->partner_service;
	}
}
