<?php
/**
 * TOC License Server front controller.
 *
 * Point your vhost document root at this `public/` directory,
 * or rewrite all requests here.
 */

declare(strict_types=1);

header( 'X-Content-Type-Options: nosniff' );

$config_file = dirname( __DIR__ ) . '/config.php';
if ( ! is_file( $config_file ) ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'success' => false, 'error' => 'Missing config.php — copy config.example.php.' ) );
	exit;
}

$config = require $config_file;

require_once dirname( __DIR__ ) . '/src/Helpers.php';
require_once dirname( __DIR__ ) . '/src/Database.php';
require_once dirname( __DIR__ ) . '/src/Api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url( $uri, PHP_URL_PATH );
$path   = is_string( $path ) ? $path : '/';

// Support both /v1/... and /index.php/v1/...
if ( preg_match( '#/index\.php(/.*)?$#', $path, $m ) ) {
	$path = isset( $m[1] ) && $m[1] !== '' ? $m[1] : '/';
}

try {
	$db  = new TOC_License_DB( $config['db_path'] );
	$api = new TOC_License_API( $db, $config );
	$api->handle( $method, $path );
} catch ( Throwable $e ) {
	// Log server-side; never leak internal detail (paths, config, stack) to clients.
	error_log( 'TOC License Server error: ' . $e->getMessage() );
	http_response_code( 500 );
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'success' => false, 'error' => 'Server error' ) );
	exit;
}
