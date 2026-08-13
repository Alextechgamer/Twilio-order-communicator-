<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product edit: visual Options + Customizer builders (0.2.0).
 */
class SC_Admin_Product {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 10, 2 );
	}

	public function add_tab( $tabs ) {
		$tabs['storecanvas'] = array(
			'label'    => __( 'StoreCanvas', 'storecanvas' ),
			'target'   => 'sc_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public function panel() {
		global $post;
		$product_id = $post ? (int) $post->ID : 0;
		$options    = SC_Product_Options::get_config( $product_id );
		$customizer = SC_Customizer::get_config( $product_id );
		$validation = SC_Customizer::get_validation( $product_id );
		$field_types = SC_Product_Options::field_types();
		$price_types = SC_Product_Options::price_types();
		$clipart_all = class_exists( 'SC_Clipart' ) ? SC_Clipart::all_published() : array();
		$clipart_sel = get_post_meta( $product_id, SC_Plugin::META_CLIPART, true );
		if ( ! is_array( $clipart_sel ) ) {
			$clipart_sel = array();
		}
		$view_count  = count( (array) ( $customizer['views'] ?? array() ) );
		$area_count  = count( (array) ( $customizer['areas'] ?? array() ) );
		$field_count = count( (array) ( $options['fields'] ?? array() ) );
		$enabled     = ! empty( $customizer['enabled'] );
		$val_on      = isset( $validation['min_dpi'] );

		$view_urls = array();
		foreach ( (array) ( $customizer['views'] ?? array() ) as $v ) {
			$iid = absint( $v['image_id'] ?? 0 );
			if ( $iid ) {
				$url = wp_get_attachment_image_url( $iid, 'full' );
				if ( $url ) {
					$view_urls[ (string) $iid ] = $url;
				}
			}
		}

		?>
		<div id="sc_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<strong><?php esc_html_e( 'StoreCanvas', 'storecanvas' ); ?></strong>
					<span class="description"> — <?php esc_html_e( 'Product options and live mockup. Version 1.1.0.', 'storecanvas' ); ?></span>
				</p>
				<p class="form-field sc-status-line" style="padding-left:12px;">
					<code><?php
					echo esc_html(
						sprintf(
							/* translators: 1: field count 2: view count 3: area count 4: on/off */
							__( 'Status: Options: %1$d fields · Views: %2$d · Areas: %3$d · Validation: %4$s', 'storecanvas' ),
							(int) $field_count,
							(int) $view_count,
							(int) $area_count,
							$val_on ? __( 'on', 'storecanvas' ) : __( 'off', 'storecanvas' )
						)
					);
					?></code>
				</p>
				<?php if ( $enabled && ( $view_count < 1 || $area_count < 1 ) ) : ?>
					<div class="notice notice-warning inline sc-empty-setup" style="margin:8px 12px;">
						<p>
							<strong><?php esc_html_e( 'Almost ready', 'storecanvas' ); ?></strong> —
							<?php if ( $view_count < 1 ) : ?>
								<?php esc_html_e( 'Add at least one view with a base image.', 'storecanvas' ); ?>
							<?php endif; ?>
							<?php if ( $area_count < 1 ) : ?>
								<?php esc_html_e( 'Add a print area so customers can place artwork.', 'storecanvas' ); ?>
							<?php endif; ?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<div class="options_group sc-admin-section">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Customizer (live mockup)', 'storecanvas' ); ?></h4>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => 'sc_customizer_enabled',
						'label'       => __( 'Enable live mockup', 'storecanvas' ),
						'description' => __( 'Show canvas placement UI on the product page.', 'storecanvas' ),
						'value'       => ! empty( $customizer['enabled'] ) ? 'yes' : 'no',
					)
				);
				?>
				<div id="sc-views-builder" class="sc-builder" style="padding:0 12px 12px;">
					<p><strong><?php esc_html_e( 'Views', 'storecanvas' ); ?></strong>
						<button type="button" class="button" id="sc-add-view"><?php esc_html_e( 'Add view', 'storecanvas' ); ?></button>
					</p>
					<div id="sc-views-list"></div>
				</div>
				<div id="sc-areas-builder" class="sc-builder" style="padding:0 12px 12px;">
					<p><strong><?php esc_html_e( 'Print areas', 'storecanvas' ); ?></strong>
						<button type="button" class="button" id="sc-add-area"><?php esc_html_e( 'Add area', 'storecanvas' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'x, y, w, h are percentages (0–100) of the view image. Use the visual editor below or edit numbers.', 'storecanvas' ); ?></p>
					<div id="sc-areas-list"></div>
				</div>
				<div id="sc-area-visual-editor" class="sc-builder" style="padding:0 12px 16px;">
					<p><strong><?php esc_html_e( 'Visual print-area editor', 'storecanvas' ); ?></strong></p>
					<p class="description"><?php esc_html_e( 'Select a view with a base image, then drag the blue rectangle or its corners. Changes sync to the area fields.', 'storecanvas' ); ?></p>
					<p>
						<label><?php esc_html_e( 'View', 'storecanvas' ); ?>
							<select id="sc-visual-view"></select>
						</label>
						<label style="margin-left:8px;"><?php esc_html_e( 'Area', 'storecanvas' ); ?>
							<select id="sc-visual-area"></select>
						</label>
					</p>
					<div class="sc-visual-stage" style="max-width:520px;border:1px solid #c3c4c7;background:#f0f0f1;position:relative;">
						<canvas id="sc-area-canvas" width="500" height="500" style="display:block;max-width:100%;cursor:crosshair;"></canvas>
					</div>
				</div>
				<input type="hidden" name="sc_customizer_views_json" id="sc_customizer_views_json" value="<?php echo esc_attr( wp_json_encode( $customizer['views'] ) ); ?>" />
				<input type="hidden" name="sc_customizer_areas_json" id="sc_customizer_areas_json" value="<?php echo esc_attr( wp_json_encode( $customizer['areas'] ) ); ?>" />
			</div>

			<div class="options_group">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Print validation', 'storecanvas' ); ?></h4>
				<p class="description" style="padding-left:12px;max-width:42em;"><?php esc_html_e( 'Print files are RGB (and PDFs are flattened RGB, not CMYK or PDF-X). DPI is estimated from pixel size vs target print width. Confirm color and resolution with your print provider before production.', 'storecanvas' ); ?></p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_min_dpi',
						'label'             => __( 'Min DPI', 'storecanvas' ),
						'type'              => 'number',
						'value'             => (int) ( $validation['min_dpi'] ?? 150 ),
						'custom_attributes' => array( 'min' => '72', 'step' => '1' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_max_mb',
						'label'             => __( 'Max upload (MB)', 'storecanvas' ),
						'type'              => 'number',
						'value'             => (float) ( $validation['max_upload_mb'] ?? 10 ),
						'custom_attributes' => array( 'min' => '1', 'step' => '0.5' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_min_px',
						'label'             => __( 'Min source px', 'storecanvas' ),
						'type'              => 'number',
						'value'             => (int) ( $validation['min_source_px'] ?? 500 ),
						'custom_attributes' => array( 'min' => '100', 'step' => '1' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_print_width',
						'label'             => __( 'Target print width (inches)', 'storecanvas' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Used to estimate DPI of uploaded artwork.', 'storecanvas' ),
						'value'             => (float) ( $validation['target_print_width_in'] ?? 12 ),
						'custom_attributes' => array( 'min' => '1', 'step' => '0.5' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_safe_margin',
						'label'             => __( 'Safe margin %', 'storecanvas' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Green guide inset inside the print area on the canvas.', 'storecanvas' ),
						'value'             => (float) ( $validation['safe_margin_pct'] ?? 5 ),
						'custom_attributes' => array( 'min' => '0', 'max' => '40', 'step' => '0.5' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_bleed',
						'label'             => __( 'Bleed inset %', 'storecanvas' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Artwork must stay inside this inset of the print area (stricter than safe margin when higher).', 'storecanvas' ),
						'value'             => (float) ( $validation['bleed_pct'] ?? 3 ),
						'custom_attributes' => array( 'min' => '0', 'max' => '40', 'step' => '0.5' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'sc_val_min_bleed_px',
						'label'             => __( 'Min bleed (px, optional)', 'storecanvas' ),
						'type'              => 'number',
						'value'             => (int) ( $validation['min_bleed_px'] ?? 0 ),
						'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => 'sc_val_require_rgb',
						'label'       => __( 'Require RGB', 'storecanvas' ),
						'description' => __( 'Block CMYK/grayscale uploads when color mode is detectable.', 'storecanvas' ),
						'value'       => ! empty( $validation['require_rgb'] ) ? 'yes' : 'no',
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => 'sc_val_strict_bleed',
						'label'       => __( 'Strict bleed', 'storecanvas' ),
						'description' => __( 'When on, bleed violations block add-to-cart. When off, soft-warn only.', 'storecanvas' ),
						'value'       => ! empty( $validation['strict_bleed'] ) ? 'yes' : 'no',
					)
				);
				?>
			</div>

			<div class="options_group sc-admin-section">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Clip-art library', 'storecanvas' ); ?></h4>
				<p class="form-field" style="padding-left:12px;">
					<span class="description"><?php esc_html_e( 'Leave empty to allow all published library items. Select items to restrict this product.', 'storecanvas' ); ?></span>
				</p>
				<p class="form-field" style="padding-left:12px;max-height:180px;overflow:auto;">
					<?php if ( empty( $clipart_all ) ) : ?>
						<em><?php esc_html_e( 'No clip-art yet. Add items under StoreCanvas → Library.', 'storecanvas' ); ?></em>
					<?php else : ?>
						<?php foreach ( $clipart_all as $ci ) : ?>
							<label style="display:block;margin:2px 0;">
								<input type="checkbox" name="sc_clipart_ids[]" value="<?php echo esc_attr( (string) $ci['id'] ); ?>" <?php checked( in_array( (int) $ci['id'], array_map( 'intval', $clipart_sel ), true ) ); ?> />
								<?php echo esc_html( $ci['title'] ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="options_group sc-admin-section">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Global option groups', 'storecanvas' ); ?></h4>
				<p class="form-field" style="padding-left:12px;">
					<span class="description"><?php esc_html_e( 'Select groups to use on this product (StoreCanvas → Option groups). Empty = auto-match by group product/category assignment. Local fields with the same id override group fields.', 'storecanvas' ); ?></span>
				</p>
				<p class="form-field" style="padding-left:12px;max-height:160px;overflow:auto;">
					<?php
					$groups_all = class_exists( 'SC_Option_Groups' ) ? SC_Option_Groups::all_groups() : array();
					$groups_sel = get_post_meta( $post->ID, SC_Plugin::META_OPTION_GROUPS, true );
					$groups_sel = is_array( $groups_sel ) ? array_map( 'intval', $groups_sel ) : array();
					if ( empty( $groups_all ) ) :
						?>
						<em><?php esc_html_e( 'No option groups yet.', 'storecanvas' ); ?></em>
					<?php else : ?>
						<?php foreach ( $groups_all as $g ) : ?>
							<label style="display:block;margin:2px 0;">
								<input type="checkbox" name="sc_option_group_ids[]" value="<?php echo esc_attr( (string) $g['id'] ); ?>" <?php checked( in_array( (int) $g['id'], $groups_sel, true ) ); ?> />
								<?php echo esc_html( $g['title'] ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="options_group sc-admin-section">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Option fields (local)', 'storecanvas' ); ?></h4>
				<p class="form-field" style="padding-left:12px;">
					<span class="description"><?php esc_html_e( 'Required + limits apply when the field is visible (show_if / role / variation). per_char pricing only for text-like fields. stock_qty on choices is optional (omit = unlimited).', 'storecanvas' ); ?></span>
				</p>
				<div id="sc-fields-builder" class="sc-builder" style="padding:0 12px 12px;">
					<p>
						<button type="button" class="button button-primary" id="sc-add-field"><?php esc_html_e( 'Add field', 'storecanvas' ); ?></button>
					</p>
					<div id="sc-fields-list"></div>
				</div>
				<input type="hidden" name="sc_options_fields_json" id="sc_options_fields_json" value="<?php echo esc_attr( wp_json_encode( $options['fields'] ) ); ?>" />
				<script type="application/json" id="sc-field-types"><?php echo wp_json_encode( $field_types ); ?></script>
				<script type="application/json" id="sc-price-types"><?php echo wp_json_encode( $price_types ); ?></script>
				<script type="application/json" id="sc-view-image-urls"><?php echo wp_json_encode( $view_urls ); ?></script>
			</div>
		</div>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = isset( $_POST['sc_customizer_enabled'] ) && 'yes' === $_POST['sc_customizer_enabled']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$views = array();
		if ( isset( $_POST['sc_customizer_views_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( wp_unslash( $_POST['sc_customizer_views_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $v ) {
					if ( ! is_array( $v ) ) {
						continue;
					}
					$views[] = array(
						'id'       => sanitize_key( $v['id'] ?? wp_generate_password( 6, false ) ),
						'label'    => sanitize_text_field( $v['label'] ?? '' ),
						'image_id' => absint( $v['image_id'] ?? 0 ),
					);
				}
			}
		}

		$areas = array();
		if ( isset( $_POST['sc_customizer_areas_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( wp_unslash( $_POST['sc_customizer_areas_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $a ) {
					if ( ! is_array( $a ) ) {
						continue;
					}
					$areas[] = array(
						'id'      => sanitize_key( $a['id'] ?? wp_generate_password( 6, false ) ),
						'view_id' => sanitize_key( $a['view_id'] ?? '' ),
						'label'   => sanitize_text_field( $a['label'] ?? '' ),
						'x'       => (float) ( $a['x'] ?? 0 ),
						'y'       => (float) ( $a['y'] ?? 0 ),
						'w'       => (float) ( $a['w'] ?? 20 ),
						'h'       => (float) ( $a['h'] ?? 20 ),
					);
				}
			}
		}

		update_post_meta(
			$post_id,
			SC_Plugin::META_CUSTOMIZER,
			array(
				'enabled' => $enabled ? 1 : 0,
				'views'   => $views,
				'areas'   => $areas,
			)
		);

		$validation = SC_Plugin::default_validation();
		if ( isset( $_POST['sc_val_min_dpi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['min_dpi'] = max( 72, absint( $_POST['sc_val_min_dpi'] ) );
		}
		if ( isset( $_POST['sc_val_max_mb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['max_upload_mb'] = max( 0.5, (float) $_POST['sc_val_max_mb'] );
		}
		if ( isset( $_POST['sc_val_min_px'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['min_source_px'] = max( 100, absint( $_POST['sc_val_min_px'] ) );
		}
		if ( isset( $_POST['sc_val_print_width'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['target_print_width_in'] = max( 1, (float) $_POST['sc_val_print_width'] );
		}
		if ( isset( $_POST['sc_val_safe_margin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['safe_margin_pct'] = max( 0, min( 40, (float) $_POST['sc_val_safe_margin'] ) );
		}
		if ( isset( $_POST['sc_val_bleed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['bleed_pct'] = max( 0, min( 40, (float) $_POST['sc_val_bleed'] ) );
		}
		if ( isset( $_POST['sc_val_min_bleed_px'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validation['min_bleed_px'] = max( 0, absint( $_POST['sc_val_min_bleed_px'] ) );
		}
		$validation['require_rgb']  = isset( $_POST['sc_val_require_rgb'] ) && 'yes' === $_POST['sc_val_require_rgb']; // phpcs:ignore
		$validation['strict_bleed'] = isset( $_POST['sc_val_strict_bleed'] ) && 'yes' === $_POST['sc_val_strict_bleed']; // phpcs:ignore
		update_post_meta( $post_id, SC_Plugin::META_VALIDATION, $validation );

		$fields = array();
		if ( isset( $_POST['sc_options_fields_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( wp_unslash( $_POST['sc_options_fields_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$row = SC_Product_Options::sanitize_field_row( $f );
					if ( ! empty( $row['id'] ) ) {
						$fields[] = $row;
					}
				}
			}
		}
		update_post_meta( $post_id, SC_Plugin::META_OPTIONS, array( 'fields' => $fields ) );

		$group_ids = array();
		if ( isset( $_POST['sc_option_group_ids'] ) && is_array( $_POST['sc_option_group_ids'] ) ) { // phpcs:ignore
			foreach ( $_POST['sc_option_group_ids'] as $gid ) { // phpcs:ignore
				$group_ids[] = absint( $gid );
			}
		}
		update_post_meta( $post_id, SC_Plugin::META_OPTION_GROUPS, array_values( array_unique( array_filter( $group_ids ) ) ) );

		$clip_ids = array();
		if ( isset( $_POST['sc_clipart_ids'] ) && is_array( $_POST['sc_clipart_ids'] ) ) { // phpcs:ignore
			foreach ( $_POST['sc_clipart_ids'] as $cid ) { // phpcs:ignore
				$clip_ids[] = absint( $cid );
			}
		}
		update_post_meta( $post_id, SC_Plugin::META_CLIPART, $clip_ids );
	}
}
