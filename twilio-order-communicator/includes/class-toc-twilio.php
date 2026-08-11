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
				// One-time use: consume the token immediately so the URL — which exposes the
				// customer name and order number — cannot be replayed for the rest of the
				// transient's TTL by anyone who captures it. Twilio fetches the TwiML once.
				delete_transient( 'toc_twiml_' . $token );
			}
		}

		if ( $message === '' && isset( $_GET['message'] ) && current_user_can( TOC_Caps::manage() ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
		}

		if ( $message === '' ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'TwiML message not found or expired.';
			exit;
		}

		$voice = self::twiml_voice_attribute( get_option( 'toc_voice', 'alice' ) );

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

	/**
	 * Map stored voice option to the string Twilio expects in <Say voice="...">.
	 * Settings keep lowercase polly.* keys; TwiML needs Polly.Joanna etc.
	 *
	 * @param string $stored Option value (alice|man|woman|polly.joanna|…).
	 * @return string
	 */
	public static function twiml_voice_attribute( $stored ) {
		$stored = is_string( $stored ) ? strtolower( trim( $stored ) ) : '';
		$map    = array(
			'alice'         => 'alice',
			'man'           => 'man',
			'woman'         => 'woman',
			'polly.joanna'  => 'Polly.Joanna',
			'polly.matthew' => 'Polly.Matthew',
			'polly.amy'     => 'Polly.Amy',
		);
		return isset( $map[ $stored ] ) ? $map[ $stored ] : 'alice';
	}

	/**
	 * Strict E.164 check (e.g. +15055551234): leading +, first digit 1-9, 8-15 digits total.
	 * Used to validate the From number at config time and each recipient at send time so a
	 * malformed number fails fast with a clear error instead of a silent Twilio rejection.
	 *
	 * @param string $number Candidate number.
	 * @return bool
	 */
	public static function is_e164( $number ) {
		return (bool) preg_match( '/^\+[1-9]\d{7,14}$/', (string) $number );
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
		$map = array(
			'toc_sms'        => 'sms',
			'toc_status'     => 'voice-status',
			'toc_msg_status' => 'message-status',
		);

		// Prefer REST routes; query-string aliases remain supported forever.
		if ( isset( $map[ $query_key ] ) ) {
			return TOC_Webhooks::rest_url( $map[ $query_key ] );
		}

		return add_query_arg( $query_key, '1', $this->public_base_url() );
	}

	private function get_credentials() {
		$sid = defined( 'TOC_ACCOUNT_SID' ) && TOC_ACCOUNT_SID
			? (string) TOC_ACCOUNT_SID
			: (string) get_option( 'toc_account_sid', '' );
		$token = defined( 'TOC_AUTH_TOKEN' ) && TOC_AUTH_TOKEN
			? (string) TOC_AUTH_TOKEN
			: (string) get_option( 'toc_auth_token', '' );
		$from = defined( 'TOC_FROM_NUMBER' ) && TOC_FROM_NUMBER
			? (string) TOC_FROM_NUMBER
			: (string) get_option( 'toc_from_number', '' );

		return array(
			'sid'   => $sid,
			'token' => $token,
			'from'  => $from,
		);
	}

	/**
	 * Whether a credential is locked via wp-config constant.
	 *
	 * @param string $which sid|token|from
	 * @return bool
	 */
	public function credential_is_constant( $which ) {
		if ( $which === 'sid' ) {
			return defined( 'TOC_ACCOUNT_SID' ) && TOC_ACCOUNT_SID;
		}
		if ( $which === 'token' ) {
			return defined( 'TOC_AUTH_TOKEN' ) && TOC_AUTH_TOKEN;
		}
		if ( $which === 'from' ) {
			return defined( 'TOC_FROM_NUMBER' ) && TOC_FROM_NUMBER;
		}
		return false;
	}

	/**
	 * Shared Twilio REST request helper.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Path under /2010-04-01/Accounts/{SID}/ (or absolute path starting with /Accounts/…).
	 * @param array  $body   Request body for POST.
	 * @return array{success:bool,code:int,data:array,error?:string}
	 */
	private function request( $method, $path, $body = array() ) {
		$creds = $this->get_credentials();
		if ( $creds['sid'] === '' || $creds['token'] === '' ) {
			return array(
				'success' => false,
				'code'    => 0,
				'data'    => array(),
				'error'   => 'Twilio credentials not configured.',
			);
		}

		$method = strtoupper( (string) $method );
		$path   = ltrim( (string) $path, '/' );

		if ( strpos( $path, 'Accounts/' ) === 0 ) {
			$url = 'https://api.twilio.com/2010-04-01/' . $path;
		} else {
			$url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $creds['sid'] ) . '/' . $path;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ),
			),
			'timeout' => 20,
		);

		if ( $method === 'POST' ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'data'    => array(),
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'code'    => $code,
				'data'    => $data,
			);
		}

		$msg = ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
		return array(
			'success' => false,
			'code'    => $code,
			'data'    => $data,
			'error'   => $msg,
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
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => 'Credentials missing. Save Account SID, Auth Token and From Number first.' );
		}

		$creds = $this->get_credentials();
		$result = $this->request( 'GET', 'Accounts/' . rawurlencode( $creds['sid'] ) . '.json' );

		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Twilio rejected credentials: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data = $result['data'];
		if ( empty( $data['sid'] ) ) {
			return array( 'success' => false, 'error' => 'Twilio rejected credentials: unexpected response.' );
		}

		return array(
			'success'       => true,
			'friendly_name' => isset( $data['friendly_name'] ) ? (string) $data['friendly_name'] : '',
			'status'        => isset( $data['status'] ) ? (string) $data['status'] : '',
		);
	}

	/**
	 * Optional SMS compliance footer appended to outbound bodies.
	 *
	 * @param string $body Message body.
	 * @return string
	 */
	public function append_sms_footer( $body ) {
		if ( ! (int) get_option( 'toc_sms_footer_enabled', 0 ) ) {
			return $body;
		}
		$footer = trim( (string) get_option( 'toc_sms_footer_text', 'Reply STOP to opt out. Msg & data rates may apply.' ) );
		if ( $footer === '' ) {
			return $body;
		}
		$body = rtrim( (string) $body );
		if ( stripos( $body, 'STOP' ) !== false && stripos( $body, 'opt out' ) !== false ) {
			return $body;
		}
		return $body . "\n\n" . $footer;
	}

	public function send_sms( $to, $body, $order_id = 0, $force = false ) {
		$creds = $this->get_credentials();
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => 'Twilio credentials not configured.' );
		}

		$to = TOC_Logger::instance()->normalize_phone( $to );
		if ( empty( $to ) || ! self::is_e164( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid phone number (must be E.164, e.g. +15055551234).' );
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

		$body = $this->append_sms_footer( $body );

		$status_cb = $this->webhook_url( 'toc_msg_status' );

		$result = $this->request(
			'POST',
			'Messages.json',
			array(
				'To'                   => $to,
				'From'                 => $creds['from'],
				'Body'                 => $body,
				'StatusCallback'       => $status_cb,
				'StatusCallbackMethod' => 'POST',
			)
		);

		if ( empty( $result['success'] ) ) {
			return array( 'success' => false, 'error' => $result['error'] ?? 'Unknown Twilio error' );
		}

		$data = $result['data'];
		if ( empty( $data['sid'] ) ) {
			return array( 'success' => false, 'error' => 'Unknown Twilio error' );
		}

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

	public function make_call( $to, $message, $order_id = 0 ) {
		$creds = $this->get_credentials();
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => 'Twilio credentials not configured.' );
		}

		$to = TOC_Logger::instance()->normalize_phone( $to );
		if ( empty( $to ) || ! self::is_e164( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid phone number (must be E.164, e.g. +15055551234).' );
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$message = $this->merge_tags( $message, $order );
		}

		$twiml_url    = $this->build_twiml_url( $message );
		$callback_url = $this->webhook_url( 'toc_status' );

		$result = $this->request(
			'POST',
			'Calls.json',
			array(
				'To'                   => $to,
				'From'                 => $creds['from'],
				'Url'                  => $twiml_url,
				'StatusCallback'       => $callback_url,
				'StatusCallbackEvent'  => array( 'completed' ),
				'StatusCallbackMethod' => 'POST',
			)
		);

		if ( empty( $result['success'] ) ) {
			return array( 'success' => false, 'error' => $result['error'] ?? 'Unknown Twilio error' );
		}

		$data = $result['data'];
		if ( empty( $data['sid'] ) ) {
			return array( 'success' => false, 'error' => 'Unknown Twilio error' );
		}

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

		// WC additional checkout field meta keys (block): any key containing sms-consent.
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			if ( $key === '' ) {
				continue;
			}
			if ( false === stripos( $key, 'sms-consent' ) && false === stripos( $key, 'sms_consent' ) ) {
				continue;
			}
			if ( $this->is_truthy_consent( $data['value'] ?? '' ) ) {
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

	/**
	 * Explicit consent state for admin display: yes|no|unknown.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public function get_sms_consent_state( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 'unknown';
		}
		if ( $this->customer_consented_sms( $order_id ) ) {
			return 'yes';
		}
		// Explicit no on canonical keys only.
		$keys = array_filter(
			array(
				get_option( 'toc_sms_consent_meta', '_toc_sms_consent' ),
				'_toc_sms_consent',
			)
		);
		foreach ( $keys as $key ) {
			$val = $order->get_meta( $key );
			if ( $val === '' || $val === null ) {
				continue;
			}
			if ( $this->is_falsy_consent( $val ) ) {
				return 'no';
			}
		}
		return 'unknown';
	}

	/**
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function is_falsy_consent( $value ) {
		if ( is_bool( $value ) ) {
			return false === $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value === 0;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $value ) );
		if ( $v === '' ) {
			return false;
		}
		return in_array( $v, array( 'no', 'n', '0', 'false', 'off', 'unchecked', 'opt-out', 'optout', 'declined' ), true );
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
