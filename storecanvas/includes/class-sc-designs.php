<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved designs for logged-in customers (0.5.0).
 */
class SC_Designs {

	const CPT = 'sc_design';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'wp_ajax_sc_save_design', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_sc_list_designs', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sc_load_design', array( $this, 'ajax_load' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_save_ui' ), 25 );
	}

	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Saved designs', 'storecanvas' ),
					'singular_name' => __( 'Saved design', 'storecanvas' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'author' ),
				'exclude_from_search' => true,
			)
		);
	}

	public function render_save_ui() {
		if ( ! is_user_logged_in() || ! is_product() ) {
			return;
		}
		echo '<div class="sc-saved-designs" style="margin:12px 0;">';
		echo '<button type="button" class="button" id="sc-save-design">' . esc_html__( 'Save this design', 'storecanvas' ) . '</button> ';
		echo '<button type="button" class="button" id="sc-load-designs">' . esc_html__( 'My designs', 'storecanvas' ) . '</button>';
		echo '<div id="sc-designs-list" style="margin-top:8px;"></div>';
		echo '</div>';
	}

	public function ajax_save() {
		check_ajax_referer( 'sc_designs', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login required' ), 403 );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$payload    = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( ! $product_id || ! $payload ) {
			wp_send_json_error( array( 'message' => 'missing data' ), 400 );
		}
		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => 'bad payload' ), 400 );
		}
		if ( ! $title ) {
			$title = sprintf( __( 'Design for product #%d – %s', 'storecanvas' ), $product_id, wp_date( 'Y-m-d H:i' ) );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}
		update_post_meta( $post_id, '_sc_product_id', $product_id );
		update_post_meta( $post_id, '_sc_design_payload', $decoded );
		wp_send_json_success( array( 'id' => $post_id, 'title' => $title ) );
	}

	public function ajax_list() {
		check_ajax_referer( 'sc_designs', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login required' ), 403 );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$args       = array(
			'post_type'      => self::CPT,
			'author'         => get_current_user_id(),
			'posts_per_page' => 20,
			'post_status'    => 'publish',
		);
		if ( $product_id ) {
			$args['meta_key']   = '_sc_product_id';
			$args['meta_value'] = $product_id;
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'date'  => $post->post_date,
			);
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	public function ajax_load() {
		check_ajax_referer( 'sc_designs', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login required' ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type || (int) $post->post_author !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}
		wp_send_json_success(
			array(
				'payload'    => get_post_meta( $id, '_sc_design_payload', true ),
				'product_id' => (int) get_post_meta( $id, '_sc_product_id', true ),
			)
		);
	}
}
