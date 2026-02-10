<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer portal: shortcode rendering, AJAX pagination, data queries.
 *
 * Extracted from Sales_Module to isolate portal-specific logic.
 *
 * @package WP4Odoo
 * @since   1.1.0
 */
class Portal_Manager {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure that returns the Sales module settings.
	 *
	 * @var \Closure
	 */
	private \Closure $settings_getter;

	/**
	 * Partner service for user → Odoo partner lookup.
	 *
	 * @var Partner_Service
	 */
	private Partner_Service $partner_service;

	/**
	 * Constructor.
	 *
	 * @param Logger          $logger          Logger instance.
	 * @param \Closure        $settings_getter Returns the module settings array.
	 * @param Partner_Service $partner_service Partner lookup service.
	 */
	public function __construct( Logger $logger, \Closure $settings_getter, Partner_Service $partner_service ) {
		$this->logger          = $logger;
		$this->settings_getter = $settings_getter;
		$this->partner_service = $partner_service;
	}

	// ─── Shortcode ───────────────────────────────────────────

	/**
	 * Render the customer portal shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_portal( array $atts = [] ): string {
		$settings = ( $this->settings_getter )();

		if ( empty( $settings['portal_enabled'] ) ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return '<p class="wp4odoo-portal-login">'
				. sprintf(
					/* translators: %s: login URL */
					__( 'Please <a href="%s">log in</a> to access your customer portal.', 'wp4odoo' ),
					esc_url( wp_login_url( get_permalink() ) )
				)
				. '</p>';
		}

		$partner_id = $this->get_partner_id_for_user( get_current_user_id() );

		if ( ! $partner_id ) {
			return '<p class="wp4odoo-portal-nolink">'
				. __( 'Your account is not yet linked to an Odoo partner. Please contact the administrator.', 'wp4odoo' )
				. '</p>';
		}

		$per_page = max( 1, (int) ( $settings['orders_per_page'] ?? 10 ) );
		$orders   = $this->get_orders( $partner_id, 1, $per_page );
		$invoices = $this->get_invoices( $partner_id, 1, $per_page );

		// Enqueue portal assets.
		wp_enqueue_style( 'wp4odoo-portal', WP4ODOO_PLUGIN_URL . 'assets/css/portal.css', [], WP4ODOO_VERSION );
		wp_enqueue_script( 'wp4odoo-portal', WP4ODOO_PLUGIN_URL . 'assets/js/portal.js', [], WP4ODOO_VERSION, true );
		wp_localize_script(
			'wp4odoo-portal',
			'wp4odooPortal',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wp4odoo_portal' ),
				'perPage'   => $per_page,
				'partnerId' => $partner_id,
				'i18n'      => [
					'loading' => __( 'Loading...', 'wp4odoo' ),
					'error'   => __( 'An error occurred. Please try again.', 'wp4odoo' ),
				],
			]
		);

		ob_start();
		include WP4ODOO_PLUGIN_DIR . 'templates/customer-portal.php';
		return ob_get_clean();
	}

	// ─── AJAX ────────────────────────────────────────────────

	/**
	 * Handle AJAX request for portal tab data (pagination / tab switch).
	 *
	 * @return void
	 */
	public function handle_portal_data(): void {
		check_ajax_referer( 'wp4odoo_portal' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not authenticated.', 'wp4odoo' ) ], 403 );
		}

		$tab        = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'orders';
		$page       = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$partner_id = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;

		// Verify the partner_id belongs to the current user.
		$actual = $this->get_partner_id_for_user( get_current_user_id() );
		if ( ! $actual || $actual !== $partner_id ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp4odoo' ) ], 403 );
		}

		$settings = ( $this->settings_getter )();
		$per_page = max( 1, (int) ( $settings['orders_per_page'] ?? 10 ) );

		$data = ( 'invoices' === $tab )
			? $this->get_invoices( $partner_id, $page, $per_page )
			: $this->get_orders( $partner_id, $page, $per_page );

		wp_send_json_success( $data );
	}

	// ─── Data Queries ────────────────────────────────────────

	/**
	 * Look up the Odoo partner ID linked to a WordPress user via CRM entity_map.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int|null Odoo partner ID, or null if not linked.
	 */
	public function get_partner_id_for_user( int $user_id ): ?int {
		return $this->partner_service->get_partner_id_for_user( $user_id );
	}

	/**
	 * Get orders for a given Odoo partner.
	 *
	 * @param int $partner_id Odoo partner ID.
	 * @param int $page       Current page (1-based).
	 * @param int $per_page   Items per page.
	 * @return array{items: array, total: int, page: int, pages: int}
	 */
	public function get_orders( int $partner_id, int $page = 1, int $per_page = 10 ): array {
		return $this->query_cpt(
			'wp4odoo_order',
			$partner_id,
			$page,
			$per_page,
			[
				'_order_total'    => '_order_total',
				'_order_date'     => '_order_date',
				'_order_state'    => '_order_state',
				'_order_currency' => '_order_currency',
			]
		);
	}

	/**
	 * Get invoices for a given Odoo partner.
	 *
	 * @param int $partner_id Odoo partner ID.
	 * @param int $page       Current page (1-based).
	 * @param int $per_page   Items per page.
	 * @return array{items: array, total: int, page: int, pages: int}
	 */
	public function get_invoices( int $partner_id, int $page = 1, int $per_page = 10 ): array {
		return $this->query_cpt(
			'wp4odoo_invoice',
			$partner_id,
			$page,
			$per_page,
			[
				'_invoice_total'    => '_invoice_total',
				'_invoice_date'     => '_invoice_date',
				'_invoice_state'    => '_invoice_state',
				'_payment_state'    => '_payment_state',
				'_invoice_currency' => '_invoice_currency',
			]
		);
	}

	/**
	 * Query a CPT by Odoo partner ID with pagination.
	 *
	 * @param string $post_type  The post type to query.
	 * @param int    $partner_id Odoo partner ID.
	 * @param int    $page       Current page (1-based).
	 * @param int    $per_page   Items per page.
	 * @param array  $meta_keys  Meta keys to retrieve: data_key => meta_key.
	 * @return array{items: array, total: int, page: int, pages: int}
	 */
	private function query_cpt( string $post_type, int $partner_id, int $page, int $per_page, array $meta_keys ): array {
		$query = new \WP_Query(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => '_wp4odoo_partner_id',
						'value' => $partner_id,
						'type'  => 'NUMERIC',
					],
				],
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$items = [];
		foreach ( $query->posts as $post ) {
			$item = [
				'id'    => $post->ID,
				'title' => $post->post_title,
			];
			foreach ( $meta_keys as $data_key => $meta_key ) {
				$item[ $data_key ] = get_post_meta( $post->ID, $meta_key, true );
			}
			$items[] = $item;
		}

		$total = (int) $query->found_posts;
		$pages = (int) $query->max_num_pages;

		return compact( 'items', 'total', 'page', 'pages' );
	}
}
