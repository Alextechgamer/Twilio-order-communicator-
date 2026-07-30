<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Admin {

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

		add_action( 'wp_ajax_toc_mark_resolved', array( $this, 'ajax_resolve' ) );
		add_action( 'wp_ajax_toc_bulk_reminder', array( $this, 'ajax_bulk' ) );
		add_action( 'wp_ajax_toc_test_connection', array( $this, 'ajax_test' ) );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order Communicator', 'twilio-order-communicator' ),
			__( 'Order Communicator', 'twilio-order-communicator' ),
			'manage_woocommerce',
			'toc-communicator',
			array( $this, 'page' )
		);
	}

	public function register_settings() {
		$text_fields = array(
			'toc_account_sid'               => 'sanitize_text_field',
			'toc_from_number'               => 'sanitize_text_field',
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
	}

	public function sanitize_checkbox( $value ) {
		return ( ! empty( $value ) && (string) $value !== '0' ) ? 1 : 0;
	}

	public function sanitize_voice( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = array( 'alice', 'man', 'woman', 'polly.joanna', 'polly.matthew', 'polly.amy' );
		return in_array( $value, $allowed, true ) ? $value : 'alice';
	}

	public function sanitize_auth_token( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( $value === '' ) {
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

	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$load = ( strpos( $hook, 'toc-communicator' ) !== false )
			|| in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( ! $load ) {
			return;
		}

		wp_enqueue_style( 'toc-admin', TOC_PLUGIN_URL . 'assets/admin.css', array(), TOC_VERSION );
		wp_enqueue_script( 'toc-admin', TOC_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), TOC_VERSION, true );
		wp_localize_script(
			'toc-admin',
			'tocData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'toc_nonce' ),
				'i18n'     => array(
					'enterMessage'       => __( 'Enter a message', 'twilio-order-communicator' ),
					'noPhone'            => __( 'No phone number on this order', 'twilio-order-communicator' ),
					'optOutConfirm'      => __( 'This phone has opted out (STOP). Send SMS anyway?', 'twilio-order-communicator' ),
					'noConsentConfirm'   => __( 'Customer has not opted in to SMS. Send anyway?', 'twilio-order-communicator' ),
					'placeCallConfirm'   => __( 'Place a voice call with the current message?', 'twilio-order-communicator' ),
					'errorPrefix'        => __( 'Error:', 'twilio-order-communicator' ),
					'requestFailed'      => __( 'Request failed', 'twilio-order-communicator' ),
					'couldNotResolve'    => __( 'Could not mark resolved', 'twilio-order-communicator' ),
					'markResolved'       => __( 'Mark Resolved', 'twilio-order-communicator' ),
					'conversationDone'   => __( 'Conversation resolved', 'twilio-order-communicator' ),
					'resolved'           => __( 'Resolved', 'twilio-order-communicator' ),
					'selectOrders'       => __( 'Select at least one order', 'twilio-order-communicator' ),
					'sending'            => __( 'Sending…', 'twilio-order-communicator' ),
					'sendSelected'       => __( 'Send to Selected', 'twilio-order-communicator' ),
					'stop'               => __( 'Stop', 'twilio-order-communicator' ),
					'stopping'           => __( 'Stopping…', 'twilio-order-communicator' ),
					'testing'            => __( 'Testing…', 'twilio-order-communicator' ),
					'testBtn'            => __( 'Run Connection Test', 'twilio-order-communicator' ),
					'unknown'            => __( 'Unknown', 'twilio-order-communicator' ),
					'needsForce'         => __( 'SMS blocked by consent', 'twilio-order-communicator' ),
					'sendAnyway'         => __( 'Send anyway?', 'twilio-order-communicator' ),
				),
			)
		);
	}

	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		?>
		<div class="wrap toc-wrap">
			<h1><?php echo esc_html__( 'Twilio Order Communicator', 'twilio-order-communicator' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=dashboard' ) ); ?>" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Dashboard', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=bulk' ) ); ?>" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Bulk Reminders', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=settings' ) ); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Settings', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=setup' ) ); ?>" class="nav-tab <?php echo $tab === 'setup' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Setup', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=tools' ) ); ?>" class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Tools & Docs', 'twilio-order-communicator' ); ?></a>
			</nav>
			<div class="toc-content">
				<?php
				if ( $tab === 'settings' ) {
					$this->render_settings();
				} elseif ( $tab === 'bulk' ) {
					$this->render_bulk();
				} elseif ( $tab === 'setup' ) {
					TOC_Onboarding::instance()->render();
				} elseif ( $tab === 'tools' ) {
					$this->render_tools();
				} else {
					$this->render_dashboard();
				}
				?>
			</div>
		</div>
		<?php
	}

	/* ---------- DASHBOARD ---------- */
	private function render_dashboard() {
		$logger   = TOC_Logger::instance();
		$stats    = $logger->get_stats();
		$per_page = 40;
		$page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$filters = array(
			'type'      => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
			'direction' => isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : '',
			'resolved'  => isset( $_GET['resolved'] ) ? sanitize_text_field( wp_unslash( $_GET['resolved'] ) ) : '',
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'limit'     => $per_page,
			'offset'    => ( $page_num - 1 ) * $per_page,
		);
		$results     = $logger->get_filtered( $filters );
		$total       = $logger->count_filtered( $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<div class="toc-stats">
			<div class="toc-stat"><span class="num"><?php echo (int) $stats['today_sms']; ?></span><span class="lbl"><?php echo esc_html__( 'SMS today', 'twilio-order-communicator' ); ?></span></div>
			<div class="toc-stat"><span class="num"><?php echo (int) $stats['today_calls']; ?></span><span class="lbl"><?php echo esc_html__( 'Calls today', 'twilio-order-communicator' ); ?></span></div>
			<div class="toc-stat alert"><span class="num"><?php echo (int) $stats['unresolved']; ?></span><span class="lbl"><?php echo esc_html__( 'Unresolved', 'twilio-order-communicator' ); ?></span></div>
			<div class="toc-stat"><span class="num"><?php echo (int) $stats['total']; ?></span><span class="lbl"><?php echo esc_html__( 'Total', 'twilio-order-communicator' ); ?></span></div>
		</div>

		<form method="get" class="toc-filters">
			<input type="hidden" name="page" value="toc-communicator" />
			<select name="type">
				<option value=""><?php echo esc_html__( 'All types', 'twilio-order-communicator' ); ?></option>
				<option value="sms" <?php selected( $filters['type'], 'sms' ); ?>><?php echo esc_html__( 'SMS', 'twilio-order-communicator' ); ?></option>
				<option value="voice" <?php selected( $filters['type'], 'voice' ); ?>><?php echo esc_html__( 'Voice', 'twilio-order-communicator' ); ?></option>
			</select>
			<select name="direction">
				<option value=""><?php echo esc_html__( 'All', 'twilio-order-communicator' ); ?></option>
				<option value="outbound" <?php selected( $filters['direction'], 'outbound' ); ?>><?php echo esc_html__( 'Outbound', 'twilio-order-communicator' ); ?></option>
				<option value="inbound" <?php selected( $filters['direction'], 'inbound' ); ?>><?php echo esc_html__( 'Inbound', 'twilio-order-communicator' ); ?></option>
			</select>
			<select name="resolved">
				<option value=""><?php echo esc_html__( 'All statuses', 'twilio-order-communicator' ); ?></option>
				<option value="0" <?php selected( $filters['resolved'], '0' ); ?>><?php echo esc_html__( 'Unresolved', 'twilio-order-communicator' ); ?></option>
				<option value="1" <?php selected( $filters['resolved'], '1' ); ?>><?php echo esc_html__( 'Resolved', 'twilio-order-communicator' ); ?></option>
			</select>
			<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
			<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Search…', 'twilio-order-communicator' ); ?>" />
			<button class="button"><?php echo esc_html__( 'Filter', 'twilio-order-communicator' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator' ) ); ?>" class="button"><?php echo esc_html__( 'Reset', 'twilio-order-communicator' ); ?></a>
		</form>

		<p class="toc-count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of matching communications */
					_n( '%d result', '%d results', $total, 'twilio-order-communicator' ),
					$total
				)
			);
			if ( $total_pages > 1 ) {
				echo ' · ' . esc_html(
					sprintf(
						/* translators: 1: current page, 2: total pages */
						__( 'Page %1$d of %2$d', 'twilio-order-communicator' ),
						$page_num,
						$total_pages
					)
				);
			}
			?>
		</p>

		<?php if ( empty( $results ) ) : ?>
			<p><?php echo esc_html__( 'No communications found.', 'twilio-order-communicator' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php echo esc_html__( 'Date', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Order', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Phone', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Type', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Dir', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Message', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'twilio-order-communicator' ); ?></th>
					<th><?php echo esc_html__( 'Resolved', 'twilio-order-communicator' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $results as $row ) :
					$link = '—';
					if ( $row->order_id ) {
						$order = wc_get_order( $row->order_id );
						if ( $order ) {
							$link = '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . (int) $row->order_id . '</a>';
						} else {
							$link = '#' . (int) $row->order_id;
						}
					}
					$type_label = $row->type === 'voice'
						? __( 'Voice', 'twilio-order-communicator' )
						: __( 'SMS', 'twilio-order-communicator' );
					$dir_label = $row->direction === 'inbound'
						? __( 'In', 'twilio-order-communicator' )
						: __( 'Out', 'twilio-order-communicator' );
					?>
					<tr class="<?php echo $row->resolved ? 'toc-resolved' : ''; ?>">
						<td><?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $row->created_at ) ) ); ?></td>
						<td><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_url above ?></td>
						<td><?php echo esc_html( $row->phone ); ?></td>
						<td><?php echo esc_html( $type_label ); ?></td>
						<td><?php echo esc_html( $dir_label ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $row->body, 14 ) ); ?></td>
						<td><?php echo esc_html( $row->status ); ?></td>
						<td>
							<?php if ( $row->resolved ) : ?>
								<span class="toc-badge"><?php echo esc_html__( 'Resolved', 'twilio-order-communicator' ); ?></span>
							<?php else : ?>
								<button type="button" class="button button-small toc-resolve" data-id="<?php echo (int) $row->id; ?>"><?php echo esc_html__( 'Mark Resolved', 'twilio-order-communicator' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<?php
				$base_args = array(
					'page'      => 'toc-communicator',
					'type'      => $filters['type'],
					'direction' => $filters['direction'],
					'resolved'  => $filters['resolved'],
					's'         => $filters['search'],
					'date_from' => $filters['date_from'],
					'date_to'   => $filters['date_to'],
				);
				$base_args = array_filter(
					$base_args,
					static function ( $v ) {
						return $v !== '' && $v !== null;
					}
				);
				?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php if ( $page_num > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $page_num - 1 ) ), admin_url( 'admin.php' ) ) ); ?>">&lsaquo; <?php echo esc_html__( 'Previous', 'twilio-order-communicator' ); ?></a>
						<?php endif; ?>
						<?php if ( $page_num < $total_pages ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $page_num + 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Next', 'twilio-order-communicator' ); ?> &rsaquo;</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif;
	}

	/* ---------- BULK ---------- */
	private function render_bulk() {
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		if ( ! in_array( $days, array( 7, 14, 30, 60, 90, 180 ), true ) ) {
			$days = 30;
		}

		$hide_recent = isset( $_GET['hide_recent'] ) ? absint( $_GET['hide_recent'] ) : 0;
		if ( ! in_array( $hide_recent, array( 0, 24, 48, 72, 168 ), true ) ) {
			$hide_recent = 0;
		}

		$ready_status = TOC_Statuses::mapped_ready_status();
		$ready_label  = TOC_Statuses::all_order_statuses()[ $ready_status ] ?? TOC_Statuses::bare_status( $ready_status );

		$orders = TOC_Logger::instance()->get_bulk_pickup_orders(
			array(
				'days'              => $days,
				'limit'             => 200,
				'skip_recent_hours' => $hide_recent,
			)
		);

		$msg = TOC_Auto::get_message_template( 'reminder' );

		$delay_default = (int) get_option( 'toc_bulk_delay_seconds', 8 );
		if ( $delay_default < 1 ) {
			$delay_default = 8;
		}
		$consent_required = (int) get_option( 'toc_require_sms_consent', 1 ) === 1;
		$twilio           = TOC_Twilio::instance();
		?>
		<h2><?php echo esc_html__( 'Bulk Pickup Reminders', 'twilio-order-communicator' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: order status label */
					__( 'Orders currently in “%s” with a phone number. Voice calls never require SMS consent. SMS is only sent when the customer has consented', 'twilio-order-communicator' ),
					wp_strip_all_tags( $ready_label )
				)
			);
			echo $consent_required ? '' : esc_html__( ' (consent currently disabled in Settings)', 'twilio-order-communicator' );
			echo '.';
			?>
		</p>

		<form method="get" class="toc-filters toc-bulk-filters">
			<input type="hidden" name="page" value="toc-communicator" />
			<input type="hidden" name="tab" value="bulk" />
			<label><?php echo esc_html__( 'Created within', 'twilio-order-communicator' ); ?>
				<select name="days">
					<?php foreach ( array( 7, 14, 30, 60, 90, 180 ) as $d ) : ?>
						<option value="<?php echo (int) $d; ?>" <?php selected( $days, $d ); ?>><?php echo (int) $d; ?> <?php echo esc_html__( 'days', 'twilio-order-communicator' ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php echo esc_html__( 'Recently reminded', 'twilio-order-communicator' ); ?>
				<select name="hide_recent">
					<option value="0" <?php selected( $hide_recent, 0 ); ?>><?php echo esc_html__( 'Show all', 'twilio-order-communicator' ); ?></option>
					<option value="24" <?php selected( $hide_recent, 24 ); ?>><?php echo esc_html__( 'Hide if reminded < 24h ago', 'twilio-order-communicator' ); ?></option>
					<option value="48" <?php selected( $hide_recent, 48 ); ?>><?php echo esc_html__( 'Hide if reminded < 48h ago', 'twilio-order-communicator' ); ?></option>
					<option value="72" <?php selected( $hide_recent, 72 ); ?>><?php echo esc_html__( 'Hide if reminded < 72h ago', 'twilio-order-communicator' ); ?></option>
					<option value="168" <?php selected( $hide_recent, 168 ); ?>><?php echo esc_html__( 'Hide if reminded < 7 days ago', 'twilio-order-communicator' ); ?></option>
				</select>
			</label>
			<button class="button"><?php echo esc_html__( 'Apply filters', 'twilio-order-communicator' ); ?></button>
		</form>

		<?php if ( empty( $orders ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: order status label */
						__( 'No matching “%s” orders in this window.', 'twilio-order-communicator' ),
						wp_strip_all_tags( $ready_label )
					)
				);
				?>
			</p></div>
		<?php else : ?>
			<form id="toc-bulk-form">
				<p><label for="toc-bulk-message"><strong><?php echo esc_html__( 'Message', 'twilio-order-communicator' ); ?></strong></label><br>
				<textarea id="toc-bulk-message" rows="3" class="large-text"><?php echo esc_textarea( $msg ); ?></textarea></p>

				<div class="toc-bulk-options">
					<p><strong><?php echo esc_html__( 'Send as', 'twilio-order-communicator' ); ?></strong></p>
					<p class="toc-bulk-modes">
						<label><input type="radio" name="mode" value="call" checked> <?php echo esc_html__( 'Voice call only', 'twilio-order-communicator' ); ?></label>
						<label><input type="radio" name="mode" value="sms"> <?php echo esc_html__( 'SMS only', 'twilio-order-communicator' ); ?> <span class="description">(<?php echo esc_html__( 'consent required', 'twilio-order-communicator' ); ?>)</span></label>
						<label><input type="radio" name="mode" value="both"> <?php echo esc_html__( 'Call + SMS when consented', 'twilio-order-communicator' ); ?></label>
					</p>
					<p>
						<label for="toc-bulk-delay"><strong><?php echo esc_html__( 'Delay between each order', 'twilio-order-communicator' ); ?></strong></label>
						<input type="number" id="toc-bulk-delay" min="1" max="120" step="1" value="<?php echo (int) $delay_default; ?>" style="width:5em" /> <?php echo esc_html__( 'seconds', 'twilio-order-communicator' ); ?>
						<span class="description"><?php echo esc_html__( 'Wait between each order so Twilio and your site are not flooded. Recommended 5–15s for calls.', 'twilio-order-communicator' ); ?></span>
					</p>
				</div>

				<p class="toc-count"><?php echo (int) count( $orders ); ?> <?php echo esc_html__( 'order(s) listed', 'twilio-order-communicator' ); ?> · <span id="toc-bulk-selected-count"></span></p>

				<table class="wp-list-table widefat fixed striped toc-bulk-table">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="toc-check-all" checked></td>
							<th><?php echo esc_html__( 'Order', 'twilio-order-communicator' ); ?></th>
							<th><?php echo esc_html__( 'Customer', 'twilio-order-communicator' ); ?></th>
							<th><?php echo esc_html__( 'Phone', 'twilio-order-communicator' ); ?></th>
							<th><?php echo esc_html__( 'Status date', 'twilio-order-communicator' ); ?></th>
							<th><?php echo esc_html__( 'SMS consent', 'twilio-order-communicator' ); ?></th>
							<th><?php echo esc_html__( 'Last reminder', 'twilio-order-communicator' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $orders as $order ) :
						$oid       = $order->get_id();
						$consented = $twilio->customer_consented_sms( $oid );
						$last      = $order->get_meta( '_toc_last_reminder_at' );
						$last_lbl  = '—';
						if ( $last ) {
							$ts = is_numeric( $last ) ? (int) $last : strtotime( (string) $last );
							if ( $ts ) {
								$last_lbl = date_i18n( 'M j, g:i a', $ts );
							}
						}
						$mod = $order->get_date_modified();
						?>
						<tr data-order-id="<?php echo (int) $oid; ?>" data-consent="<?php echo $consented ? '1' : '0'; ?>">
							<th class="check-column">
								<input type="checkbox" name="order_ids[]" value="<?php echo (int) $oid; ?>" checked>
							</th>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo (int) $oid; ?></a></td>
							<td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
							<td><?php echo esc_html( $order->get_billing_phone() ); ?></td>
							<td><?php echo $mod ? esc_html( $mod->date_i18n( 'M j, Y g:i a' ) ) : '—'; ?></td>
							<td>
								<?php if ( $consented ) : ?>
									<span class="toc-badge toc-badge-ok"><?php echo esc_html__( 'Yes', 'twilio-order-communicator' ); ?></span>
								<?php else : ?>
									<span class="toc-badge toc-badge-no"><?php echo esc_html__( 'No', 'twilio-order-communicator' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $last_lbl ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:16px">
					<button type="button" class="button button-primary button-hero" id="toc-run-bulk"><?php echo esc_html__( 'Send to Selected', 'twilio-order-communicator' ); ?></button>
					<button type="button" class="button" id="toc-stop-bulk" style="display:none;margin-left:8px;"><?php echo esc_html__( 'Stop', 'twilio-order-communicator' ); ?></button>
					<span id="toc-bulk-status" style="margin-left:12px;"></span>
				</p>
				<div id="toc-bulk-log" class="toc-bulk-log" style="display:none;"></div>
			</form>
		<?php endif;
	}

	/* ---------- SETTINGS ---------- */
	private function render_settings() {
		// Masked token display — blank field keeps existing value on save.
		$has_token = (string) get_option( 'toc_auth_token', '' ) !== '';
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'toc_settings' ); ?>

			<h2><?php echo esc_html__( 'Twilio Credentials', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Use your own Twilio account. This plugin does not provide or resell SMS, voice, or calling services — Twilio bills you directly.', 'twilio-order-communicator' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php echo esc_html__( 'Account SID', 'twilio-order-communicator' ); ?></th>
					<td><input type="text" name="toc_account_sid" value="<?php echo esc_attr( get_option( 'toc_account_sid' ) ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th>Auth Token</th>
					<td>
						<input type="password" name="toc_auth_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_token ? esc_attr( '••••••••  (leave blank to keep)' ) : ''; ?>" />
						<?php if ( $has_token ) : ?>
							<p class="description">A token is already saved. Leave blank to keep it, or paste a new token to replace it.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>From Number</th>
					<td>
						<input type="text" name="toc_from_number" value="<?php echo esc_attr( get_option( 'toc_from_number' ) ); ?>" class="regular-text" placeholder="+1xxxxxxxxxx" />
						<p class="description">E.164 format required (e.g. +15055551234)</p>
					</td>
				</tr>
			</table>

			<h2>Voice Settings</h2>
			<table class="form-table">
				<tr>
					<th>Voice</th>
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
						<p class="description">Voice used by the built-in TwiML endpoint.</p>
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
						<p class="description"><?php echo esc_html__( 'Voice calls do not require SMS consent.', 'twilio-order-communicator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'SMS', 'twilio-order-communicator' ); ?></th>
					<td>
						<?php $this->checkbox( 'toc_auto_ready_sms', 0 ); ?>
						<label for="toc_auto_ready_sms"><?php echo esc_html__( 'Also send an SMS (consent required)', 'twilio-order-communicator' ); ?></label>
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
						<p class="description"><?php echo esc_html( sprintf( __( 'Uses the WordPress timezone (%s). Overnight windows like 21:00–08:00 are supported. Deferred with Action Scheduler when available. Applies to both Ready for Pickup and Shipped.', 'twilio-order-communicator' ), wp_timezone_string() ) ); ?></p>
					</td>
				</tr>
			</table>

			<h2>SMS Consent</h2>
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
					<th>Require consent for SMS</th>
					<td>
						<?php $this->checkbox( 'toc_require_sms_consent', 1 ); ?>
						<label for="toc_require_sms_consent">Only send SMS when the customer has opted in</label>
						<p class="description">Manual Send SMS warns and can force-send. Automatic and bulk SMS respect this setting. STOP keywords always block further SMS.</p>
					</td>
				</tr>
				<tr>
					<th>Consent meta key</th>
					<td>
						<input type="text" name="toc_sms_consent_meta" value="<?php echo esc_attr( get_option( 'toc_sms_consent_meta', '_toc_sms_consent' ) ); ?>" class="regular-text" />
						<p class="description">Order meta key used by the built-in checkbox and any custom snippet (values like yes / 1 / on / true). Default: <code>_toc_sms_consent</code>.</p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Message Templates', 'twilio-order-communicator' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Merge tags:', 'twilio-order-communicator' ); ?> <code>{order_number}</code> <code>{order_id}</code> <code>{customer_first_name}</code> <code>{customer_last_name}</code> <code>{customer_full_name}</code> <code>{store_name}</code> <code>{phone}</code> <code>{order_total}</code> <code>{billing_email}</code></p>
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
					<p class="description"><?php echo esc_html__( 'Used by Bulk Reminders for orders still in Ready for Pickup.', 'twilio-order-communicator' ); ?></p></td>
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

			<h2>Advanced</h2>
			<table class="form-table">
				<tr>
					<th>Webhook base URL</th>
					<td>
						<input type="url" name="toc_webhook_base_url" value="<?php echo esc_attr( get_option( 'toc_webhook_base_url', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
						<p class="description">Optional. Set if Twilio signature checks fail behind Cloudflare/proxy (must match the public HTTPS URL Twilio calls). Leave blank to use the WordPress home URL.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save Settings' ); ?>
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

	/* ---------- TOOLS & DOCS ---------- */
	private function render_tools() {
		$sms_hook = add_query_arg( 'toc_sms', '1', home_url( '/' ) );
		?>
		<h2>Connection Test</h2>
		<p>Verifies your Twilio Account SID + Auth Token against the Twilio API, and checks that the built-in TwiML endpoint responds.</p>
		<p>
			<button type="button" class="button button-primary" id="toc-test-btn">Run Connection Test</button>
			<span id="toc-test-result" style="margin-left:12px;"></span>
		</p>

		<hr>

		<h2>Built-in TwiML Endpoint</h2>
		<p>Voice calls use a short-lived tokenized TwiML URL generated per call (the spoken text is <strong>not</strong> put in the query string). No separate page or Code Snippet is required.</p>
		<p>Admins can still preview TwiML while logged in:</p>
		<p><code><?php echo esc_html( add_query_arg( array( 'toc_twiml' => '1', 'message' => rawurlencode( 'Hello, this is a test of the voice system.' ) ), home_url( '/' ) ) ); ?></code></p>
		<p class="description">Open that URL while logged into wp-admin as a shop manager. You should see XML with a <code>&lt;Say&gt;</code> element.</p>

		<hr>

		<h2>Incoming SMS Webhook</h2>
		<p>In Twilio Console → Phone Numbers → your number → Messaging → “A MESSAGE COMES IN”:</p>
		<ul>
			<li>Webhook</li>
			<li>URL: <code><?php echo esc_html( $sms_hook ); ?></code></li>
			<li>HTTP POST</li>
		</ul>
		<p class="description">Requests are validated with Twilio’s <code>X-Twilio-Signature</code> header. Unsigned requests are rejected (403). STOP / HELP / START keywords are handled automatically.</p>
		<p>SMS delivery status callbacks are attached automatically on each outbound message (<code><?php echo esc_html( TOC_Twilio::instance()->webhook_url( 'toc_msg_status' ) ); ?></code>) — no extra Twilio console setup needed.</p>

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
				<tr><td><?php echo esc_html__( 'Account SID / Auth Token / From Number', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Your Twilio credentials (bring your own account). From Number must be E.164 (+1…).', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Status mapping', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Which WooCommerce statuses trigger Ready for Pickup / Shipped logic.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Ready for Pickup / Shipped toggles', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Per-status enable, voice, and SMS controls.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Local Pickup filter', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Optional secondary check for Ready for Pickup auto-notify only.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Require SMS consent', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Block automatic/bulk SMS unless the customer opted in.', 'twilio-order-communicator' ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Consent meta key', 'twilio-order-communicator' ); ?></td><td><?php echo wp_kses_post( __( 'Order meta field that stores the opt-in (default <code>_toc_sms_consent</code>).', 'twilio-order-communicator' ) ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Message templates', 'twilio-order-communicator' ); ?></td><td><?php echo esc_html__( 'Default text for Ready for Pickup, Shipped, reminders, and Issue / Contact.', 'twilio-order-communicator' ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}

	/* ---------- AJAX ---------- */
	public function ajax_resolve() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$id       = absint( $_POST['id'] ?? 0 );
		$order_id = absint( $_POST['order_id'] ?? 0 );

		if ( $order_id ) {
			TOC_Logger::instance()->mark_order_resolved( $order_id );
			wp_send_json_success();
		} elseif ( $id ) {
			TOC_Logger::instance()->mark_resolved( $id );
			wp_send_json_success();
		}
		wp_send_json_error( 'Missing ID' );
	}

	public function ajax_bulk() {
		// Sequential bulk: one order per request (called repeatedly from admin JS).
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id && ! empty( $_POST['order_ids'] ) ) {
			$ids      = array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) );
			$order_id = $ids[0] ?? 0;
		}

		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$mode    = sanitize_key( wp_unslash( $_POST['mode'] ?? 'call' ) );
		if ( ! in_array( $mode, array( 'call', 'sms', 'both' ), true ) ) {
			$mode = 'call';
		}

		if ( ! $order_id || $message === '' ) {
			wp_send_json_error( 'Missing data' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found' );
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			wp_send_json_success(
				array(
					'order_id' => $order_id,
					'ok'       => false,
					'skipped'  => true,
					'detail'   => 'No phone number',
					'call'     => null,
					'sms'      => null,
				)
			);
		}

		$twilio    = TOC_Twilio::instance();
		$consented = $twilio->customer_consented_sms( $order_id );
		$message   = $twilio->merge_tags( $message, $order );
		$detail    = array();
		$call_ok   = null;
		$sms_ok    = null;
		$did_work  = false;

		// Voice — never gated on SMS consent.
		if ( in_array( $mode, array( 'call', 'both' ), true ) ) {
			$r        = $twilio->make_call( $phone, $message, $order_id );
			$call_ok  = ! empty( $r['success'] );
			$did_work = $did_work || $call_ok;
			if ( $call_ok ) {
				$detail[] = 'Call queued' . ( ! empty( $r['sid'] ) ? ' (' . $r['sid'] . ')' : '' );
			} else {
				$detail[] = 'Call failed: ' . ( $r['error'] ?? 'unknown' );
			}
		}

		// SMS — respects consent when require flag is on (force=false).
		if ( in_array( $mode, array( 'sms', 'both' ), true ) ) {
			if ( (int) get_option( 'toc_require_sms_consent', 1 ) === 1 && ! $consented ) {
				$sms_ok   = false;
				$detail[] = 'SMS skipped (no consent)';
			} else {
				$r        = $twilio->send_sms( $phone, $message, $order_id, false );
				$sms_ok   = ! empty( $r['success'] );
				$did_work = $did_work || $sms_ok;
				if ( $sms_ok ) {
					$detail[] = 'SMS queued';
				} else {
					$detail[] = 'SMS failed: ' . ( $r['error'] ?? 'unknown' );
				}
			}
		}

		if ( $mode === 'call' ) {
			$ok = ( true === $call_ok );
		} elseif ( $mode === 'sms' ) {
			$ok = ( true === $sms_ok );
		} else {
			// both: success if call went out even when SMS skipped for consent
			$ok = ( true === $call_ok ) || ( true === $sms_ok );
		}

		if ( $did_work ) {
			$order->update_meta_data( '_toc_last_reminder_at', time() );
			$order->save();
		}

		if ( isset( $_POST['delay'] ) ) {
			$delay = max( 1, min( 120, absint( $_POST['delay'] ) ) );
			update_option( 'toc_bulk_delay_seconds', $delay, false );
		}

		wp_send_json_success(
			array(
				'order_id' => $order_id,
				'ok'       => $ok,
				'skipped'  => false,
				'consent'  => $consented,
				'call'     => $call_ok,
				'sms'      => $sms_ok,
				'detail'   => implode( '; ', $detail ),
			)
		);
	}

	public function ajax_test() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$twilio = TOC_Twilio::instance();
		$api    = $twilio->test_credentials();
		if ( empty( $api['success'] ) ) {
			wp_send_json_error( $api['error'] ?? 'Credential check failed.' );
		}

		// TwiML token path (same path real calls use).
		$test_url = $twilio->build_twiml_url( 'Connection test successful.' );
		$response = wp_remote_get( $test_url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Twilio OK, but TwiML endpoint unreachable: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( (int) $code !== 200 || strpos( $body, '<Say' ) === false ) {
			wp_send_json_error( 'Twilio OK, but TwiML endpoint did not return valid XML. Response code: ' . $code );
		}

		$name = ! empty( $api['friendly_name'] ) ? ' (' . $api['friendly_name'] . ')' : '';
		wp_send_json_success( 'Twilio credentials verified' . $name . ' and the built-in TwiML endpoint is working.' );
	}
}
