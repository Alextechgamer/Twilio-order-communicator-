<?php
/**
 * Version-consistency gate for the plugin monorepo.
 *
 * For each plugin, asserts that all five version markers agree:
 *   1. Plugin header `Version:` in the main plugin file
 *   2. The `*_VERSION` constant in the main plugin file
 *   3. `Stable tag:` in readme.txt
 *   4. The top changelog entry heading in readme.txt
 *   5. The plugin's row in the root README.md version table
 *
 * Dependency-free CLI (PHP 7.4+). Exits 0 when everything agrees,
 * 1 with a per-plugin diff when anything drifts. Wired into CI as a
 * hard gate; also run by tools/release/bump-version.php after a bump.
 *
 * Usage: php tools/release/check-versions.php
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$root = dirname( __DIR__, 2 );

/**
 * Plugin registry: directory, main file, version constant, and the label
 * used in the root README version table.
 */
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

/**
 * Read a file or die with a clear message.
 *
 * @param string $path Absolute path.
 * @return string
 */
function cv_read( $path ) {
	$body = @file_get_contents( $path );
	if ( $body === false ) {
		fwrite( STDERR, "ERROR: cannot read {$path}\n" );
		exit( 1 );
	}
	return $body;
}

/**
 * First regex capture or null.
 *
 * @param string $pattern PCRE with one capture group.
 * @param string $subject Text to search.
 * @return string|null
 */
function cv_match( $pattern, $subject ) {
	return preg_match( $pattern, $subject, $m ) ? trim( $m[1] ) : null;
}

$readme_md = cv_read( $root . '/README.md' );
$failures  = 0;

foreach ( $plugins as $key => $p ) {
	$main   = cv_read( $root . '/' . $p['main'] );
	$readme = cv_read( $root . '/' . $p['dir'] . '/readme.txt' );

	$places = array();

	// 1. Plugin header.
	$places['plugin header Version:'] = cv_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $main );

	// 2. Version constant.
	$places[ $p['constant'] . ' constant' ] = cv_match(
		"/define\\(\\s*'" . preg_quote( $p['constant'], '/' ) . "',\\s*'([^']+)'\\s*\\)/",
		$main
	);

	// 3. readme.txt Stable tag.
	$places['readme.txt Stable tag'] = cv_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme );

	// 4. Top changelog entry: first `= X.Y.Z =` heading after `== Changelog ==`.
	$changelog_top = null;
	$pos           = strpos( $readme, '== Changelog ==' );
	if ( $pos !== false ) {
		$changelog_top = cv_match( '/^=\s*([0-9][^=\s]*)\s*=\s*$/m', substr( $readme, $pos ) );
	}
	$places['readme.txt top changelog entry'] = $changelog_top;

	// 5. Root README version-table row: | <label> | `<dir>/` | <version> |
	$places['root README.md table row'] = cv_match(
		'/^\|\s*' . preg_quote( $p['readme_label'], '/' ) . '\s*\|\s*`' . preg_quote( $p['dir'], '/' ) . '\/`\s*\|\s*(\S+)\s*\|\s*$/m',
		$readme_md
	);

	$values = array_unique( array_filter( $places, 'is_string' ) );
	$missing = array_keys( array_filter( $places, 'is_null' ) );

	if ( count( $values ) === 1 && ! $missing ) {
		echo str_pad( $p['dir'], 28 ) . ' OK  ' . reset( $values ) . "\n";
		continue;
	}

	$failures++;
	echo str_pad( $p['dir'], 28 ) . " MISMATCH\n";
	foreach ( $places as $label => $value ) {
		echo '    ' . str_pad( $label, 42 ) . ( $value === null ? '<not found>' : $value ) . "\n";
	}
}

if ( $failures ) {
	fwrite( STDERR, "\nVersion markers disagree in {$failures} plugin(s). Fix with: php tools/release/bump-version.php <plugin> <version>\n" );
	exit( 1 );
}

echo "\nAll version markers agree.\n";
exit( 0 );
