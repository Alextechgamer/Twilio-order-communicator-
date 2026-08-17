<?php
/**
 * Packing slip print sheet (0.2.0).
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
	<title><?php echo esc_html__( 'Packing slip', 'orderbay' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $paper ); ?>; margin: 12mm; }
		* { box-sizing: border-box; }
		body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 12.5px; color: #111; margin: 16px; line-height: 1.4; }
		.sheet { page-break-after: always; max-width: 190mm; margin: 0 auto 28px; }
		.sheet:last-child { page-break-after: auto; }
		.header { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
		.logo { max-height: 56px; max-width: 200px; }
		h1 { font-size: 20px; margin: 0 0 4px; }
		.meta { color: #555; }
		.ship { white-space: pre-line; margin-bottom: 14px; padding: 10px; border: 1px solid #ddd; background: #fafafa; }
		table.items { width: 100%; border-collapse: collapse; }
		table.items th, table.items td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; }
		table.items th { border-bottom: 2px solid #222; font-size: 11px; text-transform: uppercase; }
		table.items .num { text-align: right; }
		.notes { margin-top: 14px; padding: 8px 10px; background: #fff8e5; border: 1px solid #f0e0a0; }
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
	<span><?php echo esc_html( sprintf( __( '%d packing slip(s)', 'orderbay' ), count( $orders ) ) ); ?></span>
</p>
<?php foreach ( $orders as $order ) : ?>
	<div class="sheet">
		<div class="header">
			<div>
				<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
					<img class="logo" src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" />
				<?php endif; ?>
				<h1><?php echo esc_html( sprintf( __( 'Packing slip — Order #%s', 'orderbay' ), $order->get_order_number() ) ); ?></h1>
				<div class="meta"><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?></div>
		<?php if ( class_exists( 'OB_Barcode' ) && OB_Barcode::enabled() ) : ?>
			<?php OB_Barcode::render( $order->get_order_number() ); ?>
		<?php endif; ?>

			</div>
			<div style="white-space:pre-line;text-align:right;"><?php echo esc_html( $settings['from_lines'] ); ?></div>
		</div>
		<div class="ship"><strong><?php esc_html_e( 'Ship to', 'orderbay' ); ?></strong><br />
			<?php
			$ship = $order->get_formatted_shipping_address();
			echo wp_kses_post( $ship ? $ship : $order->get_formatted_billing_address() );
			if ( $order->get_billing_phone() ) {
				echo '<br />' . esc_html( $order->get_billing_phone() );
			}
			?>
		</div>
		<?php
		$show_thumbs = ! empty( $settings['show_thumbs'] ) && '1' === (string) $settings['show_thumbs'];
		$show_partial = false;
		if ( class_exists( 'OB_Partial' ) ) {
			foreach ( $order->get_items() as $_it ) {
				if ( $_it instanceof WC_Order_Item_Product && (int) $_it->get_meta( OB_Plugin::META_QTY_FULFILLED, true ) > 0 ) {
					$show_partial = true;
					break;
				}
			}
		}
		?>
		<table class="items">
			<thead>
				<tr>
					<?php if ( $show_thumbs ) : ?><th></th><?php endif; ?>
					<th><?php esc_html_e( 'Item', 'orderbay' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'orderbay' ); ?></th>
					<th class="num"><?php esc_html_e( 'Qty', 'orderbay' ); ?></th>
					<?php if ( $show_partial ) : ?><th class="num"><?php esc_html_e( 'Done', 'orderbay' ); ?></th><th class="num"><?php esc_html_e( 'Left', 'orderbay' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<?php
				$product = $item->get_product();
				$sku     = $product ? $product->get_sku() : '';
				$thumb   = '';
				if ( $show_thumbs && $product ) {
					$img_id = $product->get_image_id();
					if ( $img_id ) {
						$src = wp_get_attachment_image_url( $img_id, 'thumbnail' );
						if ( $src ) {
							$thumb = $src;
						}
					}
				}
				?>
				<tr>
					<?php if ( $show_thumbs ) : ?>
						<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:40px;height:40px;object-fit:cover;" /><?php else : ?>—<?php endif; ?></td>
					<?php endif; ?>
					<td><?php echo esc_html( $item->get_name() ); ?></td>
					<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
					<td class="num"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
					<?php if ( $show_partial ) :
						$done = class_exists( 'OB_Partial' ) ? OB_Partial::get_fulfilled( $item ) : 0;
						$left = max( 0, (int) $item->get_quantity() - $done );
						?>
						<td class="num"><?php echo esc_html( (string) $done ); ?></td>
						<td class="num"><?php echo esc_html( (string) $left ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( class_exists( 'OB_QR' ) && OB_QR::available() ) { OB_QR::render_for_order( $order ); }
		$track = $order->get_meta( OB_Plugin::META_TRACKING );
		$turl  = class_exists( 'OB_Fulfillment' ) ? OB_Fulfillment::build_tracking_url( $order ) : (string) $order->get_meta( OB_Plugin::META_TRACKING_URL );
		$clab  = class_exists( 'OB_Fulfillment' ) ? OB_Fulfillment::carrier_label( $order ) : '';
		if ( $track ) :
			?>
			<div class="notes"><strong><?php esc_html_e( 'Tracking', 'orderbay' ); ?>:</strong>
			<?php if ( $clab ) : ?><?php echo esc_html( $clab ); ?> · <?php endif; ?>
			<?php echo esc_html( $track ); ?>
			<?php if ( $turl ) : ?> — <a href="<?php echo esc_url( $turl ); ?>"><?php esc_html_e( 'Track', 'orderbay' ); ?></a><?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
		$gift = $order->get_meta( 'gift_message' );
		if ( ! $gift ) {
			$gift = $order->get_meta( '_gift_message' );
		}
		if ( ! $gift ) {
			$gift = $order->get_meta( 'Gift Message' );
		}
		if ( $gift ) :
			?>
			<div class="notes"><strong><?php esc_html_e( 'Gift message', 'orderbay' ); ?>:</strong> <?php echo esc_html( is_string( $gift ) ? $gift : '' ); ?></div>
		<?php endif; ?>
		<?php if ( $order->get_customer_note() ) : ?>
			<div class="notes"><strong><?php esc_html_e( 'Customer note', 'orderbay' ); ?>:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $settings['footer_text'] ) ) : ?>
			<div class="footer"><?php echo esc_html( $settings['footer_text'] ); ?></div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</body>
</html>
