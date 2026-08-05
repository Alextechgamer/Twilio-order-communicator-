<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk ZIP download of StoreCanvas print files for an order (0.6.0).
 */
class SC_Bulk_Download {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_button' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_action' ) );
		add_action( 'woocommerce_order_action_sc_bulk_print_zip', array( $this, 'handle_order_action' ) );
		add_action( 'admin_post_sc_bulk_print_zip', array( $this, 'download_zip' ) );
	}

	public function order_action( $actions ) {
		$actions['sc_bulk_print_zip'] = __( 'StoreCanvas: download print files ZIP', 'storecanvas' );
		return $actions;
	}

	/**
	 * Order action just redirects to the download endpoint.
	 *
	 * @param WC_Order $order Order.
	 */
	public function handle_order_action( $order ) {
		$url = $this->download_url( $order->get_id() );
		// Store note; actual download is via button (order actions can't stream ZIP cleanly).
		$order->add_order_note( sprintf( __( 'StoreCanvas bulk ZIP link: %s', 'storecanvas' ), $url ), false, true );
	}

	public function order_button( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$url = $this->download_url( $order->get_id() );
		echo '<p class="form-field" style="clear:both;padding-left:0;">';
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Download all StoreCanvas print files (ZIP)', 'storecanvas' ) . '</a>';
		echo '</p>';
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public function download_url( $order_id ) {
		return admin_url(
			'admin-post.php?action=sc_bulk_print_zip&order_id=' . absint( $order_id ) . '&_wpnonce=' . wp_create_nonce( 'sc_bulk_print_zip_' . absint( $order_id ) )
		);
	}

	/**
	 * Collect print file paths for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array{files:array<int,array{path:string,name:string}>,manifest:string,count:int}
	 */
	public function collect_files( $order ) {
		$files    = array();
		$manifest = array();
		$manifest[] = 'StoreCanvas print package';
		$manifest[] = 'Order: #' . $order->get_order_number();
		$manifest[] = 'Date: ' . ( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' );
		$manifest[] = 'Customer: ' . $order->get_formatted_billing_full_name();
		$manifest[] = '';

		foreach ( $order->get_items() as $item_id => $item ) {
			$name = $item->get_name();
			$qty  = $item->get_quantity();
			$opts = $item->get_meta( SC_Plugin::CART_OPTIONS );
			$files_meta = $item->get_meta( SC_Print_Ready::META_PRINT_FILES );
			$art_id     = (int) $item->get_meta( '_sc_artwork_id' );
			if ( ! $files_meta && ! $art_id ) {
				// Fallback: attachments cart meta.
				$atts = $item->get_meta( SC_Plugin::CART_ATTACHMENTS );
				if ( is_array( $atts ) && ! empty( $atts['artwork'] ) ) {
					$art_id = (int) $atts['artwork'];
				}
			}
			if ( ! $files_meta && ! $art_id ) {
				continue;
			}

			$manifest[] = sprintf( 'Item #%d: %s x%d', $item_id, $name, $qty );
			if ( is_array( $opts ) ) {
				foreach ( $opts as $k => $v ) {
					$manifest[] = '  option ' . $k . ': ' . ( is_array( $v ) ? implode( ',', $v ) : $v );
				}
			}

			$slug = 'item-' . $item_id . '-' . sanitize_file_name( $name );

			if ( $art_id ) {
				$path = get_attached_file( $art_id );
				if ( $path && file_exists( $path ) ) {
					$ext = pathinfo( $path, PATHINFO_EXTENSION );
					$files[] = array(
						'path' => $path,
						'name' => $slug . '/original.' . ( $ext ? $ext : 'bin' ),
					);
					$manifest[] = '  original: attachment #' . $art_id;
				}
			}
			if ( is_array( $files_meta ) ) {
				foreach ( $files_meta as $vid => $fid ) {
					$path = get_attached_file( (int) $fid );
					if ( $path && file_exists( $path ) ) {
						$files[] = array(
							'path' => $path,
							'name' => $slug . '/composite-' . sanitize_file_name( (string) $vid ) . '.png',
						);
						$manifest[] = '  composite (' . $vid . '): attachment #' . (int) $fid;
					}
				}
			}
			$manifest[] = '';
		}

		return array(
			'files'    => $files,
			'manifest' => implode( "\n", $manifest ) . "\n",
			'count'    => count( $files ),
		);
	}

	public function download_zip() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		check_admin_referer( 'sc_bulk_print_zip_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'storecanvas' ) );
		}

		$pack = $this->collect_files( $order );
		if ( $pack['count'] < 1 ) {
			wp_die( esc_html__( 'No StoreCanvas print files on this order.', 'storecanvas' ) );
		}

		$order_num = $order->get_order_number();
		$zip_name  = 'storecanvas-order-' . sanitize_file_name( (string) $order_num ) . '.zip';

		if ( class_exists( 'ZipArchive' ) ) {
			$tmp = wp_tempnam( $zip_name );
			$zip = new ZipArchive();
			if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
				wp_die( esc_html__( 'Could not create ZIP archive.', 'storecanvas' ) );
			}
			$zip->addFromString( 'manifest.txt', $pack['manifest'] );
			foreach ( $pack['files'] as $f ) {
				$zip->addFile( $f['path'], $f['name'] );
			}
			$zip->close();

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $zip_name . '"' );
			header( 'Content-Length: ' . (string) filesize( $tmp ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			readfile( $tmp );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			exit;
		}

		// Fallback: HTML list of links.
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $zip_name ) . '</title></head><body>';
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'PHP ZipArchive is not available. Download files individually:', 'storecanvas' ) . '</p></div>';
		echo '<h1>' . esc_html__( 'StoreCanvas print files', 'storecanvas' ) . '</h1><ul>';
		foreach ( $pack['files'] as $f ) {
			// Prefer attachment URL when possible — paths are local; show name only + path hint.
			echo '<li><code>' . esc_html( $f['name'] ) . '</code> — ' . esc_html( $f['path'] ) . '</li>';
		}
		echo '</ul><pre>' . esc_html( $pack['manifest'] ) . '</pre></body></html>';
		exit;
	}
}
