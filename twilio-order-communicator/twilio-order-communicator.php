<?php
/**
 * Plugin Name: Twilio Order Communicator
 * Plugin URI:  https://wordpress.org/plugins/twilio-order-communicator/
 * Description: Send SMS and place voice calls from WooCommerce orders. Full chat history, bulk reminders, consent-aware messaging, and automatic Local Pickup notifications.
 * Version:     1.3.0
 * Author:      Twilio Order Communicator
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: twilio-order-communicator
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOC_VERSION', '1.3.0' );
define( 'TOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TOC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once TOC_PLUGIN_DIR . 'includes/class-toc-logger.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-twilio.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-webhooks.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-admin.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-order-meta.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-auto.php';

final class Twilio_Order_Communicator {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function activate() {
		TOC_Logger::create_table();
		update_option( 'toc_db_version', TOC_VERSION );
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>Twilio Order Communicator</strong> requires WooCommerce to be active.</p></div>';
			} );
			return;
		}

		if ( get_option( 'toc_db_version' ) !== TOC_VERSION ) {
			TOC_Logger::create_table();
			update_option( 'toc_db_version', TOC_VERSION );
		}

		TOC_Logger::instance();
		TOC_Twilio::instance();
		TOC_Webhooks::instance();
		TOC_Admin::instance();
		TOC_Order_Meta::instance();
		TOC_Auto::instance();
	}
}

Twilio_Order_Communicator::instance();
