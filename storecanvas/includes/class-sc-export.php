<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vector / PDF export of a generated print composite.
 *
 * - SVG: a print-dimensioned (mm) wrapper embedding the composite as a base64 image, with
 *   an optional bleed guide. Scalable and carries a real physical size.
 * - PDF: a minimal single-page PDF embedding the composite (flattened to RGB JPEG) at its
 *   physical print size. Pure PHP, no library.
 *
 * The builders are pure (bytes in, string out) so they are unit-testable without WordPress.
 * Caveat: the PDF is RGB/flattened — not CMYK/PDF-X; true vector-text export is a later step.
 */
class SC_Export {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_sc_export_svg', array( $this, 'handle_svg' ) );
		add_action( 'admin_post_sc_export_pdf', array( $this, 'handle_pdf' ) );
	}

	/**
	 * Nonce-protected export URL for a composite attachment.
	 *
	 * @param int    $att_id Attachment ID of the print composite.
	 * @param string $format svg|pdf.
	 * @return string
	 */
	public static function download_url( $att_id, $format ) {
		$att_id = absint( $att_id );
		$action = ( 'pdf' === $format ) ? 'sc_export_pdf' : 'sc_export_svg';
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&att=' . $att_id ),
			'sc_export_' . $att_id
		);
	}

	/**
	 * Resolve + authorize the requested composite attachment.
	 *
	 * @return array{att:int,path:string,w:int,h:int,mime:string}
	 */
	private function load_composite() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		$att = isset( $_GET['att'] ) ? absint( $_GET['att'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'sc_export_' . $att );
		$path = $att ? get_attached_file( $att ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Print file not found.', 'storecanvas' ) );
		}
		$info = @getimagesize( $path );
		if ( ! is_array( $info ) ) {
			wp_die( esc_html__( 'Print file is not an image.', 'storecanvas' ) );
		}
		return array(
			'att'  => $att,
			'path' => $path,
			'w'    => (int) $info[0],
			'h'    => (int) $info[1],
			'mime' => isset( $info['mime'] ) ? (string) $info['mime'] : 'image/png',
		);
	}

	public function handle_svg() {
		$c     = $this->load_composite();
		$bytes = (string) file_get_contents( $c['path'] );
		$svg   = self::svg_wrap( $bytes, $c['mime'], $c['w'], $c['h'], self::dpi(), array( 'bleed_mm' => 3.0 ) );
		nocache_headers();
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=sc-print-' . $c['att'] . '.svg' );
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DOMDocument XML.
		exit;
	}

	public function handle_pdf() {
		$c    = $this->load_composite();
		$jpeg = self::flatten_to_jpeg( $c['path'] );
		if ( '' === $jpeg ) {
			wp_die( esc_html__( 'Could not render the PDF image (PHP GD required).', 'storecanvas' ) );
		}
		$pdf = self::pdf_single_image_jpeg( $jpeg, $c['w'], $c['h'], self::dpi() );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename=sc-print-' . $c['att'] . '.pdf' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * @return int Output DPI (shared with the PNG pHYs stamp).
	 */
	private static function dpi() {
		return class_exists( 'SC_Print_Ready' ) ? SC_Print_Ready::output_dpi() : 300;
	}

	/**
	 * Build a print-dimensioned SVG embedding the composite image.
	 *
	 * @param string $img_bytes Raw image bytes.
	 * @param string $mime      Image MIME.
	 * @param int    $px_w      Pixel width.
	 * @param int    $px_h      Pixel height.
	 * @param int    $dpi       DPI for physical sizing.
	 * @param array  $opts      Optional: bleed_mm.
	 * @return string SVG XML.
	 */
	public static function svg_wrap( $img_bytes, $mime, $px_w, $px_h, $dpi, $opts = array() ) {
		$dpi  = (float) $dpi > 0 ? (float) $dpi : 300.0;
		$w_mm = round( (int) $px_w / $dpi * 25.4, 3 );
		$h_mm = round( (int) $px_h / $dpi * 25.4, 3 );
		$href = 'data:' . $mime . ';base64,' . base64_encode( (string) $img_bytes );

		$doc               = new DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$svgns             = 'http://www.w3.org/2000/svg';
		$xlinkns           = 'http://www.w3.org/1999/xlink';

		$svg = $doc->createElementNS( $svgns, 'svg' );
		$svg->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xlink', $xlinkns );
		$svg->setAttribute( 'width', $w_mm . 'mm' );
		$svg->setAttribute( 'height', $h_mm . 'mm' );
		$svg->setAttribute( 'viewBox', '0 0 ' . $w_mm . ' ' . $h_mm );
		$doc->appendChild( $svg );

		$image = $doc->createElementNS( $svgns, 'image' );
		$image->setAttribute( 'x', '0' );
		$image->setAttribute( 'y', '0' );
		$image->setAttribute( 'width', (string) $w_mm );
		$image->setAttribute( 'height', (string) $h_mm );
		$image->setAttributeNS( $xlinkns, 'xlink:href', $href );
		$image->setAttribute( 'href', $href );
		$svg->appendChild( $image );

		$bleed = isset( $opts['bleed_mm'] ) ? (float) $opts['bleed_mm'] : 0.0;
		if ( $bleed > 0 && $w_mm > 2 * $bleed && $h_mm > 2 * $bleed ) {
			$rect = $doc->createElementNS( $svgns, 'rect' );
			$rect->setAttribute( 'x', (string) $bleed );
			$rect->setAttribute( 'y', (string) $bleed );
			$rect->setAttribute( 'width', (string) round( $w_mm - 2 * $bleed, 3 ) );
			$rect->setAttribute( 'height', (string) round( $h_mm - 2 * $bleed, 3 ) );
			$rect->setAttribute( 'fill', 'none' );
			$rect->setAttribute( 'stroke', '#ff00ff' );
			$rect->setAttribute( 'stroke-width', '0.2' );
			$rect->setAttribute( 'stroke-dasharray', '2,1' );
			$svg->appendChild( $rect );
		}

		return (string) $doc->saveXML();
	}

	/**
	 * Build a minimal single-page PDF embedding one RGB JPEG at physical print size.
	 *
	 * @param string $jpeg Raw RGB JPEG bytes (DCTDecode).
	 * @param int    $px_w Pixel width.
	 * @param int    $px_h Pixel height.
	 * @param int    $dpi  DPI for physical sizing.
	 * @return string PDF bytes.
	 */
	public static function pdf_single_image_jpeg( $jpeg, $px_w, $px_h, $dpi ) {
		$dpi  = (float) $dpi > 0 ? (float) $dpi : 300.0;
		$pt_w = round( (int) $px_w / $dpi * 72, 2 );
		$pt_h = round( (int) $px_h / $dpi * 72, 2 );
		$px_w = (int) $px_w;
		$px_h = (int) $px_h;
		$ilen = strlen( (string) $jpeg );

		$stream = 'q ' . $pt_w . ' 0 0 ' . $pt_h . ' 0 0 cm /Im0 Do Q';
		$objs   = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pt_w . ' ' . $pt_h . '] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
			5 => '<< /Type /XObject /Subtype /Image /Width ' . $px_w . ' /Height ' . $px_h . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . $ilen . " >>\nstream\n" . $jpeg . "\nendstream",
		);

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objs as $n => $body ) {
			$offsets[ $n ] = strlen( $pdf );
			$pdf          .= $n . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref  = strlen( $pdf );
		$count = count( $objs ) + 1;
		$pdf  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
		for ( $i = 1; $i < $count; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	/**
	 * Flatten an image file onto white and return RGB JPEG bytes (for PDF embedding).
	 *
	 * @param string $path Image path.
	 * @return string JPEG bytes or '' on failure.
	 */
	private static function flatten_to_jpeg( $path ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$info = @getimagesize( $path );
		if ( ! is_array( $info ) ) {
			return '';
		}
		$src = null;
		switch ( $info[2] ) {
			case IMAGETYPE_PNG:
				$src = @imagecreatefrompng( $path );
				break;
			case IMAGETYPE_JPEG:
				$src = @imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_GIF:
				$src = @imagecreatefromgif( $path );
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : null;
				break;
		}
		if ( ! $src ) {
			return '';
		}
		$w    = imagesx( $src );
		$h    = imagesy( $src );
		$flat = imagecreatetruecolor( $w, $h );
		$white = imagecolorallocate( $flat, 255, 255, 255 );
		imagefilledrectangle( $flat, 0, 0, $w, $h, $white );
		imagecopy( $flat, $src, 0, 0, 0, 0, $w, $h );
		ob_start();
		imagejpeg( $flat, null, 92 );
		$jpeg = (string) ob_get_clean();
		imagedestroy( $src );
		imagedestroy( $flat );
		return $jpeg;
	}
}
