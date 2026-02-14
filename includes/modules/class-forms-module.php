<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forms Module — form plugin submissions → Odoo CRM leads.
 *
 * Intercepts form submissions from 7 supported plugins, extracts
 * lead data via Form_Handler, saves to the wp4odoo_lead CPT,
 * and enqueues a push job to Odoo's crm.lead model.
 *
 * Supported: Gravity Forms, WPForms, Contact Form 7, Fluent Forms,
 * Formidable Forms, Ninja Forms, Forminator.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Forms_Module extends Module_Base {


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
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'forms', 'Forms', $client_provider, $entity_map, $settings );
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
			add_action( 'gform_after_submission', $this->safe_callback( [ $this, 'on_gravity_form_submitted' ] ), 10, 2 );
		}

		// WPForms hook.
		if ( ! empty( $settings['sync_wpforms'] ) && function_exists( 'wpforms' ) ) {
			add_action( 'wpforms_process_complete', $this->safe_callback( [ $this, 'on_wpforms_submitted' ] ), 10, 4 );
		}

		// Contact Form 7 hook.
		if ( ! empty( $settings['sync_cf7'] ) && defined( 'WPCF7_VERSION' ) ) {
			add_action( 'wpcf7_mail_sent', $this->safe_callback( [ $this, 'on_cf7_submitted' ] ), 10, 1 );
		}

		// Fluent Forms hook.
		if ( ! empty( $settings['sync_fluent_forms'] ) && defined( 'FLUENTFORM' ) ) {
			add_action( 'fluentform/submission_inserted', $this->safe_callback( [ $this, 'on_fluent_form_submitted' ] ), 10, 3 );
		}

		// Formidable Forms hook.
		if ( ! empty( $settings['sync_formidable'] ) && class_exists( 'FrmAppHelper' ) ) {
			add_action( 'frm_after_create_entry', $this->safe_callback( [ $this, 'on_formidable_submitted' ] ), 10, 2 );
		}

		// Ninja Forms hook.
		if ( ! empty( $settings['sync_ninja_forms'] ) && class_exists( 'Ninja_Forms' ) ) {
			add_action( 'ninja_forms_after_submission', $this->safe_callback( [ $this, 'on_ninja_forms_submitted' ] ), 10, 1 );
		}

		// Forminator hook.
		if ( ! empty( $settings['sync_forminator'] ) && defined( 'FORMINATOR_VERSION' ) ) {
			add_action( 'forminator_custom_form_submit_before_set_fields', $this->safe_callback( [ $this, 'on_forminator_submitted' ] ), 10, 3 );
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
		$this->process_lead(
			$lead_data,
			'gravity_forms',
			[
				'entry' => $entry,
				'form'  => $form,
			]
		);
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
		$this->process_lead(
			$lead_data,
			'wpforms',
			[
				'fields'    => $fields,
				'entry'     => $entry,
				'form_data' => $form_data,
				'entry_id'  => $entry_id,
			]
		);
	}

	/**
	 * Handle Contact Form 7 submission.
	 *
	 * @param \WPCF7_ContactForm $contact_form CF7 form object.
	 * @return void
	 */
	public function on_cf7_submitted( $contact_form ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();

		// Normalise tags to plain arrays for testable handler.
		$tags = [];
		foreach ( $contact_form->scan_form_tags() as $tag ) {
			if ( ! empty( $tag->name ) ) {
				$tags[] = [
					'type' => $tag->basetype,
					'name' => $tag->name,
				];
			}
		}

		$lead_data = $this->form_handler->extract_from_cf7( $posted_data, $tags, $contact_form->title() );
		$this->process_lead( $lead_data, 'cf7', [ 'posted_data' => $posted_data ] );
	}

	/**
	 * Handle Fluent Forms submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param array  $form_data    Submitted data (field_name => value).
	 * @param object $form         Form object with title property.
	 * @return void
	 */
	public function on_fluent_form_submitted( int $submission_id, array $form_data, object $form ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$form_title = $form->title ?? '';
		$lead_data  = $this->form_handler->extract_from_fluent_forms( $form_data, $form_title );
		$this->process_lead( $lead_data, 'fluent_forms', [ 'form_data' => $form_data ] );
	}

	/**
	 * Handle Formidable Forms submission.
	 *
	 * Loads field definitions and entry values via Formidable's API,
	 * normalises them, and delegates extraction to Form_Handler.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 * @return void
	 */
	public function on_formidable_submitted( int $entry_id, int $form_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$frm_fields  = \FrmField::getAll( [ 'fi.form_id' => $form_id ] );
		$entry_metas = \FrmEntryMeta::getAll( [ 'it.item_id' => $entry_id ] );

		// Build value lookup: field_id → meta_value.
		$values = [];
		foreach ( $entry_metas as $meta ) {
			$values[ $meta->field_id ] = $meta->meta_value;
		}

		$fields = [];
		foreach ( $frm_fields as $field ) {
			$raw   = $values[ $field->id ] ?? '';
			$value = is_array( $raw ) ? implode( ' ', array_filter( array_map( 'trim', $raw ) ) ) : (string) $raw;
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$fields[] = [
				'type'  => $field->type ?? 'text',
				'label' => $field->name ?? '',
				'value' => $value,
			];
		}

		$form       = \FrmForm::getOne( $form_id );
		$form_title = $form ? $form->name : '';
		$lead_data  = $this->form_handler->extract_from_formidable( $fields, $form_title );
		$this->process_lead( $lead_data, 'formidable', [ 'entry_id' => $entry_id ] );
	}

	/**
	 * Handle Ninja Forms submission.
	 *
	 * @param array $form_data Ninja Forms submission data with 'fields' and 'settings'.
	 * @return void
	 */
	public function on_ninja_forms_submitted( array $form_data ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$fields = [];
		foreach ( ( $form_data['fields'] ?? [] ) as $field ) {
			$fields[] = [
				'type'  => $field['type'] ?? 'textbox',
				'label' => $field['label'] ?? '',
				'value' => $field['value'] ?? '',
			];
		}

		$form_title = $form_data['settings']['title'] ?? '';
		$lead_data  = $this->form_handler->extract_from_ninja_forms( $fields, $form_title );
		$this->process_lead( $lead_data, 'ninja_forms', [ 'form_data' => $form_data ] );
	}

	/**
	 * Handle Forminator submission.
	 *
	 * Normalises fields from the submitted data array. Forminator element
	 * IDs often embed the type (e.g. `email-1`, `text-2`, `phone-1`).
	 *
	 * @param object $entry          Forminator entry object.
	 * @param int    $form_id        Form ID.
	 * @param array  $form_data_array Submitted data (element_id => value).
	 * @return void
	 */
	public function on_forminator_submitted( object $entry, int $form_id, array $form_data_array ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$fields = [];
		foreach ( $form_data_array as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ' ', array_filter( array_map( 'trim', $value ) ) );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			// Extract type from Forminator element ID (e.g. "email-1" → "email").
			$type = (string) preg_replace( '/-\d+$/', '', (string) $key );

			$fields[] = [
				'type'  => $type,
				'label' => $key,
				'value' => $value,
			];
		}

		$form_title = '';
		if ( class_exists( 'Forminator_API' ) ) {
			$form_model = \Forminator_API::get_form( $form_id );
			if ( $form_model && ! is_wp_error( $form_model ) ) {
				$form_title = $form_model->settings['formName'] ?? '';
			}
		}

		$lead_data = $this->form_handler->extract_from_forminator( $fields, $form_title );
		$this->process_lead( $lead_data, 'forminator', [ 'form_data' => $form_data_array ] );
	}

	// ─── Settings ────────────────────────────────────────────

	/**
	 * Get default settings for the Forms module.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_gravity_forms' => true,
			'sync_wpforms'       => true,
			'sync_cf7'           => true,
			'sync_fluent_forms'  => true,
			'sync_formidable'    => true,
			'sync_ninja_forms'   => true,
			'sync_forminator'    => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
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
			'sync_cf7'           => [
				'label'       => __( 'Sync Contact Form 7', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Contact Form 7 submissions.', 'wp4odoo' ),
			],
			'sync_fluent_forms'  => [
				'label'       => __( 'Sync Fluent Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Fluent Forms submissions.', 'wp4odoo' ),
			],
			'sync_formidable'    => [
				'label'       => __( 'Sync Formidable Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Formidable Forms submissions.', 'wp4odoo' ),
			],
			'sync_ninja_forms'   => [
				'label'       => __( 'Sync Ninja Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Ninja Forms submissions.', 'wp4odoo' ),
			],
			'sync_forminator'    => [
				'label'       => __( 'Sync Forminator', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Forminator submissions.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Check external dependency status.
	 *
	 * At least one supported form plugin must be active.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		$plugins = [
			'Gravity Forms'    => class_exists( 'GFAPI' ),
			'WPForms'          => function_exists( 'wpforms' ),
			'Contact Form 7'   => defined( 'WPCF7_VERSION' ),
			'Fluent Forms'     => defined( 'FLUENTFORM' ),
			'Formidable Forms' => class_exists( 'FrmAppHelper' ),
			'Ninja Forms'      => class_exists( 'Ninja_Forms' ),
			'Forminator'       => defined( 'FORMINATOR_VERSION' ),
		];

		$active = array_filter( $plugins );

		if ( empty( $active ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'At least one form plugin must be installed and activated to use this module.', 'wp4odoo' ),
					],
				],
			];
		}

		$notices  = [];
		$inactive = array_diff_key( $plugins, $active );

		foreach ( $inactive as $name => $status ) {
			$notices[] = [
				'type'    => 'info',
				'message' => sprintf(
					/* translators: %s: form plugin name */
					__( '%s is not active.', 'wp4odoo' ),
					$name
				),
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
	 * Filter and enqueue extracted lead data.
	 *
	 * Shared by all 7 hook callbacks to avoid duplication.
	 *
	 * @param array  $lead_data   Extracted lead data (may be empty).
	 * @param string $source_type Source identifier for the filter.
	 * @param array  $raw_data    Original form submission data (for filter).
	 * @return void
	 */
	private function process_lead( array $lead_data, string $source_type, array $raw_data = [] ): void {
		/**
		 * Filter the lead data extracted from a form submission.
		 *
		 * Return an empty array to skip creating this lead.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $lead_data   Extracted lead data.
		 * @param string $source_type Plugin identifier (e.g. 'gravity_forms', 'cf7').
		 * @param array  $raw_data    Original form submission data.
		 */
		$lead_data = apply_filters( 'wp4odoo_form_lead_data', $lead_data, $source_type, $raw_data );

		$this->enqueue_lead( $lead_data );
	}

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
