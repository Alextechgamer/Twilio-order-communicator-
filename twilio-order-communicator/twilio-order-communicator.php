<?php
/**
 * Plugin Name:       Twilio Order Communicator
 * Plugin URI:        https://github.com/Alextechgamer/Twilio-order-communicator-
 * Description:       Send SMS and place voice calls from WooCommerce orders using your own Twilio account. Status-based Ready for Pickup and Shipped notifications, chat history, bulk reminders, and consent-aware messaging.
 * Version:           1.15.1
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

define( 'TOC_VERSION', '1.15.1' );
define( 'TOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TOC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'TOC_PLUGIN_FILE', __FILE__ );

require_once TOC_PLUGIN_DIR . 'includes/class-toc-caps.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-logger.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-twilio.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-webhooks.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-license.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-updater.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-admin.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-order-meta.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-statuses.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-auto.php';
require_once TOC_PLUGIN_DIR . 'includes/class-toc-reminders.php';
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
		register_deactivation_hook( TOC_PLUGIN_FILE, array( $this, 'deactivate' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Leave no scheduled work behind when the plugin is switched off.
	 * Settings, logs, and license state are kept for reactivation.
	 */
	public function deactivate() {
		TOC_License::unschedule_cron();
		TOC_Reminders::unschedule_all();
	}

	/**
	 * HPOS / custom order tables compatibility.
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TOC_PLUGIN_FILE, true );
			// The plugin integrates the block checkout (SMS consent field), so declare it
			// compatible or WooCommerce flags it "incompatible" in the block-checkout UI.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TOC_PLUGIN_FILE, true );
		}
	}

	public function activate() {
		TOC_Logger::create_table();
		TOC_Logger::create_opt_outs_table();
		TOC_Logger::migrate_opt_outs_from_option();
		$this->seed_defaults();
		$this->migrate_from_legacy();
		TOC_Caps::maybe_seed();
		update_option( 'toc_db_version', TOC_VERSION );
	}

	/**
	 * Seed settings only when missing (does not overwrite store customizations).
	 */
	public function seed_defaults() {
		$defaults = array(
			'toc_sms_consent_meta'               => '_toc_sms_consent',
			'toc_pickup_match'                   => 'local_title',
			'toc_ready_require_local_pickup'     => 0,
			'toc_status_ready_for_pickup'        => 'wc-ready-for-pickup',
			'toc_status_shipped'                 => 'wc-shipped',
			'toc_auto_ready_enabled'             => 1,
			'toc_auto_ready_voice'               => 1,
			'toc_auto_ready_sms'                 => 0,
			'toc_auto_shipped_enabled'           => 0,
			'toc_auto_shipped_voice'             => 0,
			'toc_auto_shipped_sms'               => 0,
			'toc_require_sms_consent'            => 1,
			'toc_checkout_consent_enabled'       => 1,
			'toc_checkout_consent_required'      => 0,
			'toc_checkout_consent_label'         => 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.',
			'toc_quiet_hours_enabled'            => 0,
			'toc_quiet_hours_start'              => '21:00',
			'toc_quiet_hours_end'                => '08:00',
			'toc_scheduled_reminder_enabled'     => 0,
			'toc_scheduled_reminder_delay_hours' => 24,
			'toc_delivery_alert_enabled'         => 0,
			'toc_delivery_alert_email'           => '',
			'toc_email_ready_enabled'            => 0,
			'toc_email_ready_subject'            => 'Your order #{order_number} is ready for pickup',
			'toc_email_ready_body'               => "Hello {customer_first_name},\n\nYour order #{order_number} is ready for pickup at {store_name}.\n\nThank you.",
			'toc_email_shipped_enabled'          => 0,
			'toc_email_shipped_subject'          => 'Your order #{order_number} has shipped',
			'toc_email_shipped_body'             => "Hello {customer_first_name},\n\nYour order #{order_number} has shipped.\n\nThank you for shopping at {store_name}.",
			'toc_onboarding_done'                => 0,
			'toc_onboarding_step'                => 1,
			'toc_voice'                          => 'alice',
			'toc_bulk_delay_seconds'             => 8,
			'toc_sms_footer_enabled'             => 0,
			'toc_sms_footer_text'                => 'Reply STOP to opt out. Msg & data rates may apply.',
			'toc_license_status'                 => 'inactive',
			'toc_license_server_url'             => '',
			'toc_message_ready_for_pickup'       => 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.',
			'toc_message_shipped'                => 'Hello {customer_first_name}. Your order #{order_number} has shipped. Thank you for your order.',
			'toc_message_reminder'               => 'Hello {customer_first_name}. This is a reminder that your order #{order_number} is still waiting for pickup. Please stop by at your earliest convenience. Thank you.',
			'toc_message_issue'                  => 'Hello {customer_first_name}. There is an issue with your recent order #{order_number} that requires your attention. Please contact us or reply to this message. Thank you.',
			'toc_stop_reply'                     => 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.',
			'toc_start_reply'                    => 'You have been re-subscribed to SMS messages. Reply STOP to opt out.',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * One-time migration from 1.5.x Local Pickup / Completed options.
	 */
	public function migrate_from_legacy() {
		// Copy message templates from legacy keys when new keys were just seeded empty / default.
		$msg_map = array(
			'toc_default_pickup_message'   => 'toc_message_ready_for_pickup',
			'toc_default_reminder_message' => 'toc_message_reminder',
			'toc_default_issue_message'    => 'toc_message_issue',
		);
		foreach ( $msg_map as $old => $new ) {
			$old_val = get_option( $old, false );
			if ( false === $old_val || $old_val === '' ) {
				continue;
			}
			// Only overwrite new key if it still matches the shipped default (never customized).
			$new_val = get_option( $new, false );
			$default = null;
			if ( $new === 'toc_message_ready_for_pickup' ) {
				$default = 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.';
			} elseif ( $new === 'toc_message_reminder' ) {
				$default = 'Hello {customer_first_name}. This is a reminder that your order #{order_number} is still waiting for pickup. Please stop by at your earliest convenience. Thank you.';
			} elseif ( $new === 'toc_message_issue' ) {
				$default = 'Hello {customer_first_name}. There is an issue with your recent order #{order_number} that requires your attention. Please contact us or reply to this message. Thank you.';
			}
			if ( false === $new_val || $new_val === $default ) {
				update_option( $new, $old_val );
			}
		}

		// Map old auto Completed toggles → Ready for Pickup (once).
		if ( get_option( 'toc_migrated_auto_v160', false ) === false ) {
			$had_legacy_auto = ( false !== get_option( 'toc_auto_on_completed', false ) );
			if ( $had_legacy_auto ) {
				update_option( 'toc_auto_ready_enabled', (int) get_option( 'toc_auto_on_completed', 1 ) ? 1 : 0 );
				update_option( 'toc_auto_ready_voice', (int) get_option( 'toc_auto_voice', 1 ) ? 1 : 0 );
				update_option( 'toc_auto_ready_sms', (int) get_option( 'toc_auto_sms', 0 ) ? 1 : 0 );
				// Preserve prior Completed + Local Pickup behavior until the store remaps.
				update_option( 'toc_status_ready_for_pickup', 'wc-completed' );
				update_option( 'toc_ready_require_local_pickup', 1 );
			}
			update_option( 'toc_migrated_auto_v160', 1, false );
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
			$this->migrate_from_legacy();
			TOC_Caps::maybe_seed();
			update_option( 'toc_db_version', TOC_VERSION );
		}

		// Ensure custom caps are seeded once for existing installs upgrading without re-activate.
		TOC_Caps::maybe_seed();

		TOC_Logger::instance();
		TOC_Twilio::instance();
		TOC_Webhooks::instance();
		TOC_License::instance();
		TOC_Updater::instance();
		TOC_Admin::instance();
		TOC_Order_Meta::instance();
		TOC_Statuses::instance();
		TOC_Auto::instance();
		TOC_Reminders::instance();
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
