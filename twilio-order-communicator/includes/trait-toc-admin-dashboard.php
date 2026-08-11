<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard tab UI + CSV export.
 */
trait TOC_Admin_Dashboard {

	/**
	 * Build filter args from the current request (GET), matching the Dashboard form.
	 *
	 * @return array
	 */
	private function dashboard_filter_args_from_request() {
		return array(
			'type'      => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'direction' => isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'resolved'  => isset( $_GET['resolved'] ) ? sanitize_text_field( wp_unslash( $_GET['resolved'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Escape a single CSV field (RFC 4180-ish).
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function csv_escape_field( $value ) {
		$value = (string) $value;
		// Normalize line endings inside cells.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		// Neutralize spreadsheet formula injection: a cell beginning with = + - @ (or tab)
		// can execute when opened in Excel/Sheets, and inbound SMS bodies are attacker-controlled.
		if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t" ), true ) ) {
			$value = "'" . $value;
		}
		if ( strpbrk( $value, ",\"\n" ) !== false ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * admin-post.php?action=toc_export_csv — stream filtered communications as CSV.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_die( esc_html__( 'Permission denied', 'twilio-order-communicator' ), 403 );
		}

		check_admin_referer( 'toc_export_csv' );

		$filters = $this->dashboard_filter_args_from_request();
		$logger  = TOC_Logger::instance();
		$total   = $logger->count_filtered( $filters );

		$filename = 'toc-communications-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream download, not filesystem write.
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Could not open output stream.', 'twilio-order-communicator' ) );
		}

		// UTF-8 BOM for Excel.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, "\xEF\xBB\xBF" );

		$headers = array( 'date', 'order_id', 'phone', 'type', 'direction', 'message', 'status', 'resolved' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, implode( ',', $headers ) . "\n" );

		// Paginated fetch so large logs do not OOM.
		$chunk  = 500;
		$offset = 0;
		$max    = 50000; // hard safety cap per export.
		$written = 0;

		while ( $offset < $total && $written < $max ) {
			$rows = $logger->get_filtered(
				array_merge(
					$filters,
					array(
						'limit'  => $chunk,
						'offset' => $offset,
					)
				)
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$line = array(
					$this->csv_escape_field( $row->created_at ?? '' ),
					$this->csv_escape_field( $row->order_id ?? '' ),
					$this->csv_escape_field( $row->phone ?? '' ),
					$this->csv_escape_field( $row->type ?? '' ),
					$this->csv_escape_field( $row->direction ?? '' ),
					$this->csv_escape_field( $row->body ?? '' ),
					$this->csv_escape_field( $row->status ?? '' ),
					$this->csv_escape_field( ! empty( $row->resolved ) ? '1' : '0' ),
				);
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $out, implode( ',', $line ) . "\n" );
				$written++;
				if ( $written >= $max ) {
					break 2;
				}
			}

			$offset += $chunk;
			// Free memory between chunks when possible.
			unset( $rows );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/* ---------- DASHBOARD ---------- */
	private function render_dashboard() {
		$logger   = TOC_Logger::instance();
		$stats    = $logger->get_stats();
		$per_page = 40;
		$page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array_merge(
			$this->dashboard_filter_args_from_request(),
			array(
				'limit'  => $per_page,
				'offset' => ( $page_num - 1 ) * $per_page,
			)
		);
		$results     = $logger->get_filtered( $filters );
		$total       = $logger->count_filtered( $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		$export_args = array_merge(
			array(
				'action'   => 'toc_export_csv',
				'_wpnonce' => wp_create_nonce( 'toc_export_csv' ),
			),
			array_filter(
				array(
					'type'      => $filters['type'],
					'direction' => $filters['direction'],
					'resolved'  => $filters['resolved'],
					's'         => $filters['search'],
					'date_from' => $filters['date_from'],
					'date_to'   => $filters['date_to'],
				),
				static function ( $v ) {
					return $v !== '' && $v !== null;
				}
			)
		);
		$export_url = add_query_arg( $export_args, admin_url( 'admin-post.php' ) );
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
			<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary"><?php echo esc_html__( 'Export CSV', 'twilio-order-communicator' ); ?></a>
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
