<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk Reminders tab UI.
 */
trait TOC_Admin_Bulk {

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

}
