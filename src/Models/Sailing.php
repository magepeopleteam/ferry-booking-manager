<?php
/**
 * Sailing entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Models;

use FBM\Security\Capabilities;
use FBM\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * One dated departure of a route on a vessel.
 * Departure and arrival are authored and stored as site-local wall-clock times,
 * because an 08:00 sailing must stay an 08:00 sailing even if the site timezone
 * is later corrected. Sortable UTC timestamps and local dates are derived from
 * them on save and kept in dedicated scalar meta keys, so listings and calendar
 * queries never have to parse a serialised value.
 */
final class Sailing extends Entity {
	public const POST_TYPE = 'fbm_sailing';

	public const STATUS_SCHEDULED = 'scheduled';

	public const STATUS_DELAYED = 'delayed';

	public const STATUS_DEPARTED = 'departed';

	public const STATUS_ARRIVED = 'arrived';

	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'sailing';
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
		return Capabilities::MANAGE_SAILINGS;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Sailings', 'ferry-booking-manager' ),
			'singular' => __( 'Sailing', 'ferry-booking-manager' ),
		);
	}

	/**
	 * Returns the raw field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definition(): array {
		return array(
			'route_id'                    => array(
				'type'        => 'id',
				'meta'        => '_fbm_route_id',
				'references'  => Route::POST_TYPE,
				'required'    => true,
				'description' => __( 'Route', 'ferry-booking-manager' ),
			),
			'vessel_id'                   => array(
				'type'        => 'id',
				'meta'        => '_fbm_vessel_id',
				'references'  => Vessel::POST_TYPE,
				'required'    => true,
				'description' => __( 'Vessel', 'ferry-booking-manager' ),
			),
			'departure_datetime'          => array(
				'type'        => 'datetime',
				'meta'        => '_fbm_departure_datetime',
				'required'    => true,
				'description' => __( 'Departure', 'ferry-booking-manager' ),
			),
			'arrival_datetime'            => array(
				'type'        => 'datetime',
				'meta'        => '_fbm_arrival_datetime',
				'description' => __( 'Arrival', 'ferry-booking-manager' ),
			),
			'departure_ts'                => array(
				'type'        => 'int',
				'meta'        => '_fbm_departure_ts',
				'readonly'    => true,
				'description' => __( 'Departure timestamp (UTC)', 'ferry-booking-manager' ),
			),
			'arrival_ts'                  => array(
				'type'        => 'int',
				'meta'        => '_fbm_arrival_ts',
				'readonly'    => true,
				'description' => __( 'Arrival timestamp (UTC)', 'ferry-booking-manager' ),
			),
			'departure_date'              => array(
				'type'        => 'date',
				'meta'        => '_fbm_departure_date',
				'readonly'    => true,
				'description' => __( 'Departure date', 'ferry-booking-manager' ),
			),
			'booking_open'                => array(
				'type'        => 'datetime',
				'meta'        => '_fbm_booking_open',
				'description' => __( 'Bookings open', 'ferry-booking-manager' ),
			),
			'booking_close'               => array(
				'type'        => 'datetime',
				'meta'        => '_fbm_booking_close',
				'description' => __( 'Bookings close', 'ferry-booking-manager' ),
			),
			'passenger_capacity_override' => array(
				'type'        => 'int',
				'meta'        => '_fbm_passenger_capacity_override',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Passenger capacity override', 'ferry-booking-manager' ),
			),
			'vehicle_capacity_override'   => array(
				'type'        => 'int',
				'meta'        => '_fbm_vehicle_capacity_override',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Vehicle capacity override', 'ferry-booking-manager' ),
			),
			'deck_capacity_override'      => array(
				'type'        => 'float',
				'meta'        => '_fbm_deck_capacity_override',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Lane metre override', 'ferry-booking-manager' ),
			),
			'price_adjustment_type'       => array(
				'type'        => 'enum',
				'meta'        => '_fbm_price_adjustment_type',
				'enum'        => array( 'none', 'percent', 'fixed' ),
				'default'     => 'none',
				'description' => __( 'Fare adjustment for this departure', 'ferry-booking-manager' ),
			),
			'price_adjustment'            => array(
				'type'        => 'float',
				'meta'        => '_fbm_price_adjustment',
				'default'     => 0.0,
				'min'         => -100000,
				'max'         => 100000,
				'description' => __( 'Fare adjustment amount', 'ferry-booking-manager' ),
			),
			'notes'                       => array(
				'type'        => 'text',
				'meta'        => '_fbm_notes',
				'max'         => 2000,
				'description' => __( 'Operational notes', 'ferry-booking-manager' ),
			),
			'status'                      => array(
				'type'        => 'enum',
				'meta'        => '_fbm_status',
				'enum'        => array(
					self::STATUS_SCHEDULED,
					self::STATUS_DELAYED,
					self::STATUS_DEPARTED,
					self::STATUS_ARRIVED,
					self::STATUS_CANCELLED,
				),
				'default'     => self::STATUS_SCHEDULED,
				'description' => __( 'Status', 'ferry-booking-manager' ),
			),
		);
	}

	/**
	 * Recomputes derived fields immediately before the entity is persisted.
	 *
	 * Entities with sortable or grouped projections of an authored value — a
	 * sailing's UTC timestamp, for instance — override this so the projection
	 * can never drift from its source.
	 *
	 * Recomputes the sortable timestamps and the local departure date.
	 *
	 * @return void
	 */
	public function derive(): void {
		$departure = (string) $this->get( 'departure_datetime' );
		$arrival   = (string) $this->get( 'arrival_datetime' );

		$departure_ts = '' === $departure ? 0 : Time::local_to_timestamp( $departure );
		$arrival_ts   = '' === $arrival ? 0 : Time::local_to_timestamp( $arrival );

		/*
		 * A missing arrival, or one earlier than the departure, would describe a
		 * zero-length or negative interval. Vessel overlap detection compares
		 * intervals, so clamping keeps that comparison meaningful even when the
		 * arrival was never supplied.
		 */
		if ( $arrival_ts < $departure_ts ) {
			$arrival_ts = $departure_ts;
		}

		$this->set( 'departure_ts', $departure_ts );
		$this->set( 'arrival_ts', $arrival_ts );
		$this->set( 'departure_date', '' === $departure ? '' : substr( $departure, 0, 10 ) );
	}

	/**
	 * Returns the route id.
	 *
	 * @return int
	 */
	public function route_id(): int {
		return (int) $this->get( 'route_id' );
	}

	/**
	 * Returns the vessel id.
	 *
	 * @return int
	 */
	public function vessel_id(): int {
		return (int) $this->get( 'vessel_id' );
	}

	/**
	 * Returns the UTC departure timestamp.
	 *
	 * @return int
	 */
	public function departure_timestamp(): int {
		$stored = (int) $this->get( 'departure_ts' );

		if ( $stored > 0 ) {
			return $stored;
		}

		$departure = (string) $this->get( 'departure_datetime' );

		return '' === $departure ? 0 : Time::local_to_timestamp( $departure );
	}

	/**
	 * Determines whether the sailing can still take bookings right now.
	 *
	 * @param int|null $now Optional UTC timestamp, defaults to the current time.
	 * @return bool
	 */
	public function is_bookable( ?int $now = null ): bool {
		$now = null === $now ? time() : $now;

		if ( self::STATUS_SCHEDULED !== $this->get( 'status' ) && self::STATUS_DELAYED !== $this->get( 'status' ) ) {
			return false;
		}

		$open  = (string) $this->get( 'booking_open' );
		$close = (string) $this->get( 'booking_close' );

		if ( '' !== $open && $now < Time::local_to_timestamp( $open ) ) {
			return false;
		}

		if ( '' !== $close ) {
			return $now <= Time::local_to_timestamp( $close );
		}

		$departure = $this->departure_timestamp();

		return 0 === $departure || $now <= $departure;
	}
}
