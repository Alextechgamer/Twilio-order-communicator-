<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product option fields: types, pricing, conditionals, groups merge (1.2.0).
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
			'select'       => __( 'Dropdown', 'storecanvas' ),
			'radio'        => __( 'Radio', 'storecanvas' ),
			'checkbox'     => __( 'Checkbox', 'storecanvas' ),
			'multi_select' => __( 'Multi-select', 'storecanvas' ),
			'text'         => __( 'Short text', 'storecanvas' ),
			'textarea'     => __( 'Long text', 'storecanvas' ),
			'email'        => __( 'Email', 'storecanvas' ),
			'phone'        => __( 'Phone', 'storecanvas' ),
			'tel'          => __( 'Phone (tel)', 'storecanvas' ),
			'number'       => __( 'Number', 'storecanvas' ),
			'date'         => __( 'Date', 'storecanvas' ),
			'color'        => __( 'Color', 'storecanvas' ),
			'image_choice' => __( 'Image choice', 'storecanvas' ),
			'file'         => __( 'File upload', 'storecanvas' ),
			'heading'      => __( 'Section heading', 'storecanvas' ),
		);
	}

	public static function price_types() {
		return array(
			'none'     => __( 'Free', 'storecanvas' ),
			'flat'     => __( 'Flat fee', 'storecanvas' ),
			'percent'  => __( 'Percent of product', 'storecanvas' ),
			'qty'      => __( 'Per quantity', 'storecanvas' ),
			'per_char' => __( 'Per character (text)', 'storecanvas' ),
			'lookup'   => __( 'Per choice (lookup table)', 'storecanvas' ),
		);
	}

	/**
	 * Operators available to conditional-logic rules.
	 *
	 * @return string[]
	 */
	public static function condition_operators() {
		return array( 'is', 'is_not', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty', 'in' );
	}

	/**
	 * Text-like types that may use per_char pricing and char limits.
	 *
	 * @return string[]
	 */
	public static function text_like_types() {
		return array( 'text', 'textarea', 'email', 'phone', 'tel' );
	}

	/**
	 * Sanitize one field definition (shared by product + groups).
	 *
	 * @param array $f Raw field.
	 * @return array
	 */
	public static function sanitize_field_row( $f ) {
		if ( ! is_array( $f ) ) {
			return array();
		}
		$show_if = array();
		if ( ! empty( $f['show_if'] ) && is_array( $f['show_if'] ) ) {
			$sf = sanitize_key( $f['show_if']['field'] ?? '' );
			if ( $sf ) {
				$show_if = array(
					'field' => $sf,
					'value' => sanitize_text_field( $f['show_if']['value'] ?? '' ),
				);
			}
		}
		// Multi-rule conditional logic: { logic: and|or, rules: [ {field, op, value} ] }.
		$conditions = array();
		if ( ! empty( $f['conditions']['rules'] ) && is_array( $f['conditions']['rules'] ) ) {
			$logic    = ( isset( $f['conditions']['logic'] ) && 'or' === $f['conditions']['logic'] ) ? 'or' : 'and';
			$ops      = self::condition_operators();
			$rules    = array();
			foreach ( $f['conditions']['rules'] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$rf = sanitize_key( $r['field'] ?? '' );
				if ( '' === $rf ) {
					continue;
				}
				$op = sanitize_key( $r['op'] ?? 'is' );
				if ( ! in_array( $op, $ops, true ) ) {
					$op = 'is';
				}
				$rules[] = array(
					'field' => $rf,
					'op'    => $op,
					'value' => sanitize_text_field( $r['value'] ?? '' ),
				);
			}
			if ( $rules ) {
				$conditions = array(
					'logic' => $logic,
					'rules' => $rules,
				);
			}
		}
		$choices = array();
		if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
			foreach ( $f['choices'] as $c ) {
				if ( is_array( $c ) ) {
					$row = array(
						'value' => sanitize_text_field( $c['value'] ?? '' ),
						'label' => sanitize_text_field( $c['label'] ?? ( $c['value'] ?? '' ) ),
					);
					if ( ! empty( $c['image_url'] ) ) {
						$row['image_url'] = esc_url_raw( $c['image_url'] );
					}
					if ( ! empty( $c['image_id'] ) ) {
						$row['image_id'] = absint( $c['image_id'] );
					}
					if ( array_key_exists( 'stock_qty', $c ) && null !== $c['stock_qty'] && '' !== $c['stock_qty'] ) {
						$row['stock_qty'] = max( 0, (int) $c['stock_qty'] );
					}
					// Per-choice price for lookup-table pricing (may be negative for a discount).
					if ( array_key_exists( 'price', $c ) && null !== $c['price'] && '' !== $c['price'] ) {
						$row['price'] = (float) $c['price'];
					}
					$choices[] = $row;
				} else {
					$s         = sanitize_text_field( (string) $c );
					$choices[] = array( 'value' => $s, 'label' => $s );
				}
			}
		}
		$roles = array();
		if ( ! empty( $f['roles_allowed'] ) && is_array( $f['roles_allowed'] ) ) {
			$roles = array_values( array_filter( array_map( 'sanitize_key', $f['roles_allowed'] ) ) );
		} elseif ( ! empty( $f['roles_allowed'] ) && is_string( $f['roles_allowed'] ) ) {
			$roles = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $f['roles_allowed'] ) ) ) );
		}
		$variation_ids = array();
		if ( ! empty( $f['variation_ids'] ) && is_array( $f['variation_ids'] ) ) {
			$variation_ids = array_values( array_filter( array_map( 'absint', $f['variation_ids'] ) ) );
		} elseif ( ! empty( $f['variation_ids'] ) && is_string( $f['variation_ids'] ) ) {
			foreach ( preg_split( '/[\s,]+/', $f['variation_ids'] ) as $vid ) {
				$vid = absint( $vid );
				if ( $vid ) {
					$variation_ids[] = $vid;
				}
			}
		}
		$row = array(
			'id'         => sanitize_key( $f['id'] ?? wp_generate_password( 6, false ) ),
			'type'       => sanitize_key( $f['type'] ?? 'text' ),
			'label'      => sanitize_text_field( $f['label'] ?? '' ),
			'required'   => ! empty( $f['required'] ),
			'price_type' => sanitize_key( $f['price_type'] ?? 'none' ),
			'price'      => (float) ( $f['price'] ?? 0 ),
			'choices'    => $choices,
		);
		if ( $show_if ) {
			$row['show_if'] = $show_if;
		}
		if ( $conditions ) {
			$row['conditions'] = $conditions;
		}
		if ( $roles ) {
			$row['roles_allowed'] = $roles;
		}
		if ( $variation_ids ) {
			$row['variation_ids'] = $variation_ids;
		}
		if ( isset( $f['default_value'] ) && '' !== $f['default_value'] && null !== $f['default_value'] ) {
			$row['default_value'] = is_array( $f['default_value'] )
				? array_map( 'sanitize_text_field', $f['default_value'] )
				: sanitize_text_field( (string) $f['default_value'] );
		}
		foreach ( array( 'min_chars', 'max_chars', 'min', 'max', 'step', 'option_min_qty', 'option_max_qty' ) as $numk ) {
			if ( isset( $f[ $numk ] ) && '' !== $f[ $numk ] && null !== $f[ $numk ] ) {
				$row[ $numk ] = (float) $f[ $numk ];
			}
		}
		if ( ! empty( $f['multi'] ) ) {
			$row['multi'] = 1;
		}
		// Restrict per_char on non-text-like.
		if ( 'per_char' === $row['price_type'] && ! in_array( $row['type'], self::text_like_types(), true ) ) {
			$row['price_type'] = 'flat';
		}
		return $row;
	}

	/**
	 * Local product config only (no groups).
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_local_config( $product_id ) {
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
	 * Merged fields: global groups (category then product) + local (local id wins).
	 *
	 * @param int $product_id Product ID.
	 * @return array{fields:array}
	 */
	public static function get_config( $product_id ) {
		$product_id = absint( $product_id );
		$merged     = array();

		if ( class_exists( 'SC_Option_Groups' ) ) {
			foreach ( SC_Option_Groups::groups_for_product( $product_id ) as $g ) {
				foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
					if ( empty( $f['id'] ) ) {
						continue;
					}
					$fid = sanitize_key( $f['id'] );
					if ( ! isset( $merged[ $fid ] ) ) {
						$merged[ $fid ] = $f;
					}
				}
			}
		}

		$local = self::get_local_config( $product_id );
		foreach ( (array) ( $local['fields'] ?? array() ) as $f ) {
			if ( empty( $f['id'] ) ) {
				continue;
			}
			$merged[ sanitize_key( $f['id'] ) ] = $f;
		}

		return array( 'fields' => array_values( $merged ) );
	}

	public static function pricing_config_for_product( $product_id ) {
		$config = self::get_config( $product_id );
		$out    = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			if ( empty( $field['id'] ) || 'heading' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			if ( ! self::user_can_see_field( $field ) ) {
				continue;
			}
			$row = array(
				'id'         => $field['id'],
				'type'       => $field['type'] ?? 'text',
				'price_type' => $field['price_type'] ?? 'none',
				'price'      => (float) ( $field['price'] ?? 0 ),
				'show_if'    => $field['show_if'] ?? null,
			);
			// Ship the per-choice price map so the live preview can match lookup-table charges.
			if ( 'lookup' === ( $field['price_type'] ?? 'none' ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
				$prices = array();
				foreach ( $field['choices'] as $c ) {
					if ( is_array( $c ) && isset( $c['price'] ) ) {
						$prices[ (string) ( $c['value'] ?? '' ) ] = (float) $c['price'];
					}
				}
				if ( $prices ) {
					$row['choice_prices'] = $prices;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param array $field Field.
	 * @return bool
	 */
	public static function user_can_see_field( $field ) {
		$roles = isset( $field['roles_allowed'] ) ? (array) $field['roles_allowed'] : array();
		$roles = array_filter( array_map( 'sanitize_key', $roles ) );
		if ( ! $roles ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		foreach ( $roles as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether field targets current variation (empty list = all).
	 *
	 * @param array $field Field.
	 * @param int   $variation_id Variation ID.
	 * @return bool
	 */
	public static function field_matches_variation( $field, $variation_id ) {
		$ids = isset( $field['variation_ids'] ) ? array_filter( array_map( 'absint', (array) $field['variation_ids'] ) ) : array();
		if ( ! $ids ) {
			return true;
		}
		$variation_id = absint( $variation_id );
		if ( ! $variation_id ) {
			// Variable product with no selection yet — hide targeted fields until chosen.
			return false;
		}
		return in_array( $variation_id, $ids, true );
	}

	/**
	 * show_if currently visible given submitted options.
	 *
	 * @param array $field   Field.
	 * @param array $options Submitted sc_option map.
	 * @return bool
	 */
	public static function field_is_visible( $field, $options ) {
		if ( ! self::user_can_see_field( $field ) ) {
			return false;
		}
		// Multi-rule conditional logic takes precedence when present.
		if ( ! empty( $field['conditions']['rules'] ) && is_array( $field['conditions']['rules'] ) ) {
			return self::evaluate_conditions( $field['conditions'], $options );
		}
		// Legacy single show_if (field + equality).
		$sf = $field['show_if']['field'] ?? '';
		if ( ! $sf ) {
			return true;
		}
		$want = (string) ( $field['show_if']['value'] ?? '' );
		$got  = $options[ $sf ] ?? '';
		if ( is_array( $got ) ) {
			return in_array( $want, $got, true ) || ( '' === $want );
		}
		return ( '' === $want ) || ( (string) $got === $want );
	}

	/**
	 * Evaluate a conditional-logic block against the submitted option values (pure).
	 * An empty rule set is always visible; AND requires every rule, OR requires any.
	 *
	 * @param array $conditions { logic: and|or, rules: [ {field, op, value} ] }.
	 * @param array $options    Submitted sc_option map.
	 * @return bool
	 */
	public static function evaluate_conditions( $conditions, $options ) {
		if ( empty( $conditions['rules'] ) || ! is_array( $conditions['rules'] ) ) {
			return true;
		}
		$logic   = ( isset( $conditions['logic'] ) && 'or' === $conditions['logic'] ) ? 'or' : 'and';
		$results = array();
		foreach ( $conditions['rules'] as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}
			$got       = $options[ $rule['field'] ] ?? '';
			$results[] = self::rule_matches( $rule['op'] ?? 'is', $rule['value'] ?? '', $got );
		}
		if ( ! $results ) {
			return true;
		}
		return 'or' === $logic ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Evaluate a single conditional-logic rule (pure).
	 *
	 * @param string $op   Operator (see condition_operators()).
	 * @param string $want Comparison value.
	 * @param mixed  $got  Submitted value (scalar or array for multi-selects).
	 * @return bool
	 */
	public static function rule_matches( $op, $want, $got ) {
		$got_arr = is_array( $got ) ? array_map( 'strval', $got ) : array( (string) $got );
		$got_str = is_array( $got ) ? implode( ',', $got_arr ) : (string) $got;
		$want    = (string) $want;
		$numeric = is_numeric( $got_str ) && is_numeric( $want );

		switch ( $op ) {
			case 'is':
				return in_array( $want, $got_arr, true );
			case 'is_not':
				return ! in_array( $want, $got_arr, true );
			case 'contains':
				return '' !== $want && false !== strpos( $got_str, $want );
			case 'not_contains':
				return '' === $want || false === strpos( $got_str, $want );
			case 'gt':
				return $numeric && (float) $got_str > (float) $want;
			case 'gte':
				return $numeric && (float) $got_str >= (float) $want;
			case 'lt':
				return $numeric && (float) $got_str < (float) $want;
			case 'lte':
				return $numeric && (float) $got_str <= (float) $want;
			case 'empty':
				return '' === $got_str;
			case 'not_empty':
				return '' !== $got_str;
			case 'in':
				$list = array_map( 'trim', explode( ',', $want ) );
				return (bool) array_intersect( $got_arr, $list );
			default:
				return false;
		}
	}

	/**
	 * Human label for a stored value.
	 *
	 * @param array $field Field.
	 * @param mixed $val   Value.
	 * @return string
	 */
	public static function format_value_label( $field, $val ) {
		if ( is_array( $val ) ) {
			$parts = array();
			foreach ( $val as $v ) {
				$parts[] = self::format_value_label( $field, $v );
			}
			return implode( ', ', $parts );
		}
		$val = (string) $val;
		foreach ( (array) ( $field['choices'] ?? array() ) as $c ) {
			$cv = is_array( $c ) ? (string) ( $c['value'] ?? '' ) : (string) $c;
			$cl = is_array( $c ) ? (string) ( $c['label'] ?? $cv ) : (string) $c;
			if ( $cv === $val ) {
				return $cl !== '' ? $cl : $cv;
			}
		}
		if ( 'checkbox' === ( $field['type'] ?? '' ) ) {
			return $val ? __( 'Yes', 'storecanvas' ) : __( 'No', 'storecanvas' );
		}
		return $val;
	}

	/**
	 * Choice stock remaining (null = unlimited).
	 *
	 * @param array  $field Field.
	 * @param string $value Choice value.
	 * @return int|null
	 */
	public static function choice_stock( $field, $value ) {
		foreach ( (array) ( $field['choices'] ?? array() ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			if ( (string) ( $c['value'] ?? '' ) !== (string) $value ) {
				continue;
			}
			if ( ! array_key_exists( 'stock_qty', $c ) || null === $c['stock_qty'] || '' === $c['stock_qty'] ) {
				return null;
			}
			return (int) $c['stock_qty'];
		}
		return null;
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
			if ( ! self::user_can_see_field( $field ) ) {
				continue;
			}
			$pricing_fields[] = array(
				'id'             => $field['id'],
				'type'           => $field['type'] ?? 'text',
				'price_type'     => $field['price_type'] ?? 'none',
				'price'          => (float) ( $field['price'] ?? 0 ),
				'show_if'        => $field['show_if'] ?? null,
				'variation_ids'  => $field['variation_ids'] ?? array(),
			);
		}
		echo '<div class="sc-options" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-sc-pricing="' . esc_attr( wp_json_encode( $pricing_fields ) ) . '">';
		foreach ( $config['fields'] as $field ) {
			if ( ! empty( $field['id'] ) && 'heading' !== ( $field['type'] ?? '' ) && ! self::user_can_see_field( $field ) ) {
				continue;
			}
			$this->render_field( $field );
		}
		echo '</div>';
		?>
		<script>
		(function(){
			function selectedVariation(){
				var el=document.querySelector('input[name="variation_id"], select[name="variation_id"]');
				return el ? parseInt(el.value||'0',10)||0 : 0;
			}
			function apply(){
				var root=document.querySelector('.sc-options');
				if(!root) return;
				var vid=selectedVariation();
				root.querySelectorAll('.sc-option-field').forEach(function(el){
					var hide=false;
					var vids=(el.getAttribute('data-variation-ids')||'').split(',').filter(Boolean).map(function(x){return parseInt(x,10);});
					if(vids.length){
						if(!vid || vids.indexOf(vid)<0) hide=true;
					}
					if(el.getAttribute('data-show-if-field')){
						var fid=el.getAttribute('data-show-if-field');
						var want=el.getAttribute('data-show-if-value')||'';
						var srcs=root.querySelectorAll('[name="sc_option['+fid+']"], [name="sc_option['+fid+'][]"]');
						var val='';
						if(srcs.length){
							var src=srcs[0];
							if(src.type==='checkbox' && srcs.length===1){ val=src.checked?'1':''; }
							else if(src.type==='radio'){
								srcs.forEach(function(s){ if(s.checked) val=s.value; });
							} else if(src.multiple){
								val=Array.prototype.map.call(src.selectedOptions||[],function(o){return o.value;}).join(',');
							} else { val=src.value||''; }
						}
						if(want && val!==want && (','+val+',').indexOf(','+want+',')<0) hide=true;
					}
					el.style.display = hide ? 'none' : '';
					el.setAttribute('data-sc-visible', hide ? '0' : '1');
					var inputs=el.querySelectorAll('input,select,textarea');
					inputs.forEach(function(i){
						if(hide){ i.removeAttribute('required'); }
						else if(el.getAttribute('data-required')==='1'){ i.setAttribute('required','required'); }
					});
				});
			}
			document.addEventListener('change', function(e){
				if(e.target && (e.target.closest&&e.target.closest('.sc-options') || e.target.name==='variation_id' || (e.target.name&&e.target.name.indexOf('attribute_')===0))) apply();
			});
			document.addEventListener('found_variation', apply);
			document.addEventListener('reset_data', apply);
			if(window.jQuery){
				jQuery(document.body).on('found_variation reset_data check_variations', apply);
			}
			apply();
		})();
		</script>
		<style>
		.sc-image-choice{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
		.sc-image-choice label{display:flex;flex-direction:column;align-items:center;width:88px;font-size:12px;border:1px solid #ddd;padding:6px;border-radius:4px;cursor:pointer}
		.sc-image-choice img{max-width:72px;max-height:72px;object-fit:contain}
		.sc-image-choice input{margin-bottom:4px}
		.sc-option-field[data-sc-visible="0"]{display:none!important}
		</style>
		<?php
	}

	private function render_field( $field ) {
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$id    = isset( $field['id'] ) ? $field['id'] : '';
		$label = isset( $field['label'] ) ? $field['label'] : '';
		$req   = ! empty( $field['required'] );
		$name  = 'sc_option[' . esc_attr( $id ) . ']';
		$def   = $field['default_value'] ?? '';

		if ( 'heading' === $type ) {
			echo '<div class="sc-option-heading"><strong>' . esc_html( $label ) . '</strong></div>';
			return;
		}

		$show_if_field = isset( $field['show_if']['field'] ) ? $field['show_if']['field'] : '';
		$show_if_value = isset( $field['show_if']['value'] ) ? $field['show_if']['value'] : '';
		$price_type    = isset( $field['price_type'] ) ? $field['price_type'] : 'none';
		$price_amt     = isset( $field['price'] ) ? (float) $field['price'] : 0;
		$var_ids       = isset( $field['variation_ids'] ) ? array_map( 'absint', (array) $field['variation_ids'] ) : array();

		$attrs = ' class="sc-option-field sc-option-' . esc_attr( $type ) . '" data-field-id="' . esc_attr( $id ) . '" data-price-type="' . esc_attr( $price_type ) . '" data-price="' . esc_attr( (string) $price_amt ) . '" data-required="' . ( $req ? '1' : '0' ) . '"';
		if ( $show_if_field ) {
			$attrs .= ' data-show-if-field="' . esc_attr( $show_if_field ) . '" data-show-if-value="' . esc_attr( $show_if_value ) . '"';
		}
		if ( $var_ids ) {
			$attrs .= ' data-variation-ids="' . esc_attr( implode( ',', $var_ids ) ) . '"';
		}
		echo '<p' . $attrs . '>';
		echo '<label for="sc_option_' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( $req ) {
			echo ' <abbr class="required" title="required">*</abbr>';
		}
		echo '</label> ';

		$extra = '';
		if ( isset( $field['min_chars'] ) ) {
			$extra .= ' minlength="' . esc_attr( (string) (int) $field['min_chars'] ) . '"';
		}
		if ( isset( $field['max_chars'] ) ) {
			$extra .= ' maxlength="' . esc_attr( (string) (int) $field['max_chars'] ) . '"';
		}
		if ( isset( $field['min'] ) ) {
			$extra .= ' min="' . esc_attr( (string) $field['min'] ) . '"';
		}
		if ( isset( $field['max'] ) ) {
			$extra .= ' max="' . esc_attr( (string) $field['max'] ) . '"';
		}
		if ( isset( $field['step'] ) ) {
			$extra .= ' step="' . esc_attr( (string) $field['step'] ) . '"';
		}

		switch ( $type ) {
			case 'select':
				echo '<select name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '"' . ( $req ? ' required' : '' ) . '>';
				echo '<option value="">' . esc_html__( 'Choose…', 'storecanvas' ) . '</option>';
				foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
					$val = is_array( $choice ) ? ( $choice['value'] ?? '' ) : $choice;
					$lab = is_array( $choice ) ? ( $choice['label'] ?? $val ) : $choice;
					$stk = is_array( $choice ) && array_key_exists( 'stock_qty', $choice ) ? $choice['stock_qty'] : null;
					$dis = ( null !== $stk && '' !== $stk && (int) $stk <= 0 ) ? ' disabled' : '';
					$sel = ( (string) $def === (string) $val ) ? ' selected' : '';
					$lab_extra = ( null !== $stk && '' !== $stk ) ? ' (' . (int) $stk . ')' : '';
					echo '<option value="' . esc_attr( $val ) . '"' . $sel . $dis . '>' . esc_html( $lab . $lab_extra ) . '</option>';
				}
				echo '</select>';
				break;
			case 'multi_select':
				$defs = is_array( $def ) ? $def : ( $def !== '' ? array( $def ) : array() );
				echo '<select name="' . esc_attr( $name ) . '[]" id="sc_option_' . esc_attr( $id ) . '" multiple size="4"' . ( $req ? ' required' : '' ) . '>';
				foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
					$val = is_array( $choice ) ? ( $choice['value'] ?? '' ) : $choice;
					$lab = is_array( $choice ) ? ( $choice['label'] ?? $val ) : $choice;
					$sel = in_array( (string) $val, array_map( 'strval', $defs ), true ) ? ' selected' : '';
					echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $lab ) . '</option>';
				}
				echo '</select>';
				break;
			case 'radio':
				foreach ( (array) ( $field['choices'] ?? array() ) as $i => $choice ) {
					$val = is_array( $choice ) ? ( $choice['value'] ?? '' ) : $choice;
					$lab = is_array( $choice ) ? ( $choice['label'] ?? $val ) : $choice;
					$chk = ( (string) $def === (string) $val ) ? ' checked' : '';
					echo '<label style="display:inline-block;margin-right:10px;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '"' . $chk . ( $req && 0 === $i ? ' required' : '' ) . ' /> ' . esc_html( $lab ) . '</label>';
				}
				break;
			case 'image_choice':
				$multi = ! empty( $field['multi'] );
				echo '<div class="sc-image-choice">';
				foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
					$val = is_array( $choice ) ? ( $choice['value'] ?? '' ) : $choice;
					$lab = is_array( $choice ) ? ( $choice['label'] ?? $val ) : $choice;
					$url = '';
					if ( is_array( $choice ) ) {
						if ( ! empty( $choice['image_url'] ) ) {
							$url = $choice['image_url'];
						} elseif ( ! empty( $choice['image_id'] ) ) {
							$url = wp_get_attachment_image_url( (int) $choice['image_id'], 'thumbnail' );
						}
					}
					$stk = is_array( $choice ) && array_key_exists( 'stock_qty', $choice ) ? $choice['stock_qty'] : null;
					$dis = ( null !== $stk && '' !== $stk && (int) $stk <= 0 ) ? ' disabled' : '';
					$iname = $multi ? ( $name . '[]' ) : $name;
					$itype = $multi ? 'checkbox' : 'radio';
					$chk   = ( ! $multi && (string) $def === (string) $val ) ? ' checked' : '';
					echo '<label><input type="' . esc_attr( $itype ) . '" name="' . esc_attr( $iname ) . '" value="' . esc_attr( $val ) . '"' . $chk . $dis . ' />';
					if ( $url ) {
						echo '<img src="' . esc_url( $url ) . '" alt="" />';
					}
					echo '<span>' . esc_html( $lab ) . '</span></label>';
				}
				echo '</div>';
				break;
			case 'textarea':
				echo '<textarea name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '" rows="3"' . ( $req ? ' required' : '' ) . $extra . '>' . esc_textarea( is_array( $def ) ? '' : (string) $def ) . '</textarea>';
				break;
			case 'file':
				echo '<input type="file" name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '"' . ( $req ? ' required' : '' ) . ' />';
				echo '<span class="sc-file-hint">' . esc_html__( 'File is stored with the order; use Customizer for live placement.', 'storecanvas' ) . '</span>';
				break;
			case 'checkbox':
				$chk = ( $def && '0' !== (string) $def ) ? ' checked' : '';
				echo '<input type="checkbox" name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '" value="1"' . $chk . ' />';
				break;
			case 'email':
			case 'phone':
			case 'tel':
			case 'number':
			case 'date':
			case 'color':
			case 'text':
			default:
				$input_type = $type;
				if ( 'phone' === $type ) {
					$input_type = 'tel';
				}
				if ( ! in_array( $input_type, array( 'number', 'date', 'color', 'text', 'email', 'tel' ), true ) ) {
					$input_type = 'text';
				}
				$val_attr = is_array( $def ) ? '' : (string) $def;
				if ( 'color' === $input_type && $val_attr && ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $val_attr ) ) {
					$val_attr = '';
				}
				echo '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" id="sc_option_' . esc_attr( $id ) . '" value="' . esc_attr( $val_attr ) . '"' . ( $req ? ' required' : '' ) . $extra . ' />';
		}
		echo '</p>';
	}
}
