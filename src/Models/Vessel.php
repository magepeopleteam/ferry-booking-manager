<?php
/**
 * Vessel entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Models;

use MPFBS\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A ferry, and the capacity it brings to any sailing it is assigned to.
 */
final class Vessel extends Entity {
	public const POST_TYPE = 'mpfbs_vessel';

	public const STATUS_ACTIVE = 'active';

	public const STATUS_MAINTENANCE = 'maintenance';

	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'vessel';
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
		return Capabilities::MANAGE_VESSELS;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Vessels', 'magepeople-ferry-booking-system' ),
			'singular' => __( 'Vessel', 'magepeople-ferry-booking-system' ),
		);
	}

	/**
	 * Returns the raw field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definition(): array {
		return array(
			'code'               => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_vessel_code',
				'unique'      => true,
				'required'    => true,
				'min'         => 2,
				'max'         => 16,
				'searchable'  => true,
				'description' => __( 'Vessel code', 'magepeople-ferry-booking-system' ),
			),
			'registration'       => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_registration',
				'max'         => 64,
				'searchable'  => true,
				'description' => __( 'Registration number', 'magepeople-ferry-booking-system' ),
			),
			'passenger_capacity' => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_passenger_capacity',
				'required'    => true,
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Passenger capacity', 'magepeople-ferry-booking-system' ),
			),
			'vehicle_capacity'   => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_vehicle_capacity',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Vehicle capacity', 'magepeople-ferry-booking-system' ),
			),
			'deck_capacity'      => array(
				'type'        => 'float',
				'meta'        => '_mpfbs_deck_capacity',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Vehicle deck capacity (lane metres)', 'magepeople-ferry-booking-system' ),
			),
			'crew_capacity'      => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_crew_capacity',
				'min'         => 0,
				'max'         => 10000,
				'description' => __( 'Crew capacity', 'magepeople-ferry-booking-system' ),
			),
			'speed_knots'        => array(
				'type'        => 'float',
				'meta'        => '_mpfbs_speed_knots',
				'min'         => 0,
				'max'         => 200,
				'description' => __( 'Service speed (knots)', 'magepeople-ferry-booking-system' ),
			),
			'facilities'         => array(
				'type'        => 'string_list',
				'meta'        => '_mpfbs_facilities',
				'description' => __( 'Facilities', 'magepeople-ferry-booking-system' ),
			),
			'images'             => array(
				'type'        => 'id_list',
				'meta'        => '_mpfbs_images',
				'description' => __( 'Gallery images', 'magepeople-ferry-booking-system' ),
			),
			'description'        => array(
				'type'        => 'text',
				'meta'        => '_mpfbs_description',
				'max'         => 2000,
				'description' => __( 'Description', 'magepeople-ferry-booking-system' ),
			),
			'status'             => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_status',
				'enum'        => array( self::STATUS_ACTIVE, self::STATUS_MAINTENANCE, self::STATUS_INACTIVE ),
				'default'     => self::STATUS_ACTIVE,
				'description' => __( 'Status', 'magepeople-ferry-booking-system' ),
			),
		);
	}

	/**
	 * Returns the passenger capacity.
	 *
	 * @return int
	 */
	public function passenger_capacity(): int {
		return (int) $this->get( 'passenger_capacity' );
	}

	/**
	 * Returns the vehicle capacity.
	 *
	 * @return int
	 */
	public function vehicle_capacity(): int {
		return (int) $this->get( 'vehicle_capacity' );
	}

	/**
	 * Returns the vehicle deck capacity in lane metres.
	 *
	 * @return float
	 */
	public function deck_capacity(): float {
		return (float) $this->get( 'deck_capacity' );
	}

	/**
	 * Determines whether the vessel may be assigned to new sailings.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->get( 'status' );
	}
}
