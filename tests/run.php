<?php
/**
 * Integration test runner for the Krynox Captcha WordPress plugin.
 *
 * Plain-assert script (no PHPUnit): boots the WP function shim
 * (tests/bootstrap.php — NOT a WordPress install), spawns a REAL local HTTP
 * mock of the Krynox data plane (php -S + tests/router.php), includes the
 * plugin, and drives its hooks end-to-end over the wire.
 *
 * Usage: php tests/run.php
 *
 * @package KrynoxCaptcha\Tests
 */

declare(strict_types=1);

error_reporting( E_ALL );

/* ------------------------------------------------------- tiny assert kit */

$GLOBALS['t'] = array(
	'pass' => 0,
	'fail' => 0,
);

function check( $cond, string $label ): void {
	if ( $cond ) {
		++$GLOBALS['t']['pass'];
		echo "  ok  $label\n";
	} else {
		++$GLOBALS['t']['fail'];
		echo "FAIL  $label\n";
	}
}

function check_same( $expected, $actual, string $label ): void {
	if ( $expected === $actual ) {
		++$GLOBALS['t']['pass'];
		echo "  ok  $label\n";
	} else {
		++$GLOBALS['t']['fail'];
		echo "FAIL  $label\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n";
	}
}

/* ------------------------------------------------------- mock data plane */

$state_dir = sys_get_temp_dir() . '/krynox-wp-mock-' . bin2hex( random_bytes( 6 ) );
mkdir( $state_dir, 0777, true );

$sock = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
if ( false === $sock ) {
	fwrite( STDERR, "cannot pick a free port: $errstr\n" );
	exit( 1 );
}
$port = (int) substr( (string) strrchr( stream_socket_get_name( $sock, false ), ':' ), 1 );
fclose( $sock );

$plane_url = "http://127.0.0.1:$port";
$proc      = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router.php' ),
	array(
		1 => array( 'file', '/dev/null', 'w' ),
		2 => array( 'file', $state_dir . '/server.log', 'w' ),
	),
	$pipes,
	null,
	array(
		'KRYNOX_MOCK_STATE' => $state_dir,
		'PATH'              => (string) getenv( 'PATH' ),
	)
);
if ( ! is_resource( $proc ) ) {
	fwrite( STDERR, "failed to spawn php -S mock plane\n" );
	exit( 1 );
}

register_shutdown_function(
	static function () use ( $proc, $state_dir ) {
		if ( is_resource( $proc ) ) {
			proc_terminate( $proc );
			proc_close( $proc );
		}
		array_map( 'unlink', glob( $state_dir . '/*' ) ?: array() );
		@rmdir( $state_dir );
	}
);

$deadline = microtime( true ) + 10.0;
$ready    = false;
while ( microtime( true ) < $deadline ) {
	$conn = @fsockopen( '127.0.0.1', $port, $e, $m, 0.25 );
	if ( false !== $conn ) {
		fclose( $conn );
		$ready = true;
		break;
	}
	usleep( 50000 );
}
if ( ! $ready ) {
	fwrite( STDERR, "mock plane did not become ready\n" );
	exit( 1 );
}

/** @return array<int,array{method:string,path:string,body:?array}> */
function plane_requests( string $state_dir ): array {
	$log = @file_get_contents( $state_dir . '/requests.log' );
	if ( false === $log || '' === $log ) {
		return array();
	}
	return array_map(
		static fn ( string $line ): array => json_decode( $line, true ),
		array_values( array_filter( explode( "\n", trim( $log ) ) ) )
	);
}

function plane_reset( string $state_dir ): void {
	@unlink( $state_dir . '/requests.log' );
	@unlink( $state_dir . '/queue.json' );
}

/** @param array<int,array{status:int,body:mixed}> $responses */
function plane_queue( string $state_dir, array $responses ): void {
	file_put_contents( $state_dir . '/queue.json', json_encode( $responses ), LOCK_EX );
}

/* -------------------------------------- boot the shim + load the plugin */

require __DIR__ . '/bootstrap.php';

// The plugin reads its options in the constructor (singleton instantiated at
// include time), so seed them BEFORE loading the plugin file.
update_option(
	'krynox_captcha_options',
	array(
		'site_key'   => 'kcpt_test_site',
		'secret_key' => 'kcps_test_secret',
		'api_host'   => $plane_url,
		'cdn_host'   => 'https://cdn.example.test',
	)
);

require dirname( __DIR__ ) . '/krynox-captcha.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

/* ------------------------------------------------------------- the tests */

echo "1. authenticate happy path (real mock plane, wp-submit present, valid token)\n";
plane_reset( $state_dir );
$_POST = array(
	'wp-submit'      => 'Log In',
	'krynox-captcha' => 'valid-token',
	'krynox-hp'      => '',
);
$user   = new stdClass();
$result = apply_filters( 'authenticate', $user, 'admin', 'hunter2' );
check( $result === $user, 'valid token: the WP_User object passes through untouched' );
$reqs = plane_requests( $state_dir );
check_same( 1, count( $reqs ), 'exactly one /siteverify hit' );
check_same( '/siteverify', $reqs[0]['path'] ?? null, 'hit path is /siteverify' );
$body = $reqs[0]['body'] ?? array();
check_same( 'kcps_test_secret', $body['secret'] ?? null, 'payload carries the secret key' );
check_same( 'valid-token', $body['response'] ?? null, 'payload carries the token as response' );
check_same( '203.0.113.9', $body['remoteip'] ?? null, 'payload carries REMOTE_ADDR as remoteip' );
check_same( '', $body['honeypot'] ?? null, 'payload carries the krynox-hp honeypot field' );
check( is_string( $body['idempotency_key'] ?? null ) && '' !== $body['idempotency_key'], 'payload carries an idempotency key' );

echo "\n2. authenticate with an invalid token -> WP_Error\n";
plane_reset( $state_dir );
$_POST = array(
	'wp-submit'      => 'Log In',
	'krynox-captcha' => 'bad-token',
	'krynox-hp'      => '',
);
$result = apply_filters( 'authenticate', new stdClass(), 'admin', 'hunter2' );
check( is_wp_error( $result ), 'invalid token: authenticate returns a WP_Error' );
check_same( 'krynox_failed', $result->get_error_code(), 'error code is krynox_failed' );
check_same(
	'<strong>Error:</strong> CAPTCHA verification failed. Please try again.',
	$result->get_error_message(),
	'error message matches the plugin string'
);
check_same( 1, count( plane_requests( $state_dir ) ), 'a 4xx-style invalid token is not retried (one hit)' );

echo "\n3. authenticate WITHOUT wp-submit skips verification (XML-RPC / app-password safety)\n";
plane_reset( $state_dir );
$_POST = array(
	'krynox-captcha' => 'valid-token',
	'krynox-hp'      => '',
);
$user   = new stdClass();
$result = apply_filters( 'authenticate', $user, 'admin', 'hunter2' );
check( $result === $user, 'without wp-submit the auth result passes through' );
check_same( 0, count( plane_requests( $state_dir ) ), 'the data plane was never hit' );

echo "\n4a. registration_errors adds an error on a bad token\n";
plane_reset( $state_dir );
$_POST = array(
	'krynox-captcha' => 'bad-token',
	'krynox-hp'      => '',
);
$errors = new WP_Error();
$out    = apply_filters( 'registration_errors', $errors );
check( $out === $errors, 'the WP_Error bag is returned' );
check_same( 'krynox_failed', $out->get_error_code(), 'krynox_failed added to registration errors' );
check_same(
	'<strong>Error:</strong> CAPTCHA verification failed. Please try again.',
	$out->get_error_message( 'krynox_failed' ),
	'registration error message matches'
);

echo "\n4b. preprocess_comment wp_die(403) on a bad token\n";
plane_reset( $state_dir );
$_POST = array(
	'krynox-captcha' => 'bad-token',
	'krynox-hp'      => '',
);
$died = null;
try {
	apply_filters( 'preprocess_comment', array( 'comment_content' => 'spam?' ) );
} catch ( Krynox_Test_WP_Die_Exception $e ) {
	$died = $e;
}
check( null !== $died, 'preprocess_comment calls wp_die on a bad token' );
check_same( 403, $died ? ( $died->wp_die_args['response'] ?? null ) : null, 'wp_die response code is 403' );
check_same( 'CAPTCHA verification failed. Please go back and try again.', $died ? $died->wp_die_message : null, 'wp_die message matches' );

echo "\n4c. lostpassword_post adds an error on a bad token\n";
plane_reset( $state_dir );
$_POST = array(
	'krynox-captcha' => 'bad-token',
	'krynox-hp'      => '',
);
$errors = new WP_Error();
do_action( 'lostpassword_post', $errors );
check_same( 'krynox_failed', $errors->get_error_code(), 'krynox_failed added to lost-password errors' );

echo "\n5. retry loop: 500 then 200 -> success, exactly 2 plane hits, same idempotency key\n";
plane_reset( $state_dir );
plane_queue(
	$state_dir,
	array(
		array(
			'status' => 500,
			'body'   => array( 'error' => 'boom' ),
		),
		array(
			'status' => 200,
			'body'   => array( 'success' => true ),
		),
	)
);
$_POST = array(
	'wp-submit'      => 'Log In',
	'krynox-captcha' => 'valid-token',
	'krynox-hp'      => '',
);
$user   = new stdClass();
$result = apply_filters( 'authenticate', $user, 'admin', 'hunter2' );
check( $result === $user, '500-then-200: verification ultimately succeeds' );
$reqs = plane_requests( $state_dir );
check_same( 2, count( $reqs ), 'exactly 2 plane hits (the 500 and the retried 200)' );
check(
	isset( $reqs[0]['body']['idempotency_key'], $reqs[1]['body']['idempotency_key'] )
		&& $reqs[0]['body']['idempotency_key'] === $reqs[1]['body']['idempotency_key'],
	'the retry replays the same idempotency key'
);

echo "\n6. krynox_captcha_verified filter receives (success, data)\n";
plane_reset( $state_dir );
$captured = null;
add_filter(
	'krynox_captcha_verified',
	function ( $success, $data ) use ( &$captured ) {
		$captured = array( $success, $data );
		return $success;
	},
	10,
	2
);
$_POST = array(
	'wp-submit'      => 'Log In',
	'krynox-captcha' => 'valid-token',
	'krynox-hp'      => '',
);
apply_filters( 'authenticate', new stdClass(), 'admin', 'hunter2' );
check( null !== $captured, 'the filter ran' );
check_same( true, $captured[0] ?? null, 'first arg is the boolean success' );
check( is_array( $captured[1] ?? null ) && true === ( $captured[1]['success'] ?? null ), 'second arg is the full /siteverify response array' );

/* ---------------------------------------------------------------- summary */

echo "\n----------------------------------------\n";
printf( "%d passed, %d failed\n", $GLOBALS['t']['pass'], $GLOBALS['t']['fail'] );
if ( $GLOBALS['t']['fail'] > 0 ) {
	exit( 1 );
}
echo "OK\n";
