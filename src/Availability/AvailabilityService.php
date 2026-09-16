<?php
/**
 * Availability engine.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Availability;

use FBM\Cache\CacheManager;
use FBM\Models\Booking;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use FBM\Repositories\BookingRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VesselRepository;
use FBM\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The single authority on what is left to sell.
 *
 * Every path that needs to know whether something can be booked — the search
 * results, the booking wizard, the counter form, the importer, Pro's check-in
 * app — asks this class. Nothing recomputes availability its own way, because
 * two implementations of "how full is this sailing" is how a ferry ends up with
 * more passengers than lifejackets.
 *
 * Capacity resolves in one direction only: a sailing override wins over the
 * vessel's own figure, and an override of zero is a real answer meaning "sell
 * none of these on this crossing", not an absent value.
 */
final class AvailabilityService {

	/**
	 * Seconds a snapshot stays cached.
	 *
	 * Short, because it is invalidated on every booking write anyway; the cache
	 * exists to survive a search result asking about the same sailing twice.
	 */
	private const TTL = 60;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Vessel repository.
	 *
	 * @var VesselRepository
	 */
	private VesselRepository $vessels;

	/**
	 * Booking repository.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Snapshots already computed during this request.
	 *
	 * @var array<string, Availability>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * @param SailingRepository $sailings Sailing repository.
	 * @param VesselRepository  $vessels  Vessel repository.
	 * @param BookingRepository $bookings Booking repository.
	 * @param CacheManager      $cache    Cache manager.
	 */
	public function __construct(
		SailingRepository $sailings,
		VesselRepository $vessels,
		BookingRepository $bookings,
		CacheManager $cache
	) {
		$this->sailings = $sailings;
		$this->vessels  = $vessels;
		$this->bookings = $bookings;
		$this->cache    = $cache;
	}

	/**
	 * Returns the availability snapshot for a sailing.
	 *
	 * @param int      $sailing_id Sailing id.
	 * @param int|null $now        Optional UTC timestamp.
	 * @return Availability|WP_Error
	 */
	public function for_sailing( int $sailing_id, ?int $now = null ) {
		$sailing = $this->sailings->find( $sailing_id );

		if ( ! $sailing instanceof Sailing ) {
			return new WP_Error(
				'fbm_sailing_not_found',
				__( 'That sailing could not be found.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 404 )
			);
		}

		return $this->for_sailing_entity( $sailing, $now );
	}

	/**
	 * Returns the availability snapshot for an already-loaded sailing.
	 *
	 * @param Sailing  $sailing Sailing entity.
	 * @param int|null $now     Optional UTC timestamp.
	 * @return Availability
	 */
	public function for_sailing_entity( Sailing $sailing, ?int $now = null ): Availability {
		$now = null === $now ? time() : $now;
		$key = $sailing->id . ':' . intdiv( $now, self::TTL );

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$cached = $this->cache->get( CacheManager::GROUP_AVAILABILITY, $key );

		if ( $cached instanceof Availability ) {
			$this->memo[ $key ] = $cached;

			return $cached;
		}

		$availability = $this->compute( $sailing, $now, 0 );

		$this->cache->set( CacheManager::GROUP_AVAILABILITY, $key, $availability, self::TTL );
		$this->memo[ $key ] = $availability;

		return $availability;
	}

	/**
	 * Returns snapshots for many sailings at once.
	 *
	 * @param Sailing[] $sailings Sailing entities.
	 * @param int|null  $now      Optional UTC timestamp.
	 * @return array<int, Availability> Keyed by sailing id.
	 */
	public function for_sailings( array $sailings, ?int $now = null ): array {
		$now      = null === $now ? time() : $now;
		$snapshot = array();

		foreach ( $sailings as $sailing ) {
			if ( $sailing instanceof Sailing ) {
				$snapshot[ $sailing->id ] = $this->for_sailing_entity( $sailing, $now );
			}
		}

		return $snapshot;
	}

	/**
	 * Recomputes a snapshot from storage, ignoring every cache.
	 *
	 * Used by the reservation path, which must not decide against a snapshot
	 * that predates its own write.
	 *
	 * @param Sailing  $sailing        Sailing entity.
	 * @param int|null $now            Optional UTC timestamp.
	 * @param int      $ignore_above_id Bookings with a higher id are excluded; 0 counts all.
	 * @return Availability
	 */
	public function fresh( Sailing $sailing, ?int $now = null, int $ignore_above_id = 0 ): Availability {
		return $this->compute( $sailing, null === $now ? time() : $now, $ignore_above_id );
	}

	/**
	 * Determines whether a requested amount of inventory can be sold.
	 *
	 * @param Availability             $availability Snapshot to test against.
	 * @param array<string, float|int> $request     Amount requested per measure.
	 * @return true|WP_Error
	 */
	public function check( Availability $availability, array $request ) {
		if ( ! $availability->bookable ) {
			return new WP_Error(
				'fbm_sailing_not_bookable',
				$this->reason_message( $availability->reason ),
				array(
					'status' => 409,
					'reason' => $availability->reason,
				)
			);
		}

		foreach ( $this->measures() as $measure ) {
			$amount = isset( $request[ $measure ] ) ? $request[ $measure ] : 0;

			if ( $availability->fits( $measure, $amount ) ) {
				continue;
			}

			return new WP_Error(
				'fbm_insufficient_capacity',
				$this->shortfall_message( $measure, $availability->remaining( $measure ) ),
				array(
					'status'    => 409,
					'measure'   => $measure,
					'requested' => $amount,
					'remaining' => $availability->remaining( $measure ),
				)
			);
		}

		return true;
	}

	/**
	 * Drops every cached snapshot for a sailing.
	 *
	 * @param int $sailing_id Sailing id, or 0 for all sailings.
	 * @return void
	 */
	public function invalidate( int $sailing_id = 0 ): void {
		unset( $sailing_id );

		$this->memo = array();
		$this->cache->flush_group( CacheManager::GROUP_AVAILABILITY );
		$this->cache->flush_group( CacheManager::GROUP_SEARCH );
	}

	/**
	 * Returns the measures the engine tracks.
	 *
	 * @return string[]
	 */
	public function measures(): array {
		$measures = array(
			Availability::PASSENGERS,
			Availability::VEHICLES,
			Availability::LANE_METRES,
		);

		/**
		 * Filters the inventory measures the availability engine tracks.
		 *
		 * Pro adds cabins here rather than forking the engine.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $measures Measure names.
		 */
		return array_values( array_unique( (array) apply_filters( 'fbm_availability_measures', $measures ) ) );
	}

	/**
	 * Builds a snapshot from storage.
	 *
	 * @param Sailing $sailing         Sailing entity.
	 * @param int     $now             UTC timestamp.
	 * @param int     $ignore_above_id Bookings with a higher id are excluded; 0 counts all.
	 * @return Availability
	 */
	private function compute( Sailing $sailing, int $now, int $ignore_above_id ): Availability {
		$availability = new Availability( $sailing->id, $now );

		$availability->reason   = $this->closed_reason( $sailing, $now );
		$availability->bookable = '' === $availability->reason;

		$capacity = $this->capacity( $sailing );
		$used     = $this->consumption( $sailing->id, $now, $ignore_above_id );

		foreach ( $this->measures() as $measure ) {
			$availability->set(
				$measure,
				$capacity[ $measure ] ?? -1,
				$used['sold'][ $measure ] ?? 0,
				$used['held'][ $measure ] ?? 0
			);
		}

		/**
		 * Filters a computed availability snapshot.
		 *
		 * Fires after capacity and consumption are resolved, so an add-on can
		 * apply its own overrides — a stop-sell rule, a cabin allocation —
		 * without recomputing anything the engine already knows.
		 *
		 * @since 1.0.0
		 *
		 * @param Availability $availability Snapshot.
		 * @param Sailing      $sailing      Sailing entity.
		 * @param int          $now          UTC timestamp.
		 */
		return apply_filters( 'fbm_availability', $availability, $sailing, $now );
	}

	/**
	 * Resolves the capacity of every measure for a sailing.
	 *
	 * @param Sailing $sailing Sailing entity.
	 * @return array<string, float|int>
	 */
	private function capacity( Sailing $sailing ): array {
		$vessel = $this->vessels->find( $sailing->vessel_id() );

		$passengers = $this->resolve_override(
			$sailing->get( 'passenger_capacity_override' ),
			$vessel instanceof Vessel ? (int) $vessel->get( 'passenger_capacity' ) : 0
		);

		$vehicles = $this->resolve_override(
			$sailing->get( 'vehicle_capacity_override' ),
			$vessel instanceof Vessel ? (int) $vessel->get( 'vehicle_capacity' ) : 0
		);

		$lane_metres = $this->resolve_override(
			$sailing->get( 'deck_capacity_override' ),
			$vessel instanceof Vessel ? (float) $vessel->get( 'deck_capacity' ) : 0.0
		);

		// Places the operator keeps back for crew, staff travel or a safety
		// margin. Subtracted here rather than at the point of sale so that they
		// are invisible on every channel at once: the search results, the
		// booking form, the dashboard and any add-on all ask this one engine
		// what a sailing holds.
		$settings   = Settings::all();
		$passengers = $this->hold_back( $passengers, (int) $settings['seats_held_back'] );
		$vehicles   = $this->hold_back( $vehicles, (int) $settings['vehicle_spaces_held_back'] );

		$capacity = array(
			Availability::PASSENGERS  => $passengers,
			Availability::VEHICLES    => $vehicles,
			// A vessel that does not declare a deck length is not selling by
			// lane metre, so the measure is unlimited rather than zero — the
			// vehicle slot count is what constrains it.
			Availability::LANE_METRES => $lane_metres > 0 ? $lane_metres : -1,
		);

		/**
		 * Filters the resolved capacity of a sailing.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, float|int> $capacity Capacity per measure.
		 * @param Sailing                  $sailing  Sailing entity.
		 * @param Vessel|null              $vessel   Vessel entity, when it still exists.
		 */
		return (array) apply_filters( 'fbm_sailing_capacity', $capacity, $sailing, $vessel instanceof Vessel ? $vessel : null );
	}

	/**
	 * Removes held-back places from a declared capacity.
	 *
	 * A capacity of -1 means "not declared, so not a constraint", and holding
	 * places back from an unlimited measure would silently turn it into a limit
	 * of zero. A hold-back larger than the vessel leaves nothing for sale, which
	 * is what the operator asked for.
	 *
	 * @param int|float $capacity  Declared capacity.
	 * @param int       $held_back Places to withhold.
	 * @return int|float
	 */
	private function hold_back( $capacity, int $held_back ) {
		if ( $held_back <= 0 || $capacity < 0 ) {
			return $capacity;
		}

		return max( 0, $capacity - $held_back );
	}

	/**
	 * Chooses between a sailing override and the vessel default.
	 *
	 * @param mixed     $override Stored override.
	 * @param float|int $fallback Vessel figure.
	 * @return float|int
	 */
	private function resolve_override( $override, $fallback ) {
		// An override is only stored when an operator sets one, and zero is a
		// legitimate setting: "this crossing carries no vehicles at all".
		if ( null !== $override && '' !== $override && $override > 0 ) {
			return $override;
		}

		return $fallback;
	}

	/**
	 * Sums the inventory consumed on a sailing.
	 *
	 * @param int $sailing_id      Sailing id.
	 * @param int $now             UTC timestamp.
	 * @param int $ignore_above_id Bookings with a higher id are excluded; 0 counts all.
	 * @return array{sold: array<string, float|int>, held: array<string, float|int>}
	 */
	private function consumption( int $sailing_id, int $now, int $ignore_above_id ): array {
		$zero = array();

		foreach ( $this->measures() as $measure ) {
			$zero[ $measure ] = 0;
		}

		$used = array(
			'sold' => $zero,
			'held' => $zero,
		);

		foreach ( $this->bookings->consuming_for_sailing( $sailing_id ) as $booking ) {
			if ( $ignore_above_id > 0 && $booking->id > $ignore_above_id ) {
				continue;
			}

			// An expired hold stops consuming the instant it lapses, whether or
			// not the cleanup job has caught up with it yet.
			if ( ! $booking->consumes_capacity( $now ) ) {
				continue;
			}

			$bucket = Booking::STATUS_ON_HOLD === $booking->get( 'booking_status' ) ? 'held' : 'sold';

			foreach ( $this->booking_usage( $booking, $sailing_id ) as $measure => $amount ) {
				if ( isset( $used[ $bucket ][ $measure ] ) ) {
					$used[ $bucket ][ $measure ] += $amount;
				}
			}
		}

		return $used;
	}

	/**
	 * Returns how much of each measure one booking consumes on a sailing.
	 *
	 * @param Booking $booking    Booking entity.
	 * @param int     $sailing_id Sailing the usage is being counted for.
	 * @return array<string, float|int>
	 */
	public function usage_of( Booking $booking, int $sailing_id ): array {
		return $this->booking_usage( $booking, $sailing_id );
	}

	/**
	 * Returns the inventory one booking consumes on one sailing.
	 *
	 * @param Booking $booking    Booking entity.
	 * @param int     $sailing_id Sailing being counted.
	 * @return array<string, float|int>
	 */
	private function booking_usage( Booking $booking, int $sailing_id ): array {
		$usage = array(
			Availability::PASSENGERS  => (int) $booking->get( 'passenger_count' ),
			Availability::VEHICLES    => (int) $booking->get( 'vehicle_count' ),
			Availability::LANE_METRES => (float) $booking->get( 'lane_metres' ),
		);

		/**
		 * Filters the inventory one booking consumes on one sailing.
		 *
		 * A return booking touches two sailings; the sailing id is supplied so
		 * an add-on can attribute different usage to each leg.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, float|int> $usage      Usage per measure.
		 * @param Booking                  $booking    Booking entity.
		 * @param int                      $sailing_id Sailing being counted.
		 */
		return (array) apply_filters( 'fbm_booking_usage', $usage, $booking, $sailing_id );
	}

	/**
	 * Returns the reason a sailing is closed, or an empty string when open.
	 *
	 * @param Sailing $sailing Sailing entity.
	 * @param int     $now     UTC timestamp.
	 * @return string
	 */
	private function closed_reason( Sailing $sailing, int $now ): string {
		$status = (string) $sailing->get( 'status' );

		if ( Sailing::STATUS_CANCELLED === $status ) {
			return 'cancelled';
		}

		if ( Sailing::STATUS_SCHEDULED !== $status && Sailing::STATUS_DELAYED !== $status ) {
			return 'departed';
		}

		$open = (string) $sailing->get( 'booking_open' );

		if ( '' !== $open && $now < \FBM\Support\Time::local_to_timestamp( $open ) ) {
			return 'not_open_yet';
		}

		$close     = (string) $sailing->get( 'booking_close' );
		$departure = $sailing->departure_timestamp();

		/*
		 * The site-wide booking window, applied before the sailing's own dates.
		 * A sailing may close earlier than the global rule through its own
		 * booking_close, but it may never stay open past it: the operator set
		 * these to stop selling a crossing they can no longer prepare for, and
		 * that has to hold on every channel.
		 */
		$settings = Settings::all();

		if ( $departure > 0 ) {
			$horizon = (int) $settings['max_lead_days'];

			if ( $horizon > 0 && $departure - $now > $horizon * DAY_IN_SECONDS ) {
				return 'too_far_ahead';
			}

			$lead = (int) $settings['min_lead_minutes'];

			if ( $lead > 0 && $departure - $now < $lead * MINUTE_IN_SECONDS ) {
				return 'booking_closed';
			}
		}

		if ( '' !== $close ) {
			return $now > \FBM\Support\Time::local_to_timestamp( $close ) ? 'booking_closed' : '';
		}

		return ( $departure > 0 && $now > $departure ) ? 'departed' : '';
	}

	/**
	 * Returns the customer-facing message for a closure reason.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private function reason_message( string $reason ): string {
		switch ( $reason ) {
			case 'cancelled':
				return __( 'This sailing has been cancelled.', 'magepeople-ferry-booking-system' );

			case 'not_open_yet':
				return __( 'Bookings for this sailing have not opened yet.', 'magepeople-ferry-booking-system' );

			case 'booking_closed':
				return __( 'Bookings for this sailing have closed.', 'magepeople-ferry-booking-system' );

			case 'too_far_ahead':
				return __( 'This sailing is not on sale yet.', 'magepeople-ferry-booking-system' );

			case 'departed':
				return __( 'This sailing has already departed.', 'magepeople-ferry-booking-system' );

			default:
				return __( 'This sailing is not available for booking.', 'magepeople-ferry-booking-system' );
		}
	}

	/**
	 * Returns the message shown when a measure cannot absorb a request.
	 *
	 * @param string    $measure   Measure name.
	 * @param float|int $remaining Remaining amount.
	 * @return string
	 */
	private function shortfall_message( string $measure, $remaining ): string {
		$remaining = max( 0, $remaining );

		switch ( $measure ) {
			case Availability::VEHICLES:
				return 0 === (int) $remaining
					? __( 'The vehicle deck on this sailing is full.', 'magepeople-ferry-booking-system' )
					: sprintf(
						/* translators: %d: number of vehicle spaces left. */
						_n(
							'Only %d vehicle space is left on this sailing.',
							'Only %d vehicle spaces are left on this sailing.',
							(int) $remaining,
							'magepeople-ferry-booking-system'
						),
						(int) $remaining
					);

			case Availability::LANE_METRES:
				return sprintf(
					/* translators: %s: remaining lane metres. */
					__( 'Only %s lane metres are left on this sailing.', 'magepeople-ferry-booking-system' ),
					number_format_i18n( (float) $remaining, 1 )
				);

			default:
				return 0 === (int) $remaining
					? __( 'This sailing is sold out.', 'magepeople-ferry-booking-system' )
					: sprintf(
						/* translators: %d: number of seats left. */
						_n(
							'Only %d seat is left on this sailing.',
							'Only %d seats are left on this sailing.',
							(int) $remaining,
							'magepeople-ferry-booking-system'
						),
						(int) $remaining
					);
		}
	}
}
