<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Webhooks {

	const REST_NAMESPACE = 'toc/v1';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'handle_query_aliases' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Preferred REST webhook URL for a channel.
	 *
	 * Uses WordPress rest_url() so plain permalinks (index.php?rest_route=…),
	 * pretty permalinks (/wp-json/…), and subdirectory installs stay correct.
	 * When toc_webhook_base_url is set (reverse proxy), the home_url origin is
	 * swapped for that override while keeping the REST path/query intact.
	 *
	 * @param string $route sms|voice-status|message-status
	 * @return string
	 */
	public static function rest_url( $route ) {
		$route = ltrim( (string) $route, '/' );
		$path  = self::REST_NAMESPACE . '/' . $route;
		// Call the global WP helper (not this method).
		$url = \rest_url( $path );

		$override = trim( (string) get_option( 'toc_webhook_base_url', '' ) );
		if ( $override === '' ) {
			return $url;
		}

		$home = untrailingslashit( home_url( '/' ) );
		$over = untrailingslashit( $override );
		if ( $home !== '' && strpos( $url, $home ) === 0 ) {
			return $over . substr( $url, strlen( $home ) );
		}

		// Fallback when home_url prefix does not match (odd proxy setups).
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$built = trailingslashit( $over ) . ltrim( $path, '/' );
		if ( $query !== '' ) {
			$built .= '?' . $query;
		}
		return $built;
	}

	/**
	 * Permanent query-string aliases (?toc_sms=1 etc.) so existing Twilio configs keep working.
	 */
	public function handle_query_aliases() {
		if ( isset( $_GET['toc_status'] ) && (string) $_GET['toc_status'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->voice_status();
			exit;
		}
		if ( isset( $_GET['toc_msg_status'] ) && (string) $_GET['toc_msg_status'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->message_status();
			exit;
		}
		if ( isset( $_GET['toc_sms'] ) && (string) $_GET['toc_sms'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->incoming_sms();
			exit;
		}
	}

	public function register_rest_routes() {
		$routes = array(
			'/sms'            => 'rest_incoming_sms',
			'/voice-status'   => 'rest_voice_status',
			'/message-status' => 'rest_message_status',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => '__return_true', // Authenticated via Twilio signature.
				)
			);
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return void Serves raw response for Twilio (not JSON).
	 */
	public function rest_incoming_sms( $request ) {
		$this->hydrate_post_from_rest( $request );
		ob_start();
		$this->incoming_sms();
		$this->serve_raw_and_exit( (string) ob_get_clean(), 'text/xml; charset=utf-8' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public function rest_voice_status( $request ) {
		$this->hydrate_post_from_rest( $request );
		ob_start();
		$this->voice_status();
		$this->serve_raw_and_exit( (string) ob_get_clean(), 'text/plain; charset=utf-8' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public function rest_message_status( $request ) {
		$this->hydrate_post_from_rest( $request );
		ob_start();
		$this->message_status();
		$this->serve_raw_and_exit( (string) ob_get_clean(), 'text/plain; charset=utf-8' );
	}

	/**
	 * Bypass WP REST JSON encoding — Twilio expects raw XML/text bodies.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header.
	 */
	private function serve_raw_and_exit( $body, $content_type ) {
		$code = http_response_code();
		if ( ! $code || $code < 100 ) {
			$code = 200;
		}
		status_header( $code );
		header( 'Content-Type: ' . $content_type );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twilio TwiML / plain OK body.
		echo $body !== '' ? $body : ( strpos( $content_type, 'xml' ) !== false ? '' : 'OK' );
		exit;
	}

	/**
	 * Copy REST params into $_POST so existing handlers / signature validation work.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function hydrate_post_from_rest( $request ) {
		$params = $request->get_body_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}
		if ( ! is_array( $params ) ) {
			return;
		}
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$_POST[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
	}

	private function require_valid_twilio() {
		if ( TOC_Twilio::instance()->validate_twilio_signature() ) {
			return true;
		}

		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Invalid Twilio signature.';
		return false;
	}

	/**
	 * Add an order note for a communication SID when an order is linked.
	 *
	 * @param string $sid  Twilio SID.
	 * @param string $note Note text.
	 */
	private function note_order_for_sid( $sid, $note ) {
		$order_id = TOC_Logger::instance()->get_order_id_by_sid( $sid );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( $note );
		}
	}

	private function voice_status() {
		if ( ! $this->require_valid_twilio() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sid    = isset( $_POST['CallSid'] ) ? sanitize_text_field( wp_unslash( $_POST['CallSid'] ) ) : '';
		$status = isset( $_POST['CallStatus'] ) ? sanitize_text_field( wp_unslash( $_POST['CallStatus'] ) ) : '';

		if ( $sid && $status ) {
			TOC_Logger::instance()->update_status_by_sid( $sid, $status );

			$note_statuses = array( 'completed', 'busy', 'failed', 'no-answer', 'canceled' );
			if ( in_array( $status, $note_statuses, true ) ) {
				$this->note_order_for_sid(
					$sid,
					sprintf(
						/* translators: 1: call status, 2: Twilio SID */
						__( 'Voice call status: %1$s (SID: %2$s)', 'twilio-order-communicator' ),
						ucfirst( $status ),
						$sid
					)
				);
			}
		}

		status_header( 200 );
		echo 'OK';
	}

	/**
	 * SMS delivery status callback (queued → sent → delivered / failed).
	 */
	private function message_status() {
		if ( ! $this->require_valid_twilio() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sid    = isset( $_POST['MessageSid'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageSid'] ) ) : '';
		$status = isset( $_POST['MessageStatus'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageStatus'] ) ) : '';

		if ( $sid && $status ) {
			TOC_Logger::instance()->update_status_by_sid( $sid, $status );

			if ( in_array( $status, array( 'failed', 'undelivered' ), true ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$err = isset( $_POST['ErrorCode'] ) ? sanitize_text_field( wp_unslash( $_POST['ErrorCode'] ) ) : '';
				$this->note_order_for_sid(
					$sid,
					sprintf(
						/* translators: 1: message status, 2: Twilio SID, 3: optional error suffix */
						__( 'SMS %1$s (SID: %2$s)%3$s', 'twilio-order-communicator' ),
						$status,
						$sid,
						$err ? ' ' . sprintf(
							/* translators: %s: Twilio error code */
							__( 'Error: %s', 'twilio-order-communicator' ),
							$err
						) : ''
					)
				);
				$this->maybe_send_delivery_failure_alert( $sid, $status, $err );
			}
		}

		status_header( 200 );
		echo 'OK';
	}

	/**
	 * Optional staff email when SMS delivery fails (Settings → Delivery failure alerts).
	 * Deduplicated per MessageSid so Twilio retries do not spam.
	 *
	 * @param string $sid    MessageSid.
	 * @param string $status failed|undelivered.
	 * @param string $error  Twilio ErrorCode (may be empty).
	 */
	private function maybe_send_delivery_failure_alert( $sid, $status, $error = '' ) {
		if ( (int) get_option( 'toc_delivery_alert_enabled', 0 ) !== 1 ) {
			return;
		}

		$sid = sanitize_text_field( (string) $sid );
		if ( $sid === '' ) {
			return;
		}

		// Dedup: one alert per SID for 7 days (covers Twilio retry windows).
		$transient_key = 'toc_sms_fail_alert_' . md5( $sid );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		// Claim immediately so concurrent retries do not double-send.
		set_transient( $transient_key, 1, WEEK_IN_SECONDS );

		$to = trim( (string) get_option( 'toc_delivery_alert_email', '' ) );
		if ( $to === '' || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( $to === '' || ! is_email( $to ) ) {
			return;
		}

		$logger = TOC_Logger::instance();
		$row    = $logger->get_row_by_sid( $sid );
		$order_id = $row && ! empty( $row->order_id ) ? absint( $row->order_id ) : $logger->get_order_id_by_sid( $sid );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( $store === '' ) {
			$store = 'Store';
		}

		$order_label = $order ? $order->get_order_number() : ( $order_id ? (string) $order_id : '—' );
		$status_lbl  = sanitize_text_field( (string) $status );
		$error       = sanitize_text_field( (string) $error );

		$subject = sprintf(
			/* translators: 1: store name, 2: SMS status (failed/undelivered), 3: order number */
			__( '[%1$s] SMS %2$s — order #%3$s', 'twilio-order-communicator' ),
			$store,
			$status_lbl,
			$order_label
		);

		$phone = $row && ! empty( $row->phone ) ? (string) $row->phone : '';
		if ( $phone === '' && $order ) {
			$phone = (string) $order->get_billing_phone();
		}

		$body_snippet = '';
		if ( $row && isset( $row->body ) && (string) $row->body !== '' ) {
			$body_snippet = wp_trim_words( wp_strip_all_tags( (string) $row->body ), 40, '…' );
		}

		$edit_url = $order ? $order->get_edit_order_url() : '';

		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: SMS status, 2: order number */
			__( 'An outbound SMS was reported as %1$s for order #%2$s.', 'twilio-order-communicator' ),
			$status_lbl,
			$order_label
		);
		$lines[] = '';
		if ( $edit_url ) {
			$lines[] = __( 'Order:', 'twilio-order-communicator' ) . ' ' . $edit_url;
		} elseif ( $order_id ) {
			$lines[] = __( 'Order ID:', 'twilio-order-communicator' ) . ' ' . $order_id;
		}
		if ( $phone !== '' ) {
			$lines[] = __( 'Phone:', 'twilio-order-communicator' ) . ' ' . $phone;
		}
		$lines[] = __( 'Status:', 'twilio-order-communicator' ) . ' ' . $status_lbl;
		if ( $error !== '' ) {
			$lines[] = __( 'Twilio ErrorCode:', 'twilio-order-communicator' ) . ' ' . $error;
		}
		$lines[] = __( 'MessageSid:', 'twilio-order-communicator' ) . ' ' . $sid;
		if ( $body_snippet !== '' ) {
			$lines[] = __( 'Message:', 'twilio-order-communicator' ) . ' ' . $body_snippet;
		}
		$lines[] = '';
		$lines[] = __( 'This alert was sent by Twilio Order Communicator. Messaging is not blocked by license state.', 'twilio-order-communicator' );

		$body = implode( "\n", $lines );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		wp_mail( $to, $subject, $body, $headers );
	}

	private function incoming_sms() {
		if ( ! $this->require_valid_twilio() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$from = isset( $_POST['From'] ) ? sanitize_text_field( wp_unslash( $_POST['From'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$body = isset( $_POST['Body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['Body'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sid  = isset( $_POST['MessageSid'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageSid'] ) ) : '';

		if ( empty( $from ) || $body === '' ) {
			status_header( 400 );
			echo 'Missing data';
			return;
		}

		$logger   = TOC_Logger::instance();
		$twilio   = TOC_Twilio::instance();
		$order_id = $logger->find_order_by_phone( $from );

		$logger->log(
			array(
				'order_id'      => $order_id,
				'phone'         => $from,
				'direction'     => 'inbound',
				'type'          => 'sms',
				'body'          => $body,
				'twilio_sid'    => $sid,
				'status'        => 'received',
				'admin_user_id' => 0,
			)
		);

		$reply   = '';
		$norm    = preg_replace( '/\s+/', ' ', strtoupper( trim( $body ) ) );
		$keyword = preg_replace( '/[^A-Z]/', '', $norm );

		$stop_words  = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' );
		$help_words  = array( 'HELP', 'INFO' );
		// START / UNSTOP only — do not treat bare YES as re-subscribe (too easy to match casual replies).
		$start_words = array( 'START', 'UNSTOP' );

		if ( in_array( $keyword, $stop_words, true ) ) {
			$twilio->set_sms_consent( $order_id, false, $from );
			$reply = get_option(
				'toc_stop_reply',
				__( 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.', 'twilio-order-communicator' )
			);
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( __( 'Customer opted out of SMS (STOP).', 'twilio-order-communicator' ) );
				}
			}
		} elseif ( in_array( $keyword, $help_words, true ) ) {
			$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$saved = get_option( 'toc_help_reply', '' );
			$reply = ( is_string( $saved ) && $saved !== '' )
				? $saved
				: sprintf(
					/* translators: %s: store name */
					__( '%s: For help, reply to this number or contact the store. Reply STOP to opt out of SMS.', 'twilio-order-communicator' ),
					$store
				);
		} elseif ( in_array( $keyword, $start_words, true ) ) {
			$twilio->remove_opt_out( $from );
			if ( $order_id ) {
				$twilio->set_sms_consent( $order_id, true, $from );
			}
			$reply = get_option(
				'toc_start_reply',
				__( 'You have been re-subscribed to SMS messages. Reply STOP to opt out.', 'twilio-order-communicator' )
			);
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( __( 'Customer re-subscribed to SMS (START).', 'twilio-order-communicator' ) );
				}
			}
		} else {
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: trimmed inbound SMS body */
							__( 'Incoming SMS: "%s"', 'twilio-order-communicator' ),
							wp_trim_words( $body, 20 )
						)
					);
				}
			}
		}

		// Log auto keyword reply so staff see it in order chat (task 11).
		if ( $reply !== '' ) {
			$logger->log(
				array(
					'order_id'      => $order_id,
					'phone'         => $from,
					'direction'     => 'outbound',
					'type'          => 'sms',
					'body'          => $reply,
					'twilio_sid'    => '',
					'status'        => 'auto-reply',
					'admin_user_id' => 0,
				)
			);
		}

		header( 'Content-Type: text/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<Response>';
		if ( $reply !== '' ) {
			echo '<Message>' . htmlspecialchars( $reply, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</Message>';
		}
		echo '</Response>';
	}
}
