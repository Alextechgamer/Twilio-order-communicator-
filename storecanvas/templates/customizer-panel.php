<?php
/**
 * Front-end customizer panel (0.3.0).
 *
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
		<?php foreach ( $views as $i => $view ) : ?>
			<button type="button" class="button sc-view-btn<?php echo 0 === $i ? ' active' : ''; ?>" data-view-id="<?php echo esc_attr( $view['id'] ); ?>">
				<?php echo esc_html( $view['label'] ? $view['label'] : $view['id'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>
	<div class="sc-customizer-stage">
		<canvas id="sc-canvas" width="600" height="600" style="max-width:100%;border:1px solid #ddd;background:#fafafa;touch-action:none;"></canvas>
	</div>
	<p>
		<input type="file" id="sc-upload" name="sc_artwork" accept="image/png,image/jpeg,image/svg+xml" />
		<button type="button" class="button" id="sc-reset"><?php esc_html_e( 'Reset', 'storecanvas' ); ?></button>
	</p>
	<input type="hidden" name="sc_placement" id="sc_placement" value="" />
	<p class="description">
		<?php esc_html_e( 'Upload artwork, drag inside the blue print area, use corner handles or scroll wheel to resize. Switch views to place art on each side.', 'storecanvas' ); ?>
	</p>
</div>
