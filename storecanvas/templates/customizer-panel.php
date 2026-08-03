<?php
/**
 * Front-end customizer panel (0.5.0).
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
	<p class="sc-legend description">
		<span style="color:#0078ff;">■</span> <?php esc_html_e( 'Print area', 'storecanvas' ); ?>
		&nbsp; <span style="color:#00b450;">■</span> <?php esc_html_e( 'Safe margin', 'storecanvas' ); ?>
	</p>
	<p>
		<input type="file" id="sc-upload" name="sc_artwork" accept="image/png,image/jpeg,image/svg+xml,image/webp" />
		<button type="button" class="button" id="sc-add-layer"><?php esc_html_e( 'Add layer', 'storecanvas' ); ?></button>
		<button type="button" class="button" id="sc-rotate-left" title="Rotate -15°">⟲</button>
		<button type="button" class="button" id="sc-rotate-right" title="Rotate +15°">⟳</button>
		<button type="button" class="button" id="sc-reset"><?php esc_html_e( 'Reset', 'storecanvas' ); ?></button>
	</p>
	<div id="sc-layers-list" class="sc-layers-list"></div>
	<input type="hidden" name="sc_placement" id="sc_placement" value="" />
	<input type="hidden" name="sc_layers_json" id="sc_layers_json" value="" />
	<p class="description">
		<?php esc_html_e( 'Upload artwork, drag inside the blue area (stay in the green safe margin when possible). Corner handles resize; blue handle rotates. Add layer for multi-logo designs. Switch views for front/back.', 'storecanvas' ); ?>
	</p>
</div>
