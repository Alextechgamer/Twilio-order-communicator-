<?php
/**
 * Print (and optionally write) production secrets for config.php.
 *
 * Usage:
 *   php bin/generate-secrets.php
 *   php bin/generate-secrets.php --write   # patches config.php in place
 *
 * Never commit the output. --write refuses to run if admin_token is already
 * a non-placeholder value.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$write = in_array( '--write', $argv, true );

$admin = bin2hex( random_bytes( 32 ) );
$down  = bin2hex( random_bytes( 32 ) );

echo "admin_token={$admin}\n";
echo "download_secret={$down}\n";

if ( ! $write ) {
	echo "\nCopy those into config.php, or re-run with --write after copying config.example.php.\n";
	exit( 0 );
}

$file = $root . '/config.php';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "Missing config.php — copy config.example.php first.\n" );
	exit( 1 );
}

$src = file_get_contents( $file );
if ( $src === false ) {
	fwrite( STDERR, "Could not read config.php\n" );
	exit( 1 );
}

if ( strpos( $src, 'change-me-to-a-long-random-string' ) === false
	&& preg_match( "/'admin_token'\\s*=>\\s*'([^']+)'/", $src, $m )
	&& $m[1] !== '' ) {
	fwrite( STDERR, "config.php already has a non-placeholder admin_token; refusing to overwrite.\n" );
	exit( 1 );
}

$src = preg_replace( "/('admin_token'\\s*=>\\s*)'[^']*'/", '${1}\'' . $admin . '\'', $src, 1, $c1 );
$src = preg_replace( "/('download_secret'\\s*=>\\s*)'[^']*'/", '${1}\'' . $down . '\'', $src, 1, $c2 );
if ( $c1 !== 1 || $c2 !== 1 ) {
	fwrite( STDERR, "Could not patch admin_token / download_secret in config.php\n" );
	exit( 1 );
}

if ( file_put_contents( $file, $src ) === false ) {
	fwrite( STDERR, "Could not write config.php\n" );
	exit( 1 );
}

echo "Wrote admin_token and download_secret into config.php. Set public_base_url to your HTTPS origin (no trailing slash).\n";
