<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Webhooks {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'handle' ) );
	}

	public function handle() {
		if ( isset( $_GET['toc_status'] ) && (string) $_GET['toc_status'] === '1' ) {
			$this->voice_status();
			exit;
		}
		if ( isset( $_GET['toc_msg_status'] ) && (string) $_GET['toc_msg_status'] === '1' ) {
			$this->message_status();
			exit;
		}
		if ( isset( $_GET['toc_sms'] ) && (string) $_GET['toc_sms'] === '1' ) {
			$this->incoming_sms();
			exit;
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
			}
		}

		status_header( 200 );
		echo 'OK';
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

		$reply = '';
		$norm  = preg_replace( '/\s+/', ' ', strtoupper( trim( $body ) ) );
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

		// Log auto keyword reply so staff see it in order chat.
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
