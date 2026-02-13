<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Job Manager Handler — data access for job listings.
 *
 * Loads job_listing CPT data and post meta, formats data for Odoo hr.job.
 * Provides reverse parsing (Odoo → WP) and save methods for pull sync.
 *
 * Called by Job_Manager_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.10.0
 */
class Job_Manager_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * WP → Odoo status map.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'publish' => 'recruit',
		'expired' => 'open',
		'preview' => 'open',
		'pending' => 'open',
	];

	/**
	 * Odoo → WP reverse status map.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_STATUS_MAP = [
		'recruit' => 'publish',
		'open'    => 'expired',
	];

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load job ────────────────────────────────────────────

	/**
	 * Load a job listing from the job_listing CPT.
	 *
	 * Returns pre-formatted data for Odoo hr.job (bypasses standard
	 * field mapping — same pattern as Events Calendar).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Job data for Odoo, or empty if not found.
	 */
	public function load_job( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'job_listing' !== $post->post_type ) {
			$this->logger->warning( 'Job listing not found or wrong post type.', [ 'post_id' => $post_id ] );
			return [];
		}

		$filled   = (string) get_post_meta( $post_id, '_filled', true );
		$location = (string) get_post_meta( $post_id, '_job_location', true );
		$company  = (string) get_post_meta( $post_id, '_company_name', true );

		// Determine Odoo state from WP post status and filled flag.
		$state = $this->map_status_to_odoo( $post->post_status, '1' === $filled );

		// Build description from post content + meta.
		$description = $this->build_description( $post->post_content, $location, $company );

		/**
		 * Filter WP-to-Odoo status mapping for job listings.
		 *
		 * @since 2.10.0
		 *
		 * @param string $state       Odoo state ('recruit' or 'open').
		 * @param string $post_status WP post status.
		 * @param bool   $filled      Whether the position is filled.
		 */
		$state = apply_filters( 'wp4odoo_job_manager_status_map', $state, $post->post_status, '1' === $filled );

		return [
			'name'              => $post->post_title,
			'description'       => $description,
			'state'             => $state,
			'no_of_recruitment' => '1' === $filled ? 0 : 1,
		];
	}

	// ─── Status mapping ──────────────────────────────────────

	/**
	 * Map WP post status to Odoo hr.job state.
	 *
	 * @param string $post_status WP post status.
	 * @param bool   $filled      Whether the position is filled.
	 * @return string Odoo state ('recruit' or 'open').
	 */
	private function map_status_to_odoo( string $post_status, bool $filled ): string {
		if ( $filled ) {
			return 'open';
		}

		return self::STATUS_MAP[ $post_status ] ?? 'open';
	}

	/**
	 * Map Odoo hr.job state to WP post status.
	 *
	 * @param string $odoo_state Odoo state.
	 * @return string WP post status.
	 */
	private function map_status_from_odoo( string $odoo_state ): string {
		$wp_status = self::REVERSE_STATUS_MAP[ $odoo_state ] ?? 'expired';

		/**
		 * Filter Odoo-to-WP status mapping for job listings.
		 *
		 * @since 2.10.0
		 *
		 * @param string $wp_status  WP post status.
		 * @param string $odoo_state Odoo hr.job state.
		 */
		return (string) apply_filters( 'wp4odoo_job_manager_reverse_status_map', $wp_status, $odoo_state );
	}

	// ─── Description builder ─────────────────────────────────

	/**
	 * Build a description for Odoo from post content and meta.
	 *
	 * @param string $content  Post content.
	 * @param string $location Job location.
	 * @param string $company  Company name.
	 * @return string
	 */
	private function build_description( string $content, string $location, string $company ): string {
		$parts = [];

		if ( '' !== $content ) {
			$parts[] = wp_strip_all_tags( $content );
		}

		$info = [];
		if ( '' !== $location ) {
			/* translators: %s: job location (e.g. "Paris, France"). */
			$info[] = sprintf( __( 'Location: %s', 'wp4odoo' ), $location );
		}
		if ( '' !== $company ) {
			/* translators: %s: company name. */
			$info[] = sprintf( __( 'Company: %s', 'wp4odoo' ), $company );
		}

		if ( ! empty( $info ) ) {
			$parts[] = implode( ' | ', $info );
		}

		return implode( "\n\n", $parts );
	}

	// ─── Parse job from Odoo (pull) ──────────────────────────

	/**
	 * Parse Odoo hr.job data into WordPress-compatible format.
	 *
	 * Reverse of load_job(). Handles department_id Many2one resolution.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress job data.
	 */
	public function parse_job_from_odoo( array $odoo_data ): array {
		$state       = (string) ( $odoo_data['state'] ?? 'open' );
		$post_status = $this->map_status_from_odoo( $state );

		// department_id is Many2one: [id, "Department Name"] or false.
		$department_name = '';
		$department_id   = $odoo_data['department_id'] ?? false;
		if ( is_array( $department_id ) && count( $department_id ) >= 2 ) {
			$department_name = (string) $department_id[1];
		}

		$no_of_recruitment = (int) ( $odoo_data['no_of_recruitment'] ?? 0 );

		return [
			'name'              => (string) ( $odoo_data['name'] ?? '' ),
			'description'       => (string) ( $odoo_data['description'] ?? '' ),
			'post_status'       => $post_status,
			'filled'            => 0 === $no_of_recruitment,
			'department_name'   => $department_name,
			'no_of_recruitment' => $no_of_recruitment,
		];
	}

	// ─── Save job (pull) ─────────────────────────────────────

	/**
	 * Save job data to a job_listing CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 * Sets post meta for _filled and optionally assigns job_listing_category
	 * taxonomy from department name.
	 *
	 * @param array<string, mixed> $data  Parsed job data from parse_job_from_odoo().
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_job( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'   => $data['name'] ?? '',
			'post_content' => $data['description'] ?? '',
			'post_type'    => 'job_listing',
			'post_status'  => $data['post_status'] ?? 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = wp_update_post( $post_args, true );
		} else {
			$result = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save job listing post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = (int) $result;

		// Set _filled meta.
		$filled = ! empty( $data['filled'] ) ? '1' : '0';
		update_post_meta( $post_id, '_filled', $filled );

		// Assign department as job_listing_category taxonomy term.
		$department_name = (string) ( $data['department_name'] ?? '' );
		if ( '' !== $department_name ) {
			wp_set_object_terms( $post_id, [ $department_name ], 'job_listing_category' );
		}

		return $post_id;
	}
}
