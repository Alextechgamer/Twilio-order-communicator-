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
		?>
		<div id="sc_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<strong><?php esc_html_e( 'StoreCanvas', 'storecanvas' ); ?></strong>
					<span class="description"> — <?php esc_html_e( 'Product options and live mockup. Version 0.2.0.', 'storecanvas' ); ?></span>
				</p>
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
					<p class="description"><?php esc_html_e( 'x, y, w, h are percentages (0–100) of the view image.', 'storecanvas' ); ?></p>
					<div id="sc-areas-list"></div>
				</div>
				<input type="hidden" name="sc_customizer_views_json" id="sc_customizer_views_json" value="<?php echo esc_attr( wp_json_encode( $customizer['views'] ) ); ?>" />
				<input type="hidden" name="sc_customizer_areas_json" id="sc_customizer_areas_json" value="<?php echo esc_attr( wp_json_encode( $customizer['areas'] ) ); ?>" />
			</div>

			<div class="options_group">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Print validation', 'storecanvas' ); ?></h4>
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
				?>
			</div>

			<div class="options_group sc-admin-section">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Option fields', 'storecanvas' ); ?></h4>
				<div id="sc-fields-builder" class="sc-builder" style="padding:0 12px 12px;">
					<p>
						<button type="button" class="button button-primary" id="sc-add-field"><?php esc_html_e( 'Add field', 'storecanvas' ); ?></button>
					</p>
					<div id="sc-fields-list"></div>
				</div>
				<input type="hidden" name="sc_options_fields_json" id="sc_options_fields_json" value="<?php echo esc_attr( wp_json_encode( $options['fields'] ) ); ?>" />
				<script type="application/json" id="sc-field-types"><?php echo wp_json_encode( $field_types ); ?></script>
				<script type="application/json" id="sc-price-types"><?php echo wp_json_encode( $price_types ); ?></script>
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
		update_post_meta( $post_id, SC_Plugin::META_VALIDATION, $validation );

		$fields = array();
		if ( isset( $_POST['sc_options_fields_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( wp_unslash( $_POST['sc_options_fields_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$fields[] = array(
						'id'         => sanitize_key( $f['id'] ?? wp_generate_password( 6, false ) ),
						'type'       => sanitize_key( $f['type'] ?? 'text' ),
						'label'      => sanitize_text_field( $f['label'] ?? '' ),
						'required'   => ! empty( $f['required'] ),
						'price_type' => sanitize_key( $f['price_type'] ?? 'none' ),
						'price'      => (float) ( $f['price'] ?? 0 ),
						'choices'    => isset( $f['choices'] ) && is_array( $f['choices'] ) ? $f['choices'] : array(),
					);
				}
			}
		}
		update_post_meta( $post_id, SC_Plugin::META_OPTIONS, array( 'fields' => $fields ) );
	}
}
