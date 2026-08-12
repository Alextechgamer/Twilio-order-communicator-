<?php
/**
 * Dependency-free .pot generator for the three plugins.
 *
 * Scans each plugin's PHP for WordPress gettext calls (via token_get_all, so nesting and string
 * escaping are handled correctly) and writes languages/<text-domain>.pot. JS-facing strings are
 * localized through PHP (wp_localize_script → __()), so scanning PHP covers them too.
 *
 * Usage: php tools/make-pot.php
 *
 * Not shipped in the plugins (dev tool). Deterministic output (sorted) so re-running is idempotent.
 */

$root = dirname( __DIR__ );

$plugins = array(
	array(
		'slug'   => 'twilio-order-communicator',
		'domain' => 'twilio-order-communicator',
		'name'   => 'OrderRing',
		'dir'    => $root . '/twilio-order-communicator',
	),
	array(
		'slug'   => 'storecanvas',
		'domain' => 'storecanvas',
		'name'   => 'StoreCanvas',
		'dir'    => $root . '/storecanvas',
	),
	array(
		'slug'   => 'orderbay',
		'domain' => 'orderbay',
		'name'   => 'Orderbay',
		'dir'    => $root . '/orderbay',
	),
);

// function-name => arg layout. s0 = msgid at arg0; ctx1 = context at arg1; plural1 = plural at arg1.
$functions = array(
	'__'            => array( 'msgid' => 0 ),
	'_e'            => array( 'msgid' => 0 ),
	'esc_html__'    => array( 'msgid' => 0 ),
	'esc_html_e'    => array( 'msgid' => 0 ),
	'esc_attr__'    => array( 'msgid' => 0 ),
	'esc_attr_e'    => array( 'msgid' => 0 ),
	'_x'            => array( 'msgid' => 0, 'context' => 1 ),
	'esc_html_x'    => array( 'msgid' => 0, 'context' => 1 ),
	'esc_attr_x'    => array( 'msgid' => 0, 'context' => 1 ),
	'_ex'           => array( 'msgid' => 0, 'context' => 1 ),
	'_n'            => array( 'msgid' => 0, 'plural' => 1 ),
	'_nx'           => array( 'msgid' => 0, 'plural' => 1, 'context' => 3 ),
	'_n_noop'       => array( 'msgid' => 0, 'plural' => 1 ),
	'_nx_noop'      => array( 'msgid' => 0, 'plural' => 1, 'context' => 2 ),
);

/**
 * Decode a PHP string-literal token (T_CONSTANT_ENCAPSED_STRING) to its runtime value.
 * Returns null for interpolated/complex literals we should not translate.
 */
function pot_decode_literal( $tok ) {
	$q = $tok[0];
	$inner = substr( $tok, 1, -1 );
	if ( "'" === $q ) {
		return strtr( $inner, array( "\\'" => "'", '\\\\' => '\\' ) );
	}
	if ( '"' === $q ) {
		// Reject double-quoted literals containing interpolation ($var or {$...}).
		if ( preg_match( '/(?<!\\\\)\$/', $inner ) ) {
			return null;
		}
		return strtr(
			$inner,
			array( '\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\"' => '"', '\\$' => '$', '\\\\' => '\\' )
		);
	}
	return null;
}

/** Escape a string for a .pot msgid/msgstr. */
function pot_escape( $s ) {
	$s = str_replace( array( '\\', '"', "\t", "\r" ), array( '\\\\', '\\"', '\\t', '\\r' ), $s );
	return str_replace( "\n", '\\n', $s );
}

/** Recursively collect .php files under a directory (skips vendor/node_modules). */
function pot_php_files( $dir ) {
	$out = array();
	$it  = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			function ( $current ) {
				$name = $current->getFilename();
				if ( $current->isDir() ) {
					return ! in_array( $name, array( 'vendor', 'node_modules', 'languages' ), true );
				}
				return substr( $name, -4 ) === '.php';
			}
		)
	);
	foreach ( $it as $f ) {
		$out[] = $f->getPathname();
	}
	sort( $out );
	return $out;
}

/**
 * Extract translatable entries from one file.
 * Returns list of ['context'=>?, 'msgid'=>, 'plural'=>?, 'ref'=>'file:line'].
 */
function pot_extract_file( $path, $functions, $domain, $rel ) {
	$src    = file_get_contents( $path );
	$tokens = token_get_all( $src );
	$n      = count( $tokens );
	$out    = array();

	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $functions[ $t[1] ] ) ) {
			continue;
		}
		// Guard against method/property calls ($obj->__(...)) and namespaced names.
		$prev = $i > 0 ? $tokens[ $i - 1 ] : null;
		if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		$fn   = $t[1];
		$line = $t[2];

		// Next non-whitespace must be '('.
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $n || '(' !== $tokens[ $j ] ) {
			continue;
		}

		// Walk arguments at paren-depth 1, recording the literal (or null) per arg slot.
		$depth   = 0;
		$args    = array();
		$argIdx  = 0;
		$hasDom  = false;
		for ( $k = $j; $k < $n; $k++ ) {
			$tk = $tokens[ $k ];
			if ( '(' === $tk ) {
				$depth++;
				continue;
			}
			if ( ')' === $tk ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
				continue;
			}
			if ( 1 === $depth && ',' === $tk ) {
				$argIdx++;
				continue;
			}
			if ( 1 === $depth && is_array( $tk ) && T_CONSTANT_ENCAPSED_STRING === $tk[0] ) {
				$val = pot_decode_literal( $tk[1] );
				if ( ! isset( $args[ $argIdx ] ) ) {
					$args[ $argIdx ] = $val; // first literal wins for this slot
				}
				if ( null !== $val && $val === $domain ) {
					$hasDom = true;
				}
			}
		}

		// Only include calls that reference this plugin's text domain (skip stray/other-domain).
		if ( ! $hasDom ) {
			continue;
		}

		$layout = $functions[ $fn ];
		$msgid  = isset( $args[ $layout['msgid'] ] ) ? $args[ $layout['msgid'] ] : null;
		if ( null === $msgid || '' === $msgid ) {
			continue;
		}
		$entry = array(
			'context' => isset( $layout['context'], $args[ $layout['context'] ] ) ? $args[ $layout['context'] ] : null,
			'msgid'   => $msgid,
			'plural'  => isset( $layout['plural'], $args[ $layout['plural'] ] ) ? $args[ $layout['plural'] ] : null,
			'ref'     => $rel . ':' . $line,
		);
		$out[] = $entry;
	}
	return $out;
}

$total = 0;
foreach ( $plugins as $p ) {
	$entries = array(); // key => merged entry
	foreach ( pot_php_files( $p['dir'] ) as $file ) {
		$rel = ltrim( str_replace( $p['dir'], '', $file ), '/' );
		foreach ( pot_extract_file( $file, $functions, $p['domain'], $rel ) as $e ) {
			$key = ( $e['context'] ?? '' ) . "\x04" . $e['msgid'] . "\x04" . ( $e['plural'] ?? '' );
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array(
					'context' => $e['context'],
					'msgid'   => $e['msgid'],
					'plural'  => $e['plural'],
					'refs'    => array(),
				);
			}
			$entries[ $key ]['refs'][ $e['ref'] ] = true;
		}
	}

	ksort( $entries );

	$date = gmdate( 'Y-m-d H:iO' );
	$out  = array();
	$out[] = '# Copyright (C) ' . gmdate( 'Y' ) . ' ' . $p['name'];
	$out[] = '# This file is distributed under the same license as the ' . $p['name'] . ' plugin.';
	$out[] = 'msgid ""';
	$out[] = 'msgstr ""';
	$out[] = '"Project-Id-Version: ' . $p['name'] . '\n"';
	$out[] = '"MIME-Version: 1.0\n"';
	$out[] = '"Content-Type: text/plain; charset=UTF-8\n"';
	$out[] = '"Content-Transfer-Encoding: 8bit\n"';
	$out[] = '"POT-Creation-Date: ' . $date . '\n"';
	$out[] = '"X-Domain: ' . $p['domain'] . '\n"';
	$out[] = '';

	foreach ( $entries as $e ) {
		$refs = array_keys( $e['refs'] );
		sort( $refs );
		// Wrap references at ~75 cols across multiple #: lines.
		$line = '#:';
		foreach ( $refs as $r ) {
			if ( strlen( $line ) + strlen( $r ) + 1 > 75 && '#:' !== $line ) {
				$out[] = $line;
				$line  = '#:';
			}
			$line .= ' ' . $r;
		}
		$out[] = $line;
		if ( null !== $e['context'] ) {
			$out[] = 'msgctxt "' . pot_escape( $e['context'] ) . '"';
		}
		$out[] = 'msgid "' . pot_escape( $e['msgid'] ) . '"';
		if ( null !== $e['plural'] ) {
			$out[] = 'msgid_plural "' . pot_escape( $e['plural'] ) . '"';
			$out[] = 'msgstr[0] ""';
			$out[] = 'msgstr[1] ""';
		} else {
			$out[] = 'msgstr ""';
		}
		$out[] = '';
	}

	$dir = $p['dir'] . '/languages';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	$path = $dir . '/' . $p['domain'] . '.pot';
	file_put_contents( $path, implode( "\n", $out ) . "\n" );
	$count = count( $entries );
	$total += $count;
	echo str_pad( $p['slug'], 26 ) . ' ' . $count . " strings → " . str_replace( $root . '/', '', $path ) . "\n";
}
echo "TOTAL: {$total} translatable strings\n";
