<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin helpers and constants for meta keys.
 *
 * Product meta:
 * - META_OPTIONS     (_sc_options)     — option field definitions
 * - META_CUSTOMIZER  (_sc_customizer)  — views, areas, enabled flag
 * - META_VALIDATION  (_sc_validation)  — DPI/bleed/RGB rules
 * - META_CLIPART     (_sc_clipart_ids) — product clip-art allow-list (empty = all)
 *
 * Cart / order item keys (also used as order item meta where applicable):
 * - CART_OPTIONS     (sc_options)
 * - CART_PLACEMENT   (sc_placement)
 * - CART_ATTACHMENTS (sc_attachments)
 * - CART_LAYERS      (sc_layers)
 * - sc_price_extra, sc_print_files, _sc_artwork_id, sc_preview_id (see SC_Print_Ready / SC_Cart_Order)
 *
 * Public AJAX (nopriv + nonce): library items, guest design save/load/email, journey log.
 * Admin AJAX / admin-post: print generate, bulk ZIP, print sheet (capability-checked).
 */
class SC_Plugin {

	const META_OPTIONS     = '_sc_options';
	const META_CUSTOMIZER  = '_sc_customizer';
	const META_VALIDATION  = '_sc_validation';
	const META_CLIPART     = '_sc_clipart_ids'; // product: array of clipart post IDs, empty = all
	const META_OPTION_GROUPS = '_sc_option_group_ids'; // product: assigned global group IDs
	const CPT_OPTION_GROUP = 'sc_option_group';

	/** Cart/order item keys */
	const CART_OPTIONS     = 'sc_options';
	const CART_PLACEMENT   = 'sc_placement';
	const CART_ATTACHMENTS = 'sc_attachments';
	const CART_LAYERS      = 'sc_layers';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	public static function default_validation() {
		return array(
			'min_dpi'               => 150,
			'max_upload_mb'         => 10,
			'allowed_mimes'         => array( 'image/png', 'image/jpeg', 'image/svg+xml' ),
			'min_source_px'         => 500,
			'safe_margin_pct'       => 5,
			'target_print_width_in' => 12,
			'bleed_pct'             => 3,
			'min_bleed_px'          => 0,
			'require_rgb'           => true,
			'strict_bleed'          => false,
		);
	}

	public static function empty_customizer() {
		return array(
			'enabled' => 0,
			'views'   => array(),
			'areas'   => array(),
		);
	}

	public static function empty_options() {
		return array(
			'fields' => array(),
		);
	}

	public function enqueue_front() {
		if ( ! is_product() ) {
			return;
		}
		global $product;
		$product_id = 0;
		if ( $product instanceof WC_Product ) {
			$product_id = $product->get_id();
		} elseif ( get_the_ID() ) {
			$product_id = (int) get_the_ID();
		}

		wp_enqueue_style(
			'sc-front',
			SC_PLUGIN_URL . 'assets/admin.css',
			array(),
			SC_VERSION
		);

		// Live price: independent of customizer (options-only products still get updates).
		$pricing_fields = $product_id ? SC_Product_Options::pricing_config_for_product( $product_id ) : array();
		if ( $pricing_fields ) {
			$wc_product = $product_id ? wc_get_product( $product_id ) : null;
			$base       = $wc_product ? (float) $wc_product->get_price() : 0.0;
			wp_enqueue_script(
				'sc-live-price',
				SC_PLUGIN_URL . 'assets/live-price.js',
				array(),
				SC_VERSION,
				true
			);
			wp_localize_script(
				'sc-live-price',
				'scLivePrice',
				array(
					'productId'  => $product_id,
					'basePrice'  => $base,
					'fields'     => $pricing_fields,
					'symbol'     => get_woocommerce_currency_symbol(),
					'currencyPos'=> get_option( 'woocommerce_currency_pos', 'left' ),
					'decimals'   => wc_get_price_decimals(),
					'i18n'       => array(
						'base'   => __( 'Base', 'storecanvas' ),
						'extras' => __( 'options', 'storecanvas' ),
						'line'   => __( 'line', 'storecanvas' ),
					),
				)
			);
		}

		wp_enqueue_script(
			'sc-customizer',
			SC_PLUGIN_URL . 'assets/customizer.js',
			array( 'jquery' ),
			SC_VERSION,
			true
		);
		wp_localize_script(
			'sc-customizer',
			'scCustomizer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sc_customizer' ),
				'i18n'    => array(
					'upload'   => __( 'Upload artwork', 'storecanvas' ),
					'apply'    => __( 'Apply placement', 'storecanvas' ),
					'reset'    => __( 'Reset', 'storecanvas' ),
					'noArea'   => __( 'No print area configured.', 'storecanvas' ),
				),
			)
		);
		$design_token = '';
		if ( ! empty( $_GET['sc_design'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$design_token = sanitize_text_field( wp_unslash( $_GET['sc_design'] ) ); // phpcs:ignore
		} elseif ( ! empty( $_COOKIE[ SC_Designs::COOKIE ] ) ) {
			$design_token = sanitize_text_field( wp_unslash( $_COOKIE[ SC_Designs::COOKIE ] ) );
		}
		wp_localize_script(
			'sc-customizer',
			'scDesigns',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'sc_designs' ),
				'loggedIn' => is_user_logged_in(),
				'guestTTL' => SC_Designs::TTL_DAYS,
				'token'    => $design_token,
			)
		);
		wp_localize_script(
			'sc-customizer',
			'scLibrary',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'sc_library' ),
			)
		);
	}

	public function enqueue_admin( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! $post || 'product' !== get_post_type( $post ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'sc-admin', SC_PLUGIN_URL . 'assets/admin.css', array(), SC_VERSION );
		wp_enqueue_script( 'sc-admin', SC_PLUGIN_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), SC_VERSION, true );
		wp_localize_script(
			'sc-admin',
			'scAdmin',
			array(
				'nonce' => wp_create_nonce( 'sc_admin' ),
				'i18n'  => array(
					'addField'  => __( 'Add field', 'storecanvas' ),
					'addView'   => __( 'Add view', 'storecanvas' ),
					'addArea'   => __( 'Add print area', 'storecanvas' ),
					'selectImg' => __( 'Select image', 'storecanvas' ),
				),
			)
		);
	}
}
