<?php
/**
 * Role provisioning.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the operational roles shipped with the plugin.
 *
 * Roles are provisioned on activation and refreshed whenever the capability
 * catalogue version changes, so an extension can add capabilities without a
 * manual step.
 */
final class Roles {

	public const FERRY_MANAGER   = 'mpfbs_ferry_manager';
	public const BOOKING_MANAGER = 'mpfbs_booking_manager';
	public const CASHIER         = 'mpfbs_cashier';

	/**
	 * Option storing the signature of the last provisioned capability map.
	 */
	public const SIGNATURE_OPTION = 'mpfbs_roles_signature';

	/**
	 * Returns the role definitions.
	 *
	 * @return array<string, array{label: string, capabilities: string[]}>
	 */
	public static function definitions(): array {
		$definitions = array(
			self::FERRY_MANAGER   => array(
				'label'        => __( 'Ferry Manager', 'magepeople-ferry-booking-system' ),
				'capabilities' => Capabilities::all(),
			),
			self::BOOKING_MANAGER => array(
				'label'        => __( 'Ferry Booking Manager', 'magepeople-ferry-booking-system' ),
				'capabilities' => array(
					Capabilities::ACCESS_DASHBOARD,
					Capabilities::MANAGE_SAILINGS,
					Capabilities::MANAGE_BOOKINGS,
					Capabilities::CREATE_BOOKING,
					Capabilities::MODIFY_BOOKING,
					Capabilities::CANCEL_BOOKING,
				),
			),
			self::CASHIER         => array(
				'label'        => __( 'Ferry Cashier', 'magepeople-ferry-booking-system' ),
				'capabilities' => array(
					Capabilities::ACCESS_DASHBOARD,
					Capabilities::MANAGE_BOOKINGS,
					Capabilities::CREATE_BOOKING,
				),
			),
		);

		/**
		 * Filters the roles provisioned by MagePeople Ferry Booking System.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{label: string, capabilities: string[]}> $definitions Role definitions.
		 */
		return (array) apply_filters( 'mpfbs_role_definitions', $definitions );
	}

	/**
	 * Creates the plugin roles and grants every capability to administrators.
	 *
	 * Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::definitions() as $role_slug => $definition ) {
			$capability_map = array( 'read' => true );

			foreach ( $definition['capabilities'] as $capability ) {
				$capability_map[ $capability ] = true;
			}

			$existing = get_role( $role_slug );

			if ( null === $existing ) {
				add_role( $role_slug, $definition['label'], $capability_map );
				continue;
			}

			foreach ( array_keys( $capability_map ) as $capability ) {
				$existing->add_cap( $capability );
			}

			// Drop capabilities that are no longer part of the definition.
			foreach ( Capabilities::all() as $capability ) {
				if ( ! isset( $capability_map[ $capability ] ) && $existing->has_cap( $capability ) ) {
					$existing->remove_cap( $capability );
				}
			}
		}

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			foreach ( Capabilities::all() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		update_option( self::SIGNATURE_OPTION, self::signature(), false );
	}

	/**
	 * Removes plugin roles and revokes plugin capabilities from every other role.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( array_keys( self::definitions() ) as $role_slug ) {
			remove_role( $role_slug );
		}

		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( Capabilities::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::SIGNATURE_OPTION );
	}

	/**
	 * Re-provisions roles when the capability map has changed since last run.
	 *
	 * @return void
	 */
	public static function maybe_refresh(): void {
		if ( get_option( self::SIGNATURE_OPTION ) === self::signature() ) {
			return;
		}

		self::install();
	}

	/**
	 * Builds a stable signature of the current role/capability map.
	 *
	 * @return string
	 */
	private static function signature(): string {
		$map = array();

		foreach ( self::definitions() as $role_slug => $definition ) {
			$capabilities = $definition['capabilities'];
			sort( $capabilities );
			$map[ $role_slug ] = $capabilities;
		}

		ksort( $map );

		return md5( (string) wp_json_encode( $map ) . '|' . MPFBS_VERSION );
	}
}
