<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin helpers and constants for meta keys.
 */
class SC_Plugin {

	const META_OPTIONS     = '_sc_options';
	const META_CUSTOMIZER  = '_sc_customizer';
	const META_VALIDATION  = '_sc_validation';

	/** Cart/order item keys */
	const CART_OPTIONS     = 'sc_options';
	const CART_PLACEMENT   = 'sc_placement';
	const CART_ATTACHMENTS = 'sc_attachments';

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

	/**
	 * Default validation rules for print-ready checks (Phase C expands enforcement).
	 *
	 * @return array
	 */
	public static function default_validation() {
		return array(
			'min_dpi'         => 150,
			'max_upload_mb'   => 10,
			'allowed_mimes'   => array( 'image/png', 'image/jpeg', 'image/svg+xml' ),
			'min_source_px'   => 500,
			'safe_margin_pct' => 5,
		);
	}

	/**
	 * Empty customizer config shape.
	 *
	 * @return array
	 */
	public static function empty_customizer() {
		return array(
			'enabled' => 0,
			'views'   => array(), // [ { id, label, image_id } ]
			'areas'   => array(), // [ { id, view_id, label, x, y, w, h, lock_rotation } ] coords 0–100 %
		);
	}

	/**
	 * Empty options config shape.
	 *
	 * @return array
	 */
	public static function empty_options() {
		return array(
			'fields' => array(), // [ { id, type, label, required, price_type, price, choices, conditions } ]
		);
	}

	public function enqueue_front() {
		if ( ! is_product() ) {
			return;
		}
		wp_enqueue_style(
			'sc-front',
			SC_PLUGIN_URL . 'assets/admin.css',
			array(),
			SC_VERSION
		);
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
