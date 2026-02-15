<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Error_Type;
use WP4Odoo\Module_Base;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub that exposes classify_exception() for testing.
 */
class ErrorClassificationTestModule extends Module_Base {

	public function __construct() {
		parent::__construct( 'error_class_test', 'ErrorClassTest', wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [];
	}

	/**
	 * Expose the private static classify_exception via reflection.
	 *
	 * @param \RuntimeException $e The exception to classify.
	 * @return Error_Type
	 */
	public static function test_classify( \RuntimeException $e ): Error_Type {
		$method = new \ReflectionMethod( Module_Base::class, 'classify_exception' );
		return $method->invoke( null, $e );
	}
}

/**
 * Unit tests for the Error_Classification trait.
 *
 * Verifies that RuntimeExceptions are correctly classified as
 * Transient (retryable) or Permanent (no retry) based on
 * HTTP codes and message patterns.
 */
class ErrorClassificationTest extends TestCase {

	// ─── Permanent errors ──────────────────────────────────

	public function test_access_denied_is_permanent(): void {
		$e = new \RuntimeException( 'Access denied on model res.partner', 403 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_access_error_is_permanent(): void {
		$e = new \RuntimeException( 'AccessError: not allowed', 200 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_validation_error_is_permanent(): void {
		$e = new \RuntimeException( 'ValidationError: field required', 200 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_user_input_error_is_permanent(): void {
		$e = new \RuntimeException( 'UserInputError: invalid value', 200 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_missing_required_is_permanent(): void {
		$e = new \RuntimeException( 'Missing required field: name', 200 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_constraint_is_permanent(): void {
		$e = new \RuntimeException( 'Unique constraint violated', 200 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	/**
	 * Odoo sometimes wraps AccessError in HTTP 500.
	 * Business error pattern should take precedence over HTTP code.
	 */
	public function test_business_error_in_500_is_permanent(): void {
		$e = new \RuntimeException( 'AccessError: no access rights', 500 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}

	// ─── Transient errors: HTTP codes ──────────────────────

	public function test_429_is_transient(): void {
		$e = new \RuntimeException( 'Rate limit exceeded', 429 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_503_is_transient(): void {
		$e = new \RuntimeException( 'Service unavailable', 503 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_500_is_transient(): void {
		$e = new \RuntimeException( 'Internal server error', 500 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_502_is_transient(): void {
		$e = new \RuntimeException( 'Bad gateway', 502 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	// ─── Transient errors: network patterns ────────────────

	public function test_http_error_is_transient(): void {
		$e = new \RuntimeException( 'HTTP Error: connection reset', 0 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_timed_out_is_transient(): void {
		$e = new \RuntimeException( 'Operation timed out after 30 seconds', 0 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_connection_refused_is_transient(): void {
		$e = new \RuntimeException( 'Connection refused on port 8069', 0 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_could_not_resolve_is_transient(): void {
		$e = new \RuntimeException( 'Could not resolve host: erp.example.com', 0 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	// ─── Default: unknown → transient ──────────────────────

	public function test_unknown_error_defaults_to_transient(): void {
		$e = new \RuntimeException( 'Something unexpected happened', 0 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	public function test_unknown_4xx_defaults_to_transient(): void {
		$e = new \RuntimeException( 'Some client error', 400 );
		$this->assertSame( Error_Type::Transient, ErrorClassificationTestModule::test_classify( $e ) );
	}

	// ─── Case insensitivity ────────────────────────────────

	public function test_classification_is_case_insensitive(): void {
		$e = new \RuntimeException( 'ACCESSERROR: NO RIGHTS', 200 );
		$this->assertSame( Error_Type::Permanent, ErrorClassificationTestModule::test_classify( $e ) );
	}
}
