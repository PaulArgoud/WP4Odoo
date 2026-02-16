<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailPoet data handler — loads, saves, and parses MailPoet entities.
 *
 * Accesses MailPoet data via the official MailPoet\API\API class:
 * - Subscribers via getSubscriber / addSubscriber / updateSubscriber
 * - Lists via getList / addList / updateList
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class MailPoet_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load methods ────────────────────────────────────────

	/**
	 * Load a MailPoet subscriber by ID.
	 *
	 * @param int $id Subscriber ID.
	 * @return array Subscriber data or empty array if not found.
	 */
	public function load_subscriber( int $id ): array {
		try {
			$subscriber = \MailPoet\API\API::MP()->getSubscriber( $id );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'MailPoet subscriber not found.',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}

		if ( empty( $subscriber ) ) {
			$this->logger->warning( 'MailPoet subscriber not found.', [ 'id' => $id ] );
			return [];
		}

		// Normalize list IDs from subscriptions array.
		$list_ids = [];
		if ( ! empty( $subscriber['subscriptions'] ) && is_array( $subscriber['subscriptions'] ) ) {
			foreach ( $subscriber['subscriptions'] as $sub ) {
				if ( ! empty( $sub['segment_id'] ) ) {
					$list_ids[] = (int) $sub['segment_id'];
				}
			}
		}

		return [
			'id'         => (int) ( $subscriber['id'] ?? 0 ),
			'email'      => $subscriber['email'] ?? '',
			'first_name' => $subscriber['first_name'] ?? '',
			'last_name'  => $subscriber['last_name'] ?? '',
			'status'     => $subscriber['status'] ?? 'subscribed',
			'list_ids'   => $list_ids,
		];
	}

	/**
	 * Load a MailPoet list by ID.
	 *
	 * @param int $id List ID.
	 * @return array List data or empty array if not found.
	 */
	public function load_list( int $id ): array {
		try {
			$list = \MailPoet\API\API::MP()->getList( $id );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'MailPoet list not found.',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}

		if ( empty( $list ) ) {
			$this->logger->warning( 'MailPoet list not found.', [ 'id' => $id ] );
			return [];
		}

		return [
			'id'          => (int) ( $list['id'] ?? 0 ),
			'title'       => $list['name'] ?? '',
			'description' => $list['description'] ?? '',
		];
	}

	// ─── Parse from Odoo ─────────────────────────────────────

	/**
	 * Parse subscriber data from Odoo mailing.contact record.
	 *
	 * Splits the Odoo name field into first_name and last_name.
	 *
	 * @param array $odoo_data Odoo record data.
	 * @return array WordPress-compatible subscriber data.
	 */
	public function parse_subscriber_from_odoo( array $odoo_data ): array {
		$name_parts = explode( ' ', $odoo_data['name'] ?? '', 2 );

		return [
			'email'      => $odoo_data['email'] ?? '',
			'first_name' => $name_parts[0],
			'last_name'  => $name_parts[1] ?? '',
			'status'     => $odoo_data['x_status'] ?? 'subscribed',
		];
	}

	/**
	 * Parse list data from Odoo mailing.list record.
	 *
	 * @param array $odoo_data Odoo record data.
	 * @return array WordPress-compatible list data.
	 */
	public function parse_list_from_odoo( array $odoo_data ): array {
		return [
			'title'       => $odoo_data['name'] ?? '',
			'description' => $odoo_data['x_description'] ?? '',
		];
	}

	// ─── Save methods (pull) ─────────────────────────────────

	/**
	 * Save a subscriber via the MailPoet API.
	 *
	 * Creates or updates depending on whether the subscriber exists.
	 *
	 * @param array $data  Subscriber data.
	 * @param int   $wp_id Existing subscriber ID (0 if new).
	 * @return int The subscriber ID (0 on failure).
	 */
	public function save_subscriber( array $data, int $wp_id = 0 ): int {
		try {
			$api = \MailPoet\API\API::MP();

			$values = [
				'email'      => $data['email'] ?? '',
				'first_name' => $data['first_name'] ?? '',
				'last_name'  => $data['last_name'] ?? '',
				'status'     => $data['status'] ?? 'subscribed',
			];

			if ( $wp_id > 0 ) {
				$result = $api->updateSubscriber( $wp_id, $values );
				return (int) ( $result['id'] ?? $wp_id );
			}

			$result = $api->addSubscriber( $values );
			return (int) ( $result['id'] ?? 0 );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Failed to save MailPoet subscriber.', [ 'error' => $e->getMessage() ] );
			return 0;
		}
	}

	/**
	 * Save a list via the MailPoet API.
	 *
	 * Creates or updates depending on whether the list exists.
	 *
	 * @param array $data  List data.
	 * @param int   $wp_id Existing list ID (0 if new).
	 * @return int The list ID (0 on failure).
	 */
	public function save_list( array $data, int $wp_id = 0 ): int {
		try {
			$api = \MailPoet\API\API::MP();

			$values = [
				'name'        => $data['title'] ?? '',
				'description' => $data['description'] ?? '',
			];

			if ( $wp_id > 0 ) {
				$result = $api->updateList( $wp_id, $values );
				return (int) ( $result['id'] ?? $wp_id );
			}

			$result = $api->addList( $values );
			return (int) ( $result['id'] ?? 0 );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Failed to save MailPoet list.', [ 'error' => $e->getMessage() ] );
			return 0;
		}
	}
}
