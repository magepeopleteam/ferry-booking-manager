<?php
/**
 * Editable staff permissions.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Security;

use MPFBS\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an operator adjust what each ferry role may do.
 *
 * The shipped roles are a sensible starting point, not a description of any
 * particular operator. A small company may want the person on the desk to be
 * able to cancel a booking; a larger one may want check-in staff to see nothing
 * else at all. Rather than shipping more roles, the five are adjustable.
 *
 * Overrides are stored as a sparse difference from the shipped definition, so a
 * capability added to a role in a later version still reaches an operator who
 * had customised a different capability.
 */
final class RoleSettings {

	/**
	 * Option holding the overrides.
	 */
	private const OPTION = 'role_overrides';

	/**
	 * Whether the overrides are currently suspended.
	 *
	 * @var bool
	 */
	private static bool $suspended = false;

	/**
	 * Attaches the filter that applies the overrides.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'mpfbs_role_definitions', array( $this, 'apply' ) );
	}

	/**
	 * Applies the stored overrides to the shipped role definitions.
	 *
	 * @param array<string, array{label: string, capabilities: string[]}> $definitions Role definitions.
	 * @return array<string, array{label: string, capabilities: string[]}>
	 */
	public function apply( $definitions ): array {
		$definitions = is_array( $definitions ) ? $definitions : array();

		if ( self::$suspended ) {
			return $definitions;
		}

		$overrides = self::overrides();

		if ( array() === $overrides ) {
			return $definitions;
		}

		$known = Capabilities::all();

		foreach ( $definitions as $slug => $definition ) {
			if ( ! isset( $overrides[ $slug ] ) ) {
				continue;
			}

			$capabilities = array_values( array_intersect( (array) ( $definition['capabilities'] ?? array() ), $known ) );

			foreach ( $overrides[ $slug ] as $capability => $granted ) {
				if ( ! in_array( $capability, $known, true ) ) {
					continue;
				}

				if ( $granted ) {
					$capabilities[] = $capability;
					continue;
				}

				$capabilities = array_diff( $capabilities, array( $capability ) );
			}

			$definitions[ $slug ]['capabilities'] = array_values( array_unique( $capabilities ) );
		}

		return $definitions;
	}

	/**
	 * Describes the permission matrix for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public static function matrix(): array {
		$labels = Capabilities::labels();
		$roles  = array();

		foreach ( Roles::definitions() as $slug => $definition ) {
			$granted = (array) ( $definition['capabilities'] ?? array() );
			$members = get_users(
				array(
					'role'   => $slug,
					'fields' => 'ID',
					'number' => 100,
				)
			);

			$roles[] = array(
				'slug'         => (string) $slug,
				'label'        => (string) ( $definition['label'] ?? $slug ),
				'users'        => count( $members ),
				'capabilities' => array_values( array_map( 'strval', $granted ) ),
			);
		}

		$capabilities = array();

		foreach ( Capabilities::all() as $capability ) {
			$capabilities[] = array(
				'key'   => $capability,
				'label' => (string) ( $labels[ $capability ] ?? $capability ),
			);
		}

		return array(
			'roles'        => $roles,
			'capabilities' => $capabilities,
		);
	}

	/**
	 * Stores a submitted matrix and reprovisions the roles.
	 *
	 * @param array<string, mixed> $input Role slug => capability => granted.
	 * @return void
	 */
	public static function save( array $input ): void {
		$shipped = self::shipped();
		$known   = Capabilities::all();
		$stored  = array();

		foreach ( $input as $slug => $capabilities ) {
			$slug = sanitize_key( (string) $slug );

			if ( ! isset( $shipped[ $slug ] ) || ! is_array( $capabilities ) ) {
				continue;
			}

			$baseline = (array) ( $shipped[ $slug ]['capabilities'] ?? array() );

			foreach ( $capabilities as $capability => $granted ) {
				$capability = sanitize_key( (string) $capability );

				if ( ! in_array( $capability, $known, true ) ) {
					continue;
				}

				$granted    = in_array( $granted, array( true, 1, '1', 'yes', 'true', 'on' ), true );
				$by_default = in_array( $capability, $baseline, true );

				// Only a genuine difference is stored. Recording a capability
				// that already matches the shipped role would freeze it, so a
				// role gaining a permission in a later version would never
				// reach a site that had customised something else.
				if ( $granted === $by_default ) {
					continue;
				}

				$stored[ $slug ][ $capability ] = $granted;
			}
		}

		if ( array() === $stored ) {
			Options::delete( self::OPTION );
		} else {
			Options::set( self::OPTION, $stored );
		}

		// Reprovision so the change reaches WordPress itself rather than only
		// this plugin's view of the roles.
		Roles::install();
	}

	/**
	 * Returns the stored overrides.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function overrides(): array {
		$stored = Options::get_array( self::OPTION );
		$clean  = array();

		foreach ( $stored as $slug => $capabilities ) {
			if ( ! is_array( $capabilities ) ) {
				continue;
			}

			foreach ( $capabilities as $capability => $granted ) {
				$clean[ (string) $slug ][ (string) $capability ] = (bool) $granted;
			}
		}

		return $clean;
	}

	/**
	 * Returns the shipped definitions, ignoring the stored overrides.
	 *
	 * Comparing a submitted matrix against the already-overridden definitions
	 * would make every stored override permanent after the first save. The
	 * filter is suspended with a flag rather than detached, because WordPress
	 * identifies an object callback by the instance that registered it and this
	 * class has no way to reach that instance.
	 *
	 * @return array<string, array{label: string, capabilities: string[]}>
	 */
	private static function shipped(): array {
		self::$suspended = true;

		try {
			return Roles::definitions();
		} finally {
			self::$suspended = false;
		}
	}
}
