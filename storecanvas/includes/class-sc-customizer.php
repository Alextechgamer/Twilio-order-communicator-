<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase B: live mockup — views, print areas, canvas glue.
 * Scaffold: config load/save shape + product panel shell + front template.
 */
class SC_Customizer {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_panel' ), 20 );
		add_filter( 'post_class', array( $this, 'ensure_multipart_hint' ), 10, 3 );
		add_action( 'wp_footer', array( $this, 'force_multipart_script' ), 30 );
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_config( $product_id ) {
		$raw = get_post_meta( $product_id, SC_Plugin::META_CUSTOMIZER, true );
		if ( ! is_array( $raw ) ) {
			return SC_Plugin::empty_customizer();
		}
		$raw = wp_parse_args( $raw, SC_Plugin::empty_customizer() );
		return $raw;
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_validation( $product_id ) {
		$raw = get_post_meta( $product_id, SC_Plugin::META_VALIDATION, true );
		if ( ! is_array( $raw ) ) {
			return SC_Plugin::default_validation();
		}
		return wp_parse_args( $raw, SC_Plugin::default_validation() );
	}

	/**
	 * Product-page customizer panel (canvas shell).
	 */
	public function render_panel() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$config = self::get_config( $product->get_id() );
		if ( empty( $config['enabled'] ) ) {
			return;
		}

		$views = array();
		foreach ( (array) $config['views'] as $view ) {
			$img_id = isset( $view['image_id'] ) ? (int) $view['image_id'] : 0;
			$url    = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : '';
			$views[] = array(
				'id'    => $view['id'] ?? '',
				'label' => $view['label'] ?? '',
				'url'   => $url,
			);
		}

		$areas = array_values( (array) ( $config['areas'] ?? array() ) );
		$validation = self::get_validation( $product->get_id() );

		$path = SC_PLUGIN_DIR . 'templates/customizer-panel.php';
		if ( file_exists( $path ) ) {
			include $path;
		}
	}

	/**
	 * Footer script: ensure add-to-cart form is multipart for artwork upload.
	 */
	public function force_multipart_script() {
		if ( ! is_product() ) {
			return;
		}
		?>
		<script>
		(function(){
			var f = document.querySelector('form.cart');
			if (f) { f.setAttribute('enctype','multipart/form-data'); f.setAttribute('encoding','multipart/form-data'); }
		})();
		</script>
		<?php
	}

	public function ensure_multipart_hint( $classes, $class, $post_id ) {
		return $classes;
	}
}
