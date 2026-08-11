<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX handlers.
 */
trait TOC_Admin_Ajax {

	/* ---------- AJAX ---------- */
	public function ajax_resolve() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$id       = absint( $_POST['id'] ?? 0 );
		$order_id = absint( $_POST['order_id'] ?? 0 );

		if ( $order_id ) {
			TOC_Logger::instance()->mark_order_resolved( $order_id );
			wp_send_json_success();
		} elseif ( $id ) {
			TOC_Logger::instance()->mark_resolved( $id );
			wp_send_json_success();
		}
		wp_send_json_error( __( 'Missing ID', 'twilio-order-communicator' ) );
	}

	public function ajax_bulk() {
		// Sequential bulk: one order per request (called repeatedly from admin JS).
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::send() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id && ! empty( $_POST['order_ids'] ) ) {
			$ids      = array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) );
			$order_id = $ids[0] ?? 0;
		}

		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$mode    = sanitize_key( wp_unslash( $_POST['mode'] ?? 'call' ) );
		if ( ! in_array( $mode, array( 'call', 'sms', 'both' ), true ) ) {
			$mode = 'call';
		}

		if ( ! $order_id || $message === '' ) {
			wp_send_json_error( __( 'Missing data', 'twilio-order-communicator' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found', 'twilio-order-communicator' ) );
		}

		if ( class_exists( 'TOC_Order_Meta' ) && TOC_Order_Meta::is_collected( $order ) ) {
			wp_send_json_success(
				array(
					'order_id' => $order_id,
					'ok'       => false,
					'skipped'  => true,
					'detail'   => __( 'Order is marked as collected', 'twilio-order-communicator' ),
					'call'     => null,
					'sms'      => null,
				)
			);
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			wp_send_json_success(
				array(
					'order_id' => $order_id,
					'ok'       => false,
					'skipped'  => true,
					'detail'   => __( 'No phone number', 'twilio-order-communicator' ),
					'call'     => null,
					'sms'      => null,
				)
			);
		}

		$twilio    = TOC_Twilio::instance();
		$consented = $twilio->customer_consented_sms( $order_id );
		$message   = $twilio->merge_tags( $message, $order );
		$detail    = array();
		$call_ok   = null;
		$sms_ok    = null;
		$did_work  = false;

		// Voice — never gated on SMS consent.
		if ( in_array( $mode, array( 'call', 'both' ), true ) ) {
			$r        = $twilio->make_call( $phone, $message, $order_id );
			$call_ok  = ! empty( $r['success'] );
			$did_work = $did_work || $call_ok;
			if ( $call_ok ) {
				$detail[] = 'Call queued' . ( ! empty( $r['sid'] ) ? ' (' . $r['sid'] . ')' : '' );
			} else {
				$detail[] = 'Call failed: ' . ( $r['error'] ?? 'unknown' );
			}
		}

		// SMS — respects consent when require flag is on (force=false).
		if ( in_array( $mode, array( 'sms', 'both' ), true ) ) {
			if ( (int) get_option( 'toc_require_sms_consent', 1 ) === 1 && ! $consented ) {
				$sms_ok   = false;
				$detail[] = 'SMS skipped (no consent)';
			} else {
				$r        = $twilio->send_sms( $phone, $message, $order_id, false );
				$sms_ok   = ! empty( $r['success'] );
				$did_work = $did_work || $sms_ok;
				if ( $sms_ok ) {
					$detail[] = 'SMS queued';
				} else {
					$detail[] = 'SMS failed: ' . ( $r['error'] ?? 'unknown' );
				}
			}
		}

		if ( $mode === 'call' ) {
			$ok = ( true === $call_ok );
		} elseif ( $mode === 'sms' ) {
			$ok = ( true === $sms_ok );
		} else {
			// both: success if call went out even when SMS skipped for consent
			$ok = ( true === $call_ok ) || ( true === $sms_ok );
		}

		if ( $did_work ) {
			$order->update_meta_data( '_toc_last_reminder_at', time() );
			$order->save();
		}

		if ( isset( $_POST['delay'] ) ) {
			$delay = max( 1, min( 120, absint( $_POST['delay'] ) ) );
			update_option( 'toc_bulk_delay_seconds', $delay, false );
		}

		wp_send_json_success(
			array(
				'order_id' => $order_id,
				'ok'       => $ok,
				'skipped'  => false,
				'consent'  => $consented,
				'call'     => $call_ok,
				'sms'      => $sms_ok,
				'detail'   => implode( '; ', $detail ),
			)
		);
	}

	public function ajax_test() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$twilio = TOC_Twilio::instance();
		$api    = $twilio->test_credentials();
		if ( empty( $api['success'] ) ) {
			wp_send_json_error( $api['error'] ?? __( 'Credential check failed.', 'twilio-order-communicator' ) );
		}

		// TwiML token path (same path real calls use).
		$test_url = $twilio->build_twiml_url( __( 'Connection test successful.', 'twilio-order-communicator' ) );
		$response = wp_remote_get( $test_url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: WordPress HTTP error detail. */
			wp_send_json_error( sprintf( __( 'Twilio OK, but TwiML endpoint unreachable: %s', 'twilio-order-communicator' ), $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( (int) $code !== 200 || strpos( $body, '<Say' ) === false ) {
			/* translators: %s: HTTP response code. */
			wp_send_json_error( sprintf( __( 'Twilio OK, but TwiML endpoint did not return valid XML. Response code: %s', 'twilio-order-communicator' ), $code ) );
		}

		$name = ! empty( $api['friendly_name'] ) ? ' (' . $api['friendly_name'] . ')' : '';
		/* translators: %s: optional Twilio account friendly name in parentheses. */
		wp_send_json_success( sprintf( __( 'Twilio credentials verified%s and the built-in TwiML endpoint is working.', 'twilio-order-communicator' ), $name ) );
	}
}
