<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Auto {

	const DEFERRED_HOOK = 'toc_deferred_auto_notify';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_completed' ), 25, 1 );
		add_action( self::DEFERRED_HOOK, array( $this, 'run_deferred' ), 10, 1 );
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

			if ( $is_method || stripos( $title, 'local pickup' ) !== false || stripos( $title, 'pickup' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current store-local time is inside quiet hours.
	 * Supports windows that wrap midnight (e.g. 21:00–08:00).
	 */
	public static function is_quiet_hours( $timestamp = null ) {
		if ( ! (int) get_option( 'toc_quiet_hours_enabled', 0 ) ) {
			return false;
		}

		$start = self::normalize_time_option( get_option( 'toc_quiet_hours_start', '21:00' ), '21:00' );
		$end   = self::normalize_time_option( get_option( 'toc_quiet_hours_end', '08:00' ), '08:00' );

		if ( $start === $end ) {
			return false;
		}

		$tz  = wp_timezone();
		$now = $timestamp ? ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
		$hm  = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		list( $sh, $sm ) = array_map( 'intval', explode( ':', $start ) );
		list( $eh, $em ) = array_map( 'intval', explode( ':', $end ) );
		$start_m         = $sh * 60 + $sm;
		$end_m           = $eh * 60 + $em;

		if ( $start_m < $end_m ) {
			// Same-day window (e.g. 01:00–05:00).
			return $hm >= $start_m && $hm < $end_m;
		}

		// Overnight window (e.g. 21:00–08:00).
		return $hm >= $start_m || $hm < $end_m;
	}

	/**
	 * Next timestamp (UTC epoch) when quiet hours end, in store timezone.
	 */
	public static function next_quiet_hours_end_timestamp( $from_timestamp = null ) {
		$end = self::normalize_time_option( get_option( 'toc_quiet_hours_end', '08:00' ), '08:00' );
		$tz  = wp_timezone();
		$now = $from_timestamp ? ( new DateTimeImmutable( '@' . (int) $from_timestamp ) )->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );

		list( $eh, $em ) = array_map( 'intval', explode( ':', $end ) );
		$candidate       = $now->setTime( $eh, $em, 0 );

		if ( $candidate->getTimestamp() <= $now->getTimestamp() ) {
			$candidate = $candidate->modify( '+1 day' );
		}

		// If still inside quiet hours at that candidate (edge: misconfigured), push another hour.
		$ts = $candidate->getTimestamp();
		if ( self::is_quiet_hours( $ts ) ) {
			$ts = $candidate->modify( '+1 hour' )->getTimestamp();
		}

		return $ts;
	}

	public static function normalize_time_option( $value, $fallback ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return $fallback;
		}
		list( $h, $m ) = array_map( 'intval', explode( ':', $value ) );
		return sprintf( '%02d:%02d', $h, $m );
	}

	public function on_completed( $order_id ) {
		$this->process_order( (int) $order_id, false );
	}

	public function run_deferred( $order_id ) {
		$this->process_order( (int) $order_id, true );
	}

	/**
	 * @param int  $order_id Order ID.
	 * @param bool $from_deferred Whether this run came from the deferred hook.
	 */
	private function process_order( $order_id, $from_deferred = false ) {
		if ( ! get_option( 'toc_auto_on_completed', 1 ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_toc_auto_notified_at' ) ) {
			return;
		}

		if ( ! self::is_local_pickup( $order ) ) {
			return;
		}

		// Quiet hours: defer instead of calling/texting now.
		if ( self::is_quiet_hours() ) {
			$when = self::next_quiet_hours_end_timestamp();
			$order->update_meta_data( '_toc_auto_deferred_until', $when );
			$order->save();
			$this->schedule_deferred( $order_id, $when );

			$order->add_order_note(
				sprintf(
					/* translators: %s: local datetime when notification will run */
					__( 'Auto notification deferred (quiet hours). Scheduled for %s.', 'twilio-order-communicator' ),
					wp_date( 'M j, Y g:i a', $when )
				)
			);
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			$order->add_order_note( __( 'Auto notification skipped: no phone number.', 'twilio-order-communicator' ) );
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
				$order->add_order_note(
					sprintf(
						/* translators: %s: Twilio call SID */
						__( 'Auto voice call placed (Ready for Pickup). SID: %s', 'twilio-order-communicator' ),
						$result['sid']
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: error message */
						__( 'Auto voice call failed: %s', 'twilio-order-communicator' ),
						$result['error'] ?? 'unknown'
					)
				);
			}
		}

		if ( get_option( 'toc_auto_sms', 0 ) ) {
			$result = $twilio->send_sms( $phone, $message, $order_id, false );
			if ( ! empty( $result['success'] ) ) {
				$order->add_order_note( __( 'Auto SMS sent (Ready for Pickup).', 'twilio-order-communicator' ) );
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: error / skip reason */
						__( 'Auto SMS not sent: %s', 'twilio-order-communicator' ),
						$result['error'] ?? 'unknown'
					)
				);
			}
		} else {
			$order->add_order_note(
				__( 'Auto SMS skipped: "Also send an SMS" is disabled in Order Communicator → Settings.', 'twilio-order-communicator' )
			);
		}

		$order->delete_meta_data( '_toc_auto_deferred_until' );
		$order->update_meta_data( '_toc_auto_notified_at', time() );
		if ( $from_deferred ) {
			$order->add_order_note( __( 'Deferred auto notification completed after quiet hours.', 'twilio-order-communicator' ) );
		}
		$order->save();
	}

	private function schedule_deferred( $order_id, $timestamp ) {
		$order_id  = absint( $order_id );
		$timestamp = absint( $timestamp );
		if ( ! $order_id || ! $timestamp ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::DEFERRED_HOOK, array( $order_id ), 'toc' ) ) {
				as_schedule_single_action( $timestamp, self::DEFERRED_HOOK, array( $order_id ), 'toc' );
			}
			return;
		}

		$hook = self::DEFERRED_HOOK;
		if ( ! wp_next_scheduled( $hook, array( $order_id ) ) ) {
			wp_schedule_single_event( $timestamp, $hook, array( $order_id ) );
		}
	}
}
