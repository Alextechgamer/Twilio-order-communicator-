<?php
/**
 * Credit note print sheet.
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
	<title><?php echo esc_html__( 'Credit note', 'orderbay' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $paper ); ?>; margin: 12mm; }
		body { font-family: system-ui, sans-serif; font-size: 12.5px; margin: 16px; color: #111; }
		.sheet { page-break-after: always; max-width: 190mm; margin: 0 auto 28px; }
		.sheet:last-child { page-break-after: auto; }
		h1 { font-size: 20px; margin: 0 0 8px; }
		.meta { color: #555; margin-bottom: 12px; }
		table { width: 100%; border-collapse: collapse; margin-top: 10px; }
		th, td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; }
		th { border-bottom: 2px solid #222; }
		.num { text-align: right; }
		.totals { margin-top: 12px; text-align: right; font-weight: 700; }
		.footer { margin-top: 20px; color: #666; font-size: 11px; }
		@media print { .no-print { display: none !important; } body { margin: 0; } }
	</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay' ); ?></button></p>
<?php foreach ( $orders as $order ) : ?>
	<?php
	$cn     = $order->get_meta( OB_Plugin::META_CREDIT_NUMBER );
	$refunds = $order->get_refunds();
	$total_refunded = (float) $order->get_total_refunded();
	?>
	<div class="sheet">
		<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
			<img src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" style="max-height:56px;" />
		<?php endif; ?>
		<h1><?php echo esc_html( $cn ? sprintf( __( 'Credit note %s', 'orderbay' ), $cn ) : __( 'Credit note', 'orderbay' ) ); ?></h1>
		<div class="meta">
			<?php echo esc_html( sprintf( __( 'Order #%s', 'orderbay' ), $order->get_order_number() ) ); ?>
			· <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?>
		</div>
		<div style="white-space:pre-line;margin-bottom:12px;"><strong><?php esc_html_e( 'From', 'orderbay' ); ?></strong><br /><?php echo esc_html( $settings['from_lines'] ); ?></div>
		<div style="margin-bottom:12px;"><strong><?php esc_html_e( 'Bill to', 'orderbay' ); ?></strong><br /><?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?></div>

		<?php if ( $refunds ) : ?>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Refund / item', 'orderbay' ); ?></th>
						<th class="num"><?php esc_html_e( 'Amount (excl. tax)', 'orderbay' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $refunds as $refund ) : ?>
					<?php
					if ( ! $refund instanceof WC_Order_Refund ) {
						continue;
					}
					$items = $refund->get_items();
					if ( $items ) {
						foreach ( $items as $item ) {
							?>
							<tr>
								<td><?php echo esc_html( $item->get_name() ); ?> × <?php echo esc_html( (string) abs( $item->get_quantity() ) ); ?></td>
								<td class="num"><?php echo wp_kses_post( wc_price( abs( (float) $item->get_total() ), array( 'currency' => $order->get_currency() ) ) ); ?></td>
							</tr>
							<?php
						}
					} else {
						?>
						<tr>
							<td><?php echo esc_html( $refund->get_reason() ? $refund->get_reason() : __( 'Refund', 'orderbay' ) ); ?></td>
							<td class="num"><?php echo wp_kses_post( wc_price( abs( (float) $refund->get_amount() ), array( 'currency' => $order->get_currency() ) ) ); ?></td>
						</tr>
						<?php
					}
					?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No WooCommerce refunds recorded on this order. Credit note number issued for staff records.', 'orderbay' ); ?></p>
		<?php endif; ?>

		<div class="totals">
			<?php
			// Itemize refunded tax and shipping so the net line amounts above reconcile
			// with the gross total credited (required for a compliant VAT credit note).
			$ob_tax_refunded      = abs( (float) $order->get_total_tax_refunded() );
			$ob_shipping_refunded = abs( (float) $order->get_total_shipping_refunded() );
			if ( $ob_shipping_refunded > 0 ) {
				echo '<div>' . esc_html__( 'Shipping refunded', 'orderbay' ) . ': ' . wp_kses_post( wc_price( $ob_shipping_refunded, array( 'currency' => $order->get_currency() ) ) ) . '</div>';
			}
			if ( $ob_tax_refunded > 0 ) {
				echo '<div>' . esc_html__( 'Tax refunded', 'orderbay' ) . ': ' . wp_kses_post( wc_price( $ob_tax_refunded, array( 'currency' => $order->get_currency() ) ) ) . '</div>';
			}
			?>
			<div><strong><?php esc_html_e( 'Total credited', 'orderbay' ); ?>:
			<?php echo wp_kses_post( wc_price( $total_refunded, array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
		</div>
		<?php if ( ! empty( $settings['footer_text'] ) ) : ?>
			<div class="footer"><?php echo esc_html( $settings['footer_text'] ); ?></div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</body>
</html>
