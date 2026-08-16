<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional customer proof email with composite preview (0.6.0).
 * Store-level options; default OFF; once-per-order meta.
 */
class SC_Proof_Email {

	const META_SENT = '_sc_proof_emailed_at';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'settings_page' ), 65 );
		// Default trigger: processing.
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_send' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	public static function default_subject() {
		return __( 'Your print proof for order {order_number}', 'storecanvas' );
	}

	public static function default_body() {
		return __( "Hi {customer_first_name},\n\nThanks for your order #{order_number} at {store_name}.\n\nAttached is a proof of your customized artwork. If anything looks wrong, reply to this email before we print.\n\nThank you!", 'storecanvas' );
	}

	public function register_settings() {
		register_setting( 'sc_proof_email', 'sc_proof_email_enabled', array( 'type' => 'string', 'default' => '0', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( 'sc_proof_email', 'sc_proof_email_subject', array( 'type' => 'string', 'default' => self::default_subject(), 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'sc_proof_email', 'sc_proof_email_body', array( 'type' => 'string', 'default' => self::default_body(), 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'sc_proof_email', 'sc_proof_email_status', array( 'type' => 'string', 'default' => 'processing', 'sanitize_callback' => 'sanitize_key' ) );
	}

	public function sanitize_checkbox( $v ) {
		return ( '1' === (string) $v || 'yes' === $v || true === $v ) ? '1' : '0';
	}

	public function settings_page() {
		add_submenu_page(
			SC_Plugin::MENU_SLUG,
			__( 'Proof email', 'storecanvas' ),
			__( 'Proof email', 'storecanvas' ),
			'manage_woocommerce',
			'sc-proof-email',
			array( $this, 'render_settings' )
		);
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'StoreCanvas customer proof email', 'storecanvas' ); ?></h1>
			<p><?php esc_html_e( 'Optional email with print composite when an order has StoreCanvas artwork. Default off. Independent of OrderRing.', 'storecanvas' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'sc_proof_email' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable proof email', 'storecanvas' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="sc_proof_email_enabled" value="0" />
								<input type="checkbox" name="sc_proof_email_enabled" value="1" <?php checked( get_option( 'sc_proof_email_enabled', '0' ), '1' ); ?> />
								<?php esc_html_e( 'Send customer proof email', 'storecanvas' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Trigger status', 'storecanvas' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="sc_proof_email_status" value="<?php echo esc_attr( get_option( 'sc_proof_email_status', 'processing' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'WooCommerce status slug, e.g. processing or completed.', 'storecanvas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subject', 'storecanvas' ); ?></th>
						<td>
							<input type="text" class="large-text" name="sc_proof_email_subject" value="<?php echo esc_attr( get_option( 'sc_proof_email_subject', self::default_subject() ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Body', 'storecanvas' ); ?></th>
						<td>
							<textarea class="large-text" rows="8" name="sc_proof_email_body"><?php echo esc_textarea( get_option( 'sc_proof_email_body', self::default_body() ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Merge tags: {customer_first_name}, {order_number}, {store_name}', 'storecanvas' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		$want = get_option( 'sc_proof_email_status', 'processing' );
		if ( $want && $to === $want ) {
			$this->maybe_send( $order_id, $order );
		}
	}

	/**
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object.
	 */
	public function maybe_send( $order_id, $order = null ) {
		if ( get_option( 'sc_proof_email_enabled', '0' ) !== '1' ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( self::META_SENT ) ) {
			return;
		}

		$attachments = array();
		$has_sc      = false;
		foreach ( $order->get_items() as $item ) {
			$files  = $item->get_meta( SC_Print_Ready::META_PRINT_FILES );
			$art_id = (int) $item->get_meta( '_sc_artwork_id' );
			if ( $files || $art_id ) {
				$has_sc = true;
			}
			if ( is_array( $files ) ) {
				foreach ( $files as $fid ) {
					$path = get_attached_file( (int) $fid );
					if ( $path && file_exists( $path ) && filesize( $path ) < 8 * 1024 * 1024 ) {
						$attachments[] = $path;
					}
				}
			}
			if ( ! $attachments && $art_id ) {
				$path = get_attached_file( $art_id );
				if ( $path && file_exists( $path ) && filesize( $path ) < 8 * 1024 * 1024 ) {
					$attachments[] = $path;
				}
			}
		}

		if ( ! $has_sc ) {
			$order->add_order_note( __( 'StoreCanvas proof email skipped: no artwork/composites.', 'storecanvas' ), false, true );
			return;
		}

		$to = $order->get_billing_email();
		if ( ! $to || ! is_email( $to ) ) {
			$order->add_order_note( __( 'StoreCanvas proof email skipped: no billing email.', 'storecanvas' ), false, true );
			return;
		}

		$tags = array(
			'{customer_first_name}' => $order->get_billing_first_name() ? $order->get_billing_first_name() : __( 'there', 'storecanvas' ),
			'{order_number}'        => $order->get_order_number(),
			'{store_name}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		$subject = strtr( get_option( 'sc_proof_email_subject', self::default_subject() ), $tags );
		$body    = strtr( get_option( 'sc_proof_email_body', self::default_body() ), $tags );

		$from_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$from_email = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
		$headers    = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		// Limit attachments to first 3 composites.
		$attachments = array_slice( $attachments, 0, 3 );
		$sent        = wp_mail( $to, $subject, $body, $headers, $attachments );

		if ( $sent ) {
			$order->update_meta_data( self::META_SENT, current_time( 'mysql' ) );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s email address */
					__( 'StoreCanvas proof email sent to %s.', 'storecanvas' ),
					$to
				),
				false,
				true
			);
		} else {
			$order->add_order_note( __( 'StoreCanvas proof email failed to send.', 'storecanvas' ), false, true );
		}
	}
}
