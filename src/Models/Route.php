<?php
/**
 * Route entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Models;

use MPFBS\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A repeatable journey between two ports.
 */
final class Route extends Entity {
	public const POST_TYPE = 'mpfbs_route';

	public const STATUS_ACTIVE = 'active';

	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'route';
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
		return Capabilities::MANAGE_ROUTES;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Routes', 'magepeople-ferry-booking-system' ),
			'singular' => __( 'Route', 'magepeople-ferry-booking-system' ),
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
				'meta'        => '_mpfbs_route_code',
				'unique'      => true,
				'max'         => 24,
				'searchable'  => true,
				'description' => __( 'Route code', 'magepeople-ferry-booking-system' ),
			),
			'origin_port'        => array(
				'type'        => 'id',
				'meta'        => '_mpfbs_origin_port',
				'references'  => Port::POST_TYPE,
				'required'    => true,
				'description' => __( 'Origin port', 'magepeople-ferry-booking-system' ),
			),
			'destination_port'   => array(
				'type'        => 'id',
				'meta'        => '_mpfbs_destination_port',
				'references'  => Port::POST_TYPE,
				'required'    => true,
				'description' => __( 'Destination port', 'magepeople-ferry-booking-system' ),
			),
			'intermediate_ports' => array(
				'type'        => 'id_list',
				'meta'        => '_mpfbs_intermediate_ports',
				'description' => __( 'Intermediate ports', 'magepeople-ferry-booking-system' ),
			),
			'duration'           => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_duration',
				'required'    => true,
				'min'         => 1,
				'max'         => 20160,
				'description' => __( 'Duration (minutes)', 'magepeople-ferry-booking-system' ),
			),
			'distance'           => array(
				'type'        => 'float',
				'meta'        => '_mpfbs_distance',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Distance (nautical miles)', 'magepeople-ferry-booking-system' ),
			),
			'default_vessel'     => array(
				'type'        => 'id',
				'meta'        => '_mpfbs_default_vessel',
				'references'  => Vessel::POST_TYPE,
				'description' => __( 'Default vessel', 'magepeople-ferry-booking-system' ),
			),
			'allows_vehicles'    => array(
				'type'        => 'bool',
				'meta'        => '_mpfbs_allows_vehicles',
				'default'     => true,
				'description' => __( 'Accepts vehicles', 'magepeople-ferry-booking-system' ),
			),
			'passenger_prices'   => array(
				'type'        => 'map',
				'map_of'      => 'money',
				'meta'        => '_mpfbs_route_passenger_prices',
				'default'     => array(),
				'description' => __( 'Passenger fares by type, in minor units', 'magepeople-ferry-booking-system' ),
			),
			'vehicle_prices'     => array(
				'type'        => 'map',
				'map_of'      => 'money',
				'meta'        => '_mpfbs_route_vehicle_prices',
				'default'     => array(),
				'description' => __( 'Vehicle fares by type, in minor units', 'magepeople-ferry-booking-system' ),
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
				'enum'        => array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ),
				'default'     => self::STATUS_ACTIVE,
				'description' => __( 'Status', 'magepeople-ferry-booking-system' ),
			),
		);
	}

	/**
	 * Returns the origin port id.
	 *
	 * @return int
	 */
	public function origin_port(): int {
		return (int) $this->get( 'origin_port' );
	}

	/**
	 * Returns the destination port id.
	 *
	 * @return int
	 */
	public function destination_port(): int {
		return (int) $this->get( 'destination_port' );
	}

	/**
	 * Returns the scheduled duration in minutes.
	 *
	 * @return int
	 */
	public function duration(): int {
		return (int) $this->get( 'duration' );
	}

	/**
	 * Determines whether the route may be scheduled.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->get( 'status' );
	}
}
