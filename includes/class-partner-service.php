<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared service for Odoo res.partner lookup and creation.
 *
 * Provides a centralized way for any module (CRM, WooCommerce, Portal)
 * to find or create an Odoo partner without duplicating logic.
 * Uses Entity_Map_Repository for mapping persistence.
 *
 * @package WP4Odoo
 * @since   1.3.0
 */
class Partner_Service {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure that returns the Odoo client.
	 *
	 * @var \Closure
	 */
	private \Closure $client_getter;

	/**
	 * Entity map repository.
	 *
	 * @var Entity_Map_Repository
	 */
	private Entity_Map_Repository $entity_map;

	/**
	 * Constructor.
	 *
	 * @param \Closure              $client_getter Returns the Odoo_Client instance.
	 * @param Entity_Map_Repository $entity_map    Entity map repository.
	 */
	public function __construct( \Closure $client_getter, Entity_Map_Repository $entity_map ) {
		$this->logger        = new Logger( 'partner' );
		$this->client_getter = $client_getter;
		$this->entity_map    = $entity_map;
	}

	/**
	 * Get the Odoo partner ID linked to a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int|null Odoo partner ID, or null if not linked.
	 */
	public function get_partner_id_for_user( int $user_id ): ?int {
		return $this->entity_map->get_odoo_id( 'crm', 'contact', $user_id );
	}

	/**
	 * Get the WordPress user ID linked to an Odoo partner.
	 *
	 * @param int $odoo_id Odoo partner ID.
	 * @return int|null WordPress user ID, or null if not linked.
	 */
	public function get_user_for_partner( int $odoo_id ): ?int {
		return $this->entity_map->get_wp_id( 'crm', 'contact', $odoo_id );
	}

	/**
	 * Find or create an Odoo partner by email.
	 *
	 * Lookup order:
	 * 1. Check entity_map for an existing mapping (by wp_id if provided).
	 * 2. Search Odoo by email.
	 * 3. Create a new partner in Odoo.
	 *
	 * @param string $email  Partner email address.
	 * @param array  $data   Additional partner fields (name, phone, etc.).
	 * @param int    $wp_id  WordPress user ID to link (0 if none).
	 * @return int|null Odoo partner ID, or null on failure.
	 */
	public function get_or_create( string $email, array $data = [], int $wp_id = 0 ): ?int {
		// 1. Check existing mapping by WP user ID.
		if ( $wp_id > 0 ) {
			$existing = $this->get_partner_id_for_user( $wp_id );
			if ( $existing ) {
				return $existing;
			}
		}

		$client = ( $this->client_getter )();

		if ( ! $client->is_connected() ) {
			$this->logger->error(
				'Cannot resolve partner: Odoo client not connected.',
				[
					'email' => $email,
				]
			);
			return null;
		}

		// 2. Search Odoo by email.
		$ids = $client->search( 'res.partner', [ [ 'email', '=', $email ] ], 0, 1 );

		if ( ! empty( $ids ) ) {
			$odoo_id = (int) $ids[0];
			$this->logger->info(
				'Found existing Odoo partner by email.',
				[
					'email'   => $email,
					'odoo_id' => $odoo_id,
				]
			);

			// Save mapping if we have a WP user.
			if ( $wp_id > 0 ) {
				$this->entity_map->save( 'crm', 'contact', $wp_id, $odoo_id, 'res.partner' );
			}

			return $odoo_id;
		}

		// 3. Create a new partner.
		$partner_data          = $data;
		$partner_data['email'] = $email;

		if ( empty( $partner_data['name'] ) ) {
			$partner_data['name'] = $email;
		}

		try {
			$odoo_id = $client->create( 'res.partner', $partner_data );

			$this->logger->info(
				'Created new Odoo partner.',
				[
					'email'   => $email,
					'odoo_id' => $odoo_id,
				]
			);

			// Save mapping if we have a WP user.
			if ( $wp_id > 0 ) {
				$this->entity_map->save( 'crm', 'contact', $wp_id, $odoo_id, 'res.partner' );
			}

			return $odoo_id;
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to create Odoo partner.',
				[
					'email' => $email,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}
}
