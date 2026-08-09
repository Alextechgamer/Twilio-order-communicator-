<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A) Invoice + packing slip — HTML print (browser → PDF). No heavy PDF libs.
 */
class OB_Documents {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_admin_order_actions_end', array( $this, 'list_row_actions' ), 20 );
		add_action( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_ob_print_invoice', array( $this, 'action_print_invoice' ) );
		add_action( 'woocommerce_order_action_ob_print_packing_slip', array( $this, 'action_print_packing' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_screen_buttons' ) );
		add_action( 'admin_post_ob_print_invoice', array( $this, 'handle_print_invoice' ) );
		add_action( 'admin_post_ob_print_packing_slip', array( $this, 'handle_print_packing' ) );
		add_action( 'admin_post_ob_bulk_print', array( $this, 'handle_bulk_print' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions_legacy' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions_hpos' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_legacy' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_hpos' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'bulk_empty_notice' ) );
	}

	public function register_settings() {
		register_setting(
			'ob_documents',
			OB_Plugin::OPT_DOCS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => OB_Plugin::default_doc_settings(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$out = OB_Plugin::default_doc_settings();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$out['logo_url']    = isset( $input['logo_url'] ) ? esc_url_raw( $input['logo_url'] ) : '';
		$out['from_lines']  = isset( $input['from_lines'] ) ? sanitize_textarea_field( $input['from_lines'] ) : '';
		$out['footer_text'] = isset( $input['footer_text'] ) ? sanitize_textarea_field( $input['footer_text'] ) : '';
		$paper              = isset( $input['paper'] ) ? sanitize_key( $input['paper'] ) : 'letter';
		$out['paper']       = in_array( $paper, array( 'letter', 'a4' ), true ) ? $paper : 'letter';
		$out['tax_id']      = isset( $input['tax_id'] ) ? sanitize_text_field( $input['tax_id'] ) : '';
		$out['show_thumbs']   = ! empty( $input['show_thumbs'] ) ? '1' : '0';
		$out['show_barcodes'] = ! empty( $input['show_barcodes'] ) ? '1' : '0';
		return $out;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$s = OB_Plugin::get_doc_settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay documents', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Primary path: open print view → browser Print / Save as PDF. No Dompdf/TCPDF dependency. Works offline once opened.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_documents' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="ob_logo">' . esc_html__( 'Logo URL', 'orderbay' ) . '</label></th><td>';
		echo '<input type="url" class="large-text" id="ob_logo" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[logo_url]" value="' . esc_attr( $s['logo_url'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Full URL to a logo image (optional).', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th><label for="ob_from">' . esc_html__( 'From name / address', 'orderbay' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="ob_from" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[from_lines]">' . esc_textarea( $s['from_lines'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="ob_footer">' . esc_html__( 'Footer text', 'orderbay' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="ob_footer" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[footer_text]">' . esc_textarea( $s['footer_text'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="ob_paper">' . esc_html__( 'Paper size (print CSS)', 'orderbay' ) . '</label></th><td>';
		echo '<select id="ob_paper" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[paper]">';
		echo '<option value="letter"' . selected( $s['paper'] ?? 'letter', 'letter', false ) . '>Letter</option>';
		echo '<option value="a4"' . selected( $s['paper'] ?? 'letter', 'a4', false ) . '>A4</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Applied via @page CSS in the print view. Use browser Print → Save as PDF for a PDF file.', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th><label for="ob_tax_id">' . esc_html__( 'Company VAT / Tax ID', 'orderbay' ) . '</label></th><td>';
		echo '<input type="text" id="ob_tax_id" class="regular-text" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[tax_id]" value="' . esc_attr( $s['tax_id'] ?? '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Printed on invoice header when set.', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Packing slip thumbnails', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_thumbs]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_thumbs]" value="1" ' . checked( ( $s['show_thumbs'] ?? '0' ), '1', false ) . ' /> ';
		echo esc_html__( 'Show product image column (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Barcodes on documents', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_barcodes]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_barcodes]" value="1" ' . checked( ( $s['show_barcodes'] ?? '0' ), '1', false ) . ' /> ';
		echo esc_html__( 'Print Code 128 barcode (SVG) for order number on invoice, packing slip, and RMA (default off; pure PHP, no Composer)', 'orderbay' ) . '</label></td></tr>';
		echo '</table>';

		echo '<table class="form-table"><tr><th><label for="ob_inv_prefix">' . esc_html__( 'Invoice number prefix', 'orderbay' ) . '</label></th><td>';
		echo '<input type="text" id="ob_inv_prefix" name="ob_invoice_prefix" value="' . esc_attr( get_option( OB_Plugin::OPT_INVOICE_PREFIX, 'INV-' ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Default INV-. Existing order invoice numbers are never renumbered.', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th><label for="ob_inv_next">' . esc_html__( 'Next invoice sequence', 'orderbay' ) . '</label></th><td>';
		echo '<input type="number" min="1" id="ob_inv_next" name="ob_invoice_next" value="' . esc_attr( (string) max( 1, (int) get_option( OB_Plugin::OPT_INVOICE_NEXT, 1 ) ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Admin only: sets the next number to assign. Does not change past invoices.', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th><label for="ob_cn_prefix">' . esc_html__( 'Credit note prefix', 'orderbay' ) . '</label></th><td>';
		echo '<input type="text" id="ob_cn_prefix" name="ob_credit_prefix" value="' . esc_attr( get_option( OB_Plugin::OPT_CREDIT_PREFIX, 'CN-' ) ) . '" /></td></tr>';
		echo '<tr><th><label for="ob_cn_next">' . esc_html__( 'Next credit note sequence', 'orderbay' ) . '</label></th><td>';
		echo '<input type="number" min="1" id="ob_cn_next" name="ob_credit_next" value="' . esc_attr( (string) max( 1, (int) get_option( OB_Plugin::OPT_CREDIT_NEXT, 1 ) ) ) . '" /></td></tr>';
		echo '</table>';

		submit_button( __( 'Save document settings', 'orderbay' ) );
		echo '</form></div>';
	}

	public function order_actions( $actions ) {
		$actions['ob_print_invoice']      = __( 'Orderbay: print invoice', 'orderbay' );
		$actions['ob_print_packing_slip'] = __( 'Orderbay: print packing slip', 'orderbay' );
		return $actions;
	}

	public function action_print_invoice( $order ) {
		$url = $this->print_url( $order->get_id(), 'invoice' );
		$order->add_order_note( sprintf( __( 'Orderbay invoice print view: %s', 'orderbay' ), $url ), false, true );
	}

	public function action_print_packing( $order ) {
		$url = $this->print_url( $order->get_id(), 'packing' );
		$order->add_order_note( sprintf( __( 'Orderbay packing slip print view: %s', 'orderbay' ), $url ), false, true );
	}

	/**
	 * Buttons on order edit screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_screen_buttons( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$inv  = $this->print_url( $order->get_id(), 'invoice' );
		$pack = $this->print_url( $order->get_id(), 'packing' );
		$inv_no = $order->get_meta( OB_Plugin::META_INVOICE_NUMBER );
		if ( $inv_no ) {
			echo '<p class="form-field" style="clear:both;padding-left:0;"><strong>' . esc_html__( 'Invoice #', 'orderbay' ) . ':</strong> ' . esc_html( $inv_no ) . '</p>';
		}
		echo '<p class="form-field" style="clear:both;padding-left:0;">';
		echo '<a class="button button-primary" target="_blank" href="' . esc_url( $inv ) . '">' . esc_html__( 'Print invoice', 'orderbay' ) . '</a> ';
		echo '<a class="button" target="_blank" href="' . esc_url( $pack ) . '">' . esc_html__( 'Print packing slip', 'orderbay' ) . '</a> ';
		// "Download PDF" = same print view (browser Save as PDF); no server PDF lib.
		echo '<a class="button" target="_blank" href="' . esc_url( $inv ) . '">' . esc_html__( 'Open PDF print view', 'orderbay' ) . '</a>';
		echo '<span class="description" style="display:block;margin-top:4px;">' . esc_html__( 'PDF: open print view → browser Print → Save as PDF. No server-side PDF library required.', 'orderbay' ) . '</span>';
		echo '</p>';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function list_row_actions( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		printf(
			'<a class="button wc-action-button ob-print-invoice" href="%s" target="_blank" title="%s">%s</a> ',
			esc_url( $this->print_url( $order->get_id(), 'invoice' ) ),
			esc_attr__( 'Invoice', 'orderbay' ),
			esc_html__( 'Inv', 'orderbay' )
		);
		printf(
			'<a class="button wc-action-button ob-print-packing" href="%s" target="_blank" title="%s">%s</a>',
			esc_url( $this->print_url( $order->get_id(), 'packing' ) ),
			esc_attr__( 'Packing slip', 'orderbay' ),
			esc_html__( 'Pack', 'orderbay' )
		);
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $type invoice|packing.
	 * @return string
	 */
	public function print_url( $order_id, $type = 'invoice' ) {
		$action = ( 'packing' === $type ) ? 'ob_print_packing_slip' : 'ob_print_invoice';
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&order_id=' . absint( $order_id ) ),
			$action . '_' . absint( $order_id )
		);
	}

	public function handle_print_invoice() {
		$this->render_document( 'invoice' );
	}

	public function handle_print_packing() {
		$this->render_document( 'packing' );
	}

	/**
	 * @param string $type invoice|packing.
	 */
	private function render_document( $type ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		$action   = ( 'packing' === $type ) ? 'ob_print_packing_slip' : 'ob_print_invoice';
		check_admin_referer( $action . '_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		$settings = OB_Plugin::get_doc_settings();
		if ( 'invoice' === $type && class_exists( 'OB_Invoicing' ) ) {
			OB_Invoicing::ensure_invoice_number( $order );
		}
		$orders   = array( $order );
		$template = ( 'packing' === $type ) ? 'packing-slip.php' : 'invoice.php';
		include OB_PLUGIN_DIR . 'templates/' . $template;
		exit;
	}

	public function bulk_actions_legacy( $actions ) {
		$actions['ob_print_invoices'] = __( 'Print Orderbay invoices', 'orderbay' );
		$actions['ob_print_packing']  = __( 'Print Orderbay packing slips', 'orderbay' );
		return $actions;
	}

	public function bulk_actions_hpos( $actions ) {
		return $this->bulk_actions_legacy( $actions );
	}

	public function handle_bulk_legacy( $redirect, $action, $ids ) {
		return $this->handle_bulk( $redirect, $action, $ids );
	}

	public function handle_bulk_hpos( $redirect, $action, $ids ) {
		return $this->handle_bulk( $redirect, $action, $ids );
	}

	private function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'ob_print_invoices', 'ob_print_packing' ), true ) ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$type = ( 'ob_print_packing' === $action ) ? 'packing' : 'invoice';
		$ids  = array_map( 'absint', (array) $ids );
		$ids  = array_filter( $ids );
		if ( ! $ids ) {
			return add_query_arg( 'ob_bulk_empty', '1', $redirect );
		}
		// Cap bulk print to keep browser print usable.
		$ids = array_slice( $ids, 0, 50 );
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ob_bulk_print&type=' . rawurlencode( $type ) . '&ids=' . implode( ',', $ids ) ),
			'ob_bulk_print'
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_bulk_print() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		check_admin_referer( 'ob_bulk_print' );
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'invoice'; // phpcs:ignore
		$raw  = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ''; // phpcs:ignore
		$ids  = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		$orders = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$orders[] = $o;
			}
		}
		if ( ! $orders ) {
			wp_die( esc_html__( 'No orders selected.', 'orderbay' ) );
		}
		$settings = OB_Plugin::get_doc_settings();
		if ( 'invoice' === $type && class_exists( 'OB_Invoicing' ) ) {
			foreach ( $orders as $o ) {
				OB_Invoicing::ensure_invoice_number( $o );
			}
		}
		$template = ( 'packing' === $type ) ? 'packing-slip.php' : 'invoice.php';
		include OB_PLUGIN_DIR . 'templates/' . $template;
		exit;
	}

	public function bulk_empty_notice() {
		if ( empty( $_GET['ob_bulk_empty'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Orderbay: no orders selected for bulk print.', 'orderbay' ) . '</p></div>';
	}
}
