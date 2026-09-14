<?php
/**
 * Passenger type entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Models;

use FBM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A category of traveller a sailing can be sold to.
 *
 * Passenger types carry three separate concerns that operators routinely
 * conflate: who qualifies (the age band), what they cost (the fare rule), and
 * what they consume (the capacity impact). An infant on a lap qualifies by age,
 * is usually free, and takes no seat — three independent switches, so they are
 * three independent fields rather than one hard-coded "infant" special case.
 */
final class PassengerType extends Entity {
	public const POST_TYPE = 'fbm_passenger_type';

	public const STATUS_ACTIVE = 'active';

	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Fare is the amount stored on the type.
	 */
	public const PRICE_FIXED = 'fixed';

	/**
	 * Fare is a percentage of the base passenger type's fare.
	 */
	public const PRICE_PERCENT = 'percent';

	/**
	 * Fare is always zero, whatever the base type costs.
	 */
	public const PRICE_FREE = 'free';

	/**
	 * Age is not recorded, so the band never applies.
	 */
	public const NO_AGE_LIMIT = -1;

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'passenger_type';
	}

	/**
	 * Returns the post type storing the entity.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * Returns the capability required to write the entity.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Passenger types', 'ferry-booking-manager' ),
			'singular' => __( 'Passenger type', 'ferry-booking-manager' ),
		);
	}

	/**
	 * Returns the raw field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definition(): array {
		return array(
			'code'            => array(
				'type'        => 'string',
				'meta'        => '_fbm_pt_code',
				'unique'      => true,
				'required'    => true,
				'min'         => 2,
				'max'         => 20,
				'searchable'  => true,
				'description' => __( 'Passenger type code', 'ferry-booking-manager' ),
			),
			'min_age'         => array(
				'type'        => 'int',
				'meta'        => '_fbm_pt_min_age',
				'default'     => self::NO_AGE_LIMIT,
				'min'         => self::NO_AGE_LIMIT,
				'max'         => 130,
				'description' => __( 'Minimum age', 'ferry-booking-manager' ),
			),
			'max_age'         => array(
				'type'        => 'int',
				'meta'        => '_fbm_pt_max_age',
				'default'     => self::NO_AGE_LIMIT,
				'min'         => self::NO_AGE_LIMIT,
				'max'         => 130,
				'description' => __( 'Maximum age', 'ferry-booking-manager' ),
			),
			'requires_dob'    => array(
				'type'        => 'bool',
				'meta'        => '_fbm_pt_requires_dob',
				'default'     => false,
				'description' => __( 'Require date of birth', 'ferry-booking-manager' ),
			),
			'requires_adult'  => array(
				'type'        => 'bool',
				'meta'        => '_fbm_pt_requires_adult',
				'default'     => false,
				'description' => __( 'Must travel with an adult', 'ferry-booking-manager' ),
			),
			'occupies_seat'   => array(
				'type'        => 'bool',
				'meta'        => '_fbm_pt_occupies_seat',
				'default'     => true,
				'description' => __( 'Occupies a passenger seat', 'ferry-booking-manager' ),
			),
			'is_base'         => array(
				'type'        => 'bool',
				'meta'        => '_fbm_pt_is_base',
				'default'     => false,
				'description' => __( 'Base fare for percentage pricing', 'ferry-booking-manager' ),
			),
			'price_mode'      => array(
				'type'        => 'enum',
				'meta'        => '_fbm_pt_price_mode',
				'enum'        => array( self::PRICE_FIXED, self::PRICE_PERCENT, self::PRICE_FREE ),
				'default'     => self::PRICE_FIXED,
				'description' => __( 'Fare mode', 'ferry-booking-manager' ),
			),
			'base_price'      => array(
				'type'        => 'money',
				'meta'        => '_fbm_pt_base_price',
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Fare', 'ferry-booking-manager' ),
			),
			'price_percent'   => array(
				'type'        => 'float',
				'meta'        => '_fbm_pt_price_percent',
				'default'     => 100.0,
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Percentage of the base fare', 'ferry-booking-manager' ),
			),
			'min_per_booking' => array(
				'type'        => 'int',
				'meta'        => '_fbm_pt_min_per_booking',
				'default'     => 0,
				'min'         => 0,
				'max'         => 99,
				'description' => __( 'Minimum per booking', 'ferry-booking-manager' ),
			),
			'max_per_booking' => array(
				'type'        => 'int',
				'meta'        => '_fbm_pt_max_per_booking',
				'default'     => 9,
				'min'         => 0,
				'max'         => 99,
				'description' => __( 'Maximum per booking', 'ferry-booking-manager' ),
			),
			'sort_order'      => array(
				'type'        => 'int',
				'meta'        => '_fbm_pt_sort_order',
				'default'     => 0,
				'min'         => 0,
				'max'         => 999,
				'description' => __( 'Display order', 'ferry-booking-manager' ),
			),
			'description'     => array(
				'type'        => 'text',
				'meta'        => '_fbm_pt_description',
				'max'         => 500,
				'description' => __( 'Description shown to customers', 'ferry-booking-manager' ),
			),
			'status'          => array(
				'type'        => 'enum',
				'meta'        => '_fbm_status',
				'enum'        => array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ),
				'default'     => self::STATUS_ACTIVE,
				'description' => __( 'Status', 'ferry-booking-manager' ),
			),
		);
	}

	/**
	 * Returns the passenger type code.
	 *
	 * @return string
	 */
	public function code(): string {
		return (string) $this->get( 'code' );
	}

	/**
	 * Determines whether the type may be sold.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->get( 'status' );
	}

	/**
	 * Determines whether the type consumes passenger capacity.
	 *
	 * @return bool
	 */
	public function occupies_seat(): bool {
		return (bool) $this->get( 'occupies_seat' );
	}

	/**
	 * Determines whether an age falls inside this type's band.
	 *
	 * An unset bound means "no limit in that direction", so a type with neither
	 * bound accepts every age — which is what an operator who does not collect
	 * dates of birth actually wants.
	 *
	 * @param int $age Age in years.
	 * @return bool
	 */
	public function accepts_age( int $age ): bool {
		$min = (int) $this->get( 'min_age' );
		$max = (int) $this->get( 'max_age' );

		if ( self::NO_AGE_LIMIT !== $min && $age < $min ) {
			return false;
		}

		if ( self::NO_AGE_LIMIT !== $max && $age > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the fare for one passenger of this type, in minor units.
	 *
	 * @param int $base_minor Fare of the base passenger type, in minor units.
	 * @return int
	 */
	public function fare( int $base_minor = 0 ): int {
		switch ( (string) $this->get( 'price_mode' ) ) {
			case self::PRICE_FREE:
				return 0;

			case self::PRICE_PERCENT:
				return (int) round( $base_minor * ( (float) $this->get( 'price_percent' ) / 100 ) );

			default:
				return (int) $this->get( 'base_price' );
		}
	}

	/**
	 * Recomputes derived fields immediately before the entity is persisted.
	 *
	 * @return void
	 */
	public function derive(): void {
		$min = (int) $this->get( 'min_age' );
		$max = (int) $this->get( 'max_age' );

		// An inverted band would silently accept nobody; normalise instead of
		// storing a range that can never match a passenger.
		if ( self::NO_AGE_LIMIT !== $min && self::NO_AGE_LIMIT !== $max && $max < $min ) {
			$this->set( 'max_age', $min );
		}

		if ( self::PRICE_FREE === $this->get( 'price_mode' ) ) {
			$this->set( 'base_price', 0 );
		}
	}
}
