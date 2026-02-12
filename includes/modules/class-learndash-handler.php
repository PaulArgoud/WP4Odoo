<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnDash Handler — data access for courses, groups, transactions, and enrollments.
 *
 * Loads LearnDash entities and formats data for Odoo push.
 * Transactions are pre-formatted as Odoo `account.move` data (invoice).
 * Enrollments are formatted as Odoo `sale.order` data.
 *
 * Called by LearnDash_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.6.0
 */
class LearnDash_Handler {

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
	 * Load a LearnDash course.
	 *
	 * @param int $course_id Course post ID (CPT sfwd-courses).
	 * @return array<string, mixed> Course data for field mapping, or empty if not found.
	 */
	public function load_course( int $course_id ): array {
		$post = get_post( $course_id );
		if ( ! $post || 'sfwd-courses' !== $post->post_type ) {
			$this->logger->warning( 'LearnDash course not found.', [ 'course_id' => $course_id ] );
			return [];
		}

		$price_data = learndash_get_course_price( $course_id );

		return [
			'title'       => $post->post_title,
			'description' => wp_strip_all_tags( $post->post_content ),
			'list_price'  => (float) $price_data['price'],
			'type'        => 'service',
		];
	}

	// ─── Load group ────────────────────────────────────────

	/**
	 * Load a LearnDash group.
	 *
	 * @param int $group_id Group post ID (CPT groups).
	 * @return array<string, mixed> Group data for field mapping, or empty if not found.
	 */
	public function load_group( int $group_id ): array {
		$post = get_post( $group_id );
		if ( ! $post || 'groups' !== $post->post_type ) {
			$this->logger->warning( 'LearnDash group not found.', [ 'group_id' => $group_id ] );
			return [];
		}

		$price_data = learndash_get_group_price( $group_id );

		return [
			'title'       => $post->post_title,
			'description' => wp_strip_all_tags( $post->post_content ),
			'list_price'  => (float) $price_data['price'],
			'type'        => 'service',
		];
	}

	// ─── Load transaction ──────────────────────────────────

	/**
	 * Load a LearnDash transaction as raw data.
	 *
	 * Returns parsed transaction meta for resolution by the module.
	 *
	 * @param int $transaction_id Transaction post ID (CPT sfwd-transactions).
	 * @return array<string, mixed> Transaction data, or empty if not found.
	 */
	public function load_transaction( int $transaction_id ): array {
		$post = get_post( $transaction_id );
		if ( ! $post || 'sfwd-transactions' !== $post->post_type ) {
			$this->logger->warning( 'LearnDash transaction not found.', [ 'transaction_id' => $transaction_id ] );
			return [];
		}

		$meta = get_post_meta( $transaction_id );

		return [
			'course_id'  => (int) ( $meta['course_id'][0] ?? $meta['post_id'][0] ?? 0 ),
			'user_id'    => (int) ( $meta['user_id'][0] ?? $post->post_author ),
			'amount'     => (float) ( $meta['mc_gross'][0] ?? $meta['stripe_price'][0] ?? 0 ),
			'gateway'    => $meta['ld_payment_processor'][0] ?? 'unknown',
			'currency'   => strtoupper( $meta['mc_currency'][0] ?? $meta['stripe_currency'][0] ?? 'USD' ),
			'created_at' => $post->post_date_gmt,
		];
	}

	// ─── Load enrollment ───────────────────────────────────

	/**
	 * Load a LearnDash enrollment (user + course access).
	 *
	 * @param int $user_id   WordPress user ID.
	 * @param int $course_id LearnDash course ID.
	 * @return array<string, mixed> Enrollment data, or empty if not found.
	 */
	public function load_enrollment( int $user_id, int $course_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'User not found for enrollment.', [ 'user_id' => $user_id ] );
			return [];
		}

		$post = get_post( $course_id );
		if ( ! $post || 'sfwd-courses' !== $post->post_type ) {
			$this->logger->warning( 'Course not found for enrollment.', [ 'course_id' => $course_id ] );
			return [];
		}

		$enrolled_date = learndash_user_get_course_date( $user_id, $course_id );

		return [
			'user_id'    => $user_id,
			'course_id'  => $course_id,
			'user_email' => $user->user_email,
			'user_name'  => $user->display_name,
			'date'       => $enrolled_date ?: gmdate( 'Y-m-d' ),
		];
	}

	// ─── Format invoice ────────────────────────────────────

	/**
	 * Format transaction data as an Odoo account.move (invoice).
	 *
	 * Returns pre-formatted Odoo data with invoice_line_ids as
	 * One2many tuples [(0, 0, {...})].
	 *
	 * @param array<string, mixed> $data           Transaction data from load_transaction().
	 * @param int                  $product_odoo_id Resolved Odoo product.product ID.
	 * @param int                  $partner_id      Resolved Odoo partner ID.
	 * @param bool                 $auto_post       Whether to auto-post the invoice.
	 * @return array<string, mixed> Odoo account.move data.
	 */
	public function format_invoice( array $data, int $product_odoo_id, int $partner_id, bool $auto_post ): array {
		$course_name = '';
		$course_id   = $data['course_id'] ?? 0;
		if ( $course_id > 0 ) {
			$course_post = get_post( $course_id );
			if ( $course_post ) {
				$course_name = $course_post->post_title;
			}
		}

		$invoice = [
			'move_type'        => 'out_invoice',
			'partner_id'       => $partner_id,
			'invoice_date'     => substr( $data['created_at'] ?? '', 0, 10 ),
			'ref'              => sprintf( 'LD-TXN-%d', $data['transaction_id'] ?? 0 ),
			'invoice_line_ids' => [
				[
					0,
					0,
					[
						'product_id' => $product_odoo_id,
						'quantity'   => 1,
						'price_unit' => (float) ( $data['amount'] ?? 0 ),
						'name'       => $course_name ?: __( 'LearnDash course', 'wp4odoo' ),
					],
				],
			],
		];

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
						'name'       => $course_name ?: __( 'LearnDash enrollment', 'wp4odoo' ),
					],
				],
			],
		];
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

	// ─── Parse group from Odoo ────────────────────────────

	/**
	 * Parse Odoo product data into WordPress group format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress group data.
	 */
	public function parse_group_from_odoo( array $odoo_data ): array {
		return [
			'title'       => $odoo_data['name'] ?? '',
			'description' => $odoo_data['description_sale'] ?? '',
			'list_price'  => (float) ( $odoo_data['list_price'] ?? 0 ),
		];
	}

	// ─── Save course ──────────────────────────────────────

	/**
	 * Save course data to a sfwd-courses CPT post.
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
			'post_type'    => 'sfwd-courses',
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

		return $result;
	}

	// ─── Save group ───────────────────────────────────────

	/**
	 * Save group data to a groups CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed group data.
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_group( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'   => $data['title'] ?? '',
			'post_content' => $data['description'] ?? '',
			'post_type'    => 'groups',
			'post_status'  => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save group post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		return $result;
	}
}
