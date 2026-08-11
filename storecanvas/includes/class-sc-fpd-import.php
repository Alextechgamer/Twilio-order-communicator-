<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click importer for Fancy Product Designer (FPD) product JSON.
 *
 * FPD is end-of-life on CodeCanyon; this maps an exported FPD product (views + print zones +
 * simple text elements) into a StoreCanvas customizer + options config so stranded stores have a
 * migration path.
 *
 * The mapping core, {@see SC_FPD_Import::map()}, is pure and unit-tested. FPD's export schema
 * varies by version, so the mapper is deliberately tolerant (missing keys fall back to defaults
 * and are reported in `notes`). The admin handler additionally sideloads the view images, which
 * requires a live WordPress runtime.
 */
class SC_FPD_Import {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_sc_fpd_import', array( $this, 'handle_import' ) );
	}

	/* ─────────────────────────  Pure mapping core  ───────────────────────── */

	/**
	 * Map a decoded FPD product export into a StoreCanvas config (pure).
	 *
	 * @param mixed $fpd Decoded FPD JSON (array of views, or an object/array wrapping `views`).
	 * @return array{title:string,customizer:array,options:array,notes:array}
	 */
	public static function map( $fpd ) {
		$views_in = self::extract_views( $fpd );

		$views     = array();
		$areas     = array();
		$fields    = array();
		$notes     = array();
		$seen_text = array();
		$skipped   = 0;

		foreach ( $views_in as $i => $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$n     = $i + 1;
			$vid   = 'view' . $n;
			$label = self::str( $v['title'] ?? '' );
			if ( '' === $label ) {
				$label = 'View ' . $n;
			}

			$image = self::str( $v['thumbnail'] ?? '' );
			if ( '' === $image ) {
				$image = self::base_image( $v );
			}
			$views[] = array(
				'id'        => $vid,
				'label'     => $label,
				'image_id'  => 0,
				'image_url' => $image,
			);

			// Print zone → area, converted to stage-relative percentages.
			list( $sw, $sh ) = self::stage_dims( $v );
			$box             = self::printing_box( $v );
			if ( $box && $sw > 0 && $sh > 0 ) {
				$areas[] = array(
					'id'      => 'area' . $n,
					'view_id' => $vid,
					'label'   => $label,
					'x'       => self::pct( $box['left'], $sw ),
					'y'       => self::pct( $box['top'], $sh ),
					'w'       => self::pct( $box['width'], $sw ),
					'h'       => self::pct( $box['height'], $sh ),
				);
			} else {
				$areas[] = array(
					'id'      => 'area' . $n,
					'view_id' => $vid,
					'label'   => $label,
					'x'       => 20.0,
					'y'       => 20.0,
					'w'       => 60.0,
					'h'       => 60.0,
				);
				$notes[] = sprintf( 'View "%s": no print box found — used a default centered area.', $label );
			}

			// Text elements → text option fields (deduped by label).
			foreach ( self::elements( $v ) as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				$type = strtolower( self::str( $el['type'] ?? '' ) );
				if ( 'text' === $type ) {
					$flabel = self::str( $el['title'] ?? '' );
					if ( '' === $flabel ) {
						$flabel = self::str( $el['parameters']['text'] ?? '' );
					}
					if ( '' === $flabel ) {
						continue;
					}
					$key = strtolower( $flabel );
					if ( isset( $seen_text[ $key ] ) ) {
						continue;
					}
					$seen_text[ $key ] = true;
					$fields[]          = array(
						'id'         => self::slug( $flabel ),
						'type'       => 'text',
						'label'      => $flabel,
						'required'   => false,
						'price_type' => 'none',
						'price'      => 0.0,
					);
				} elseif ( '' !== $type ) {
					++$skipped;
				}
			}
		}

		if ( $skipped > 0 ) {
			$notes[] = sprintf( '%d image/clip-art element(s) were not imported as options (add artwork upload or clip-art fields manually).', $skipped );
		}
		if ( ! $views ) {
			$notes[] = 'No views were found in the FPD data.';
		}

		return array(
			'title'      => self::str( is_array( $fpd ) ? ( $fpd['title'] ?? '' ) : '' ),
			'customizer' => array(
				'enabled' => $views ? 1 : 0,
				'views'   => $views,
				'areas'   => $areas,
			),
			'options'    => array( 'fields' => $fields ),
			'notes'      => $notes,
		);
	}

	/**
	 * Pull the list of view arrays out of the various FPD top-level shapes.
	 *
	 * @param mixed $fpd Decoded FPD JSON.
	 * @return array
	 */
	private static function extract_views( $fpd ) {
		if ( ! is_array( $fpd ) ) {
			return array();
		}
		if ( isset( $fpd['views'] ) && is_array( $fpd['views'] ) ) {
			return $fpd['views'];
		}
		if ( isset( $fpd['product'] ) && is_array( $fpd['product'] ) ) {
			return self::extract_views( $fpd['product'] );
		}
		// A bare list: treat as a list of views if the first element looks like a view.
		$first = reset( $fpd );
		if ( is_array( $first ) && ( isset( $first['elements'] ) || isset( $first['title'] ) || isset( $first['thumbnail'] ) ) ) {
			return array_values( $fpd );
		}
		return array();
	}

	/**
	 * The element list of a view (FPD uses `elements`).
	 *
	 * @param array $view View array.
	 * @return array
	 */
	private static function elements( $view ) {
		return ( isset( $view['elements'] ) && is_array( $view['elements'] ) ) ? $view['elements'] : array();
	}

	/**
	 * Stage width/height for percentage conversion (options.stageWidth/Height, then a base image).
	 *
	 * @param array $view View array.
	 * @return array{0:float,1:float}
	 */
	private static function stage_dims( $view ) {
		$opts = ( isset( $view['options'] ) && is_array( $view['options'] ) ) ? $view['options'] : array();
		$w    = (float) ( $opts['stageWidth'] ?? $view['stageWidth'] ?? 0 );
		$h    = (float) ( $opts['stageHeight'] ?? $view['stageHeight'] ?? 0 );
		if ( $w <= 0 || $h <= 0 ) {
			foreach ( self::elements( $view ) as $el ) {
				$p = ( is_array( $el ) && isset( $el['parameters'] ) && is_array( $el['parameters'] ) ) ? $el['parameters'] : array();
				$ew = (float) ( $p['width'] ?? 0 );
				$eh = (float) ( $p['height'] ?? 0 );
				if ( $ew > $w ) {
					$w = $ew;
				}
				if ( $eh > $h ) {
					$h = $eh;
				}
			}
		}
		return array( $w, $h );
	}

	/**
	 * Find a print/design box: a view-level printingBox, else the first element carrying one.
	 *
	 * @param array $view View array.
	 * @return array{left:float,top:float,width:float,height:float}|null
	 */
	private static function printing_box( $view ) {
		$box = self::normalize_box( $view['printingBox'] ?? null );
		if ( $box ) {
			return $box;
		}
		foreach ( self::elements( $view ) as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$box = self::normalize_box( $el['printingBox'] ?? ( $el['parameters']['printingBox'] ?? null ) );
			if ( $box ) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * Normalize an FPD box (associative left/top/width/height, or [x,y,w,h]) to a float box.
	 *
	 * @param mixed $b Raw box.
	 * @return array{left:float,top:float,width:float,height:float}|null
	 */
	private static function normalize_box( $b ) {
		if ( ! is_array( $b ) ) {
			return null;
		}
		$left   = $b['left'] ?? ( $b['x'] ?? ( $b[0] ?? null ) );
		$top    = $b['top'] ?? ( $b['y'] ?? ( $b[1] ?? null ) );
		$width  = $b['width'] ?? ( $b['w'] ?? ( $b[2] ?? null ) );
		$height = $b['height'] ?? ( $b['h'] ?? ( $b[3] ?? null ) );
		if ( null === $width || null === $height ) {
			return null;
		}
		return array(
			'left'   => (float) $left,
			'top'    => (float) $top,
			'width'  => (float) $width,
			'height' => (float) $height,
		);
	}

	/**
	 * A base/background image URL from the first image element, if any.
	 *
	 * @param array $view View array.
	 * @return string
	 */
	private static function base_image( $view ) {
		foreach ( self::elements( $view ) as $el ) {
			if ( is_array( $el ) && '' !== self::str( $el['source'] ?? '' ) ) {
				return self::str( $el['source'] );
			}
		}
		return '';
	}

	/**
	 * Percentage of a dimension, clamped to [0,100] and rounded to 2 dp.
	 *
	 * @param mixed $value Numerator.
	 * @param float $total Denominator.
	 * @return float
	 */
	private static function pct( $value, $total ) {
		if ( $total <= 0 ) {
			return 0.0;
		}
		$p = (float) $value / $total * 100;
		if ( $p < 0 ) {
			$p = 0.0;
		}
		if ( $p > 100 ) {
			$p = 100.0;
		}
		return round( $p, 2 );
	}

	/**
	 * Slug for an option field id (pure, no WordPress dependency).
	 *
	 * @param string $s Source label.
	 * @return string
	 */
	private static function slug( $s ) {
		$s = strtolower( self::str( $s ) );
		$s = preg_replace( '/[^a-z0-9]+/', '_', $s );
		$s = trim( (string) $s, '_' );
		if ( '' === $s ) {
			return 'field';
		}
		return substr( $s, 0, 32 );
	}

	/**
	 * Trim a scalar to a string; non-scalars become ''.
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	private static function str( $s ) {
		return is_scalar( $s ) ? trim( (string) $s ) : '';
	}

	/* ─────────────────────────  Admin UI + handler  ───────────────────────── */

	/**
	 * @param string $post_type Current post type.
	 */
	public function add_meta_box( $post_type ) {
		if ( 'product' !== $post_type || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		add_meta_box( 'sc-fpd-import', __( 'StoreCanvas: Import from Fancy Product Designer', 'storecanvas' ), array( $this, 'render_meta_box' ), 'product', 'normal', 'low' );
	}

	/**
	 * @param WP_Post $post Product post.
	 */
	public function render_meta_box( $post ) {
		echo '<p>' . esc_html__( 'Paste an exported Fancy Product Designer product JSON to import its views, print areas and text fields into StoreCanvas. Review the result before selling — view images are re-uploaded and coordinates are converted from FPD.', 'storecanvas' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sc_fpd_import_' . $post->ID );
		echo '<input type="hidden" name="action" value="sc_fpd_import" />';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $post->ID ) . '" />';
		echo '<textarea name="fpd_json" rows="8" style="width:100%;font-family:monospace;" placeholder="{ &quot;views&quot;: [ … ] }"></textarea>';
		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Import FPD product', 'storecanvas' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'This overwrites the StoreCanvas customizer and options for this product.', 'storecanvas' ) . '</span></p>';
		echo '</form>';
	}

	/**
	 * Handle the import: map, sideload view images, sanitize fields, save meta.
	 * The mapping is pure; the image sideload requires the WordPress media runtime.
	 */
	public function handle_import() {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		check_admin_referer( 'sc_fpd_import_' . $product_id );

		$raw = isset( $_POST['fpd_json'] ) ? wp_unslash( $_POST['fpd_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$data = json_decode( is_string( $raw ) ? $raw : '', true );
		if ( ! is_array( $data ) ) {
			$this->redirect_back( $product_id, 'error', __( 'Could not parse the FPD JSON.', 'storecanvas' ) );
		}

		$mapped = self::map( $data );

		// Sideload view images (runtime-only).
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		foreach ( $mapped['customizer']['views'] as &$view ) {
			$url = $view['image_url'] ?? '';
			unset( $view['image_url'] );
			if ( $url && function_exists( 'media_sideload_image' ) ) {
				$att = media_sideload_image( esc_url_raw( $url ), $product_id, null, 'id' );
				if ( ! is_wp_error( $att ) ) {
					$view['image_id'] = (int) $att;
				}
			}
		}
		unset( $view );

		// Sanitize option fields through the canonical sanitizer.
		$fields = array();
		foreach ( $mapped['options']['fields'] as $f ) {
			$row = SC_Product_Options::sanitize_field_row( $f );
			if ( ! empty( $row['id'] ) ) {
				$fields[] = $row;
			}
		}

		update_post_meta( $product_id, SC_Plugin::META_CUSTOMIZER, $mapped['customizer'] );
		update_post_meta( $product_id, SC_Plugin::META_OPTIONS, array( 'fields' => $fields ) );

		$msg = sprintf(
			/* translators: 1: view count, 2: field count. */
			__( 'Imported %1$d view(s) and %2$d option field(s) from FPD.', 'storecanvas' ),
			count( $mapped['customizer']['views'] ),
			count( $fields )
		);
		if ( ! empty( $mapped['notes'] ) ) {
			$msg .= ' ' . implode( ' ', array_map( 'wp_strip_all_tags', $mapped['notes'] ) );
		}
		$this->redirect_back( $product_id, 'updated', $msg );
	}

	/**
	 * @param int    $product_id Product ID.
	 * @param string $type       updated|error.
	 * @param string $message    Notice text.
	 */
	private function redirect_back( $product_id, $type, $message ) {
		$url = add_query_arg(
			array(
				'sc_fpd_notice' => rawurlencode( $message ),
				'sc_fpd_type'   => ( 'error' === $type ? 'error' : 'updated' ),
			),
			get_edit_post_link( $product_id, 'url' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
