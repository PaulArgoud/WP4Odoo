<?php
/**
 * WordPress core class stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $error_data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code       = $code;
			$this->message    = $message;
			$this->error_data = is_array( $data ) ? $data : [];
		}

		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->error_data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];
		private array $headers = [];
		private array $json_params = [];
		private string $body = '';

		public function __construct( string $method = 'GET', string $route = '' ) {}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_body( string $body ): void {
			$this->body        = $body;
			$this->json_params = json_decode( $body, true ) ?: [];
		}

		public function get_body(): string {
			return $this->body;
		}

		public function set_json_params( array $params ): void {
			$this->json_params = $params;
		}

		public function get_json_params(): array {
			return $this->json_params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private mixed $data;
		private int $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value, bool $replace = true ): void {}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;
		public string $user_login = '';
		public string $user_email = '';
		public string $display_name = '';
		public string $first_name = '';
		public string $last_name = '';
		public string $description = '';
		public string $user_url = '';
		/** @var string[] */
		public array $roles = [];

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

// ─── WP-CLI stub ───────────────────────────────────────

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var array<int, array{method: string, args: array}> */
		public static array $calls = [];

		public static function add_command( string $command, string $class ): void {}

		public static function line( string $message = '' ): void {
			self::$calls[] = [ 'method' => 'line', 'args' => [ $message ] ];
		}

		public static function success( string $message ): void {
			self::$calls[] = [ 'method' => 'success', 'args' => [ $message ] ];
		}

		public static function warning( string $message ): void {
			self::$calls[] = [ 'method' => 'warning', 'args' => [ $message ] ];
		}

		public static function error( string $message ): void {
			self::$calls[] = [ 'method' => 'error', 'args' => [ $message ] ];
		}

		public static function confirm( string $message, array $assoc_args = [] ): void {
			self::$calls[] = [ 'method' => 'confirm', 'args' => [ $message ] ];
		}

		public static function reset(): void {
			self::$calls = [];
		}
	}
}

// ─── AJAX response helpers (throw exceptions for test capture) ─

class WP4Odoo_Test_JsonSuccess extends \RuntimeException {
	public mixed $data;
	public function __construct( mixed $data = null ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json_success' );
	}
}

class WP4Odoo_Test_JsonError extends \RuntimeException {
	public mixed $data;
	public int $status_code;
	public function __construct( mixed $data = null, int $status_code = 200 ) {
		$this->data        = $data;
		$this->status_code = $status_code;
		parent::__construct( 'wp_send_json_error' );
	}
}
