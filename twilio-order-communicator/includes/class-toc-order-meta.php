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
			add_meta_box(
				'toc_communications',
				__( 'Customer Communications', 'twilio-order-communicator' ),
				array( $this, 'render' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	public function render( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Unable to load order.', 'twilio-order-communicator' ) . '</p>';
			return;
		}

		$order_id  = $order->get_id();
		$phone     = $order->get_billing_phone();
		$history   = TOC_Logger::instance()->get_order_history( $order_id );
		$twilio    = TOC_Twilio::instance();
		$consented = $twilio->customer_consented_sms( $order_id );
		$opted_out = $phone && $twilio->phone_is_opted_out( $phone );

		$pickup   = $twilio->merge_tags(
			TOC_Auto::get_message_template( TOC_Auto::KIND_READY ),
			$order
		);
		$shipped  = $twilio->merge_tags(
			TOC_Auto::get_message_template( TOC_Auto::KIND_SHIPPED ),
			$order
		);
		$reminder = $twilio->merge_tags(
			TOC_Auto::get_message_template( 'reminder' ),
			$order
		);
		$issue    = $twilio->merge_tags(
			TOC_Auto::get_message_template( 'issue' ),
			$order
		);
		?>
		<div class="toc-chat"
			data-order-id="<?php echo esc_attr( $order_id ); ?>"
			data-phone="<?php echo esc_attr( $phone ); ?>"
			data-consent="<?php echo $consented ? '1' : '0'; ?>"
			data-opted-out="<?php echo $opted_out ? '1' : '0'; ?>">
			<div class="toc-chat-header">
				<strong><?php echo esc_html__( 'Phone:', 'twilio-order-communicator' ); ?></strong> <?php echo esc_html( $phone ?: __( 'No phone on file', 'twilio-order-communicator' ) ); ?>
				<?php if ( $opted_out ) : ?>
					<span class="toc-badge toc-badge-no"><?php echo esc_html__( 'SMS STOP / opted out', 'twilio-order-communicator' ); ?></span>
				<?php elseif ( $consented ) : ?>
					<span class="toc-badge toc-badge-ok"><?php echo esc_html__( 'SMS consent: Yes', 'twilio-order-communicator' ); ?></span>
				<?php else : ?>
					<span class="toc-badge toc-badge-no"><?php echo esc_html__( 'SMS consent: No', 'twilio-order-communicator' ); ?></span>
				<?php endif; ?>
				<?php if ( ! $twilio->is_configured() ) : ?>
					<span class="toc-warn"><?php echo esc_html__( 'Twilio not configured', 'twilio-order-communicator' ); ?></span>
				<?php endif; ?>
				<button type="button" class="button button-small toc-resolve-order" style="margin-left:auto"><?php echo esc_html__( 'Mark conversation resolved', 'twilio-order-communicator' ); ?></button>
			</div>

			<div class="toc-history">
				<?php if ( empty( $history ) ) : ?>
					<div class="toc-empty"><?php echo esc_html__( 'No messages or calls yet.', 'twilio-order-communicator' ); ?></div>
				<?php else : ?>
					<?php foreach ( $history as $row ) { $this->bubble( $row ); } ?>
				<?php endif; ?>
			</div>

			<div class="toc-actions">
				<div class="toc-quick">
					<button type="button" class="button toc-tpl" data-tpl="pickup"><?php echo esc_html__( 'Ready for Pickup', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button toc-tpl" data-tpl="shipped"><?php echo esc_html__( 'Shipped', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button toc-tpl" data-tpl="reminder"><?php echo esc_html__( 'Pickup Reminder', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button toc-tpl" data-tpl="issue"><?php echo esc_html__( 'Issue / Contact', 'twilio-order-communicator' ); ?></button>
				</div>
				<textarea id="toc-message" rows="2" placeholder="<?php echo esc_attr__( 'Type a custom message…', 'twilio-order-communicator' ); ?>"></textarea>
				<div class="toc-btns">
					<button type="button" class="button button-primary" id="toc-sms"><?php echo esc_html__( 'Send SMS', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button" id="toc-call"><?php echo esc_html__( 'Place Call', 'twilio-order-communicator' ); ?></button>
				</div>
			</div>

			<script type="text/template" id="toc-tpl-pickup"><?php echo esc_html( $pickup ); ?></script>
			<script type="text/template" id="toc-tpl-shipped"><?php echo esc_html( $shipped ); ?></script>
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
		$icon     = $voice ? __( 'Call', 'twilio-order-communicator' ) : __( 'SMS', 'twilio-order-communicator' );
		$time     = date_i18n( 'M j, g:i a', strtotime( $row->created_at ) );
		$status   = $row->status ? ' · ' . esc_html( $row->status ) : '';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-id="<?php echo (int) $row->id; ?>">
			<div class="meta">
				<?php echo esc_html( $icon ); ?> <?php echo $out ? esc_html__( 'You', 'twilio-order-communicator' ) : esc_html__( 'Customer', 'twilio-order-communicator' ); ?>
				<span class="time"><?php echo esc_html( $time ) . $status; ?></span>
				<?php if ( $resolved ) : ?>
					<span class="tag"><?php echo esc_html__( 'resolved', 'twilio-order-communicator' ); ?></span>
				<?php elseif ( ! $out ) : ?>
					<button type="button" class="toc-resolve-one" data-id="<?php echo (int) $row->id; ?>" title="<?php echo esc_attr__( 'Mark resolved', 'twilio-order-communicator' ); ?>"><?php echo esc_html__( 'OK', 'twilio-order-communicator' ); ?></button>
				<?php endif; ?>
			</div>
			<div class="body"><?php echo nl2br( esc_html( $row->body ) ); ?></div>
		</div>
		<?php
	}

	public function ajax_sms() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$force    = ! empty( $_POST['force'] );

		if ( empty( $message ) || empty( $phone ) ) {
			wp_send_json_error( __( 'Message and phone required.', 'twilio-order-communicator' ) );
		}

		$result = TOC_Twilio::instance()->send_sms( $phone, $message, $order_id, $force ? true : false );

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
				$order->add_order_note(
					sprintf(
						/* translators: 1: trimmed message, 2: optional forced suffix */
						__( 'SMS sent: "%1$s"%2$s', 'twilio-order-communicator' ),
						wp_trim_words( $message, 15 ),
						$force ? ' ' . __( '(forced)', 'twilio-order-communicator' ) : ''
					)
				);
			}
			wp_send_json_success();
		}
		wp_send_json_error( is_array( $result['error'] ?? null ) ? $result['error'] : ( $result['error'] ?? __( 'Failed', 'twilio-order-communicator' ) ) );
	}

	public function ajax_call() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( empty( $message ) || empty( $phone ) ) {
			wp_send_json_error( __( 'Message and phone required.', 'twilio-order-communicator' ) );
		}

		$result = TOC_Twilio::instance()->make_call( $phone, $message, $order_id );

		if ( ! empty( $result['success'] ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: Twilio call SID */
						__( 'Voice call placed (SID: %s)', 'twilio-order-communicator' ),
						$result['sid']
					)
				);
			}
			wp_send_json_success();
		}
		wp_send_json_error( $result['error'] ?? __( 'Failed', 'twilio-order-communicator' ) );
	}

	public function ajax_history() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$history  = TOC_Logger::instance()->get_order_history( $order_id );

		ob_start();
		if ( empty( $history ) ) {
			echo '<div class="toc-empty">' . esc_html__( 'No messages or calls yet.', 'twilio-order-communicator' ) . '</div>';
		} else {
			foreach ( $history as $row ) {
				$this->bubble( $row );
			}
		}
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}
}
