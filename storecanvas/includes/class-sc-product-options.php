<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase A: product option fields (types, pricing, conditionals).
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

	public static function price_types() {
		return array(
			'none'       => __( 'Free', 'storecanvas' ),
			'flat'       => __( 'Flat fee', 'storecanvas' ),
			'percent'    => __( 'Percent of product', 'storecanvas' ),
			'qty'        => __( 'Per quantity', 'storecanvas' ),
			'per_char'   => __( 'Per character (text)', 'storecanvas' ),
		);
	}

	public static function pricing_config_for_product( $product_id ) {
		$config = self::get_config( $product_id );
		$out    = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			if ( empty( $field['id'] ) || 'heading' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$out[] = array(
				'id'         => $field['id'],
				'type'       => $field['type'] ?? 'text',
				'price_type' => $field['price_type'] ?? 'none',
				'price'      => (float) ( $field['price'] ?? 0 ),
			);
		}
		return $out;
	}

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

	public function render_fields() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$config = self::get_config( $product->get_id() );
		if ( empty( $config['fields'] ) ) {
			return;
		}

		$pricing_fields = array();
		foreach ( $config['fields'] as $field ) {
			if ( empty( $field['id'] ) || 'heading' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$pricing_fields[] = array(
				'id'         => $field['id'],
				'type'       => $field['type'] ?? 'text',
				'price_type' => $field['price_type'] ?? 'none',
				'price'      => (float) ( $field['price'] ?? 0 ),
				'show_if'    => $field['show_if'] ?? null,
			);
		}
		echo '<div class="sc-options" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-sc-pricing="' . esc_attr( wp_json_encode( $pricing_fields ) ) . '">';
		foreach ( $config['fields'] as $field ) {
			$this->render_field( $field );
		}
		echo '</div>';
		?>
		<script>
		(function(){
			function apply(){
				var root=document.querySelector('.sc-options');
				if(!root) return;
				root.querySelectorAll('.sc-option-field[data-show-if-field]').forEach(function(el){
					var fid=el.getAttribute('data-show-if-field');
					var want=el.getAttribute('data-show-if-value')||'';
					var src=root.querySelector('[name="sc_option['+fid+']"], [name="sc_option['+fid+'][]"]');
					var val='';
					if(src){
						if(src.type==='checkbox'){ val=src.checked?'1':''; }
						else { val=src.value||''; }
					}
					el.style.display = (!want || val===want) ? '' : 'none';
					var inputs=el.querySelectorAll('input,select,textarea');
					inputs.forEach(function(i){ if(el.style.display==='none'){ i.removeAttribute('required'); } });
				});
			}
			document.addEventListener('change', function(e){ if(e.target.closest&&e.target.closest('.sc-options')) apply(); });
			apply();
		})();
		</script>
		<?php
	}

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

		$show_if_field = isset( $field['show_if']['field'] ) ? $field['show_if']['field'] : ( $field['show_if_field'] ?? '' );
		$show_if_value = isset( $field['show_if']['value'] ) ? $field['show_if']['value'] : ( $field['show_if_value'] ?? '' );
		$price_type = isset( $field['price_type'] ) ? $field['price_type'] : 'none';
		$price_amt  = isset( $field['price'] ) ? (float) $field['price'] : 0;
		$attrs = ' class="sc-option-field sc-option-' . esc_attr( $type ) . '" data-field-id="' . esc_attr( $id ) . '" data-price-type="' . esc_attr( $price_type ) . '" data-price="' . esc_attr( (string) $price_amt ) . '"';
		if ( $show_if_field ) {
			$attrs .= ' data-show-if-field="' . esc_attr( $show_if_field ) . '" data-show-if-value="' . esc_attr( $show_if_value ) . '"';
		}
		echo '<p' . $attrs . '>';
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
