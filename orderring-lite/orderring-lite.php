<?php
/**
 * Plugin Name:       OrderRing Lite
 * Plugin URI:        https://github.com/Alextechgamer/Twilio-order-communicator-
 * Description:       Ready-for-pickup SMS for WooCommerce via your own Twilio account. Checkout consent and STOP/HELP/START included. Bring your own Twilio account — you pay Twilio directly, zero markup.
 * Version:           1.0.1
 * Author:            Alextechgamer
 * Author URI:        https://github.com/Alextechgamer
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       orderring-lite
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORL_VERSION', '1.0.1' );
define( 'ORL_BRAND_NAME', 'OrderRing Lite' );
define( 'ORL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ORL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ORL_PLUGIN_FILE', __FILE__ );

require_once ORL_PLUGIN_DIR . 'includes/class-orl-caps.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-logger.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-twilio.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-webhooks.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-admin.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-order-meta.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-statuses.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-auto.php';
require_once ORL_PLUGIN_DIR . 'includes/class-orl-checkout.php';

final class OrderRing_Lite {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( ORL_PLUGIN_FILE, array( $this, 'activate' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORL_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ORL_PLUGIN_FILE, true );
		}
	}

	public function activate() {
		if ( $this->pro_is_active() ) {
			return;
		}
		ORL_Logger::create_table();
		ORL_Logger::create_opt_outs_table();
		ORL_Logger::migrate_opt_outs_from_option();
		ORL_Logger::backfill_opt_out_last10();
		$this->seed_defaults();
		ORL_Caps::maybe_seed();
		update_option( 'orl_db_version', ORL_VERSION );
	}

	public function seed_defaults() {
		$defaults = array(
			'orl_sms_consent_meta'          => '_orl_sms_consent',
			'orl_pickup_match'              => 'local_title',
			'orl_ready_require_local_pickup'=> 0,
			'orl_status_ready_for_pickup'   => 'wc-ready-for-pickup',
			'orl_auto_ready_enabled'        => 1,
			'orl_auto_ready_sms'            => 1,
			'orl_require_sms_consent'       => 1,
			'orl_checkout_consent_enabled'  => 1,
			'orl_checkout_consent_required' => 0,
			'orl_checkout_consent_label'    => 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.',
			'orl_sms_footer_enabled'        => 0,
			'orl_sms_footer_text'           => 'Reply STOP to opt out. Msg & data rates may apply.',
			'orl_message_ready_for_pickup'  => 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.',
			'orl_stop_reply'                => 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.',
			'orl_start_reply'               => 'You have been re-subscribed to SMS messages. Reply STOP to opt out.',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}

	public function init() {
		if ( $this->pro_is_active() ) {
			add_action( 'admin_notices', array( $this, 'pro_active_notice' ) );
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( get_option( 'orl_db_version' ) !== ORL_VERSION ) {
			ORL_Logger::create_table();
			ORL_Logger::create_opt_outs_table();
			ORL_Logger::migrate_opt_outs_from_option();
			ORL_Logger::backfill_opt_out_last10();
			$this->seed_defaults();
			ORL_Caps::maybe_seed();
			update_option( 'orl_db_version', ORL_VERSION );
		}

		ORL_Caps::maybe_seed();
		ORL_Logger::instance();
		ORL_Twilio::instance();
		ORL_Webhooks::instance();
		ORL_Admin::instance();
		ORL_Order_Meta::instance();
		ORL_Statuses::instance();
		ORL_Auto::instance();
		ORL_Checkout::instance();
	}

	public function pro_is_active() {
		return defined( 'TOC_VERSION' ) || class_exists( 'Twilio_Order_Communicator', false );
	}

	public function pro_active_notice() {
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'OrderRing (Pro) is active, so OrderRing Lite is idle. You can deactivate Lite — Pro already includes these SMS tools.', 'orderring-lite' );
		echo '</p></div>';
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: plugin name */
				__( '<strong>%s</strong> requires WooCommerce to be active.', 'orderring-lite' ),
				ORL_BRAND_NAME
			)
		);
		echo '</p></div>';
	}
}

OrderRing_Lite::instance();
