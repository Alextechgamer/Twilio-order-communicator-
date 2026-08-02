<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase A: product option fields (types, pricing, conditionals).
 * Scaffold: data model + product-page render shell + cart capture hooks via SC_Cart_Order.
 */
class SC_Product_Options {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_fields' ), 15 );
	}

	/**
	 * Supported field types (A foundation; more can be added without schema break).
	 *
	 * @return string[]
	 */
	public static function field_types() {
		return array(
			'select'     => __( 'Dropdown', 'storecanvas' ),
			'radio'      => __( 'Radio', 'storecanvas' ),
			'checkbox'   => __( 'Checkbox', 'storecanvas' ),
			'text'       => __( 'Short text', 'storecanvas' ),
			'textarea'   => __( 'Long text', 'storecanvas' ),
			'file'       => __( 'File upload', 'storecanvas' ),
			'number'     => __( 'Number', 'storecanvas' ),
			'date'       => __( 'Date', 'storecanvas' ),
			'color'      => __( 'Color', 'storecanvas' ),
			'heading'    => __( 'Section heading', 'storecanvas' ),
		);
	}

	/**
	 * Price types.
	 *
	 * @return string[]
	 */
	public static function price_types() {
		return array(
			'none'       => __( 'Free', 'storecanvas' ),
			'flat'       => __( 'Flat fee', 'storecanvas' ),
			'percent'    => __( 'Percent of product', 'storecanvas' ),
			'qty'        => __( 'Per quantity', 'storecanvas' ),
			'per_char'   => __( 'Per character (text)', 'storecanvas' ),
		);
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_config( $product_id ) {
		$raw = get_post_meta( $product_id, SC_Plugin::META_OPTIONS, true );
		if ( ! is_array( $raw ) ) {
			return SC_Plugin::empty_options();
		}
		if ( empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
			$raw['fields'] = array();
		}
		return $raw;
	}

	/**
	 * Render option fields on the single product page.
	 */
	public function render_fields() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$config = self::get_config( $product->get_id() );
		if ( empty( $config['fields'] ) ) {
			return;
		}

		echo '<div class="sc-options" data-product-id="' . esc_attr( $product->get_id() ) . '">';
		foreach ( $config['fields'] as $field ) {
			$this->render_field( $field );
		}
		echo '</div>';
	}

	/**
	 * @param array $field Field config.
	 */
	private function render_field( $field ) {
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$id    = isset( $field['id'] ) ? $field['id'] : '';
		$label = isset( $field['label'] ) ? $field['label'] : '';
		$req   = ! empty( $field['required'] );
		$name  = 'sc_option[' . esc_attr( $id ) . ']';

		if ( 'heading' === $type ) {
			echo '<div class="sc-option-heading"><strong>' . esc_html( $label ) . '</strong></div>';
			return;
		}

		echo '<p class="sc-option-field sc-option-' . esc_attr( $type ) . '" data-field-id="' . esc_attr( $id ) . '">';
		echo '<label for="sc_option_' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( $req ) {
			echo ' <abbr class="required" title="required">*</abbr>';
		}
		echo '</label> ';

		switch ( $type ) {
			case 'select':
				echo '<select name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '"' . ( $req ? ' required' : '' ) . '>';
				echo '<option value="">' . esc_html__( 'Choose…', 'storecanvas' ) . '</option>';
				foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
					$val = is_array( $choice ) ? ( $choice['value'] ?? '' ) : $choice;
					$lab = is_array( $choice ) ? ( $choice['label'] ?? $val ) : $choice;
					echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $lab ) . '</option>';
				}
				echo '</select>';
				break;
			case 'textarea':
				echo '<textarea name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '" rows="3"' . ( $req ? ' required' : '' ) . '></textarea>';
				break;
			case 'file':
				echo '<input type="file" name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '"' . ( $req ? ' required' : '' ) . ' />';
				echo '<span class="sc-file-hint">' . esc_html__( 'File is stored with the order; use Customizer for live placement.', 'storecanvas' ) . '</span>';
				break;
			case 'checkbox':
				echo '<input type="checkbox" name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '" value="1" />';
				break;
			default:
				$input_type = in_array( $type, array( 'number', 'date', 'color', 'text' ), true ) ? $type : 'text';
				echo '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '"' . ( $req ? ' required' : '' ) . ' />';
		}
		echo '</p>';
	}
}
