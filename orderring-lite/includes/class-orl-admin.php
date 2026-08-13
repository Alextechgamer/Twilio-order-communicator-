<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ORL_Admin {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_orl_test_connection', array( $this, 'ajax_test' ) );
		add_filter( 'plugin_action_links_' . ORL_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'OrderRing Lite', 'orderring-lite' ),
			__( 'OrderRing Lite', 'orderring-lite' ),
			ORL_Caps::manage(),
			'orderring-lite',
			array( $this, 'render_settings' ),
			'dashicons-smartphone',
			56
		);
	}

	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=orderring-lite' ) ) . '">' . esc_html__( 'Settings', 'orderring-lite' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function assets( $hook ) {
		$on_settings = ( is_string( $hook ) && false !== strpos( $hook, 'orderring-lite' ) );
		$on_order    = ( $hook === 'post.php' || $hook === 'woocommerce_page_wc-orders' );
		if ( ! $on_settings && ! $on_order ) {
			return;
		}
		wp_enqueue_style( 'orl-admin', ORL_PLUGIN_URL . 'assets/admin.css', array(), ORL_VERSION );
		wp_enqueue_script( 'orl-admin', ORL_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), ORL_VERSION, true );
		wp_localize_script(
			'orl-admin',
			'orlAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'orl_nonce' ),
				'i18n'  => array(
					'testing' => __( 'Testing…', 'orderring-lite' ),
					'sending' => __( 'Sending…', 'orderring-lite' ),
					'failed'  => __( 'Request failed.', 'orderring-lite' ),
					'force'   => __( 'Customer has not consented or opted out. Send anyway?', 'orderring-lite' ),
				),
			)
		);
	}

	public function register_settings() {
		register_setting(
			'orl_settings',
			'orl_account_sid',
			array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'orl_settings',
			'orl_auth_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_auth_token' ),
			)
		);
		register_setting(
			'orl_settings',
			'orl_from_number',
			array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_from_number' ) )
		);
		$plain = array(
			'orl_webhook_base_url'          => array( $this, 'sanitize_webhook_base' ),
			'orl_status_ready_for_pickup'   => array( $this, 'sanitize_order_status' ),
			'orl_pickup_match'              => 'sanitize_text_field',
			'orl_message_ready_for_pickup'  => 'sanitize_textarea_field',
			'orl_checkout_consent_label'    => 'sanitize_text_field',
			'orl_sms_consent_meta'          => 'sanitize_text_field',
			'orl_stop_reply'                => 'sanitize_textarea_field',
			'orl_help_reply'                => 'sanitize_textarea_field',
			'orl_start_reply'               => 'sanitize_textarea_field',
			'orl_sms_footer_text'           => 'sanitize_text_field',
		);
		foreach ( $plain as $key => $cb ) {
			register_setting( 'orl_settings', $key, array( 'type' => 'string', 'sanitize_callback' => $cb ) );
		}
		foreach ( array( 'orl_auto_ready_enabled', 'orl_auto_ready_sms', 'orl_ready_require_local_pickup', 'orl_require_sms_consent', 'orl_checkout_consent_enabled', 'orl_checkout_consent_required', 'orl_sms_footer_enabled' ) as $box ) {
			register_setting( 'orl_settings', $box, array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		}
	}

	public function sanitize_checkbox( $value ) {
		return $value ? 1 : 0;
	}

	public function sanitize_from_number( $value ) {
		$value = preg_replace( '/\s+/', '', (string) $value );
		if ( $value !== '' && ! ORL_Twilio::is_e164( $value ) ) {
			add_settings_error( 'orl_from_number', 'orl_from', __( 'From Number must be E.164 (e.g. +15055551234).', 'orderring-lite' ) );
			return (string) get_option( 'orl_from_number', '' );
		}
		return $value;
	}

	public function sanitize_auth_token( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return (string) get_option( 'orl_auth_token', '' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'orl_auth_token', 'orl_token_cap', __( 'Only administrators can change the Twilio Auth Token.', 'orderring-lite' ) );
			return (string) get_option( 'orl_auth_token', '' );
		}
		return sanitize_text_field( $value );
	}

	public function sanitize_order_status( $value ) {
		return ORL_Statuses::normalize_wc_status( $value, ORL_Statuses::READY_FOR_PICKUP );
	}

	public function sanitize_webhook_base( $value ) {
		$value = esc_url_raw( trim( (string) $value ) );
		return $value;
	}

	public function ajax_test() {
		check_ajax_referer( 'orl_nonce', 'nonce' );
		if ( ! current_user_can( ORL_Caps::manage() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'orderring-lite' ) ), 403 );
		}
		$result = ORL_Twilio::instance()->test_credentials();
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	public function render_settings() {
		if ( ! current_user_can( ORL_Caps::manage() ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderring-lite' ) );
		}
		$twilio = ORL_Twilio::instance();
		$sms_url = ORL_Webhooks::rest_url( 'sms' );
		?>
		<div class="wrap orl-wrap">
			<h1><?php echo esc_html__( 'OrderRing Lite', 'orderring-lite' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Ready-for-pickup SMS via your own Twilio account. You pay Twilio directly, zero markup.', 'orderring-lite' ); ?></p>

			<div class="orl-upgrade">
				<p><strong><?php echo esc_html__( 'Want voice, WhatsApp, two-way chat, and bulk reminders?', 'orderring-lite' ); ?></strong>
				<?php echo esc_html__( 'Those live in OrderRing (the paid plugin), sold separately. This Lite plugin stays fully functional either way.', 'orderring-lite' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'orl_settings' ); ?>

				<h2><?php echo esc_html__( 'Twilio credentials', 'orderring-lite' ); ?></h2>
				<p><?php echo esc_html__( 'US A2P 10DLC: register your brand and campaign in the Twilio Console before texting US mobiles. This plugin does not file 10DLC for you.', 'orderring-lite' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="orl_account_sid"><?php echo esc_html__( 'Account SID', 'orderring-lite' ); ?></label></th>
						<td>
							<?php if ( $twilio->credential_is_constant( 'sid' ) ) : ?>
								<code><?php echo esc_html__( 'Set in wp-config.php (ORL_ACCOUNT_SID)', 'orderring-lite' ); ?></code>
							<?php else : ?>
								<input type="text" class="regular-text" id="orl_account_sid" name="orl_account_sid" value="<?php echo esc_attr( get_option( 'orl_account_sid', '' ) ); ?>" autocomplete="off" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orl_auth_token"><?php echo esc_html__( 'Auth Token', 'orderring-lite' ); ?></label></th>
						<td>
							<?php if ( $twilio->credential_is_constant( 'token' ) ) : ?>
								<code><?php echo esc_html__( 'Set in wp-config.php (ORL_AUTH_TOKEN)', 'orderring-lite' ); ?></code>
							<?php else : ?>
								<input type="password" class="regular-text" id="orl_auth_token" name="orl_auth_token" value="" placeholder="<?php echo esc_attr( get_option( 'orl_auth_token', '' ) ? '••••••••' : '' ); ?>" autocomplete="new-password" />
								<p class="description"><?php echo esc_html__( 'Leave blank to keep the saved token.', 'orderring-lite' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orl_from_number"><?php echo esc_html__( 'From Number', 'orderring-lite' ); ?></label></th>
						<td>
							<?php if ( $twilio->credential_is_constant( 'from' ) ) : ?>
								<code><?php echo esc_html__( 'Set in wp-config.php (ORL_FROM_NUMBER)', 'orderring-lite' ); ?></code>
							<?php else : ?>
								<input type="text" class="regular-text" id="orl_from_number" name="orl_from_number" value="<?php echo esc_attr( get_option( 'orl_from_number', '' ) ); ?>" placeholder="+15055551234" />
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" class="button" id="orl-test-connection"><?php echo esc_html__( 'Test connection', 'orderring-lite' ); ?></button>
					<span id="orl-test-msg"></span>
				</p>

				<h2><?php echo esc_html__( 'Ready for Pickup', 'orderring-lite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mapped status', 'orderring-lite' ); ?></th>
						<td>
							<select name="orl_status_ready_for_pickup" id="orl_status_ready_for_pickup">
								<?php foreach ( ORL_Statuses::all_order_statuses() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( ORL_Statuses::mapped_ready_status(), $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Auto SMS', 'orderring-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="orl_auto_ready_enabled" value="1" <?php checked( (int) get_option( 'orl_auto_ready_enabled', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Send when an order enters this status', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orl_auto_ready_sms" value="1" <?php checked( (int) get_option( 'orl_auto_ready_sms', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Enable SMS (this is the Lite channel)', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orl_ready_require_local_pickup" value="1" <?php checked( (int) get_option( 'orl_ready_require_local_pickup', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Only if the shipping method looks like Local Pickup', 'orderring-lite' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orl_message_ready_for_pickup"><?php echo esc_html__( 'Message', 'orderring-lite' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" id="orl_message_ready_for_pickup" name="orl_message_ready_for_pickup"><?php echo esc_textarea( (string) get_option( 'orl_message_ready_for_pickup', '' ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Tags: {customer_first_name} {order_number} {store_name} {phone}', 'orderring-lite' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'SMS consent', 'orderring-lite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Checkout', 'orderring-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="orl_checkout_consent_enabled" value="1" <?php checked( (int) get_option( 'orl_checkout_consent_enabled', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Show a consent checkbox at checkout', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orl_checkout_consent_required" value="1" <?php checked( (int) get_option( 'orl_checkout_consent_required', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Require the checkbox to place the order', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orl_require_sms_consent" value="1" <?php checked( (int) get_option( 'orl_require_sms_consent', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Do not send SMS unless the customer consented (or you force-send from the order screen)', 'orderring-lite' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orl_checkout_consent_label"><?php echo esc_html__( 'Checkbox label', 'orderring-lite' ); ?></label></th>
						<td><input type="text" class="large-text" id="orl_checkout_consent_label" name="orl_checkout_consent_label" value="<?php echo esc_attr( (string) get_option( 'orl_checkout_consent_label', '' ) ); ?>" /></td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'STOP / HELP / START', 'orderring-lite' ); ?></h2>
				<p><?php echo esc_html__( 'Paste this Incoming SMS webhook into Twilio:', 'orderring-lite' ); ?>
					<code><?php echo esc_html( $sms_url ); ?></code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="orl_stop_reply"><?php echo esc_html__( 'STOP reply', 'orderring-lite' ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="orl_stop_reply" name="orl_stop_reply"><?php echo esc_textarea( (string) get_option( 'orl_stop_reply', '' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="orl_start_reply"><?php echo esc_html__( 'START reply', 'orderring-lite' ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="orl_start_reply" name="orl_start_reply"><?php echo esc_textarea( (string) get_option( 'orl_start_reply', '' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="orl_webhook_base_url"><?php echo esc_html__( 'Public site URL override', 'orderring-lite' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="orl_webhook_base_url" name="orl_webhook_base_url" value="<?php echo esc_attr( (string) get_option( 'orl_webhook_base_url', '' ) ); ?>" placeholder="https://example.com" />
							<p class="description"><?php echo esc_html__( 'Only if WordPress is behind a reverse proxy and webhook URLs are wrong.', 'orderring-lite' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<p class="orl-legal"><?php echo esc_html__( 'Twilio and all related logos are trademarks of Twilio Inc. or its affiliates. OrderRing is not affiliated with, endorsed, or sponsored by Twilio Inc.', 'orderring-lite' ); ?></p>
		</div>
		<?php
	}
}
