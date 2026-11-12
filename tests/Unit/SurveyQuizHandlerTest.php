<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Logger;
use WP4Odoo\Modules\Survey_Quiz_Handler;
use WP4Odoo\Modules\Survey_Extractor;

/**
 * Unit tests for Survey_Quiz_Handler and Survey_Extractor.
 *
 * Tests survey formatting, response formatting, question type mapping,
 * and plugin-specific data extraction from Ays Quiz Maker and QSM.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Survey_Quiz_Handler::class)]
#[CoversClass(\WP4Odoo\Modules\Survey_Extractor::class)]
class SurveyQuizHandlerTest extends Module_Test_Case {

	private Survey_Quiz_Handler $handler;
	private Survey_Extractor $extractor;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->handler   = new Survey_Quiz_Handler( new Logger( 'test' ) );
		$this->extractor = new Survey_Extractor( new Logger( 'test' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Handler: format_survey ─────────────────────────────

	public function test_format_survey_returns_title_and_questions(): void {
		$data = [
			'title'     => 'Math Quiz',
			'questions' => [
				[ 'title' => 'What is 2+2?', 'type' => 'radio' ],
				[ 'title' => 'What is 3*3?', 'type' => 'radio' ],
			],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertSame( 'Math Quiz', $result['title'] );
		$this->assertCount( 2, $result['question_and_page_ids'] );
		$this->assertSame( 0, $result['question_and_page_ids'][0][0] );
		$this->assertSame( 0, $result['question_and_page_ids'][0][1] );
		$this->assertSame( 'What is 2+2?', $result['question_and_page_ids'][0][2]['title'] );
	}

	public function test_format_survey_returns_empty_when_no_title(): void {
		$data = [
			'title'     => '',
			'questions' => [
				[ 'title' => 'Q1', 'type' => 'text' ],
			],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertSame( [], $result );
	}

	public function test_format_survey_includes_description_when_non_empty(): void {
		$data = [
			'title'       => 'Science Quiz',
			'description' => 'Test your science knowledge',
			'questions'   => [],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertSame( 'Test your science knowledge', $result['description'] );
	}

	public function test_format_survey_omits_description_when_empty(): void {
		$data = [
			'title'       => 'Science Quiz',
			'description' => '',
			'questions'   => [],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertArrayNotHasKey( 'description', $result );
	}

	public function test_format_survey_maps_radio_to_simple_choice(): void {
		$data = [
			'title'     => 'Quiz',
			'questions' => [
				[ 'title' => 'Q1', 'type' => 'radio' ],
			],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertSame( 'simple_choice', $result['question_and_page_ids'][0][2]['question_type'] );
	}

	public function test_format_survey_maps_checkbox_to_multiple_choice(): void {
		$data = [
			'title'     => 'Quiz',
			'questions' => [
				[ 'title' => 'Q1', 'type' => 'checkbox' ],
			],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertSame( 'multiple_choice', $result['question_and_page_ids'][0][2]['question_type'] );
	}

	public function test_format_survey_maps_text_to_text_box(): void {
		$data = [
			'title'     => 'Quiz',
			'questions' => [
				[ 'title' => 'Q1', 'type' => 'text' ],
			],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertSame( 'text_box', $result['question_and_page_ids'][0][2]['question_type'] );
	}

	public function test_format_survey_skips_questions_without_title(): void {
		$data = [
			'title'     => 'Quiz',
			'questions' => [
				[ 'title' => '', 'type' => 'radio' ],
				[ 'title' => 'Valid Q', 'type' => 'text' ],
			],
		];

		$result = $this->handler->format_survey( $data );

		$this->assertCount( 1, $result['question_and_page_ids'] );
		$this->assertSame( 'Valid Q', $result['question_and_page_ids'][0][2]['title'] );
	}

	// ─── Handler: format_response ───────────────────────────

	public function test_format_response_has_state_done(): void {
		$result = $this->handler->format_response( [], 0, 0 );

		$this->assertSame( 'done', $result['state'] );
	}

	public function test_format_response_includes_survey_id_when_positive(): void {
		$result = $this->handler->format_response( [], 42, 0 );

		$this->assertSame( 42, $result['survey_id'] );
	}

	public function test_format_response_excludes_survey_id_when_zero(): void {
		$result = $this->handler->format_response( [], 0, 0 );

		$this->assertArrayNotHasKey( 'survey_id', $result );
	}

	public function test_format_response_includes_partner_id_when_positive(): void {
		$result = $this->handler->format_response( [], 0, 7 );

		$this->assertSame( 7, $result['partner_id'] );
	}

	public function test_format_response_excludes_partner_id_when_zero(): void {
		$result = $this->handler->format_response( [], 0, 0 );

		$this->assertArrayNotHasKey( 'partner_id', $result );
	}

	public function test_format_response_includes_scoring_total_when_present(): void {
		$data = [ 'total_score' => 85.0 ];

		$result = $this->handler->format_response( $data, 0, 0 );

		$this->assertSame( 85.0, $result['scoring_total'] );
	}

	public function test_format_response_excludes_scoring_total_when_absent(): void {
		$result = $this->handler->format_response( [], 0, 0 );

		$this->assertArrayNotHasKey( 'scoring_total', $result );
	}

	public function test_format_response_includes_answer_line_tuples(): void {
		$data = [
			'answers' => [
				[ 'question' => 'What is 2+2?', 'answer' => '4' ],
				[ 'question' => 'What is 3*3?', 'answer' => '9' ],
			],
		];

		$result = $this->handler->format_response( $data, 0, 0 );

		$this->assertCount( 2, $result['user_input_line_ids'] );
		$this->assertSame( 0, $result['user_input_line_ids'][0][0] );
		$this->assertSame( 0, $result['user_input_line_ids'][0][1] );
		$this->assertSame( 'What is 2+2?', $result['user_input_line_ids'][0][2]['display_name'] );
		$this->assertSame( '4', $result['user_input_line_ids'][0][2]['value_text_box'] );
	}

	// ─── Extractor: extract_survey_from_ays ─────────────────

	public function test_extract_survey_from_ays_returns_normalized_data(): void {
		$this->wpdb->get_row_return = [
			'id'           => 1,
			'title'        => 'Math Quiz',
			'description'  => 'Test your math',
			'question_ids' => '1,2',
		];
		$this->wpdb->get_results_return = [
			[ 'id' => 1, 'question' => 'What is 2+2?', 'type' => 'radio', 'answers' => json_encode( [ '3', '4', '5' ] ) ],
			[ 'id' => 2, 'question' => 'What is 3*3?', 'type' => 'radio', 'answers' => json_encode( [ '6', '9', '12' ] ) ],
		];

		$data = $this->extractor->extract_survey_from_ays( 1 );

		$this->assertSame( 'Math Quiz', $data['title'] );
		$this->assertSame( 'Test your math', $data['description'] );
		$this->assertCount( 2, $data['questions'] );
		$this->assertSame( 'What is 2+2?', $data['questions'][0]['title'] );
		$this->assertSame( 'radio', $data['questions'][0]['type'] );
		$this->assertSame( 'ays', $data['source'] );
	}

	public function test_extract_survey_from_ays_returns_empty_when_not_found(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->extractor->extract_survey_from_ays( 999 );

		$this->assertSame( [], $data );
	}

	// ─── Extractor: extract_survey_from_qsm ────────────────

	public function test_extract_survey_from_qsm_returns_normalized_data(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'ID'             => 20,
			'post_type'      => 'qsm_quiz',
			'post_title'     => 'Science Quiz',
			'post_content'   => 'Test your science knowledge',
			'post_date_gmt'  => '2024-01-15',
			'post_author'    => 1,
			'post_status'    => 'publish',
		];
		$GLOBALS['_wp_post_meta'][20] = [
			'_qsm_questions' => [
				[ 'question' => 'What is H2O?', 'type' => 'text', 'answers' => [ 'Water' ] ],
			],
		];

		$data = $this->extractor->extract_survey_from_qsm( 20 );

		$this->assertSame( 'Science Quiz', $data['title'] );
		$this->assertSame( 'Test your science knowledge', $data['description'] );
		$this->assertCount( 1, $data['questions'] );
		$this->assertSame( 'What is H2O?', $data['questions'][0]['title'] );
		$this->assertSame( 'qsm', $data['source'] );
	}

	public function test_extract_survey_from_qsm_returns_empty_when_not_found(): void {
		$data = $this->extractor->extract_survey_from_qsm( 999 );

		$this->assertSame( [], $data );
	}

	public function test_extract_survey_from_qsm_returns_empty_for_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][30] = (object) [
			'ID'           => 30,
			'post_type'    => 'post',
			'post_title'   => 'Not a quiz',
			'post_content' => '',
		];

		$data = $this->extractor->extract_survey_from_qsm( 30 );

		$this->assertSame( [], $data );
	}

	// ─── Extractor: extract_response_from_ays ───────────────

	public function test_extract_response_from_ays_returns_normalized_data(): void {
		$this->wpdb->get_row_return = [
			'id'         => 1,
			'quiz_id'    => 1,
			'user_email' => 'student@test.com',
			'user_name'  => 'Student',
			'score'      => 85.0,
			'options'    => json_encode( [ 'Q1' => '4', 'Q2' => '9' ] ),
			'end_date'   => '2024-01-15 15:00:00',
		];

		$data = $this->extractor->extract_response_from_ays( 1 );

		$this->assertSame( 1, $data['quiz_id'] );
		$this->assertSame( 'student@test.com', $data['user_email'] );
		$this->assertSame( 'Student', $data['user_name'] );
		$this->assertSame( 85.0, $data['total_score'] );
		$this->assertCount( 2, $data['answers'] );
		$this->assertSame( 'Q1', $data['answers'][0]['question'] );
		$this->assertSame( '4', $data['answers'][0]['answer'] );
		$this->assertSame( '2024-01-15 15:00:00', $data['date'] );
		$this->assertSame( 'ays', $data['source'] );
	}

	public function test_extract_response_from_ays_returns_empty_when_not_found(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->extractor->extract_response_from_ays( 999 );

		$this->assertSame( [], $data );
	}

	// ─── Extractor: extract_response_from_qsm ──────────────

	public function test_extract_response_from_qsm_returns_normalized_data(): void {
		$this->wpdb->get_row_return = [
			'result_id'    => 1,
			'quiz_id'      => 20,
			'email'        => 'student@test.com',
			'name'         => 'Student',
			'point_score'  => 90.0,
			'quiz_results' => json_encode( [
				[ 'question' => 'What is H2O?', 'answer' => 'Water', 'points' => 10 ],
			] ),
			'time_taken'   => '2024-01-15 16:00:00',
		];

		$data = $this->extractor->extract_response_from_qsm( 1 );

		$this->assertSame( 20, $data['quiz_id'] );
		$this->assertSame( 'student@test.com', $data['user_email'] );
		$this->assertSame( 'Student', $data['user_name'] );
		$this->assertSame( 90.0, $data['total_score'] );
		$this->assertCount( 1, $data['answers'] );
		$this->assertSame( 'What is H2O?', $data['answers'][0]['question'] );
		$this->assertSame( 'Water', $data['answers'][0]['answer'] );
		$this->assertSame( 10.0, $data['answers'][0]['score'] );
		$this->assertSame( '2024-01-15 16:00:00', $data['date'] );
		$this->assertSame( 'qsm', $data['source'] );
	}

	public function test_extract_response_from_qsm_returns_empty_when_not_found(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->extractor->extract_response_from_qsm( 999 );

		$this->assertSame( [], $data );
	}
}
