<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard tab UI.
 */
trait TOC_Admin_Dashboard {

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

}
