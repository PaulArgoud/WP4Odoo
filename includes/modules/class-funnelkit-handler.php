<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FunnelKit data handler — loads, saves, and parses FunnelKit entities.
 *
 * Accesses FunnelKit data via $wpdb custom table queries:
 * - {prefix}bwf_contact — contact records
 * - {prefix}bwf_contact_meta — contact metadata
 *
 * Funnel steps are stored as WordPress posts (wffn_step CPT).
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class FunnelKit_Handler {

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

	// ─── Load methods ────────────────────────────────────────

	/**
	 * Load a FunnelKit contact by ID.
	 *
	 * @param int $id Contact ID.
	 * @return array Contact data or empty array if not found.
	 */
	public function load_contact( int $id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bwf_contact';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'FunnelKit contact not found.', [ 'id' => $id ] );
			return [];
		}

		// Load meta values.
		$meta_table = $wpdb->prefix . 'bwf_contact_meta';
		$meta_rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta_table} WHERE contact_id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$meta = [];
		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $meta_row ) {
				$meta[ $meta_row['meta_key'] ] = $meta_row['meta_value'];
			}
		}

		return [
			'id'              => (int) $row['id'],
			'email'           => $row['email'] ?? '',
			'first_name'      => $row['f_name'] ?? '',
			'last_name'       => $row['l_name'] ?? '',
			'phone'           => $row['contact_no'] ?? '',
			'current_step_id' => (int) ( $meta['current_step_id'] ?? 0 ),
			'funnel_id'       => (int) ( $meta['funnel_id'] ?? 0 ),
		];
	}

	/**
	 * Load a FunnelKit funnel step by post ID.
	 *
	 * Steps are stored as the wffn_step custom post type.
	 *
	 * @param int $step_id Step (post) ID.
	 * @return array Step data or empty array if not found.
	 */
	public function load_step( int $step_id ): array {
		$post = get_post( $step_id );

		if ( ! $post || 'wffn_step' !== $post->post_type ) {
			$this->logger->warning( 'FunnelKit step not found.', [ 'step_id' => $step_id ] );
			return [];
		}

		$sequence  = (int) get_post_meta( $step_id, '_step_sequence', true );
		$funnel_id = (int) get_post_meta( $step_id, '_funnel_id', true );
		$type      = (string) get_post_meta( $step_id, '_step_type', true );

		return [
			'id'        => $step_id,
			'title'     => $post->post_title,
			'type'      => $type,
			'sequence'  => $sequence,
			'funnel_id' => $funnel_id,
		];
	}

	// ─── Save methods (pull) ─────────────────────────────────

	/**
	 * Save a contact to the FunnelKit contacts table.
	 *
	 * Finds existing contact by email for upsert behavior.
	 *
	 * @param array $data Contact data.
	 * @return int The contact ID (0 on failure).
	 */
	public function save_contact( array $data ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'bwf_contact';

		// Find existing by email.
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $data['email'] ?? '' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$values = [
			'email'      => sanitize_email( $data['email'] ?? '' ),
			'f_name'     => sanitize_text_field( $data['first_name'] ?? '' ),
			'l_name'     => sanitize_text_field( $data['last_name'] ?? '' ),
			'contact_no' => sanitize_text_field( $data['phone'] ?? '' ),
		];

		if ( $existing ) {
			$wpdb->update( $table, $values, [ 'id' => (int) $existing ] );
			return (int) $existing;
		}

		$wpdb->insert( $table, $values );
		return (int) $wpdb->insert_id;
	}

	// ─── Format for Odoo ─────────────────────────────────────

	/**
	 * Format a FunnelKit contact as an Odoo crm.lead record.
	 *
	 * @param array $contact Contact data from load_contact().
	 * @return array Odoo crm.lead field values.
	 */
	public function format_lead( array $contact ): array {
		$first = $contact['first_name'] ?? '';
		$last  = $contact['last_name'] ?? '';
		$name  = trim( $first . ' ' . $last );

		return [
			'email_from'     => $contact['email'] ?? '',
			'contact_name'   => $name,
			'phone'          => $contact['phone'] ?? '',
			'x_wp_source'    => 'funnelkit',
			'x_wp_funnel_id' => $contact['funnel_id'] ?? 0,
		];
	}

	/**
	 * Format a FunnelKit step as an Odoo crm.stage record.
	 *
	 * @param array $step     Step data from load_step().
	 * @param int   $team_id  Odoo CRM team (pipeline) ID from settings.
	 * @return array Odoo crm.stage field values.
	 */
	public function format_stage( array $step, int $team_id = 0 ): array {
		$values = [
			'name'     => $step['title'] ?? '',
			'sequence' => $step['sequence'] ?? 0,
		];

		if ( $team_id > 0 ) {
			$values['team_id'] = $team_id;
		}

		return $values;
	}

	// ─── Parse from Odoo ─────────────────────────────────────

	/**
	 * Parse contact data from an Odoo crm.lead record.
	 *
	 * Splits the Odoo contact_name field into first_name and last_name.
	 * Extracts the stage_id name for step resolution.
	 *
	 * @param array $odoo_data Odoo record data.
	 * @return array WordPress-compatible contact data.
	 */
	public function parse_contact_from_odoo( array $odoo_data ): array {
		$name_parts = explode( ' ', $odoo_data['contact_name'] ?? '', 2 );

		$data = [
			'email'      => $odoo_data['email_from'] ?? '',
			'first_name' => $name_parts[0],
			'last_name'  => $name_parts[1] ?? '',
			'phone'      => $odoo_data['phone'] ?? '',
		];

		// Extract stage name for step resolution.
		if ( isset( $odoo_data['stage_id'] ) && is_array( $odoo_data['stage_id'] ) ) {
			$data['_stage_name'] = $odoo_data['stage_id'][1] ?? '';
		}

		return $data;
	}

	// ─── Stage / Step resolution ─────────────────────────────

	/**
	 * Resolve an Odoo crm.stage ID from a FunnelKit step via entity_map.
	 *
	 * @param int                          $step_id    FunnelKit step (post) ID.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map Entity map repository.
	 * @return int|null Odoo crm.stage ID, or null if not mapped.
	 */
	public function resolve_stage_from_step( int $step_id, \WP4Odoo\Entity_Map_Repository $entity_map ): ?int {
		return $entity_map->get_odoo_id( 'funnelkit', 'step', $step_id );
	}

	/**
	 * Resolve a FunnelKit step ID from an Odoo stage name.
	 *
	 * Uses Status_Mapper::resolve() with a filterable map for keyword
	 * heuristic or direct name matching.
	 *
	 * @param string           $stage_name Odoo crm.stage name.
	 * @param array<int, string> $step_map   Map of step_id => step title.
	 * @return int|null Matched step ID, or null if no match.
	 */
	public function resolve_step_from_stage( string $stage_name, array $step_map ): ?int {
		if ( '' === $stage_name || empty( $step_map ) ) {
			return null;
		}

		// Build reverse map: title (lowercase) => step_id.
		$reverse = [];
		foreach ( $step_map as $step_id => $title ) {
			$reverse[ strtolower( $title ) ] = $step_id;
		}

		/**
		 * Filter the stage-to-step name map.
		 *
		 * @since 3.2.0
		 *
		 * @param array<string, int> $reverse Map of lowercase title => step_id.
		 * @param string             $stage_name The Odoo stage name to resolve.
		 */
		$reverse = apply_filters( 'wp4odoo_funnelkit_stage_map', $reverse, $stage_name );

		$key = strtolower( $stage_name );
		return $reverse[ $key ] ?? null;
	}
}
