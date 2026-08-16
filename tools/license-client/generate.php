<?php
/**
 * Canonical license-client generator.
 *
 * StoreCanvas and OrderBay ship the same license client (PHP class + admin JS),
 * differing only by plugin prefix, display name, and one description string.
 * The single source of truth lives here:
 *
 *   tools/license-client/class-license.php.tpl  — PHP class template
 *                                                 ({{PREFIX}}, {{prefix}}, {{name}},
 *                                                  {{slug}}, {{description}} tokens)
 *   tools/license-client/license.js             — admin JS, shipped verbatim
 *
 * Running this script rewrites the per-plugin copies in place. Never hand-edit
 * the generated files — edit the template and re-run:
 *
 *   php tools/license-client/generate.php
 *
 * CI enforces this via tools/release/check-license-sync.php, which requires
 * this file and byte-compares the shipped copies against fresh output.
 *
 * Dependency-free CLI (PHP 7.4+).
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

/**
 * Build the generated file set: repo-relative target path => exact contents.
 *
 * @param string $root Absolute path to the repo root.
 * @return array<string,string>
 */
function toc_license_client_files( $root ) {
	$tpl_path = $root . '/tools/license-client/class-license.php.tpl';
	$js_path  = $root . '/tools/license-client/license.js';

	$tpl = file_get_contents( $tpl_path );
	$js  = file_get_contents( $js_path );
	if ( false === $tpl || false === $js ) {
		fwrite( STDERR, "Cannot read canonical sources under tools/license-client/.\n" );
		exit( 1 );
	}

	$plugins = array(
		array(
			'slug'        => 'storecanvas',
			'name'        => 'StoreCanvas',
			'prefix'      => 'sc',
			'description' => 'Self-hosted WooCommerce product designer.',
		),
		array(
			'slug'        => 'orderbay',
			'name'        => 'OrderBay',
			'prefix'      => 'ob',
			'description' => 'Self-hosted WooCommerce ops desk.',
		),
	);

	$files = array();
	foreach ( $plugins as $p ) {
		$php = str_replace(
			array( '{{PREFIX}}', '{{prefix}}', '{{name}}', '{{slug}}', '{{description}}' ),
			array( strtoupper( $p['prefix'] ), $p['prefix'], $p['name'], $p['slug'], $p['description'] ),
			$tpl
		);

		$files[ $p['slug'] . '/includes/class-' . $p['prefix'] . '-license.php' ] = $php;
		$files[ $p['slug'] . '/assets/license.js' ]                              = $js;
	}

	return $files;
}

// Write the generated copies when invoked directly (not when required by the CI check).
if ( realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	$root = dirname( __DIR__, 2 );
	foreach ( toc_license_client_files( $root ) as $rel => $contents ) {
		if ( false === file_put_contents( $root . '/' . $rel, $contents ) ) {
			fwrite( STDERR, "FAIL: could not write {$rel}\n" );
			exit( 1 );
		}
		echo "wrote {$rel}\n";
	}
	echo "License client regenerated. Verify with: php tools/release/check-license-sync.php\n";
}
