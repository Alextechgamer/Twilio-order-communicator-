<?php
/**
 * Customer-facing delivery note (ship summary). Distinct from packing slip.
 *
 * @var WC_Order[] $orders
 * @var array      $settings
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( empty( $orders ) || ! is_array( $orders ) ) {
	$orders = array();
}
if ( empty( $settings ) || ! is_array( $settings ) ) {
	$settings = class_exists( 'OB_Plugin' ) ? OB_Plugin::get_doc_settings() : array();
}
$show_prices = ! empty( $settings['delivery_prices'] ) && '1' === (string) $settings['delivery_prices'];
$paper = ( isset( $settings['paper'] ) && 'a4' === $settings['paper'] ) ? 'A4' : 'letter';
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'Delivery note', 'orderbay' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $paper ); ?>; margin: 12mm; }
		body { font-family: system-ui, sans-serif; font-size: 12.5px; color: #111; margin: 16px; }
		.sheet { page-break-after: always; max-width: 190mm; margin: 0 auto 28px; }
		.sheet:last-child { page-break-after: auto; }
		h1 { font-size: 20px; margin: 0 0 8px; }
		.meta { color: #555; margin-bottom: 12px; }
		.cols { display: flex; gap: 24px; margin-bottom: 16px; }
		.cols > div { flex: 1; white-space: pre-line; }
		table.items { width: 100%; border-collapse: collapse; }
		table.items th, table.items td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; }
		table.items th { border-bottom: 2px solid #222; font-size: 11px; text-transform: uppercase; }
		table.items .num { text-align: right; }
		.notes { margin-top: 16px; padding: 8px 10px; background: #f6f7f7; border: 1px solid #e0e0e0; }
		@media print { .no-print { display: none !important; } .sheet { page-break-after: always; } .sheet:last-child { page-break-after: auto; } }
	</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button>
<span><?php echo esc_html( sprintf( __( '%d delivery note(s)', 'orderbay' ), count( $orders ) ) ); ?></span></p>
<?php if ( ! $orders ) : ?><p><?php esc_html_e( 'No orders to print.', 'orderbay' ); ?></p><?php endif; ?>
<?php foreach ( $orders as $order ) : ?>
	<?php if ( ! $order instanceof WC_Order ) { continue; } ?>
	<div class="sheet">
		<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
			<img src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" style="max-height:48px;margin-bottom:8px;" />
		<?php endif; ?>
		<h1><?php echo esc_html( sprintf( __( 'Delivery note — Order #%s', 'orderbay' ), $order->get_order_number() ) ); ?></h1>
		<div class="meta"><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?></div>
		<div class="cols">
			<div><strong><?php esc_html_e( 'From', 'orderbay' ); ?></strong><?php echo esc_html( $settings['from_lines'] ?? '' ); ?></div>
			<div><strong><?php esc_html_e( 'Ship to', 'orderbay' ); ?></strong><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ?: '—' ); ?>
				<?php if ( $order->get_billing_phone() ) : ?><br /><?php echo esc_html( $order->get_billing_phone() ); ?><?php endif; ?>
			</div>
		</div>
		<table class="items">
			<thead><tr>
				<th><?php esc_html_e( 'Item', 'orderbay' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'orderbay' ); ?></th>
				<th class="num"><?php esc_html_e( 'Qty', 'orderbay' ); ?></th>
				<?php if ( $show_prices ) : ?><th class="num"><?php esc_html_e( 'Total', 'orderbay' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<?php $product = $item->get_product(); $sku = $product ? $product->get_sku() : ''; ?>
				<tr>
					<td><?php echo esc_html( $item->get_name() ); ?></td>
					<td><?php echo esc_html( $sku ?: '—' ); ?></td>
					<td class="num"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
					<?php if ( $show_prices ) : ?><td class="num"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td><?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$track = $order->get_meta( OB_Plugin::META_TRACKING );
		$turl  = class_exists( 'OB_Fulfillment' ) ? OB_Fulfillment::build_tracking_url( $order ) : '';
		if ( $track ) :
			?>
			<div class="notes"><strong><?php esc_html_e( 'Tracking', 'orderbay' ); ?>:</strong> <?php echo esc_html( $track ); ?>
			<?php if ( $turl ) : ?> — <a href="<?php echo esc_url( $turl ); ?>"><?php esc_html_e( 'Track', 'orderbay' ); ?></a><?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $order->get_customer_note() ) : ?>
			<div class="notes"><strong><?php esc_html_e( 'Customer note', 'orderbay' ); ?>:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</body>
</html>
