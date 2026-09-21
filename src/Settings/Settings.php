<?php
/**
 * Settings store.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Settings;

use MPFBS\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's configuration: general behaviour, booking rules, the
 * checkout engine, payment defaults and the email preferences.
 *
 * Every value is stored through the prefixed option helper so uninstall can
 * find it again, and every write is whitelisted here — a crafted request can
 * only ever set a field declared in SettingsPanels.
 */
final class Settings {

	/**
	 * Option holding the settings array.
	 */
	private const OPTION = 'settings';

	/**
	 * Checkout is handled by the plugin's own flow.
	 */
	public const CHECKOUT_NATIVE = 'native';

	/**
	 * Checkout is handed to WooCommerce.
	 */
	public const CHECKOUT_WOOCOMMERCE = 'woocommerce';

	/**
	 * Defaults, memoised for the request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $defaults = null;

	/**
	 * Returns the default value of every declared setting.
	 *
	 * Building the tab declarations means constructing a couple of hundred small
	 * objects, and the settings are read on nearly every request, so the result
	 * is held for the rest of the request. The static also breaks a recursion:
	 * a plugin filtering the tabs is free to read the settings while doing so.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		if ( null !== self::$defaults ) {
			return self::$defaults;
		}

		// Assigned before the fields are walked, so that a filter callback
		// reading the settings during declaration sees an empty set rather than
		// re-entering this method for ever.
		self::$defaults = array();

		$defaults = array();

		foreach ( SettingsPanels::fields() as $key => $field ) {
			$defaults[ $key ] = $field->default;
		}

		self::$defaults = $defaults;

		return $defaults;
	}

	/**
	 * Returns the stored settings, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = Options::get_array( self::OPTION );

		$settings = array_merge( self::defaults(), $stored );

		/**
		 * Filters the resolved settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $settings Resolved settings.
		 */
		return (array) apply_filters( 'mpfbs_settings', $settings );
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the setting is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Stores the settings, dropping anything not recognised.
	 *
	 * Each field sanitises itself and is given the value it currently holds, so
	 * a payload that mentions one tab leaves every other tab alone. Saving the
	 * General tab used to switch off guest checkout and all four booking emails,
	 * because an absent checkbox read as "off".
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 * @return array<string, mixed> The settings actually stored.
	 */
	public static function save( array $input ): array {
		$current = self::all();
		$clean   = array();

		foreach ( SettingsPanels::fields() as $key => $field ) {
			$clean[ $key ] = $field->sanitize( $input, $current[ $key ] ?? $field->default );
		}

		Options::set( self::OPTION, $clean );

		return self::all();
	}

	/**
	 * Returns the settings the public booking form needs.
	 *
	 * @return array<string, mixed>
	 */
	public static function public_context(): array {
		$settings = self::all();

		return array(
			'companyName'           => (string) $settings['company_name'],
			'requirePhone'          => (bool) $settings['require_phone'],
			'checkoutEngine'        => (string) $settings['checkout_engine'],
			'termsUrl'              => (string) $settings['terms_url'],
			'cancellationPolicyUrl' => (string) $settings['cancellation_policy_url'],
			'primaryColor'          => (string) $settings['frontend_primary_color'],
			'requireTerms'          => (bool) $settings['require_terms'] && '' !== (string) $settings['terms_url'],
			'vehiclesEnabled'       => (bool) $settings['vehicles_enabled'],
			'showRemainingSeats'    => (bool) $settings['show_remaining_seats'],
			'lowAvailabilityAt'     => (int) $settings['capacity_warning_percent'],
			'lowAvailabilityLeft'   => (int) $settings['low_availability_places'],
			'maxPassengers'         => (int) $settings['max_passengers_per_booking'],
			'maxVehicles'           => (int) $settings['max_vehicles_per_booking'],
			'analyticsEvents'       => (bool) $settings['analytics_events'],
			'measurementId'         => (string) $settings['ga4_measurement_id'],
		);
	}
}
