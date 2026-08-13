<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings registration, sanitizers, and Settings tab UI.
 */
trait TOC_Admin_Settings {

	public function register_settings() {
		// The Settings form posts to options.php, which requires `manage_options` by
		// default. Grant save access to whoever holds the plugin's own manage cap
		// (e.g. shop_manager via the role matrix), matching who can view the page.
		add_filter(
			'option_page_capability_toc_settings',
			static function () {
				return TOC_Caps::manage();
			}
		);

		$text_fields = array(
			'toc_account_sid'               => 'sanitize_text_field',
			'toc_from_number'               => array( $this, 'sanitize_from_number' ),
			'toc_whatsapp_from'             => array( $this, 'sanitize_whatsapp_from' ),
			'toc_voice'                     => array( $this, 'sanitize_voice' ),
			'toc_sms_consent_meta'          => 'sanitize_key',
			'toc_pickup_match'              => array( $this, 'sanitize_pickup_match' ),
			'toc_status_ready_for_pickup'   => array( $this, 'sanitize_order_status' ),
			'toc_status_shipped'            => array( $this, 'sanitize_order_status_shipped' ),
			'toc_webhook_base_url'          => array( $this, 'sanitize_webhook_base' ),
			'toc_message_ready_for_pickup'  => 'sanitize_textarea_field',
			'toc_message_shipped'           => 'sanitize_textarea_field',
			'toc_message_reminder'          => 'sanitize_textarea_field',
			'toc_message_issue'             => 'sanitize_textarea_field',
			// Legacy keys kept registered so old forms / migrations do not fatal.
			'toc_default_pickup_message'    => 'sanitize_textarea_field',
			'toc_default_reminder_message'  => 'sanitize_textarea_field',
			'toc_default_issue_message'     => 'sanitize_textarea_field',
			'toc_stop_reply'                => 'sanitize_textarea_field',
			'toc_help_reply'                => 'sanitize_textarea_field',
			'toc_start_reply'               => 'sanitize_textarea_field',
			'toc_checkout_consent_label'    => 'sanitize_textarea_field',
			'toc_quiet_hours_start'         => array( $this, 'sanitize_time' ),
			'toc_quiet_hours_end'           => array( $this, 'sanitize_time' ),
			'toc_sms_footer_text'           => 'sanitize_textarea_field',
			'toc_email_ready_subject'       => 'sanitize_text_field',
			'toc_email_ready_body'          => 'sanitize_textarea_field',
			'toc_email_shipped_subject'     => 'sanitize_text_field',
			'toc_email_shipped_body'        => 'sanitize_textarea_field',
		);

		foreach ( $text_fields as $option => $cb ) {
			register_setting(
				'toc_settings',
				$option,
				array(
					'type'              => 'string',
					'sanitize_callback' => $cb,
					'default'           => '',
				)
			);
		}

		// Auth token: keep existing value when the password field is left blank.
		register_setting(
			'toc_settings',
			'toc_auth_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_auth_token' ),
				'default'           => '',
			)
		);

		$checkboxes = array(
			'toc_auto_ready_enabled'         => 1,
			'toc_auto_ready_voice'           => 1,
			'toc_auto_ready_sms'             => 0,
			'toc_auto_shipped_enabled'       => 0,
			'toc_auto_shipped_voice'         => 0,
			'toc_auto_shipped_sms'           => 0,
			'toc_ready_require_local_pickup' => 0,
			'toc_require_sms_consent'        => 1,
			'toc_checkout_consent_enabled'   => 1,
			'toc_checkout_consent_required'  => 0,
			'toc_quiet_hours_enabled'        => 0,
			'toc_sms_footer_enabled'         => 0,
			'toc_scheduled_reminder_enabled' => 0,
			'toc_delivery_alert_enabled'     => 0,
			'toc_email_ready_enabled'        => 0,
			'toc_email_shipped_enabled'      => 0,
		);

		foreach ( $checkboxes as $option => $default ) {
			register_setting(
				'toc_settings',
				$option,
				array(
					'type'              => 'integer',
					'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
					'default'           => $default,
				)
			);
		}

		register_setting(
			'toc_settings',
			'toc_scheduled_reminder_delay_hours',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_reminder_delay_hours' ),
				'default'           => 24,
			)
		);

		register_setting(
			'toc_settings',
			'toc_delivery_alert_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_delivery_alert_email' ),
				'default'           => '',
			)
		);
	}

	public function sanitize_checkbox( $value ) {
		return ( ! empty( $value ) && (string) $value !== '0' ) ? 1 : 0;
	}

	/**
	 * Hours until a scheduled Ready-for-Pickup reminder fires (1–720).
	 *
	 * @param mixed $value Raw input.
	 * @return int
	 */
	public function sanitize_reminder_delay_hours( $value ) {
		$hours = absint( $value );
		if ( $hours < TOC_Reminders::MIN_DELAY_HOURS ) {
			$hours = TOC_Reminders::DEFAULT_DELAY_HOURS;
		}
		if ( $hours > TOC_Reminders::MAX_DELAY_HOURS ) {
			$hours = TOC_Reminders::MAX_DELAY_HOURS;
		}
		return $hours;
	}

	/**
	 * Optional staff email for SMS delivery failure alerts. Empty = use admin_email.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_delivery_alert_email( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( $value === '' ) {
			return '';
		}
		$email = sanitize_email( $value );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * The Twilio From number must be strict E.164 (e.g. +15055551234). Tolerate common
	 * separators on input; on an invalid value keep the previously saved number and flag
	 * an error rather than persisting something Twilio will reject on every send.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_from_number( $value ) {
		if ( TOC_Twilio::instance()->credential_is_constant( 'from' ) ) {
			return (string) get_option( 'toc_from_number', '' );
		}
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( $value === '' ) {
			return '';
		}
		$compact = preg_replace( '/[\s().-]/', '', $value );
		if ( TOC_Twilio::is_e164( $compact ) ) {
			return $compact;
		}
		add_settings_error(
			'toc_from_number',
			'toc_from_number_invalid',
			__( 'From Number must be in E.164 format, e.g. +15055551234. Your previous value was kept.', 'twilio-order-communicator' )
		);
		return (string) get_option( 'toc_from_number', '' );
	}

	/**
	 * WhatsApp sender: optional E.164 number of a WhatsApp-enabled Twilio sender. Empty is allowed
	 * (falls back to the SMS From at send time).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_whatsapp_from( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		$compact = preg_replace( '/[\s().-]/', '', $value );
		if ( TOC_Twilio::is_e164( $compact ) ) {
			return $compact;
		}
		add_settings_error(
			'toc_whatsapp_from',
			'toc_whatsapp_from_invalid',
			__( 'WhatsApp sender must be in E.164 format, e.g. +15055551234. Your previous value was kept.', 'twilio-order-communicator' )
		);
		return (string) get_option( 'toc_whatsapp_from', '' );
	}

	public function sanitize_voice( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = array( 'alice', 'man', 'woman', 'polly.joanna', 'polly.matthew', 'polly.amy' );
		return in_array( $value, $allowed, true ) ? $value : 'alice';
	}

	public function sanitize_auth_token( $value ) {
		if ( TOC_Twilio::instance()->credential_is_constant( 'token' ) ) {
			return (string) get_option( 'toc_auth_token', '' );
		}
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( $value === '' ) {
			return (string) get_option( 'toc_auth_token', '' );
		}
		// The Twilio Auth Token is a high-value secret: only full administrators may
		// change it, so a toc_manage-only user (e.g. shop_manager) cannot swap in their
		// own Twilio account and hijack sending. Defining TOC_AUTH_TOKEN as a constant
		// stays the recommended path.
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				'toc_auth_token',
				'toc_auth_token_denied',
				__( 'Only administrators can change the Twilio Auth Token. Your previous value was kept.', 'twilio-order-communicator' )
			);
			return (string) get_option( 'toc_auth_token', '' );
		}
		return sanitize_text_field( $value );
	}

	public function sanitize_pickup_match( $value ) {
		$value = sanitize_key( $value );
		$allowed = array( 'method_id', 'local_title', 'any_pickup' );
		return in_array( $value, $allowed, true ) ? $value : 'local_title';
	}

	public function sanitize_order_status( $value ) {
		return TOC_Statuses::normalize_wc_status( $value, TOC_Statuses::READY_FOR_PICKUP );
	}

	public function sanitize_order_status_shipped( $value ) {
		return TOC_Statuses::normalize_wc_status( $value, TOC_Statuses::SHIPPED );
	}

	public function sanitize_webhook_base( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( $value === '' ) {
			return '';
		}
		$value = esc_url_raw( $value );
		return $value ? untrailingslashit( $value ) : '';
	}

	public function sanitize_time( $value ) {
		return TOC_Auto::normalize_time_option( is_string( $value ) ? $value : '', '00:00' );
	}


	/* ---------- SETTINGS ---------- */
	private function render_settings() {
		// This page lives under the WooCommerce menu, not Settings, so WordPress does not
		// print add_settings_error() notices automatically (options-head.php never loads).
		// Without this call the sanitize-time warnings — e.g. "Only administrators can
		// change the Twilio Auth Token" — were silently dropped after the redirect.
		settings_errors();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'toc_settings' ); ?>

			<h2><?php echo esc_html__( 'Twilio Credentials', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Bring your own Twilio account. This plugin does not provide or resell SMS, voice, WhatsApp, or calling services — you pay Twilio directly, zero markup.', 'twilio-order-communicator' ); ?></p>
			<p class="description"><?php echo esc_html__( 'US A2P 10DLC: register your brand and campaign in the Twilio Console before texting US mobiles. This plugin does not file 10DLC for you. See Tools & Docs.', 'twilio-order-communicator' ); ?></p>
			<?php
			$twilio    = TOC_Twilio::instance();
			$creds     = array(
				'sid'   => $twilio->credential_is_constant( 'sid' ),
				'token' => $twilio->credential_is_constant( 'token' ),
				'from'  => $twilio->credential_is_constant( 'from' ),
			);
			$has_token = (string) get_option( 'toc_auth_token', '' ) !== '' || $creds['token'];
			?>
			<?php if ( $creds['sid'] || $creds['token'] || $creds['from'] ) : ?>
				<p class="description"><?php echo esc_html__( 'Some credentials are set via wp-config.php constants (TOC_ACCOUNT_SID / TOC_AUTH_TOKEN / TOC_FROM_NUMBER) and cannot be changed here.', 'twilio-order-communicator' ); ?></p>
			<?php endif; ?>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Account SID', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php if ( $creds['sid'] ) : ?>
							<input type="text" value="<?php echo esc_attr( '•••••••• (TOC_ACCOUNT_SID)' ); ?>" class="regular-text" disabled />
						<?php else : ?>
							<input type="text" name="toc_account_sid" value="<?php echo esc_attr( get_option( 'toc_account_sid' ) ); ?>" class="regular-text" autocomplete="off" />
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Auth Token', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php if ( $creds['token'] ) : ?>
							<input type="password" value="" class="regular-text" disabled placeholder="<?php echo esc_attr__( 'Set via TOC_AUTH_TOKEN', 'twilio-order-communicator' ); ?>" />
						<?php else : ?>
							<input type="password" name="toc_auth_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_token ? esc_attr( '••••••••  (leave blank to keep)' ) : ''; ?>" />
							<?php if ( $has_token ) : ?>
								<p class="description"><?php echo esc_html__( 'A token is already saved. Leave blank to keep it, or paste a new token to replace it.', 'twilio-order-communicator' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'From Number', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php if ( $creds['from'] ) : ?>
							<input type="text" value="<?php echo esc_attr( '•••••••• (TOC_FROM_NUMBER)' ); ?>" class="regular-text" disabled />
						<?php else : ?>
							<input type="text" name="toc_from_number" value="<?php echo esc_attr( get_option( 'toc_from_number' ) ); ?>" class="regular-text" placeholder="+1xxxxxxxxxx" />
						<?php endif; ?>
						<p class="description"><?php echo esc_html__( 'E.164 format required (e.g. +15055551234)', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'WhatsApp Sender', 'twilio-order-communicator' ); ?></th>
					<td>
						<input type="text" name="toc_whatsapp_from" value="<?php echo esc_attr( get_option( 'toc_whatsapp_from' ) ); ?>" class="regular-text" placeholder="+1xxxxxxxxxx" />
						<p class="description"><?php echo esc_html__( 'Optional. E.164 number of your WhatsApp-enabled Twilio sender. Leave blank to use the From Number. Requires WhatsApp enabled on your Twilio account (approved sender + templates).', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Voice Settings', 'twilio-order-communicator' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Voice', 'twilio-order-communicator' ); ?></th>
					<td>
						<select name="toc_voice">
							<?php
							$voices  = array(
								'alice'         => 'Alice (default)',
								'man'           => 'Man',
								'woman'         => 'Woman',
								'polly.joanna'  => 'Polly Joanna',
								'polly.matthew' => 'Polly Matthew',
								'polly.amy'     => 'Polly Amy',
							);
							$current = get_option( 'toc_voice', 'alice' );
							foreach ( $voices as $val => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $val ),
									selected( $current, $val, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
						<p class="description"><?php echo esc_html__( 'Voice used by the built-in TwiML endpoint. Alice / Man / Woman work on all accounts. Polly voices require Amazon Polly support on your Twilio account; the plugin maps stored values (e.g. polly.joanna) to Twilio’s Polly.Joanna form automatically.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Order Status Mapping', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Choose which WooCommerce statuses trigger Ready for Pickup and Shipped notifications. Defaults are the statuses registered by this plugin.', 'twilio-order-communicator' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Ready for Pickup status', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->status_dropdown( 'toc_status_ready_for_pickup', TOC_Statuses::mapped_ready_status() ); ?>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Shipped status', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->status_dropdown( 'toc_status_shipped', TOC_Statuses::mapped_shipped_status() ); ?>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Automatic Notifications', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Independent controls for each status. Uses your own Twilio account — message and call costs are billed by Twilio.', 'twilio-order-communicator' ); ?></p>

			<h3><?php echo esc_html__( 'Ready for Pickup', 'twilio-order-communicator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Enable', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_ready_enabled', 1 ); ?>
						<label for="toc_auto_ready_enabled"><?php echo esc_html__( 'Auto-notify when an order enters the mapped Ready for Pickup status', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Runs once per order (meta', 'twilio-order-communicator' ); ?> <code>_toc_notified_ready_for_pickup_at</code>). <?php echo esc_html__( 'Delete that meta to allow a re-send.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Voice call', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_ready_voice', 1 ); ?>
						<label for="toc_auto_ready_voice"><?php echo esc_html__( 'Place a voice call', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Voice calls do not require SMS consent. Also used by scheduled pickup reminders.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'SMS', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_ready_sms', 0 ); ?>
						<label for="toc_auto_ready_sms"><?php echo esc_html__( 'Also send an SMS (consent required)', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Also used by scheduled pickup reminders.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Local Pickup filter', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_ready_require_local_pickup', 0 ); ?>
						<label for="toc_ready_require_local_pickup"><?php echo esc_html__( 'Only auto-notify Ready for Pickup if shipping method looks like Local Pickup', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Optional secondary filter. Primary trigger is order status. Off by default for new installs.', 'twilio-order-communicator' ); ?></p>
						<?php $pm = get_option( 'toc_pickup_match', 'local_title' ); ?>
						<select name="toc_pickup_match" style="margin-top:8px;">
							<option value="method_id" <?php selected( $pm, 'method_id' ); ?>><?php echo esc_html__( 'Strict: shipping method ID = local_pickup only', 'twilio-order-communicator' ); ?></option>
							<option value="local_title" <?php selected( $pm, 'local_title' ); ?>><?php echo esc_html__( 'Recommended: method ID or title contains "local pickup"', 'twilio-order-communicator' ); ?></option>
							<option value="any_pickup" <?php selected( $pm, 'any_pickup' ); ?>><?php echo esc_html__( 'Loose (legacy): any title containing "pickup"', 'twilio-order-communicator' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h3><?php echo esc_html__( 'Shipped', 'twilio-order-communicator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Enable', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_shipped_enabled', 0 ); ?>
						<label for="toc_auto_shipped_enabled"><?php echo esc_html__( 'Auto-notify when an order enters the mapped Shipped status', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Runs once per order (meta', 'twilio-order-communicator' ); ?> <code>_toc_notified_shipped_at</code>). <?php echo esc_html__( 'Delete that meta to allow a re-send. An order can receive both Ready for Pickup and Shipped notifications.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Voice call', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_shipped_voice', 0 ); ?>
						<label for="toc_auto_shipped_voice"><?php echo esc_html__( 'Place a voice call', 'twilio-order-communicator' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'SMS', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_shipped_sms', 0 ); ?>
						<label for="toc_auto_shipped_sms"><?php echo esc_html__( 'Also send an SMS (consent required)', 'twilio-order-communicator' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Customer status emails', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Optional email to the customer when an order enters the mapped Ready for Pickup or Shipped status. Independent of voice/SMS auto-notify. Sent once per order (clear the order meta to re-send). Uses wp_mail with your store From address. Quiet hours do not apply. Never gated by license.', 'twilio-order-communicator' ); ?></p>
			<p class="description"><?php echo esc_html__( 'Merge tags:', 'twilio-order-communicator' ); ?> <code>{order_number}</code> <code>{order_id}</code> <code>{customer_first_name}</code> <code>{customer_last_name}</code> <code>{customer_full_name}</code> <code>{store_name}</code> <code>{phone}</code> <code>{order_total}</code> <code>{billing_email}</code> <code>{tracking}</code> <code>{tracking_url}</code></p>

			<h3><?php echo esc_html__( 'Ready for Pickup email', 'twilio-order-communicator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Enable', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_email_ready_enabled', 0 ); ?>
						<label for="toc_email_ready_enabled"><?php echo esc_html__( 'Email the customer when the order enters Ready for Pickup', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Meta', 'twilio-order-communicator' ); ?> <code>_toc_emailed_ready_for_pickup_at</code></p>
					</td>
				</tr>
				<tr>
					<th><label for="toc_email_ready_subject"><?php echo esc_html__( 'Subject', 'twilio-order-communicator' ); ?></label></th>
					<td><input type="text" id="toc_email_ready_subject" name="toc_email_ready_subject" class="large-text" value="<?php echo esc_attr( get_option( 'toc_email_ready_subject', 'Your order #{order_number} is ready for pickup' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="toc_email_ready_body"><?php echo esc_html__( 'Body', 'twilio-order-communicator' ); ?></label></th>
					<td><textarea id="toc_email_ready_body" name="toc_email_ready_body" rows="5" class="large-text"><?php echo esc_textarea( get_option( 'toc_email_ready_body', "Hello {customer_first_name},\n\nYour order #{order_number} is ready for pickup at {store_name}.\n\nThank you." ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Sent with the WooCommerce email template when available (plain text is auto-wrapped). Light HTML is allowed. Quiet hours do not apply.', 'twilio-order-communicator' ); ?></p></td>
				</tr>
			</table>

			<h3><?php echo esc_html__( 'Shipped email', 'twilio-order-communicator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Enable', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_email_shipped_enabled', 0 ); ?>
						<label for="toc_email_shipped_enabled"><?php echo esc_html__( 'Email the customer when the order enters Shipped', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Meta', 'twilio-order-communicator' ); ?> <code>_toc_emailed_shipped_at</code></p>
					</td>
				</tr>
				<tr>
					<th><label for="toc_email_shipped_subject"><?php echo esc_html__( 'Subject', 'twilio-order-communicator' ); ?></label></th>
					<td><input type="text" id="toc_email_shipped_subject" name="toc_email_shipped_subject" class="large-text" value="<?php echo esc_attr( get_option( 'toc_email_shipped_subject', 'Your order #{order_number} has shipped' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="toc_email_shipped_body"><?php echo esc_html__( 'Body', 'twilio-order-communicator' ); ?></label></th>
					<td><textarea id="toc_email_shipped_body" name="toc_email_shipped_body" rows="5" class="large-text"><?php echo esc_textarea( get_option( 'toc_email_shipped_body', "Hello {customer_first_name},\n\nYour order #{order_number} has shipped.\n\nThank you for shopping at {store_name}." ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Sent with the WooCommerce email template when available. Quiet hours do not apply.', 'twilio-order-communicator' ); ?></p></td>
				</tr>
			</table>

			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Quiet hours', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_quiet_hours_enabled', 0 ); ?>
						<label for="toc_quiet_hours_enabled"><?php echo esc_html__( 'Defer auto notifications during quiet hours', 'twilio-order-communicator' ); ?></label>
						<p>
							<label><?php echo esc_html__( 'From', 'twilio-order-communicator' ); ?>
								<input type="time" name="toc_quiet_hours_start" value="<?php echo esc_attr( TOC_Auto::normalize_time_option( get_option( 'toc_quiet_hours_start', '21:00' ), '21:00' ) ); ?>" />
							</label>
							<label><?php echo esc_html__( 'Until', 'twilio-order-communicator' ); ?>
								<input type="time" name="toc_quiet_hours_end" value="<?php echo esc_attr( TOC_Auto::normalize_time_option( get_option( 'toc_quiet_hours_end', '08:00' ), '08:00' ) ); ?>" />
							</label>
						</p>
						<p class="description"><?php echo esc_html( sprintf( __( 'Uses the WordPress timezone (%s). Overnight windows like 21:00–08:00 are supported. Deferred with Action Scheduler when available. Applies to Ready for Pickup, Shipped, and scheduled pickup reminders.', 'twilio-order-communicator' ), wp_timezone_string() ) ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php echo esc_html__( 'Scheduled pickup reminders', 'twilio-order-communicator' ); ?></h3>
			<p class="description"><?php echo esc_html__( 'Automatically remind customers whose orders are still in Ready for Pickup after a delay. Uses the Pickup Reminder template, quiet hours, SMS consent, and the Ready for Pickup voice/SMS channel toggles above. Never gated by license.', 'twilio-order-communicator' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Enable', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_scheduled_reminder_enabled', 0 ); ?>
						<label for="toc_scheduled_reminder_enabled"><?php echo esc_html__( 'Schedule a reminder when an order enters Ready for Pickup', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'One Action Scheduler job per order. Cancelled if the order leaves Ready for Pickup. Skips if _toc_last_reminder_at is still recent (same delay window).', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="toc_scheduled_reminder_delay_hours"><?php echo esc_html__( 'Delay', 'twilio-order-communicator' ); ?></label></th>
					<td>
						<input type="number" id="toc_scheduled_reminder_delay_hours" name="toc_scheduled_reminder_delay_hours" min="1" max="720" step="1" value="<?php echo (int) TOC_Reminders::delay_hours(); ?>" style="width:5em" />
						<?php echo esc_html__( 'hours after entering Ready for Pickup', 'twilio-order-communicator' ); ?>
						<p class="description"><?php echo esc_html__( 'Default 24. Range 1–720 (30 days).', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'SMS Consent', 'twilio-order-communicator' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Checkout checkbox', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_checkout_consent_enabled', 1 ); ?>
						<label for="toc_checkout_consent_enabled"><?php echo esc_html__( 'Show built-in SMS consent checkbox on checkout', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Works with classic checkout and WooCommerce block checkout (8.9+). Stores consent, timestamp, and IP on the order.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Require at checkout', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_checkout_consent_required', 0 ); ?>
						<label for="toc_checkout_consent_required"><?php echo esc_html__( 'Customer must check the box to place the order', 'twilio-order-communicator' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Checkbox label', 'twilio-order-communicator' ); ?></th>
					<td>
						<textarea name="toc_checkout_consent_label" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'toc_checkout_consent_label', 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.' ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Require consent for SMS', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_require_sms_consent', 1 ); ?>
						<label for="toc_require_sms_consent"><?php esc_html_e( 'Only send SMS when the customer has opted in', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php esc_html_e( 'Manual Send SMS warns and can force-send. Automatic and bulk SMS respect this setting. STOP keywords always block further SMS.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Consent meta key', 'twilio-order-communicator' ); ?></th>
					<td>
						<input type="text" name="toc_sms_consent_meta" value="<?php echo esc_attr( get_option( 'toc_sms_consent_meta', '_toc_sms_consent' ) ); ?>" class="regular-text" />
						<p class="description">
							<?php echo esc_html__( 'Enter the order meta key written by your SMS consent checkbox — TOC’s built-in box or a third-party one. Truthy values: yes / 1 / on / true / checked.', 'twilio-order-communicator' ); ?>
							<?php echo esc_html__( 'Default:', 'twilio-order-communicator' ); ?> <code>_toc_sms_consent</code>.
							<?php echo esc_html__( 'Examples:', 'twilio-order-communicator' ); ?>
							<code>_toc_sms_consent</code>, <code>_sms_consent</code>, <code>sms_opt_in</code>, <code>_wc_sms_consent</code>, <code>billing_sms_consent</code>.
							<?php echo esc_html__( 'If the key is wrong, automatic and bulk SMS may be blocked even when the customer opted in. See Tools & Docs for webhook and consent notes.', 'twilio-order-communicator' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Message Templates', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Merge tags:', 'twilio-order-communicator' ); ?> <code>{order_number}</code> <code>{order_id}</code> <code>{customer_first_name}</code> <code>{customer_last_name}</code> <code>{customer_full_name}</code> <code>{store_name}</code> <code>{phone}</code> <code>{order_total}</code> <code>{billing_email}</code> <code>{tracking}</code> <code>{tracking_url}</code></p>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Ready for Pickup', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_message_ready_for_pickup" rows="3" class="large-text"><?php echo esc_textarea( TOC_Auto::get_message_template( TOC_Auto::KIND_READY ) ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Shipped', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_message_shipped" rows="3" class="large-text"><?php echo esc_textarea( TOC_Auto::get_message_template( TOC_Auto::KIND_SHIPPED ) ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Pickup Reminder', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_message_reminder" rows="3" class="large-text"><?php echo esc_textarea( TOC_Auto::get_message_template( 'reminder' ) ); ?></textarea>
					<p class="description"><?php echo esc_html__( 'Used by Bulk Reminders and scheduled pickup reminders for orders still in Ready for Pickup.', 'twilio-order-communicator' ); ?></p></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Issue / Contact', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_message_issue" rows="3" class="large-text"><?php echo esc_textarea( TOC_Auto::get_message_template( 'issue' ) ); ?></textarea></td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Inbound SMS keywords (STOP / HELP / START)', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Re-subscribe keywords are START and UNSTOP only (not YES), so casual customer replies do not re-opt-in by accident.', 'twilio-order-communicator' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'STOP reply', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_stop_reply" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'toc_stop_reply', 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'HELP reply', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_help_reply" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'toc_help_reply', '' ) ); ?></textarea>
					<p class="description"><?php echo esc_html__( 'Leave blank to use a default with your store name.', 'twilio-order-communicator' ); ?></p></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'START reply', 'twilio-order-communicator' ); ?></th>
					<td><textarea name="toc_start_reply" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'toc_start_reply', 'You have been re-subscribed to SMS messages. Reply STOP to opt out.' ) ); ?></textarea></td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'SMS Footer', 'twilio-order-communicator' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Append footer', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_sms_footer_enabled', 0 ); ?>
						<label for="toc_sms_footer_enabled"><?php echo esc_html__( 'Automatically append a compliance footer to outbound SMS', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Skipped when the message already mentions STOP and opt out. Voice calls are not affected.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Footer text', 'twilio-order-communicator' ); ?></th>
					<td>
						<textarea name="toc_sms_footer_text" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'toc_sms_footer_text', 'Reply STOP to opt out. Msg & data rates may apply.' ) ); ?></textarea>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Delivery failure alerts', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'When Twilio reports an outbound SMS as failed or undelivered (StatusCallback), optionally email staff. SMS/voice sending is never gated by license.', 'twilio-order-communicator' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Enable', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_delivery_alert_enabled', 0 ); ?>
						<label for="toc_delivery_alert_enabled"><?php echo esc_html__( 'Email staff when an SMS is failed or undelivered', 'twilio-order-communicator' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Deduplicated per MessageSid so Twilio retries do not send multiple emails. Order notes are always written regardless of this setting.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="toc_delivery_alert_email"><?php echo esc_html__( 'Alert email', 'twilio-order-communicator' ); ?></label></th>
					<td>
						<input type="email" id="toc_delivery_alert_email" name="toc_delivery_alert_email" value="<?php echo esc_attr( get_option( 'toc_delivery_alert_email', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						<p class="description"><?php echo esc_html__( 'Leave blank to use the WordPress admin email.', 'twilio-order-communicator' ); ?> <code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Advanced', 'twilio-order-communicator' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Webhook base URL', 'twilio-order-communicator' ); ?></th>
					<td>
						<input type="url" name="toc_webhook_base_url" value="<?php echo esc_attr( get_option( 'toc_webhook_base_url', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. Set if Twilio signature checks fail behind Cloudflare/proxy (must match the public HTTPS URL Twilio calls). Leave blank to use the WordPress home URL.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<?php $this->render_role_permissions(); ?>
		<?php
	}

	/**
	 * Role matrix: who can manage the plugin vs send SMS/calls.
	 * Separate form (not options.php) — updates WP_Role caps directly.
	 */
	private function render_role_permissions() {
		// Deciding which roles hold the plugin's capabilities is an escalation-sensitive
		// action, so it stays with full administrators (manage_options) — not merely
		// whoever already holds toc_manage, who could otherwise grant caps to low
		// privilege roles or lock others out.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$roles = TOC_Caps::editable_roles();
		if ( empty( $roles ) ) {
			return;
		}

		if ( isset( $_GET['toc_roles_saved'] ) && (string) $_GET['toc_roles_saved'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Role permissions saved.', 'twilio-order-communicator' ) . '</p></div>';
		} elseif ( isset( $_GET['toc_roles_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['toc_roles_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<hr />
		<h2><?php echo esc_html__( 'Role permissions', 'twilio-order-communicator' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Control which WordPress roles can open OrderRing admin pages, and which can send SMS or place calls from orders. Messaging is never gated by license — these are WordPress capabilities only.', 'twilio-order-communicator' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="toc_save_role_caps" />
			<?php wp_nonce_field( 'toc_save_role_caps' ); ?>
			<table class="widefat striped" style="max-width:720px">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Role', 'twilio-order-communicator' ); ?></th>
						<th style="width:40%">
							<?php echo esc_html__( 'Manage plugin', 'twilio-order-communicator' ); ?>
							<br><span class="description" style="font-weight:normal"><?php echo esc_html__( 'Who can open OrderRing admin pages', 'twilio-order-communicator' ); ?></span>
						</th>
						<th style="width:40%">
							<?php echo esc_html__( 'Send SMS & calls', 'twilio-order-communicator' ); ?>
							<br><span class="description" style="font-weight:normal"><?php echo esc_html__( 'Who can send SMS / place calls from orders', 'twilio-order-communicator' ); ?></span>
						</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $roles as $role_key => $role_data ) :
					$label   = isset( $role_data['name'] ) ? translate_user_role( $role_data['name'] ) : $role_key;
					$caps    = isset( $role_data['capabilities'] ) && is_array( $role_data['capabilities'] ) ? $role_data['capabilities'] : array();
					// Live role object is authoritative for our custom caps.
					$role_obj = get_role( $role_key );
					$has_manage = $role_obj ? $role_obj->has_cap( TOC_Caps::CAP_MANAGE ) : ! empty( $caps[ TOC_Caps::CAP_MANAGE ] );
					$has_send   = $role_obj ? $role_obj->has_cap( TOC_Caps::CAP_SEND ) : ! empty( $caps[ TOC_Caps::CAP_SEND ] );
					$is_admin   = ( $role_key === 'administrator' );
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong> <code><?php echo esc_html( $role_key ); ?></code></td>
						<td>
							<?php if ( $is_admin ) : ?>
								<input type="hidden" name="toc_role_caps[<?php echo esc_attr( $role_key ); ?>][manage]" value="1" />
								<input type="checkbox" checked disabled />
								<span class="description"><?php echo esc_html__( 'Always on for Administrator', 'twilio-order-communicator' ); ?></span>
							<?php else : ?>
								<label>
									<input type="checkbox" name="toc_role_caps[<?php echo esc_attr( $role_key ); ?>][manage]" value="1" <?php checked( $has_manage ); ?> />
									<?php echo esc_html__( 'Allow', 'twilio-order-communicator' ); ?>
								</label>
							<?php endif; ?>
						</td>
						<td>
							<label>
								<input type="checkbox" name="toc_role_caps[<?php echo esc_attr( $role_key ); ?>][send]" value="1" <?php checked( $has_send ); ?> />
								<?php echo esc_html__( 'Allow', 'twilio-order-communicator' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:720px">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: toc_manage, 2: toc_send */
						__( 'Capabilities: %1$s (manage) and %2$s (send). Advanced sites can still override the required capability string with the toc_manage_settings / toc_send_sms filters.', 'twilio-order-communicator' ),
						TOC_Caps::CAP_MANAGE,
						TOC_Caps::CAP_SEND
					)
				);
				?>
			</p>
			<?php submit_button( __( 'Save role permissions', 'twilio-order-communicator' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render a checkbox that correctly saves 0 when unchecked.
	 * Hidden field submits 0; checked box overrides with 1.
	 */
	private function checkbox( $name, $default = 0 ) {
		$checked = (int) get_option( $name, $default ) === 1;
		printf(
			'<input type="hidden" name="%1$s" value="0" />' .
			'<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />',
			esc_attr( $name ),
			checked( $checked, true, false )
		);
	}

	/**
	 * Order status dropdown for mapping settings.
	 *
	 * @param string $name    Option name.
	 * @param string $current Current wc- status slug.
	 */
	private function status_dropdown( $name, $current ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( TOC_Statuses::all_order_statuses() as $slug => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $current, $slug, false ),
				esc_html( wp_strip_all_tags( $label ) )
			);
		}
		echo '</select>';
	}

}
