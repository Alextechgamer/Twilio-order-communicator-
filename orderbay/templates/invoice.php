<?php
/**
 * Invoice print sheet.
 *
 * @var WC_Order[] $orders
 * @var array      $settings
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'Invoice', 'orderbay' ); ?></title>
	<style>
		body { font-family: system-ui, sans-serif; font-size: 13px; color: #111; margin: 24px; }
		.sheet { page-break-after: always; max-width: 800px; margin: 0 auto 32px; }
		.sheet:last-child { page-break-after: auto; }
		h1 { font-size: 22px; margin: 0 0 8px; }
		.meta { color: #555; margin-bottom: 16px; }
		.from, .bill { white-space: pre-line; margin-bottom: 12px; }
		table { width: 100%; border-collapse: collapse; margin-top: 12px; }
		th, td { border-bottom: 1px solid #ddd; padding: 6px 4px; text-align: left; }
		th { border-bottom: 2px solid #333; }
		.totals { margin-top: 12px; text-align: right; }
		.footer { margin-top: 24px; color: #666; font-size: 12px; }
		.logo { max-height: 64px; margin-bottom: 12px; }
		@media print { .no-print { display: none; } body { margin: 0; } }
	</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button></p>
<?php foreach ( $orders as $order ) : ?>
	<div class="sheet">
		<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
			<img class="logo" src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" />
		<?php endif; ?>
		<h1><?php echo esc_html( sprintf( __( 'Invoice — Order #%s', 'orderbay' ), $order->get_order_number() ) ); ?></h1>
		<div class="meta">
			<?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ); ?>
			· <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
		</div>
		<div class="from"><strong><?php esc_html_e( 'From', 'orderbay' ); ?></strong><br /><?php echo esc_html( $settings['from_lines'] ); ?></div>
		<div class="bill"><strong><?php esc_html_e( 'Bill to', 'orderbay' ); ?></strong><br /><?php echo wp_kses_post( $order->get_formatted_billing_address() ? $order->get_formatted_billing_address() : $order->get_formatted_billing_full_name() ); ?></div>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'Total', 'orderbay' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item->get_name() ); ?></td>
					<td><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
					<td><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="totals">
			<div><?php esc_html_e( 'Subtotal', 'orderbay' ); ?>: <?php echo wp_kses_post( $order->get_subtotal_to_display() ); ?></div>
			<?php if ( $order->get_shipping_total() > 0 ) : ?>
				<div><?php esc_html_e( 'Shipping', 'orderbay' ); ?>: <?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?></div>
			<?php endif; ?>
			<div><strong><?php esc_html_e( 'Total', 'orderbay' ); ?>: <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div>
		</div>
		<?php if ( ! empty( $settings['footer_text'] ) ) : ?>
			<div class="footer"><?php echo esc_html( $settings['footer_text'] ); ?></div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</body>
</html>
