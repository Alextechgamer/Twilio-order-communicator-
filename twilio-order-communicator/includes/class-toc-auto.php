<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Auto {

	const DEFERRED_HOOK = 'toc_deferred_auto_notify';

	const KIND_READY   = 'ready_for_pickup';
	const KIND_SHIPPED = 'shipped';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 25, 4 );
		add_action( self::DEFERRED_HOOK, array( $this, 'run_deferred' ), 10, 2 );
	}

	/**
	 * Local Pickup detection — mode from Settings (toc_pickup_match).
	 * Optional secondary filter when toc_ready_require_local_pickup is on.
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

	/**
	 * Message template with fallback to legacy option keys.
	 *
	 * @param string $kind KIND_READY | KIND_SHIPPED | 'reminder' | 'issue'
	 * @return string
	 */
	public static function get_message_template( $kind ) {
		$defaults = array(
			self::KIND_READY   => 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.',
			self::KIND_SHIPPED => 'Hello {customer_first_name}. Your order #{order_number} has shipped. Thank you for your order.',
			'reminder'         => 'Hello {customer_first_name}. This is a reminder that your order #{order_number} is still waiting for pickup. Please stop by at your earliest convenience. Thank you.',
			'issue'            => 'Hello {customer_first_name}. There is an issue with your recent order #{order_number} that requires your attention. Please contact us or reply to this message. Thank you.',
		);

		$map = array(
			self::KIND_READY   => array( 'toc_message_ready_for_pickup', 'toc_default_pickup_message' ),
			self::KIND_SHIPPED => array( 'toc_message_shipped' ),
			'reminder'         => array( 'toc_message_reminder', 'toc_default_reminder_message' ),
			'issue'            => array( 'toc_message_issue', 'toc_default_issue_message' ),
		);

		if ( ! isset( $map[ $kind ] ) ) {
			return '';
		}

		$default = $defaults[ $kind ] ?? '';
		foreach ( $map[ $kind ] as $option_key ) {
			$val = get_option( $option_key, null );
			if ( null !== $val && false !== $val && $val !== '' ) {
				return (string) $val;
			}
		}

		return $default;
	}

	/**
	 * Config for a notification kind.
	 *
	 * @param string $kind KIND_READY | KIND_SHIPPED
	 * @return array|null
	 */
	public static function kind_config( $kind ) {
		if ( $kind === self::KIND_READY ) {
			return array(
				'kind'          => self::KIND_READY,
				'label'         => __( 'Ready for Pickup', 'twilio-order-communicator' ),
				'enabled'       => (int) get_option( 'toc_auto_ready_enabled', 1 ) === 1,
				'voice'         => (int) get_option( 'toc_auto_ready_voice', 1 ) === 1,
				'sms'           => (int) get_option( 'toc_auto_ready_sms', 0 ) === 1,
				'meta'          => '_toc_notified_ready_for_pickup_at',
				'deferred_meta' => '_toc_deferred_ready_until',
				'message'       => self::get_message_template( self::KIND_READY ),
				'mapped_status' => TOC_Statuses::mapped_ready_status(),
			);
		}
		if ( $kind === self::KIND_SHIPPED ) {
			return array(
				'kind'          => self::KIND_SHIPPED,
				'label'         => __( 'Shipped', 'twilio-order-communicator' ),
				'enabled'       => (int) get_option( 'toc_auto_shipped_enabled', 0 ) === 1,
				'voice'         => (int) get_option( 'toc_auto_shipped_voice', 0 ) === 1,
				'sms'           => (int) get_option( 'toc_auto_shipped_sms', 0 ) === 1,
				'meta'          => '_toc_notified_shipped_at',
				'deferred_meta' => '_toc_deferred_shipped_until',
				'message'       => self::get_message_template( self::KIND_SHIPPED ),
				'mapped_status' => TOC_Statuses::mapped_shipped_status(),
			);
		}
		return null;
	}

	/**
	 * @param int    $order_id   Order ID.
	 * @param string $from       Previous status (bare).
	 * @param string $to         New status (bare).
	 * @param object $order      WC_Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		$order_id = absint( $order_id );
		$to       = (string) $to;

		$ready_bare   = TOC_Statuses::bare_status( TOC_Statuses::mapped_ready_status() );
		$shipped_bare = TOC_Statuses::bare_status( TOC_Statuses::mapped_shipped_status() );

		if ( $to === $ready_bare ) {
			$this->maybe_send_status_email( $order_id, self::KIND_READY );
			$this->process_order( $order_id, self::KIND_READY, false );
		}
		if ( $to === $shipped_bare ) {
			$this->maybe_send_status_email( $order_id, self::KIND_SHIPPED );
			$this->process_order( $order_id, self::KIND_SHIPPED, false );
		}
	}

	/**
	 * @param int         $order_id Order ID.
	 * @param string|null $kind     Notification kind (defaults to ready for legacy deferred jobs).
	 */
	public function run_deferred( $order_id, $kind = null ) {
		$kind = is_string( $kind ) && $kind !== '' ? $kind : self::KIND_READY;
		if ( ! in_array( $kind, array( self::KIND_READY, self::KIND_SHIPPED ), true ) ) {
			$kind = self::KIND_READY;
		}
		$this->process_order( (int) $order_id, $kind, true );
	}

	/**
	 * @param int    $order_id      Order ID.
	 * @param string $kind          KIND_READY | KIND_SHIPPED.
	 * @param bool   $from_deferred Whether this run came from the deferred hook.
	 */
	private function process_order( $order_id, $kind, $from_deferred = false ) {
		$config = self::kind_config( $kind );
		if ( ! $config || ! $config['enabled'] ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Collected orders: skip auto-notify (stamp so we do not re-run).
		if ( class_exists( 'TOC_Order_Meta' ) && TOC_Order_Meta::is_collected( $order ) ) {
			if ( ! $order->get_meta( $config['meta'] ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: notification label */
						__( 'Auto notification skipped (%s): order is marked as collected.', 'twilio-order-communicator' ),
						$config['label']
					)
				);
				$order->update_meta_data( $config['meta'], time() );
				$order->delete_meta_data( $config['deferred_meta'] );
				$order->save();
			}
			return;
		}

		// Still on the mapped status? (status may have changed while deferred).
		$current = $order->get_status();
		$expected = TOC_Statuses::bare_status( $config['mapped_status'] );
		if ( $current !== $expected ) {
			return;
		}

		if ( $order->get_meta( $config['meta'] ) ) {
			return;
		}

		// Legacy 1.5.x meta: treat as already notified for Ready for Pickup.
		if ( $kind === self::KIND_READY && $order->get_meta( '_toc_auto_notified_at' ) ) {
			$order->update_meta_data( $config['meta'], $order->get_meta( '_toc_auto_notified_at' ) );
			$order->save();
			return;
		}

		if ( ! $config['voice'] && ! $config['sms'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: notification label */
					__( 'Auto notification skipped (%s): voice and SMS are both disabled.', 'twilio-order-communicator' ),
					$config['label']
				)
			);
			$order->update_meta_data( $config['meta'], time() );
			$order->save();
			return;
		}

		// Optional Local Pickup filter (Ready for Pickup only).
		if ( $kind === self::KIND_READY && (int) get_option( 'toc_ready_require_local_pickup', 0 ) === 1 ) {
			if ( ! self::is_local_pickup( $order ) ) {
				$order->add_order_note(
					__( 'Auto notification skipped (Ready for Pickup): shipping method does not look like Local Pickup.', 'twilio-order-communicator' )
				);
				// Stamp so a re-save does not repeat this check and add another note.
				$order->update_meta_data( $config['meta'], time() );
				$order->save();
				return;
			}
		}

		// Quiet hours: defer instead of calling/texting now.
		if ( self::is_quiet_hours() ) {
			$when = self::next_quiet_hours_end_timestamp();
			$order->update_meta_data( $config['deferred_meta'], $when );
			$order->save();
			$this->schedule_deferred( $order_id, $kind, $when );

			$order->add_order_note(
				sprintf(
					/* translators: 1: notification label, 2: local datetime when notification will run */
					__( 'Auto notification deferred (quiet hours, %1$s). Scheduled for %2$s.', 'twilio-order-communicator' ),
					$config['label'],
					wp_date( 'M j, Y g:i a', $when )
				)
			);
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: notification label */
					__( 'Auto notification skipped (%s): no phone number.', 'twilio-order-communicator' ),
					$config['label']
				)
			);
			// Still stamp so we do not retry endlessly on every status touch.
			$order->update_meta_data( $config['meta'], time() );
			$order->delete_meta_data( $config['deferred_meta'] );
			$order->save();
			return;
		}

		$twilio  = TOC_Twilio::instance();
		$message = $twilio->merge_tags( $config['message'], $order );

		if ( $config['voice'] ) {
			$result = $twilio->make_call( $phone, $message, $order_id );
			if ( ! empty( $result['success'] ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: notification label, 2: Twilio call SID */
						__( 'Auto voice call placed (%1$s). SID: %2$s', 'twilio-order-communicator' ),
						$config['label'],
						$result['sid']
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: 1: notification label, 2: error message */
						__( 'Auto voice call failed (%1$s): %2$s', 'twilio-order-communicator' ),
						$config['label'],
						$result['error'] ?? 'unknown'
					)
				);
			}
		}

		if ( $config['sms'] ) {
			$result = $twilio->send_sms( $phone, $message, $order_id, false );
			if ( ! empty( $result['success'] ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: notification label */
						__( 'Auto SMS sent (%s).', 'twilio-order-communicator' ),
						$config['label']
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: 1: notification label, 2: error / skip reason */
						__( 'Auto SMS not sent (%1$s): %2$s', 'twilio-order-communicator' ),
						$config['label'],
						$result['error'] ?? 'unknown'
					)
				);
			}
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: notification label */
					__( 'Auto SMS skipped (%s): SMS toggle is disabled in OrderRing → Settings.', 'twilio-order-communicator' ),
					$config['label']
				)
			);
		}

		$order->delete_meta_data( $config['deferred_meta'] );
		$order->update_meta_data( $config['meta'], time() );
		if ( $from_deferred ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: notification label */
					__( 'Deferred auto notification completed after quiet hours (%s).', 'twilio-order-communicator' ),
					$config['label']
				)
			);
		}
		$order->save();
	}

	private function schedule_deferred( $order_id, $kind, $timestamp ) {
		$order_id  = absint( $order_id );
		$timestamp = absint( $timestamp );
		$kind      = sanitize_key( $kind );
		if ( ! $order_id || ! $timestamp || ! $kind ) {
			return;
		}

		$args = array( $order_id, $kind );

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::DEFERRED_HOOK, $args, 'toc' ) ) {
				as_schedule_single_action( $timestamp, self::DEFERRED_HOOK, $args, 'toc' );
			}
			return;
		}

		$hook = self::DEFERRED_HOOK;
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}

	/**
	 * Optional customer email when order enters Ready for Pickup / Shipped.
	 * Independent of voice/SMS auto-notify toggles. Quiet hours do not apply
	 * (email is not disruptive like a phone call). Uses WC mailer wrap when available, else wp_mail.
	 * Once per order via _toc_emailed_* meta. Never gated by license.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $kind     KIND_READY | KIND_SHIPPED.
	 */
	public function maybe_send_status_email( $order_id, $kind ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! in_array( $kind, array( self::KIND_READY, self::KIND_SHIPPED ), true ) ) {
			return;
		}

		$cfg = self::email_kind_config( $kind );
		if ( ! $cfg || ! $cfg['enabled'] ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Still on the mapped status?
		$expected = TOC_Statuses::bare_status( $cfg['mapped_status'] );
		if ( $order->get_status() !== $expected ) {
			return;
		}

		if ( $order->get_meta( $cfg['meta'] ) ) {
			return;
		}

		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( $email === '' || ! is_email( $email ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: notification label */
					__( 'Status email skipped (%s): no billing email on the order.', 'twilio-order-communicator' ),
					$cfg['label']
				)
			);
			$order->update_meta_data( $cfg['meta'], time() );
			$order->save();
			return;
		}

		$twilio  = TOC_Twilio::instance();
		$subject = $twilio->merge_tags( $cfg['subject'], $order );
		$body    = $twilio->merge_tags( $cfg['body'], $order );

		// Prefer WooCommerce mailer template wrap (HTML); fallback plain wp_mail.
		$sent = false;
		if ( function_exists( 'WC' ) && WC() && is_callable( array( WC(), 'mailer' ) ) ) {
			$mailer = WC()->mailer();
			if ( $mailer && method_exists( $mailer, 'wrap_message' ) && method_exists( $mailer, 'send' ) ) {
				$heading = $subject;
				// Body setting is plain text (or light HTML); merge_tags then wpautop for WC template.
				$html    = wpautop( wp_kses_post( $body ) );
				$message = $mailer->wrap_message( $heading, $html );
				$sent    = (bool) $mailer->send(
					$email,
					$subject,
					$message,
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
		}

		if ( ! $sent ) {
			// Fallback: plain text wp_mail when WC mailer unavailable or send failed once.
			$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
			$from_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$from_email = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
			if ( is_email( $from_email ) ) {
				$headers[] = 'From: ' . sprintf( '%s <%s>', $from_name, $from_email );
			}
			$sent = (bool) wp_mail( $email, $subject, $body, $headers );
		}

		if ( $sent ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: notification label, 2: recipient email */
					__( 'Status email sent (%1$s) to %2$s.', 'twilio-order-communicator' ),
					$cfg['label'],
					$email
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: notification label */
					__( 'Status email failed (%s): mailer returned false.', 'twilio-order-communicator' ),
					$cfg['label']
				)
			);
		}

		// Stamp either way so retries / status re-saves do not spam.
		$order->update_meta_data( $cfg['meta'], time() );
		$order->save();
	}

	/**
	 * Email settings for a status kind.
	 *
	 * @param string $kind KIND_READY | KIND_SHIPPED.
	 * @return array|null
	 */
	public static function email_kind_config( $kind ) {
		if ( $kind === self::KIND_READY ) {
			return array(
				'kind'          => self::KIND_READY,
				'label'         => __( 'Ready for Pickup', 'twilio-order-communicator' ),
				'enabled'       => (int) get_option( 'toc_email_ready_enabled', 0 ) === 1,
				'meta'          => '_toc_emailed_ready_for_pickup_at',
				'mapped_status' => TOC_Statuses::mapped_ready_status(),
				'subject'       => (string) get_option(
					'toc_email_ready_subject',
					'Your order #{order_number} is ready for pickup'
				),
				'body'          => (string) get_option(
					'toc_email_ready_body',
					"Hello {customer_first_name},\n\nYour order #{order_number} is ready for pickup at {store_name}.\n\nThank you."
				),
			);
		}
		if ( $kind === self::KIND_SHIPPED ) {
			return array(
				'kind'          => self::KIND_SHIPPED,
				'label'         => __( 'Shipped', 'twilio-order-communicator' ),
				'enabled'       => (int) get_option( 'toc_email_shipped_enabled', 0 ) === 1,
				'meta'          => '_toc_emailed_shipped_at',
				'mapped_status' => TOC_Statuses::mapped_shipped_status(),
				'subject'       => (string) get_option(
					'toc_email_shipped_subject',
					'Your order #{order_number} has shipped'
				),
				'body'          => (string) get_option(
					'toc_email_shipped_body',
					"Hello {customer_first_name},\n\nYour order #{order_number} has shipped.\n\nThank you for shopping at {store_name}."
				),
			);
		}
		return null;
	}
}
