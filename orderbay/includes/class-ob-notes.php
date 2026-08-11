<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canned private order note templates.
 */
class OB_Notes {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_ui' ), 40 );
		add_action( 'admin_post_ob_insert_note_template', array( $this, 'handle_insert' ) );
	}

	/**
	 * @return array[]
	 */
	public static function get_templates() {
		$raw = get_option( OB_Plugin::OPT_NOTE_TEMPLATES, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( $raw );
	}

	public function register_settings() {
		register_setting(
			'ob_notes',
			OB_Plugin::OPT_NOTE_TEMPLATES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize( $input ) {
		$out = array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$i = 0;
		foreach ( $input as $row ) {
			if ( $i >= 8 ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$body  = isset( $row['body'] ) ? sanitize_textarea_field( $row['body'] ) : '';
			if ( ! $title && ! $body ) {
				continue;
			}
			$out[] = array(
				'title' => $title ? $title : __( 'Untitled', 'orderbay' ),
				'body'  => $body,
			);
			$i++;
		}
		return $out;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$templates = self::get_templates();
		// Pad to 8 empty slots for editing.
		while ( count( $templates ) < 8 ) {
			$templates[] = array( 'title' => '', 'body' => '' );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay note templates', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Up to 8 canned private order notes. Empty slots are ignored.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_notes' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'orderbay' ) . '</th><th>' . esc_html__( 'Body', 'orderbay' ) . '</th></tr></thead><tbody>';
		foreach ( $templates as $i => $row ) {
			echo '<tr>';
			echo '<td><input type="text" class="regular-text" name="' . esc_attr( OB_Plugin::OPT_NOTE_TEMPLATES ) . '[' . (int) $i . '][title]" value="' . esc_attr( $row['title'] ?? '' ) . '" /></td>';
			echo '<td><textarea rows="2" class="large-text" name="' . esc_attr( OB_Plugin::OPT_NOTE_TEMPLATES ) . '[' . (int) $i . '][body]">' . esc_textarea( $row['body'] ?? '' ) . '</textarea></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save note templates', 'orderbay' ) );
		echo '</form></div>';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function order_ui( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$templates = self::get_templates();
		if ( ! $templates ) {
			return;
		}
		echo '<div class="ob-note-templates" style="clear:both;padding:8px 0;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
		echo '<input type="hidden" name="action" value="ob_insert_note_template" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		wp_nonce_field( 'ob_insert_note_template_' . $order->get_id() );
		echo '<label><strong>' . esc_html__( 'Insert note template', 'orderbay' ) . '</strong> ';
		echo '<select name="template_index">';
		foreach ( $templates as $i => $row ) {
			echo '<option value="' . esc_attr( (string) $i ) . '">' . esc_html( $row['title'] ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'Add private note', 'orderbay' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	public function handle_insert() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_insert_note_template_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		$idx = isset( $_POST['template_index'] ) ? absint( $_POST['template_index'] ) : 0; // phpcs:ignore
		$templates = self::get_templates();
		if ( ! isset( $templates[ $idx ] ) ) {
			wp_die( esc_html__( 'Template not found', 'orderbay' ) );
		}
		$body = $templates[ $idx ]['body'] ?? '';
		$title = $templates[ $idx ]['title'] ?? '';
		$note = $body;
		if ( $title && $body ) {
			$note = $title . "\n\n" . $body;
		} elseif ( $title ) {
			$note = $title;
		}
		if ( $note ) {
			$order->add_order_note( $note, false, true );
		}
		// HPOS-safe edit URL (post.php?post= is invalid for HPOS-stored orders).
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
