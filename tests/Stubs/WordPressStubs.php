<?php

/**
 * @file
 * Minimal WordPress function/constant stubs needed by the plugin classes
 * we test in isolation. Tests can override per-call behavior by setting
 * entries in $GLOBALS['autotix_wp_stub'].
 *
 * Only stubs the surface the test subjects actually call. Adding a new
 * stub? Keep it minimal — anything more elaborate belongs in a real
 * WP integration test.
 */

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Default stub state. Tests reset this between cases.
$GLOBALS['autotix_wp_stub'] = [
	'transients' => [],
	'options' => [],           // get_option / update_option / add_option store
	'plugins' => [],           // what get_plugins() returns
	'http_response' => null,   // null = error; ['code'=>200,'body'=>'…','headers'=>[]] = ok
	'http_error_message' => null,
	'last_request' => null,    // captured args from wp_remote_post
	'error_log' => [],
];

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['autotix_wp_stub']['last_request'] = compact( 'url', 'args' );
		if ( $GLOBALS['autotix_wp_stub']['http_error_message'] !== null ) {
			return new \WP_Error( 'http_request_failed', $GLOBALS['autotix_wp_stub']['http_error_message'] );
		}
		return $GLOBALS['autotix_wp_stub']['http_response'] ?? [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body' => '',
			'headers' => [],
		];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( $response ) {
		return $response['headers'] ?? [];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['autotix_wp_stub']['transients'][ $key ] = [
			'value' => $value,
			'ttl' => $ttl,
		];
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['autotix_wp_stub']['transients'][ $key ]['value'] ?? false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['autotix_wp_stub']['options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['autotix_wp_stub']['options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $deprecated = '', $autoload = null ) {
		$GLOBALS['autotix_wp_stub']['options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'https://demo.example.test' . $path;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '6.5' : '';
	}
}

// Defining this makes Env_Reporter::collect_plugins() skip the
// require ABSPATH . 'wp-admin/includes/plugin.php' branch.
if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins() {
		return $GLOBALS['autotix_wp_stub']['plugins'] ?? [];
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// Minimal WP_Error to satisfy is_wp_error() + get_error_message().
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}
