<?php
/**
 * Plugin Name:       Orderbay
 * Plugin URI:        https://github.com/Alextechgamer/Twilio-order-communicator-
 * Description:       Self-hosted WooCommerce ops toolkit — invoices/packing slips, fulfillment, order ops, email rules, catalog helpers, and dashboard. Independent of Twilio Order Communicator and StoreCanvas.
 * Version:           0.6.0
 * Author:            Alextechgamer
 * Author URI:        https://github.com/Alextechgamer
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       orderbay
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OB_VERSION', '0.6.0' );
define( 'OB_PLUGIN_FILE', __FILE__ );
define( 'OB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once OB_PLUGIN_DIR . 'includes/class-ob-plugin.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-documents.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-order-ops.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-notifications.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-catalog.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-dashboard.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-digest.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-export.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-invoicing.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-fulfillment.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-rma.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-sla.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-notes.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-partial.php';
require_once OB_PLUGIN_DIR . 'includes/class-ob-barcode.php';

/**
 * Bootstrap when WooCommerce is active.
 */
function ob_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ob_woocommerce_missing_notice' );
		return;
	}
	OB_Plugin::instance();
	OB_Documents::instance();
	OB_Order_Ops::instance();
	OB_Notifications::instance();
	OB_Catalog::instance();
	OB_Dashboard::instance();
	OB_Digest::instance();
	OB_Export::instance();
	OB_Invoicing::instance();
	OB_Fulfillment::instance();
	OB_RMA::instance();
	OB_SLA::instance();
	OB_Notes::instance();
	OB_Partial::instance();
}

register_deactivation_hook( __FILE__, 'ob_deactivate' );
function ob_deactivate() {
	if ( class_exists( 'OB_Digest' ) ) {
		OB_Digest::unschedule();
	}
	if ( class_exists( 'OB_SLA' ) ) {
		OB_SLA::unschedule();
	}
	$ts = wp_next_scheduled( 'ob_daily_stock_scan' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'ob_daily_stock_scan' );
	}
}
add_action( 'plugins_loaded', 'ob_init' );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OB_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OB_PLUGIN_FILE, true );
	}
} );

function ob_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Orderbay requires WooCommerce to be active.', 'orderbay' );
	echo '</p></div>';
}
