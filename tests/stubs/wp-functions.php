<?php
/**
 * WordPress function stubs for PHPUnit tests.
 *
 * Requires wp-classes.php to be loaded first (for WP4Odoo_Test_Json* classes).
 *
 * @package WP4Odoo\Tests
 */

// ─── Core utility functions ─────────────────────────────

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

// ─── Options API ────────────────────────────────────────

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['_wp_options'] ) ? $GLOBALS['_wp_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['_wp_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['_wp_options'][ $option ] );
		return true;
	}
}

// ─── Transients ─────────────────────────────────────────

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return $GLOBALS['_wp_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$GLOBALS['_wp_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['_wp_transients'][ $transient ] );
		return true;
	}
}

// ─── Object Cache ───────────────────────────────────────

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$store = $GLOBALS['_wp_cache'][ $group ] ?? [];
		if ( isset( $store[ $key ] ) ) {
			$found = true;
			return $store[ $key ];
		}
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		$GLOBALS['_wp_cache'][ $group ][ $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['_wp_cache'][ $group ][ $key ] );
		return true;
	}
}

// ─── Hooks ──────────────────────────────────────────────

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( isset( $GLOBALS['_wp_filters'][ $tag ] ) && is_callable( $GLOBALS['_wp_filters'][ $tag ] ) ) {
			return call_user_func( $GLOBALS['_wp_filters'][ $tag ], $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
}

// ─── Sanitization ───────────────────────────────────────

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '-', trim( (string) $title ) ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ?: '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

// ─── Escaping ───────────────────────────────────────────

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $_context = 'display' ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}

// ─── Post revision / autosave ───────────────────────────

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ) {
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post ) {
		return false;
	}
}

// ─── Formatting ─────────────────────────────────────────

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text, $br = true ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		return '<p>' . $text . "</p>\n";
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, $decimals, '.', ',' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

// ─── Date/time ──────────────────────────────────────────

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = false ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new \DateTimeZone( 'UTC' );
	}
}

// ─── User functions ─────────────────────────────────────

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['_wp_users'][ $user_id ] ?? false;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		if ( ! isset( $GLOBALS['_wp_users'] ) ) {
			return false;
		}
		foreach ( $GLOBALS['_wp_users'] as $user ) {
			if ( 'email' === $field && $user->user_email === $value ) {
				return $user;
			}
			if ( 'login' === $field && $user->user_login === $value ) {
				return $user;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			// No key: return all meta as ['key' => ['value'], ...] (WP format).
			$all = $GLOBALS['_wp_user_meta'][ $user_id ] ?? [];
			return array_map( function ( $v ) { return (array) $v; }, $all );
		}
		return $GLOBALS['_wp_user_meta'][ $user_id ][ $key ] ?? ( $single ? '' : [] );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
		$GLOBALS['_wp_user_meta'][ $user_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'wp_insert_user' ) ) {
	function wp_insert_user( $userdata ) {
		static $next_id = 100;
		return ++$next_id;
	}
}

if ( ! function_exists( 'wp_update_user' ) ) {
	function wp_update_user( $userdata ) {
		return $userdata['ID'] ?? 0;
	}
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	function wp_delete_user( $id, $reassign = null ) {
		return true;
	}
}

if ( ! function_exists( 'username_exists' ) ) {
	function username_exists( $username ) {
		return false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return $GLOBALS['_wp_current_user_can'] ?? true;
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() {
		return new class {
			public array $roles = [
				'administrator' => [ 'name' => 'Administrator' ],
				'editor'        => [ 'name' => 'Editor' ],
				'subscriber'    => [ 'name' => 'Subscriber' ],
			];
		};
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special = false ) {
		return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', (int) ceil( $length / 36 ) ), 0, $length );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return $value;
	}
}

// ─── UUID ───────────────────────────────────────────────

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	function wp_convert_hr_to_bytes( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$bytes = (int) $value;

		if ( str_contains( $value, 'g' ) ) {
			$bytes *= GB_IN_BYTES;
		} elseif ( str_contains( $value, 'm' ) ) {
			$bytes *= MB_IN_BYTES;
		} elseif ( str_contains( $value, 'k' ) ) {
			$bytes *= KB_IN_BYTES;
		}

		return min( $bytes, PHP_INT_MAX );
	}
}

// ─── Email ──────────────────────────────────────────────

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		$GLOBALS['_wp_mail_calls'][] = [
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
		];
		return true;
	}
}

// ─── Post functions ─────────────────────────────────────

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = null ) {
		return $GLOBALS['_wp_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id = null ) {
		$post = $GLOBALS['_wp_posts'][ $post_id ] ?? null;
		return $post ? $post->post_type : false;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id = null ) {
		$post = $GLOBALS['_wp_posts'][ $post_id ] ?? null;
		return $post ? ( $post->post_status ?? false ) : false;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args, $wp_error = false ) {
		static $next_id = 500;
		return ++$next_id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		return $args['ID'] ?? 0;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $post_id, $force_delete = false ) {
		return (object) [ 'ID' => $post_id ];
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id, $unfiltered = false ) {
		return false;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = [] ) {
		$results   = [];
		$post_type = $args['post_type'] ?? 'post';
		$meta_key  = $args['meta_key'] ?? '';
		$meta_val  = $args['meta_value'] ?? '';
		$fields    = $args['fields'] ?? '';

		foreach ( $GLOBALS['_wp_posts'] ?? [] as $post ) {
			if ( $post->post_type !== $post_type ) {
				continue;
			}
			if ( $meta_key && $meta_val ) {
				$stored = $GLOBALS['_wp_post_meta'][ $post->ID ][ $meta_key ] ?? '';
				if ( (string) $stored !== (string) $meta_val ) {
					continue;
				}
			}
			$results[] = 'ids' === $fields ? $post->ID : $post;
		}

		$limit = (int) ( $args['posts_per_page'] ?? -1 );
		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}

		return $results;
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( $post_type, $args = [] ) {}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}

// ─── Post meta / media ─────────────────────────────────

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			// Return all meta for this post (WP format: key => [values]).
			return $GLOBALS['_wp_post_meta'][ $post_id ] ?? [];
		}
		if ( isset( $GLOBALS['_wp_post_meta'][ $post_id ][ $key ] ) ) {
			return $single ? $GLOBALS['_wp_post_meta'][ $post_id ][ $key ] : [ $GLOBALS['_wp_post_meta'][ $post_id ][ $key ] ];
		}
		return $single ? '' : [];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( ! isset( $GLOBALS['_wp_post_meta'][ $post_id ] ) ) {
			$GLOBALS['_wp_post_meta'][ $post_id ] = [];
		}
		$GLOBALS['_wp_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
		unset( $GLOBALS['_wp_post_meta'][ $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		return 0;
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( $post, $thumbnail_id ) {
		return true;
	}
}

if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( $post ) {
		return true;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
		return [
			'path'    => sys_get_temp_dir(),
			'url'     => 'http://example.com/wp-content/uploads',
			'subdir'  => '',
			'basedir' => sys_get_temp_dir(),
			'baseurl' => 'http://example.com/wp-content/uploads',
			'error'   => false,
		];
	}
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	function wp_insert_attachment( $args, $file = false, $parent_post_id = 0 ) {
		static $id = 1000;
		return ++$id;
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( $attachment_id, $file ) {
		return [];
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( $attachment_id, $data ) {
		return true;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		return [ 1 ];
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $term, $taxonomy, $args = [] ) {
		static $id = 5000;
		$id++;
		return [ 'term_id' => $id, 'term_taxonomy_id' => $id ];
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $term, $taxonomy = '', $parent = 0 ) {
		return $GLOBALS['_wp_term_exists'][ $taxonomy ][ $term ] ?? null;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '', $output = OBJECT, $filter = 'raw' ) {
		return $GLOBALS['_wp_terms_by'][ $taxonomy ][ $field ][ $value ] ?? false;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term, $taxonomy = '', $output = OBJECT, $filter = 'raw' ) {
		return $GLOBALS['_wp_terms'][ $term ] ?? false;
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $args = [] ) {
		$GLOBALS['_wp_updated_terms'][ $term_id ] = $args;
		return [ 'term_id' => $term_id, 'term_taxonomy_id' => $term_id ];
	}
}

// ─── Scripts / styles ───────────────────────────────────

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( ...$args ) {}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ) {}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( ...$args ) {}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'test-nonce';
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		return 'Test Page';
	}
}

// ─── Admin / REST ───────────────────────────────────────

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = [] ) {}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [], $override = false ) {
		return true;
	}
}

// ─── AJAX responses (throw for test capture) ────────────

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = 200 ) {
		throw new \WP4Odoo_Test_JsonSuccess( $data );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = 200 ) {
		throw new \WP4Odoo_Test_JsonError( $data, $status_code );
	}
}

// ─── HTTP API ───────────────────────────────────────────

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		if ( ! empty( $GLOBALS['_wp_remote_responses'] ) ) {
			return array_shift( $GLOBALS['_wp_remote_responses'] );
		}
		return $GLOBALS['_wp_remote_response'] ?? new \WP_Error( 'http_error', 'Stub: no response configured.' );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		// Support sequential response queue for multi-call tests.
		if ( ! empty( $GLOBALS['_wp_remote_responses'] ) ) {
			return array_shift( $GLOBALS['_wp_remote_responses'] );
		}
		return $GLOBALS['_wp_remote_response'] ?? new \WP_Error( 'http_error', 'Stub: no response configured.' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 200;
	}
}

// ─── WP-Cron ────────────────────────────────────────────

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = [] ) {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [], $wp_error = false ) {
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = [] ) {
		return 0;
	}
}
