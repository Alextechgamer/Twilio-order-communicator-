<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once TOC_PLUGIN_DIR . 'includes/trait-toc-admin-settings.php';
require_once TOC_PLUGIN_DIR . 'includes/trait-toc-admin-dashboard.php';
require_once TOC_PLUGIN_DIR . 'includes/trait-toc-admin-bulk.php';
require_once TOC_PLUGIN_DIR . 'includes/trait-toc-admin-tools.php';
require_once TOC_PLUGIN_DIR . 'includes/trait-toc-admin-ajax.php';
require_once TOC_PLUGIN_DIR . 'includes/trait-toc-admin-license.php';

/**
 * Thin admin orchestrator — UI and AJAX live in focused traits.
 */
class TOC_Admin {

	use TOC_Admin_Settings;
	use TOC_Admin_Dashboard;
	use TOC_Admin_Bulk;
	use TOC_Admin_Tools;
	use TOC_Admin_Ajax;
	use TOC_Admin_License;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_toc_mark_resolved', array( $this, 'ajax_resolve' ) );
		add_action( 'wp_ajax_toc_bulk_reminder', array( $this, 'ajax_bulk' ) );
		add_action( 'wp_ajax_toc_test_connection', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_toc_license_save_server', array( $this, 'ajax_license_save_server' ) );
		add_action( 'admin_post_toc_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_toc_save_role_caps', array( $this, 'handle_save_role_caps' ) );
	}

	/**
	 * Save role capability matrix from Settings.
	 */
	public function handle_save_role_caps() {
		// Editing the capability matrix is administrator-only (see render_role_permissions):
		// a toc_manage holder must not be able to grant caps to other roles.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'twilio-order-communicator' ), 403 );
		}
		check_admin_referer( 'toc_save_role_caps' );

		$posted = isset( $_POST['toc_role_caps'] ) ? wp_unslash( $_POST['toc_role_caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $posted ) ) {
			$posted = array();
		}

		// Sanitize nested checkboxes to 0/1.
		$clean = array();
		foreach ( $posted as $role_key => $row ) {
			$role_key = sanitize_key( $role_key );
			if ( $role_key === '' || ! is_array( $row ) ) {
				continue;
			}
			$clean[ $role_key ] = array(
				'manage' => ! empty( $row['manage'] ) ? 1 : 0,
				'send'   => ! empty( $row['send'] ) ? 1 : 0,
			);
		}

		$result = TOC_Caps::save_role_matrix( $clean );
		$redirect = add_query_arg(
			array(
				'page'            => 'toc-communicator',
				'tab'             => 'settings',
				'toc_roles_saved' => is_wp_error( $result ) ? '0' : '1',
			),
			admin_url( 'admin.php' )
		);
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'toc_roles_error', rawurlencode( $result->get_error_message() ), $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order Communicator', 'twilio-order-communicator' ),
			__( 'Order Communicator', 'twilio-order-communicator' ),
			TOC_Caps::manage(),
			'toc-communicator',
			array( $this, 'page' )
		);
	}

	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$load = ( strpos( $hook, 'toc-communicator' ) !== false )
			|| in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( ! $load ) {
			return;
		}

		wp_enqueue_style( 'toc-admin', TOC_PLUGIN_URL . 'assets/admin.css', array(), TOC_VERSION );
		wp_enqueue_script( 'toc-admin', TOC_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), TOC_VERSION, true );
		wp_localize_script(
			'toc-admin',
			'tocData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'toc_nonce' ),
				'i18n'     => array(
					'enterMessage'     => __( 'Enter a message', 'twilio-order-communicator' ),
					'noPhone'          => __( 'No phone number on this order', 'twilio-order-communicator' ),
					'optOutConfirm'    => __( 'This phone has opted out (STOP). Send SMS anyway?', 'twilio-order-communicator' ),
					'noConsentConfirm' => __( 'Customer has not opted in to SMS. Send anyway?', 'twilio-order-communicator' ),
					'placeCallConfirm' => __( 'Place a voice call with the current message?', 'twilio-order-communicator' ),
					'errorPrefix'      => __( 'Error:', 'twilio-order-communicator' ),
					'requestFailed'    => __( 'Request failed', 'twilio-order-communicator' ),
					'couldNotResolve'  => __( 'Could not mark resolved', 'twilio-order-communicator' ),
					'markResolved'     => __( 'Mark Resolved', 'twilio-order-communicator' ),
					'conversationDone' => __( 'Conversation resolved', 'twilio-order-communicator' ),
					'resolved'         => __( 'Resolved', 'twilio-order-communicator' ),
					'selectOrders'     => __( 'Select at least one order', 'twilio-order-communicator' ),
					'sending'          => __( 'Sending…', 'twilio-order-communicator' ),
					'sendSelected'     => __( 'Send to Selected', 'twilio-order-communicator' ),
					'stop'             => __( 'Stop', 'twilio-order-communicator' ),
					'stopping'         => __( 'Stopping…', 'twilio-order-communicator' ),
					'testing'          => __( 'Testing…', 'twilio-order-communicator' ),
					'testBtn'          => __( 'Run Connection Test', 'twilio-order-communicator' ),
					'unknown'          => __( 'Unknown', 'twilio-order-communicator' ),
					'needsForce'       => __( 'SMS blocked by consent', 'twilio-order-communicator' ),
					'sendAnyway'       => __( 'Send anyway?', 'twilio-order-communicator' ),
					'activating'       => __( 'Activating…', 'twilio-order-communicator' ),
					'deactivating'     => __( 'Deactivating…', 'twilio-order-communicator' ),
					'checking'         => __( 'Checking…', 'twilio-order-communicator' ),
					'saving'           => __( 'Saving…', 'twilio-order-communicator' ),
					'activate'         => __( 'Activate', 'twilio-order-communicator' ),
					'deactivate'       => __( 'Deactivate', 'twilio-order-communicator' ),
					'recheck'          => __( 'Re-check', 'twilio-order-communicator' ),
					'saveServer'       => __( 'Save server URL', 'twilio-order-communicator' ),
					'updatesOn'        => __( 'premium updates enabled', 'twilio-order-communicator' ),
					'updatesOff'       => __( 'premium updates paused', 'twilio-order-communicator' ),
					'lifetime'         => __( 'Lifetime / none set', 'twilio-order-communicator' ),
				),
			)
		);
	}

	public function ajax_license_save_server() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}
		if ( defined( 'TOC_LICENSE_SERVER_URL' ) && TOC_LICENSE_SERVER_URL ) {
			wp_send_json_error( __( 'Server URL is locked by TOC_LICENSE_SERVER_URL.', 'twilio-order-communicator' ) );
		}
		$url = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '';
		$url = $url ? untrailingslashit( $url ) : '';
		update_option( 'toc_license_server_url', $url, false );
		wp_send_json_success(
			array(
				'message' => __( 'License server URL saved.', 'twilio-order-communicator' ),
				'server_configured' => TOC_License::instance()->is_configured(),
			)
		);
	}

	public function page() {
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap toc-wrap">
			<h1><?php echo esc_html__( 'Twilio Order Communicator', 'twilio-order-communicator' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=dashboard' ) ); ?>" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Dashboard', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=bulk' ) ); ?>" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Bulk Reminders', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=settings' ) ); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Settings', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=setup' ) ); ?>" class="nav-tab <?php echo $tab === 'setup' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Setup', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=license' ) ); ?>" class="nav-tab <?php echo $tab === 'license' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'License', 'twilio-order-communicator' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=toc-communicator&tab=tools' ) ); ?>" class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Tools & Docs', 'twilio-order-communicator' ); ?></a>
			</nav>
			<div class="toc-content">
				<?php
				if ( $tab === 'settings' ) {
					$this->render_settings();
				} elseif ( $tab === 'bulk' ) {
					$this->render_bulk();
				} elseif ( $tab === 'setup' ) {
					TOC_Onboarding::instance()->render();
				} elseif ( $tab === 'license' ) {
					$this->render_license();
				} elseif ( $tab === 'tools' ) {
					$this->render_tools();
				} else {
					$this->render_dashboard();
				}
				?>
			</div>
		</div>
		<?php
	}
}
