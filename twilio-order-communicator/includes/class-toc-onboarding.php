<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-run onboarding wizard (credentials → test → webhook → consent → auto notify).
 */
class TOC_Onboarding {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_via_get' ) );
		add_action( 'wp_ajax_toc_onboarding_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_toc_onboarding_complete', array( $this, 'ajax_complete' ) );
		add_action( 'wp_ajax_toc_onboarding_dismiss', array( $this, 'ajax_dismiss' ) );
	}

	public static function is_done() {
		return (int) get_option( 'toc_onboarding_done', 0 ) === 1;
	}

	public function maybe_dismiss_via_get() {
		if ( empty( $_GET['toc_dismiss_setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			return;
		}
		check_admin_referer( 'toc_dismiss_setup' );
		update_option( 'toc_onboarding_done', 1, false );
		wp_safe_redirect( remove_query_arg( array( 'toc_dismiss_setup', '_wpnonce' ) ) );
		exit;
	}

	public function admin_notice() {
		if ( self::is_done() ) {
			return;
		}
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $page === 'toc-communicator-setup' || ( $page === 'toc-communicator' && $tab === 'setup' ) ) {
			return;
		}

		$url      = TOC_Admin::tab_url( 'setup' );
		$dismiss  = wp_nonce_url( add_query_arg( 'toc_dismiss_setup', '1' ), 'toc_dismiss_setup' );
		echo '<div class="notice notice-info"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: 1: plugin name, 2: opening link, 3: closing link */
				__( '<strong>%1$s</strong> — finish setup in under five minutes: %2$sStart setup wizard%3$s', 'twilio-order-communicator' ),
				TOC_BRAND_NAME,
				'<a href="' . esc_url( $url ) . '">',
				'</a>'
			)
		);
		echo ' &nbsp;|&nbsp; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'twilio-order-communicator' ) . '</a>';
		echo '</p></div>';
	}

	public function ajax_dismiss() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}
		update_option( 'toc_onboarding_done', 1, false );
		wp_send_json_success();
	}

	public function ajax_complete() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}
		update_option( 'toc_onboarding_done', 1, false );
		update_option( 'toc_onboarding_step', 6, false );
		wp_send_json_success(
			array(
				'redirect' => TOC_Admin::tab_url( 'dashboard' ),
			)
		);
	}

	public function ajax_save() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;
		update_option( 'toc_onboarding_step', $step, false );

		// Credentials.
		if ( isset( $_POST['toc_account_sid'] ) ) {
			update_option( 'toc_account_sid', sanitize_text_field( wp_unslash( $_POST['toc_account_sid'] ) ) );
		}
		if ( isset( $_POST['toc_from_number'] ) ) {
			update_option( 'toc_from_number', sanitize_text_field( wp_unslash( $_POST['toc_from_number'] ) ) );
		}
		if ( isset( $_POST['toc_auth_token'] ) ) {
			$token = trim( (string) wp_unslash( $_POST['toc_auth_token'] ) );
			if ( $token !== '' ) {
				update_option( 'toc_auth_token', sanitize_text_field( $token ) );
			}
		}

		// Toggles (hidden 0 / checkbox 1 pattern from wizard).
		$bools = array(
			'toc_checkout_consent_enabled',
			'toc_auto_ready_enabled',
			'toc_auto_ready_voice',
			'toc_auto_ready_sms',
			'toc_auto_shipped_enabled',
			'toc_auto_shipped_voice',
			'toc_auto_shipped_sms',
			'toc_require_sms_consent',
			'toc_quiet_hours_enabled',
		);
		foreach ( $bools as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, ( ! empty( $_POST[ $key ] ) && (string) $_POST[ $key ] !== '0' ) ? 1 : 0 );
			}
		}

		if ( isset( $_POST['toc_quiet_hours_start'] ) ) {
			update_option(
				'toc_quiet_hours_start',
				TOC_Auto::normalize_time_option( sanitize_text_field( wp_unslash( $_POST['toc_quiet_hours_start'] ) ), '21:00' )
			);
		}
		if ( isset( $_POST['toc_quiet_hours_end'] ) ) {
			update_option(
				'toc_quiet_hours_end',
				TOC_Auto::normalize_time_option( sanitize_text_field( wp_unslash( $_POST['toc_quiet_hours_end'] ) ), '08:00' )
			);
		}

		wp_send_json_success( array( 'step' => $step ) );
	}

	/**
	 * Render wizard UI (Setup tab).
	 */
	public function render() {
		$step     = max( 1, min( 6, (int) get_option( 'toc_onboarding_step', 1 ) ) );
		$has_token = (string) get_option( 'toc_auth_token', '' ) !== '';
		$sms_hook  = TOC_Twilio::instance()->webhook_url( 'toc_sms' );
		?>
		<div class="toc-wizard" data-step="<?php echo (int) $step; ?>">
			<h2><?php echo esc_html__( 'Setup wizard', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Connect your own Twilio account, verify webhooks, and turn on Ready for Pickup / Shipped notifications.', 'twilio-order-communicator' ); ?></p>

			<ol class="toc-wizard-steps">
				<li data-step="1" class="<?php echo $step === 1 ? 'is-active' : ( $step > 1 ? 'is-done' : '' ); ?>"><?php echo esc_html__( 'Credentials', 'twilio-order-communicator' ); ?></li>
				<li data-step="2" class="<?php echo $step === 2 ? 'is-active' : ( $step > 2 ? 'is-done' : '' ); ?>"><?php echo esc_html__( 'Test', 'twilio-order-communicator' ); ?></li>
				<li data-step="3" class="<?php echo $step === 3 ? 'is-active' : ( $step > 3 ? 'is-done' : '' ); ?>"><?php echo esc_html__( 'Webhook', 'twilio-order-communicator' ); ?></li>
				<li data-step="4" class="<?php echo $step === 4 ? 'is-active' : ( $step > 4 ? 'is-done' : '' ); ?>"><?php echo esc_html__( 'Consent', 'twilio-order-communicator' ); ?></li>
				<li data-step="5" class="<?php echo $step === 5 ? 'is-active' : ( $step > 5 ? 'is-done' : '' ); ?>"><?php echo esc_html__( 'Auto notify', 'twilio-order-communicator' ); ?></li>
				<li data-step="6" class="<?php echo $step === 6 ? 'is-active' : ''; ?>"><?php echo esc_html__( 'Done', 'twilio-order-communicator' ); ?></li>
			</ol>

			<div class="toc-wizard-panel" data-panel="1" <?php echo $step === 1 ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html__( '1. Twilio credentials', 'twilio-order-communicator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php echo esc_html__( 'Account SID', 'twilio-order-communicator' ); ?></th>
						<td><input type="text" class="regular-text" id="toc-wiz-sid" value="<?php echo esc_attr( get_option( 'toc_account_sid', '' ) ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Auth Token', 'twilio-order-communicator' ); ?></th>
						<td>
							<input type="password" class="regular-text" id="toc-wiz-token" value="" autocomplete="new-password" placeholder="<?php echo $has_token ? esc_attr__( '•••••••• (leave blank to keep)', 'twilio-order-communicator' ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'From Number', 'twilio-order-communicator' ); ?></th>
						<td><input type="text" class="regular-text" id="toc-wiz-from" value="<?php echo esc_attr( get_option( 'toc_from_number', '' ) ); ?>" placeholder="+1xxxxxxxxxx" /></td>
					</tr>
				</table>
				<p>
					<button type="button" class="button button-primary toc-wiz-next" data-next="2"><?php echo esc_html__( 'Save & continue', 'twilio-order-communicator' ); ?></button>
				</p>
			</div>

			<div class="toc-wizard-panel" data-panel="2" <?php echo $step === 2 ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html__( '2. Connection test', 'twilio-order-communicator' ); ?></h3>
				<p><?php echo esc_html__( 'Verifies Account SID + Auth Token with Twilio and checks the built-in TwiML endpoint.', 'twilio-order-communicator' ); ?></p>
				<p>
					<button type="button" class="button button-primary" id="toc-wiz-test"><?php echo esc_html__( 'Run Connection Test', 'twilio-order-communicator' ); ?></button>
					<span id="toc-wiz-test-result" style="margin-left:12px;"></span>
				</p>
				<p>
					<button type="button" class="button toc-wiz-back" data-back="1"><?php echo esc_html__( 'Back', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button button-primary toc-wiz-next" data-next="3"><?php echo esc_html__( 'Continue', 'twilio-order-communicator' ); ?></button>
				</p>
			</div>

			<div class="toc-wizard-panel" data-panel="3" <?php echo $step === 3 ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html__( '3. Incoming SMS webhook', 'twilio-order-communicator' ); ?></h3>
				<p><?php echo esc_html__( 'In Twilio Console → Phone Numbers → your number → Messaging → “A MESSAGE COMES IN”:', 'twilio-order-communicator' ); ?></p>
				<ul>
					<li><?php echo esc_html__( 'Webhook', 'twilio-order-communicator' ); ?></li>
					<li><?php echo esc_html__( 'HTTP POST', 'twilio-order-communicator' ); ?></li>
					<li><code id="toc-wiz-webhook"><?php echo esc_html( $sms_hook ); ?></code>
						<button type="button" class="button button-small" id="toc-wiz-copy-webhook"><?php echo esc_html__( 'Copy', 'twilio-order-communicator' ); ?></button>
					</li>
				</ul>
				<p class="description"><?php echo esc_html__( 'Requests are validated with X-Twilio-Signature. STOP / HELP / START are handled automatically.', 'twilio-order-communicator' ); ?></p>
				<p>
					<button type="button" class="button toc-wiz-back" data-back="2"><?php echo esc_html__( 'Back', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button button-primary toc-wiz-next" data-next="4"><?php echo esc_html__( 'Continue', 'twilio-order-communicator' ); ?></button>
				</p>
			</div>

			<div class="toc-wizard-panel" data-panel="4" <?php echo $step === 4 ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html__( '4. Checkout SMS consent', 'twilio-order-communicator' ); ?></h3>
				<p><?php echo esc_html__( 'Show a built-in opt-in checkbox on WooCommerce checkout (classic and block). Stores consent + timestamp/IP on the order.', 'twilio-order-communicator' ); ?></p>
				<p>
					<label>
						<input type="checkbox" id="toc-wiz-checkout-consent" value="1" <?php checked( (int) get_option( 'toc_checkout_consent_enabled', 1 ), 1 ); ?> />
						<?php echo esc_html__( 'Enable checkout SMS consent checkbox', 'twilio-order-communicator' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" id="toc-wiz-require-consent" value="1" <?php checked( (int) get_option( 'toc_require_sms_consent', 1 ), 1 ); ?> />
						<?php echo esc_html__( 'Require consent before automatic/bulk SMS', 'twilio-order-communicator' ); ?>
					</label>
				</p>
				<p>
					<button type="button" class="button toc-wiz-back" data-back="3"><?php echo esc_html__( 'Back', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button button-primary toc-wiz-next" data-next="5"><?php echo esc_html__( 'Save & continue', 'twilio-order-communicator' ); ?></button>
				</p>
			</div>

			<div class="toc-wizard-panel" data-panel="5" <?php echo $step === 5 ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html__( '5. Automatic notifications', 'twilio-order-communicator' ); ?></h3>
				<p class="description"><?php echo esc_html__( 'Triggered by order status (Ready for Pickup / Shipped), not shipping method. Uses your Twilio account.', 'twilio-order-communicator' ); ?></p>
				<p><strong><?php echo esc_html__( 'Ready for Pickup', 'twilio-order-communicator' ); ?></strong></p>
				<p>
					<label><input type="checkbox" id="toc-wiz-auto-ready" value="1" <?php checked( (int) get_option( 'toc_auto_ready_enabled', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Enable auto notifications', 'twilio-order-communicator' ); ?></label><br>
					<label><input type="checkbox" id="toc-wiz-auto-ready-voice" value="1" <?php checked( (int) get_option( 'toc_auto_ready_voice', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Voice call', 'twilio-order-communicator' ); ?></label><br>
					<label><input type="checkbox" id="toc-wiz-auto-ready-sms" value="1" <?php checked( (int) get_option( 'toc_auto_ready_sms', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Also send SMS (requires consent)', 'twilio-order-communicator' ); ?></label>
				</p>
				<p><strong><?php echo esc_html__( 'Shipped', 'twilio-order-communicator' ); ?></strong></p>
				<p>
					<label><input type="checkbox" id="toc-wiz-auto-shipped" value="1" <?php checked( (int) get_option( 'toc_auto_shipped_enabled', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Enable auto notifications', 'twilio-order-communicator' ); ?></label><br>
					<label><input type="checkbox" id="toc-wiz-auto-shipped-voice" value="1" <?php checked( (int) get_option( 'toc_auto_shipped_voice', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Voice call', 'twilio-order-communicator' ); ?></label><br>
					<label><input type="checkbox" id="toc-wiz-auto-shipped-sms" value="1" <?php checked( (int) get_option( 'toc_auto_shipped_sms', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Also send SMS (requires consent)', 'twilio-order-communicator' ); ?></label>
				</p>
				<hr>
				<p>
					<label><input type="checkbox" id="toc-wiz-quiet" value="1" <?php checked( (int) get_option( 'toc_quiet_hours_enabled', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Enable quiet hours (defer auto notify)', 'twilio-order-communicator' ); ?></label>
				</p>
				<p>
					<label><?php echo esc_html__( 'Quiet from', 'twilio-order-communicator' ); ?>
						<input type="time" id="toc-wiz-quiet-start" value="<?php echo esc_attr( TOC_Auto::normalize_time_option( get_option( 'toc_quiet_hours_start', '21:00' ), '21:00' ) ); ?>" />
					</label>
					<label><?php echo esc_html__( 'until', 'twilio-order-communicator' ); ?>
						<input type="time" id="toc-wiz-quiet-end" value="<?php echo esc_attr( TOC_Auto::normalize_time_option( get_option( 'toc_quiet_hours_end', '08:00' ), '08:00' ) ); ?>" />
					</label>
					<span class="description"><?php echo esc_html__( 'Store timezone', 'twilio-order-communicator' ); ?>: <?php echo esc_html( wp_timezone_string() ); ?></span>
				</p>
				<p>
					<button type="button" class="button toc-wiz-back" data-back="4"><?php echo esc_html__( 'Back', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button button-primary toc-wiz-next" data-next="6"><?php echo esc_html__( 'Save & continue', 'twilio-order-communicator' ); ?></button>
				</p>
			</div>

			<div class="toc-wizard-panel" data-panel="6" <?php echo $step === 6 ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html__( 'You are ready', 'twilio-order-communicator' ); ?></h3>
				<p><?php echo esc_html__( 'Mark a test order as Ready for Pickup (or Shipped) to verify auto notify. Check order notes if SMS is skipped.', 'twilio-order-communicator' ); ?></p>
				<p>
					<button type="button" class="button button-primary button-hero" id="toc-wiz-finish"><?php echo esc_html__( 'Finish setup', 'twilio-order-communicator' ); ?></button>
					<a class="button" href="<?php echo esc_url( TOC_Admin::tab_url( 'settings' ) ); ?>"><?php echo esc_html__( 'Open Settings', 'twilio-order-communicator' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}
}
