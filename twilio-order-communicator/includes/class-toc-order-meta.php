<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Order_Meta {

	/** Order meta key: Unix timestamp when marked collected (empty = not collected). */
	const META_COLLECTED = '_toc_collected';

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

		// Classic + HPOS order actions dropdown.
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_toc_mark_collected', array( $this, 'action_mark_collected' ) );
		add_action( 'woocommerce_order_action_toc_unmark_collected', array( $this, 'action_unmark_collected' ) );
	}

	/**
	 * Whether an order is marked collected (pickup completed).
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return bool
	 */
	public static function is_collected( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( absint( $order ) );
		}
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		$val = $order->get_meta( self::META_COLLECTED );
		return ! empty( $val );
	}

	/**
	 * Mark order as collected; cancel pending scheduled reminders.
	 *
	 * @param WC_Order $order Order.
	 * @return bool True if state changed.
	 */
	public static function mark_collected( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		if ( self::is_collected( $order ) ) {
			return false;
		}
		$order->update_meta_data( self::META_COLLECTED, time() );
		$order->add_order_note( __( 'Order marked as collected (OrderRing). Auto-notify and pickup reminders are suppressed.', 'twilio-order-communicator' ) );
		$order->save();

		if ( class_exists( 'TOC_Reminders' ) ) {
			TOC_Reminders::instance()->cancel_for_order( $order->get_id() );
		}
		return true;
	}

	/**
	 * Clear collected flag.
	 *
	 * @param WC_Order $order Order.
	 * @return bool True if state changed.
	 */
	public static function unmark_collected( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		if ( ! self::is_collected( $order ) ) {
			return false;
		}
		$order->delete_meta_data( self::META_COLLECTED );
		$order->add_order_note( __( 'Collected flag cleared (OrderRing).', 'twilio-order-communicator' ) );
		$order->save();
		return true;
	}

	/**
	 * @param array $actions Existing order actions.
	 * @return array
	 */
	public function order_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}
		// Resolve order for HPOS list/edit screens when available.
		$order = null;
		if ( isset( $GLOBALS['theorder'] ) && is_a( $GLOBALS['theorder'], 'WC_Order' ) ) {
			$order = $GLOBALS['theorder'];
		} elseif ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $order && self::is_collected( $order ) ) {
			$actions['toc_unmark_collected'] = __( 'Unmark collected (OrderRing)', 'twilio-order-communicator' );
		} else {
			$actions['toc_mark_collected'] = __( 'Mark as collected (OrderRing)', 'twilio-order-communicator' );
		}
		return $actions;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function action_mark_collected( $order ) {
		self::mark_collected( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function action_unmark_collected( $order ) {
		self::unmark_collected( $order );
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
		$consent_state = method_exists( $twilio, 'get_sms_consent_state' )
			? $twilio->get_sms_consent_state( $order_id )
			: ( $consented ? 'yes' : 'no' );
		$opted_out = $phone && $twilio->phone_is_opted_out( $phone );
		$collected = self::is_collected( $order );
		$collected_at = $order->get_meta( self::META_COLLECTED );

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
				<?php if ( $collected ) : ?>
					<?php
					$ts  = is_numeric( $collected_at ) ? (int) $collected_at : 0;
					$lbl = $ts
						? sprintf(
							/* translators: %s: local datetime */
							__( 'Collected %s', 'twilio-order-communicator' ),
							date_i18n( 'M j, g:i a', $ts )
						)
						: __( 'Collected', 'twilio-order-communicator' );
					?>
					<span class="toc-badge toc-badge-ok" title="<?php echo esc_attr__( 'Pickup complete — auto-notify and scheduled reminders are suppressed', 'twilio-order-communicator' ); ?>"><?php echo esc_html( $lbl ); ?></span>
				<?php endif; ?>
				<?php if ( $opted_out ) : ?>
					<span class="toc-badge toc-badge-no"><?php echo esc_html__( 'SMS STOP / opted out', 'twilio-order-communicator' ); ?></span>
				<?php elseif ( 'yes' === $consent_state || $consented ) : ?>
					<span class="toc-badge toc-badge-ok"><?php echo esc_html__( 'SMS consent: Yes', 'twilio-order-communicator' ); ?></span>
				<?php elseif ( 'no' === $consent_state ) : ?>
					<span class="toc-badge toc-badge-no"><?php echo esc_html__( 'SMS consent: No', 'twilio-order-communicator' ); ?></span>
				<?php else : ?>
					<span class="toc-badge"><?php echo esc_html__( 'SMS consent: Unknown', 'twilio-order-communicator' ); ?></span>
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

	/**
	 * Resolve the number to contact for an order.
	 *
	 * Defaults to the order's billing phone. An operator-supplied phone is honored only
	 * when it matches that billing number (formatting-tolerant via TOC_Logger::phones_match),
	 * so the order screen can never be used to message an arbitrary number under an
	 * order_id. Returns '' when there is no usable, order-bound number.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $posted Operator-supplied phone (may be empty).
	 * @return string
	 */
	private function resolve_order_phone( $order, $posted ) {
		$billing = (string) $order->get_billing_phone();
		$posted  = trim( (string) $posted );
		if ( '' === $posted ) {
			return $billing;
		}
		if ( '' !== $billing && TOC_Logger::phones_match( $posted, $billing ) ) {
			return $posted;
		}
		return '';
	}

	public function ajax_sms() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::send() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$posted   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$force    = ! empty( $_POST['force'] );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'twilio-order-communicator' ) );
		}
		// Bind the destination to the order's own billing phone so a send can never be
		// directed at an arbitrary number while attributed to this order.
		$phone = $this->resolve_order_phone( $order, $posted );
		if ( empty( $message ) || '' === $phone ) {
			wp_send_json_error( __( 'A message is required, and the phone must match the order\'s billing number.', 'twilio-order-communicator' ) );
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
		if ( ! current_user_can( TOC_Caps::send() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$posted   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'twilio-order-communicator' ) );
		}
		// Bind the destination to the order's own billing phone (see ajax_sms).
		$phone = $this->resolve_order_phone( $order, $posted );
		if ( empty( $message ) || '' === $phone ) {
			wp_send_json_error( __( 'A message is required, and the phone must match the order\'s billing number.', 'twilio-order-communicator' ) );
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
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
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
