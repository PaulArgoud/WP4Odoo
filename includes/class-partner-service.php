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
	 * @param \Closure                 $client_getter Returns the Odoo_Client instance.
	 * @param Entity_Map_Repository    $entity_map    Entity map repository.
	 * @param Settings_Repository|null $settings      Settings repository (null uses default).
	 */
	public function __construct( \Closure $client_getter, Entity_Map_Repository $entity_map, ?Settings_Repository $settings = null ) {
		$this->logger        = Logger::for_channel( 'partner', $settings );
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
	 * Resolve Odoo partner IDs for a batch of emails.
	 *
	 * Uses a single Odoo search for all unknown emails, reducing RPC calls
	 * from N to 1 when processing batches of orders/donations.
	 *
	 * @param array<string, array{data?: array, wp_id?: int}> $entries Keyed by email.
	 * @return array<string, int|null> Odoo partner ID per email, or null on failure.
	 */
	public function get_or_create_batch( array $entries ): array {
		$normalized = [];
		foreach ( $entries as $email => $entry ) {
			$normalized[ strtolower( $email ) ] = $entry;
		}
		$entries = $normalized;

		$results  = [];
		$to_fetch = [];

		// 1. Batch-check entity_map for known WP user mappings (single query).
		$wp_ids_by_email = [];
		foreach ( $entries as $email => $entry ) {
			$wp_id = $entry['wp_id'] ?? 0;
			if ( $wp_id > 0 ) {
				$wp_ids_by_email[ $email ] = $wp_id;
			}
		}

		$known_partners = [];
		if ( ! empty( $wp_ids_by_email ) ) {
			$known_partners = $this->entity_map->get_odoo_ids_batch( 'crm', 'contact', array_values( $wp_ids_by_email ) );
		}

		foreach ( $entries as $email => $entry ) {
			$wp_id = $wp_ids_by_email[ $email ] ?? 0;
			if ( $wp_id > 0 && isset( $known_partners[ $wp_id ] ) ) {
				$results[ $email ] = $known_partners[ $wp_id ];
				continue;
			}
			$to_fetch[ $email ] = $entry;
		}

		if ( empty( $to_fetch ) ) {
			return $results;
		}

		$client = ( $this->client_getter )();

		if ( ! $client->is_connected() ) {
			$this->logger->error( 'Cannot resolve partners: Odoo client not connected.' );
			foreach ( $to_fetch as $email => $entry ) {
				$results[ $email ] = null;
			}
			return $results;
		}

		// 2. Single Odoo search for all unknown emails.
		$emails_list = array_keys( $to_fetch );
		$domain      = [ [ 'email', 'in', $emails_list ] ];

		try {
			$records = $client->search_read( 'res.partner', $domain, [ 'id', 'email' ], 0, count( $emails_list ) );
		} catch ( \Exception $e ) {
			$this->logger->error( 'Batch partner search failed.', [ 'error' => $e->getMessage() ] );
			foreach ( $to_fetch as $email => $entry ) {
				$results[ $email ] = null;
			}
			return $results;
		}

		// Index found partners by email.
		$found = [];
		foreach ( $records as $record ) {
			$record_email = is_string( $record['email'] ?? null ) ? strtolower( $record['email'] ) : '';
			if ( '' !== $record_email ) {
				$found[ $record_email ] = (int) $record['id'];
			}
		}

		// 3. Match found or create missing.
		foreach ( $to_fetch as $email => $entry ) {
			$email_lower = strtolower( $email );
			$wp_id       = $entry['wp_id'] ?? 0;

			if ( isset( $found[ $email_lower ] ) ) {
				$odoo_id           = $found[ $email_lower ];
				$results[ $email ] = $odoo_id;
				if ( $wp_id > 0 ) {
					$this->entity_map->save( 'crm', 'contact', $wp_id, $odoo_id, 'res.partner' );
				}
			} else {
				// Fall back to individual create.
				$results[ $email ] = $this->get_or_create( $email, $entry['data'] ?? [], $wp_id );
			}
		}

		return $results;
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

		// Advisory lock prevents duplicate partner creation when concurrent
		// queue workers resolve the same email simultaneously (TOCTOU race
		// between search and create). Same proven pattern as Circuit_Breaker.
		$lock = new Advisory_Lock( 'wp4odoo_partner_' . md5( $email ) );

		if ( ! $lock->acquire() ) {
			$this->logger->warning(
				'Could not acquire partner lock — returning null to let Sync_Engine retry.',
				[ 'email' => $email ]
			);
			return null;
		}

		try {
			return $this->search_or_create_partner( $client, $email, $data, $wp_id );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Search for or create a partner in Odoo (called under advisory lock).
	 *
	 * @param \WP4Odoo\API\Odoo_Client $client Odoo client.
	 * @param string                    $email  Partner email.
	 * @param array                     $data   Partner data.
	 * @param int                       $wp_id  WordPress user ID (0 if guest).
	 * @return int|null Odoo partner ID, or null on failure.
	 */
	private function search_or_create_partner( $client, string $email, array $data, int $wp_id ): ?int {
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
