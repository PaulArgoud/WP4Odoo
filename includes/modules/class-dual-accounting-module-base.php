<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for donation/payment modules (GiveWP, Charitable, SimplePay).
 *
 * Provides shared sync logic for modules that push a parent entity
 * (form/campaign) as an Odoo product and a child entity (donation/payment)
 * as either an OCA donation record or a core invoice, with automatic
 * runtime model detection.
 *
 * Subclasses provide plugin-specific handler delegation, meta key
 * configuration, and donor name extraction via abstract methods.
 *
 * @package WP4Odoo
 * @since   2.2.0
 */
abstract class Dual_Accounting_Module_Base extends Module_Base {

	// ─── OCA donation model detection ──────────────────────

	/**
	 * Check whether the OCA donation.donation model exists in Odoo.
	 *
	 * Delegates to Module_Helpers::has_odoo_model().
	 *
	 * @return bool
	 */
	private function has_donation_model(): bool {
		return $this->has_odoo_model( Odoo_Model::Donation, 'wp4odoo_has_donation_model' );
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
			$this->odoo_models[ $entity_key ] = Odoo_Model::Donation->value;
		} else {
			$this->odoo_models[ $entity_key ] = Odoo_Model::AccountMove->value;
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
		$method = Odoo_Model::Donation->value === $model ? 'validate' : 'action_post';

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

	// ─── Subclass configuration ─────────────────────────────

	/**
	 * Get the entity type for the child (donation/payment).
	 *
	 * @return string 'donation' for GiveWP/Charitable, 'payment' for SimplePay.
	 */
	abstract protected function get_child_entity_type(): string;

	/**
	 * Get the entity type for the parent (form/campaign).
	 *
	 * @return string 'form' for GiveWP/SimplePay, 'campaign' for Charitable.
	 */
	abstract protected function get_parent_entity_type(): string;

	/**
	 * Get the custom post type slug for the child entity.
	 *
	 * @return string 'give_payment', 'donation', or 'wp4odoo_spay'.
	 */
	abstract protected function get_child_cpt(): string;

	/**
	 * Get the post meta key containing the donor/payer email.
	 *
	 * @return string Meta key.
	 */
	abstract protected function get_email_meta_key(): string;

	/**
	 * Get the post meta key containing the parent entity's WP ID.
	 *
	 * @return string Meta key.
	 */
	abstract protected function get_parent_meta_key(): string;

	/**
	 * Get the settings key for the auto-validate toggle.
	 *
	 * @return string Settings key (e.g. 'auto_validate_donations').
	 */
	abstract protected function get_validate_setting_key(): string;

	/**
	 * Get the WP post status required for auto-validation, or null to skip.
	 *
	 * @return string|null Required status, or null.
	 */
	abstract protected function get_validate_status(): ?string;

	// ─── Handler delegation ─────────────────────────────────

	/**
	 * Load the parent entity (form/campaign) from the plugin's handler.
	 *
	 * @param int $wp_id Parent entity WordPress ID.
	 * @return array<string, mixed> Parent data, or empty if not found.
	 */
	abstract protected function handler_load_parent( int $wp_id ): array;

	/**
	 * Extract the donor/payer display name for a child entity.
	 *
	 * Delegates to the plugin's handler — extraction logic differs
	 * per plugin (single meta vs first/last name metas).
	 *
	 * @param int $wp_id Child entity WordPress ID.
	 * @return string Donor/payer name, or empty string.
	 */
	abstract protected function handler_get_donor_name( int $wp_id ): string;

	/**
	 * Load the child entity (donation/payment) from the plugin's handler.
	 *
	 * Called after partner and parent Odoo ID have been resolved.
	 *
	 * @param int  $wp_id              Child entity WordPress ID.
	 * @param int  $partner_id         Resolved Odoo partner ID.
	 * @param int  $parent_odoo_id     Resolved Odoo product ID for the parent.
	 * @param bool $use_donation_model Whether the OCA donation model is in use.
	 * @return array<string, mixed> Odoo-ready data.
	 */
	abstract protected function handler_load_child( int $wp_id, int $partner_id, int $parent_odoo_id, bool $use_donation_model ): array;

	// ─── Shared sync direction ──────────────────────────────

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For child entities: resolves the Odoo model dynamically (OCA donation
	 * vs core invoice), ensures the parent is synced, and auto-validates.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( $this->get_child_entity_type() === $entity_type && 'delete' !== $action ) {
			$this->resolve_accounting_model( $entity_type );
			$this->ensure_parent_synced( $wp_id, $this->get_parent_meta_key(), $this->get_parent_entity_type() );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result->succeeded() && $this->get_child_entity_type() === $entity_type && 'create' === $action ) {
			$this->auto_validate( $entity_type, $wp_id, $this->get_validate_setting_key(), $this->get_validate_status() );
		}

		return $result;
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Child entities bypass standard mapping — the handler pre-formats
	 * for the target Odoo model. Parent entities use standard field mapping.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( $this->get_child_entity_type() === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			$this->get_parent_entity_type() => $this->handler_load_parent( $wp_id ),
			$this->get_child_entity_type()  => $this->load_child_data( $wp_id ),
			default                         => [],
		};
	}

	/**
	 * Load and resolve a child entity (donation/payment) with Odoo references.
	 *
	 * Validates the CPT, resolves donor email → partner and
	 * parent entity → Odoo product ID, then delegates to the handler.
	 *
	 * @param int $wp_id Child entity WordPress ID.
	 * @return array<string, mixed>
	 */
	private function load_child_data( int $wp_id ): array {
		$post = get_post( $wp_id );
		if ( ! $post || $this->get_child_cpt() !== $post->post_type ) {
			return [];
		}

		// Resolve donor/payer → partner via email.
		$email = (string) get_post_meta( $wp_id, $this->get_email_meta_key(), true );
		if ( empty( $email ) ) {
			$this->logger->warning(
				sprintf( '%s has no donor/payer email.', ucfirst( $this->get_child_entity_type() ) ),
				[ 'wp_id' => $wp_id ]
			);
			return [];
		}

		$name       = $this->handler_get_donor_name( $wp_id );
		$partner_id = $this->resolve_partner_from_email( $email, $name ?: $email );

		if ( ! $partner_id ) {
			$this->logger->warning(
				sprintf( 'Cannot resolve partner for %s.', $this->get_child_entity_type() ),
				[ 'wp_id' => $wp_id ]
			);
			return [];
		}

		// Resolve parent → Odoo product ID.
		$parent_id      = (int) get_post_meta( $wp_id, $this->get_parent_meta_key(), true );
		$parent_odoo_id = 0;
		if ( $parent_id > 0 ) {
			$parent_odoo_id = $this->get_mapping( $this->get_parent_entity_type(), $parent_id ) ?? 0;
		}

		if ( ! $parent_odoo_id ) {
			$this->logger->warning(
				sprintf( 'Cannot resolve Odoo product for %s.', $this->get_parent_entity_type() ),
				[ $this->get_parent_entity_type() . '_id' => $parent_id ]
			);
			return [];
		}

		$use_donation_model = Odoo_Model::Donation->value === $this->odoo_models[ $this->get_child_entity_type() ];

		return $this->handler_load_child( $wp_id, $partner_id, $parent_odoo_id, $use_donation_model );
	}
}
