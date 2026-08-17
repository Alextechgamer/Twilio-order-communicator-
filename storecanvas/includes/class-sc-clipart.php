<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clip-art / design library (0.7.0).
 */
class SC_Clipart {

	const CPT = 'sc_clipart';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_clipart' ), 10, 2 );
		add_action( 'wp_ajax_sc_library_items', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_nopriv_sc_library_items', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sc_list_library', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_nopriv_sc_list_library', array( $this, 'ajax_list' ) );
	}

	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Library', 'storecanvas' ),
					'singular_name' => __( 'Clip-art', 'storecanvas' ),
					'add_new_item'  => __( 'Add clip-art', 'storecanvas' ),
					'edit_item'     => __( 'Edit clip-art', 'storecanvas' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => SC_Plugin::MENU_SLUG,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'thumbnail' ),
				'exclude_from_search' => true,
				'menu_icon'           => 'dashicons-images-alt2',
			)
		);
		register_taxonomy(
			'sc_clipart_tag',
			self::CPT,
			array(
				'labels'       => array( 'name' => __( 'Clip-art tags', 'storecanvas' ) ),
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => false,
			)
		);
	}

	public function meta_boxes() {
		add_meta_box(
			'sc_clipart_media',
			__( 'Artwork file', 'storecanvas' ),
			array( $this, 'render_media_box' ),
			self::CPT,
			'normal',
			'high'
		);
	}

	public function render_media_box( $post ) {
		wp_nonce_field( 'sc_clipart_save', 'sc_clipart_nonce' );
		$att_id = (int) get_post_meta( $post->ID, '_sc_clipart_attachment', true );
		if ( ! $att_id && has_post_thumbnail( $post ) ) {
			$att_id = (int) get_post_thumbnail_id( $post );
		}
		$url = $att_id ? wp_get_attachment_url( $att_id ) : '';
		echo '<p><label>' . esc_html__( 'Attachment ID', 'storecanvas' ) . ' ';
		echo '<input type="number" name="sc_clipart_attachment" value="' . esc_attr( (string) $att_id ) . '" min="0" step="1" /></label></p>';
		echo '<p class="description">' . esc_html__( 'Upload via Media Library and paste the attachment ID, or set the Featured Image (PNG/JPEG/SVG).', 'storecanvas' ) . '</p>';
		if ( $url ) {
			echo '<p><img src="' . esc_url( $url ) . '" alt="" style="max-width:200px;height:auto;border:1px solid #ddd;" /></p>';
		}
	}

	public function save_clipart( $post_id, $post ) {
		if ( ! isset( $_POST['sc_clipart_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sc_clipart_nonce'] ) ), 'sc_clipart_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$att = isset( $_POST['sc_clipart_attachment'] ) ? absint( $_POST['sc_clipart_attachment'] ) : 0;
		if ( ! $att && has_post_thumbnail( $post_id ) ) {
			$att = (int) get_post_thumbnail_id( $post_id );
		}
		update_post_meta( $post_id, '_sc_clipart_attachment', $att );
		if ( $att && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $att );
		}
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_for_product( $product_id ) {
		$allow = get_post_meta( $product_id, SC_Plugin::META_CLIPART, true );
		$args  = array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( is_array( $allow ) && ! empty( $allow ) ) {
			$args['post__in'] = array_map( 'absint', $allow );
			$args['orderby']  = 'post__in';
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $post ) {
			$att = (int) get_post_meta( $post->ID, '_sc_clipart_attachment', true );
			if ( ! $att ) {
				$att = (int) get_post_thumbnail_id( $post );
			}
			if ( ! $att ) {
				continue;
			}
			$url   = wp_get_attachment_url( $att );
			$thumb = wp_get_attachment_image_url( $att, 'thumbnail' );
			if ( ! $url ) {
				continue;
			}
			$tags = wp_get_post_terms( $post->ID, 'sc_clipart_tag', array( 'fields' => 'names' ) );
			if ( is_wp_error( $tags ) ) {
				$tags = array();
			}
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
				'url'   => $url,
				'thumb' => $thumb ? $thumb : $url,
				'tags'  => array_values( (array) $tags ),
			);
		}
		return $items;
	}

	public function ajax_list() {
		check_ajax_referer( 'sc_library', 'nonce' );
		$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'missing product' ), 400 );
		}
		wp_send_json_success( array( 'items' => self::get_for_product( $product_id ) ) );
	}

	/**
	 * @return array
	 */
	public static function all_published() {
		$q   = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $q->posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
			);
		}
		return $out;
	}
}
