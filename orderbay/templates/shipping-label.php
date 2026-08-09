<?php
/**
 * Compact shipping / address label (layout only — no postage API).
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
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'Shipping labels', 'orderbay' ); ?></title>
	<style>
		@page { size: letter; margin: 8mm; }
		* { box-sizing: border-box; }
		body { font-family: system-ui, sans-serif; font-size: 13px; color: #111; margin: 8px; }
		.grid { display: flex; flex-wrap: wrap; gap: 8mm; }
		.label {
			width: 4in; height: 6in; max-width: 100%;
			border: 1px solid #222; padding: 10mm 8mm;
			page-break-inside: avoid; display: flex; flex-direction: column; justify-content: space-between;
		}
		.from { font-size: 11px; color: #444; white-space: pre-line; border-bottom: 1px dashed #aaa; padding-bottom: 8px; margin-bottom: 10px; }
		.to strong { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
		.to-addr { font-size: 16px; line-height: 1.35; white-space: pre-line; font-weight: 600; }
		.meta { font-size: 11px; color: #555; margin-top: 8px; }
		@media print {
			.no-print { display: none !important; }
			.label { page-break-after: always; border-color: #000; }
			.grid { gap: 0; }
		}
	</style>
</head>
<body>
<p class="no-print">
	<button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button>
	<span><?php echo esc_html( sprintf( __( '%d label(s) · ~4×6 layout', 'orderbay' ), count( $orders ) ) ); ?></span>
</p>
<?php if ( ! $orders ) : ?><p><?php esc_html_e( 'No orders to print.', 'orderbay' ); ?></p><?php endif; ?>
<div class="grid">
<?php foreach ( $orders as $order ) : ?>
	<?php if ( ! $order instanceof WC_Order ) { continue; } ?>
	<div class="label">
		<div>
			<div class="from"><strong><?php esc_html_e( 'From', 'orderbay' ); ?></strong><br /><?php echo esc_html( $settings['from_lines'] ?? get_bloginfo( 'name' ) ); ?></div>
			<div class="to">
				<strong><?php esc_html_e( 'Ship to', 'orderbay' ); ?></strong>
				<div class="to-addr"><?php
					$ship = $order->get_formatted_shipping_address();
					echo wp_kses_post( $ship ? $ship : ( $order->get_formatted_billing_address() ?: '—' ) );
				?></div>
				<?php if (  ( method_exists( $order, 'get_shipping_phone' ) && $order->get_shipping_phone() ) || $order->get_billing_phone()  ) : ?>
					<div class="meta"><?php echo esc_html( ( method_exists( $order, 'get_shipping_phone' ) && $order->get_shipping_phone() ) ? $order->get_shipping_phone() : $order->get_billing_phone() ); ?></div>
				<?php endif; ?>
			</div>
		</div>
		<div>
			<div class="meta"><strong><?php esc_html_e( 'Order', 'orderbay' ); ?> #<?php echo esc_html( $order->get_order_number() ); ?></strong></div>
			<?php
			if ( class_exists( 'OB_Barcode' ) && OB_Barcode::enabled() ) {
				OB_Barcode::render( $order->get_order_number() );
			}
			?>
		</div>
	</div>
<?php endforeach; ?>
</div>
</body>
</html>
