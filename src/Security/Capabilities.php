<?php
/**
 * Capability catalogue.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every MagePeople Ferry Booking System capability.
 *
 * Nothing in the plugin may gate behaviour on `manage_options` alone; every
 * privileged operation maps to one of these capabilities so that site owners can
 * delegate work to counter staff, agents and check-in crews.
 */
final class Capabilities {

	public const ACCESS_DASHBOARD = 'fbm_access_dashboard';
	public const MANAGE_SETTINGS  = 'fbm_manage_settings';
	public const MANAGE_VESSELS   = 'fbm_manage_vessels';
	public const MANAGE_PORTS     = 'fbm_manage_ports';
	public const MANAGE_ROUTES    = 'fbm_manage_routes';
	public const MANAGE_SAILINGS  = 'fbm_manage_sailings';
	public const MANAGE_PRICING   = 'fbm_manage_pricing';
	public const MANAGE_BOOKINGS  = 'fbm_manage_bookings';
	public const CREATE_BOOKING   = 'fbm_create_booking';
	public const MODIFY_BOOKING   = 'fbm_modify_booking';
	public const CANCEL_BOOKING   = 'fbm_cancel_booking';
	public const ISSUE_REFUND     = 'fbm_issue_refund';
	public const CHECKIN          = 'fbm_checkin';
	public const VIEW_REPORTS     = 'fbm_view_reports';
	public const MANAGE_AGENTS    = 'fbm_manage_agents';

	/**
	 * Returns every capability the plugin defines.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$capabilities = array(
			self::ACCESS_DASHBOARD,
			self::MANAGE_SETTINGS,
			self::MANAGE_VESSELS,
			self::MANAGE_PORTS,
			self::MANAGE_ROUTES,
			self::MANAGE_SAILINGS,
			self::MANAGE_PRICING,
			self::MANAGE_BOOKINGS,
			self::CREATE_BOOKING,
			self::MODIFY_BOOKING,
			self::CANCEL_BOOKING,
			self::ISSUE_REFUND,
			self::CHECKIN,
			self::VIEW_REPORTS,
			self::MANAGE_AGENTS,
		);

		/**
		 * Filters the full capability catalogue.
		 *
		 * Pro adds its own capabilities here so that role provisioning and the
		 * Roles settings screen stay in sync automatically.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $capabilities Capability slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'fbm_capabilities', $capabilities ) ) );
	}

	/**
	 * Returns human readable labels for the capability catalogue.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = array(
			self::ACCESS_DASHBOARD => __( 'Access the Ferry Manager dashboard', 'magepeople-ferry-booking-system' ),
			self::MANAGE_SETTINGS  => __( 'Manage settings', 'magepeople-ferry-booking-system' ),
			self::MANAGE_VESSELS   => __( 'Manage vessels', 'magepeople-ferry-booking-system' ),
			self::MANAGE_PORTS     => __( 'Manage ports', 'magepeople-ferry-booking-system' ),
			self::MANAGE_ROUTES    => __( 'Manage routes', 'magepeople-ferry-booking-system' ),
			self::MANAGE_SAILINGS  => __( 'Manage sailings', 'magepeople-ferry-booking-system' ),
			self::MANAGE_PRICING   => __( 'Manage pricing', 'magepeople-ferry-booking-system' ),
			self::MANAGE_BOOKINGS  => __( 'View and manage bookings', 'magepeople-ferry-booking-system' ),
			self::CREATE_BOOKING   => __( 'Create bookings', 'magepeople-ferry-booking-system' ),
			self::MODIFY_BOOKING   => __( 'Modify bookings', 'magepeople-ferry-booking-system' ),
			self::CANCEL_BOOKING   => __( 'Cancel bookings', 'magepeople-ferry-booking-system' ),
			self::ISSUE_REFUND     => __( 'Issue refunds', 'magepeople-ferry-booking-system' ),
			self::CHECKIN          => __( 'Check in and board passengers', 'magepeople-ferry-booking-system' ),
			self::VIEW_REPORTS     => __( 'View reports', 'magepeople-ferry-booking-system' ),
			self::MANAGE_AGENTS    => __( 'Manage agents', 'magepeople-ferry-booking-system' ),
		);

		/**
		 * Filters the capability labels shown in the Roles settings screen.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $labels Capability slug => label.
		 */
		return (array) apply_filters( 'fbm_capability_labels', $labels );
	}
}
