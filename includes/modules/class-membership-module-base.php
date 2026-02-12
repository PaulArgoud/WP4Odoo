<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for membership modules (MemberPress, PMPro, RCP).
 *
 * Provides shared push_to_odoo() orchestration (level auto-sync, invoice
 * auto-posting) and load_wp_data() dispatch with partner/level resolution
 * for payment and membership entity types.
 *
 * Subclasses provide entity type names, handler delegation, and
 * plugin-specific data extraction via abstract methods.
 *
 * @package WP4Odoo
 * @since   2.7.0
 */
abstract class Membership_Module_Base extends Module_Base {

	protected string $exclusive_group = 'memberships';

	/**
	 * Sync direction: push-only (WP -> Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	// ─── Entity type names ─────────────────────────────────

	/**
	 * Get the level/plan entity type name.
	 *
	 * @return string 'plan' for MemberPress, 'level' for PMPro/RCP.
	 */
	abstract protected function get_level_entity_type(): string;

	/**
	 * Get the payment entity type name.
	 *
	 * @return string 'transaction' for MemberPress, 'order' for PMPro, 'payment' for RCP.
	 */
	abstract protected function get_payment_entity_type(): string;

	/**
	 * Get the membership entity type name.
	 *
	 * @return string 'subscription' for MemberPress, 'membership' for PMPro/RCP.
	 */
	abstract protected function get_membership_entity_type(): string;

	// ─── Handler delegation ────────────────────────────────

	/**
	 * Load a level/plan via the handler.
	 *
	 * @param int $wp_id Level or plan ID.
	 * @return array<string, mixed>
	 */
	abstract protected function handler_load_level( int $wp_id ): array;

	/**
	 * Load a payment/transaction/order via the handler, with resolved Odoo references.
	 *
	 * @param int $wp_id         Payment entity ID.
	 * @param int $partner_id    Resolved Odoo partner ID.
	 * @param int $level_odoo_id Resolved Odoo product.product ID for the level.
	 * @return array<string, mixed>
	 */
	abstract protected function handler_load_payment( int $wp_id, int $partner_id, int $level_odoo_id ): array;

	/**
	 * Load a membership/subscription via the handler (raw data with user_id and level key).
	 *
	 * Must return array with 'user_id' and '{level_entity_type}_id' keys
	 * for resolution by the base class.
	 *
	 * @param int $wp_id Membership entity ID.
	 * @return array<string, mixed>
	 */
	abstract protected function handler_load_membership( int $wp_id ): array;

	// ─── Data extraction ───────────────────────────────────

	/**
	 * Get user ID and level ID from a payment entity.
	 *
	 * @param int $wp_id Payment entity ID.
	 * @return array{int, int} [user_id, level_id].
	 */
	abstract protected function get_payment_user_and_level( int $wp_id ): array;

	/**
	 * Get the level ID associated with a dependent entity.
	 *
	 * @param int    $wp_id       Entity ID.
	 * @param string $entity_type Entity type (payment or membership).
	 * @return int Level ID, or 0 if not found.
	 */
	abstract protected function get_level_id_for_entity( int $wp_id, string $entity_type ): int;

	/**
	 * Check whether a payment entity is in a completed state.
	 *
	 * Used to guard invoice auto-posting.
	 *
	 * @param int $wp_id Payment entity ID.
	 * @return bool
	 */
	abstract protected function is_payment_complete( int $wp_id ): bool;

	/**
	 * Resolve the member price from a level/plan.
	 *
	 * @param int $level_id Level or plan ID.
	 * @return float Price, or 0.0 if not available.
	 */
	abstract protected function resolve_member_price( int $level_id ): float;

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures the level/plan is synced before payment/membership entities.
	 * Auto-posts invoices for completed new payments.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$level_entity   = $this->get_level_entity_type();
		$payment_entity = $this->get_payment_entity_type();

		// Ensure level is synced before payment/membership.
		if ( $entity_type !== $level_entity && 'delete' !== $action ) {
			$level_id = $this->get_level_id_for_entity( $wp_id, $entity_type );
			if ( $level_id > 0 ) {
				$this->ensure_entity_synced( $level_entity, $level_id );
			}
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		// Auto-post invoice for new completed payments.
		if ( $result->succeeded() && $entity_type === $payment_entity && 'create' === $action ) {
			if ( $this->is_payment_complete( $wp_id ) ) {
				$this->auto_post_invoice( 'auto_post_invoices', $payment_entity, $wp_id );
			}
		}

		return $result;
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * Dispatches to handler methods: level via handler_load_level(),
	 * payment via load_payment_data() (with partner/level resolution),
	 * membership via load_membership_data() (with partner/level/price resolution).
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		$level_entity      = $this->get_level_entity_type();
		$payment_entity    = $this->get_payment_entity_type();
		$membership_entity = $this->get_membership_entity_type();

		return match ( $entity_type ) {
			$level_entity      => $this->handler_load_level( $wp_id ),
			$payment_entity    => $this->load_payment_data( $wp_id ),
			$membership_entity => $this->load_membership_data( $wp_id ),
			default            => [],
		};
	}

	// ─── Payment resolution ────────────────────────────────

	/**
	 * Load and resolve a payment entity with Odoo references.
	 *
	 * Resolves user -> partner and level -> Odoo product ID, then
	 * delegates to handler_load_payment() for formatting.
	 *
	 * @param int $wp_id Payment entity ID.
	 * @return array<string, mixed>
	 */
	private function load_payment_data( int $wp_id ): array {
		[ $user_id, $level_id ] = $this->get_payment_user_and_level( $wp_id );

		$partner_id = $this->resolve_partner_from_user( $user_id );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for payment entity.', [ 'wp_id' => $wp_id ] );
			return [];
		}

		$level_entity  = $this->get_level_entity_type();
		$level_odoo_id = 0;
		if ( $level_id > 0 ) {
			$level_odoo_id = $this->get_mapping( $level_entity, $level_id ) ?? 0;
		}

		if ( ! $level_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for payment level.', [ 'level_id' => $level_id ] );
			return [];
		}

		return $this->handler_load_payment( $wp_id, $partner_id, $level_odoo_id );
	}

	// ─── Membership resolution ─────────────────────────────

	/**
	 * Load and resolve a membership entity with Odoo references.
	 *
	 * Calls handler_load_membership() for raw data, then resolves
	 * user -> partner, level -> Odoo product ID, and member price.
	 *
	 * @param int $wp_id Membership entity ID.
	 * @return array<string, mixed>
	 */
	private function load_membership_data( int $wp_id ): array {
		$data = $this->handler_load_membership( $wp_id );

		if ( empty( $data ) ) {
			return [];
		}

		// Resolve WP user -> Odoo partner.
		$user_id = $data['user_id'] ?? 0;
		unset( $data['user_id'] );

		$data['partner_id'] = $this->resolve_partner_from_user( $user_id );

		if ( empty( $data['partner_id'] ) ) {
			$this->logger->warning( 'Cannot resolve partner for membership entity.', [ 'wp_id' => $wp_id ] );
			return [];
		}

		// Resolve level -> Odoo product.product ID.
		$level_entity = $this->get_level_entity_type();
		$level_key    = $level_entity . '_id';
		$level_id     = $data[ $level_key ] ?? 0;
		unset( $data[ $level_key ] );

		if ( $level_id > 0 ) {
			$data['membership_id'] = $this->get_mapping( $level_entity, $level_id );
		}

		if ( empty( $data['membership_id'] ) ) {
			$this->logger->warning( 'Cannot resolve Odoo product for membership level.', [ 'level_id' => $level_id ] );
			return [];
		}

		// Resolve member_price from level.
		$price = $this->resolve_member_price( $level_id );
		if ( $price > 0 ) {
			$data['member_price'] = $price;
		}

		return $data;
	}
}
