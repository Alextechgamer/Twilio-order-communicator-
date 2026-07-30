<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Auto {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_completed' ), 25, 1 );
	}

	/**
	 * Local Pickup detection — mode from Settings (toc_pickup_match).
	 * method_id | local_title (default) | any_pickup (legacy loose)
	 */
	public static function is_local_pickup( $order ) {
		if ( ! $order ) {
			return false;
		}

		$mode = get_option( 'toc_pickup_match', 'local_title' );
		if ( ! in_array( $mode, array( 'method_id', 'local_title', 'any_pickup' ), true ) ) {
			$mode = 'local_title';
		}

		foreach ( $order->get_shipping_methods() as $shipping ) {
			$title     = (string) $shipping->get_method_title();
			$method_id = (string) $shipping->get_method_id();
			$is_method = ( $method_id === 'local_pickup' || strpos( $method_id, 'local_pickup' ) !== false );

			if ( $mode === 'method_id' ) {
				if ( $is_method ) {
					return true;
				}
				continue;
			}

			if ( $mode === 'local_title' ) {
				if ( $is_method || stripos( $title, 'local pickup' ) !== false ) {
					return true;
				}
				continue;
			}

			// any_pickup
			if ( $is_method || stripos( $title, 'local pickup' ) !== false || stripos( $title, 'pickup' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	public function on_completed( $order_id ) {
		if ( ! get_option( 'toc_auto_on_completed', 1 ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotency — Completed can fire more than once.
		if ( $order->get_meta( '_toc_auto_notified_at' ) ) {
			return;
		}

		if ( ! self::is_local_pickup( $order ) ) {
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			$order->add_order_note( 'Auto notification skipped: no phone number.' );
			return;
		}

		$raw_message = get_option(
			'toc_default_pickup_message',
			'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.'
		);
		$twilio  = TOC_Twilio::instance();
		$message = $twilio->merge_tags( $raw_message, $order );

		if ( get_option( 'toc_auto_voice', 1 ) ) {
			$result = $twilio->make_call( $phone, $message, $order_id );
			if ( ! empty( $result['success'] ) ) {
				$order->add_order_note( 'Auto voice call placed (Ready for Pickup). SID: ' . $result['sid'] );
			} else {
				$order->add_order_note( 'Auto voice call failed: ' . ( $result['error'] ?? 'unknown' ) );
			}
		}

		if ( get_option( 'toc_auto_sms', 0 ) ) {
			$result = $twilio->send_sms( $phone, $message, $order_id, false );
			if ( ! empty( $result['success'] ) ) {
				$order->add_order_note( 'Auto SMS sent (Ready for Pickup).' );
			} else {
				// Always explain why (was silent on consent before — hard to debug).
				$order->add_order_note( 'Auto SMS not sent: ' . ( $result['error'] ?? 'unknown' ) );
			}
		} else {
			$order->add_order_note( 'Auto SMS skipped: "Also send an SMS" is disabled in Order Communicator → Settings.' );
		}

		// Stamp once in-scope so re-completing does not spam. Clear meta to force re-send.
		$order->update_meta_data( '_toc_auto_notified_at', time() );
		$order->save();
	}
}
