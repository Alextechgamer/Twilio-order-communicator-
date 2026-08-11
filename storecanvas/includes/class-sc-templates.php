<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prebuilt StoreCanvas product templates (tee / mug / sticker / sign) + onboarding.
 *
 * A one-click way to seed a working customizer + options config on a product, so a new store is not
 * staring at an empty configuration. Templates seed structure — views, print areas (as
 * stage-relative percentages) and sensible option fields — but not artwork: the store still sets
 * the product's own view image(s).
 *
 * The template data and {@see SC_Templates::apply()} are pure and unit-tested; the save handler
 * needs the WordPress runtime.
 */
class SC_Templates {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_sc_apply_template', array( $this, 'handle_apply' ) );
	}

	/* ─────────────────────────  Pure template data  ───────────────────────── */

	/**
	 * All prebuilt templates keyed by slug.
	 *
	 * @return array<string,array{label:string,customizer:array,options:array}>
	 */
	public static function templates() {
		return array(
			'tee'     => array(
				'label'      => __( 'T-shirt', 'storecanvas' ),
				'customizer' => array(
					'enabled' => 1,
					'views'   => array(
						array( 'id' => 'front', 'label' => __( 'Front', 'storecanvas' ), 'image_id' => 0 ),
						array( 'id' => 'back', 'label' => __( 'Back', 'storecanvas' ), 'image_id' => 0 ),
					),
					'areas'   => array(
						array( 'id' => 'front_print', 'view_id' => 'front', 'label' => __( 'Front print', 'storecanvas' ), 'x' => 30.0, 'y' => 25.0, 'w' => 40.0, 'h' => 45.0 ),
						array( 'id' => 'back_print', 'view_id' => 'back', 'label' => __( 'Back print', 'storecanvas' ), 'x' => 30.0, 'y' => 20.0, 'w' => 40.0, 'h' => 50.0 ),
					),
				),
				'options'    => array(
					'fields' => array(
						self::select( 'size', __( 'Size', 'storecanvas' ), array( 'S', 'M', 'L', 'XL', '2XL' ), true ),
						self::select( 'color', __( 'Shirt color', 'storecanvas' ), array( 'White', 'Black', 'Navy', 'Grey' ), true ),
						self::text( 'custom_text', __( 'Custom text (optional)', 'storecanvas' ) ),
					),
				),
			),
			'mug'     => array(
				'label'      => __( 'Mug', 'storecanvas' ),
				'customizer' => array(
					'enabled' => 1,
					'views'   => array(
						array( 'id' => 'wrap', 'label' => __( 'Wrap', 'storecanvas' ), 'image_id' => 0 ),
					),
					'areas'   => array(
						array( 'id' => 'wrap_print', 'view_id' => 'wrap', 'label' => __( 'Print area', 'storecanvas' ), 'x' => 10.0, 'y' => 18.0, 'w' => 80.0, 'h' => 60.0 ),
					),
				),
				'options'    => array(
					'fields' => array(
						self::select( 'style', __( 'Mug style', 'storecanvas' ), array( 'White 11oz', 'White 15oz', 'Black 11oz' ), true ),
						self::text( 'custom_text', __( 'Custom text', 'storecanvas' ) ),
					),
				),
			),
			'sticker' => array(
				'label'      => __( 'Sticker', 'storecanvas' ),
				'customizer' => array(
					'enabled' => 1,
					'views'   => array(
						array( 'id' => 'front', 'label' => __( 'Front', 'storecanvas' ), 'image_id' => 0 ),
					),
					'areas'   => array(
						array( 'id' => 'front_print', 'view_id' => 'front', 'label' => __( 'Print area', 'storecanvas' ), 'x' => 5.0, 'y' => 5.0, 'w' => 90.0, 'h' => 90.0 ),
					),
				),
				'options'    => array(
					'fields' => array(
						self::select( 'size', __( 'Size', 'storecanvas' ), array( '2"', '3"', '4"', '5"' ), true ),
						self::select( 'finish', __( 'Finish', 'storecanvas' ), array( 'Matte', 'Glossy', 'Holographic' ), true ),
					),
				),
			),
			'sign'    => array(
				'label'      => __( 'Sign / Poster', 'storecanvas' ),
				'customizer' => array(
					'enabled' => 1,
					'views'   => array(
						array( 'id' => 'front', 'label' => __( 'Front', 'storecanvas' ), 'image_id' => 0 ),
					),
					'areas'   => array(
						array( 'id' => 'front_print', 'view_id' => 'front', 'label' => __( 'Print area', 'storecanvas' ), 'x' => 5.0, 'y' => 8.0, 'w' => 90.0, 'h' => 74.0 ),
					),
				),
				'options'    => array(
					'fields' => array(
						self::select( 'size', __( 'Size', 'storecanvas' ), array( 'A4', 'A3', '18x24"', '24x36"' ), true ),
						self::select( 'material', __( 'Material', 'storecanvas' ), array( 'Paper', 'Foamboard', 'Aluminium' ), true ),
						self::text( 'headline', __( 'Headline text', 'storecanvas' ) ),
					),
				),
			),
		);
	}

	/**
	 * The customizer + options config for one template key, or null if unknown (pure).
	 *
	 * @param string $key Template slug.
	 * @return array{customizer:array,options:array}|null
	 */
	public static function apply( $key ) {
		$all = self::templates();
		$key = (string) $key;
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		return array(
			'customizer' => $all[ $key ]['customizer'],
			'options'    => $all[ $key ]['options'],
		);
	}

	/**
	 * Build a select field row (choice values == labels).
	 *
	 * @param string   $id       Field id.
	 * @param string   $label    Field label.
	 * @param string[] $choices  Choice values.
	 * @param bool     $required Whether required.
	 * @return array
	 */
	private static function select( $id, $label, $choices, $required = false ) {
		$rows = array();
		foreach ( $choices as $c ) {
			$rows[] = array( 'value' => (string) $c, 'label' => (string) $c );
		}
		return array(
			'id'         => $id,
			'type'       => 'select',
			'label'      => $label,
			'required'   => (bool) $required,
			'price_type' => 'none',
			'price'      => 0.0,
			'choices'    => $rows,
		);
	}

	/**
	 * Build a text field row.
	 *
	 * @param string $id    Field id.
	 * @param string $label Field label.
	 * @return array
	 */
	private static function text( $id, $label ) {
		return array(
			'id'         => $id,
			'type'       => 'text',
			'label'      => $label,
			'required'   => false,
			'price_type' => 'none',
			'price'      => 0.0,
		);
	}

	/* ─────────────────────────  Admin UI + handler  ───────────────────────── */

	/**
	 * @param string $post_type Current post type.
	 */
	public function add_meta_box( $post_type ) {
		if ( 'product' !== $post_type || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		add_meta_box( 'sc-templates', __( 'StoreCanvas: Start from a template', 'storecanvas' ), array( $this, 'render_meta_box' ), 'product', 'side', 'default' );
	}

	/**
	 * @param WP_Post $post Product post.
	 */
	public function render_meta_box( $post ) {
		echo '<p>' . esc_html__( 'Seed a working print area and options from a preset, then set the product image and adjust as needed.', 'storecanvas' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sc_apply_template_' . $post->ID );
		echo '<input type="hidden" name="action" value="sc_apply_template" />';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $post->ID ) . '" />';
		echo '<select name="template" style="width:100%;margin-bottom:8px;">';
		foreach ( self::templates() as $key => $tpl ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $tpl['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Apply template', 'storecanvas' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Overwrites the StoreCanvas customizer and options for this product.', 'storecanvas' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Apply a template to a product: sanitize fields + save meta. Reuses the FPD-import notice.
	 */
	public function handle_apply() {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		check_admin_referer( 'sc_apply_template_' . $product_id );

		$key     = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
		$applied = self::apply( $key );
		if ( null === $applied ) {
			$this->redirect_back( $product_id, 'error', __( 'Unknown template.', 'storecanvas' ) );
		}

		$fields = array();
		foreach ( $applied['options']['fields'] as $f ) {
			$row = SC_Product_Options::sanitize_field_row( $f );
			if ( ! empty( $row['id'] ) ) {
				$fields[] = $row;
			}
		}

		update_post_meta( $product_id, SC_Plugin::META_CUSTOMIZER, $applied['customizer'] );
		update_post_meta( $product_id, SC_Plugin::META_OPTIONS, array( 'fields' => $fields ) );

		$labels = self::templates();
		$name   = isset( $labels[ $key ]['label'] ) ? $labels[ $key ]['label'] : $key;
		$this->redirect_back(
			$product_id,
			'updated',
			sprintf(
				/* translators: 1: template name, 2: field count. */
				__( 'Applied the %1$s template (%2$d option field(s)). Set the product view image to finish.', 'storecanvas' ),
				$name,
				count( $fields )
			)
		);
	}

	/**
	 * @param int    $product_id Product ID.
	 * @param string $type       updated|error.
	 * @param string $message    Notice text.
	 */
	private function redirect_back( $product_id, $type, $message ) {
		$url = add_query_arg(
			array(
				'sc_fpd_notice' => rawurlencode( $message ),
				'sc_fpd_type'   => ( 'error' === $type ? 'error' : 'updated' ),
			),
			get_edit_post_link( $product_id, 'url' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
