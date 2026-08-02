<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase C: print-ready generation + validation against product rules.
 * Scaffold only — methods are stubs until 1.16.0 work.
 */
class SC_Print_Ready {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Future: admin order action "Generate print file", AJAX validators.
	}

	/**
	 * Validate an uploaded source image against product rules.
	 *
	 * @param string $file_path Absolute path.
	 * @param array  $rules     Validation rules.
	 * @param array  $area      Print area (for size-at-print estimates).
	 * @return array{ok:bool,errors:string[],meta:array}
	 */
	public function validate_source( $file_path, $rules, $area = array() ) {
		$errors = array();
		$meta   = array();

		if ( ! file_exists( $file_path ) ) {
			return array( 'ok' => false, 'errors' => array( __( 'File not found.', 'storecanvas' ) ), 'meta' => $meta );
		}

		$size_mb = filesize( $file_path ) / ( 1024 * 1024 );
		$max_mb  = isset( $rules['max_upload_mb'] ) ? (float) $rules['max_upload_mb'] : 10;
		if ( $size_mb > $max_mb ) {
			$errors[] = sprintf(
				/* translators: 1: size 2: max */
				__( 'File is %.1f MB; max allowed is %.1f MB.', 'storecanvas' ),
				$size_mb,
				$max_mb
			);
		}

		$ft = wp_check_filetype( $file_path );
		$mime = $ft['type'] ?? '';
		$allowed = isset( $rules['allowed_mimes'] ) ? (array) $rules['allowed_mimes'] : array( 'image/png', 'image/jpeg' );
		if ( $mime && ! in_array( $mime, $allowed, true ) ) {
			$errors[] = __( 'File type is not allowed.', 'storecanvas' );
		}

		$dim = @getimagesize( $file_path );
		if ( is_array( $dim ) ) {
			$meta['width']  = (int) $dim[0];
			$meta['height'] = (int) $dim[1];
			$min_px = isset( $rules['min_source_px'] ) ? (int) $rules['min_source_px'] : 500;
			if ( $meta['width'] < $min_px && $meta['height'] < $min_px ) {
				$errors[] = sprintf(
					/* translators: %d: min pixels */
					__( 'Image is too small; minimum %d px on the long edge recommended.', 'storecanvas' ),
					$min_px
				);
			}
		}

		// DPI-at-print is Phase C (needs physical print size of area).
		return array(
			'ok'     => empty( $errors ),
			'errors' => $errors,
			'meta'   => $meta,
		);
	}

	/**
	 * Placeholder for high-res composite generation.
	 *
	 * @return int|\WP_Error Attachment ID or error.
	 */
	public function generate_composite( $product_id, $placement, $source_attachment_id ) {
		return new WP_Error(
			'sc_not_implemented',
			__( 'Print-ready composite generation ships in Phase C.', 'storecanvas' )
		);
	}
}
