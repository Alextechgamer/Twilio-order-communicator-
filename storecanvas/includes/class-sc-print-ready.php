<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase C (0.4.0): print-ready validation, composite generation, order downloads.
 */
class SC_Print_Ready {

	const META_PRINT_FILES = 'sc_print_files'; // order item meta: [ view_id => attachment_id ]

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'maybe_generate_on_order' ), 20, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_download_links' ), 15, 3 );
		add_action( 'wp_ajax_sc_generate_print_file', array( $this, 'ajax_generate' ) );
	}

	/**
	 * Validate source image; includes estimated DPI when target print width is set.
	 *
	 * @param string $file_path Absolute path.
	 * @param array  $rules     Validation rules.
	 * @param array  $area      Optional area with w % of full design; uses rules target_print_width_in.
	 * @return array{ok:bool,errors:string[],warnings:string[],meta:array}
	 */
	public function validate_source( $file_path, $rules, $area = array() ) {
		$errors   = array();
		$warnings = array();
		$meta     = array();

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array(
				'ok'       => false,
				'errors'   => array( __( 'File not found.', 'storecanvas' ) ),
				'warnings' => array(),
				'meta'     => $meta,
			);
		}

		$size_mb = filesize( $file_path ) / ( 1024 * 1024 );
		$max_mb  = isset( $rules['max_upload_mb'] ) ? (float) $rules['max_upload_mb'] : 10;
		if ( $size_mb > $max_mb ) {
			$errors[] = sprintf(
				__( 'File is %.1f MB; max allowed is %.1f MB.', 'storecanvas' ),
				$size_mb,
				$max_mb
			);
		}

		$ft      = wp_check_filetype( $file_path );
		$mime    = $ft['type'] ?? '';
		$allowed = isset( $rules['allowed_mimes'] ) ? (array) $rules['allowed_mimes'] : array( 'image/png', 'image/jpeg' );
		// wp_check_filetype may miss tmp uploads — also check getimagesize mime.
		$dim = @getimagesize( $file_path );
		if ( is_array( $dim ) && ! empty( $dim['mime'] ) ) {
			$mime = $dim['mime'];
		}
		if ( $mime && ! in_array( $mime, $allowed, true ) ) {
			$errors[] = __( 'File type is not allowed.', 'storecanvas' );
		}

		if ( is_array( $dim ) ) {
			$meta['width']  = (int) $dim[0];
			$meta['height'] = (int) $dim[1];
			$long            = max( $meta['width'], $meta['height'] );
			$min_px         = isset( $rules['min_source_px'] ) ? (int) $rules['min_source_px'] : 500;
			if ( $long < $min_px ) {
				$errors[] = sprintf(
					__( 'Image is too small; minimum %d px on the long edge required.', 'storecanvas' ),
					$min_px
				);
			}

			// Estimated DPI: assume art fills the print area width at target_print_width_in.
			$target_in = isset( $rules['target_print_width_in'] ) ? (float) $rules['target_print_width_in'] : 12.0;
			$min_dpi   = isset( $rules['min_dpi'] ) ? (float) $rules['min_dpi'] : 150;
			if ( $target_in > 0 && $meta['width'] > 0 ) {
				// If area width % is known, effective print inches for art ≈ target_in * (area.w/100).
				$area_frac = 1.0;
				if ( ! empty( $area['w'] ) ) {
					$area_frac = max( 0.05, min( 1.0, (float) $area['w'] / 100 ) );
				}
				$print_in         = $target_in * $area_frac;
				$est_dpi          = $meta['width'] / $print_in;
				$meta['est_dpi']  = round( $est_dpi, 1 );
				$meta['print_in'] = round( $print_in, 2 );
				if ( $est_dpi < $min_dpi ) {
					$errors[] = sprintf(
						__( 'Estimated print resolution is ~%1$s DPI (need at least %2$s DPI at %3$s″ width). Upload a larger image.', 'storecanvas' ),
						(string) $meta['est_dpi'],
						(string) $min_dpi,
						(string) $meta['print_in']
					);
				} elseif ( $est_dpi < $min_dpi * 1.2 ) {
					$warnings[] = sprintf(
						__( 'Estimated DPI is ~%s — acceptable but close to the minimum.', 'storecanvas' ),
						(string) $meta['est_dpi']
					);
				}
			}
		} else {
			$errors[] = __( 'Could not read image dimensions.', 'storecanvas' );
		}

		// Color mode (RGB) check when detectable.
		$color = $this->detect_color_mode( $file_path, $dim ?? null );
		if ( $color ) {
			$meta['color_mode'] = $color;
		}
		$require_rgb = ! empty( $rules['require_rgb'] );
		if ( $require_rgb && $color && ! in_array( $color, array( 'rgb', 'rgba', 'truecolor', 'unknown' ), true ) ) {
			$msg = sprintf(
				__( 'Artwork color mode is %s; RGB is required for print.', 'storecanvas' ),
				strtoupper( $color )
			);
			if ( in_array( $color, array( 'cmyk', 'gray', 'grayscale', 'indexed' ), true ) ) {
				$errors[] = $msg;
			}
		}

		// Optional placement bleed check (when placement + area provided).
		if ( ! empty( $rules['_placement'] ) && is_array( $rules['_placement'] ) ) {
			$bleed = $this->check_placement_bleed( $rules['_placement'], $area, $rules );
			$meta  = array_merge( $meta, $bleed['meta'] );
			if ( $bleed['errors'] ) {
				if ( ! empty( $rules['strict_bleed'] ) ) {
					$errors = array_merge( $errors, $bleed['errors'] );
				} else {
					$warnings = array_merge( $warnings, $bleed['errors'] );
				}
			}
			if ( $bleed['warnings'] ) {
				$warnings = array_merge( $warnings, $bleed['warnings'] );
			}
		}

		return array(
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
			'meta'     => $meta,
		);
	}

	/**
	 * Detect color mode for a raster file when possible.
	 *
	 * @param string     $path File path.
	 * @param array|null $dim  getimagesize result.
	 * @return string|null rgb|rgba|cmyk|gray|indexed|unknown|null
	 */
	public function detect_color_mode( $path, $dim = null ) {
		if ( ! is_array( $dim ) ) {
			$dim = @getimagesize( $path );
		}
		if ( ! is_array( $dim ) ) {
			return null;
		}
		// channels: 3=RGB, 4=CMYK or RGBA depending on type, 1=gray.
		if ( ! empty( $dim['channels'] ) ) {
			$ch = (int) $dim['channels'];
			if ( 1 === $ch ) {
				return 'gray';
			}
			if ( 4 === $ch && ! empty( $dim[2] ) && IMAGETYPE_JPEG === $dim[2] ) {
				return 'cmyk';
			}
			if ( 4 === $ch ) {
				return 'rgba';
			}
			if ( 3 === $ch ) {
				return 'rgb';
			}
		}
		if ( function_exists( 'exif_read_data' ) && ! empty( $dim[2] ) && IMAGETYPE_JPEG === $dim[2] ) {
			$exif = @exif_read_data( $path );
			if ( is_array( $exif ) && isset( $exif['ColorSpace'] ) ) {
				// 1 = sRGB
				if ( 1 === (int) $exif['ColorSpace'] ) {
					return 'rgb';
				}
			}
			if ( is_array( $exif ) && ! empty( $exif['ComponentsConfiguration'] ) ) {
				return 'rgb';
			}
		}
		// PNG color type via GD if available.
		if ( ! empty( $dim[2] ) && IMAGETYPE_PNG === $dim[2] && function_exists( 'imagecreatefrompng' ) ) {
			$im = @imagecreatefrompng( $path );
			if ( $im ) {
				$tc = imageistruecolor( $im );
				imagedestroy( $im );
				return $tc ? 'truecolor' : 'indexed';
			}
		}
		return 'unknown';
	}

	/**
	 * Check whether placement keeps artwork inside bleed inset of the print area.
	 * Placement x,y are 0–100 relative to area; scale 1 = 50% of area width.
	 *
	 * @param array $placement Placement.
	 * @param array $area      Area with w/h %.
	 * @param array $rules     Validation rules.
	 * @return array{errors:string[],warnings:string[],meta:array}
	 */
	public function check_placement_bleed( $placement, $area, $rules ) {
		$errors   = array();
		$warnings = array();
		$meta     = array();
		$bleed    = isset( $rules['bleed_pct'] ) ? (float) $rules['bleed_pct'] : 3.0;
		$safe     = isset( $rules['safe_margin_pct'] ) ? (float) $rules['safe_margin_pct'] : 5.0;
		// Effective inset = max(bleed, optional min expressed as % of area).
		$inset = max( 0.0, $bleed );
		$min_px = isset( $rules['min_bleed_px'] ) ? (float) $rules['min_bleed_px'] : 0.0;
		// Without pixel area size, treat min_bleed_px as soft note only.
		$meta['bleed_pct'] = $inset;
		$meta['safe_margin_pct'] = $safe;

		$scale = isset( $placement['scale'] ) ? (float) $placement['scale'] : 1.0;
		$scale = max( 0.1, min( 3.0, $scale ) );
		// Art width as % of area = 50 * scale; height unknown without image ratio — assume square worst-case.
		$art_w_pct = 50.0 * $scale;
		$art_h_pct = $art_w_pct;
		if ( ! empty( $placement['_art_ratio'] ) ) {
			$art_h_pct = $art_w_pct * (float) $placement['_art_ratio'];
		}
		$cx = isset( $placement['x'] ) ? (float) $placement['x'] : 50.0;
		$cy = isset( $placement['y'] ) ? (float) $placement['y'] : 50.0;
		$left   = $cx - $art_w_pct / 2;
		$right  = $cx + $art_w_pct / 2;
		$top    = $cy - $art_h_pct / 2;
		$bottom = $cy + $art_h_pct / 2;
		$meta['art_bounds_pct'] = array( $left, $top, $right, $bottom );

		// Must stay within [inset, 100-inset].
		if ( $left < $inset || $top < $inset || $right > ( 100 - $inset ) || $bottom > ( 100 - $inset ) ) {
			$errors[] = sprintf(
				__( 'Artwork extends outside the %s%% bleed inset of the print area. Move or scale down.', 'storecanvas' ),
				(string) $inset
			);
		} elseif ( $left < $safe || $top < $safe || $right > ( 100 - $safe ) || $bottom > ( 100 - $safe ) ) {
			$warnings[] = sprintf(
				__( 'Artwork is outside the %s%% safe margin (still within bleed).', 'storecanvas' ),
				(string) $safe
			);
		}
		if ( $min_px > 0 ) {
			$meta['min_bleed_px'] = $min_px;
		}
		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'meta'     => $meta,
		);
	}

	/**
	 * Sideload an uploaded file into the media library.
	 *
	 * @param array $file $_FILES element.
	 * @return int|\WP_Error Attachment ID.
	 */
	public function sideload_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'sc_no_file', __( 'No artwork uploaded.', 'storecanvas' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'gif'      => 'image/gif',
				'webp'     => 'image/webp',
			),
		);

		$move = wp_handle_upload( $file, $overrides );
		if ( isset( $move['error'] ) ) {
			return new WP_Error( 'sc_upload', $move['error'] );
		}

		$attachment = array(
			'post_mime_type' => $move['type'],
			'post_title'     => sanitize_file_name( basename( $move['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$att_id = wp_insert_attachment( $attachment, $move['file'] );
		if ( is_wp_error( $att_id ) || ! $att_id ) {
			return new WP_Error( 'sc_attach', __( 'Could not create attachment.', 'storecanvas' ) );
		}
		$meta = wp_generate_attachment_metadata( $att_id, $move['file'] );
		wp_update_attachment_metadata( $att_id, $meta );
		return (int) $att_id;
	}

	/**
	 * Generate a composite PNG for one view.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $placement  Placement for this view (x,y,scale,area_id,view_id).
	 * @param int   $art_id     Artwork attachment ID.
	 * @param string $view_id   View ID.
	 * @return int|\WP_Error Attachment ID of composite.
	 */
	public function generate_composite( $product_id, $placement, $art_id, $view_id = '', $layers = array() ) {
		$config = SC_Customizer::get_config( $product_id );
		$views  = (array) ( $config['views'] ?? array() );
		$areas  = (array) ( $config['areas'] ?? array() );

		$view = null;
		foreach ( $views as $v ) {
			if ( ( $v['id'] ?? '' ) === $view_id || ( ! $view_id && $view === null ) ) {
				$view = $v;
				if ( $view_id ) {
					break;
				}
			}
		}
		if ( ! $view && $views ) {
			$view = $views[0];
			$view_id = $view['id'] ?? '';
		}
		if ( ! $view || empty( $view['image_id'] ) ) {
			return new WP_Error( 'sc_no_view', __( 'No base view image configured.', 'storecanvas' ) );
		}

		$area = null;
		foreach ( $areas as $a ) {
			if ( ( $a['view_id'] ?? '' ) === $view_id ) {
				$area = $a;
				break;
			}
		}
		if ( ! $area && $areas ) {
			$area = $areas[0];
		}
		if ( ! $area ) {
			return new WP_Error( 'sc_no_area', __( 'No print area configured.', 'storecanvas' ) );
		}

		$base_path = get_attached_file( (int) $view['image_id'] );
		$art_path  = get_attached_file( (int) $art_id );
		if ( ! $base_path || ! file_exists( $base_path ) || ! $art_path || ! file_exists( $art_path ) ) {
			return new WP_Error( 'sc_missing_files', __( 'Base or artwork file missing.', 'storecanvas' ) );
		}

		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return new WP_Error( 'sc_no_gd', __( 'PHP GD is required to generate print files.', 'storecanvas' ) );
		}

		$base = $this->gd_load( $base_path );
		$art  = $this->gd_load( $art_path );
		if ( ! $base || ! $art ) {
			return new WP_Error( 'sc_gd_load', __( 'Could not load images for composite.', 'storecanvas' ) );
		}

		$bw = imagesx( $base );
		$bh = imagesy( $base );

		// Upscale base long edge to at least 3000px for print when smaller.
		$target_long = 3000;
		$long        = max( $bw, $bh );
		if ( $long < $target_long ) {
			$factor = $target_long / $long;
			$nw     = (int) round( $bw * $factor );
			$nh     = (int) round( $bh * $factor );
			$scaled = imagecreatetruecolor( $nw, $nh );
			imagealphablending( $scaled, false );
			imagesavealpha( $scaled, true );
			imagecopyresampled( $scaled, $base, 0, 0, 0, 0, $nw, $nh, $bw, $bh );
			imagedestroy( $base );
			$base = $scaled;
			$bw   = $nw;
			$bh   = $nh;
		}

		imagealphablending( $base, true );
		imagesavealpha( $base, true );

		$ax = ( (float) $area['x'] / 100 ) * $bw;
		$ay = ( (float) $area['y'] / 100 ) * $bh;
		$aw = ( (float) $area['w'] / 100 ) * $bw;
		$ah = ( (float) $area['h'] / 100 ) * $bh;

		$scale = isset( $placement['scale'] ) ? (float) $placement['scale'] : 1.0;
		$scale = max( 0.1, min( 3.0, $scale ) );
		$art_w = $aw * 0.5 * $scale;
		$art_h = $art_w * ( imagesy( $art ) / max( 1, imagesx( $art ) ) );

		$px = $ax + ( ( (float) ( $placement['x'] ?? 50 ) ) / 100 ) * $aw - $art_w / 2;
		$py = $ay + ( ( (float) ( $placement['y'] ?? 50 ) ) / 100 ) * $ah - $art_h / 2;

		$art_resized = imagecreatetruecolor( (int) $art_w, (int) $art_h );
		imagealphablending( $art_resized, false );
		imagesavealpha( $art_resized, true );
		$transparent = imagecolorallocatealpha( $art_resized, 0, 0, 0, 127 );
		imagefilledrectangle( $art_resized, 0, 0, (int) $art_w, (int) $art_h, $transparent );
		imagealphablending( $art_resized, true );
		imagecopyresampled( $art_resized, $art, 0, 0, 0, 0, (int) $art_w, (int) $art_h, imagesx( $art ), imagesy( $art ) );

		imagecopy( $base, $art_resized, (int) $px, (int) $py, 0, 0, (int) $art_w, (int) $art_h );
		imagedestroy( $art_resized );
		imagedestroy( $art );

		// Extra library image layers + text layers (0.7.0).
		if ( $layers ) {
			$this->gd_draw_image_layers( $base, $layers, $view_id, $area, $bw, $bh, (int) $art_id );
			$this->gd_draw_text_layers( $base, $layers, $view_id, $area, $bw, $bh );
		}

		$upload = wp_upload_dir();
		$fname  = 'sc-print-' . $product_id . '-' . sanitize_file_name( $view_id ) . '-' . wp_generate_password( 6, false ) . '.png';
		$out    = trailingslashit( $upload['path'] ) . $fname;
		imagepng( $base, $out, 6 );
		imagedestroy( $base );

		if ( ! file_exists( $out ) ) {
			return new WP_Error( 'sc_write', __( 'Could not write print file.', 'storecanvas' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => sprintf( 'StoreCanvas print – product %d – %s', $product_id, $view_id ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$att_id = wp_insert_attachment( $attachment, $out );
		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}
		$meta = wp_generate_attachment_metadata( $att_id, $out );
		wp_update_attachment_metadata( $att_id, $meta );
		return (int) $att_id;
	}


	/**
	 * Path to bundled TTF for text composites, or empty.
	 *
	 * @return string
	 */
	public static function font_path() {
		$candidates = array(
			SC_PLUGIN_DIR . 'assets/fonts/sc-sans.ttf',
			SC_PLUGIN_DIR . 'assets/fonts/DejaVuSans.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
		);
		foreach ( $candidates as $c ) {
			if ( $c && file_exists( $c ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * Draw text layers onto a GD base image for one view.
	 *
	 * @param resource|\GdImage $base    Base image.
	 * @param array             $layers  Layer list from CART_LAYERS.
	 * @param string            $view_id View id.
	 * @param array             $area    Area x,y,w,h %.
	 * @param int               $bw      Base width.
	 * @param int               $bh      Base height.
	 */
	public function gd_draw_text_layers( $base, $layers, $view_id, $area, $bw, $bh ) {
		if ( ! is_array( $layers ) || ! $area ) {
			return;
		}
		$ax = ( (float) $area['x'] / 100 ) * $bw;
		$ay = ( (float) $area['y'] / 100 ) * $bh;
		$aw = ( (float) $area['w'] / 100 ) * $bw;
		$ah = ( (float) $area['h'] / 100 ) * $bh;
		$font = self::font_path();
		$use_ttf = $font && function_exists( 'imagettftext' );

		foreach ( $layers as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}
			$type = $layer['type'] ?? 'image';
			if ( 'text' !== $type ) {
				continue;
			}
			$content = isset( $layer['content'] ) ? (string) $layer['content'] : '';
			if ( '' === $content ) {
				continue;
			}
			$pl = array();
			if ( ! empty( $layer['placements'][ $view_id ] ) && is_array( $layer['placements'][ $view_id ] ) ) {
				$pl = $layer['placements'][ $view_id ];
			} elseif ( ! empty( $layer['placementByView'][ $view_id ] ) && is_array( $layer['placementByView'][ $view_id ] ) ) {
				$pl = $layer['placementByView'][ $view_id ];
			} else {
				$pl = array( 'x' => 50, 'y' => 50, 'scale' => 1, 'rotation' => 0 );
			}
			$scale    = isset( $pl['scale'] ) ? max( 0.2, min( 3.0, (float) $pl['scale'] ) ) : 1.0;
			$rotation = isset( $pl['rotation'] ) ? (float) $pl['rotation'] : 0.0;
			$cx       = $ax + ( ( (float) ( $pl['x'] ?? 50 ) ) / 100 ) * $aw;
			$cy       = $ay + ( ( (float) ( $pl['y'] ?? 50 ) ) / 100 ) * $ah;
			$font_size_ui = isset( $layer['fontSize'] ) ? (float) $layer['fontSize'] : 28.0;
			// Map UI font size (canvas-ish) to print px: scale with area width.
			$pt = max( 10, (int) round( $font_size_ui * $scale * ( $aw / 400 ) ) );
			$fill = isset( $layer['fill'] ) ? (string) $layer['fill'] : '#111111';
			$rgb  = $this->parse_hex_color( $fill );
			$color = imagecolorallocate( $base, $rgb[0], $rgb[1], $rgb[2] );

			if ( $use_ttf ) {
				// imagettftext angle is counter-clockwise degrees.
				$angle = -$rotation;
				$bbox  = imagettfbbox( $pt, $angle, $font, $content );
				if ( is_array( $bbox ) ) {
					$minx = min( $bbox[0], $bbox[2], $bbox[4], $bbox[6] );
					$maxx = max( $bbox[0], $bbox[2], $bbox[4], $bbox[6] );
					$miny = min( $bbox[1], $bbox[3], $bbox[5], $bbox[7] );
					$maxy = max( $bbox[1], $bbox[3], $bbox[5], $bbox[7] );
					$tw = $maxx - $minx;
					$th = $maxy - $miny;
					$tx = (int) round( $cx - $tw / 2 - $minx );
					$ty = (int) round( $cy + $th / 2 - $maxy );
				} else {
					$tx = (int) $cx;
					$ty = (int) $cy;
				}
				@imagettftext( $base, $pt, $angle, $tx, $ty, $color, $font, $content );
			} else {
				// GD built-in font fallback (no rotation, approximate size).
				$font_id = 5;
				$tw = imagefontwidth( $font_id ) * strlen( $content );
				$th = imagefontheight( $font_id );
				imagestring( $base, $font_id, (int) ( $cx - $tw / 2 ), (int) ( $cy - $th / 2 ), $content, $color );
			}
		}
	}

	/**
	 * @param string $hex Color.
	 * @return array{0:int,1:int,2:int}
	 */
	private function parse_hex_color( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return array( 17, 17, 17 );
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Draw image layers from library/upload meta onto base (attachment id or skip).
	 * Primary artwork is already drawn via $art_id; extra image layers use clipartUrl path if server-side file known.
	 * For 0.7.0, library layers store clipart_id; resolve attachment from clipart post.
	 *
	 * @param resource|\GdImage $base Base.
	 * @param array             $layers Layers.
	 * @param string            $view_id View.
	 * @param array             $area Area.
	 * @param int               $bw Width.
	 * @param int               $bh Height.
	 * @param int               $skip_art_id Skip primary art already drawn.
	 */
	public function gd_draw_image_layers( $base, $layers, $view_id, $area, $bw, $bh, $skip_art_id = 0 ) {
		if ( ! is_array( $layers ) || ! $area ) {
			return;
		}
		$ax = ( (float) $area['x'] / 100 ) * $bw;
		$ay = ( (float) $area['y'] / 100 ) * $bh;
		$aw = ( (float) $area['w'] / 100 ) * $bw;
		$ah = ( (float) $area['h'] / 100 ) * $bh;

		foreach ( $layers as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}
			$type = $layer['type'] ?? 'image';
			if ( 'image' !== $type && 'clipart' !== $type ) {
				continue;
			}
			$art_id = 0;
			if ( ! empty( $layer['attachment_id'] ) ) {
				$art_id = (int) $layer['attachment_id'];
			} elseif ( ! empty( $layer['clipart_id'] ) && class_exists( 'SC_Clipart' ) ) {
				$cid = (int) $layer['clipart_id'];
				$art_id = (int) get_post_meta( $cid, '_sc_clipart_attachment', true );
				if ( ! $art_id ) {
					$art_id = (int) get_post_thumbnail_id( $cid );
				}
			}
			if ( ! $art_id || $art_id === (int) $skip_art_id ) {
				// Primary upload already drawn; skip duplicate. Library-only without id skipped if no file.
				if ( empty( $layer['clipart_id'] ) && empty( $layer['attachment_id'] ) ) {
					continue;
				}
				if ( ! $art_id ) {
					continue;
				}
				if ( $art_id === (int) $skip_art_id ) {
					continue;
				}
			}
			$path = get_attached_file( $art_id );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}
			$art = $this->gd_load( $path );
			if ( ! $art ) {
				continue;
			}
			$pl = array();
			if ( ! empty( $layer['placements'][ $view_id ] ) ) {
				$pl = $layer['placements'][ $view_id ];
			} elseif ( ! empty( $layer['placementByView'][ $view_id ] ) ) {
				$pl = $layer['placementByView'][ $view_id ];
			} else {
				$pl = array( 'x' => 50, 'y' => 50, 'scale' => 1 );
			}
			$scale = isset( $pl['scale'] ) ? max( 0.1, min( 3.0, (float) $pl['scale'] ) ) : 1.0;
			$art_w = $aw * 0.5 * $scale;
			$art_h = $art_w * ( imagesy( $art ) / max( 1, imagesx( $art ) ) );
			$px = $ax + ( ( (float) ( $pl['x'] ?? 50 ) ) / 100 ) * $aw - $art_w / 2;
			$py = $ay + ( ( (float) ( $pl['y'] ?? 50 ) ) / 100 ) * $ah - $art_h / 2;
			$art_resized = imagecreatetruecolor( max( 1, (int) $art_w ), max( 1, (int) $art_h ) );
			imagealphablending( $art_resized, false );
			imagesavealpha( $art_resized, true );
			$transparent = imagecolorallocatealpha( $art_resized, 0, 0, 0, 127 );
			imagefilledrectangle( $art_resized, 0, 0, (int) $art_w, (int) $art_h, $transparent );
			imagealphablending( $art_resized, true );
			imagecopyresampled( $art_resized, $art, 0, 0, 0, 0, (int) $art_w, (int) $art_h, imagesx( $art ), imagesy( $art ) );
			imagecopy( $base, $art_resized, (int) $px, (int) $py, 0, 0, (int) $art_w, (int) $art_h );
			imagedestroy( $art_resized );
			imagedestroy( $art );
		}
	}

	/**
	 * @param string $path File path.
	 * @return resource|false
	 */
	private function gd_load( $path ) {
		$info = @getimagesize( $path );
		if ( ! is_array( $info ) ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $path );
			case IMAGETYPE_PNG:
				$im = imagecreatefrompng( $path );
				if ( $im ) {
					imagealphablending( $im, true );
					imagesavealpha( $im, true );
				}
				return $im;
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $path );
			case IMAGETYPE_WEBP:
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					return imagecreatefromwebp( $path );
				}
				return false;
			default:
				return false;
		}
	}

	/**
	 * After line item created: generate print files when artwork attachment present.
	 */
	public function maybe_generate_on_order( $item, $cart_item_key, $values, $order ) {
		$art_id = 0;
		if ( ! empty( $values[ SC_Plugin::CART_ATTACHMENTS ]['artwork'] ) ) {
			$art_id = (int) $values[ SC_Plugin::CART_ATTACHMENTS ]['artwork'];
		}
		$layers = array();
		if ( ! empty( $values[ SC_Plugin::CART_LAYERS ] ) && is_array( $values[ SC_Plugin::CART_LAYERS ] ) ) {
			$layers = $values[ SC_Plugin::CART_LAYERS ];
		}
		$has_text = false;
		foreach ( $layers as $L ) {
			if ( is_array( $L ) && ( $L['type'] ?? '' ) === 'text' && ! empty( $L['content'] ) ) {
				$has_text = true;
				break;
			}
		}
		if ( ! $art_id && ! $has_text && ! $layers ) {
			return;
		}

		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			if ( $order instanceof WC_Order ) {
				$order->add_order_note(
					__( 'StoreCanvas: print composites skipped (PHP GD not available).', 'storecanvas' ),
					false,
					true
				);
			}
			return;
		}

		$product_id = $item->get_product_id();
		$placement  = isset( $values[ SC_Plugin::CART_PLACEMENT ] ) ? $values[ SC_Plugin::CART_PLACEMENT ] : array();
		$files      = array();

		$placements = array();
		if ( ! empty( $placement['placements'] ) && is_array( $placement['placements'] ) ) {
			$placements = $placement['placements'];
		} elseif ( ! empty( $placement['view_id'] ) ) {
			$placements[ $placement['view_id'] ] = $placement;
		} else {
			$placements['default'] = $placement;
		}

		foreach ( $placements as $vid => $pl ) {
			$vid_s = is_string( $vid ) ? $vid : ( $pl['view_id'] ?? '' );
			if ( $art_id ) {
				$att = $this->generate_composite( $product_id, $pl, $art_id, $vid_s, $layers );
			} else {
				$att = $this->generate_composite_layers_only( $product_id, $pl, $vid_s, $layers );
			}
			if ( ! is_wp_error( $att ) && $att ) {
				$files[ $vid_s ? $vid_s : 'view' ] = $att;
			}
		}

		if ( $files ) {
			$item->add_meta_data( self::META_PRINT_FILES, $files, true );
			if ( $art_id ) {
				$item->add_meta_data( '_sc_artwork_id', $art_id, true );
			}
		}
	}

	/**
	 * Composite when there is no primary artwork attachment (text/clipart only).
	 *
	 * @param int    $product_id Product.
	 * @param array  $placement Placement.
	 * @param string $view_id View.
	 * @param array  $layers Layers.
	 * @return int|\WP_Error
	 */
	public function generate_composite_layers_only( $product_id, $placement, $view_id, $layers ) {
		$config = SC_Customizer::get_config( $product_id );
		$views  = (array) ( $config['views'] ?? array() );
		$areas  = (array) ( $config['areas'] ?? array() );
		$view = null;
		foreach ( $views as $v ) {
			if ( ( $v['id'] ?? '' ) === $view_id || ( ! $view_id && $view === null ) ) {
				$view = $v;
				if ( $view_id ) {
					break;
				}
			}
		}
		if ( ! $view && $views ) {
			$view = $views[0];
			$view_id = $view['id'] ?? '';
		}
		if ( ! $view || empty( $view['image_id'] ) ) {
			return new WP_Error( 'sc_no_view', __( 'No base view image configured.', 'storecanvas' ) );
		}
		$area = null;
		foreach ( $areas as $a ) {
			if ( ( $a['view_id'] ?? '' ) === $view_id ) {
				$area = $a;
				break;
			}
		}
		if ( ! $area && $areas ) {
			$area = $areas[0];
		}
		if ( ! $area ) {
			return new WP_Error( 'sc_no_area', __( 'No print area configured.', 'storecanvas' ) );
		}
		$base_path = get_attached_file( (int) $view['image_id'] );
		if ( ! $base_path || ! file_exists( $base_path ) ) {
			return new WP_Error( 'sc_missing_files', __( 'Base file missing.', 'storecanvas' ) );
		}
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return new WP_Error( 'sc_no_gd', __( 'PHP GD is required to generate print files.', 'storecanvas' ) );
		}
		$base = $this->gd_load( $base_path );
		if ( ! $base ) {
			return new WP_Error( 'sc_gd_load', __( 'Could not load base image.', 'storecanvas' ) );
		}
		$bw = imagesx( $base );
		$bh = imagesy( $base );
		$target_long = 3000;
		$long = max( $bw, $bh );
		if ( $long < $target_long ) {
			$factor = $target_long / $long;
			$nw = (int) round( $bw * $factor );
			$nh = (int) round( $bh * $factor );
			$scaled = imagecreatetruecolor( $nw, $nh );
			imagealphablending( $scaled, false );
			imagesavealpha( $scaled, true );
			imagecopyresampled( $scaled, $base, 0, 0, 0, 0, $nw, $nh, $bw, $bh );
			imagedestroy( $base );
			$base = $scaled;
			$bw = $nw;
			$bh = $nh;
		}
		imagealphablending( $base, true );
		imagesavealpha( $base, true );
		$this->gd_draw_image_layers( $base, $layers, $view_id, $area, $bw, $bh, 0 );
		$this->gd_draw_text_layers( $base, $layers, $view_id, $area, $bw, $bh );
		$upload = wp_upload_dir();
		$fname  = 'sc-print-' . $product_id . '-' . sanitize_file_name( $view_id ) . '-' . wp_generate_password( 6, false ) . '.png';
		$out    = trailingslashit( $upload['path'] ) . $fname;
		imagepng( $base, $out, 6 );
		imagedestroy( $base );
		if ( ! file_exists( $out ) ) {
			return new WP_Error( 'sc_write', __( 'Could not write print file.', 'storecanvas' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => sprintf( 'StoreCanvas print – product %d – %s', $product_id, $view_id ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$att_id = wp_insert_attachment( $attachment, $out );
		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}
		$meta = wp_generate_attachment_metadata( $att_id, $out );
		wp_update_attachment_metadata( $att_id, $meta );
		return (int) $att_id;
	}

	/**
	 * Admin order item: download original + print composites.
	 */
	public function admin_download_links( $item_id, $item, $product ) {
		$files  = $item->get_meta( self::META_PRINT_FILES );
		$art_id = (int) $item->get_meta( '_sc_artwork_id' );
		if ( ! $files && ! $art_id ) {
			return;
		}
		echo '<div class="sc-print-files" style="margin-top:8px;">';
		echo '<strong>' . esc_html__( 'Print files', 'storecanvas' ) . '</strong><ul style="margin:4px 0 0 1em;">';
		if ( $art_id ) {
			$url = wp_get_attachment_url( $art_id );
			if ( $url ) {
				echo '<li><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'Original artwork', 'storecanvas' ) . '</a></li>';
			}
		}
		if ( is_array( $files ) ) {
			foreach ( $files as $vid => $fid ) {
				$url = wp_get_attachment_url( (int) $fid );
				if ( $url ) {
					echo '<li><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( sprintf( __( 'Print composite (%s)', 'storecanvas' ), $vid ) ) . '</a></li>';
				}
			}
		}
		echo '</ul></div>';
	}

	public function ajax_generate() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'sc_admin', 'nonce' );
		$item_id    = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$art_id     = isset( $_POST['art_id'] ) ? absint( $_POST['art_id'] ) : 0;
		if ( ! $product_id || ! $art_id ) {
			wp_send_json_error( array( 'message' => 'missing params' ) );
		}
		$att = $this->generate_composite( $product_id, array( 'x' => 50, 'y' => 50, 'scale' => 1 ), $art_id, '' );
		if ( is_wp_error( $att ) ) {
			wp_send_json_error( array( 'message' => $att->get_error_message() ) );
		}
		wp_send_json_success( array( 'attachment_id' => $att, 'url' => wp_get_attachment_url( $att ) ) );
	}
}
