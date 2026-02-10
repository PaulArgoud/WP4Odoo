<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead management: CPT registration, shortcode, form submission, data load/save.
 *
 * Extracted from CRM_Module to isolate lead-specific logic.
 *
 * @package WP4Odoo
 * @since   1.0.3
 */
class Lead_Manager {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure that returns the CRM module settings.
	 *
	 * @var \Closure
	 */
	private \Closure $settings_getter;

	/**
	 * Constructor.
	 *
	 * @param Logger   $logger          Logger instance.
	 * @param \Closure $settings_getter Returns the module settings array.
	 */
	public function __construct( Logger $logger, \Closure $settings_getter ) {
		$this->logger          = $logger;
		$this->settings_getter = $settings_getter;
	}

	// ─── CPT + Shortcode ────────────────────────────────────

	/**
	 * Register the wp4odoo_lead custom post type.
	 *
	 * @return void
	 */
	public function register_lead_cpt(): void {
		register_post_type(
			'wp4odoo_lead',
			[
				'labels'          => [
					'name'               => __( 'Leads', 'wp4odoo' ),
					'singular_name'      => __( 'Lead', 'wp4odoo' ),
					'add_new_item'       => __( 'Add New Lead', 'wp4odoo' ),
					'edit_item'          => __( 'Edit Lead', 'wp4odoo' ),
					'view_item'          => __( 'View Lead', 'wp4odoo' ),
					'search_items'       => __( 'Search Leads', 'wp4odoo' ),
					'not_found'          => __( 'No leads found.', 'wp4odoo' ),
					'not_found_in_trash' => __( 'No leads found in Trash.', 'wp4odoo' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wp4odoo',
				'supports'        => [ 'title', 'editor' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	/**
	 * Render the lead form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML form.
	 */
	public function render_lead_form( array $atts = [] ): string {
		$settings = ( $this->settings_getter )();
		if ( empty( $settings['lead_form_enabled'] ) ) {
			return '';
		}

		wp_enqueue_style( 'wp4odoo-frontend', WP4ODOO_PLUGIN_URL . 'assets/css/frontend.css', [], WP4ODOO_VERSION );
		wp_enqueue_script( 'wp4odoo-lead-form', WP4ODOO_PLUGIN_URL . 'assets/js/lead-form.js', [], WP4ODOO_VERSION, true );
		wp_localize_script(
			'wp4odoo-lead-form',
			'wp4odooLead',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp4odoo_lead' ),
				'i18n'    => [
					'sending' => __( 'Sending...', 'wp4odoo' ),
					'success' => __( 'Your message has been sent successfully.', 'wp4odoo' ),
					'error'   => __( 'An error occurred. Please try again.', 'wp4odoo' ),
				],
			]
		);

		$source = $atts['source'] ?? get_the_title();

		ob_start();
		?>
		<form class="wp4odoo-lead-form" data-source="<?php echo esc_attr( $source ); ?>">
			<p>
				<label for="wp4odoo-lead-name"><?php esc_html_e( 'Name', 'wp4odoo' ); ?> <span class="required">*</span></label>
				<input type="text" id="wp4odoo-lead-name" name="name" required />
			</p>
			<p>
				<label for="wp4odoo-lead-email"><?php esc_html_e( 'Email', 'wp4odoo' ); ?> <span class="required">*</span></label>
				<input type="email" id="wp4odoo-lead-email" name="email" required />
			</p>
			<p>
				<label for="wp4odoo-lead-phone"><?php esc_html_e( 'Phone', 'wp4odoo' ); ?></label>
				<input type="tel" id="wp4odoo-lead-phone" name="phone" />
			</p>
			<p>
				<label for="wp4odoo-lead-company"><?php esc_html_e( 'Company', 'wp4odoo' ); ?></label>
				<input type="text" id="wp4odoo-lead-company" name="company" />
			</p>
			<p>
				<label for="wp4odoo-lead-message"><?php esc_html_e( 'Message', 'wp4odoo' ); ?></label>
				<textarea id="wp4odoo-lead-message" name="description" rows="5"></textarea>
			</p>
			<p>
				<button type="submit" class="wp4odoo-lead-submit"><?php esc_html_e( 'Send', 'wp4odoo' ); ?></button>
			</p>
			<div class="wp4odoo-lead-feedback" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	// ─── AJAX ───────────────────────────────────────────────

	/**
	 * Handle AJAX lead form submission.
	 *
	 * @return void
	 */
	public function handle_lead_submission(): void {
		check_ajax_referer( 'wp4odoo_lead' );

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$company = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
		$desc    = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$source  = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';

		if ( '' === $name || '' === $email ) {
			wp_send_json_error( [ 'message' => __( 'Name and email are required.', 'wp4odoo' ) ] );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid email address.', 'wp4odoo' ) ] );
		}

		$lead_data                = compact( 'name', 'email', 'phone', 'company', 'source' );
		$lead_data['description'] = $desc;

		$wp_id = $this->save_lead_data( $lead_data );

		if ( 0 === $wp_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save lead.', 'wp4odoo' ) ] );
		}

		Queue_Manager::push( 'crm', 'lead', 'create', $wp_id, null, $lead_data );
		$this->logger->info(
			'Lead form submitted and enqueued.',
			[
				'wp_id' => $wp_id,
				'email' => $email,
			]
		);

		/**
		 * Fires after a lead form is submitted and saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $wp_id     The lead post ID.
		 * @param array $lead_data The sanitized lead data.
		 */
		do_action( 'wp4odoo_lead_created', $wp_id, $lead_data );

		wp_send_json_success( [ 'message' => __( 'Your message has been sent successfully.', 'wp4odoo' ) ] );
	}

	// ─── Data Load / Save ───────────────────────────────────

	/**
	 * Load lead data from an wp4odoo_lead CPT post.
	 *
	 * @param int $wp_id Post ID.
	 * @return array
	 */
	public function load_lead_data( int $wp_id ): array {
		$post = get_post( $wp_id );
		if ( ! $post || 'wp4odoo_lead' !== $post->post_type ) {
			return [];
		}

		return [
			'name'        => $post->post_title,
			'email'       => get_post_meta( $wp_id, '_lead_email', true ),
			'phone'       => get_post_meta( $wp_id, '_lead_phone', true ),
			'company'     => get_post_meta( $wp_id, '_lead_company', true ),
			'description' => $post->post_content,
			'source'      => get_post_meta( $wp_id, '_lead_source', true ),
		];
	}

	/**
	 * Save lead data as an wp4odoo_lead CPT post.
	 *
	 * @param array $data  Mapped lead data.
	 * @param int   $wp_id Existing post ID (0 to create).
	 * @return int Post ID or 0 on failure.
	 */
	public function save_lead_data( array $data, int $wp_id = 0 ): int {
		$post_data = [
			'post_type'    => 'wp4odoo_lead',
			'post_title'   => $data['name'] ?? __( 'Lead', 'wp4odoo' ),
			'post_content' => $data['description'] ?? '',
			'post_status'  => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_data['ID'] = $wp_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save lead post.', [ 'error' => $result->get_error_message() ] );
			return 0;
		}

		$post_id = (int) $result;

		$meta_fields = [
			'email'   => '_lead_email',
			'phone'   => '_lead_phone',
			'company' => '_lead_company',
			'source'  => '_lead_source',
		];
		foreach ( $meta_fields as $data_key => $meta_key ) {
			if ( isset( $data[ $data_key ] ) ) {
				update_post_meta( $post_id, $meta_key, $data[ $data_key ] );
			}
		}

		return $post_id;
	}
}
