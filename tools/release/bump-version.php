<?php
/**
 * Bump a plugin's version across all five markers in one command.
 *
 * Updates, atomically per file:
 *   1. Plugin header `Version:` in the main plugin file
 *   2. The `*_VERSION` constant in the main plugin file
 *   3. `Stable tag:` in readme.txt
 *   4. Inserts a changelog stub `= X.Y.Z =` under `== Changelog ==` in readme.txt
 *      (skipped if that heading already exists)
 *   5. The plugin's row in the root README.md version table
 *
 * Then re-runs check-versions.php so a bump can never leave the tree inconsistent.
 *
 * Dependency-free CLI (PHP 7.4+).
 *
 * Usage: php tools/release/bump-version.php <toc|storecanvas|orderbay> <version>
 *   e.g. php tools/release/bump-version.php orderbay 1.8.2
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$root = dirname( __DIR__, 2 );

$plugins = array(
	'toc' => array(
		'dir'          => 'twilio-order-communicator',
		'main'         => 'twilio-order-communicator/twilio-order-communicator.php',
		'constant'     => 'TOC_VERSION',
		'readme_label' => 'OrderRing',
	),
	'storecanvas' => array(
		'dir'          => 'storecanvas',
		'main'         => 'storecanvas/storecanvas.php',
		'constant'     => 'SC_VERSION',
		'readme_label' => 'StoreCanvas',
	),
	'orderbay' => array(
		'dir'          => 'orderbay',
		'main'         => 'orderbay/orderbay.php',
		'constant'     => 'OB_VERSION',
		'readme_label' => 'Orderbay',
	),
);

$key     = isset( $argv[1] ) ? strtolower( trim( $argv[1] ) ) : '';
$version = isset( $argv[2] ) ? trim( $argv[2] ) : '';

// Accept the directory name as an alias for the registry key.
if ( ! isset( $plugins[ $key ] ) ) {
	foreach ( $plugins as $k => $p ) {
		if ( $key === $p['dir'] ) {
			$key = $k;
			break;
		}
	}
}

if ( ! isset( $plugins[ $key ] ) || $version === '' ) {
	fwrite( STDERR, "Usage: php tools/release/bump-version.php <toc|storecanvas|orderbay> <version>\n" );
	exit( 1 );
}

if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	fwrite( STDERR, "ERROR: version must be X.Y.Z (got '{$version}').\n" );
	exit( 1 );
}

$p = $plugins[ $key ];

/**
 * Apply a single regex replacement to a file, failing loudly when the
 * pattern does not match exactly once (so a format drift can never be
 * silently skipped).
 *
 * @param string $path        Absolute file path.
 * @param string $pattern     PCRE to replace.
 * @param string $replacement Replacement text.
 * @param string $label       Human label for messages.
 */
function bv_replace( $path, $pattern, $replacement, $label ) {
	$body = @file_get_contents( $path );
	if ( $body === false ) {
		fwrite( STDERR, "ERROR: cannot read {$path}\n" );
		exit( 1 );
	}
	$new = preg_replace( $pattern, $replacement, $body, 1, $count );
	if ( $new === null || $count !== 1 ) {
		fwrite( STDERR, "ERROR: {$label} not found in {$path} — marker format changed? Nothing partial was written.\n" );
		exit( 1 );
	}
	if ( file_put_contents( $path, $new ) === false ) {
		fwrite( STDERR, "ERROR: cannot write {$path}\n" );
		exit( 1 );
	}
	echo '  updated ' . str_pad( $label, 38 ) . basename( $path ) . "\n";
}

$main_path   = $root . '/' . $p['main'];
$readme_path = $root . '/' . $p['dir'] . '/readme.txt';
$md_path     = $root . '/README.md';

echo "Bumping {$p['dir']} to {$version}\n";

// 1. Plugin header.
bv_replace(
	$main_path,
	'/^(\s*\*\s*Version:\s*)\S+\s*$/m',
	'${1}' . $version,
	'plugin header Version:'
);

// 2. Version constant.
bv_replace(
	$main_path,
	"/(define\\(\\s*'" . preg_quote( $p['constant'], '/' ) . "',\\s*')[^']+('\\s*\\))/",
	'${1}' . $version . '${2}',
	$p['constant'] . ' constant'
);

// 3. readme.txt Stable tag.
bv_replace(
	$readme_path,
	'/^(Stable tag:\s*)\S+\s*$/m',
	'${1}' . $version,
	'readme.txt Stable tag'
);

// 4. Changelog stub (skip when the heading already exists).
$readme = file_get_contents( $readme_path );
if ( preg_match( '/^=\s*' . preg_quote( $version, '/' ) . '\s*=\s*$/m', $readme ) ) {
	echo "  skipped changelog stub (= {$version} = already present)\n";
} else {
	bv_replace(
		$readme_path,
		'/^(== Changelog ==\s*\n)/m',
		"\${1}\n= {$version} =\n* TODO: describe this release.\n",
		'readme.txt changelog stub'
	);
}

// 5. Root README table row.
bv_replace(
	$md_path,
	'/^(\|\s*' . preg_quote( $p['readme_label'], '/' ) . '\s*\|\s*`' . preg_quote( $p['dir'], '/' ) . '\/`\s*\|\s*)\S+(\s*\|\s*)$/m',
	'${1}' . $version . '${2}',
	'root README.md table row'
);

// Verify the tree is consistent after the bump.
echo "\n";
passthru( PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/check-versions.php' ), $status );
if ( $status !== 0 ) {
	fwrite( STDERR, "\nERROR: tree inconsistent after bump — inspect the messages above.\n" );
	exit( 1 );
}

echo "\nDone. Remember to replace the TODO changelog stub with real notes before release.\n";
exit( 0 );
