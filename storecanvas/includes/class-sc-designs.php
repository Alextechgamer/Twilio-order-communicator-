<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved designs — logged-in CPT + guest token/transient (0.7.0).
 *
 * AJAX: sc_save_design / sc_load_design / sc_email_design_link are nopriv + nonce
 * (guest path). sc_list_designs is logged-in only.
 */
class SC_Designs {

	const CPT          = 'sc_design';
	const COOKIE       = 'sc_design_token';
	const TRANSIENT_PF = 'sc_gdesign_';
	const TTL_DAYS     = 14;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'wp_ajax_sc_save_design', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_nopriv_sc_save_design', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_sc_list_designs', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sc_load_design', array( $this, 'ajax_load' ) );
		add_action( 'wp_ajax_nopriv_sc_load_design', array( $this, 'ajax_load' ) );
		add_action( 'wp_ajax_sc_email_design_link', array( $this, 'ajax_email_design_link' ) );
		add_action( 'wp_ajax_nopriv_sc_email_design_link', array( $this, 'ajax_email_design_link' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_save_ui' ), 25 );
	}

	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Saved designs', 'storecanvas' ),
					'singular_name' => __( 'Saved design', 'storecanvas' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => SC_Plugin::MENU_SLUG,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'author' ),
				'exclude_from_search' => true,
			)
		);
	}

	public function render_save_ui() {
		if ( ! is_product() ) {
			return;
		}
		$logged = is_user_logged_in();
		echo '<div class="sc-saved-designs" style="margin:12px 0;" data-guest="' . ( $logged ? '0' : '1' ) . '">';
		echo '<button type="button" class="button" id="sc-save-design">' . esc_html__( 'Save this design', 'storecanvas' ) . '</button> ';
		if ( $logged ) {
			echo '<button type="button" class="button" id="sc-load-designs">' . esc_html__( 'My designs', 'storecanvas' ) . '</button>';
		} else {
			echo '<button type="button" class="button" id="sc-reload-guest-design">' . esc_html__( 'Reload saved design', 'storecanvas' ) . '</button> ';
			echo '<button type="button" class="button" id="sc-email-guest-design">' . esc_html__( 'Email me a link', 'storecanvas' ) . '</button>';
		}
		echo '<div id="sc-designs-list" style="margin-top:8px;"></div>';
		echo '<p class="description" id="sc-guest-design-hint" style="display:none;"></p>';
		echo '</div>';
	}

	public static function ttl() {
		return self::TTL_DAYS * DAY_IN_SECONDS;
	}

	private function make_token() {
		return wp_generate_password( 32, false, false );
	}

	private function transient_key( $token ) {
		return self::TRANSIENT_PF . substr( preg_replace( '/[^a-zA-Z0-9]/', '', $token ), 0, 40 );
	}

	/**
	 * @param int   $product_id Product ID.
	 * @param array $decoded    Payload.
	 * @return string
	 */
	public function save_guest( $product_id, $decoded ) {
		$token = $this->make_token();
		$data  = array(
			'product_id' => (int) $product_id,
			'payload'    => $decoded,
			'created'    => time(),
		);
		set_transient( $this->transient_key( $token ), $data, self::ttl() );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $token, time() + self::ttl(), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE[ self::COOKIE ] = $token;
		return $token;
	}

	/**
	 * @param string $token Token.
	 * @return array|null
	 */
	public function load_guest( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 16 ) {
			return null;
		}
		$data = get_transient( $this->transient_key( $token ) );
		return is_array( $data ) ? $data : null;
	}

	public function ajax_save() {
		check_ajax_referer( 'sc_designs', 'nonce' );

		// Throttle unauthenticated saves so a visitor cannot flood transients / wp_options.
		if ( ! is_user_logged_in()
			&& ! $this->rate_ok( 'save', (int) apply_filters( 'sc_max_guest_saves_per_hour', 20 ), HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => 'too many saves, please try again later' ), 429 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$payload    = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( ! $product_id || ! $payload ) {
			wp_send_json_error( array( 'message' => 'missing data' ), 400 );
		}

		// Bound the stored payload size.
		$max_bytes = (int) apply_filters( 'sc_max_design_bytes', 256 * 1024 );
		if ( is_string( $payload ) && strlen( $payload ) > $max_bytes ) {
			wp_send_json_error( array( 'message' => 'payload too large' ), 413 );
		}

		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => 'bad payload' ), 400 );
		}

		// Defense in depth vs stored/DOM XSS: strip tags from string leaves and bound the
		// structure before it is persisted and later reflected into the product page.
		$decoded = $this->sanitize_payload( $decoded );

		if ( is_user_logged_in() ) {
			if ( ! $title ) {
				$title = sprintf( __( 'Design for product #%d – %s', 'storecanvas' ), $product_id, wp_date( 'Y-m-d H:i' ) );
			}
			$post_id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_author' => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
			}
			update_post_meta( $post_id, '_sc_product_id', $product_id );
			update_post_meta( $post_id, '_sc_design_payload', $decoded );
			wp_send_json_success( array( 'id' => $post_id, 'title' => $title, 'mode' => 'user' ) );
		}

		$token = $this->save_guest( $product_id, $decoded );
		wp_send_json_success(
			array(
				'token'   => $token,
				'mode'    => 'guest',
				'ttlDays' => self::TTL_DAYS,
				'message' => __( 'Design saved on this device for 14 days.', 'storecanvas' ),
			)
		);
	}

	public function ajax_list() {
		check_ajax_referer( 'sc_designs', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login required' ), 403 );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$args       = array(
			'post_type'      => self::CPT,
			'author'         => get_current_user_id(),
			'posts_per_page' => 20,
			'post_status'    => 'publish',
		);
		if ( $product_id ) {
			$args['meta_key']   = '_sc_product_id';
			$args['meta_value'] = $product_id;
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'date'  => $post->post_date,
			);
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Load by CPT id (logged-in) or by guest token.
	 */
	public function ajax_load() {
		check_ajax_referer( 'sc_designs', 'nonce' );

		// Guest token path.
		$token = '';
		if ( ! empty( $_REQUEST['token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_REQUEST['token'] ) );
		} elseif ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		if ( $token && empty( $_REQUEST['id'] ) ) {
			$data = $this->load_guest( $token );
			if ( ! $data ) {
				wp_send_json_error( array( 'message' => 'expired or missing' ), 404 );
			}
			$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;
			if ( $product_id && (int) $data['product_id'] !== $product_id ) {
				wp_send_json_error( array( 'message' => 'wrong product' ), 403 );
			}
			wp_send_json_success(
				array(
					'payload'    => $data['payload'],
					'product_id' => (int) $data['product_id'],
					'token'      => $token,
					'mode'       => 'guest',
				)
			);
		}

		// Logged-in CPT path.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login required' ), 403 );
		}
		$id   = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type || (int) $post->post_author !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}
		wp_send_json_success(
			array(
				'payload'    => get_post_meta( $id, '_sc_design_payload', true ),
				'product_id' => (int) get_post_meta( $id, '_sc_product_id', true ),
				'mode'       => 'user',
			)
		);
	}

	public function ajax_email_design_link() {
		check_ajax_referer( 'sc_designs', 'nonce' );

		// Throttle this unauthenticated send-to-any-address endpoint (email relay abuse).
		if ( ! $this->rate_ok( 'email', (int) apply_filters( 'sc_max_design_emails_per_hour', 10 ), HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => 'too many requests, please try again later' ), 429 );
		}

		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! $token && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		if ( ! is_email( $email ) || ! $product_id || ! $token ) {
			wp_send_json_error( array( 'message' => 'invalid' ), 400 );
		}
		$data = $this->load_guest( $token );
		if ( ! $data || (int) $data['product_id'] !== $product_id ) {
			wp_send_json_error( array( 'message' => 'design not found' ), 404 );
		}
		$url = add_query_arg(
			array( 'sc_design' => rawurlencode( $token ) ),
			get_permalink( $product_id )
		);
		$subject = sprintf(
			/* translators: %s store name */
			__( 'Your saved design at %s', 'storecanvas' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$body = sprintf(
			/* translators: %s reload URL */
			__( "Here is a link to reload your StoreCanvas design (valid ~14 days):\n\n%s\n", 'storecanvas' ),
			$url
		);
		$from_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$from_email = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
		$headers    = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);
		if ( ! wp_mail( $email, $subject, $body, $headers ) ) {
			wp_send_json_error( array( 'message' => 'mail failed' ) );
		}
		// Do not echo the design URL (which carries the guest token) back in the JSON —
		// the link goes only to the emailed address. Defense in depth against the token
		// leaking via browser devtools / proxy logs of the AJAX response.
		wp_send_json_success( array( 'message' => __( 'Link emailed.', 'storecanvas' ) ) );
	}

	/**
	 * Per-IP transient rate limiter for the guest endpoints. Returns false when the
	 * caller has exceeded $max requests in $window seconds ($max <= 0 disables it).
	 *
	 * @param string $bucket Logical bucket (e.g. save|email).
	 * @param int    $max    Max allowed in the window.
	 * @param int    $window Window length in seconds.
	 * @return bool
	 */
	private function rate_ok( $bucket, $max, $window ) {
		$max = (int) $max;
		if ( $max <= 0 ) {
			return true;
		}
		$key   = 'sc_rl_' . preg_replace( '/[^a-z0-9_]/', '', (string) $bucket ) . '_' . md5( $this->client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, max( 60, (int) $window ) );
		return true;
	}

	/**
	 * Server-set connecting IP (not client-forgeable) for rate-limit keys.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0';
	}

	/**
	 * Bound and clean a decoded design payload before storage: strip tags from string
	 * leaves (defense in depth against stored/DOM XSS), cap string length, and limit
	 * nesting depth and node count so a hostile payload cannot bloat storage.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_payload( $value, $depth = 0 ) {
		if ( $depth > 8 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out   = array();
			$count = 0;
			foreach ( $value as $k => $v ) {
				if ( $count++ >= 2000 ) {
					break;
				}
				$key         = is_int( $k ) ? $k : substr( sanitize_text_field( (string) $k ), 0, 100 );
				$out[ $key ] = $this->sanitize_payload( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return substr( wp_kses( $value, array() ), 0, 5000 );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return null;
	}
}
