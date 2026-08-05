<?php
/**
 * Warehouse pick list (grouped by SKU).
 *
 * @var array $lines Rows: sku, name, qty, orders (map).
 * @var array $order_numbers
 * @var array $settings
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$paper = ( isset( $settings['paper'] ) && 'a4' === $settings['paper'] ) ? 'A4' : 'letter';
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'Pick list', 'orderbay' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $paper ); ?>; margin: 12mm; }
		body { font-family: system-ui, sans-serif; font-size: 13px; margin: 16px; color: #111; }
		h1 { font-size: 20px; margin: 0 0 8px; }
		.meta { color: #555; margin-bottom: 14px; }
		table { width: 100%; border-collapse: collapse; }
		th, td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; vertical-align: top; }
		th { border-bottom: 2px solid #222; text-transform: uppercase; font-size: 11px; }
		.num { text-align: right; font-weight: 600; font-size: 15px; }
		.check { width: 28px; }
		@media print { .no-print { display: none !important; } body { margin: 0; } }
	</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button></p>
<h1><?php esc_html_e( 'Warehouse pick list', 'orderbay' ); ?></h1>
<div class="meta">
	<?php echo esc_html( sprintf( __( '%d order(s): %s', 'orderbay' ), count( $order_numbers ), implode( ', ', array_map( 'strval', $order_numbers ) ) ) ); ?>
	· <?php echo esc_html( gmdate( 'Y-m-d H:i' ) ); ?> UTC
</div>
<table>
	<thead>
		<tr>
			<th class="check">✓</th>
			<th><?php esc_html_e( 'Bin', 'orderbay' ); ?></th>
			<th><?php esc_html_e( 'SKU', 'orderbay' ); ?></th>
			<th><?php esc_html_e( 'Product', 'orderbay' ); ?></th>
			<th class="num"><?php esc_html_e( 'Qty', 'orderbay' ); ?></th>
			<th><?php esc_html_e( 'Orders', 'orderbay' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $lines ) ) : ?>
		<tr><td colspan="6"><?php esc_html_e( 'No line items in selection.', 'orderbay' ); ?></td></tr>
	<?php else : ?>
		<?php foreach ( $lines as $row ) : ?>
			<tr>
				<td class="check">☐</td>
				<td><?php echo esc_html( $row['bin'] ?? '' ); ?></td>
				<td><?php echo esc_html( $row['sku'] ); ?></td>
				<td><?php echo esc_html( $row['name'] ); ?></td>
				<td class="num"><?php echo esc_html( (string) $row['qty'] ); ?></td>
				<td><?php echo esc_html( implode( ', ', array_keys( $row['orders'] ) ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>
</body>
</html>
