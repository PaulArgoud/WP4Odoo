<?php
/**
 * PHPStan MailPoet stubs — namespaced class.
 *
 * Separated from phpstan-bootstrap.php because PHP doesn't allow
 * mixing brace-style namespace blocks with non-namespaced code.
 *
 * @package WP4Odoo
 */

namespace MailPoet\API {
	if ( ! class_exists( 'MailPoet\API\API' ) ) {
		class API {
			/** @return self */
			public static function MP(): self {
				return new self();
			}

			/** @return array<string, mixed> */
			public function getSubscriber( int $id ): array {
				return [];
			}

			/** @return array<string, mixed> */
			public function getList( int $id ): array {
				return [];
			}

			/** @return array<int, array<string, mixed>> */
			public function getSubscribers(): array {
				return [];
			}

			/** @return array<int, array<string, mixed>> */
			public function getLists(): array {
				return [];
			}

			/** @return array<string, mixed> */
			public function addSubscriber( array $data ): array {
				return [];
			}

			/** @return array<string, mixed> */
			public function updateSubscriber( int $id, array $data ): array {
				return [];
			}

			public function subscribeToList( int $id, array $list_ids ): bool {
				return true;
			}

			/** @return array<string, mixed> */
			public function addList( array $data ): array {
				return [];
			}

			/** @return array<string, mixed> */
			public function updateList( int $id, array $data ): array {
				return [];
			}
		}
	}
}
