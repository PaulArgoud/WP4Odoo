<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partner resolution helpers for Module_Base.
 *
 * Provides lazy Partner_Service access and convenience methods
 * for resolving WordPress users and emails to Odoo res.partner IDs.
 *
 * Mixed into Module_Base via Module_Helpers and accesses its
 * protected properties/methods through $this.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
trait Partner_Helpers {

	/**
	 * Shared Partner_Service instance across all modules.
	 *
	 * All modules use the same Odoo client and Entity_Map_Repository,
	 * so sharing the Partner_Service avoids duplicate Odoo lookups
	 * when multiple modules resolve the same email in a single batch.
	 *
	 * @var Partner_Service|null
	 */
	private static ?Partner_Service $shared_partner_service = null;

	/**
	 * Get or create the shared Partner_Service instance (lazy).
	 *
	 * Used by any module that needs to resolve WordPress users or
	 * guest emails to Odoo res.partner records.
	 *
	 * @return Partner_Service
	 */
	protected function partner_service(): Partner_Service {
		if ( null === self::$shared_partner_service ) {
			self::$shared_partner_service = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		}

		return self::$shared_partner_service;
	}

	/**
	 * Reset the shared Partner_Service singleton.
	 *
	 * Called between tests to prevent cross-test state leakage.
	 *
	 * @since 3.9.0
	 *
	 * @return void
	 */
	public static function reset_partner_service(): void {
		self::$shared_partner_service = null;
	}

	/**
	 * Resolve a WordPress user ID to an Odoo partner ID.
	 *
	 * Loads the user, extracts email + display name, then delegates to
	 * Partner_Service::get_or_create(). Returns null if the user does
	 * not exist or partner resolution fails.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int|null Odoo partner ID, or null on failure.
	 */
	protected function resolve_partner_from_user( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find WordPress user for partner resolution.',
				[ 'user_id' => $user_id ]
			);
			return null;
		}

		return $this->partner_service()->get_or_create(
			$user->user_email,
			[ 'name' => $user->display_name ],
			$user_id
		);
	}

	/**
	 * Resolve an email address to an Odoo partner ID.
	 *
	 * Delegates directly to Partner_Service::get_or_create().
	 * Suitable for guest users (no WP account) or when email
	 * is already available without user lookup.
	 *
	 * @param string $email Partner email address.
	 * @param string $name  Partner display name (falls back to email in Partner_Service).
	 * @param int    $wp_id Optional WordPress user ID to link (0 if guest).
	 * @return int|null Odoo partner ID, or null on failure.
	 */
	protected function resolve_partner_from_email( string $email, string $name = '', int $wp_id = 0 ): ?int {
		if ( empty( $email ) ) {
			return null;
		}

		$data = [];
		if ( '' !== $name ) {
			$data['name'] = $name;
		}

		return $this->partner_service()->get_or_create( $email, $data, $wp_id );
	}
}
