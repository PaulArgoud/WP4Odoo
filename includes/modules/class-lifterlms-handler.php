<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LifterLMS Handler — data access for courses, memberships, orders, and enrollments.
 *
 * Loads LifterLMS entities and formats data for Odoo push.
 * Provides reverse parsing (Odoo → WP) and save methods for pull sync.
 * Orders are pre-formatted as Odoo `account.move` data (invoice).
 * Enrollments are formatted as Odoo `sale.order` data.
 *
 * LifterLMS uses WordPress CPTs:
 * - `llms_course` — courses
 * - `llms_membership` — memberships
 * - `llms_order` — payment orders (LLMS_Order wrapper)
 *
 * Called by LifterLMS_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class LifterLMS_Handler {

	/**
	 * Order status mapping: LifterLMS → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_STATUS_MAP = [
		'llms-active'    => 'posted',
		'llms-completed' => 'posted',
		'llms-pending'   => 'draft',
		'llms-on-hold'   => 'draft',
		'llms-refunded'  => 'cancel',
		'llms-cancelled' => 'cancel',
		'llms-expired'   => 'cancel',
		'llms-failed'    => 'cancel',
	];

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

	// ─── Load course ───────────────────────────────────────

	/**
	 * Load a LifterLMS course.
	 *
	 * @param int $course_id Course post ID (CPT llms_course).
	 * @return array<string, mixed> Course data for field mapping, or empty if not found.
	 */
	public function load_course( int $course_id ): array {
		$post = get_post( $course_id );
		if ( ! $post || 'llms_course' !== $post->post_type ) {
			$this->logger->warning( 'LifterLMS course not found.', [ 'course_id' => $course_id ] );
			return [];
		}

		$price = (float) get_post_meta( $course_id, '_llms_regular_price', true );

		return [
			'title'       => $post->post_title,
			'description' => wp_strip_all_tags( $post->post_content ),
			'list_price'  => $price,
			'type'        => 'service',
		];
	}

	// ─── Load membership ──────────────────────────────────

	/**
	 * Load a LifterLMS membership.
	 *
	 * @param int $membership_id Membership post ID (CPT llms_membership).
	 * @return array<string, mixed> Membership data for field mapping, or empty if not found.
	 */
	public function load_membership( int $membership_id ): array {
		$post = get_post( $membership_id );
		if ( ! $post || 'llms_membership' !== $post->post_type ) {
			$this->logger->warning( 'LifterLMS membership not found.', [ 'membership_id' => $membership_id ] );
			return [];
		}

		$price = (float) get_post_meta( $membership_id, '_llms_regular_price', true );

		return [
			'title'       => $post->post_title,
			'description' => wp_strip_all_tags( $post->post_content ),
			'list_price'  => $price,
			'type'        => 'service',
		];
	}

	// ─── Load order ────────────────────────────────────────

	/**
	 * Load a LifterLMS order as raw data.
	 *
	 * Returns parsed order data for resolution by the module.
	 *
	 * @param int $order_id Order post ID (CPT llms_order).
	 * @return array<string, mixed> Order data, or empty if not found.
	 */
	public function load_order( int $order_id ): array {
		$order = new \LLMS_Order( $order_id );
		if ( ! $order->get_id() ) {
			$this->logger->warning( 'LifterLMS order not found.', [ 'order_id' => $order_id ] );
			return [];
		}

		$post = get_post( $order_id );
		if ( ! $post || 'llms_order' !== $post->post_type ) {
			$this->logger->warning( 'LifterLMS order is not an llms_order CPT.', [ 'order_id' => $order_id ] );
			return [];
		}

		return [
			'product_id' => $order->get_product_id(),
			'user_id'    => $order->get_customer_id(),
			'total'      => $order->get_total(),
			'status'     => $order->get_status(),
			'date'       => $post->post_date_gmt,
		];
	}

	// ─── Load enrollment ──────────────────────────────────

	/**
	 * Load a LifterLMS enrollment (user + course).
	 *
	 * @param int $user_id   WordPress user ID.
	 * @param int $course_id Course post ID.
	 * @return array<string, mixed> Enrollment data, or empty if not found.
	 */
	public function load_enrollment( int $user_id, int $course_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'User not found for enrollment.', [ 'user_id' => $user_id ] );
			return [];
		}

		$post = get_post( $course_id );
		if ( ! $post || 'llms_course' !== $post->post_type ) {
			$this->logger->warning( 'Course not found for enrollment.', [ 'course_id' => $course_id ] );
			return [];
		}

		$student = llms_get_student( $user_id );
		$date    = '';
		if ( $student ) {
			$date = $student->get_enrollment_date( $course_id );
		}

		return [
			'user_id'    => $user_id,
			'course_id'  => $course_id,
			'user_email' => $user->user_email,
			'user_name'  => $user->display_name,
			'date'       => $date ?: gmdate( 'Y-m-d' ),
		];
	}

	// ─── Format invoice ────────────────────────────────────

	/**
	 * Format order data as an Odoo account.move (invoice).
	 *
	 * Returns pre-formatted Odoo data with invoice_line_ids as
	 * One2many tuples [(0, 0, {...})].
	 *
	 * @param array<string, mixed> $data             Order data from load_order().
	 * @param int                  $product_odoo_id  Resolved Odoo product.product ID.
	 * @param int                  $partner_id       Resolved Odoo partner ID.
	 * @param bool                 $auto_post        Whether to auto-post the invoice.
	 * @return array<string, mixed> Odoo account.move data.
	 */
	public function format_invoice( array $data, int $product_odoo_id, int $partner_id, bool $auto_post ): array {
		$product_name = '';
		$product_id   = $data['product_id'] ?? 0;
		if ( $product_id > 0 ) {
			$product_post = get_post( $product_id );
			if ( $product_post ) {
				$product_name = $product_post->post_title;
			}
		}

		$invoice = Odoo_Accounting_Formatter::for_account_move(
			$partner_id,
			$product_odoo_id,
			(float) ( $data['total'] ?? 0 ),
			substr( $data['date'] ?? '', 0, 10 ),
			sprintf( 'LLMS-ORD-%d', $data['order_id'] ?? 0 ),
			$product_name,
			__( 'LifterLMS course', 'wp4odoo' )
		);

		if ( $auto_post ) {
			$invoice['_auto_validate'] = true;
		}

		return $invoice;
	}

	// ─── Format sale order ─────────────────────────────────

	/**
	 * Format enrollment data as an Odoo sale.order.
	 *
	 * @param int    $product_odoo_id Resolved Odoo product.product ID.
	 * @param int    $partner_id      Resolved Odoo partner ID.
	 * @param string $date            Enrollment date (Y-m-d).
	 * @param string $course_name     Course name for the order line.
	 * @return array<string, mixed> Odoo sale.order data.
	 */
	public function format_sale_order( int $product_odoo_id, int $partner_id, string $date, string $course_name = '' ): array {
		return [
			'partner_id' => $partner_id,
			'date_order' => $date,
			'state'      => 'sale',
			'order_line' => [
				[
					0,
					0,
					[
						'product_id' => $product_odoo_id,
						'quantity'   => 1,
						'name'       => $course_name ?: __( 'LifterLMS enrollment', 'wp4odoo' ),
					],
				],
			],
		];
	}

	// ─── Product ID helpers ────────────────────────────────

	/**
	 * Get the product (course/membership) ID for an order.
	 *
	 * @param int $order_id LifterLMS order post ID.
	 * @return int Product ID, or 0 if not found.
	 */
	public function get_product_id_for_order( int $order_id ): int {
		$order = new \LLMS_Order( $order_id );
		return $order->get_product_id();
	}

	// ─── Parse course from Odoo ───────────────────────────

	/**
	 * Parse Odoo product data into WordPress course format.
	 *
	 * Reverse of load_course() + map_to_odoo(). Extracts name,
	 * description, and list_price from Odoo product.product data.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress course data.
	 */
	public function parse_course_from_odoo( array $odoo_data ): array {
		return [
			'title'       => $odoo_data['name'] ?? '',
			'description' => $odoo_data['description_sale'] ?? '',
			'list_price'  => (float) ( $odoo_data['list_price'] ?? 0 ),
		];
	}

	// ─── Parse membership from Odoo ───────────────────────

	/**
	 * Parse Odoo product data into WordPress membership format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress membership data.
	 */
	public function parse_membership_from_odoo( array $odoo_data ): array {
		return [
			'title'       => $odoo_data['name'] ?? '',
			'description' => $odoo_data['description_sale'] ?? '',
			'list_price'  => (float) ( $odoo_data['list_price'] ?? 0 ),
		];
	}

	// ─── Save course ──────────────────────────────────────

	/**
	 * Save course data to an llms_course CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed course data.
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_course( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'   => $data['title'] ?? '',
			'post_content' => $data['description'] ?? '',
			'post_type'    => 'llms_course',
			'post_status'  => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save course post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = $result;

		if ( isset( $data['list_price'] ) ) {
			\update_post_meta( $post_id, '_llms_regular_price', (string) $data['list_price'] );
		}

		return $post_id;
	}

	// ─── Save membership ─────────────────────────────────

	/**
	 * Save membership data to an llms_membership CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed membership data.
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_membership( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'   => $data['title'] ?? '',
			'post_content' => $data['description'] ?? '',
			'post_type'    => 'llms_membership',
			'post_status'  => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save membership post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = $result;

		if ( isset( $data['list_price'] ) ) {
			\update_post_meta( $post_id, '_llms_regular_price', (string) $data['list_price'] );
		}

		return $post_id;
	}

	// ─── Reverse status mapping ──────────────────────────

	/**
	 * Reverse order status mapping: Odoo account.move state → LifterLMS.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_ORDER_STATUS_MAP = [
		'draft'  => 'llms-pending',
		'posted' => 'llms-completed',
		'cancel' => 'llms-cancelled',
	];

	/**
	 * Map an Odoo account.move state to a LifterLMS order status.
	 *
	 * @param string $odoo_state Odoo account.move state.
	 * @return string LifterLMS order status.
	 */
	public function map_odoo_status_to_llms( string $odoo_state ): string {
		return Status_Mapper::resolve( $odoo_state, self::REVERSE_ORDER_STATUS_MAP, 'wp4odoo_lifterlms_reverse_order_status_map', 'llms-pending' );
	}

	// ─── Status mapping ────────────────────────────────────

	/**
	 * Map a LifterLMS order status to an Odoo account.move state.
	 *
	 * @param string $status LifterLMS order status (with llms- prefix).
	 * @return string Odoo account.move state.
	 */
	public function map_order_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::ORDER_STATUS_MAP, 'wp4odoo_lifterlms_order_status_map', 'draft' );
	}
}
