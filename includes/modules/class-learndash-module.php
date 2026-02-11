<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnDash Module — push LMS data to Odoo.
 *
 * Syncs LearnDash courses and groups as Odoo service products (product.product),
 * transactions as invoices (account.move), and enrollments as sale orders
 * (sale.order). Push-only (WP → Odoo).
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
			'sync_courses'      => true,
			'sync_groups'       => true,
			'sync_transactions' => true,
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
				'description' => __( 'Push LearnDash courses to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_groups'       => [
				'label'       => __( 'Sync groups', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LearnDash groups to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_transactions' => [
				'label'       => __( 'Sync transactions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push LearnDash payment transactions to Odoo as invoices.', 'wp4odoo' ),
			],
			'sync_enrollments'  => [
				'label'       => __( 'Sync enrollments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push course enrollments to Odoo as sale orders.', 'wp4odoo' ),
			],
			'auto_post_invoice' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm invoices in Odoo for completed transactions.', 'wp4odoo' ),
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
			$this->auto_post_invoice( 'auto_post_invoice', 'transaction', $wp_id );
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
		$user_id = $data['user_id'] ?? 0;
		if ( $user_id <= 0 ) {
			$this->logger->warning( 'Transaction has no user.', [ 'transaction_id' => $transaction_id ] );
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find user for transaction.',
				[
					'transaction_id' => $transaction_id,
					'user_id'        => $user_id,
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
		$auto_post              = ! empty( $settings['auto_post_invoice'] );
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
			// Synthetic ID: user_id * 1M + course_id.
			$course_id = $wp_id % 1_000_000;
		}

		$this->ensure_entity_synced( 'course', $course_id );
	}
}
