<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core bootstrap, constants, shared admin menu shell.
 */
class OB_Plugin {

	const OPT_DOCS          = 'ob_documents_settings';
	const OPT_EMAIL_RULES   = 'ob_email_rules';
	const OPT_LOW_STOCK     = 'ob_low_stock_settings';
	const META_ATTENTION    = '_ob_needs_attention';
	const META_TAGS         = '_ob_order_tags';
	const TAX_ORDER_TAG     = 'ob_order_tag';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 55 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
	}

	public function register_taxonomies() {
		// Registered by order ops if needed; keep hook for future.
	}

	/**
	 * Default document settings.
	 *
	 * @return array
	 */
	public static function default_doc_settings() {
		return array(
			'logo_url'    => '',
			'from_lines'  => get_bloginfo( 'name' ) . "\n" . get_option( 'woocommerce_store_address', '' ),
			'footer_text' => __( 'Thank you for your order.', 'orderbay' ),
			'paper'       => 'letter', // letter|a4 — CSS @page size for print
		);
	}

	/**
	 * @return array
	 */
	public static function get_doc_settings() {
		$raw = get_option( self::OPT_DOCS, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args( $raw, self::default_doc_settings() );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Orderbay', 'orderbay' ),
			__( 'Orderbay', 'orderbay' ),
			'edit_shop_orders',
			'orderbay',
			array( 'OB_Dashboard', 'render_page_static' ),
			'dashicons-clipboard',
			56
		);
		// Dashboard is first submenu (same slug).
		add_submenu_page(
			'orderbay',
			__( 'Dashboard', 'orderbay' ),
			__( 'Dashboard', 'orderbay' ),
			'edit_shop_orders',
			'orderbay',
			array( 'OB_Dashboard', 'render_page_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Documents', 'orderbay' ),
			__( 'Documents', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-documents',
			array( 'OB_Documents', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Email rules', 'orderbay' ),
			__( 'Email rules', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-notifications',
			array( 'OB_Notifications', 'render_settings_static' )
		);
	}

	public function enqueue_admin( $hook ) {
		if ( false === strpos( (string) $hook, 'orderbay' ) && false === strpos( (string) $hook, 'shop_order' ) && false === strpos( (string) $hook, 'wc-orders' ) && false === strpos( (string) $hook, 'product' ) ) {
			// Still load lightly on order/product screens.
			if ( ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true ) ) {
				return;
			}
		}
		wp_enqueue_style( 'ob-admin', OB_PLUGIN_URL . 'assets/admin.css', array(), OB_VERSION );
		wp_enqueue_script( 'ob-admin', OB_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), OB_VERSION, true );
		wp_localize_script(
			'ob-admin',
			'obAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ob_admin' ),
			)
		);
	}
}
