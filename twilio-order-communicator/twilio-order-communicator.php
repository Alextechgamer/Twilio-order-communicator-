<?php
/**
 * Plugin Name:       Twilio Order Communicator
 * Plugin URI:        https://github.com/Alextechgamer/Twilio-order-communicator-
 * Description:       Send SMS and place voice calls from WooCommerce orders. Full chat history, bulk reminders, consent-aware messaging, and automatic Local Pickup notifications.
 * Version:           1.5.0
 * Author:            Alextechgamer
 * Author URI:        https://github.com/Alextechgamer
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       twilio-order-communicator
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOC_VERSION', '1.5.0' );
define( 'TOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TOC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'TOC_PLUGIN_FILE', __FILE__ );

require_once TOC_PLUGIN_DIR . 'includes/class-toc-logger.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-twilio.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-webhooks.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-admin.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-order-meta.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-auto.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-checkout.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-onboarding.php';

final class Twilio_Order_Communicator {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( TOC_PLUGIN_FILE, array( $this, 'activate' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * HPOS / custom order tables compatibility.
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TOC_PLUGIN_FILE, true );
		}
	}

	public function activate() {
		TOC_Logger::create_table();
		TOC_Logger::create_opt_outs_table();
		TOC_Logger::migrate_opt_outs_from_option();
		$this->seed_defaults();
		update_option( 'toc_db_version', TOC_VERSION );
	}

	/**
	 * Seed settings only when missing (does not overwrite store customizations).
	 */
	public function seed_defaults() {
		$defaults = array(
			'toc_sms_consent_meta'           => '_toc_sms_consent',
			'toc_pickup_match'               => 'local_title',
			'toc_auto_on_completed'          => 1,
			'toc_auto_voice'                 => 1,
			'toc_auto_sms'                   => 0,
			'toc_require_sms_consent'        => 1,
			'toc_checkout_consent_enabled'   => 1,
			'toc_checkout_consent_required'  => 0,
			'toc_checkout_consent_label'     => 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.',
			'toc_quiet_hours_enabled'        => 0,
			'toc_quiet_hours_start'          => '21:00',
			'toc_quiet_hours_end'            => '08:00',
			'toc_onboarding_done'            => 0,
			'toc_onboarding_step'            => 1,
			'toc_voice'                      => 'alice',
			'toc_bulk_delay_seconds'         => 8,
			'toc_default_pickup_message'     => 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.',
			'toc_default_reminder_message'   => 'Hello {customer_first_name}. This is a reminder that your order #{order_number} is still waiting for pickup. Please stop by at your earliest convenience. Thank you.',
			'toc_default_issue_message'      => 'Hello {customer_first_name}. There is an issue with your recent order #{order_number} that requires your attention. Please contact us or reply to this message. Thank you.',
			'toc_stop_reply'                 => 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.',
			'toc_start_reply'                => 'You have been re-subscribed to SMS messages. Reply STOP to opt out.',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}

	public function init() {
		load_plugin_textdomain( 'twilio-order-communicator', false, dirname( TOC_PLUGIN_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( get_option( 'toc_db_version' ) !== TOC_VERSION ) {
			TOC_Logger::create_table();
			TOC_Logger::create_opt_outs_table();
			TOC_Logger::migrate_opt_outs_from_option();
			$this->seed_defaults();
			update_option( 'toc_db_version', TOC_VERSION );
		}

		TOC_Logger::instance();
		TOC_Twilio::instance();
		TOC_Webhooks::instance();
		TOC_Admin::instance();
		TOC_Order_Meta::instance();
		TOC_Auto::instance();
		TOC_Checkout::instance();
		TOC_Onboarding::instance();
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: plugin name */
				__( '<strong>%s</strong> requires WooCommerce to be active.', 'twilio-order-communicator' ),
				'Twilio Order Communicator'
			)
		);
		echo '</p></div>';
	}
}

Twilio_Order_Communicator::instance();
