<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnDash Module — bidirectional LMS data sync with Odoo.
 *
 * Syncs LearnDash courses and groups as Odoo service products (product.product),
 * transactions as invoices (account.move), and enrollments as sale orders
 * (sale.order). Courses and groups are bidirectional (push + pull).
 * Transactions and enrollments are push-only.
 *
 * Requires the LearnDash LMS plugin to be active.
 * No mutual exclusivity — LearnDash is an LMS, not an e-commerce plugin.
 *
 * @package WP4Odoo
 * @since   2.6.0
 */
class LearnDash_Module extends Module_Base {

	use LearnDash_Hooks;

	/**
	 * Sync direction: bidirectional for courses/groups, push-only for transactions/enrollments.
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
		'course'      => 'product.product',
		'group'       => 'product.product',
		'transaction' => 'account.move',
		'enrollment'  => 'sale.order',
	];

	/**
	 * Default field mappings.
	 *
	 * Course and group mappings use wp_field → odoo_field renames.
	 * Transaction and enrollment mappings are identity (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'course'      => [
			'title'       => 'name',
			'description' => 'description_sale',
			'list_price'  => 'list_price',
			'type'        => 'type',
		],
		'group'       => [
			'title'       => 'name',
			'description' => 'description_sale',
			'list_price'  => 'list_price',
			'type'        => 'type',
		],
		'transaction' => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'enrollment'  => [
			'partner_id' => 'partner_id',
			'date_order' => 'date_order',
			'state'      => 'state',
			'order_line' => 'order_line',
		],
	];

	/**
	 * LearnDash data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var LearnDash_Handler
	 */
	private LearnDash_Handler $handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'learndash', 'LearnDash', $client_provider, $entity_map, $settings );
		$this->handler = new LearnDash_Handler( $this->logger );
	}

	/**
	 * Boot the module: register LearnDash hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'LEARNDASH_VERSION' ) ) {
			$this->logger->warning( __( 'LearnDash module enabled but LearnDash is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_courses'] ) ) {
			add_action( 'save_post_sfwd-courses', [ $this, 'on_course_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_groups'] ) ) {
			add_action( 'save_post_groups', [ $this, 'on_group_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_transactions'] ) ) {
			add_action( 'learndash_transaction_created', [ $this, 'on_transaction_created' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_enrollments'] ) ) {
			add_action( 'learndash_update_course_access', [ $this, 'on_enrollment_change' ], 10, 4 );
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
			'sync_groups'        => true,
			'sync_transactions'  => true,
			'sync_enrollments'   => true,
			'auto_post_invoices' => true,
			'pull_courses'       => true,
			'pull_groups'        => true,
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
				'description' => __( 'Push LearnDash courses to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_groups'        => [
				'label'       => __( 'Sync groups', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LearnDash groups to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_transactions'  => [
				'label'       => __( 'Sync transactions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LearnDash payment transactions to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_enrollments'   => [
				'label'       => __( 'Sync enrollments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push course enrollments to Odoo as sale orders.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed transactions.', 'wp4odoo' ),
			],
			'pull_courses'       => [
				'label'       => __( 'Pull courses from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull course changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
			'pull_groups'        => [
				'label'       => __( 'Pull groups from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull group changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for LearnDash.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'LEARNDASH_VERSION' ), 'LearnDash LMS' );
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures courses are synced before transactions and enrollments.
	 * Auto-posts invoices for transactions when configured.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( in_array( $entity_type, [ 'transaction', 'enrollment' ], true ) && 'delete' !== $action ) {
			$this->ensure_product_synced( $wp_id, $entity_type );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result->succeeded() && 'transaction' === $entity_type && 'create' === $action ) {
			$this->auto_post_invoice( 'auto_post_invoices', 'transaction', $wp_id );
		}

		return $result;
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Transactions and enrollments are push-only and cannot be pulled.
	 * Courses and groups delegate to the parent pull_from_odoo() infrastructure.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( in_array( $entity_type, [ 'transaction', 'enrollment' ], true ) ) {
			$this->logger->info( "{$entity_type} pull not supported — {$entity_type}s originate in WordPress.", [ 'odoo_id' => $odoo_id ] );
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		$settings = $this->get_settings();

		if ( 'course' === $entity_type && empty( $settings['pull_courses'] ) ) {
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		if ( 'group' === $entity_type && empty( $settings['pull_groups'] ) ) {
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Courses and groups use the handler's parse methods.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'course' => $this->handler->parse_course_from_odoo( $odoo_data ),
			'group'  => $this->handler->parse_group_from_odoo( $odoo_data ),
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
			'group'  => $this->handler->save_group( $data, $wp_id ),
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
		if ( 'course' === $entity_type || 'group' === $entity_type ) {
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
			'course'      => $this->handler->load_course( $wp_id ),
			'group'       => $this->handler->load_group( $wp_id ),
			'transaction' => $this->load_transaction_data( $wp_id ),
			'enrollment'  => $this->load_enrollment_data( $wp_id ),
			default       => [],
		};
	}

	/**
	 * Load and resolve a transaction with Odoo references.
	 *
	 * Resolves user → partner and course → product Odoo ID.
	 *
	 * @param int $transaction_id LearnDash transaction post ID.
	 * @return array<string, mixed>
	 */
	private function load_transaction_data( int $transaction_id ): array {
		$data = $this->handler->load_transaction( $transaction_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve user → partner.
		$user_id    = $data['user_id'] ?? 0;
		$partner_id = $this->resolve_partner_from_user( $user_id );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for transaction.', [ 'transaction_id' => $transaction_id ] );
			return [];
		}

		// Resolve course → Odoo product.
		$course_id       = $data['course_id'] ?? 0;
		$product_odoo_id = 0;
		if ( $course_id > 0 ) {
			$product_odoo_id = $this->get_mapping( 'course', $course_id ) ?? 0;
		}

		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for transaction course.', [ 'course_id' => $course_id ] );
			return [];
		}

		$settings               = $this->get_settings();
		$auto_post              = ! empty( $settings['auto_post_invoices'] );
		$data['transaction_id'] = $transaction_id;

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
		[ $user_id, $course_id ] = self::decode_synthetic_id( $synthetic_id );

		$data = $this->handler->load_enrollment( $user_id, $course_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve user → partner.
		$partner_id = $this->resolve_partner_from_email( $data['user_email'], $data['user_name'], $user_id );

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
	 * Ensure the parent product (course or group) is synced before dependent entity.
	 *
	 * @param int    $wp_id       Entity ID.
	 * @param string $entity_type 'transaction' or 'enrollment'.
	 * @return void
	 */
	private function ensure_product_synced( int $wp_id, string $entity_type ): void {
		$course_id = 0;

		if ( 'transaction' === $entity_type ) {
			$post = get_post( $wp_id );
			if ( $post && 'sfwd-transactions' === $post->post_type ) {
				$meta      = get_post_meta( $wp_id );
				$course_id = (int) ( $meta['course_id'][0] ?? $meta['post_id'][0] ?? 0 );
			}
		} elseif ( 'enrollment' === $entity_type ) {
			[ , $course_id ] = self::decode_synthetic_id( $wp_id );
		}

		$this->ensure_entity_synced( 'course', $course_id );
	}
}
