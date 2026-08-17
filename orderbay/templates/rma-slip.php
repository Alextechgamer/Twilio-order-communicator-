<?php
/**
 * RMA / return slip.
 *
 * @var WC_Order[] $orders
 * @var array      $settings Doc settings.
 * @var array      $rma RMA settings.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$paper = ( isset( $settings['paper'] ) && 'a4' === $settings['paper'] ) ? 'A4' : 'letter';
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'RMA slip', 'orderbay' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $paper ); ?>; margin: 12mm; }
		body { font-family: system-ui, sans-serif; font-size: 12.5px; margin: 16px; color: #111; }
		.sheet { page-break-after: always; max-width: 190mm; margin: 0 auto 28px; }
		.sheet:last-child { page-break-after: auto; }
		h1 { font-size: 20px; margin: 0 0 8px; }
		.meta { color: #555; margin-bottom: 12px; }
		.box { border: 1px solid #ccc; padding: 10px; margin: 10px 0; white-space: pre-line; background: #fafafa; }
		table { width: 100%; border-collapse: collapse; margin-top: 10px; }
		th, td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; }
		th { border-bottom: 2px solid #222; }
		@media print { .no-print { display: none !important; } body { margin: 0; } }
	</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button></p>
<?php foreach ( $orders as $order ) : ?>
	<?php
	$rma_no = $order->get_meta( OB_Plugin::META_RMA_NUMBER );
	$reason = $order->get_meta( OB_Plugin::META_RMA_REASON );
	$status = $order->get_meta( OB_Plugin::META_RMA_STATUS );
	$items  = $order->get_items();
	?>
	<div class="sheet">
		<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
			<img src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" style="max-height:56px;" />
		<?php endif; ?>
		<h1><?php echo esc_html( $rma_no ? sprintf( __( 'RMA %s', 'orderbay' ), $rma_no ) : __( 'RMA slip', 'orderbay' ) ); ?></h1>
		<div class="meta">
			<?php echo esc_html( sprintf( __( 'Order #%s', 'orderbay' ), $order->get_order_number() ) ); ?>
			<?php if ( $status && 'none' !== $status ) : ?> · <?php echo esc_html( $status ); ?><?php endif; ?>
			· <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?>
		<?php if ( class_exists( 'OB_Barcode' ) && OB_Barcode::enabled() ) : ?>
			<?php OB_Barcode::render( $order->get_order_number() ); ?>
		<?php endif; ?>

		</div>
		<div class="box"><strong><?php esc_html_e( 'Ship return to', 'orderbay' ); ?></strong><br /><?php echo esc_html( $rma['return_address'] ?? '' ); ?></div>
		<?php if ( $reason ) : ?>
			<p><strong><?php esc_html_e( 'Reason', 'orderbay' ); ?>:</strong> <?php echo esc_html( $reason ); ?></p>
		<?php endif; ?>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'Return qty', 'orderbay' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $items ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No line items on this order.', 'orderbay' ); ?></td></tr>
			<?php else : ?>
				<?php
				$ob_rma_items = $order->get_meta( OB_Plugin::META_RMA_ITEMS );
				$ob_rma_items = is_array( $ob_rma_items ) ? $ob_rma_items : array();
				?>
				<?php foreach ( $items as $ob_item_id => $item ) : ?>
					<?php
					$product = $item->get_product();
					$sku     = $product ? $product->get_sku() : '';
					$ret_qty = isset( $ob_rma_items[ $ob_item_id ] ) ? (int) $ob_rma_items[ $ob_item_id ] : 0;
					?>
					<tr>
						<td><?php echo esc_html( $item->get_name() ); ?></td>
						<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
						<td><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
						<td><?php echo $ret_qty > 0 ? esc_html( (string) $ret_qty ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<p style="margin-top:16px;color:#555;"><?php esc_html_e( 'Include this slip with your return. Refunds are processed separately.', 'orderbay' ); ?></p>
	</div>
<?php endforeach; ?>
</body>
</html>
