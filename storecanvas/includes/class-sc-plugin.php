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
	const MENU_SLUG        = 'storecanvas';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
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

	public function register_menu() {
		add_menu_page(
			__( 'StoreCanvas', 'storecanvas' ),
			__( 'StoreCanvas', 'storecanvas' ),
			'edit_shop_orders',
			self::MENU_SLUG,
			array( $this, 'render_home' ),
			'dashicons-art',
			55
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Overview', 'storecanvas' ),
			__( 'Overview', 'storecanvas' ),
			'edit_shop_orders',
			self::MENU_SLUG,
			array( $this, 'render_home' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'License', 'storecanvas' ),
			__( 'License', 'storecanvas' ),
			'manage_woocommerce',
			'sc-license',
			array( SC_License::instance(), 'render_page' )
		);
	}

	public function render_home() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		$groups   = wp_count_posts( self::CPT_OPTION_GROUP );
		$designs  = wp_count_posts( SC_Designs::CPT );
		$library  = wp_count_posts( SC_Clipart::CPT );
		$n_groups = $groups && isset( $groups->publish ) ? (int) $groups->publish : 0;
		$n_des    = $designs && isset( $designs->publish ) ? (int) $designs->publish : 0;
		$n_lib    = $library && isset( $library->publish ) ? (int) $library->publish : 0;
		$lic      = class_exists( 'SC_License' ) ? SC_License::instance() : null;

		$links = array(
			array(
				'href'  => admin_url( 'admin.php?page=' . SC_Queue::PAGE_SLUG ),
				'icon'  => 'dashicons-list-view',
				'title' => __( 'Production queue', 'storecanvas' ),
				'desc'  => __( 'Print, mark done, and download artwork ZIPs.', 'storecanvas' ),
			),
			array(
				'href'  => admin_url( 'edit.php?post_type=' . self::CPT_OPTION_GROUP ),
				'icon'  => 'dashicons-forms',
				'title' => __( 'Option groups', 'storecanvas' ),
				'desc'  => __( 'Reusable option fields assigned to products.', 'storecanvas' ),
			),
			array(
				'href'  => admin_url( 'edit.php?post_type=' . SC_Designs::CPT ),
				'icon'  => 'dashicons-portfolio',
				'title' => __( 'Saved designs', 'storecanvas' ),
				'desc'  => __( 'Customer-saved canvas designs.', 'storecanvas' ),
			),
			array(
				'href'  => admin_url( 'edit.php?post_type=' . SC_Clipart::CPT ),
				'icon'  => 'dashicons-images-alt2',
				'title' => __( 'Library', 'storecanvas' ),
				'desc'  => __( 'Clip-art available in the customizer.', 'storecanvas' ),
			),
			array(
				'href'  => admin_url( 'admin.php?page=sc-journey' ),
				'icon'  => 'dashicons-chart-area',
				'title' => __( 'Journey', 'storecanvas' ),
				'desc'  => __( 'Where customers drop off in the customizer.', 'storecanvas' ),
			),
			array(
				'href'  => admin_url( 'admin.php?page=sc-proof-email' ),
				'icon'  => 'dashicons-email-alt',
				'title' => __( 'Proof email', 'storecanvas' ),
				'desc'  => __( 'Optional proof of artwork after order.', 'storecanvas' ),
			),
		);
		echo '<div class="wrap sc-home">';
		echo '<div class="sc-home-hero">';
		echo '<div>';
		echo '<p class="sc-home-kicker">' . esc_html__( 'Designer', 'storecanvas' ) . '</p>';
		echo '<h1>' . esc_html__( 'StoreCanvas', 'storecanvas' ) . '</h1>';
		echo '<p class="sc-home-lead">' . esc_html__( 'Live canvas, options, print files, and production. Product-level setup still lives on each product.', 'storecanvas' ) . '</p>';
		echo '</div>';
		echo '<div class="sc-home-hero-actions">';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Products', 'storecanvas' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=sc-license' ) ) . '">' . esc_html__( 'License', 'storecanvas' ) . '</a>';
		echo '</div></div>';
		echo '<div class="sc-home-stats">';
		echo '<a class="sc-stat" href="' . esc_url( admin_url( 'edit.php?post_type=' . SC_Designs::CPT ) ) . '"><span class="sc-stat-value">' . esc_html( (string) $n_des ) . '</span><span class="sc-stat-title">' . esc_html__( 'Saved designs', 'storecanvas' ) . '</span></a>';
		echo '<a class="sc-stat sc-stat--groups" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_OPTION_GROUP ) ) . '"><span class="sc-stat-value">' . esc_html( (string) $n_groups ) . '</span><span class="sc-stat-title">' . esc_html__( 'Option groups', 'storecanvas' ) . '</span></a>';
		echo '<a class="sc-stat sc-stat--lib" href="' . esc_url( admin_url( 'edit.php?post_type=' . SC_Clipart::CPT ) ) . '"><span class="sc-stat-value">' . esc_html( (string) $n_lib ) . '</span><span class="sc-stat-title">' . esc_html__( 'Library items', 'storecanvas' ) . '</span></a>';
		if ( $lic ) {
			$ok = $lic->allows_updates();
			echo '<a class="sc-stat ' . ( $ok ? 'sc-stat--ok' : 'sc-stat--muted' ) . '" href="' . esc_url( admin_url( 'admin.php?page=sc-license' ) ) . '"><span class="sc-stat-value">' . esc_html( $ok ? __( 'On', 'storecanvas' ) : __( 'Off', 'storecanvas' ) ) . '</span><span class="sc-stat-title">' . esc_html__( 'Premium updates', 'storecanvas' ) . '</span></a>';
		}
		echo '</div>';
		echo '<div class="sc-home-grid">';
		foreach ( $links as $item ) {
			echo '<a class="sc-home-card" href="' . esc_url( $item['href'] ) . '">';
			echo '<span class="dashicons ' . esc_attr( $item['icon'] ) . '"></span>';
			echo '<strong>' . esc_html( $item['title'] ) . '</strong>';
			echo '<span>' . esc_html( $item['desc'] ) . '</span>';
			echo '</a>';
		}
		echo '</div></div>';
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
					'upload'            => __( 'Upload artwork', 'storecanvas' ),
					'apply'             => __( 'Apply placement', 'storecanvas' ),
					'reset'             => __( 'Reset', 'storecanvas' ),
					'noArea'            => __( 'No print area configured.', 'storecanvas' ),
					'layerUp'           => __( 'Up', 'storecanvas' ),
					'layerDown'         => __( 'Down', 'storecanvas' ),
					'layerRemove'       => __( 'Remove', 'storecanvas' ),
					'noBaseImage'       => __( 'No base image for this view', 'storecanvas' ),
					'libImgError'       => __( 'Could not load library image.', 'storecanvas' ),
					'defaultText'       => __( 'Your text', 'storecanvas' ),
					'libUnavailable'    => __( 'Library unavailable.', 'storecanvas' ),
					'noClipart'         => __( 'No clip-art available.', 'storecanvas' ),
					'defaultDesignName' => __( 'My design', 'storecanvas' ),
					'promptName'        => __( 'Name this design', 'storecanvas' ),
					'designSaved'       => __( 'Design saved.', 'storecanvas' ),
					'designSavedDevice' => __( 'Design saved on this device.', 'storecanvas' ),
					'saveFailed'        => __( 'Could not save design.', 'storecanvas' ),
					'noSavedDesigns'    => __( 'No saved designs.', 'storecanvas' ),
					'noSavedDesign'     => __( 'No saved design found.', 'storecanvas' ),
					'designReloaded'    => __( 'Design reloaded.', 'storecanvas' ),
					'saveFirst'         => __( 'Save a design first.', 'storecanvas' ),
					'promptEmail'       => __( 'Email address for your design link', 'storecanvas' ),
					'linkEmailed'       => __( 'Link emailed.', 'storecanvas' ),
					'emailFailed'       => __( 'Could not send email.', 'storecanvas' ),
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
		$on_sc_menu = is_string( $hook ) && (
			false !== strpos( $hook, 'storecanvas' )
			|| false !== strpos( $hook, 'sc-production-queue' )
			|| false !== strpos( $hook, 'sc-journey' )
			|| false !== strpos( $hook, 'sc-proof-email' )
			|| false !== strpos( $hook, 'sc-license' )
			|| false !== strpos( $hook, 'sc_option_group' )
			|| false !== strpos( $hook, 'sc_design' )
			|| false !== strpos( $hook, 'sc_clipart' )
		);
		$on_product = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& $post && 'product' === get_post_type( $post );

		if ( ! $on_sc_menu && ! $on_product ) {
			return;
		}

		wp_enqueue_style( 'sc-admin', SC_PLUGIN_URL . 'assets/admin.css', array(), SC_VERSION );

		if ( is_string( $hook ) && false !== strpos( $hook, 'sc-license' ) ) {
			wp_enqueue_script( 'sc-license', SC_PLUGIN_URL . 'assets/license.js', array( 'jquery' ), SC_VERSION, true );
			wp_localize_script(
				'sc-license',
				'scLicense',
				array(
					'ajax'   => admin_url( 'admin-ajax.php' ),
					'nonce'  => wp_create_nonce( 'sc_license' ),
					'prefix' => 'sc',
					'i18n'   => array(
						'activate'      => __( 'Activate', 'storecanvas' ),
						'activating'    => __( 'Activating…', 'storecanvas' ),
						'deactivate'    => __( 'Deactivate', 'storecanvas' ),
						'deactivating'  => __( 'Deactivating…', 'storecanvas' ),
						'recheck'       => __( 'Re-check', 'storecanvas' ),
						'checking'      => __( 'Checking…', 'storecanvas' ),
						'saveServer'    => __( 'Save server URL', 'storecanvas' ),
						'saving'        => __( 'Saving…', 'storecanvas' ),
						'requestFailed' => __( 'Request failed', 'storecanvas' ),
						'updatesOn'     => __( 'premium updates enabled', 'storecanvas' ),
						'updatesOff'    => __( 'premium updates paused', 'storecanvas' ),
						'lifetime'      => __( 'Lifetime / none set', 'storecanvas' ),
					),
				)
			);
		}

		if ( ! $on_product ) {
			return;
		}
		wp_enqueue_media();
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
