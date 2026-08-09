<?php
/**
 * Invoice print sheet (0.2.0).
 *
 * @var WC_Order[] $orders
 * @var array      $settings
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$paper = ( isset( $settings['paper'] ) && 'a4' === $settings['paper'] ) ? 'A4' : 'letter';
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'Invoice', 'orderbay' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $paper ); ?>; margin: 12mm; }
		* { box-sizing: border-box; }
		body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 12.5px; color: #111; margin: 16px; line-height: 1.4; }
		.sheet { page-break-after: always; max-width: 190mm; margin: 0 auto 28px; }
		.sheet:last-child { page-break-after: auto; }
		.header { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 16px; align-items: flex-start; }
		.logo { max-height: 56px; max-width: 200px; }
		h1 { font-size: 20px; margin: 0 0 4px; }
		.meta { color: #555; margin-bottom: 4px; }
		.cols { display: flex; gap: 24px; margin-bottom: 16px; }
		.cols > div { flex: 1; white-space: pre-line; }
		.cols strong { display: block; margin-bottom: 4px; }
		table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
		table.items th, table.items td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; vertical-align: top; }
		table.items th { border-bottom: 2px solid #222; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
		table.items .num { text-align: right; white-space: nowrap; }
		.totals { margin-top: 12px; margin-left: auto; width: 260px; }
		.totals .row { display: flex; justify-content: space-between; padding: 3px 0; }
		.totals .row.grand { font-weight: 700; font-size: 14px; border-top: 2px solid #222; margin-top: 6px; padding-top: 8px; }
		.notes { margin-top: 16px; padding: 8px 10px; background: #f6f7f7; border: 1px solid #e0e0e0; }
		.footer { margin-top: 20px; color: #666; font-size: 11px; border-top: 1px solid #eee; padding-top: 8px; }
		@media print {
			.no-print, #wpadminbar, .woocommerce-store-notice { display: none !important; }
			body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
			.sheet { page-break-after: always; break-after: page; }
			.sheet:last-child { page-break-after: auto; break-after: auto; }
		}
	</style>
</head>
<body>
<p class="no-print">
	<button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button>
	<span><?php echo esc_html( sprintf( __( '%d document(s) · paper: %s', 'orderbay' ), count( $orders ), $paper ) ); ?></span>
</p>
<?php foreach ( $orders as $order ) : ?>
	<div class="sheet">
		<div class="header">
			<div>
				<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
					<img class="logo" src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" />
				<?php endif; ?>
				<?php $inv_no = $order->get_meta( OB_Plugin::META_INVOICE_NUMBER ); ?>
				<h1><?php echo esc_html( $inv_no ? sprintf( __( 'Invoice %1$s — Order #%2$s', 'orderbay' ), $inv_no, $order->get_order_number() ) : sprintf( __( 'Invoice — Order #%s', 'orderbay' ), $order->get_order_number() ) ); ?></h1>
				<?php if ( $inv_no ) : ?><div class="meta"><strong><?php esc_html_e( 'Invoice number', 'orderbay' ); ?>:</strong> <?php echo esc_html( $inv_no ); ?></div><?php endif; ?>
		<?php if ( class_exists( 'OB_Barcode' ) && OB_Barcode::enabled() ) : ?>
			<?php OB_Barcode::render( $order->get_order_number() ); ?>
		<?php endif; ?>
		<?php if ( class_exists( 'OB_QR' ) ) { OB_QR::render_for_order( $order ); } ?>

				<div class="meta">
					<?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ); ?>
					· <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
					· <?php echo esc_html( $order->get_currency() ); ?>
				</div>
			</div>
			<div class="from" style="white-space:pre-line;text-align:right;"><strong><?php esc_html_e( 'From', 'orderbay' ); ?></strong><br /><?php echo esc_html( $settings['from_lines'] ); ?>
				<?php if ( ! empty( $settings['tax_id'] ) ) : ?><br /><strong><?php esc_html_e( 'VAT / Tax ID', 'orderbay' ); ?>:</strong> <?php echo esc_html( $settings['tax_id'] ); ?><?php endif; ?>
			</div>
		</div>
		<div class="cols">
			<div><strong><?php esc_html_e( 'Bill to', 'orderbay' ); ?></strong><?php echo wp_kses_post( $order->get_formatted_billing_address() ? $order->get_formatted_billing_address() : esc_html( $order->get_formatted_billing_full_name() ) ); ?>
				<?php if ( $order->get_billing_email() ) : ?><br /><?php echo esc_html( $order->get_billing_email() ); ?><?php endif; ?>
				<?php if ( $order->get_billing_phone() ) : ?><br /><?php echo esc_html( $order->get_billing_phone() ); ?><?php endif; ?>
			</div>
			<div><strong><?php esc_html_e( 'Ship to', 'orderbay' ); ?></strong><?php
				$ship = $order->get_formatted_shipping_address();
				echo wp_kses_post( $ship ? $ship : '—' );
			?></div>
		</div>
		<table class="items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'orderbay' ); ?></th>
					<th class="num"><?php esc_html_e( 'Qty', 'orderbay' ); ?></th>
					<th class="num"><?php esc_html_e( 'Total', 'orderbay' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<?php
				$product = $item->get_product();
				$sku     = $product ? $product->get_sku() : '';
				?>
				<tr>
					<td><?php echo esc_html( $item->get_name() ); ?></td>
					<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
					<td class="num"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
					<td class="num"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="totals">
			<div class="row"><span><?php esc_html_e( 'Subtotal', 'orderbay' ); ?></span><span><?php echo wp_kses_post( $order->get_subtotal_to_display() ); ?></span></div>
			<?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
				<div class="row"><span><?php esc_html_e( 'Shipping', 'orderbay' ); ?></span><span><?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( (float) $order->get_total_tax() > 0 ) : ?>
				<div class="row"><span><?php esc_html_e( 'Tax', 'orderbay' ); ?></span><span><?php echo wp_kses_post( wc_price( $order->get_total_tax(), array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( (float) $order->get_discount_total() > 0 ) : ?>
				<div class="row"><span><?php esc_html_e( 'Discount', 'orderbay' ); ?></span><span>−<?php echo wp_kses_post( wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
			<?php endif; ?>
			<div class="row grand"><span><?php esc_html_e( 'Total', 'orderbay' ); ?></span><span><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span></div>
		</div>
		<?php if ( $order->get_customer_note() ) : ?>
			<div class="notes"><strong><?php esc_html_e( 'Customer note', 'orderbay' ); ?>:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></div>
		<?php endif; ?>
		<?php
		$track = $order->get_meta( OB_Plugin::META_TRACKING );
		$turl  = class_exists( 'OB_Fulfillment' ) ? OB_Fulfillment::build_tracking_url( $order ) : '';
		if ( $track ) :
			?>
			<div class="notes"><strong><?php esc_html_e( 'Tracking', 'orderbay' ); ?>:</strong> <?php echo esc_html( $track ); ?>
			<?php if ( $turl ) : ?> — <a href="<?php echo esc_url( $turl ); ?>"><?php esc_html_e( 'Track', 'orderbay' ); ?></a><?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $settings['footer_text'] ) || ! empty( $settings['tax_id'] ) ) : ?>
			<div class="footer">
				<?php if ( ! empty( $settings['footer_text'] ) ) : ?><?php echo esc_html( $settings['footer_text'] ); ?><?php endif; ?>
				<?php if ( ! empty( $settings['tax_id'] ) ) : ?><?php echo $settings['footer_text'] ? '<br />' : ''; ?><strong><?php esc_html_e( 'VAT / Tax ID', 'orderbay' ); ?>:</strong> <?php echo esc_html( $settings['tax_id'] ); ?><?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</body>
</html>
