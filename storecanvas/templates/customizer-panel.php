<?php
/**
 * Front-end customizer panel (1.1.0).
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
	<p class="sc-toolbar">
		<input type="file" id="sc-upload" name="sc_artwork" accept="image/png,image/jpeg,image/svg+xml,image/webp" />
		<button type="button" class="button" id="sc-add-layer"><?php esc_html_e( 'Add layer', 'storecanvas' ); ?></button>
		<button type="button" class="button" id="sc-add-text"><?php esc_html_e( 'Add text', 'storecanvas' ); ?></button>
		<button type="button" class="button" id="sc-toggle-library"><?php esc_html_e( 'Library', 'storecanvas' ); ?></button>
		<button type="button" class="button" id="sc-rotate-left" title="Rotate -15°">⟲</button>
		<button type="button" class="button" id="sc-rotate-right" title="Rotate +15°">⟳</button>
		<button type="button" class="button" id="sc-reset"><?php esc_html_e( 'Reset', 'storecanvas' ); ?></button>
	</p>
	<div id="sc-library-panel" class="sc-library-panel" style="display:none;margin:8px 0;padding:8px;border:1px solid #ddd;background:#fafafa;">
		<p class="description" style="margin-top:0;"><?php esc_html_e( 'Click clip-art to add as a layer.', 'storecanvas' ); ?></p>
		<div id="sc-library-thumbs" class="sc-library-thumbs" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
	</div>
	<div id="sc-text-editor" class="sc-text-editor" style="display:none;margin:8px 0;padding:8px;border:1px solid #ddd;background:#fff;">
		<p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Text layer', 'storecanvas' ); ?></strong></p>
		<p>
			<label><?php esc_html_e( 'Content', 'storecanvas' ); ?>
				<input type="text" id="sc-text-content" style="width:100%;max-width:320px;" />
			</label>
		</p>
		<p>
			<label><?php esc_html_e( 'Size', 'storecanvas' ); ?>
				<input type="number" id="sc-text-size" min="8" max="200" step="1" value="28" style="width:70px;" />
			</label>
			<label style="margin-left:8px;"><?php esc_html_e( 'Color', 'storecanvas' ); ?>
				<input type="color" id="sc-text-fill" value="#111111" />
			</label>
		</p>
		<p>
			<label><?php esc_html_e( 'Font', 'storecanvas' ); ?>
				<select id="sc-text-font">
					<option value="Arial, Helvetica, sans-serif">Arial</option>
					<option value="Georgia, serif">Georgia</option>
					<option value="Times New Roman, Times, serif">Times New Roman</option>
					<option value="Courier New, Courier, monospace">Courier New</option>
					<option value="Verdana, Geneva, sans-serif">Verdana</option>
					<option value="Trebuchet MS, sans-serif">Trebuchet MS</option>
					<option value="Impact, Haettenschweiler, sans-serif">Impact</option>
					<option value="system-ui, -apple-system, sans-serif">System UI</option>
				</select>
			</label>
		</p>
		<p>
			<label><?php esc_html_e( 'Stroke', 'storecanvas' ); ?>
				<input type="color" id="sc-text-stroke-color" value="#000000" />
			</label>
			<label style="margin-left:8px;"><?php esc_html_e( 'Width', 'storecanvas' ); ?>
				<input type="number" id="sc-text-stroke-width" min="0" max="20" step="0.5" value="0" style="width:60px;" />
			</label>
			<span class="description"><?php esc_html_e( '0 = no stroke', 'storecanvas' ); ?></span>
		</p>
	</div>
	<div id="sc-layers-list" class="sc-layers-list"></div>
	<input type="hidden" name="sc_placement" id="sc_placement" value="" />
	<input type="hidden" name="sc_layers_json" id="sc_layers_json" value="" />
	<p class="description">
		<?php esc_html_e( 'Upload artwork, add text, or pick from the library. Drag inside the blue print area; green guide is the safe margin. Corner handles resize; blue handle rotates.', 'storecanvas' ); ?>
	</p>
</div>
