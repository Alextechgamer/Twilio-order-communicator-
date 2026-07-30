<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Twilio {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'serve_twiml' ), 5 );
	}

	/**
	 * Built-in TwiML endpoint.
	 * Preferred: ?toc_twiml=1&token=...
	 * Admin preview: ?toc_twiml=1&message=... (manage_woocommerce only).
	 */
	public function serve_twiml() {
		if ( ! isset( $_GET['toc_twiml'] ) || (string) $_GET['toc_twiml'] !== '1' ) {
			return;
		}

		$message = '';
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( $token !== '' ) {
			$stored = get_transient( 'toc_twiml_' . $token );
			if ( is_string( $stored ) && $stored !== '' ) {
				$message = $stored;
			}
		}

		if ( $message === '' && isset( $_GET['message'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
		}

		if ( $message === '' ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'TwiML message not found or expired.';
			exit;
		}

		$voice          = get_option( 'toc_voice', 'alice' );
		$allowed_voices = array( 'alice', 'man', 'woman', 'polly.joanna', 'polly.matthew', 'polly.amy' );
		if ( ! in_array( $voice, $allowed_voices, true ) ) {
			$voice = 'alice';
		}

		$safe_message = htmlspecialchars( $message, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		header( 'Content-Type: text/xml; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		status_header( 200 );

		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<Response>';
		echo '<Say voice="' . esc_attr( $voice ) . '">' . $safe_message . '</Say>';
		echo '</Response>';
		exit;
	}

	public function build_twiml_url( $message ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( 'toc_twiml_' . $token, (string) $message, 15 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'toc_twiml' => '1',
				'token'     => $token,
			),
			$this->public_base_url()
		);
	}

	/** Public site base for webhooks/TwiML. Optional override for reverse proxies. */
	public function public_base_url() {
		$override = trim( (string) get_option( 'toc_webhook_base_url', '' ) );
		if ( $override !== '' ) {
			return trailingslashit( $override );
		}
		return home_url( '/' );
	}

	public function webhook_url( $query_key ) {
		return add_query_arg( $query_key, '1', $this->public_base_url() );
	}

	private function get_credentials() {
		return array(
			'sid'   => (string) get_option( 'toc_account_sid', '' ),
			'token' => (string) get_option( 'toc_auth_token', '' ),
			'from'  => (string) get_option( 'toc_from_number', '' ),
		);
	}

	public function is_configured() {
		$c = $this->get_credentials();
		return $c['sid'] !== '' && $c['token'] !== '' && $c['from'] !== '';
	}

	/**
	 * Replace template merge tags.
	 * {order_number} {order_id} {customer_first_name} {customer_last_name}
	 * {customer_full_name} {store_name} {phone} {order_total} {billing_email}
	 */
	public function merge_tags( $message, $order = null ) {
		$message = (string) $message;
		if ( $message === '' ) {
			return $message;
		}

		$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$replacements = array(
			'{store_name}'          => $store,
			'{order_number}'        => '',
			'{order_id}'            => '',
			'{customer_first_name}' => '',
			'{customer_last_name}'  => '',
			'{customer_full_name}'  => '',
			'{phone}'               => '',
			'{order_total}'         => '',
			'{billing_email}'       => '',
		);

		if ( $order instanceof WC_Order ) {
			$replacements['{order_number}']        = (string) $order->get_order_number();
			$replacements['{order_id}']            = (string) $order->get_id();
			$replacements['{customer_first_name}'] = (string) $order->get_billing_first_name();
			$replacements['{customer_last_name}']  = (string) $order->get_billing_last_name();
			$replacements['{customer_full_name}']  = trim( $order->get_formatted_billing_full_name() );
			$replacements['{phone}']               = (string) $order->get_billing_phone();
			$replacements['{order_total}']         = wp_strip_all_tags( $order->get_formatted_order_total() );
			$replacements['{billing_email}']       = (string) $order->get_billing_email();
		}

		return str_ireplace( array_keys( $replacements ), array_values( $replacements ), $message );
	}

	public function validate_twilio_signature() {
		$creds = $this->get_credentials();
		if ( $creds['token'] === '' ) {
			return false;
		}

		$signature = isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) )
			: '';

		if ( $signature === '' ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$params = wp_unslash( $_POST );
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		foreach ( $this->candidate_signature_urls() as $url ) {
			$data = $url;
			if ( ! empty( $params ) ) {
				ksort( $params );
				foreach ( $params as $key => $value ) {
					if ( is_array( $value ) ) {
						continue;
					}
					$data .= $key . $value;
				}
			}
			$expected = base64_encode( hash_hmac( 'sha1', $data, $creds['token'], true ) );
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	private function candidate_signature_urls() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$urls = array();

		$override = trim( (string) get_option( 'toc_webhook_base_url', '' ) );
		if ( $override !== '' ) {
			$parsed = wp_parse_url( trailingslashit( $override ) );
			if ( ! empty( $parsed['host'] ) ) {
				$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
				$port   = ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
				$urls[] = $scheme . '://' . $parsed['host'] . $port . $uri;
			}
		}

		$urls[] = $this->current_request_url();

		$home = wp_parse_url( home_url( '/' ) );
		if ( ! empty( $home['host'] ) ) {
			$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : 'https';
			$port   = ! empty( $home['port'] ) ? ':' . (int) $home['port'] : '';
			$urls[] = $scheme . '://' . $home['host'] . $port . $uri;
			$urls[] = 'https://' . $home['host'] . $port . $uri;
		}

		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
			$fwd  = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
				? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
				: '';
			$scheme = ( $fwd === 'https' || is_ssl() ) ? 'https' : 'http';
			$urls[] = $scheme . '://' . $host . $uri;
			$urls[] = 'https://' . $host . $uri;
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function current_request_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
			: '';

		if ( $forwarded === 'https' || is_ssl() ) {
			$scheme = 'https';
		} else {
			$scheme = 'http';
		}

		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = isset( $home['host'] ) ? $home['host'] : '';
		$req_host  = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: $home_host;

		if ( $forwarded === '' && ! empty( $home['scheme'] ) ) {
			$scheme = $home['scheme'];
		}

		$host = $home_host !== '' ? $home_host : $req_host;

		if ( ! empty( $home['port'] ) ) {
			$host_only = preg_replace( '/:\d+$/', '', $host );
			$host      = $host_only . ':' . (int) $home['port'];
		}

		return $scheme . '://' . $host . $uri;
	}

	public function test_credentials() {
		$creds = $this->get_credentials();
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => 'Credentials missing. Save Account SID, Auth Token and From Number first.' );
		}

		$response = wp_remote_get(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $creds['sid'] ) . '.json',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $data['sid'] ) ) {
			return array(
				'success'       => true,
				'friendly_name' => isset( $data['friendly_name'] ) ? (string) $data['friendly_name'] : '',
				'status'        => isset( $data['status'] ) ? (string) $data['status'] : '',
			);
		}

		$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
		return array( 'success' => false, 'error' => 'Twilio rejected credentials: ' . $msg );
	}

	public function send_sms( $to, $body, $order_id = 0, $force = false ) {
		$creds = $this->get_credentials();
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => 'Twilio credentials not configured.' );
		}

		$to = TOC_Logger::instance()->normalize_phone( $to );
		if ( empty( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid phone number.' );
		}

		if ( ! $force && $this->phone_is_opted_out( $to ) ) {
			return array( 'success' => false, 'error' => 'Phone number has opted out (STOP).' );
		}

		if ( ! $force && get_option( 'toc_require_sms_consent', 1 ) ) {
			if ( $order_id && ! $this->customer_consented_sms( $order_id ) ) {
				return array( 'success' => false, 'error' => 'Customer has not consented to SMS.' );
			}
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$body = $this->merge_tags( $body, $order );
		}

		$status_cb = $this->webhook_url( 'toc_msg_status' );

		$response = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $creds['sid'] ) . '/Messages.json',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ),
				),
				'body'    => array(
					'To'                   => $to,
					'From'                 => $creds['from'],
					'Body'                 => $body,
					'StatusCallback'       => $status_cb,
					'StatusCallbackMethod' => 'POST',
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $data['sid'] ) ) {
			TOC_Logger::instance()->log(
				array(
					'order_id'   => $order_id,
					'phone'      => $to,
					'direction'  => 'outbound',
					'type'       => 'sms',
					'body'       => $body,
					'twilio_sid' => $data['sid'],
					'status'     => isset( $data['status'] ) ? $data['status'] : 'queued',
				)
			);
			return array(
				'success' => true,
				'sid'     => $data['sid'],
				'status'  => $data['status'] ?? 'queued',
			);
		}

		return array( 'success' => false, 'error' => $data['message'] ?? 'Unknown Twilio error' );
	}

	public function make_call( $to, $message, $order_id = 0 ) {
		$creds = $this->get_credentials();
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => 'Twilio credentials not configured.' );
		}

		$to = TOC_Logger::instance()->normalize_phone( $to );
		if ( empty( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid phone number.' );
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$message = $this->merge_tags( $message, $order );
		}

		$twiml_url    = $this->build_twiml_url( $message );
		$callback_url = $this->webhook_url( 'toc_status' );

		$response = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $creds['sid'] ) . '/Calls.json',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ),
				),
				'body'    => array(
					'To'                   => $to,
					'From'                 => $creds['from'],
					'Url'                  => $twiml_url,
					'StatusCallback'       => $callback_url,
					'StatusCallbackEvent'  => array( 'completed' ),
					'StatusCallbackMethod' => 'POST',
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $data['sid'] ) ) {
			TOC_Logger::instance()->log(
				array(
					'order_id'   => $order_id,
					'phone'      => $to,
					'direction'  => 'outbound',
					'type'       => 'voice',
					'body'       => $message,
					'twilio_sid' => $data['sid'],
					'status'     => $data['status'] ?? 'queued',
				)
			);

			if ( $order_id ) {
				$order_obj = wc_get_order( $order_id );
				if ( $order_obj ) {
					$order_obj->update_meta_data( '_toc_last_call_sid', $data['sid'] );
					$order_obj->save();
				}
			}

			return array(
				'success' => true,
				'sid'     => $data['sid'],
				'status'  => $data['status'] ?? 'queued',
			);
		}

		return array( 'success' => false, 'error' => $data['message'] ?? 'Unknown Twilio error' );
	}

	public function customer_consented_sms( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$phone = TOC_Logger::instance()->normalize_phone( $order->get_billing_phone() );
		if ( $phone && $this->phone_is_opted_out( $phone ) ) {
			return false;
		}

		$meta_key = get_option( 'toc_sms_consent_meta', '_toc_sms_consent' );
		if ( $meta_key && $this->is_truthy_consent( $order->get_meta( $meta_key ) ) ) {
			return true;
		}

		$fallbacks = array(
			'_toc_sms_consent',
			'_sms_consent',
			'sms_opt_in',
			'_wc_sms_consent',
			'billing_sms_consent',
			'sms_consent',
			'_sms_opt_in',
			'toc_sms_consent',
			'_billing_sms_consent',
			'accept_sms',
			'_accept_sms',
		);
		foreach ( $fallbacks as $key ) {
			if ( $this->is_truthy_consent( $order->get_meta( $key ) ) ) {
				return true;
			}
		}

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			foreach ( array( $meta_key, '_toc_sms_consent', 'sms_opt_in', '_sms_consent' ) as $key ) {
				if ( $key && $this->is_truthy_consent( get_user_meta( $user_id, $key, true ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public function set_sms_consent( $order_id, $consented, $phone = '' ) {
		$value    = $consented ? 'yes' : 'no';
		$meta_key = get_option( 'toc_sms_consent_meta', '_toc_sms_consent' );
		if ( ! $meta_key ) {
			$meta_key = '_toc_sms_consent';
		}

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( $meta_key, $value );
				$order->update_meta_data( '_toc_sms_consent', $value );
				if ( ! $phone ) {
					$phone = $order->get_billing_phone();
				}
				$user_id = $order->get_user_id();
				if ( $user_id ) {
					update_user_meta( $user_id, $meta_key, $value );
					update_user_meta( $user_id, '_toc_sms_consent', $value );
				}
				$order->save();
			}
		}

		$norm = TOC_Logger::instance()->normalize_phone( $phone );
		if ( $norm ) {
			if ( $consented ) {
				$this->remove_opt_out( $norm );
			} else {
				$this->add_opt_out( $norm );
			}
		}
	}

	public function phone_is_opted_out( $phone ) {
		return TOC_Logger::instance()->phone_is_opted_out( $phone );
	}

	public function add_opt_out( $phone ) {
		TOC_Logger::instance()->add_opt_out_phone( $phone );
	}

	public function remove_opt_out( $phone ) {
		TOC_Logger::instance()->remove_opt_out_phone( $phone );
	}

	public function is_truthy_consent( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value === 1;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, array( 'yes', 'y', '1', 'true', 'on', 'checked', 'opt-in', 'optin', 'agreed', 'agree' ), true );
	}
}
