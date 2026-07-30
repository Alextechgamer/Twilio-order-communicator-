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
				global $wpdb;
				$table = $wpdb->prefix . 'toc_communications';
				$row   = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT order_id FROM {$table} WHERE twilio_sid = %s LIMIT 1",
						$sid
					)
				);
				if ( $row && $row->order_id ) {
					$order = wc_get_order( $row->order_id );
					if ( $order ) {
						$order->add_order_note(
							sprintf( 'Voice call status: %s (SID: %s)', ucfirst( $status ), $sid )
						);
					}
				}
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

			// Note only failures / undelivered to avoid noise.
			if ( in_array( $status, array( 'failed', 'undelivered' ), true ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'toc_communications';
				$row   = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT order_id FROM {$table} WHERE twilio_sid = %s LIMIT 1",
						$sid
					)
				);
				if ( $row && $row->order_id ) {
					$order = wc_get_order( $row->order_id );
					if ( $order ) {
						$err = isset( $_POST['ErrorCode'] ) ? sanitize_text_field( wp_unslash( $_POST['ErrorCode'] ) ) : '';
						$order->add_order_note(
							sprintf(
								'SMS %s (SID: %s)%s',
								$status,
								$sid,
								$err ? ' Error: ' . $err : ''
							)
						);
					}
				}
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
		$body = isset( $_POST['Body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['Body'] ) ) : '';
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
		// Strip common punctuation for keyword match.
		$keyword = preg_replace( '/[^A-Z]/', '', $norm );

		$stop_words = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' );
		$help_words = array( 'HELP', 'INFO' );
		$start_words = array( 'START', 'YES', 'UNSTOP' );

		if ( in_array( $keyword, $stop_words, true ) ) {
			$twilio->set_sms_consent( $order_id, false, $from );
			$reply = get_option(
				'toc_stop_reply',
				'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.'
			);
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( 'Customer opted out of SMS (STOP).' );
				}
			}
		} elseif ( in_array( $keyword, $help_words, true ) ) {
			$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$reply = get_option(
				'toc_help_reply',
				sprintf(
					'%s: For help, reply to this number or contact the store. Reply STOP to opt out of SMS.',
					$store
				)
			);
		} elseif ( in_array( $keyword, $start_words, true ) ) {
			// Re-subscribe at phone level; order consent left for checkout/staff.
			$twilio->remove_opt_out( $from );
			if ( $order_id ) {
				$twilio->set_sms_consent( $order_id, true, $from );
			}
			$reply = get_option(
				'toc_start_reply',
				'You have been re-subscribed to SMS messages. Reply STOP to opt out.'
			);
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( 'Customer re-subscribed to SMS (START).' );
				}
			}
		} else {
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note(
						sprintf( 'Incoming SMS: "%s"', wp_trim_words( $body, 20 ) )
					);
				}
			}
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
