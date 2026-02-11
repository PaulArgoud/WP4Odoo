<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for donation/payment modules (GiveWP, Charitable, SimplePay).
 *
 * Provides shared sync logic for modules that push a parent entity
 * (form/campaign) as an Odoo product and a child entity (donation/payment)
 * as either an OCA donation record or a core invoice, with automatic
 * runtime model detection via the Dual_Accounting_Model trait.
 *
 * Subclasses provide plugin-specific handler delegation, meta key
 * configuration, and donor name extraction via abstract methods.
 *
 * @package WP4Odoo
 * @since   2.2.0
 */
abstract class Dual_Accounting_Module_Base extends Module_Base {

	use Dual_Accounting_Model;

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

	/**
	 * Extract the donor/payer display name for a child entity.
	 *
	 * Reads from post meta — the extraction logic differs per plugin
	 * (single meta vs first/last name metas).
	 *
	 * @param int $wp_id Child entity WordPress ID.
	 * @return string Donor/payer name, or empty string.
	 */
	abstract protected function get_donor_name( int $wp_id ): string;

	// ─── Handler delegation ─────────────────────────────────

	/**
	 * Load the parent entity (form/campaign) from the plugin's handler.
	 *
	 * @param int $wp_id Parent entity WordPress ID.
	 * @return array<string, mixed> Parent data, or empty if not found.
	 */
	abstract protected function handler_load_parent( int $wp_id ): array;

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

		$name       = $this->get_donor_name( $wp_id );
		$partner_id = $this->partner_service()->get_or_create(
			$email,
			[ 'name' => $name ?: $email ],
			0
		);

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

		$use_donation_model = 'donation.donation' === $this->odoo_models[ $this->get_child_entity_type() ];

		return $this->handler_load_child( $wp_id, $partner_id, $parent_odoo_id, $use_donation_model );
	}
}
