<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools & Docs tab UI.
 */
trait TOC_Admin_Tools {

	/* ---------- TOOLS & DOCS ---------- */
	private function render_tools() {
		$twilio      = TOC_Twilio::instance();
		$sms_rest    = TOC_Webhooks::rest_url( 'sms' );
		$sms_alias   = add_query_arg( 'toc_sms', '1', $twilio->public_base_url() );
		$msg_status  = $twilio->webhook_url( 'toc_msg_status' );
		$store_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$privacy     = sprintf(
			/* translators: %s: store name */
			__( "%s may send transactional SMS and/or place automated voice calls about your order status (for example Ready for Pickup or Shipped) using our telephony provider, Twilio. Message and data rates may apply. Message frequency varies. Reply STOP to opt out of SMS, HELP for help. Consent is not a condition of purchase. Voice calls do not require SMS consent. Phone numbers and message content are processed by Twilio on our behalf according to Twilio's privacy policy.", 'twilio-order-communicator' ),
			$store_name
		);
		?>
		<h2><?php echo esc_html__( 'Connection Test', 'twilio-order-communicator' ); ?></h2>
		<p><?php echo esc_html__( 'Verifies your Twilio Account SID + Auth Token against the Twilio API, and checks that the built-in TwiML endpoint responds.', 'twilio-order-communicator' ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="toc-test-btn"><?php echo esc_html__( 'Run Connection Test', 'twilio-order-communicator' ); ?></button>
			<span id="toc-test-result" style="margin-left:12px;"></span>
		</p>

		<hr>

		<h2><?php echo esc_html__( 'Built-in TwiML Endpoint', 'twilio-order-communicator' ); ?></h2>
		<p><?php echo wp_kses_post( __( 'Voice calls use a short-lived tokenized TwiML URL generated per call (the spoken text is <strong>not</strong> put in the query string). No separate page or Code Snippet is required.', 'twilio-order-communicator' ) ); ?></p>
		<p><?php echo esc_html__( 'Admins can still preview TwiML while logged in:', 'twilio-order-communicator' ); ?></p>
		<p><code><?php echo esc_html( add_query_arg( array( 'toc_twiml' => '1', 'message' => rawurlencode( 'Hello, this is a test of the voice system.' ) ), home_url( '/' ) ) ); ?></code></p>
		<p class="description"><?php echo wp_kses_post( __( 'Open that URL while logged into wp-admin as a shop manager. You should see XML with a <code>&lt;Say&gt;</code> element.', 'twilio-order-communicator' ) ); ?></p>

		<hr>

		<h2><?php echo esc_html__( 'Incoming SMS Webhook', 'twilio-order-communicator' ); ?></h2>
		<p><?php echo esc_html__( 'In Twilio Console → Phone Numbers → your number → Messaging → “A MESSAGE COMES IN”:', 'twilio-order-communicator' ); ?></p>
		<ul>
			<li><?php echo esc_html__( 'Webhook', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'Preferred REST URL:', 'twilio-order-communicator' ); ?> <code><?php echo esc_html( $sms_rest ); ?></code></li>
			<li><?php echo esc_html__( 'Legacy alias (still supported):', 'twilio-order-communicator' ); ?> <code><?php echo esc_html( $sms_alias ); ?></code></li>
			<li><?php echo esc_html__( 'HTTP POST', 'twilio-order-communicator' ); ?></li>
		</ul>
		<p class="description"><?php echo wp_kses_post( __( 'Requests are validated with Twilio’s <code>X-Twilio-Signature</code> header. Unsigned requests are rejected (403). STOP / HELP / START keywords are handled automatically and logged in order chat.', 'twilio-order-communicator' ) ); ?></p>
		<p><?php echo wp_kses_post( sprintf( __( 'SMS delivery status callbacks are attached automatically on each outbound message (<code>%s</code>) — no extra Twilio console setup needed.', 'twilio-order-communicator' ), esc_html( $msg_status ) ) ); ?></p>

		<hr>

		<h2><?php echo esc_html__( 'Privacy Policy helper', 'twilio-order-communicator' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Copy this paragraph into your store Privacy Policy (customize as needed). You bring your own Twilio account; Twilio processes messages on your behalf.', 'twilio-order-communicator' ); ?></p>
		<textarea class="large-text" rows="5" readonly onclick="this.select();"><?php echo esc_textarea( $privacy ); ?></textarea>

		<hr>

		<h2><?php echo esc_html__( 'How Automatic Notifications Work', 'twilio-order-communicator' ); ?></h2>
		<ul>
			<li><?php echo esc_html__( 'Primary trigger is order status (mapped Ready for Pickup and/or Shipped), not shipping method.', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'Each status has independent Enable / Voice / SMS toggles and its own message template.', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'An order can receive both notifications (Ready for Pickup first, later Shipped).', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'Optional: restrict Ready for Pickup auto-notify to orders whose shipping method looks like Local Pickup (off by default on new installs).', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'Voice calls do not require SMS consent.', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'SMS is only sent automatically if the customer has consented (when Require consent is on) and has not texted STOP.', 'twilio-order-communicator' ); ?></li>
			<li><?php echo wp_kses_post( __( 'Each status notifies once: <code>_toc_notified_ready_for_pickup_at</code> / <code>_toc_notified_shipped_at</code>. Clear the relevant meta to re-fire.', 'twilio-order-communicator' ) ); ?></li>
			<li><?php echo esc_html__( 'Bulk Reminders target orders currently in the mapped Ready for Pickup status (uses the Reminder template).', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'Order notes always explain if Auto SMS was skipped (setting off, no consent, Twilio error).', 'twilio-order-communicator' ); ?></li>
			<li><?php echo esc_html__( 'You must use your own Twilio Account SID, Auth Token, and From Number. This plugin does not provide messaging services.', 'twilio-order-communicator' ); ?></li>
		</ul>

		<hr>

		<h2><?php echo esc_html__( 'Settings Reference', 'twilio-order-communicator' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Setting', 'twilio-order-communicator' ); ?></th><th><?php echo esc_html__( 'Purpose', 'twilio-order-communicator' ); ?></th></tr></thead>
			<tbody>
				<tr><td><?php echo esc_html__( 'Account SID / Auth Token / From Number', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Your Twilio credentials (bring your own account). Optional wp-config constants: TOC_ACCOUNT_SID, TOC_AUTH_TOKEN, TOC_FROM_NUMBER.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Status mapping', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Which WooCommerce statuses trigger Ready for Pickup / Shipped logic.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Ready for Pickup / Shipped toggles', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Per-status enable, voice, and SMS controls.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Local Pickup filter', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Optional secondary check for Ready for Pickup auto-notify only.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Require SMS consent', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Block automatic/bulk SMS unless the customer opted in.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'SMS footer', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Optional compliance footer appended to outbound SMS.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Consent meta key', 'twilio-order-communicator' ); ?></td><td><?php echo wp_kses_post( __( 'Order meta field that stores the opt-in (default <code>_toc_sms_consent</code>).', 'twilio-order-communicator' ) ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Message templates', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Default text for Ready for Pickup, Shipped, reminders, and Issue / Contact.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Capabilities', 'twilio-order-communicator' ); ?></td><td><code>toc_manage_settings</code> / <code>toc_send_sms</code> <?php echo esc_html__( 'filters (default manage_woocommerce).', 'twilio-order-communicator' ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}
}
