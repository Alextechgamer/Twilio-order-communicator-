<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled Ready-for-Pickup reminders (Action Scheduler / WP-Cron).
 *
 * When an order enters the mapped Ready for Pickup status and the feature is
 * enabled, a single future action is scheduled. On fire: still Ready, quiet
 * hours re-defer, phone/consent checks, recent-reminder cooldown, then send
 * via the same Twilio paths as bulk/auto and stamp _toc_last_reminder_at.
 *
 * Messaging is never gated by license state.
 */
class TOC_Reminders {

	const HOOK = 'toc_scheduled_reminder';

	/** Default delay after entering Ready for Pickup (hours). */
	const DEFAULT_DELAY_HOURS = 24;

	/** Hard bounds for the delay setting. */
	const MIN_DELAY_HOURS = 1;
	const MAX_DELAY_HOURS = 720; // 30 days

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Whether scheduled reminders are enabled in Settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (int) get_option( 'toc_scheduled_reminder_enabled', 0 ) === 1;
	}

	/**
	 * Delay in hours (clamped).
	 *
	 * @return int
	 */
	public static function delay_hours() {
		$hours = (int) get_option( 'toc_scheduled_reminder_delay_hours', self::DEFAULT_DELAY_HOURS );
		if ( $hours < self::MIN_DELAY_HOURS ) {
			$hours = self::DEFAULT_DELAY_HOURS;
		}
		if ( $hours > self::MAX_DELAY_HOURS ) {
			$hours = self::MAX_DELAY_HOURS;
		}
		return $hours;
	}

	/**
	 * Cooldown (seconds) before another reminder is allowed after _toc_last_reminder_at.
	 * Uses the same delay window so bulk + scheduled do not double-fire closely.
	 *
	 * @return int
	 */
	public static function cooldown_seconds() {
		return self::delay_hours() * HOUR_IN_SECONDS;
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous bare status.
	 * @param string $to       New bare status.
	 * @param object $order    WC_Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		$order_id   = absint( $order_id );
		$to         = (string) $to;
		$ready_bare = TOC_Statuses::bare_status( TOC_Statuses::mapped_ready_status() );

		if ( $to === $ready_bare ) {
			if ( self::is_enabled() ) {
				// Do not schedule for already-collected orders.
				if ( class_exists( 'TOC_Order_Meta' ) && TOC_Order_Meta::is_collected( $order_id ) ) {
					return;
				}
				// Respect the Local Pickup filter, same as auto-notify and bulk — a
				// ship-to-home order must never get a pickup reminder.
				if ( $this->skip_for_local_pickup( $order ) ) {
					return;
				}
				$this->schedule_for_order( $order_id );
			}
			return;
		}

		// Left Ready for Pickup (or never was) — drop any pending reminder.
		if ( (string) $from === $ready_bare ) {
			$this->cancel_for_order( $order_id );
		}
	}

	/**
	 * Schedule a single future reminder for this order (replaces any pending one).
	 *
	 * @param int      $order_id  Order ID.
	 * @param int|null $timestamp Optional fire time (UTC epoch). Default: now + delay.
	 */
	public function schedule_for_order( $order_id, $timestamp = null ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! self::is_enabled() ) {
			return;
		}
		if ( class_exists( 'TOC_Order_Meta' ) && TOC_Order_Meta::is_collected( $order_id ) ) {
			return;
		}

		$this->cancel_for_order( $order_id );

		if ( null === $timestamp ) {
			$timestamp = time() + ( self::delay_hours() * HOUR_IN_SECONDS );
		}
		$timestamp = absint( $timestamp );
		if ( ! $timestamp ) {
			return;
		}

		$args = array( $order_id );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::HOOK, $args, 'toc' );
			return;
		}

		wp_schedule_single_event( $timestamp, self::HOOK, $args );
	}

	/**
	 * Cancel pending scheduled reminder(s) for one order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function cancel_for_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$args = array( $order_id );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, $args, 'toc' );
		}

		// WP-Cron: clear every matching single event.
		while ( $ts = wp_next_scheduled( self::HOOK, $args ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			wp_unschedule_event( $ts, self::HOOK, $args );
		}
	}

	/**
	 * Cancel every scheduled reminder action (deactivate / uninstall).
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, 'toc' );
		}
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::HOOK );
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Action Scheduler / WP-Cron callback.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! self::is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Collected after schedule was created.
		if ( class_exists( 'TOC_Order_Meta' ) && TOC_Order_Meta::is_collected( $order ) ) {
			$order->add_order_note(
				__( 'Scheduled pickup reminder skipped: order is marked as collected.', 'twilio-order-communicator' )
			);
			return;
		}

		$ready_bare = TOC_Statuses::bare_status( TOC_Statuses::mapped_ready_status() );
		if ( $order->get_status() !== $ready_bare ) {
			return;
		}

		// Local Pickup filter (matches auto-notify / bulk): never remind a non-pickup order.
		if ( $this->skip_for_local_pickup( $order ) ) {
			$order->add_order_note(
				__( 'Scheduled pickup reminder skipped: order is not Local Pickup (Local Pickup filter is on).', 'twilio-order-communicator' )
			);
			return;
		}

		// Quiet hours: re-defer to window end (same pattern as TOC_Auto).
		if ( TOC_Auto::is_quiet_hours() ) {
			$when = TOC_Auto::next_quiet_hours_end_timestamp();
			$this->schedule_for_order( $order_id, $when );
			$order->add_order_note(
				sprintf(
					/* translators: %s: local datetime when the reminder will run */
					__( 'Scheduled pickup reminder deferred (quiet hours). Rescheduled for %s.', 'twilio-order-communicator' ),
					wp_date( 'M j, Y g:i a', $when )
				)
			);
			return;
		}

		// Recent reminder cooldown (bulk or previous scheduled).
		$last = $order->get_meta( '_toc_last_reminder_at' );
		if ( $last ) {
			$last_ts = is_numeric( $last ) ? (int) $last : strtotime( (string) $last );
			if ( $last_ts && ( time() - $last_ts ) < self::cooldown_seconds() ) {
				$order->add_order_note(
					__( 'Scheduled pickup reminder skipped: a reminder was sent too recently.', 'twilio-order-communicator' )
				);
				return;
			}
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			$order->add_order_note(
				__( 'Scheduled pickup reminder skipped: no phone number.', 'twilio-order-communicator' )
			);
			return;
		}

		// Channel toggles shared with Ready for Pickup auto-notify.
		$do_voice = (int) get_option( 'toc_auto_ready_voice', 1 ) === 1;
		$do_sms   = (int) get_option( 'toc_auto_ready_sms', 0 ) === 1;

		if ( ! $do_voice && ! $do_sms ) {
			$order->add_order_note(
				__( 'Scheduled pickup reminder skipped: Ready for Pickup voice and SMS are both disabled in Settings.', 'twilio-order-communicator' )
			);
			return;
		}

		$twilio  = TOC_Twilio::instance();
		$message = $twilio->merge_tags( TOC_Auto::get_message_template( 'reminder' ), $order );
		$did     = false;

		if ( $do_voice ) {
			$result = $twilio->make_call( $phone, $message, $order_id );
			if ( ! empty( $result['success'] ) ) {
				$did = true;
				$order->add_order_note(
					sprintf(
						/* translators: %s: Twilio call SID */
						__( 'Scheduled pickup reminder voice call placed. SID: %s', 'twilio-order-communicator' ),
						$result['sid'] ?? ''
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: error message */
						__( 'Scheduled pickup reminder voice call failed: %s', 'twilio-order-communicator' ),
						$result['error'] ?? 'unknown'
					)
				);
			}
		}

		if ( $do_sms ) {
			// force=false so consent + STOP list are respected (same as auto/bulk).
			$result = $twilio->send_sms( $phone, $message, $order_id, false );
			if ( ! empty( $result['success'] ) ) {
				$did = true;
				$order->add_order_note(
					__( 'Scheduled pickup reminder SMS sent.', 'twilio-order-communicator' )
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: error / skip reason */
						__( 'Scheduled pickup reminder SMS not sent: %s', 'twilio-order-communicator' ),
						$result['error'] ?? 'unknown'
					)
				);
			}
		}

		if ( $did ) {
			$order->update_meta_data( '_toc_last_reminder_at', time() );
		}
		$order->save();
	}

	/**
	 * Whether the Local Pickup filter should suppress a reminder for this order.
	 * True only when the filter is enabled AND the order is not a Local Pickup order —
	 * the same rule TOC_Auto and bulk reminders apply.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function skip_for_local_pickup( $order ) {
		if ( (int) get_option( 'toc_ready_require_local_pickup', 0 ) !== 1 ) {
			return false;
		}
		return class_exists( 'TOC_Auto' ) && ! TOC_Auto::is_local_pickup( $order );
	}
}
