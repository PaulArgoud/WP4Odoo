<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forms Module — Gravity Forms and WPForms → Odoo CRM leads.
 *
 * Intercepts form submissions from Gravity Forms and/or WPForms,
 * extracts lead data via Form_Handler, saves to the wp4odoo_lead
 * CPT, and enqueues a push job to Odoo's crm.lead model.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Forms_Module extends Module_Base {

	protected string $id   = 'forms';
	protected string $name = 'Forms';

	/**
	 * Sync direction: Forms module only pushes to Odoo.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	protected array $odoo_models = [
		'lead' => 'crm.lead',
	];

	protected array $default_mappings = [
		'lead' => [
			'name'        => 'name',
			'email'       => 'email_from',
			'phone'       => 'phone',
			'company'     => 'partner_name',
			'description' => 'description',
			'source'      => 'x_wp_source',
		],
	];

	/**
	 * Lead CPT persistence (shared with CRM module).
	 *
	 * Initialised in __construct() so the Sync_Engine can call
	 * push_to_odoo() on residual queue jobs even when the module
	 * is not booted.
	 *
	 * @var Lead_Manager
	 */
	private Lead_Manager $lead_manager;

	/**
	 * Form data extraction handler.
	 *
	 * @var Form_Handler
	 */
	private Form_Handler $form_handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map ) {
		parent::__construct( $client_provider, $entity_map );
		$this->lead_manager = new Lead_Manager( $this->logger, fn() => $this->get_settings() );
		$this->form_handler = new Form_Handler( $this->logger );
	}

	/**
	 * Boot the module: register CPT and form submission hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		$settings = $this->get_settings();

		// Register lead CPT (safe to call multiple times — WP deduplicates).
		add_action( 'init', [ $this->lead_manager, 'register_lead_cpt' ] );

		// Gravity Forms hook.
		if ( ! empty( $settings['sync_gravity_forms'] ) && class_exists( 'GFAPI' ) ) {
			add_action( 'gform_after_submission', [ $this, 'on_gravity_form_submitted' ], 10, 2 );
		}

		// WPForms hook.
		if ( ! empty( $settings['sync_wpforms'] ) && function_exists( 'wpforms' ) ) {
			add_action( 'wpforms_process_complete', [ $this, 'on_wpforms_submitted' ], 10, 4 );
		}
	}

	// ─── Hook Callbacks ──────────────────────────────────────

	/**
	 * Handle Gravity Forms submission.
	 *
	 * @param array $entry GF entry array.
	 * @param array $form  GF form object.
	 * @return void
	 */
	public function on_gravity_form_submitted( array $entry, array $form ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$lead_data = $this->form_handler->extract_from_gravity_forms( $entry, $form );

		/**
		 * Filter the lead data extracted from a form submission.
		 *
		 * Return an empty array to skip creating this lead.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $lead_data   Extracted lead data.
		 * @param string $source_type 'gravity_forms' or 'wpforms'.
		 * @param array  $raw_data    Original form submission data.
		 */
		$lead_data = apply_filters(
			'wp4odoo_form_lead_data',
			$lead_data,
			'gravity_forms',
			[
				'entry' => $entry,
				'form'  => $form,
			]
		);

		$this->enqueue_lead( $lead_data );
	}

	/**
	 * Handle WPForms submission.
	 *
	 * @param array $fields    WPForms fields.
	 * @param array $entry     WPForms entry ($_POST).
	 * @param array $form_data WPForms form data.
	 * @param int   $entry_id  Entry ID.
	 * @return void
	 */
	public function on_wpforms_submitted( array $fields, array $entry, array $form_data, int $entry_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$lead_data = $this->form_handler->extract_from_wpforms( $fields, $form_data );

		/** This filter is documented in Forms_Module::on_gravity_form_submitted(). */
		$lead_data = apply_filters(
			'wp4odoo_form_lead_data',
			$lead_data,
			'wpforms',
			[
				'fields'    => $fields,
				'entry'     => $entry,
				'form_data' => $form_data,
				'entry_id'  => $entry_id,
			]
		);

		$this->enqueue_lead( $lead_data );
	}

	// ─── Settings ────────────────────────────────────────────

	/**
	 * Get default settings for the Forms module.
	 *
	 * @return array
	 */
	public function get_default_settings(): array {
		return [
			'sync_gravity_forms' => true,
			'sync_wpforms'       => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_gravity_forms' => [
				'label'       => __( 'Sync Gravity Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Gravity Forms submissions.', 'wp4odoo' ),
			],
			'sync_wpforms'       => [
				'label'       => __( 'Sync WPForms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from WPForms submissions.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Check external dependency status.
	 *
	 * At least one form plugin (Gravity Forms or WPForms) must be active.
	 *
	 * @return array{available: bool, notices: array}
	 */
	public function get_dependency_status(): array {
		$gf_available = class_exists( 'GFAPI' );
		$wf_available = function_exists( 'wpforms' );

		if ( ! $gf_available && ! $wf_available ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'Gravity Forms or WPForms must be installed and activated to use this module.', 'wp4odoo' ),
					],
				],
			];
		}

		$notices = [];

		if ( ! $gf_available ) {
			$notices[] = [
				'type'    => 'info',
				'message' => __( 'Gravity Forms is not active. Only WPForms submissions will be synced.', 'wp4odoo' ),
			];
		}

		if ( ! $wf_available ) {
			$notices[] = [
				'type'    => 'info',
				'message' => __( 'WPForms is not active. Only Gravity Forms submissions will be synced.', 'wp4odoo' ),
			];
		}

		return [
			'available' => true,
			'notices'   => $notices,
		];
	}

	// ─── Data Access (Module_Base abstract) ───────────────────

	/**
	 * Load WordPress data for a lead entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'lead' === $entity_type ) {
			return $this->lead_manager->load_lead_data( $wp_id );
		}

		$this->log_unsupported_entity( $entity_type, 'load' );
		return [];
	}

	/**
	 * Save data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'lead' === $entity_type ) {
			return $this->lead_manager->save_lead_data( $data, $wp_id );
		}

		$this->log_unsupported_entity( $entity_type, 'save' );
		return 0;
	}

	/**
	 * Delete a WordPress entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'lead' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		$this->log_unsupported_entity( $entity_type, 'delete' );
		return false;
	}

	// ─── Private Helpers ─────────────────────────────────────

	/**
	 * Save lead data to CPT and enqueue for Odoo sync.
	 *
	 * @param array $lead_data Extracted and filtered lead data.
	 * @return void
	 */
	private function enqueue_lead( array $lead_data ): void {
		if ( empty( $lead_data ) ) {
			return;
		}

		$wp_id = $this->lead_manager->save_lead_data( $lead_data );

		if ( 0 === $wp_id ) {
			$this->logger->error(
				'Failed to save form lead to CPT.',
				[ 'email' => $lead_data['email'] ?? '' ]
			);
			return;
		}

		Queue_Manager::push( 'forms', 'lead', 'create', $wp_id, null, $lead_data );

		$this->logger->info(
			'Form lead enqueued for Odoo sync.',
			[
				'wp_id'  => $wp_id,
				'email'  => $lead_data['email'] ?? '',
				'source' => $lead_data['source'] ?? '',
			]
		);

		/**
		 * Fires after a form lead is created and enqueued.
		 *
		 * @since 2.0.0
		 *
		 * @param int   $wp_id     The lead post ID.
		 * @param array $lead_data The lead data.
		 */
		do_action( 'wp4odoo_form_lead_created', $wp_id, $lead_data );
	}
}
