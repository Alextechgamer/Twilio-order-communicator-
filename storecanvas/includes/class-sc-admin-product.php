<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product edit screen: Options + Customizer sections (shared StoreCanvas tab).
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

	/**
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['storecanvas'] = array(
			'label'    => __( 'StoreCanvas', 'storecanvas' ),
			'target'   => 'sc_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Product data panel markup (scaffold UI).
	 */
	public function panel() {
		global $post;
		$product_id = $post ? (int) $post->ID : 0;
		$options    = SC_Product_Options::get_config( $product_id );
		$customizer = SC_Customizer::get_config( $product_id );
		$validation = SC_Customizer::get_validation( $product_id );
		?>
		<div id="sc_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<strong><?php esc_html_e( 'StoreCanvas — Product options & live mockup', 'storecanvas' ); ?></strong><br />
					<span class="description"><?php esc_html_e( 'Scaffold 0.1.0: data model and admin shell. Full field builder and canvas editor ship in follow-up commits.', 'storecanvas' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Customizer', 'storecanvas' ); ?></h4>
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
				<p class="form-field">
					<label><?php esc_html_e( 'Views (JSON scaffold)', 'storecanvas' ); ?></label>
					<textarea class="short" name="sc_customizer_views_json" rows="4" style="width:90%;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $customizer['views'], JSON_PRETTY_PRINT ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Array of {id,label,image_id}. Visual editor next.', 'storecanvas' ); ?></span>
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Print areas (JSON scaffold)', 'storecanvas' ); ?></label>
					<textarea class="short" name="sc_customizer_areas_json" rows="4" style="width:90%;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $customizer['areas'], JSON_PRETTY_PRINT ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Array of {id,view_id,label,x,y,w,h} — percentages 0–100.', 'storecanvas' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Print validation defaults', 'storecanvas' ); ?></h4>
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
						'label'             => __( 'Min source px (long edge)', 'storecanvas' ),
						'type'              => 'number',
						'value'             => (int) ( $validation['min_source_px'] ?? 500 ),
						'custom_attributes' => array( 'min' => '100', 'step' => '1' ),
					)
				);
				?>
			</div>

			<div class="options_group">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Option fields (JSON scaffold)', 'storecanvas' ); ?></h4>
				<p class="form-field">
					<label><?php esc_html_e( 'Fields JSON', 'storecanvas' ); ?></label>
					<textarea class="short" name="sc_options_fields_json" rows="6" style="width:90%;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $options['fields'], JSON_PRETTY_PRINT ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Array of fields: id, type, label, required, price_type, price, choices[], conditions[]. Visual builder next.', 'storecanvas' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save product meta from scaffold form.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = isset( $_POST['sc_customizer_enabled'] ) && 'yes' === $_POST['sc_customizer_enabled']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$views = array();
		if ( isset( $_POST['sc_customizer_views_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( wp_unslash( $_POST['sc_customizer_views_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				$views = $decoded;
			}
		}

		$areas = array();
		if ( isset( $_POST['sc_customizer_areas_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( wp_unslash( $_POST['sc_customizer_areas_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				$areas = $decoded;
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
				$fields = $decoded;
			}
		}
		update_post_meta( $post_id, SC_Plugin::META_OPTIONS, array( 'fields' => $fields ) );
	}
}
