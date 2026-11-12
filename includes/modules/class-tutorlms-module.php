<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TutorLMS Module — bidirectional LMS data sync with Odoo.
 *
 * Syncs TutorLMS courses as Odoo service products (product.product),
 * orders as invoices (account.move), and enrollments as sale orders
 * (sale.order). Courses are bidirectional (push + pull).
 * Orders and enrollments are push-only.
 *
 * Requires the Tutor LMS plugin to be active.
 * No mutual exclusivity — TutorLMS is an LMS, not an e-commerce plugin.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class TutorLMS_Module extends LMS_Module_Base {

	use TutorLMS_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.6';
	protected const PLUGIN_TESTED_UP_TO = '3.0';

	/**
	 * Sync direction: bidirectional for courses, push-only for orders/enrollments.
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
		'order'      => 'account.move',
		'enrollment' => 'sale.order',
	];

	/**
	 * Default field mappings.
	 *
	 * Course mappings use wp_field → odoo_field renames.
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
	 * TutorLMS data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var TutorLMS_Handler
	 */
	private TutorLMS_Handler $handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'tutorlms', 'TutorLMS', $client_provider, $entity_map, $settings );
		$this->handler = new TutorLMS_Handler( $this->logger );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_lms_handler(): LMS_Handler_Base {
		return $this->handler;
	}

	/**
	 * Boot the module: register TutorLMS hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'TUTOR_VERSION' ) ) {
			$this->logger->warning( __( 'TutorLMS module enabled but Tutor LMS is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_courses'] ) ) {
			add_action( 'save_post_courses', $this->safe_callback( [ $this, 'on_course_save' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'tutor_order_placed', $this->safe_callback( [ $this, 'on_order_placed' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_enrollments'] ) ) {
			add_action( 'tutor_after_enrolled', $this->safe_callback( [ $this, 'on_enrollment' ] ), 10, 2 );
			add_action( 'tutor_enrollment/after/cancel', $this->safe_callback( [ $this, 'on_enrollment_cancel' ] ), 10, 2 );
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
			'sync_orders'        => true,
			'sync_enrollments'   => true,
			'auto_post_invoices' => true,
			'pull_courses'       => true,
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
				'description' => __( 'Push TutorLMS courses to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_orders'        => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push TutorLMS orders to Odoo as invoices.', 'wp4odoo' ),
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
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'TUTOR_VERSION' );
	}

	/**
	 * Get external dependency status for TutorLMS.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'Tutor LMS' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : '';
	}

	// ─── Translation ──────────────────────────────────────

	/**
	 * Translatable fields for courses (name + description).
	 *
	 * @param string $entity_type Entity type.
	 * @return array<string, string> Odoo field => WP field.
	 */
	protected function get_translatable_fields( string $entity_type ): array {
		if ( 'course' === $entity_type ) {
			return [
				'name'             => 'post_title',
				'description_sale' => 'post_content',
			];
		}

		return [];
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Courses dedup by product name. Orders dedup by invoice ref.
	 * Enrollments have no reliable natural key — skipped.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'course' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		if ( 'order' === $entity_type && ! empty( $odoo_values['ref'] ) ) {
			return [ [ 'ref', '=', $odoo_values['ref'] ] ];
		}

		return [];
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures courses are synced before orders and enrollments.
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
	 * Courses delegate to the parent pull_from_odoo() infrastructure.
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

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Courses use the handler's parse method.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'course' => $this->handler->parse_course_from_odoo( $odoo_data ),
			default  => parent::map_from_odoo( $entity_type, $odoo_data ),
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
			'course' => $this->handler->save_course( $data, $wp_id ),
			default  => 0,
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
		if ( 'course' === $entity_type ) {
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
			'order'      => $this->load_order_data( $wp_id ),
			'enrollment' => $this->load_enrollment_data( $wp_id ),
			default      => [],
		};
	}

	/**
	 * Load and resolve an order with Odoo references.
	 *
	 * Resolves user → partner and course → product Odoo ID.
	 *
	 * @param int $order_id TutorLMS order post ID.
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
			$this->logger->warning( 'Cannot resolve partner for order.', [ 'order_id' => $order_id ] );
			return [];
		}

		// Resolve course → Odoo product.
		$course_id       = $data['course_id'] ?? 0;
		$product_odoo_id = 0;
		if ( $course_id > 0 ) {
			$product_odoo_id = $this->get_mapping( 'course', $course_id ) ?? 0;
		}

		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for order course.', [ 'course_id' => $course_id ] );
			return [];
		}

		$settings         = $this->get_settings();
		$auto_post        = ! empty( $settings['auto_post_invoices'] );
		$data['order_id'] = $order_id;

		return $this->handler->format_invoice( $data, $product_odoo_id, $partner_id, $auto_post );
	}

	// ─── Product sync ──────────────────────────────────────

	/**
	 * Ensure the parent product (course) is synced before dependent entity.
	 *
	 * @param int    $wp_id       Entity ID.
	 * @param string $entity_type 'order' or 'enrollment'.
	 * @return void
	 */
	private function ensure_product_synced( int $wp_id, string $entity_type ): void {
		$course_id = 0;

		if ( 'order' === $entity_type ) {
			$meta      = get_post_meta( $wp_id );
			$course_id = (int) ( $meta['_tutor_order_course_id'][0] ?? 0 );
		} elseif ( 'enrollment' === $entity_type ) {
			[ , $course_id ] = self::decode_synthetic_id( $wp_id );
		}

		$this->ensure_entity_synced( 'course', $course_id );
	}
}
