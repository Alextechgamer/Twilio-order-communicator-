<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal pure-PHP QR Code SVG (no Composer).
 *
 * Encodes short text/URLs as Version 1–3 byte-mode QR (sufficient for order # / short links).
 * If encoding fails, callers should fail soft (no broken layout).
 *
 * Algorithm: compact byte-mode encoder with fixed EC level M tables for V1–V3.
 */
class OB_QR {

	/**
	 * Whether document QR is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = OB_Plugin::get_doc_settings();
		return ! empty( $s['qr_enabled'] ) && '1' === (string) $s['qr_enabled'];
	}

	/**
	 * Whether a vetted QR library is installed. When present it renders every symbol (correct by
	 * construction, any length); the bundled encoder is only an experimental short-payload fallback.
	 *
	 * @return bool
	 */
	public static function library_available() {
		return class_exists( '\chillerlan\QRCode\QRCode' ) || class_exists( '\Endroid\QrCode\QrCode' );
	}

	/**
	 * Smallest built-in version (1–3) whose EC-M byte capacity holds a payload, or 0 if it exceeds
	 * the bundled encoder's capacity. Prevents the old silent truncation that produced dead QR for
	 * order URLs (~47 bytes) by always forcing Version 3.
	 *
	 * @param int $len Payload length in bytes.
	 * @return int 1|2|3, or 0 when too long for the built-in encoder.
	 */
	public static function pick_version( $len ) {
		$len = (int) $len;
		if ( $len <= 0 ) {
			return 0;
		}
		if ( $len <= 14 ) {
			return 1;
		}
		if ( $len <= 26 ) {
			return 2;
		}
		if ( $len <= 42 ) {
			return 3;
		}
		return 0;
	}

	/**
	 * Render a QR SVG through an installed vetted library. Returns '' if none is usable.
	 *
	 * @param string $text      Payload.
	 * @param int    $module_px Module size.
	 * @return string
	 */
	private static function svg_via_library( $text, $module_px ) {
		$scale = max( 1, (int) $module_px );
		try {
			if ( class_exists( '\chillerlan\QRCode\QRCode' ) && class_exists( '\chillerlan\QRCode\QROptions' ) ) {
				$opts = new \chillerlan\QRCode\QROptions(
					array(
						'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
						'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
						'scale'      => $scale,
					)
				);
				return (string) ( new \chillerlan\QRCode\QRCode( $opts ) )->render( $text );
			}
			if ( class_exists( '\Endroid\QrCode\QrCode' ) && class_exists( '\Endroid\QrCode\Writer\SvgWriter' ) ) {
				$qr     = \Endroid\QrCode\QrCode::create( $text );
				$writer = new \Endroid\QrCode\Writer\SvgWriter();
				return (string) $writer->write( $qr )->getString();
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/**
	 * Payload for an order: prefer My Account view URL, else order number text.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function payload_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$url = $order->get_view_order_url();
		if ( $url ) {
			return $url;
		}
		return (string) $order->get_order_number();
	}

	/**
	 * Echo a QR block when enabled.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_for_order( $order ) {
		if ( ! self::enabled() || ! $order instanceof WC_Order ) {
			return;
		}
		$data = self::payload_for_order( $order );
		$svg  = self::svg( $data, 3 );
		if ( ! $svg ) {
			return;
		}
		echo '<div class="ob-qr" style="margin:10px 0;text-align:right;">';
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div style="font-size:10px;color:#666;">' . esc_html__( 'Order QR', 'orderbay' ) . '</div>';
		echo '</div>';
	}

	/**
	 * Build SVG QR for text.
	 *
	 * @param string $text Payload.
	 * @param int    $module_px Module size.
	 * @return string Empty on failure.
	 */
	public static function svg( $text, $module_px = 3 ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}

		// Prefer a vetted library when installed — it renders any payload correctly.
		if ( self::library_available() ) {
			$lib = self::svg_via_library( $text, $module_px );
			if ( '' !== $lib ) {
				return $lib;
			}
		}

		// Built-in fallback: only encode payloads that fit the reliable V1–V3 capacity. Never
		// truncate — a truncated symbol is an unscannable (dead) QR, so fail soft instead.
		if ( 0 === self::pick_version( strlen( $text ) ) ) {
			return '';
		}

		$matrix = self::encode_matrix( $text );
		if ( ! $matrix ) {
			return '';
		}
		$n    = count( $matrix );
		$size = $n * max( 1, (int) $module_px );
		$parts = array();
		$parts[] = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" shape-rendering="crispEdges">',
			$size,
			$size,
			$n,
			$n
		);
		$parts[] = '<rect width="100%" height="100%" fill="#fff"/>';
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( ! empty( $matrix[ $y ][ $x ] ) ) {
					$parts[] = sprintf( '<rect x="%d" y="%d" width="1" height="1" fill="#000"/>', $x, $y );
				}
			}
		}
		$parts[] = '</svg>';
		return implode( '', $parts );
	}

	/**
	 * Encode to binary matrix (1 = dark).
	 *
	 * Uses a simplified path: for reliability on short strings, builds a
	 * presentation QR via Reed-Solomon free approach using precomputed
	 * format/version for V1-M / V2-M / V3-M.
	 *
	 * @param string $text Text.
	 * @return array|null
	 */
	private static function encode_matrix( $text ) {
		// Prefer external-free path: use PHP's hash to drive a deterministic
		// scannable-looking matrix is NOT valid QR. Implement real V1-M when short.
		$bytes = array_values( unpack( 'C*', $text ) );
		$len   = count( $bytes );

		// Capacity (bytes) EC-M: V1=14, V2=26, V3=42. Reject over-capacity instead of truncating
		// to a fixed V3 (which produced dead QR for order URLs).
		$ver = self::pick_version( $len );
		if ( 0 === $ver ) {
			return null;
		}

		$size = 17 + 4 * $ver; // 21, 25, 29
		// Build data codewords: mode byte(0100) + length + data + terminator + pad.
		$bits = array();
		self::push_bits( $bits, 0x4, 4 ); // byte mode
		self::push_bits( $bits, $len, ( $ver <= 9 ? 8 : 16 ) );
		foreach ( $bytes as $b ) {
			self::push_bits( $bits, $b, 8 );
		}
		// Terminator up to 4 bits.
		$term = min( 4, ( 8 - ( count( $bits ) % 8 ) ) % 8 );
		if ( 0 === $term && count( $bits ) % 8 !== 0 ) {
			$term = min( 4, 8 - ( count( $bits ) % 8 ) );
		}
		// Simpler: pad to byte boundary then pad codewords.
		while ( count( $bits ) % 8 !== 0 ) {
			$bits[] = 0;
		}
		// Total data codewords for version/EC-M (approx table).
		$data_cw = array( 1 => 16, 2 => 28, 3 => 44 );
		$need    = $data_cw[ $ver ] ?? 16;
		$cw      = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$v = 0;
			for ( $j = 0; $j < 8; $j++ ) {
				$v = ( $v << 1 ) | ( $bits[ $i + $j ] ?? 0 );
			}
			$cw[] = $v;
		}
		$pad = array( 0xEC, 0x11 );
		$pi  = 0;
		while ( count( $cw ) < $need ) {
			$cw[] = $pad[ $pi % 2 ];
			$pi++;
		}
		$cw = array_slice( $cw, 0, $need );

		// Reed-Solomon EC (EC-M codewords per version): V1=10, V2=16, V3=26.
		$ec_len = array( 1 => 10, 2 => 16, 3 => 26 );
		$ecl    = $ec_len[ $ver ] ?? 10;
		$ec     = self::rs_encode( $cw, $ecl );
		$all    = array_merge( $cw, $ec );

		// Place into matrix.
		$m = array_fill( 0, $size, array_fill( 0, $size, null ) );
		self::place_finders( $m, $size );
		self::place_timing( $m, $size );
		self::place_dark_module( $m, $ver );
		if ( $ver >= 2 ) {
			self::place_alignment( $m, $ver );
		}
		// Reserve format areas.
		self::reserve_format( $m, $size );

		// Data bits zigzag.
		$bitstr = array();
		foreach ( $all as $byte ) {
			for ( $i = 7; $i >= 0; $i-- ) {
				$bitstr[] = ( $byte >> $i ) & 1;
			}
		}
		self::place_data( $m, $size, $bitstr );

		// Mask 0 + format info EC-M mask0.
		self::apply_mask0( $m, $size );
		self::write_format( $m, $size, 0 ); // mask 0, EC M

		// Convert null→0.
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( null === $m[ $y ][ $x ] ) {
					$m[ $y ][ $x ] = 0;
				}
			}
		}
		return $m;
	}

	private static function push_bits( &$bits, $val, $n ) {
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$bits[] = ( $val >> $i ) & 1;
		}
	}

	/** GF(256) RS remainder. */
	private static function rs_encode( $data, $ec_len ) {
		// Generator poly for degree ec_len over GF(256) QR.
		$gen = self::rs_generator( $ec_len );
		$msg = array_merge( $data, array_fill( 0, $ec_len, 0 ) );
		foreach ( $data as $i => $unused ) {
			$coef = $msg[ $i ];
			if ( 0 === $coef ) {
				continue;
			}
			for ( $j = 0; $j < count( $gen ); $j++ ) {
				$msg[ $i + $j ] ^= self::gf_mul( $gen[ $j ], $coef );
			}
		}
		return array_slice( $msg, -$ec_len );
	}

	private static function rs_generator( $degree ) {
		$g = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$g = self::poly_mul( $g, array( 1, self::gf_exp( $i ) ) );
		}
		return $g;
	}

	private static function poly_mul( $a, $b ) {
		$r = array_fill( 0, count( $a ) + count( $b ) - 1, 0 );
		for ( $i = 0; $i < count( $a ); $i++ ) {
			for ( $j = 0; $j < count( $b ); $j++ ) {
				$r[ $i + $j ] ^= self::gf_mul( $a[ $i ], $b[ $j ] );
			}
		}
		return $r;
	}

	private static function gf_exp( $n ) {
		static $exp = null;
		static $log = null;
		if ( null === $exp ) {
			$exp = array();
			$log = array();
			$x   = 1;
			for ( $i = 0; $i < 255; $i++ ) {
				$exp[ $i ] = $x;
				$log[ $x ] = $i;
				$x <<= 1;
				if ( $x & 0x100 ) {
					$x ^= 0x11d;
				}
			}
			$exp[255] = $exp[0];
		}
		return $exp[ $n % 255 ];
	}

	private static function gf_mul( $a, $b ) {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		static $log = null;
		static $exp = null;
		if ( null === $log ) {
			$exp = array();
			$log = array();
			$x   = 1;
			for ( $i = 0; $i < 255; $i++ ) {
				$exp[ $i ] = $x;
				$log[ $x ] = $i;
				$x <<= 1;
				if ( $x & 0x100 ) {
					$x ^= 0x11d;
				}
			}
		}
		return $exp[ ( $log[ $a ] + $log[ $b ] ) % 255 ];
	}

	private static function place_finders( &$m, $size ) {
		$positions = array( array( 0, 0 ), array( $size - 7, 0 ), array( 0, $size - 7 ) );
		foreach ( $positions as $p ) {
			self::draw_finder( $m, $p[0], $p[1] );
		}
	}

	private static function draw_finder( &$m, $row, $col ) {
		for ( $y = -1; $y <= 7; $y++ ) {
			for ( $x = -1; $x <= 7; $x++ ) {
				$rr = $row + $y;
				$cc = $col + $x;
				if ( $rr < 0 || $cc < 0 || $rr >= count( $m ) || $cc >= count( $m ) ) {
					continue;
				}
				$dark = ( $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6 && ( 0 === $x || 6 === $x || 0 === $y || 6 === $y || ( $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4 ) ) );
				$m[ $rr ][ $cc ] = $dark ? 1 : 0;
			}
		}
	}

	private static function place_timing( &$m, $size ) {
		for ( $i = 8; $i < $size - 8; $i++ ) {
			if ( null === $m[6][ $i ] ) {
				$m[6][ $i ] = ( $i % 2 === 0 ) ? 1 : 0;
			}
			if ( null === $m[ $i ][6] ) {
				$m[ $i ][6] = ( $i % 2 === 0 ) ? 1 : 0;
			}
		}
	}

	private static function place_dark_module( &$m, $ver ) {
		// (4*ver+9, 8)
		$r = 4 * $ver + 9;
		if ( isset( $m[ $r ][8] ) ) {
			$m[ $r ][8] = 1;
		}
	}

	private static function place_alignment( &$m, $ver ) {
		// V2 center 6,18 ; V3 6,22
		$centers = array( 2 => array( 6, 18 ), 3 => array( 6, 22 ) );
		if ( empty( $centers[ $ver ] ) ) {
			return;
		}
		list( $c1, $c2 ) = $centers[ $ver ];
		foreach ( array( array( $c2, $c2 ) ) as $pos ) {
			self::draw_alignment( $m, $pos[0], $pos[1] );
		}
	}

	private static function draw_alignment( &$m, $cx, $cy ) {
		for ( $y = -2; $y <= 2; $y++ ) {
			for ( $x = -2; $x <= 2; $x++ ) {
				$rr = $cy + $y;
				$cc = $cx + $x;
				if ( $rr < 0 || $cc < 0 || $rr >= count( $m ) || $cc >= count( $m ) ) {
					continue;
				}
				if ( null !== $m[ $rr ][ $cc ] ) {
					continue; // don't overwrite finders
				}
				$dark = ( abs( $x ) === 2 || abs( $y ) === 2 || ( 0 === $x && 0 === $y ) );
				$m[ $rr ][ $cc ] = $dark ? 1 : 0;
			}
		}
	}

	private static function reserve_format( &$m, $size ) {
		for ( $i = 0; $i < 9; $i++ ) {
			if ( null === $m[8][ $i ] ) {
				$m[8][ $i ] = 0;
			}
			if ( null === $m[ $i ][8] ) {
				$m[ $i ][8] = 0;
			}
		}
		for ( $i = 0; $i < 8; $i++ ) {
			if ( null === $m[8][ $size - 1 - $i ] ) {
				$m[8][ $size - 1 - $i ] = 0;
			}
			if ( null === $m[ $size - 1 - $i ][8] ) {
				$m[ $size - 1 - $i ][8] = 0;
			}
		}
	}

	private static function place_data( &$m, $size, $bits ) {
		$bi = 0;
		$n  = count( $bits );
		for ( $right = $size - 1; $right > 0; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x = $right - $j;
					$upward = ( (int) ( ( $right + 1 ) / 2 ) % 2 === 0 );
					$y = $upward ? ( $size - 1 - $vert ) : $vert;
					if ( null !== $m[ $y ][ $x ] ) {
						continue;
					}
					$bit = ( $bi < $n ) ? $bits[ $bi ] : 0;
					$m[ $y ][ $x ] = $bit;
					$bi++;
				}
			}
		}
	}

	private static function apply_mask0( &$m, $size ) {
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				// Only data modules were null before place; after place all set.
				// Mask0: (x+y)%2==0 — but skip function patterns (finders etc already fixed).
				// We approximate: only flip if not on reserved finder/timing - check timing row/col and finders.
				if ( self::is_function( $x, $y, $size ) ) {
					continue;
				}
				if ( ( ( $x + $y ) % 2 ) === 0 ) {
					$m[ $y ][ $x ] = $m[ $y ][ $x ] ? 0 : 1;
				}
			}
		}
	}

	private static function is_function( $x, $y, $size ) {
		if ( $y === 6 || $x === 6 ) {
			return true;
		}
		// Finders + separators.
		if ( $x < 9 && $y < 9 ) {
			return true;
		}
		if ( $x >= $size - 8 && $y < 9 ) {
			return true;
		}
		if ( $x < 9 && $y >= $size - 8 ) {
			return true;
		}
		// Format.
		if ( 8 === $y && ( $x < 9 || $x >= $size - 8 ) ) {
			return true;
		}
		if ( 8 === $x && ( $y < 9 || $y >= $size - 7 ) ) {
			return true;
		}
		return false;
	}

	private static function write_format( &$m, $size, $mask ) {
		// EC level M = 00, mask in low 3 bits. Precomputed BCH for common mask0-M:
		// format bits for (M, mask0) = 0x5412 pattern — use known table.
		// Format info for ECC=M (0b00) mask=0: 0x5412 after mask with 0x5412...
		// Use fixed 15-bit sequence for mask 0 EC-M: 101010000010010
		$bits = array( 1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 1, 0 );
		// Horizontal near finder.
		$pos = array( 0, 1, 2, 3, 4, 5, 7, 8 );
		// Simpler placement per ISO:
		$coords_a = array(
			array( 8, 0 ), array( 8, 1 ), array( 8, 2 ), array( 8, 3 ), array( 8, 4 ), array( 8, 5 ), array( 8, 7 ), array( 8, 8 ),
			array( 7, 8 ), array( 5, 8 ), array( 4, 8 ), array( 3, 8 ), array( 2, 8 ), array( 1, 8 ), array( 0, 8 ),
		);
		for ( $i = 0; $i < 15; $i++ ) {
			$m[ $coords_a[ $i ][0] ][ $coords_a[ $i ][1] ] = $bits[ $i ];
		}
		// Copy to other side.
		for ( $i = 0; $i < 8; $i++ ) {
			$m[ 8 ][ $size - 1 - $i ] = $bits[ $i ];
		}
		for ( $i = 0; $i < 7; $i++ ) {
			$m[ $size - 7 + $i ][ 8 ] = $bits[ 8 + $i ];
		}
	}
}
