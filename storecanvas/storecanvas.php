<?php
/**
 * Plugin Name:       StoreCanvas
 * Plugin URI:        https://github.com/Alextechgamer/Twilio-order-communicator-
 * Description:       Self-hosted WooCommerce personalization: product options, live mockup placement, print-ready exports, clip-art library, and guest design save.
 * Version:           1.2.0
 * Author:            Alextechgamer
 * Author URI:        https://github.com/Alextechgamer
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       storecanvas
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SC_VERSION', '1.2.0' );
define( 'SC_PLUGIN_FILE', __FILE__ );
define( 'SC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SC_PLUGIN_DIR . 'includes/class-sc-plugin.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-product-options.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-option-groups.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-customizer.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-print-ready.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-cart-order.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-admin-product.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-journey.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-designs.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-print-sheet.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-bulk-download.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-proof-email.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-clipart.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-orders-list.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-blocks.php';
require_once SC_PLUGIN_DIR . 'includes/class-sc-queue.php';

/**
 * Bootstrap when WooCommerce is available.
 */
function sc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'sc_woocommerce_missing_notice' );
		return;
	}
	SC_Plugin::instance();
	SC_Product_Options::instance();
	SC_Option_Groups::instance();
	SC_Customizer::instance();
	SC_Print_Ready::instance();
	SC_Cart_Order::instance();
	SC_Journey::instance();
	SC_Designs::instance();
	SC_Print_Sheet::instance();
	SC_Bulk_Download::instance();
	SC_Proof_Email::instance();
	SC_Clipart::instance();
	SC_Blocks::instance();
	if ( is_admin() ) {
		SC_Admin_Product::instance();
		SC_Orders_List::instance();
		SC_Queue::instance();
		add_action( 'admin_notices', 'sc_gd_missing_notice' );
	}
}
add_action( 'plugins_loaded', 'sc_init' );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SC_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SC_PLUGIN_FILE, true );
	}
} );

function sc_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'StoreCanvas requires WooCommerce to be active.', 'storecanvas' );
	echo '</p></div>';
}

/**
 * Soft-fail notice when PHP GD is unavailable (composites will skip).
 */
function sc_gd_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( function_exists( 'imagecreatefrompng' ) ) {
		return;
	}
	// Once per session to avoid noise.
	if ( get_transient( 'sc_gd_notice_shown' ) ) {
		return;
	}
	set_transient( 'sc_gd_notice_shown', 1, HOUR_IN_SECONDS );
	echo '<div class="notice notice-warning is-dismissible"><p>';
	echo esc_html__( 'StoreCanvas: PHP GD is not available. Print composites will be skipped until GD is enabled. Product options and live mockup still work.', 'storecanvas' );
	echo '</p></div>';
}
