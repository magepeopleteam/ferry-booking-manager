<?php
/**
 * Tax, fee and discount configuration.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Pricing;

use FBM\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The commercial rules that sit on top of a fare.
 *
 * Kept apart from the pricing engine so that "what do we charge" and "how do we
 * work it out" can be reasoned about separately: the engine is pure arithmetic
 * over these values, which makes it testable without a database.
 */
final class PricingSettings {

	/**
	 * Option holding the settings array.
	 */
	private const OPTION = 'pricing';

	/**
	 * Tax is added on top of the fare.
	 */
	public const TAX_EXCLUSIVE = 'exclusive';

	/**
	 * Tax is already inside the fare.
	 */
	public const TAX_INCLUSIVE = 'inclusive';

	/**
	 * Returns the stored settings, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = Options::get_array( self::OPTION );

		$defaults = array(
			'tax_enabled'         => false,
			'tax_rate'            => 0.0,
			'tax_mode'            => self::TAX_EXCLUSIVE,
			'tax_label'           => __( 'VAT', 'magepeople-ferry-booking-system' ),
			'tax_applies_to_fees' => true,
			'booking_fee'         => 0,
			'passenger_fee'       => 0,
			'vehicle_fee'         => 0,
			'fee_label'           => __( 'Booking fee', 'magepeople-ferry-booking-system' ),
			'return_discount'     => 0.0,
			'group_discount_from' => 0,
			'group_discount'      => 0.0,
			'rounding'            => 'none',
		);

		$settings = array_merge( $defaults, $stored );

		/**
		 * Filters the pricing settings used for every quote.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $settings Resolved settings.
		 */
		return (array) apply_filters( 'fbm_pricing_settings', $settings );
	}

	/**
	 * Stores the settings, dropping anything not recognised.
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 * @return array<string, mixed> The settings actually stored.
	 */
	public static function save( array $input ): array {
		$current = self::all();

		$clean = array(
			'tax_enabled'         => ! empty( $input['tax_enabled'] ),
			'tax_rate'            => self::rate( $input['tax_rate'] ?? $current['tax_rate'] ),
			'tax_mode'            => self::TAX_INCLUSIVE === ( $input['tax_mode'] ?? '' ) ? self::TAX_INCLUSIVE : self::TAX_EXCLUSIVE,
			'tax_label'           => sanitize_text_field( (string) ( $input['tax_label'] ?? $current['tax_label'] ) ),
			'tax_applies_to_fees' => ! empty( $input['tax_applies_to_fees'] ),
			'booking_fee'         => self::money( $input['booking_fee'] ?? $current['booking_fee'] ),
			'passenger_fee'       => self::money( $input['passenger_fee'] ?? $current['passenger_fee'] ),
			'vehicle_fee'         => self::money( $input['vehicle_fee'] ?? $current['vehicle_fee'] ),
			'fee_label'           => sanitize_text_field( (string) ( $input['fee_label'] ?? $current['fee_label'] ) ),
			'return_discount'     => self::rate( $input['return_discount'] ?? $current['return_discount'] ),
			'group_discount_from' => max( 0, min( 99, (int) ( $input['group_discount_from'] ?? $current['group_discount_from'] ) ) ),
			'group_discount'      => self::rate( $input['group_discount'] ?? $current['group_discount'] ),
			'rounding'            => in_array( $input['rounding'] ?? '', array( 'none', 'up', 'nearest' ), true ) ? (string) $input['rounding'] : 'none',
		);

		Options::set( self::OPTION, $clean );

		return self::all();
	}

	/**
	 * Clamps a percentage into a sane range.
	 *
	 * @param mixed $value Raw percentage.
	 * @return float
	 */
	private static function rate( $value ): float {
		$rate = is_numeric( $value ) ? (float) $value : 0.0;

		return max( 0.0, min( 100.0, round( $rate, 4 ) ) );
	}

	/**
	 * Reads an amount in minor units.
	 *
	 * @param mixed $value Raw amount.
	 * @return int
	 */
	private static function money( $value ): int {
		return max( 0, (int) round( (float) $value ) );
	}
}
