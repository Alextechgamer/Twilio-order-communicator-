<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable capability helpers + role grants.
 *
 * Custom caps:
 *   orl_manage — Order Communicator admin pages (Dashboard, Settings, Bulk, …)
 *   orl_send   — Send SMS / place calls from the order screen
 *
 * Defaults are granted to administrator + shop_manager once (never wiped on upgrade).
 * Advanced sites can still override the required string via filters:
 *   add_filter( 'orl_manage_settings', fn() => 'edit_shop_orders' );
 *   add_filter( 'orl_send_sms', fn() => 'edit_shop_orders' );
 */
class ORL_Caps {

	const CAP_MANAGE = 'orl_manage';
	const CAP_SEND   = 'orl_send';

	/** Option flag: default role grants have been applied once. */
	const SEEDED_OPTION = 'orl_caps_seeded';

	/**
	 * Capability for Settings / Tools / Setup / Dashboard / Bulk / CSV / License admin pages.
	 *
	 * @return string
	 */
	public static function manage() {
		/**
		 * Filter the capability required to manage Order Communicator settings.
		 *
		 * @param string $cap Default orl_manage.
		 */
		$cap = apply_filters( 'orl_manage_settings', self::CAP_MANAGE );
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
		 * @param string $cap Default orl_send.
		 */
		$cap = apply_filters( 'orl_send_sms', self::CAP_SEND );
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
	 * Always keep orl_manage on the administrator role.
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
	 * Roles that currently hold orl_manage.
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
	 * Whether a role is eligible to hold orl_manage / orl_send.
	 *
	 * Guards against escalating a low-privilege role (e.g. subscriber) into plugin
	 * management — reading Twilio secrets, exporting PII, granting further caps. A role
	 * may receive the caps only if it is the administrator or already holds a WooCommerce
	 * shop baseline (manage_woocommerce or edit_shop_orders). Pure so the rule is
	 * unit-testable without a WP runtime.
	 *
	 * @param string $role_key        Role slug.
	 * @param bool   $has_manage_woo  Whether the role holds manage_woocommerce.
	 * @param bool   $has_edit_orders Whether the role holds edit_shop_orders.
	 * @return bool
	 */
	public static function role_meets_baseline( $role_key, $has_manage_woo, $has_edit_orders ) {
		if ( 'administrator' === $role_key ) {
			return true;
		}
		return (bool) $has_manage_woo || (bool) $has_edit_orders;
	}

}

