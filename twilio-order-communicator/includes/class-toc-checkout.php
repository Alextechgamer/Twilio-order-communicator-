<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in checkout SMS consent checkbox (classic + block checkout).
 */
class TOC_Checkout {

	const BLOCK_FIELD_ID = 'toc/sms-consent';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! (int) get_option( 'toc_checkout_consent_enabled', 1 ) ) {
			return;
		}

		// Classic checkout.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_classic_checkbox' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_to_order' ), 20, 2 );

		// Block / Store API additional checkout field (WooCommerce 8.9+).
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_block_from_request' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'sync_block_field_to_consent_meta' ), 20, 1 );
	}

	public static function consent_label() {
		$default = __( 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.', 'twilio-order-communicator' );
		$label   = get_option( 'toc_checkout_consent_label', $default );
		return is_string( $label ) && $label !== '' ? $label : $default;
	}

	public static function meta_key() {
		$key = get_option( 'toc_sms_consent_meta', '_toc_sms_consent' );
		return $key ? $key : '_toc_sms_consent';
	}

	public function render_classic_checkbox() {
		$checked = ! empty( $_POST['toc_sms_consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		?>
		<p class="form-row form-row-wide toc-checkout-consent">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox"
					class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
					name="toc_sms_consent"
					id="toc_sms_consent"
					value="1"
					<?php checked( $checked, true ); ?>
				/>
				<span class="woocommerce-terms-and-conditions-checkbox-text"><?php echo esc_html( self::consent_label() ); ?></span>
				<?php if ( (int) get_option( 'toc_checkout_consent_required', 0 ) ) : ?>
					&nbsp;<abbr class="required" title="<?php echo esc_attr__( 'required', 'twilio-order-communicator' ); ?>">*</abbr>
				<?php endif; ?>
			</label>
		</p>
		<?php
	}

	public function validate_classic() {
		if ( ! (int) get_option( 'toc_checkout_consent_required', 0 ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['toc_sms_consent'] ) ) {
			wc_add_notice(
				__( 'Please agree to receive SMS updates about your order, or contact the store for alternatives.', 'twilio-order-communicator' ),
				'error'
			);
		}
	}

	/**
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	public function save_classic_to_order( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$consented = ! empty( $_POST['toc_sms_consent'] );
		$this->apply_consent_to_order( $order, $consented, 'classic' );
	}

	public function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::BLOCK_FIELD_ID,
				'label'    => self::consent_label(),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => (bool) (int) get_option( 'toc_checkout_consent_required', 0 ),
			)
		);
	}

	/**
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public function save_block_from_request( $order, $request ) {
		$consented = false;

		// Additional fields may appear under different keys depending on WC version.
		$candidates = array();
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$extra = $request->get_param( 'additional_fields' );
			if ( is_array( $extra ) ) {
				$candidates[] = $extra;
			}
			$extensions = $request->get_param( 'extensions' );
			if ( is_array( $extensions ) ) {
				$candidates[] = $extensions;
			}
		}

		foreach ( $candidates as $bag ) {
			if ( isset( $bag[ self::BLOCK_FIELD_ID ] ) ) {
				$consented = TOC_Twilio::instance()->is_truthy_consent( $bag[ self::BLOCK_FIELD_ID ] );
				break;
			}
			if ( isset( $bag['toc/sms-consent'] ) ) {
				$consented = TOC_Twilio::instance()->is_truthy_consent( $bag['toc/sms-consent'] );
				break;
			}
		}

		// Fallback: read WC-stored additional field meta if already set on the order.
		if ( ! $consented ) {
			$consented = $this->order_has_block_consent_meta( $order );
		}

		$this->apply_consent_to_order( $order, $consented, 'block' );
	}

	/**
	 * After order create, copy block additional field into our consent meta key.
	 *
	 * @param WC_Order $order Order.
	 */
	public function sync_block_field_to_consent_meta( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $this->order_has_block_consent_meta( $order ) ) {
			$this->apply_consent_to_order( $order, true, 'block-sync' );
			$order->save();
		}
	}

	private function order_has_block_consent_meta( $order ) {
		$keys = array(
			'_wc_other/' . self::BLOCK_FIELD_ID,
			'_wc_billing/' . self::BLOCK_FIELD_ID,
			'_wc_shipping/' . self::BLOCK_FIELD_ID,
			self::BLOCK_FIELD_ID,
			'_toc_block_sms_consent',
		);
		foreach ( $keys as $key ) {
			$val = $order->get_meta( $key );
			if ( TOC_Twilio::instance()->is_truthy_consent( $val ) ) {
				return true;
			}
		}

		// WC 8.9+ may expose get_meta with namespaced keys differently.
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			if ( $key !== '' && ( strpos( $key, 'toc/sms-consent' ) !== false || strpos( $key, 'sms-consent' ) !== false ) ) {
				if ( TOC_Twilio::instance()->is_truthy_consent( $data['value'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Persist consent + audit trail on the order (and user when available).
	 *
	 * @param WC_Order $order     Order.
	 * @param bool     $consented Whether opted in.
	 * @param string   $source    classic|block|….
	 */
	public function apply_consent_to_order( $order, $consented, $source = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$meta_key = self::meta_key();
		$value    = $consented ? 'yes' : 'no';

		$order->update_meta_data( $meta_key, $value );
		$order->update_meta_data( '_toc_sms_consent', $value );

		if ( $consented ) {
			$order->update_meta_data( '_toc_sms_consent_at', time() );
			$order->update_meta_data( '_toc_sms_consent_ip', $this->client_ip() );
			if ( $source ) {
				$order->update_meta_data( '_toc_sms_consent_source', sanitize_key( $source ) );
			}
		}

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, $meta_key, $value );
			update_user_meta( $user_id, '_toc_sms_consent', $value );
		}
	}

	private function client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $parts[0] );
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}
}
