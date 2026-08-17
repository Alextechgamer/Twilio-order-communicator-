<?php
/**
 * CLI: register a plugin release package for licensed updates.
 *
 * Usage:
 *   php bin/add-release.php --version=1.20.0 --file=/path/to/plugin.zip [--slug=orderring] [--changelog="..."] [--php=7.4] [--wp=6.0]
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
	}
}

$version = isset( $args['version'] ) ? (string) $args['version'] : '';
$file    = isset( $args['file'] ) ? (string) $args['file'] : '';
if ( $version === '' || $file === '' ) {
	fwrite( STDERR, "Required: --version=x.y.z --file=/path/to.zip\n" );
	exit( 1 );
}
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "File not found: {$file}\n" );
	exit( 1 );
}

$releases_dir = rtrim( (string) $config['releases_dir'], '/' );
if ( ! is_dir( $releases_dir ) ) {
	mkdir( $releases_dir, 0755, true );
}

$db   = new TOC_License_DB( $config['db_path'] );
$slug = isset( $args['slug'] ) ? (string) $args['slug'] : (string) ( $config['item_slug'] ?? 'orderring' );
$slug = strtolower( preg_replace( '/[^a-zA-Z0-9._-]/', '', $slug ) );
if ( $slug === '' ) {
	fwrite( STDERR, "Invalid --slug (use letters, numbers, dot, underscore, hyphen).\n" );
	exit( 1 );
}

$basename = $slug . '-' . $version . '.zip';
$dest     = $releases_dir . '/' . $basename;
if ( ! copy( $file, $dest ) ) {
	fwrite( STDERR, "Failed to copy package to {$dest}\n" );
	exit( 1 );
}

$stmt = $db->pdo()->prepare(
	'INSERT INTO releases (slug, version, required_php, required_wp, package_path, changelog, released_at)
	 VALUES (?, ?, ?, ?, ?, ?, ?)
	 ON CONFLICT(slug, version) DO UPDATE SET
	   required_php=excluded.required_php,
	   required_wp=excluded.required_wp,
	   package_path=excluded.package_path,
	   changelog=excluded.changelog,
	   released_at=excluded.released_at'
);
$stmt->execute(
	array(
		$slug,
		$version,
		isset( $args['php'] ) ? (string) $args['php'] : '7.4',
		isset( $args['wp'] ) ? (string) $args['wp'] : '6.0',
		$basename,
		isset( $args['changelog'] ) ? (string) $args['changelog'] : '',
		gmdate( 'c' ),
	)
);

echo "Release registered:\n";
echo "  SLUG:    {$slug}\n";
echo "  VERSION: {$version}\n";
echo "  FILE:    {$dest}\n";
