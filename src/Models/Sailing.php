<?php
/**
 * Sailing entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Models;

use MPFBS\Security\Capabilities;
use MPFBS\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * One dated departure of a route on a vessel.
 * Departure and arrival are authored and stored as site-local wall-clock times,
 * because an 08:00 sailing must stay an 08:00 sailing even if the site timezone
 * is later corrected. Sortable UTC timestamps and local dates are derived from
 * them on save and kept in dedicated scalar meta keys, so listings and date
 * queries never have to parse a serialised value.
 */
final class Sailing extends Entity {
	public const POST_TYPE = 'mpfbs_sailing';

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
			'plural'   => __( 'Sailings', 'magepeople-ferry-booking-system' ),
			'singular' => __( 'Sailing', 'magepeople-ferry-booking-system' ),
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
				'meta'        => '_mpfbs_route_id',
				'references'  => Route::POST_TYPE,
				'required'    => true,
				'description' => __( 'Route', 'magepeople-ferry-booking-system' ),
			),
			'vessel_id'                   => array(
				'type'        => 'id',
				'meta'        => '_mpfbs_vessel_id',
				'references'  => Vessel::POST_TYPE,
				'required'    => true,
				'description' => __( 'Vessel', 'magepeople-ferry-booking-system' ),
			),
			'departure_datetime'          => array(
				'type'        => 'datetime',
				'meta'        => '_mpfbs_departure_datetime',
				'required'    => true,
				'description' => __( 'Departure', 'magepeople-ferry-booking-system' ),
			),
			'arrival_datetime'            => array(
				'type'        => 'datetime',
				'meta'        => '_mpfbs_arrival_datetime',
				'description' => __( 'Arrival', 'magepeople-ferry-booking-system' ),
			),
			'departure_ts'                => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_departure_ts',
				'readonly'    => true,
				'description' => __( 'Departure timestamp (UTC)', 'magepeople-ferry-booking-system' ),
			),
			'arrival_ts'                  => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_arrival_ts',
				'readonly'    => true,
				'description' => __( 'Arrival timestamp (UTC)', 'magepeople-ferry-booking-system' ),
			),
			'departure_date'              => array(
				'type'        => 'date',
				'meta'        => '_mpfbs_departure_date',
				'readonly'    => true,
				'description' => __( 'Departure date', 'magepeople-ferry-booking-system' ),
			),
			'booking_open'                => array(
				'type'        => 'datetime',
				'meta'        => '_mpfbs_booking_open',
				'description' => __( 'Bookings open', 'magepeople-ferry-booking-system' ),
			),
			'booking_close'               => array(
				'type'        => 'datetime',
				'meta'        => '_mpfbs_booking_close',
				'description' => __( 'Bookings close', 'magepeople-ferry-booking-system' ),
			),
			'passenger_capacity_override' => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_passenger_capacity_override',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Passenger capacity override', 'magepeople-ferry-booking-system' ),
			),
			'vehicle_capacity_override'   => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_vehicle_capacity_override',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Vehicle capacity override', 'magepeople-ferry-booking-system' ),
			),
			'deck_capacity_override'      => array(
				'type'        => 'float',
				'meta'        => '_mpfbs_deck_capacity_override',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Lane metre override', 'magepeople-ferry-booking-system' ),
			),
			'price_adjustment_type'       => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_price_adjustment_type',
				'enum'        => array( 'none', 'percent', 'fixed' ),
				'default'     => 'none',
				'description' => __( 'Fare adjustment for this departure', 'magepeople-ferry-booking-system' ),
			),
			'price_adjustment'            => array(
				'type'        => 'float',
				'meta'        => '_mpfbs_price_adjustment',
				'default'     => 0.0,
				'min'         => -100000,
				'max'         => 100000,
				'description' => __( 'Fare adjustment amount', 'magepeople-ferry-booking-system' ),
			),
			'notes'                       => array(
				'type'        => 'text',
				'meta'        => '_mpfbs_notes',
				'max'         => 2000,
				'description' => __( 'Operational notes', 'magepeople-ferry-booking-system' ),
			),
			'status'                      => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_status',
				'enum'        => array(
					self::STATUS_SCHEDULED,
					self::STATUS_DELAYED,
					self::STATUS_DEPARTED,
					self::STATUS_ARRIVED,
					self::STATUS_CANCELLED,
				),
				'default'     => self::STATUS_SCHEDULED,
				'description' => __( 'Status', 'magepeople-ferry-booking-system' ),
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
