<?php
/**
 * CLI: create a license key and optionally register a release.
 *
 * Usage:
 *   php bin/create-key.php [--email=a@b.c] [--sites=1] [--expires=2027-01-01|lifetime] [--notes="..."]
 *   php bin/add-release.php --version=1.8.0 --file=twilio-order-communicator-1.8.0.zip [--changelog="..."]
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$config_file = $root . '/config.php';
if ( ! is_file( $config_file ) ) {
	fwrite( STDERR, "Missing config.php — copy config.example.php to config.php first.\n" );
	exit( 1 );
}

$config = require $config_file;
require_once $root . '/src/Helpers.php';
require_once $root . '/src/Database.php';

$args = array();
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--([^=]+)=(.*)$/', $arg, $m ) ) {
		$args[ $m[1] ] = $m[2];
	} elseif ( preg_match( '/^--([^=]+)$/', $arg, $m ) ) {
		$args[ $m[1] ] = true;
	}
}

$db = new TOC_License_DB( $config['db_path'] );

$email   = isset( $args['email'] ) ? (string) $args['email'] : null;
$sites   = isset( $args['sites'] ) ? max( 1, (int) $args['sites'] ) : 1;
$notes   = isset( $args['notes'] ) ? (string) $args['notes'] : null;
$expires = null;
if ( isset( $args['expires'] ) && $args['expires'] !== 'lifetime' && $args['expires'] !== '' ) {
	$ts = strtotime( (string) $args['expires'] );
	if ( ! $ts ) {
		fwrite( STDERR, "Invalid --expires date.\n" );
		exit( 1 );
	}
	$expires = gmdate( 'c', $ts );
}

$key = TOC_License_Helpers::generate_key();
// Extremely unlikely collision loop.
while ( $db->get_license( $key ) ) {
	$key = TOC_License_Helpers::generate_key();
}

$stmt = $db->pdo()->prepare(
	'INSERT INTO licenses (license_key, status, expires_at, max_sites, customer_email, notes, created_at)
	 VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute(
	array(
		$key,
		'active',
		$expires,
		$sites,
		$email,
		$notes,
		gmdate( 'c' ),
	)
);

echo "Created license key:\n";
echo "  KEY:      {$key}\n";
echo '  SITES:    ' . $sites . "\n";
echo '  EXPIRES:  ' . ( $expires ?: 'lifetime' ) . "\n";
echo '  EMAIL:    ' . ( $email ?: '(none)' ) . "\n";
echo "Store this key securely; it will not be shown again by the server.\n";
