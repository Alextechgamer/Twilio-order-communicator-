<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Order_Meta {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'wp_ajax_toc_send_sms', array( $this, 'ajax_sms' ) );
		add_action( 'wp_ajax_toc_send_call', array( $this, 'ajax_call' ) );
		add_action( 'wp_ajax_toc_get_history', array( $this, 'ajax_history' ) );
	}

	public function meta_box() {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( 'toc_communications', 'Customer Communications', array( $this, 'render' ), $screen, 'normal', 'high' );
		}
	}

	public function render( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			echo '<p>Unable to load order.</p>';
			return;
		}

		$order_id  = $order->get_id();
		$phone     = $order->get_billing_phone();
		$history   = TOC_Logger::instance()->get_order_history( $order_id );
		$twilio    = TOC_Twilio::instance();
		$consented = $twilio->customer_consented_sms( $order_id );
		$opted_out = $phone && $twilio->phone_is_opted_out( $phone );

		$pickup   = $twilio->merge_tags(
			get_option( 'toc_default_pickup_message', 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.' ),
			$order
		);
		$reminder = $twilio->merge_tags(
			get_option( 'toc_default_reminder_message', 'Hello {customer_first_name}. This is a reminder that your order #{order_number} is still waiting for pickup. Please stop by at your earliest convenience. Thank you.' ),
			$order
		);
		$issue    = $twilio->merge_tags(
			get_option( 'toc_default_issue_message', 'Hello {customer_first_name}. There is an issue with your recent order #{order_number} that requires your attention. Please contact us or reply to this message. Thank you.' ),
			$order
		);
		?>
		<div class="toc-chat"
			data-order-id="<?php echo esc_attr( $order_id ); ?>"
			data-phone="<?php echo esc_attr( $phone ); ?>"
			data-consent="<?php echo $consented ? '1' : '0'; ?>"
			data-opted-out="<?php echo $opted_out ? '1' : '0'; ?>">
			<div class="toc-chat-header">
				<strong>Phone:</strong> <?php echo esc_html( $phone ?: 'No phone on file' ); ?>
				<?php if ( $opted_out ) : ?>
					<span class="toc-badge toc-badge-no">SMS STOP / opted out</span>
				<?php elseif ( $consented ) : ?>
					<span class="toc-badge toc-badge-ok">SMS consent: Yes</span>
				<?php else : ?>
					<span class="toc-badge toc-badge-no">SMS consent: No</span>
				<?php endif; ?>
				<?php if ( ! $twilio->is_configured() ) : ?>
					<span class="toc-warn">Twilio not configured</span>
				<?php endif; ?>
				<button type="button" class="button button-small toc-resolve-order" style="margin-left:auto">Mark conversation resolved</button>
			</div>

			<div class="toc-history">
				<?php if ( empty( $history ) ) : ?>
					<div class="toc-empty">No messages or calls yet.</div>
				<?php else : ?>
					<?php foreach ( $history as $row ) { $this->bubble( $row ); } ?>
				<?php endif; ?>
			</div>

			<div class="toc-actions">
				<div class="toc-quick">
					<button type="button" class="button toc-tpl" data-tpl="pickup">Ready for Pickup</button>
					<button type="button" class="button toc-tpl" data-tpl="reminder">Pickup Reminder</button>
					<button type="button" class="button toc-tpl" data-tpl="issue">Issue / Contact</button>
				</div>
				<textarea id="toc-message" rows="2" placeholder="Type a custom message…"></textarea>
				<div class="toc-btns">
					<button type="button" class="button button-primary" id="toc-sms">Send SMS</button>
					<button type="button" class="button" id="toc-call">Place Call</button>
				</div>
			</div>

			<script type="text/template" id="toc-tpl-pickup"><?php echo esc_html( $pickup ); ?></script>
			<script type="text/template" id="toc-tpl-reminder"><?php echo esc_html( $reminder ); ?></script>
			<script type="text/template" id="toc-tpl-issue"><?php echo esc_html( $issue ); ?></script>
		</div>
		<?php
	}

	private function bubble( $row ) {
		$out      = $row->direction === 'outbound';
		$voice    = $row->type === 'voice';
		$resolved = ! empty( $row->resolved );
		$class    = 'toc-bubble ' . ( $out ? 'out' : 'in' ) . ( $voice ? ' voice' : '' ) . ( $resolved ? ' resolved' : '' );
		$icon     = $voice ? 'Call' : 'SMS';
		$time     = date_i18n( 'M j, g:i a', strtotime( $row->created_at ) );
		$status   = $row->status ? ' · ' . esc_html( $row->status ) : '';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-id="<?php echo (int) $row->id; ?>">
			<div class="meta">
				<?php echo esc_html( $icon ); ?> <?php echo $out ? 'You' : 'Customer'; ?>
				<span class="time"><?php echo esc_html( $time . $status ); ?></span>
				<?php if ( $resolved ) : ?>
					<span class="tag">resolved</span>
				<?php elseif ( ! $out ) : ?>
					<button type="button" class="toc-resolve-one" data-id="<?php echo (int) $row->id; ?>" title="Mark resolved">OK</button>
				<?php endif; ?>
			</div>
			<div class="body"><?php echo nl2br( esc_html( $row->body ) ); ?></div>
		</div>
		<?php
	}

	public function ajax_sms() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$force    = ! empty( $_POST['force'] );

		if ( empty( $message ) || empty( $phone ) ) {
			wp_send_json_error( 'Message and phone required.' );
		}

		// Manual send can force (bypass consent) after UI confirm.
		$result = TOC_Twilio::instance()->send_sms( $phone, $message, $order_id, $force ? true : false );

		// If blocked by consent and not forced, tell the client so it can confirm.
		if ( empty( $result['success'] ) && ! $force ) {
			$err = (string) ( $result['error'] ?? '' );
			if ( stripos( $err, 'consented' ) !== false || stripos( $err, 'opted out' ) !== false ) {
				wp_send_json_error(
					array(
						'code'    => 'needs_force',
						'message' => $err,
					)
				);
			}
		}

		if ( ! empty( $result['success'] ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'SMS sent: "' . wp_trim_words( $message, 15 ) . '"' . ( $force ? ' (forced)' : '' ) );
			}
			wp_send_json_success();
		}
		wp_send_json_error( is_array( $result['error'] ?? null ) ? $result['error'] : ( $result['error'] ?? 'Failed' ) );
	}

	public function ajax_call() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( empty( $message ) || empty( $phone ) ) {
			wp_send_json_error( 'Message and phone required.' );
		}

		$result = TOC_Twilio::instance()->make_call( $phone, $message, $order_id );

		if ( ! empty( $result['success'] ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'Voice call placed (SID: ' . $result['sid'] . ')' );
			}
			wp_send_json_success();
		}
		wp_send_json_error( $result['error'] ?? 'Failed' );
	}

	public function ajax_history() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$history  = TOC_Logger::instance()->get_order_history( $order_id );

		ob_start();
		if ( empty( $history ) ) {
			echo '<div class="toc-empty">No messages or calls yet.</div>';
		} else {
			foreach ( $history as $row ) {
				$this->bubble( $row );
			}
		}
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}
}
