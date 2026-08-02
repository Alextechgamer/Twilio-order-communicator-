<?php
/**
 * Front-end customizer shell.
 *
 * Variables from SC_Customizer::render_panel():
 * @var WC_Product $product
 * @var array      $views
 * @var array      $areas
 * @var array      $validation
 * @var array      $config
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sc-customizer"
	data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
	data-views="<?php echo esc_attr( wp_json_encode( $views ) ); ?>"
	data-areas="<?php echo esc_attr( wp_json_encode( $areas ) ); ?>"
	data-validation="<?php echo esc_attr( wp_json_encode( $validation ) ); ?>">
	<p class="sc-customizer-title"><strong><?php esc_html_e( 'Customize placement', 'storecanvas' ); ?></strong></p>
	<div class="sc-customizer-views">
		<?php foreach ( $views as $view ) : ?>
			<button type="button" class="button sc-view-btn" data-view-id="<?php echo esc_attr( $view['id'] ); ?>">
				<?php echo esc_html( $view['label'] ? $view['label'] : $view['id'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>
	<div class="sc-customizer-stage">
		<canvas id="sc-canvas" width="600" height="600" style="max-width:100%;border:1px solid #ddd;background:#fafafa;"></canvas>
	</div>
	<p>
		<input type="file" id="sc-upload" accept="image/png,image/jpeg,image/svg+xml" />
		<button type="button" class="button" id="sc-reset"><?php esc_html_e( 'Reset', 'storecanvas' ); ?></button>
	</p>
	<input type="hidden" name="sc_placement" id="sc_placement" value="" />
	<p class="description"><?php esc_html_e( 'Upload artwork, then drag within the print area. Placement is saved with the cart line.', 'storecanvas' ); ?></p>
</div>
