<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LifterLMS Module — bidirectional LMS data sync with Odoo.
 *
 * Syncs LifterLMS courses and memberships as Odoo service products
 * (product.product), orders as invoices (account.move), and enrollments
 * as sale orders (sale.order).
 *
 * Courses and memberships are bidirectional (push + pull).
 * Orders and enrollments are push-only.
 *
 * LifterLMS uses WordPress CPTs:
 * - `llms_course` — courses
 * - `llms_membership` — memberships
 * - `llms_order` — payment orders
 *
 * Requires the LifterLMS plugin to be active.
 * No mutual exclusivity — LifterLMS is an LMS, not an e-commerce plugin.
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class LifterLMS_Module extends Module_Base {

	use LifterLMS_Hooks;
	use LMS_Helpers;

	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '9.2';

	/**
	 * Sync direction: bidirectional for courses/memberships, push-only for orders/enrollments.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'course'     => 'product.product',
		'membership' => 'product.product',
		'order'      => 'account.move',
		'enrollment' => 'sale.order',
	];

	/**
	 * Default field mappings.
	 *
	 * Course and membership mappings use wp_field → odoo_field renames.
	 * Order and enrollment mappings are identity (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'course'     => [
			'title'       => 'name',
			'description' => 'description_sale',
			'list_price'  => 'list_price',
			'type'        => 'type',
		],
		'membership' => [
			'title'       => 'name',
			'description' => 'description_sale',
			'list_price'  => 'list_price',
			'type'        => 'type',
		],
		'order'      => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'enrollment' => [
			'partner_id' => 'partner_id',
			'date_order' => 'date_order',
			'state'      => 'state',
			'order_line' => 'order_line',
		],
	];

	/**
	 * LifterLMS data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var LifterLMS_Handler
	 */
	private LifterLMS_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'lifterlms', 'LifterLMS', $client_provider, $entity_map, $settings );
		$this->handler = new LifterLMS_Handler( $this->logger );
	}

	/**
	 * Boot the module: register LifterLMS hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'LLMS_VERSION' ) ) {
			$this->logger->warning( __( 'LifterLMS module enabled but LifterLMS is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_courses'] ) ) {
			add_action( 'save_post_llms_course', $this->safe_callback( [ $this, 'on_course_save' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'save_post_llms_membership', $this->safe_callback( [ $this, 'on_membership_save' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'lifterlms_order_status_completed', $this->safe_callback( [ $this, 'on_order_completed' ] ), 10, 1 );
			add_action( 'lifterlms_order_status_active', $this->safe_callback( [ $this, 'on_order_completed' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_enrollments'] ) ) {
			add_action( 'llms_user_enrolled_in_course', $this->safe_callback( [ $this, 'on_enrollment' ] ), 10, 2 );
			add_action( 'llms_user_removed_from_course', $this->safe_callback( [ $this, 'on_unenrollment' ] ), 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_courses'       => true,
			'sync_memberships'   => true,
			'sync_orders'        => true,
			'sync_enrollments'   => true,
			'auto_post_invoices' => true,
			'pull_courses'       => true,
			'pull_memberships'   => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_courses'       => [
				'label'       => __( 'Sync courses', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LifterLMS courses to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_memberships'   => [
				'label'       => __( 'Sync memberships', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LifterLMS memberships to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_orders'        => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed orders to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_enrollments'   => [
				'label'       => __( 'Sync enrollments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push course enrollments to Odoo as sale orders.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed orders.', 'wp4odoo' ),
			],
			'pull_courses'       => [
				'label'       => __( 'Pull courses from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull course changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
			'pull_memberships'   => [
				'label'       => __( 'Pull memberships from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull membership changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for LifterLMS.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'LLMS_VERSION' ), 'LifterLMS' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'LLMS_VERSION' ) ? LLMS_VERSION : '';
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures courses/memberships are synced before orders and enrollments.
	 * Auto-posts invoices for orders when configured.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( in_array( $entity_type, [ 'order', 'enrollment' ], true ) && 'delete' !== $action ) {
			$this->ensure_product_synced( $wp_id, $entity_type );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result->succeeded() && 'order' === $entity_type && 'create' === $action ) {
			$this->auto_post_invoice( 'auto_post_invoices', 'order', $wp_id );
		}

		return $result;
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Orders and enrollments are push-only and cannot be pulled.
	 * Courses and memberships delegate to the parent pull_from_odoo() infrastructure.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( in_array( $entity_type, [ 'order', 'enrollment' ], true ) ) {
			$this->logger->info( "{$entity_type} pull not supported — {$entity_type}s originate in WordPress.", [ 'odoo_id' => $odoo_id ] );
			return \WP4Odoo\Sync_Result::success();
		}

		$settings = $this->get_settings();

		if ( 'course' === $entity_type && empty( $settings['pull_courses'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'membership' === $entity_type && empty( $settings['pull_memberships'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Courses and memberships use the handler's parse methods.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'course'     => $this->handler->parse_course_from_odoo( $odoo_data ),
			'membership' => $this->handler->parse_membership_from_odoo( $odoo_data ),
			default      => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'course'     => $this->handler->save_course( $data, $wp_id ),
			'membership' => $this->handler->save_membership( $data, $wp_id ),
			default      => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress post ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'course' === $entity_type || 'membership' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		return false;
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
			'course'     => $this->handler->load_course( $wp_id ),
			'membership' => $this->handler->load_membership( $wp_id ),
			'order'      => $this->load_order_data( $wp_id ),
			'enrollment' => $this->load_enrollment_data( $wp_id ),
			default      => [],
		};
	}

	/**
	 * Load and resolve an order with Odoo references.
	 *
	 * Resolves user → partner and product → Odoo product ID.
	 *
	 * @param int $order_id LifterLMS order post ID.
	 * @return array<string, mixed>
	 */
	private function load_order_data( int $order_id ): array {
		$data = $this->handler->load_order( $order_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve user → partner.
		$user_id    = $data['user_id'] ?? 0;
		$partner_id = $this->resolve_partner_from_user( $user_id );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for LifterLMS order.', [ 'order_id' => $order_id ] );
			return [];
		}

		// Resolve product → Odoo product ID (try course first, then membership).
		$product_id      = $data['product_id'] ?? 0;
		$product_odoo_id = 0;
		if ( $product_id > 0 ) {
			$product_odoo_id = $this->get_mapping( 'course', $product_id ) ?? 0;
			if ( ! $product_odoo_id ) {
				$product_odoo_id = $this->get_mapping( 'membership', $product_id ) ?? 0;
			}
		}

		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for LifterLMS order.', [ 'product_id' => $product_id ] );
			return [];
		}

		$settings         = $this->get_settings();
		$auto_post        = ! empty( $settings['auto_post_invoices'] );
		$data['order_id'] = $order_id;

		return $this->handler->format_invoice( $data, $product_odoo_id, $partner_id, $auto_post );
	}

	/**
	 * Load and resolve an enrollment with Odoo references.
	 *
	 * Delegates to LMS_Helpers::load_enrollment_from_synthetic().
	 *
	 * @param int $synthetic_id Synthetic enrollment ID (user_id * 1M + course_id).
	 * @return array<string, mixed>
	 */
	private function load_enrollment_data( int $synthetic_id ): array {
		return $this->load_enrollment_from_synthetic(
			$synthetic_id,
			[ $this->handler, 'load_enrollment' ],
			[ $this->handler, 'format_sale_order' ]
		);
	}

	// ─── Product sync ──────────────────────────────────────

	/**
	 * Ensure the parent product (course or membership) is synced before dependent entity.
	 *
	 * @param int    $wp_id       Entity ID.
	 * @param string $entity_type 'order' or 'enrollment'.
	 * @return void
	 */
	private function ensure_product_synced( int $wp_id, string $entity_type ): void {
		$product_id   = 0;
		$product_type = 'course';

		if ( 'order' === $entity_type ) {
			$product_id = $this->handler->get_product_id_for_order( $wp_id );
			// Determine if the product is a course or membership.
			$post = get_post( $product_id );
			if ( $post && 'llms_membership' === $post->post_type ) {
				$product_type = 'membership';
			}
		} elseif ( 'enrollment' === $entity_type ) {
			[ , $product_id ] = self::decode_synthetic_id( $wp_id );
		}

		$this->ensure_entity_synced( $product_type, $product_id );
	}
}
