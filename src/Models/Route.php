<?php
/**
 * Route entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Models;

use FBM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A repeatable journey between two ports.
 */
final class Route extends Entity {
	public const POST_TYPE = 'fbm_route';

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
			'plural'   => __( 'Routes', 'ferry-booking-manager' ),
			'singular' => __( 'Route', 'ferry-booking-manager' ),
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
				'meta'        => '_fbm_route_code',
				'unique'      => true,
				'max'         => 24,
				'searchable'  => true,
				'description' => __( 'Route code', 'ferry-booking-manager' ),
			),
			'origin_port'        => array(
				'type'        => 'id',
				'meta'        => '_fbm_origin_port',
				'references'  => Port::POST_TYPE,
				'required'    => true,
				'description' => __( 'Origin port', 'ferry-booking-manager' ),
			),
			'destination_port'   => array(
				'type'        => 'id',
				'meta'        => '_fbm_destination_port',
				'references'  => Port::POST_TYPE,
				'required'    => true,
				'description' => __( 'Destination port', 'ferry-booking-manager' ),
			),
			'intermediate_ports' => array(
				'type'        => 'id_list',
				'meta'        => '_fbm_intermediate_ports',
				'description' => __( 'Intermediate ports', 'ferry-booking-manager' ),
			),
			'duration'           => array(
				'type'        => 'int',
				'meta'        => '_fbm_duration',
				'required'    => true,
				'min'         => 1,
				'max'         => 20160,
				'description' => __( 'Duration (minutes)', 'ferry-booking-manager' ),
			),
			'distance'           => array(
				'type'        => 'float',
				'meta'        => '_fbm_distance',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Distance (nautical miles)', 'ferry-booking-manager' ),
			),
			'default_vessel'     => array(
				'type'        => 'id',
				'meta'        => '_fbm_default_vessel',
				'references'  => Vessel::POST_TYPE,
				'description' => __( 'Default vessel', 'ferry-booking-manager' ),
			),
			'allows_vehicles'    => array(
				'type'        => 'bool',
				'meta'        => '_fbm_allows_vehicles',
				'default'     => true,
				'description' => __( 'Accepts vehicles', 'ferry-booking-manager' ),
			),
			'passenger_prices'   => array(
				'type'        => 'map',
				'map_of'      => 'money',
				'meta'        => '_fbm_route_passenger_prices',
				'default'     => array(),
				'description' => __( 'Passenger fares by type, in minor units', 'ferry-booking-manager' ),
			),
			'vehicle_prices'     => array(
				'type'        => 'map',
				'map_of'      => 'money',
				'meta'        => '_fbm_route_vehicle_prices',
				'default'     => array(),
				'description' => __( 'Vehicle fares by type, in minor units', 'ferry-booking-manager' ),
			),
			'description'        => array(
				'type'        => 'text',
				'meta'        => '_fbm_description',
				'max'         => 2000,
				'description' => __( 'Description', 'ferry-booking-manager' ),
			),
			'status'             => array(
				'type'        => 'enum',
				'meta'        => '_fbm_status',
				'enum'        => array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ),
				'default'     => self::STATUS_ACTIVE,
				'description' => __( 'Status', 'ferry-booking-manager' ),
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
