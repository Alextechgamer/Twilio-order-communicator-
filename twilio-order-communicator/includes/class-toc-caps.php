<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable capability helpers + role grants.
 *
 * Custom caps:
 *   toc_manage — Order Communicator admin pages (Dashboard, Settings, Bulk, …)
 *   toc_send   — Send SMS / place calls from the order screen
 *
 * Defaults are granted to administrator + shop_manager once (never wiped on upgrade).
 * Advanced sites can still override the required string via filters:
 *   add_filter( 'toc_manage_settings', fn() => 'edit_shop_orders' );
 *   add_filter( 'toc_send_sms', fn() => 'edit_shop_orders' );
 */
class TOC_Caps {

	const CAP_MANAGE = 'toc_manage';
	const CAP_SEND   = 'toc_send';

	/** Option flag: default role grants have been applied once. */
	const SEEDED_OPTION = 'toc_caps_seeded';

	/**
	 * Capability for Settings / Tools / Setup / Dashboard / Bulk / CSV / License admin pages.
	 *
	 * @return string
	 */
	public static function manage() {
		/**
		 * Filter the capability required to manage Order Communicator settings.
		 *
		 * @param string $cap Default toc_manage.
		 */
		$cap = apply_filters( 'toc_manage_settings', self::CAP_MANAGE );
		return is_string( $cap ) && $cap !== '' ? $cap : self::CAP_MANAGE;
	}

	/**
	 * Capability for sending SMS / placing calls (order meta box + related AJAX).
	 *
	 * @return string
	 */
	public static function send() {
		/**
		 * Filter the capability required to send SMS or place calls.
		 *
		 * @param string $cap Default toc_send.
		 */
		$cap = apply_filters( 'toc_send_sms', self::CAP_SEND );
		return is_string( $cap ) && $cap !== '' ? $cap : self::CAP_SEND;
	}

	/**
	 * Ensure default caps exist on administrator + shop_manager (once).
	 * Safe to call from activate and init; never removes custom grants.
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			// Still ensure administrator always has manage (safety net after bad saves).
			self::ensure_administrator_manage();
			return;
		}

		$defaults = array( 'administrator', 'shop_manager' );
		foreach ( $defaults as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			$role->add_cap( self::CAP_MANAGE );
			$role->add_cap( self::CAP_SEND );
		}

		update_option( self::SEEDED_OPTION, 1, false );
		self::ensure_administrator_manage();
	}

	/**
	 * Always keep toc_manage on the administrator role.
	 */
	public static function ensure_administrator_manage() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_MANAGE ) ) {
			$role->add_cap( self::CAP_MANAGE );
		}
		// Administrators can also send by default if somehow stripped during seed race.
		if ( $role && ! $role->has_cap( self::CAP_SEND ) && get_option( self::SEEDED_OPTION, false ) ) {
			// Do not re-add send if the store intentionally removed it from admin via matrix —
			// only force manage. Send is optional for admins who only configure.
		}
	}

	/**
	 * Roles that currently hold toc_manage.
	 *
	 * @return string[] Role keys.
	 */
	public static function roles_with_manage() {
		$found = array();
		foreach ( self::editable_roles() as $key => $role ) {
			if ( ! empty( $role['capabilities'][ self::CAP_MANAGE ] ) ) {
				$found[] = $key;
			}
		}
		return $found;
	}

	/**
	 * @return array<string,array> Role key => role data from get_editable_roles().
	 */
	public static function editable_roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$roles = get_editable_roles();
		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * Apply a role matrix from Settings (manage / send checkboxes per role).
	 *
	 * @param array $posted Expected shape: [ role_key => [ 'manage' => '1'|0, 'send' => '1'|0 ], … ]
	 * @return true|WP_Error
	 */
	public static function save_role_matrix( $posted ) {
		if ( ! is_array( $posted ) ) {
			return new WP_Error( 'toc_caps_invalid', __( 'Invalid role matrix.', 'twilio-order-communicator' ) );
		}

		$roles = self::editable_roles();
		if ( empty( $roles ) ) {
			return new WP_Error( 'toc_caps_no_roles', __( 'No editable roles found.', 'twilio-order-communicator' ) );
		}

		// Build intended grants.
		$want_manage = array();
		$want_send   = array();
		foreach ( array_keys( $roles ) as $role_key ) {
			$row = isset( $posted[ $role_key ] ) && is_array( $posted[ $role_key ] ) ? $posted[ $role_key ] : array();
			if ( ! empty( $row['manage'] ) ) {
				$want_manage[ $role_key ] = true;
			}
			if ( ! empty( $row['send'] ) ) {
				$want_send[ $role_key ] = true;
			}
		}

		// Safety: administrator always keeps manage.
		$want_manage['administrator'] = true;

		// Safety: at least one role that can manage_options or manage_woocommerce must keep toc_manage.
		// (administrator already forced; this blocks locking out every shop admin if administrator role is missing.)
		$has_safe_manage = false;
		foreach ( $want_manage as $role_key => $yes ) {
			if ( ! $yes ) {
				continue;
			}
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			if ( $role->has_cap( 'manage_options' ) || $role->has_cap( 'manage_woocommerce' ) || $role_key === 'administrator' ) {
				$has_safe_manage = true;
				break;
			}
		}
		if ( ! $has_safe_manage ) {
			$want_manage['administrator'] = true;
		}

		foreach ( array_keys( $roles ) as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}

			// Manage.
			if ( ! empty( $want_manage[ $role_key ] ) ) {
				$role->add_cap( self::CAP_MANAGE );
			} else {
				// Never strip administrator manage.
				if ( $role_key !== 'administrator' ) {
					$role->remove_cap( self::CAP_MANAGE );
				}
			}

			// Send.
			if ( ! empty( $want_send[ $role_key ] ) ) {
				$role->add_cap( self::CAP_SEND );
			} else {
				$role->remove_cap( self::CAP_SEND );
			}
		}

		// Final safety net.
		self::ensure_administrator_manage();

		return true;
	}
}
