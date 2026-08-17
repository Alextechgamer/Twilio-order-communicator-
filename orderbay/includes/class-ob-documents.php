<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document print views: invoice, packing, proforma, delivery note, shipping label.
 * Primary: browser Print → Save as PDF. Optional Dompdf/TCPDF if present on host.
 */
class OB_Documents {

	private static $instance = null;

	/** @var string[] Supported print types. */
	const TYPES = array( 'invoice', 'packing', 'proforma', 'delivery', 'label' );

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
		add_action( 'woocommerce_order_action_ob_print_proforma', array( $this, 'action_print_proforma' ) );
		add_action( 'woocommerce_order_action_ob_print_delivery', array( $this, 'action_print_delivery' ) );
		add_action( 'woocommerce_order_action_ob_print_label', array( $this, 'action_print_label' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_screen_buttons' ) );
		add_action( 'admin_post_ob_print_invoice', array( $this, 'handle_print_invoice' ) );
		add_action( 'admin_post_ob_print_packing_slip', array( $this, 'handle_print_packing' ) );
		add_action( 'admin_post_ob_print_proforma', array( $this, 'handle_print_proforma' ) );
		add_action( 'admin_post_ob_print_delivery', array( $this, 'handle_print_delivery' ) );
		add_action( 'admin_post_ob_print_label', array( $this, 'handle_print_label' ) );
		add_action( 'admin_post_ob_bulk_print', array( $this, 'handle_bulk_print' ) );
		add_action( 'admin_post_ob_pdf_download', array( $this, 'handle_pdf_download' ) );
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
		$out['seller_country'] = isset( $input['seller_country'] ) ? strtoupper( substr( sanitize_text_field( $input['seller_country'] ), 0, 2 ) ) : '';
		$out['show_thumbs']     = ! empty( $input['show_thumbs'] ) ? '1' : '0';
		$out['show_barcodes']   = ! empty( $input['show_barcodes'] ) ? '1' : '0';
		$out['qr_enabled']      = ! empty( $input['qr_enabled'] ) ? '1' : '0';
		$out['delivery_prices'] = ! empty( $input['delivery_prices'] ) ? '1' : '0';
		$engine                 = isset( $input['pdf_engine'] ) ? sanitize_key( $input['pdf_engine'] ) : 'browser';
		$out['pdf_engine']      = in_array( $engine, array( 'browser', 'auto' ), true ) ? $engine : 'browser';
		return $out;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	/**
	 * Whether host has Dompdf or TCPDF available.
	 *
	 * @return string|false 'dompdf'|'tcpdf'|false
	 */
	public static function detect_pdf_engine() {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return 'dompdf';
		}
		if ( class_exists( 'TCPDF' ) ) {
			return 'tcpdf';
		}
		return false;
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$s = OB_Plugin::get_doc_settings();
		$eng = self::detect_pdf_engine();
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay documents', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Primary path: open print view → browser Print / Save as PDF. No Dompdf/TCPDF bundled. Bulk print requires edit_shop_orders. Documents: invoice, proforma, packing slip, delivery note, shipping label.', 'orderbay' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Not tax advice. Invoice layout, numbering, VAT lines, and e-invoice files are tools — confirm legal requirements with your accountant or tax adviser before using them in production.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_documents' );
		echo '<h2>' . esc_html__( 'Document appearance', 'orderbay' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="ob_logo">' . esc_html__( 'Logo URL', 'orderbay' ) . '</label></th><td>';
		echo '<input type="url" class="large-text" id="ob_logo" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[logo_url]" value="' . esc_attr( $s['logo_url'] ) . '" /></td></tr>';
		echo '<tr><th><label for="ob_from">' . esc_html__( 'From name / address', 'orderbay' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="ob_from" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[from_lines]">' . esc_textarea( $s['from_lines'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="ob_footer">' . esc_html__( 'Footer text', 'orderbay' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="ob_footer" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[footer_text]">' . esc_textarea( $s['footer_text'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="ob_paper">' . esc_html__( 'Paper size', 'orderbay' ) . '</label></th><td>';
		echo '<select id="ob_paper" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[paper]">';
		echo '<option value="letter"' . selected( $s['paper'] ?? 'letter', 'letter', false ) . '>Letter</option>';
		echo '<option value="a4"' . selected( $s['paper'] ?? 'letter', 'a4', false ) . '>A4</option>';
		echo '</select></td></tr>';
		echo '<tr><th><label for="ob_tax_id">' . esc_html__( 'Company VAT / Tax ID', 'orderbay' ) . '</label></th><td>';
		echo '<input type="text" id="ob_tax_id" class="regular-text" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[tax_id]" value="' . esc_attr( $s['tax_id'] ?? '' ) . '" /></td></tr>';
		echo '<tr><th><label for="ob_seller_country">' . esc_html__( 'Seller country (ISO)', 'orderbay' ) . '</label></th><td>';
		echo '<input type="text" id="ob_seller_country" class="small-text" maxlength="2" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[seller_country]" value="' . esc_attr( $s['seller_country'] ?? '' ) . '" placeholder="' . esc_attr( strtoupper( substr( (string) get_option( 'woocommerce_default_country', '' ), 0, 2 ) ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'For e-invoicing. Blank = WooCommerce store country.', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Packing slip thumbnails', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_thumbs]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_thumbs]" value="1" ' . checked( ( $s['show_thumbs'] ?? '0' ), '1', false ) . ' /> ';
		echo esc_html__( 'Show product image column (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Barcodes', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_barcodes]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[show_barcodes]" value="1" ' . checked( ( $s['show_barcodes'] ?? '0' ), '1', false ) . ' /> ';
		echo esc_html__( 'Code 128 SVG on invoice / packing / labels (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'QR codes', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[qr_enabled]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[qr_enabled]" value="1" ' . checked( ( $s['qr_enabled'] ?? '0' ), '1', false ) . ' /> ';
		echo esc_html__( 'Show order QR on invoice + packing slip (default off)', 'orderbay' ) . '</label>';
		if ( class_exists( 'OB_QR' ) && OB_QR::library_available() ) {
			echo '<p class="description" style="color:#1a7f37;">' . esc_html__( 'A QR library is installed — order QR codes are rendered through it (scannable for full order URLs).', 'orderbay' ) . '</p></td></tr>';
		} else {
			echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'No QR library installed: QR codes are not rendered on documents. Install chillerlan/php-qrcode (or endroid/qr-code) via Composer for reliable QR. The bundled experimental encoder (short payloads only, up to ~42 bytes) is disabled unless you define OB_QR_BUILTIN_ENCODER as true in wp-config.php. The Code 128 barcode above is production-ready.', 'orderbay' ) . '</p></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Delivery note prices', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[delivery_prices]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[delivery_prices]" value="1" ' . checked( ( $s['delivery_prices'] ?? '0' ), '1', false ) . ' /> ';
		echo esc_html__( 'Show prices on delivery notes (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'PDF engine', 'orderbay' ) . '</th><td>';
		echo '<select name="' . esc_attr( OB_Plugin::OPT_DOCS ) . '[pdf_engine]">';
		echo '<option value="browser"' . selected( $s['pdf_engine'] ?? 'browser', 'browser', false ) . '>' . esc_html__( 'Browser only (Print → Save as PDF)', 'orderbay' ) . '</option>';
		echo '<option value="auto"' . selected( $s['pdf_engine'] ?? 'browser', 'auto', false ) . '>' . esc_html__( 'Auto: Dompdf/TCPDF if already installed on host', 'orderbay' ) . '</option>';
		echo '</select>';
		echo '<p class="description">';
		if ( $eng ) {
			echo esc_html( sprintf( __( 'Detected on this host: %s. Download PDF button appears when engine is Auto.', 'orderbay' ), $eng ) );
		} else {
			echo esc_html__( 'No Dompdf/TCPDF detected. Orderbay does not bundle PDF libraries.', 'orderbay' );
		}
		echo '</p></td></tr>';
		echo '</table>';

		if ( class_exists( 'OB_EInvoice' ) ) {
			echo '<h2>' . esc_html__( 'E-invoicing (UBL / Factur-X)', 'orderbay' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Download UBL (Peppol BIS 3.0) or CII (Factur-X EN16931) invoice XML from the order screen. Export only — Orderbay does not connect to the Peppol network or any PDP/access point. EN16931 baseline: validate against an official validator before relying on it. A "Factur-X PDF" button also appears when a PDF engine (Dompdf/TCPDF) and the horstoeko/zugferd library are installed on the host; its output must pass a Factur-X validator.', 'orderbay' ) . '</p>';
			$seller_issues = OB_EInvoice::seller_issues( OB_EInvoice::seller_data() );
			if ( $seller_issues ) {
				echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Seller details needed for compliant e-invoices:', 'orderbay' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
				foreach ( $seller_issues as $iss ) {
					echo '<li>' . esc_html( $iss ) . '</li>';
				}
				echo '</ul></div>';
			} else {
				echo '<p style="color:#1a7f37;">' . esc_html__( 'Seller details look complete for e-invoicing.', 'orderbay' ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Invoice, proforma & credit note numbers', 'orderbay' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Format tokens: {PREFIX} {YYYY} {YY} {MM} {DD} {SEQ} {SEQ:5} (zero-padded). Default {PREFIX}{SEQ} keeps existing numbers. A sequence token is always enforced. Yearly/monthly reset restarts the counter at 1 each period.', 'orderbay' ) . '</p>';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Invoice prefix', 'orderbay' ) . '</th><td><input type="text" name="ob_invoice_prefix" value="' . esc_attr( get_option( OB_Plugin::OPT_INVOICE_PREFIX, 'INV-' ) ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Next invoice #', 'orderbay' ) . '</th><td><input type="number" min="1" name="ob_invoice_next" value="' . esc_attr( (string) max( 1, (int) get_option( OB_Plugin::OPT_INVOICE_NEXT, 1 ) ) ) . '" /></td></tr>';
		$this->numbering_format_rows( __( 'Invoice', 'orderbay' ), OB_Plugin::OPT_INVOICE_FORMAT, OB_Plugin::OPT_INVOICE_RESET );
		echo '<tr><th>' . esc_html__( 'Proforma prefix', 'orderbay' ) . '</th><td><input type="text" name="ob_proforma_prefix" value="' . esc_attr( get_option( OB_Plugin::OPT_PROFORMA_PREFIX, 'PRO-' ) ) . '" /><p class="description">' . esc_html__( 'Immutable once assigned per order (_ob_proforma_number).', 'orderbay' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Next proforma #', 'orderbay' ) . '</th><td><input type="number" min="1" name="ob_proforma_next" value="' . esc_attr( (string) max( 1, (int) get_option( OB_Plugin::OPT_PROFORMA_NEXT, 1 ) ) ) . '" /></td></tr>';
		$this->numbering_format_rows( __( 'Proforma', 'orderbay' ), OB_Plugin::OPT_PROFORMA_FORMAT, OB_Plugin::OPT_PROFORMA_RESET );
		echo '<tr><th>' . esc_html__( 'Credit note prefix', 'orderbay' ) . '</th><td><input type="text" name="ob_credit_prefix" value="' . esc_attr( get_option( OB_Plugin::OPT_CREDIT_PREFIX, 'CN-' ) ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Next credit #', 'orderbay' ) . '</th><td><input type="number" min="1" name="ob_credit_next" value="' . esc_attr( (string) max( 1, (int) get_option( OB_Plugin::OPT_CREDIT_NEXT, 1 ) ) ) . '" /></td></tr>';
		$this->numbering_format_rows( __( 'Credit note', 'orderbay' ), OB_Plugin::OPT_CREDIT_FORMAT, OB_Plugin::OPT_CREDIT_RESET );
		echo '</table>';

		submit_button( __( 'Save document settings', 'orderbay' ) );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Template customization', 'orderbay' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Override any document template without editing the plugin: copy a file from the plugin\'s templates/ folder into your theme at wp-content/themes/your-theme/orderbay/ (e.g. orderbay/invoice.php). Your copy survives plugin updates. The ob_before_document / ob_after_document actions and the ob_locate_template filter are also available.', 'orderbay' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the format-template + reset-period rows for one document type.
	 *
	 * @param string $label      Human label for the document type.
	 * @param string $opt_format Format option key.
	 * @param string $opt_reset  Reset-period option key.
	 */
	private function numbering_format_rows( $label, $opt_format, $opt_reset ) {
		$format = (string) get_option( $opt_format, '{PREFIX}{SEQ}' );
		if ( '' === $format ) {
			$format = '{PREFIX}{SEQ}';
		}
		$reset = (string) get_option( $opt_reset, 'none' );

		echo '<tr><th>' . esc_html( sprintf( /* translators: %s: document type. */ __( '%s number format', 'orderbay' ), $label ) ) . '</th><td>';
		echo '<input type="text" name="' . esc_attr( $opt_format ) . '" value="' . esc_attr( $format ) . '" class="regular-text" /></td></tr>';

		echo '<tr><th>' . esc_html( sprintf( /* translators: %s: document type. */ __( '%s counter reset', 'orderbay' ), $label ) ) . '</th><td>';
		echo '<select name="' . esc_attr( $opt_reset ) . '">';
		$options = array(
			'none'    => __( 'Never (continuous)', 'orderbay' ),
			'yearly'  => __( 'Yearly (restart at 1 each year)', 'orderbay' ),
			'monthly' => __( 'Monthly (restart at 1 each month)', 'orderbay' ),
		);
		foreach ( $options as $val => $text ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $reset, $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * Normalize WooCommerce tax totals into simple per-rate rows for document rendering (pure).
	 * Accepts the array returned by WC_Order::get_tax_totals() (objects with ->label/->amount)
	 * or equivalent arrays, so it can be unit-tested without a WooCommerce runtime.
	 *
	 * @param mixed $tax_totals Iterable of tax totals.
	 * @return array<int,array{label:string,amount:float}>
	 */
	public static function normalize_tax_rows( $tax_totals ) {
		$rows = array();
		if ( ! is_array( $tax_totals ) ) {
			return $rows;
		}
		foreach ( $tax_totals as $t ) {
			if ( is_object( $t ) ) {
				$label  = isset( $t->label ) ? (string) $t->label : '';
				$amount = isset( $t->amount ) ? (float) $t->amount : 0.0;
			} elseif ( is_array( $t ) ) {
				$label  = isset( $t['label'] ) ? (string) $t['label'] : '';
				$amount = isset( $t['amount'] ) ? (float) $t['amount'] : 0.0;
			} else {
				continue;
			}
			if ( '' === $label ) {
				$label = __( 'Tax', 'orderbay' );
			}
			$rows[] = array(
				'label'  => $label,
				'amount' => $amount,
			);
		}
		return $rows;
	}

	/**
	 * Ordered theme→plugin candidate paths for a document template (pure).
	 * A store can override any template by copying it into `wp-content/themes/<theme>/orderbay/`.
	 *
	 * @param string $name           Template filename (e.g. 'invoice.php').
	 * @param string $stylesheet_dir Active (child) theme directory.
	 * @param string $template_dir   Parent theme directory.
	 * @param string $plugin_dir     Plugin templates directory.
	 * @return string[]
	 */
	public static function template_candidates( $name, $stylesheet_dir, $template_dir, $plugin_dir ) {
		$name   = basename( (string) $name );
		$subdir = 'orderbay/';
		$out    = array();
		if ( $stylesheet_dir ) {
			$out[] = rtrim( $stylesheet_dir, '/' ) . '/' . $subdir . $name;
		}
		if ( $template_dir && rtrim( $template_dir, '/' ) !== rtrim( (string) $stylesheet_dir, '/' ) ) {
			$out[] = rtrim( $template_dir, '/' ) . '/' . $subdir . $name;
		}
		$out[] = rtrim( $plugin_dir, '/' ) . '/' . $name;
		return $out;
	}

	/**
	 * Resolve a document template path, preferring a theme override, then the plugin default.
	 * The final path is filterable via `ob_locate_template`.
	 *
	 * @param string $name Template filename (basename only; traversal is stripped).
	 * @return string Absolute path.
	 */
	public static function locate_template( $name ) {
		$name       = basename( (string) $name );
		$candidates = self::template_candidates(
			$name,
			function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
			function_exists( 'get_template_directory' ) ? get_template_directory() : '',
			rtrim( OB_PLUGIN_DIR, '/' ) . '/templates'
		);
		$found = '';
		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				$found = $path;
				break;
			}
		}
		if ( '' === $found ) {
			$found = rtrim( OB_PLUGIN_DIR, '/' ) . '/templates/' . $name;
		}
		/**
		 * Filter the resolved OrderBay document template path.
		 *
		 * @param string $found Absolute path.
		 * @param string $name  Template filename.
		 */
		return apply_filters( 'ob_locate_template', $found, $name );
	}

	public function order_actions( $actions ) {
		$actions['ob_print_invoice']      = __( 'Orderbay: print invoice', 'orderbay' );
		$actions['ob_print_proforma']     = __( 'Orderbay: print proforma', 'orderbay' );
		$actions['ob_print_packing_slip'] = __( 'Orderbay: print packing slip', 'orderbay' );
		$actions['ob_print_delivery']     = __( 'Orderbay: print delivery note', 'orderbay' );
		$actions['ob_print_label']        = __( 'Orderbay: print shipping label', 'orderbay' );
		return $actions;
	}

	public function action_print_invoice( $order ) {
		$order->add_order_note( sprintf( __( 'Orderbay invoice: %s', 'orderbay' ), $this->print_url( $order->get_id(), 'invoice' ) ), false, true );
	}
	public function action_print_packing( $order ) {
		$order->add_order_note( sprintf( __( 'Orderbay packing: %s', 'orderbay' ), $this->print_url( $order->get_id(), 'packing' ) ), false, true );
	}
	public function action_print_proforma( $order ) {
		$order->add_order_note( sprintf( __( 'Orderbay proforma: %s', 'orderbay' ), $this->print_url( $order->get_id(), 'proforma' ) ), false, true );
	}
	public function action_print_delivery( $order ) {
		$order->add_order_note( sprintf( __( 'Orderbay delivery note: %s', 'orderbay' ), $this->print_url( $order->get_id(), 'delivery' ) ), false, true );
	}
	public function action_print_label( $order ) {
		$order->add_order_note( sprintf( __( 'Orderbay shipping label: %s', 'orderbay' ), $this->print_url( $order->get_id(), 'label' ) ), false, true );
	}

	public function order_screen_buttons( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$inv_no = $order->get_meta( OB_Plugin::META_INVOICE_NUMBER );
		$pro_no = $order->get_meta( OB_Plugin::META_PROFORMA_NUMBER );
		if ( $inv_no ) {
			echo '<p class="form-field" style="clear:both;padding-left:0;"><strong>' . esc_html__( 'Invoice #', 'orderbay' ) . ':</strong> ' . esc_html( $inv_no ) . '</p>';
		}
		if ( $pro_no ) {
			echo '<p class="form-field" style="clear:both;padding-left:0;"><strong>' . esc_html__( 'Proforma #', 'orderbay' ) . ':</strong> ' . esc_html( $pro_no ) . '</p>';
		}
		echo '<p class="form-field" style="clear:both;padding-left:0;">';
		foreach ( array(
			'invoice'  => __( 'Invoice', 'orderbay' ),
			'proforma' => __( 'Proforma', 'orderbay' ),
			'packing'  => __( 'Packing slip', 'orderbay' ),
			'delivery' => __( 'Delivery note', 'orderbay' ),
			'label'    => __( 'Shipping label', 'orderbay' ),
		) as $type => $label ) {
			$class = ( 'invoice' === $type ) ? 'button button-primary' : 'button';
			echo '<a class="' . esc_attr( $class ) . '" target="_blank" href="' . esc_url( $this->print_url( $order->get_id(), $type ) ) . '" style="margin:0 4px 4px 0;">' . esc_html( $label ) . '</a> ';
		}
		$s = OB_Plugin::get_doc_settings();
		if ( 'auto' === ( $s['pdf_engine'] ?? 'browser' ) && self::detect_pdf_engine() ) {
			echo '<a class="button" href="' . esc_url( $this->pdf_url( $order->get_id(), 'invoice' ) ) . '">' . esc_html__( 'Download PDF (host engine)', 'orderbay' ) . '</a> ';
		}
		if ( class_exists( 'OB_EInvoice' ) ) {
			echo '<a class="button" href="' . esc_url( OB_EInvoice::download_url( $order->get_id(), 'ubl' ) ) . '">' . esc_html__( 'E-invoice UBL', 'orderbay' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( OB_EInvoice::download_url( $order->get_id(), 'cii' ) ) . '">' . esc_html__( 'E-invoice CII (Factur-X)', 'orderbay' ) . '</a> ';
			if ( OB_EInvoice::facturx_available() ) {
				echo '<a class="button" href="' . esc_url( OB_EInvoice::facturx_url( $order->get_id() ) ) . '">' . esc_html__( 'Factur-X PDF', 'orderbay' ) . '</a> ';
			}
		}
		echo '<span class="description" style="display:block;margin-top:4px;">' . esc_html__( 'PDF: browser Print → Save as PDF (primary). Optional host Dompdf/TCPDF when PDF engine = Auto.', 'orderbay' ) . '</span>';
		if ( class_exists( 'OB_EInvoice' ) ) {
			$einv_issues = OB_EInvoice::compliance_issues( OB_EInvoice::order_to_invoice_data( $order ) );
			if ( $einv_issues ) {
				echo '<span class="description" style="display:block;margin-top:4px;color:#b32d2e;">';
				echo esc_html__( 'E-invoice XML is a draft — not fully compliant yet:', 'orderbay' ) . ' ' . esc_html( implode( ' ', $einv_issues ) );
				echo '</span>';
			}
		}
		echo '</p>';
	}

	public function list_row_actions( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		foreach ( array(
			'invoice'  => __( 'Inv', 'orderbay' ),
			'proforma' => __( 'Pro', 'orderbay' ),
			'packing'  => __( 'Pack', 'orderbay' ),
			'delivery' => __( 'DN', 'orderbay' ),
			'label'    => __( 'Lbl', 'orderbay' ),
		) as $type => $short ) {
			printf(
				'<a class="button wc-action-button" href="%s" target="_blank" title="%s">%s</a> ',
				esc_url( $this->print_url( $order->get_id(), $type ) ),
				esc_attr( $short ),
				esc_html( $short )
			);
		}
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $type Type.
	 * @return string
	 */
	public function print_url( $order_id, $type = 'invoice' ) {
		$map = array(
			'invoice'  => 'ob_print_invoice',
			'packing'  => 'ob_print_packing_slip',
			'proforma' => 'ob_print_proforma',
			'delivery' => 'ob_print_delivery',
			'label'    => 'ob_print_label',
		);
		$action = $map[ $type ] ?? 'ob_print_invoice';
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&order_id=' . absint( $order_id ) ),
			$action . '_' . absint( $order_id )
		);
	}

	public function pdf_url( $order_id, $type = 'invoice' ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=ob_pdf_download&type=' . rawurlencode( $type ) . '&order_id=' . absint( $order_id ) ),
			'ob_pdf_' . absint( $order_id )
		);
	}

	public function handle_print_invoice() {
		$this->render_document( 'invoice' );
	}
	public function handle_print_packing() {
		$this->render_document( 'packing' );
	}
	public function handle_print_proforma() {
		$this->render_document( 'proforma' );
	}
	public function handle_print_delivery() {
		$this->render_document( 'delivery' );
	}
	public function handle_print_label() {
		$this->render_document( 'label' );
	}

	/**
	 * @param string $type Document type.
	 */
	private function render_document( $type ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$type     = in_array( $type, self::TYPES, true ) ? $type : 'invoice';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		$map      = array(
			'invoice'  => 'ob_print_invoice',
			'packing'  => 'ob_print_packing_slip',
			'proforma' => 'ob_print_proforma',
			'delivery' => 'ob_print_delivery',
			'label'    => 'ob_print_label',
		);
		$action = $map[ $type ];
		check_admin_referer( $action . '_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		$settings = OB_Plugin::get_doc_settings();
		$this->prepare_order_for_type( $order, $type );
		$orders = array( $order );
		/**
		 * Fires before an OrderBay document renders (extension point for headers/logos).
		 *
		 * @param string     $type   Document type.
		 * @param WC_Order[] $orders Orders being rendered.
		 */
		do_action( 'ob_before_document', $type, $orders );
		include self::locate_template( $this->template_for( $type ) );
		do_action( 'ob_after_document', $type, $orders );
		exit;
	}

	/**
	 * @param WC_Order $order Order.
	 * @param string   $type Type.
	 */
	private function prepare_order_for_type( $order, $type ) {
		if ( ! class_exists( 'OB_Invoicing' ) ) {
			return;
		}
		if ( 'invoice' === $type ) {
			OB_Invoicing::ensure_invoice_number( $order );
		}
		if ( 'proforma' === $type ) {
			OB_Invoicing::ensure_proforma_number( $order );
		}
	}

	/**
	 * @param string $type Type.
	 * @return string Template filename.
	 */
	private function template_for( $type ) {
		$map = array(
			'invoice'  => 'invoice.php',
			'packing'  => 'packing-slip.php',
			'proforma' => 'proforma.php',
			'delivery' => 'delivery-note.php',
			'label'    => 'shipping-label.php',
		);
		return $map[ $type ] ?? 'invoice.php';
	}

	public function bulk_actions_legacy( $actions ) {
		$actions['ob_print_invoices']  = __( 'Print Orderbay invoices', 'orderbay' );
		$actions['ob_print_proformas'] = __( 'Print Orderbay proformas', 'orderbay' );
		$actions['ob_print_packing']   = __( 'Print Orderbay packing slips', 'orderbay' );
		$actions['ob_print_delivery']  = __( 'Print Orderbay delivery notes', 'orderbay' );
		$actions['ob_print_labels']    = __( 'Print Orderbay shipping labels', 'orderbay' );
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
		$map = array(
			'ob_print_invoices'  => 'invoice',
			'ob_print_proformas' => 'proforma',
			'ob_print_packing'   => 'packing',
			'ob_print_delivery'  => 'delivery',
			'ob_print_labels'    => 'label',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$type = $map[ $action ];
		$ids  = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return add_query_arg( 'ob_bulk_empty', '1', $redirect );
		}
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
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'invoice';
		}
		$raw  = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ''; // phpcs:ignore
		$ids  = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		$orders = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$this->prepare_order_for_type( $o, $type );
				$orders[] = $o;
			}
		}
		if ( ! $orders ) {
			wp_die( esc_html__( 'No orders selected.', 'orderbay' ) );
		}
		$settings = OB_Plugin::get_doc_settings();
		do_action( 'ob_before_document', $type, $orders );
		include self::locate_template( $this->template_for( $type ) );
		do_action( 'ob_after_document', $type, $orders );
		exit;
	}

	/**
	 * Render a document to PDF bytes using the detected host engine (Dompdf/TCPDF).
	 * Returns '' when no engine is available. Reused by the Factur-X assembler.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  Document type.
	 * @return string PDF bytes or ''.
	 */
	public static function render_pdf_bytes( $order, $type = 'invoice' ) {
		$engine = self::detect_pdf_engine();
		if ( ! $engine ) {
			return '';
		}
		$self = self::instance();
		$self->prepare_order_for_type( $order, $type );
		$settings = OB_Plugin::get_doc_settings();
		$orders   = array( $order );
		ob_start();
		include self::locate_template( $self->template_for( $type ) );
		$html = (string) ob_get_clean();
		$html = preg_replace( '/<p class="no-print">.*?<\/p>/s', '', $html );

		if ( 'dompdf' === $engine && class_exists( '\\Dompdf\\Dompdf' ) ) {
			$dompdf = new \Dompdf\Dompdf();
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( ( 'a4' === ( $settings['paper'] ?? 'letter' ) ) ? 'a4' : 'letter' );
			$dompdf->render();
			return (string) $dompdf->output();
		}
		if ( 'tcpdf' === $engine && class_exists( 'TCPDF' ) ) {
			$pdf = new TCPDF();
			$pdf->AddPage();
			$pdf->writeHTML( $html, true, false, true, false, '' );
			return (string) $pdf->Output( '', 'S' );
		}
		return '';
	}

	/**
	 * Optional host PDF stream (Dompdf/TCPDF only if present).
	 */
	public function handle_pdf_download() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_pdf_' . $order_id );
		$s = OB_Plugin::get_doc_settings();
		if ( 'auto' !== ( $s['pdf_engine'] ?? 'browser' ) ) {
			wp_die( esc_html__( 'PDF engine is set to browser-only.', 'orderbay' ) );
		}
		$engine = self::detect_pdf_engine();
		if ( ! $engine ) {
			wp_die( esc_html__( 'No Dompdf/TCPDF on this host. Use browser Print → Save as PDF.', 'orderbay' ) );
		}
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'invoice'; // phpcs:ignore
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		$this->prepare_order_for_type( $order, $type );
		$settings = $s;
		$orders   = array( $order );
		ob_start();
		include self::locate_template( $this->template_for( $type ) );
		$html = ob_get_clean();
		// Strip no-print scripts for PDF engines.
		$html = preg_replace( '/<p class="no-print">.*?<\/p>/s', '', $html );

		if ( 'dompdf' === $engine && class_exists( '\\Dompdf\\Dompdf' ) ) {
			$dompdf = new \Dompdf\Dompdf();
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( ( 'a4' === ( $s['paper'] ?? 'letter' ) ) ? 'a4' : 'letter' );
			$dompdf->render();
			$dompdf->stream( 'orderbay-' . $type . '-' . $order_id . '.pdf', array( 'Attachment' => true ) );
			exit;
		}
		if ( 'tcpdf' === $engine && class_exists( 'TCPDF' ) ) {
			$pdf = new TCPDF();
			$pdf->AddPage();
			$pdf->writeHTML( $html, true, false, true, false, '' );
			$pdf->Output( 'orderbay-' . $type . '-' . $order_id . '.pdf', 'D' );
			exit;
		}
		wp_die( esc_html__( 'PDF engine failed.', 'orderbay' ) );
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
