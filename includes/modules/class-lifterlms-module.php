<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LifterLMS Module — push LMS data to Odoo.
 *
 * Syncs LifterLMS courses and memberships as Odoo service products
 * (product.product), orders as invoices (account.move), and enrollments
 * as sale orders (sale.order). Push-only (WP → Odoo).
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

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
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
			add_action( 'save_post_llms_course', [ $this, 'on_course_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_memberships'] ) ) {
			add_action( 'save_post_llms_membership', [ $this, 'on_membership_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'lifterlms_order_status_completed', [ $this, 'on_order_completed' ], 10, 1 );
			add_action( 'lifterlms_order_status_active', [ $this, 'on_order_completed' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_enrollments'] ) ) {
			add_action( 'llms_user_enrolled_in_course', [ $this, 'on_enrollment' ], 10, 2 );
			add_action( 'llms_user_removed_from_course', [ $this, 'on_unenrollment' ], 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_courses'      => true,
			'sync_memberships'  => true,
			'sync_orders'       => true,
			'sync_enrollments'  => true,
			'auto_post_invoice' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_courses'      => [
				'label'       => __( 'Sync courses', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LifterLMS courses to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_memberships'  => [
				'label'       => __( 'Sync memberships', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LifterLMS memberships to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_orders'       => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed orders to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_enrollments'  => [
				'label'       => __( 'Sync enrollments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push course enrollments to Odoo as sale orders.', 'wp4odoo' ),
			],
			'auto_post_invoice' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed orders.', 'wp4odoo' ),
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
			$this->auto_post_invoice( 'auto_post_invoice', 'order', $wp_id );
		}

		return $result;
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
		$user_id = $data['user_id'] ?? 0;
		if ( $user_id <= 0 ) {
			$this->logger->warning( 'LifterLMS order has no user.', [ 'order_id' => $order_id ] );
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find user for LifterLMS order.',
				[
					'order_id' => $order_id,
					'user_id'  => $user_id,
				]
			);
			return [];
		}

		$partner_id = $this->partner_service()->get_or_create(
			$user->user_email,
			[ 'name' => $user->display_name ],
			$user_id
		);

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
		$auto_post        = ! empty( $settings['auto_post_invoice'] );
		$data['order_id'] = $order_id;

		return $this->handler->format_invoice( $data, $product_odoo_id, $partner_id, $auto_post );
	}

	/**
	 * Load and resolve an enrollment with Odoo references.
	 *
	 * Decodes the synthetic ID and resolves user → partner, course → product.
	 *
	 * @param int $synthetic_id Synthetic enrollment ID (user_id * 1M + course_id).
	 * @return array<string, mixed>
	 */
	private function load_enrollment_data( int $synthetic_id ): array {
		$user_id   = intdiv( $synthetic_id, 1_000_000 );
		$course_id = $synthetic_id % 1_000_000;

		$data = $this->handler->load_enrollment( $user_id, $course_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve user → partner.
		$partner_id = $this->partner_service()->get_or_create(
			$data['user_email'],
			[ 'name' => $data['user_name'] ],
			$user_id
		);

		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve partner for enrollment.',
				[
					'user_id'   => $user_id,
					'course_id' => $course_id,
				]
			);
			return [];
		}

		// Resolve course → Odoo product.
		$product_odoo_id = $this->get_mapping( 'course', $course_id ) ?? 0;
		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for enrollment course.', [ 'course_id' => $course_id ] );
			return [];
		}

		$course_post = get_post( $course_id );
		$course_name = $course_post ? $course_post->post_title : '';

		return $this->handler->format_sale_order( $product_odoo_id, $partner_id, $data['date'], $course_name );
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
			// Synthetic ID: user_id * 1M + course_id.
			$product_id = $wp_id % 1_000_000;
		}

		$this->ensure_entity_synced( $product_type, $product_id );
	}
}
