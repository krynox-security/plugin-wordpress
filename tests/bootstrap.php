<?php
/**
 * Minimal, honest WordPress function shim for the integration tests.
 *
 * This is NOT a WordPress install. It defines only the functions/classes the
 * plugin actually calls, with faithful minimal semantics:
 *   - wp_remote_post performs a REAL HTTP request (PHP streams), so the local
 *     mock data plane is genuinely hit over the wire;
 *   - get_option/update_option are an in-memory store;
 *   - add_action/add_filter/apply_filters/do_action are a simple registry;
 *   - wp_die throws a catchable exception carrying its args.
 *
 * @package KrynoxCaptcha\Tests
 */

define( 'ABSPATH', '/tmp/not-a-real-wordpress/' );

$GLOBALS['krynox_test'] = array(
	'options'  => array(),
	'hooks'    => array(),
	'enqueued' => array(),
);

/* ------------------------------------------------------------------ hooks */

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['krynox_test']['hooks'][ $tag ][] = array(
		'cb'   => $callback,
		'prio' => $priority,
		'args' => $accepted_args,
	);
	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted_args );
}

function apply_filters( $tag, $value, ...$extra ) {
	$hooks = $GLOBALS['krynox_test']['hooks'][ $tag ] ?? array();
	usort( $hooks, static fn ( $a, $b ) => $a['prio'] <=> $b['prio'] );
	foreach ( $hooks as $hook ) {
		$all   = array_merge( array( $value ), $extra );
		$value = call_user_func_array( $hook['cb'], array_slice( $all, 0, max( 1, (int) $hook['args'] ) ) );
	}
	return $value;
}

function do_action( $tag, ...$args ) {
	$hooks = $GLOBALS['krynox_test']['hooks'][ $tag ] ?? array();
	usort( $hooks, static fn ( $a, $b ) => $a['prio'] <=> $b['prio'] );
	foreach ( $hooks as $hook ) {
		call_user_func_array( $hook['cb'], array_slice( $args, 0, max( 1, (int) $hook['args'] ) ) );
	}
}

/* ---------------------------------------------------------------- options */

function get_option( $name, $default = false ) {
	return $GLOBALS['krynox_test']['options'][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	$GLOBALS['krynox_test']['options'][ $name ] = $value;
	return true;
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
}

/* ------------------------------------------------------------------- HTTP */

/**
 * Real HTTP POST via PHP streams — the mock data plane is genuinely hit.
 */
function wp_remote_post( $url, $args = array() ) {
	$headers = '';
	foreach ( (array) ( $args['headers'] ?? array() ) as $k => $v ) {
		$headers .= $k . ': ' . $v . "\r\n";
	}
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'method'        => 'POST',
				'header'        => $headers,
				'content'       => (string) ( $args['body'] ?? '' ),
				'timeout'       => (float) ( $args['timeout'] ?? 5 ),
				'ignore_errors' => true, // return the body even on 4xx/5xx, like WP_Http.
			),
		)
	);
	$body = @file_get_contents( $url, false, $ctx );
	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'A valid URL was not provided or the connection failed.' );
	}
	$code = 0;
	foreach ( $http_response_header ?? array() as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
			$code = (int) $m[1];
		}
	}
	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	if ( is_wp_error( $response ) || ! isset( $response['response']['code'] ) ) {
		return '';
	}
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	if ( is_wp_error( $response ) || ! isset( $response['body'] ) ) {
		return '';
	}
	return $response['body'];
}

/* ---------------------------------------------------------------- WP_Error */

class WP_Error {
	public $errors = array();

	public function __construct( $code = '', $message = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message );
		}
	}

	public function add( $code, $message ) {
		$this->errors[ $code ][] = $message;
	}

	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	public function get_error_code() {
		$codes = $this->get_error_codes();
		return $codes[0] ?? '';
	}

	public function get_error_message( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return $this->errors[ $code ][0] ?? '';
	}

	public function has_errors() {
		return array() !== $this->errors;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* ------------------------------------------------------------------ wp_die */

class Krynox_Test_WP_Die_Exception extends \Exception {
	public $wp_die_message;
	public $wp_die_title;
	public $wp_die_args;

	public function __construct( $message, $title, $args ) {
		parent::__construct( is_string( $message ) ? $message : 'wp_die' );
		$this->wp_die_message = $message;
		$this->wp_die_title   = $title;
		$this->wp_die_args    = $args;
	}
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Krynox_Test_WP_Die_Exception( $message, $title, $args );
}

/* ------------------------------------------------- escaping / sanitizing */

function esc_url( $url ) {
	return $url;
}

function esc_url_raw( $url ) {
	return $url;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}

function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

/* ------------------------------------------------------- misc plugin API */

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['krynox_test']['enqueued'][ $handle ] = array(
		'src'       => $src,
		'deps'      => $deps,
		'ver'       => $ver,
		'in_footer' => $in_footer,
	);
}

/* Admin-screen helpers referenced by the plugin's admin callbacks (which the
 * tests may fire but never render): harmless no-ops. */

function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	return $menu_slug;
}

function register_setting( $group, $name, $args = array() ) {}

function settings_fields( $group ) {}

function submit_button() {}

function checked( $checked, $current = true ) {}

function admin_url( $path = '' ) {
	return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
}

// Deliberately NOT defined: wp_generate_uuid4() — the plugin guards it with
// function_exists() and falls back to bin2hex(random_bytes(16)), which is the
// code path this shim exercises.
