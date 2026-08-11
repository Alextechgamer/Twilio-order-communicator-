<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core bootstrap, constants, shared admin menu shell.
 */
class OB_Plugin {

	const OPT_DOCS            = 'ob_documents_settings';
	const OPT_EMAIL_RULES     = 'ob_email_rules';
	const OPT_LOW_STOCK       = 'ob_low_stock_settings';
	const OPT_DIGEST          = 'ob_digest_settings';
	const OPT_INVOICE_PREFIX  = 'ob_invoice_prefix';
	const OPT_INVOICE_NEXT    = 'ob_invoice_next';
	const OPT_CREDIT_PREFIX   = 'ob_credit_prefix';
	const OPT_CREDIT_NEXT     = 'ob_credit_next';
	const OPT_PROFORMA_PREFIX = 'ob_proforma_prefix';
	const OPT_PROFORMA_NEXT   = 'ob_proforma_next';
	const OPT_TRACKING_EMAIL  = 'ob_tracking_email_settings';
	const OPT_AUTO_ATTENTION  = 'ob_auto_attention_statuses';
	const OPT_RMA             = 'ob_rma_settings';
	const OPT_RMA_PREFIX      = 'ob_rma_prefix';
	const OPT_RMA_NEXT        = 'ob_rma_next';
	const OPT_SLA             = 'ob_sla_settings';
	const OPT_NOTE_TEMPLATES  = 'ob_note_templates';
	const OPT_TRACKING_CARRIERS = 'ob_tracking_carriers';
	const OPT_CUSTOMER_PACKING = 'ob_customer_packing_slip_enabled';

	const META_ATTENTION      = '_ob_needs_attention';
	const META_TAGS           = '_ob_order_tags';
	const META_INVOICE_NUMBER = '_ob_invoice_number';
	const META_CREDIT_NUMBER  = '_ob_credit_note_number';
	const META_PROFORMA_NUMBER = '_ob_proforma_number';
	const META_TRACKING       = '_ob_tracking_number';
	const META_TRACKING_URL   = '_ob_tracking_url';
	const META_TRACKING_CARRIER = '_ob_tracking_carrier';
	const META_TRACKING_EMAIL = '_ob_tracking_emailed_at';
	const META_RMA_STATUS     = '_ob_rma_status';
	const META_RMA_NUMBER     = '_ob_rma_number';
	const META_RMA_REASON     = '_ob_rma_reason';
	const META_SLA_AGED       = '_ob_sla_aged_at';
	const META_BIN            = '_ob_bin_location';
	const META_QTY_FULFILLED  = '_ob_qty_fulfilled';
	const META_FULFILL_STATUS = '_ob_fulfillment_status';
	const TAX_ORDER_TAG       = 'ob_order_tag';

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
		// Reserved for future taxonomy tags.
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
			'paper'       => 'letter',
			'tax_id'      => '',
			'seller_country' => '',
			'show_thumbs'     => '0',
			'show_barcodes'   => '0',
			'qr_enabled'      => '0',
			'pdf_engine'      => 'browser',
			'delivery_prices' => '0',
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
			__( 'Fulfillment', 'orderbay' ),
			__( 'Fulfillment', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-fulfillment',
			array( 'OB_Fulfillment', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Returns / RMA', 'orderbay' ),
			__( 'Returns / RMA', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-rma',
			array( 'OB_RMA', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'SLA aging', 'orderbay' ),
			__( 'SLA aging', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-sla',
			array( 'OB_SLA', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Note templates', 'orderbay' ),
			__( 'Note templates', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-notes',
			array( 'OB_Notes', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Email rules', 'orderbay' ),
			__( 'Email rules', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-notifications',
			array( 'OB_Notifications', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Staff digest', 'orderbay' ),
			__( 'Staff digest', 'orderbay' ),
			'manage_woocommerce',
			'orderbay-digest',
			array( 'OB_Digest', 'render_settings_static' )
		);
		add_submenu_page(
			'orderbay',
			__( 'Export CSV', 'orderbay' ),
			__( 'Export CSV', 'orderbay' ),
			'edit_shop_orders',
			'orderbay-export',
			array( 'OB_Export', 'render_tools_static' )
		);
	}

	public function enqueue_admin( $hook ) {
		if ( false === strpos( (string) $hook, 'orderbay' ) && false === strpos( (string) $hook, 'shop_order' ) && false === strpos( (string) $hook, 'wc-orders' ) && false === strpos( (string) $hook, 'product' ) ) {
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
