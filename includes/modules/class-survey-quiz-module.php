<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Survey & Quiz Module — quiz/survey data → Odoo Survey.
 *
 * Intercepts quiz and survey submissions from supported plugins,
 * extracts data via Survey_Extractor, and pushes to Odoo's
 * survey.survey and survey.user_input models.
 *
 * Supported: Quiz Maker (Ays), Quiz And Survey Master (QSM).
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class Survey_Quiz_Module extends Module_Base {

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
		'survey'   => 'survey.survey',
		'response' => 'survey.user_input',
	];

	/**
	 * Default field mappings.
	 *
	 * Data is pre-formatted by the handler (identity pass-through).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'survey'   => [
			'title'                 => 'title',
			'description'           => 'description',
			'question_and_page_ids' => 'question_and_page_ids',
		],
		'response' => [
			'survey_id'           => 'survey_id',
			'partner_id'          => 'partner_id',
			'state'               => 'state',
			'user_input_line_ids' => 'user_input_line_ids',
		],
	];

	/**
	 * Survey handler.
	 *
	 * @var Survey_Quiz_Handler
	 */
	private Survey_Quiz_Handler $handler;

	/**
	 * Survey extractor.
	 *
	 * @var Survey_Extractor
	 */
	private Survey_Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'survey_quiz', 'Survey & Quiz', $client_provider, $entity_map, $settings );
		$this->handler   = new Survey_Quiz_Handler( $this->logger );
		$this->extractor = new Survey_Extractor( $this->logger );
	}

	/**
	 * Boot the module: register hooks for detected quiz/survey plugins.
	 *
	 * @return void
	 */
	public function boot(): void {
		$settings = $this->get_settings();

		// Quiz Maker (Ays) hooks.
		if ( ! empty( $settings['sync_ays_quizzes'] ) && ( defined( 'QUIZ_MAKER_VERSION' ) || defined( 'AYS_QUIZ_VERSION' ) ) ) {
			add_action( 'ays_qm_front_end_save_result', $this->safe_callback( [ $this, 'on_ays_result' ] ), 10, 1 );
		}

		// Quiz And Survey Master (QSM) hooks.
		if ( ! empty( $settings['sync_qsm'] ) && defined( 'QSM_PLUGIN_INSTALLED' ) ) {
			add_action( 'qsm_quiz_submitted', $this->safe_callback( [ $this, 'on_qsm_response' ] ), 10, 2 );
		}
	}

	// ─── Hook Callbacks ──────────────────────────────────────

	/**
	 * Handle Quiz Maker (Ays) result submission.
	 *
	 * @param int $result_id Ays quiz result ID.
	 * @return void
	 */
	public function on_ays_result( int $result_id ): void {
		if ( $this->is_importing() || $result_id <= 0 ) {
			return;
		}

		// Extract and sync the quiz structure first.
		$response_data = $this->extractor->extract_response_from_ays( $result_id );
		if ( empty( $response_data ) ) {
			return;
		}

		$quiz_id = $response_data['quiz_id'] ?? 0;
		if ( $quiz_id > 0 ) {
			$this->sync_quiz_structure( 'ays', $quiz_id );
		}

		$this->process_response( $response_data );
	}

	/**
	 * Handle QSM quiz submission.
	 *
	 * @param int                  $result_id QSM result ID.
	 * @param array<string, mixed> $results   QSM results data.
	 * @return void
	 */
	public function on_qsm_response( int $result_id, array $results = [] ): void {
		if ( $this->is_importing() || $result_id <= 0 ) {
			return;
		}

		$response_data = $this->extractor->extract_response_from_qsm( $result_id );
		if ( empty( $response_data ) ) {
			return;
		}

		$quiz_id = $response_data['quiz_id'] ?? 0;
		if ( $quiz_id > 0 ) {
			$this->sync_quiz_structure( 'qsm', $quiz_id );
		}

		$this->process_response( $response_data );
	}

	// ─── Settings ────────────────────────────────────────────

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_ays_quizzes' => true,
			'sync_qsm'         => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_ays_quizzes' => [
				'label'       => __( 'Sync Quiz Maker (Ays)', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Quiz Maker quizzes and results to Odoo Survey.', 'wp4odoo' ),
			],
			'sync_qsm'         => [
				'label'       => __( 'Sync Quiz And Survey Master', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push QSM quizzes and responses to Odoo Survey.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Check external dependency status.
	 *
	 * At least one supported quiz/survey plugin must be active.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		$plugins = [
			'Quiz Maker'             => defined( 'QUIZ_MAKER_VERSION' ) || defined( 'AYS_QUIZ_VERSION' ),
			'Quiz And Survey Master' => defined( 'QSM_PLUGIN_INSTALLED' ),
		];

		$active = array_filter( $plugins );

		if ( empty( $active ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'At least one quiz or survey plugin must be installed and activated.', 'wp4odoo' ),
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
					/* translators: %s: plugin name */
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
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'survey' === $entity_type && ! empty( $odoo_values['title'] ) ) {
			return [ [ 'title', '=', $odoo_values['title'] ] ];
		}

		return [];
	}

	// ─── Data Access ─────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'survey' === $entity_type ) {
			$data = get_option( 'wp4odoo_survey_' . $wp_id, [] );
			return is_array( $data ) ? $data : [];
		}

		if ( 'response' === $entity_type ) {
			$data = get_option( 'wp4odoo_survey_response_' . $wp_id, [] );
			return is_array( $data ) ? $data : [];
		}

		return [];
	}

	/**
	 * Map WP data to Odoo values (identity — pre-formatted).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array<string, mixed>
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		return $wp_data;
	}

	// ─── Private Helpers ─────────────────────────────────────

	/**
	 * Sync a quiz/survey structure to Odoo if not already synced.
	 *
	 * @param string $source  Source plugin ('ays' or 'qsm').
	 * @param int    $quiz_id Quiz ID in the source plugin.
	 * @return void
	 */
	private function sync_quiz_structure( string $source, int $quiz_id ): void {
		// Check if already synced.
		$existing = $this->get_mapping( 'survey', $quiz_id );
		if ( $existing ) {
			return;
		}

		$survey_data = 'ays' === $source
			? $this->extractor->extract_survey_from_ays( $quiz_id )
			: $this->extractor->extract_survey_from_qsm( $quiz_id );

		if ( empty( $survey_data ) ) {
			return;
		}

		$formatted = $this->handler->format_survey( $survey_data );
		if ( empty( $formatted ) ) {
			return;
		}

		$ref_id = $quiz_id;
		update_option( 'wp4odoo_survey_' . $ref_id, $formatted, false );
		Queue_Manager::push( 'survey_quiz', 'survey', 'create', $ref_id, null, $formatted );
	}

	/**
	 * Process an extracted quiz response: resolve partner, format, and enqueue.
	 *
	 * @param array<string, mixed> $response_data Normalized response data.
	 * @return void
	 */
	private function process_response( array $response_data ): void {
		if ( empty( $response_data ) ) {
			return;
		}

		// Resolve or create partner.
		$email = $response_data['user_email'] ?? '';
		$name  = $response_data['user_name'] ?? '';

		$partner_id = 0;
		if ( '' !== $email ) {
			$partner_id = $this->resolve_partner_from_email( $email, $name );
		}

		// Resolve survey Odoo ID.
		$quiz_id        = (int) ( $response_data['quiz_id'] ?? 0 );
		$survey_odoo_id = $this->get_mapping( 'survey', $quiz_id ) ?? 0;

		$formatted = $this->handler->format_response( $response_data, $survey_odoo_id, $partner_id );
		if ( empty( $formatted ) ) {
			return;
		}

		$ref_id = absint( crc32( ( $response_data['date'] ?? '' ) . $email ) );
		update_option( 'wp4odoo_survey_response_' . $ref_id, $formatted, false );
		Queue_Manager::push( 'survey_quiz', 'response', 'create', $ref_id, null, $formatted );

		$this->logger->info(
			'Survey response enqueued for Odoo sync.',
			[
				'ref_id' => $ref_id,
				'source' => $response_data['source'] ?? '',
			]
		);
	}
}
