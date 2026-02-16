<?php
/**
 * MailPoet class and function stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global constants ───────────────────────────────────

namespace {

	if ( ! defined( 'MAILPOET_VERSION' ) ) {
		define( 'MAILPOET_VERSION', '5.0.0' );
	}

	$GLOBALS['_mailpoet_subscribers'] = [];
	$GLOBALS['_mailpoet_lists']       = [];
}

// ─── MailPoet API stub ──────────────────────────────────

namespace MailPoet\API {

	if ( ! class_exists( 'MailPoet\API\API' ) ) {
		/**
		 * MailPoet API stub.
		 */
		class API {

			/** @var self|null */
			private static ?self $instance = null;

			/**
			 * Get the MailPoet API instance.
			 *
			 * @return self
			 */
			public static function MP(): self {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}

			/**
			 * Get a subscriber by ID.
			 *
			 * @param int $id Subscriber ID.
			 * @return array
			 */
			public function getSubscriber( int $id ): array {
				return $GLOBALS['_mailpoet_subscribers'][ $id ] ?? [];
			}

			/**
			 * Get a list by ID.
			 *
			 * @param int $id List ID.
			 * @return array
			 */
			public function getList( int $id ): array {
				return $GLOBALS['_mailpoet_lists'][ $id ] ?? [];
			}

			/**
			 * Get all subscribers.
			 *
			 * @return array
			 */
			public function getSubscribers(): array {
				return array_values( $GLOBALS['_mailpoet_subscribers'] );
			}

			/**
			 * Get all lists.
			 *
			 * @return array
			 */
			public function getLists(): array {
				return array_values( $GLOBALS['_mailpoet_lists'] );
			}

			/**
			 * Add a subscriber.
			 *
			 * @param array $data Subscriber data.
			 * @return array The created subscriber.
			 */
			public function addSubscriber( array $data ): array {
				$id                                       = count( $GLOBALS['_mailpoet_subscribers'] ) + 1;
				$data['id']                               = $id;
				$GLOBALS['_mailpoet_subscribers'][ $id ] = $data;
				return $data;
			}

			/**
			 * Update a subscriber.
			 *
			 * @param int   $id   Subscriber ID.
			 * @param array $data Subscriber data.
			 * @return array The updated subscriber.
			 */
			public function updateSubscriber( int $id, array $data ): array {
				if ( isset( $GLOBALS['_mailpoet_subscribers'][ $id ] ) ) {
					$GLOBALS['_mailpoet_subscribers'][ $id ] = array_merge(
						$GLOBALS['_mailpoet_subscribers'][ $id ],
						$data
					);
				}
				return $GLOBALS['_mailpoet_subscribers'][ $id ] ?? [];
			}

			/**
			 * Subscribe a subscriber to lists.
			 *
			 * @param int   $id       Subscriber ID.
			 * @param array $list_ids List IDs.
			 * @return bool
			 */
			public function subscribeToList( int $id, array $list_ids ): bool {
				return true;
			}

			/**
			 * Add a list.
			 *
			 * @param array $data List data.
			 * @return array The created list.
			 */
			public function addList( array $data ): array {
				$id                                  = count( $GLOBALS['_mailpoet_lists'] ) + 1;
				$data['id']                          = $id;
				$GLOBALS['_mailpoet_lists'][ $id ] = $data;
				return $data;
			}

			/**
			 * Update a list.
			 *
			 * @param int   $id   List ID.
			 * @param array $data List data.
			 * @return array The updated list.
			 */
			public function updateList( int $id, array $data ): array {
				if ( isset( $GLOBALS['_mailpoet_lists'][ $id ] ) ) {
					$GLOBALS['_mailpoet_lists'][ $id ] = array_merge(
						$GLOBALS['_mailpoet_lists'][ $id ],
						$data
					);
				}
				return $GLOBALS['_mailpoet_lists'][ $id ] ?? [];
			}
		}
	}
}
