<?php
/**
 * License-client drift gate.
 *
 * The StoreCanvas and OrderBay license clients are generated from the single
 * canonical source in tools/license-client/ (see generate.php there). This
 * check regenerates the copies in memory and byte-compares them against the
 * shipped files, so a hand-edit to a generated copy — or a template change
 * without regeneration — fails CI.
 *
 * Dependency-free CLI (PHP 7.4+). Exits 0 when everything matches,
 * 1 with a per-file report when anything drifts. Wired into CI as a
 * hard gate alongside tools/release/check-versions.php.
 *
 * Usage: php tools/release/check-license-sync.php
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$root = dirname( __DIR__, 2 );

require $root . '/tools/license-client/generate.php';

$drift = array();
foreach ( toc_license_client_files( $root ) as $rel => $expected ) {
	$actual = file_get_contents( $root . '/' . $rel );
	if ( false === $actual ) {
		$drift[] = "{$rel}: missing (expected a generated copy)";
		continue;
	}
	if ( $actual !== $expected ) {
		// Locate the first differing line to make the report actionable.
		$exp_lines = explode( "\n", $expected );
		$act_lines = explode( "\n", $actual );
		$max       = max( count( $exp_lines ), count( $act_lines ) );
		$line      = $max;
		for ( $i = 0; $i < $max; $i++ ) {
			$e = isset( $exp_lines[ $i ] ) ? $exp_lines[ $i ] : null;
			$a = isset( $act_lines[ $i ] ) ? $act_lines[ $i ] : null;
			if ( $e !== $a ) {
				$line = $i + 1;
				break;
			}
		}
		$drift[] = "{$rel}: differs from generated output (first difference at line {$line})";
	}
}

if ( $drift ) {
	fwrite( STDERR, "License client drift detected:\n" );
	foreach ( $drift as $d ) {
		fwrite( STDERR, "  - {$d}\n" );
	}
	fwrite( STDERR, "\nGenerated copies must not be hand-edited. Edit the canonical source in\n" );
	fwrite( STDERR, "tools/license-client/ and run: php tools/license-client/generate.php\n" );
	exit( 1 );
}

echo "License client in sync: all generated copies match tools/license-client/ sources.\n";
exit( 0 );
