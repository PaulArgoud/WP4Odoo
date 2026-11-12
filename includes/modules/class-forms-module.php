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
 * Intercepts form submissions from 8 supported plugins, extracts
 * lead data via Form_Handler, saves to the wp4odoo_lead CPT,
 * and enqueues a push job to Odoo's crm.lead model.
 *
 * Supported: Gravity Forms, WPForms, Contact Form 7, Fluent Forms,
 * Formidable Forms, Ninja Forms, Forminator, JetFormBuilder,
 * Elementor Pro, Divi, Bricks.
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

		// JetFormBuilder hook.
		if ( ! empty( $settings['sync_jetformbuilder'] ) && defined( 'JET_FORM_BUILDER_VERSION' ) ) {
			add_action( 'jet-form-builder/form-handler/after-send', $this->safe_callback( [ $this, 'on_jetformbuilder_submitted' ] ), 10, 2 );
		}

		// Elementor Pro hook.
		if ( ! empty( $settings['sync_elementor_forms'] ) && defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			add_action( 'elementor_pro/forms/new_record', $this->safe_callback( [ $this, 'on_elementor_form_submitted' ] ), 10, 2 );
		}

		// Divi hook (filter — pass through original value).
		if ( ! empty( $settings['sync_divi_forms'] ) && function_exists( 'et_setup_theme' ) ) {
			add_filter( 'et_pb_contact_form_submit', $this->safe_callback( [ $this, 'on_divi_form_submitted' ] ), 10, 2 );
		}

		// Bricks hook.
		if ( ! empty( $settings['sync_bricks_forms'] ) && defined( 'BRICKS_VERSION' ) ) {
			add_action( 'bricks/form/custom_action', $this->safe_callback( [ $this, 'on_bricks_form_submitted' ] ), 10, 1 );
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

	/**
	 * Handle JetFormBuilder submission.
	 *
	 * @param object $handler    JetFormBuilder form handler.
	 * @param bool   $is_success Whether the submission was successful.
	 * @return void
	 */
	public function on_jetformbuilder_submitted( $handler, $is_success ): void {
		if ( ! $is_success || $this->is_importing() ) {
			return;
		}

		$form_data  = $handler->get_form_data();
		$form_id    = $handler->get_form_id();
		$form_title = get_the_title( $form_id );

		$lead_data = $this->form_handler->extract_from_jetformbuilder( $form_data, $form_title );
		$this->process_lead( $lead_data, 'jetformbuilder', [ 'form_data' => $form_data ] );
	}

	/**
	 * Handle Elementor Pro form submission.
	 *
	 * @param object $record  Elementor Form_Record.
	 * @param object $handler Elementor Ajax_Handler.
	 * @return void
	 */
	public function on_elementor_form_submitted( $record, $handler ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$raw_fields = $record->get( 'fields' );
		$form_name  = $record->get_form_settings( 'form_name' ) ?? '';
		$fields     = [];

		foreach ( $raw_fields as $id => $field ) {
			$value = trim( (string) ( $field['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$fields[] = [
				'type'  => $field['type'] ?? 'text',
				'label' => $field['title'] ?? $id,
				'value' => $value,
			];
		}

		$lead_data = $this->form_handler->extract_from_elementor( $fields, $form_name );
		$this->process_lead( $lead_data, 'elementor', [ 'fields' => $raw_fields ] );
	}

	/**
	 * Handle Divi contact form submission (filter — must return first arg).
	 *
	 * @param mixed $et_contact_error Original error state (pass through).
	 * @param array $contact_form_info Form data including fields.
	 * @return mixed Unmodified $et_contact_error.
	 */
	public function on_divi_form_submitted( $et_contact_error, $contact_form_info ) {
		if ( $this->is_importing() ) {
			return $et_contact_error;
		}

		// Only process successful submissions (no errors).
		if ( ! empty( $et_contact_error ) ) {
			return $et_contact_error;
		}

		$fields     = [];
		$form_title = $contact_form_info['title'] ?? '';

		foreach ( $contact_form_info as $key => $value ) {
			if ( ! is_string( $value ) || in_array( $key, [ 'title', 'contact_form_id' ], true ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$fields[] = [
				'type'  => 'text',
				'label' => $key,
				'value' => $value,
			];
		}

		$lead_data = $this->form_handler->extract_from_divi( $fields, $form_title );
		$this->process_lead( $lead_data, 'divi', [ 'form_info' => $contact_form_info ] );

		return $et_contact_error;
	}

	/**
	 * Handle Bricks form submission.
	 *
	 * @param object $form Bricks Form object.
	 * @return void
	 */
	public function on_bricks_form_submitted( $form ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$form_settings = $form->get_settings() ?? [];
		$form_fields   = $form->get_fields() ?? [];
		$form_title    = $form_settings['formName'] ?? '';

		$fields = [];
		foreach ( $form_fields as $id => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ' ', array_filter( array_map( 'trim', $value ) ) );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$fields[] = [
				'type'  => 'text',
				'label' => $id,
				'value' => $value,
			];
		}

		$lead_data = $this->form_handler->extract_from_bricks( $fields, $form_title );
		$this->process_lead( $lead_data, 'bricks', [ 'form_fields' => $form_fields ] );
	}

	// ─── Settings ────────────────────────────────────────────

	/**
	 * Get default settings for the Forms module.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_gravity_forms'   => true,
			'sync_wpforms'         => true,
			'sync_cf7'             => true,
			'sync_fluent_forms'    => true,
			'sync_formidable'      => true,
			'sync_ninja_forms'     => true,
			'sync_forminator'      => true,
			'sync_jetformbuilder'  => true,
			'sync_elementor_forms' => true,
			'sync_divi_forms'      => true,
			'sync_bricks_forms'    => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_gravity_forms'   => [
				'label'       => __( 'Sync Gravity Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Gravity Forms submissions.', 'wp4odoo' ),
			],
			'sync_wpforms'         => [
				'label'       => __( 'Sync WPForms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from WPForms submissions.', 'wp4odoo' ),
			],
			'sync_cf7'             => [
				'label'       => __( 'Sync Contact Form 7', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Contact Form 7 submissions.', 'wp4odoo' ),
			],
			'sync_fluent_forms'    => [
				'label'       => __( 'Sync Fluent Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Fluent Forms submissions.', 'wp4odoo' ),
			],
			'sync_formidable'      => [
				'label'       => __( 'Sync Formidable Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Formidable Forms submissions.', 'wp4odoo' ),
			],
			'sync_ninja_forms'     => [
				'label'       => __( 'Sync Ninja Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Ninja Forms submissions.', 'wp4odoo' ),
			],
			'sync_forminator'      => [
				'label'       => __( 'Sync Forminator', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Forminator submissions.', 'wp4odoo' ),
			],
			'sync_jetformbuilder'  => [
				'label'       => __( 'Sync JetFormBuilder', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from JetFormBuilder submissions.', 'wp4odoo' ),
			],
			'sync_elementor_forms' => [
				'label'       => __( 'Sync Elementor Pro Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Elementor Pro form submissions.', 'wp4odoo' ),
			],
			'sync_divi_forms'      => [
				'label'       => __( 'Sync Divi Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Divi Contact Form submissions.', 'wp4odoo' ),
			],
			'sync_bricks_forms'    => [
				'label'       => __( 'Sync Bricks Forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create Odoo leads from Bricks form submissions.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return class_exists( 'GFAPI' ) || function_exists( 'wpforms' ) || defined( 'WPCF7_VERSION' ) || defined( 'FLUENTFORM' ) || class_exists( 'FrmAppHelper' ) || class_exists( 'Ninja_Forms' ) || defined( 'FORMINATOR_VERSION' ) || defined( 'JET_FORM_BUILDER_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' ) || function_exists( 'et_setup_theme' ) || defined( 'BRICKS_VERSION' );
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
			'JetFormBuilder'   => defined( 'JET_FORM_BUILDER_VERSION' ),
			'Elementor Pro'    => defined( 'ELEMENTOR_PRO_VERSION' ),
			'Divi'             => function_exists( 'et_setup_theme' ),
			'Bricks'           => defined( 'BRICKS_VERSION' ),
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

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Leads dedup by email_from.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'lead' === $entity_type && ! empty( $odoo_values['email_from'] ) ) {
			return [ [ 'email_from', '=', $odoo_values['email_from'] ] ];
		}

		return [];
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
	 * Shared by all 11 hook callbacks to avoid duplication.
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
