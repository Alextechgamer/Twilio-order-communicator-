<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight barcode rendering for print docs.
 *
 * Approach: pure-PHP Code 128B → SVG bars (no Composer / JS).
 * Falls back to large monospace *ORDER* if encoding fails.
 */
class OB_Barcode {

	/**
	 * Whether barcodes are enabled in document settings.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = OB_Plugin::get_doc_settings();
		return ! empty( $s['show_barcodes'] ) && '1' === (string) $s['show_barcodes'];
	}

	/**
	 * Echo print-ready barcode block for an order number (or any code).
	 *
	 * @param string $code Code to encode (order number preferred).
	 * @param string $label Optional human label under bars.
	 */
	public static function render( $code, $label = '' ) {
		$code = preg_replace( '/[^\x20-\x7E]/', '', (string) $code );
		if ( '' === $code ) {
			return;
		}
		$svg = self::code128_svg( $code );
		echo '<div class="ob-barcode" style="margin:10px 0;text-align:center;">';
		if ( $svg ) {
			echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from our encoder
		} else {
			// Scanner-friendly fallback: asterisk-framed monospace.
			echo '<div style="font-family:ui-monospace,monospace;font-size:22px;letter-spacing:2px;font-weight:700;">*' . esc_html( $code ) . '*</div>';
		}
		if ( $label ) {
			echo '<div style="font-size:11px;color:#444;margin-top:2px;">' . esc_html( $label ) . '</div>';
		} else {
			echo '<div style="font-size:11px;color:#444;margin-top:2px;font-family:ui-monospace,monospace;">' . esc_html( $code ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Minimal Code 128B encoder → SVG.
	 *
	 * @param string $text ASCII printable.
	 * @return string SVG markup or empty.
	 */
	public static function code128_svg( $text ) {
		// Code 128 patterns (B set values 0–106) as bar/space width sequences (11 modules each except stop).
		// Compact subset: we build using standard 128B table.
		static $patterns = null;
		if ( null === $patterns ) {
			$patterns = self::patterns();
		}
		// Start B = 104, Stop = 106.
		$codes = array( 104 );
		$sum   = 104;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$v = ord( $text[ $i ] ) - 32;
			if ( $v < 0 || $v > 95 ) {
				return '';
			}
			$codes[] = $v;
			$sum    += $v * ( $i + 1 );
		}
		$codes[] = $sum % 103; // checksum
		$codes[] = 106; // stop

		$widths = '';
		foreach ( $codes as $c ) {
			if ( ! isset( $patterns[ $c ] ) ) {
				return '';
			}
			$widths .= $patterns[ $c ];
		}
		// Module width 1.5px, height 48.
		$mw     = 1.6;
		$height = 48;
		$x      = 0;
		$bars   = '';
		$is_bar = true;
		for ( $i = 0, $n = strlen( $widths ); $i < $n; $i++ ) {
			$w = (int) $widths[ $i ];
			$px = $w * $mw;
			if ( $is_bar ) {
				$bars .= '<rect x="' . $x . '" y="0" width="' . $px . '" height="' . $height . '" fill="#000"/>';
			}
			$x     += $px;
			$is_bar = ! $is_bar;
		}
		$total = $x + 4;
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . esc_attr( (string) round( $total ) ) . '" height="' . esc_attr( (string) $height ) . '" viewBox="0 0 ' . esc_attr( (string) $total ) . ' ' . esc_attr( (string) $height ) . '" role="img" aria-label="' . esc_attr( $text ) . '">' . $bars . '</svg>';
	}

	/**
	 * Code 128 width patterns (bars/spaces as digit string) index 0–106.
	 *
	 * @return string[]
	 */
	private static function patterns() {
		// Standard Code 128 patterns (from ISO/IEC 15417 common tables).
		return array(
			'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
			'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
			'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
			'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
			'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
			'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
			'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
			'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
			'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
			'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
			'114131', '311141', '411131', '211412', '211214', '211232', '2331112',
		);
	}
}
