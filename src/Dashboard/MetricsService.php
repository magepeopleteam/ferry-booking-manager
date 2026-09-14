<?php
/**
 * Operational dashboard metrics.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Dashboard;

use FBM\Availability\Availability;
use FBM\Availability\AvailabilityService;
use FBM\Cache\CacheManager;
use FBM\Models\Booking;
use FBM\Models\Sailing;
use FBM\Repositories\BookingRepository;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VesselRepository;
use FBM\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the questions an operator opens the dashboard to ask.
 *
 * Which boats are going out today, how many people are on them, what has been
 * sold, and what still needs chasing. Deliberately not a diagnostics screen:
 * the PHP version matters once, on the day the plugin is installed, and never
 * again — while "is the 08:00 full?" matters every morning.
 *
 * Every figure comes from the same repositories and the same availability
 * engine the booking flow uses, so the dashboard cannot disagree with what a
 * customer sees.
 */
final class MetricsService {

	/**
	 * Seconds a snapshot stays cached.
	 *
	 * Short: an operator watching a sailing fill up needs the number to move,
	 * and the whole point of the screen is that it is current.
	 */
	private const TTL = 60;

	/**
	 * Largest number of bookings summed for one day.
	 *
	 * A day busier than this is a bigger operation than the Free plugin's
	 * aggregation approach suits, and the figure is marked as capped rather
	 * than quietly wrong.
	 */
	private const MAX_BOOKINGS = 2000;

	/**
	 * Departures listed on the dashboard.
	 */
	private const MAX_DEPARTURES = 12;

	/**
	 * Recent bookings listed on the dashboard.
	 */
	private const MAX_RECENT = 8;

	/**
	 * Booking repository.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Route repository.
	 *
	 * @var RouteRepository
	 */
	private RouteRepository $routes;

	/**
	 * Vessel repository.
	 *
	 * @var VesselRepository
	 */
	private VesselRepository $vessels;

	/**
	 * Availability engine.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param BookingRepository   $bookings     Booking repository.
	 * @param SailingRepository   $sailings     Sailing repository.
	 * @param RouteRepository     $routes       Route repository.
	 * @param VesselRepository    $vessels      Vessel repository.
	 * @param AvailabilityService $availability Availability engine.
	 * @param CacheManager        $cache        Cache manager.
	 */
	public function __construct(
		BookingRepository $bookings,
		SailingRepository $sailings,
		RouteRepository $routes,
		VesselRepository $vessels,
		AvailabilityService $availability,
		CacheManager $cache
	) {
		$this->bookings     = $bookings;
		$this->sailings     = $sailings;
		$this->routes       = $routes;
		$this->vessels      = $vessels;
		$this->availability = $availability;
		$this->cache        = $cache;
	}

	/**
	 * Returns the dashboard snapshot.
	 *
	 * @param string $date Operating day, Y-m-d. Defaults to today.
	 * @return array<string, mixed>
	 */
	public function snapshot( string $date = '' ): array {
		$date = $this->normalise_date( $date );

		return (array) $this->cache->remember(
			CacheManager::GROUP_DASHBOARD,
			'snapshot_' . $date,
			function () use ( $date ): array {
				return $this->build( $date );
			},
			self::TTL
		);
	}

	/**
	 * Builds the snapshot from storage.
	 *
	 * @param string $date Operating day, Y-m-d.
	 * @return array<string, mixed>
	 */
	private function build( string $date ): array {
		$departures  = $this->departures( $date );
		$travelling  = $this->travelling( $date );
		$sold        = $this->sold_on( $date );
		$outstanding = $this->outstanding();

		$snapshot = array(
			'date'       => $date,
			'today'      => Time::now( 'Y-m-d' ),
			'metrics'    => array(
				'sailings'   => array(
					'label' => __( 'Sailings today', 'ferry-booking-manager' ),
					'value' => count( $departures ),
					'hint'  => $this->departure_hint( $departures ),
				),
				'passengers' => array(
					'label' => __( 'Passengers today', 'ferry-booking-manager' ),
					'value' => $travelling['passengers'],
					'hint'  => $this->load_hint( $departures ),
				),
				'vehicles'   => array(
					'label' => __( 'Vehicles today', 'ferry-booking-manager' ),
					'value' => $travelling['vehicles'],
					'hint'  => '',
				),
				'revenue'    => array(
					'label' => __( 'Sold today', 'ferry-booking-manager' ),
					'value' => $sold['total'],
					'money' => true,
					'hint'  => $this->count_hint( $sold['count'] ),
				),
				'checked_in' => array(
					'label' => __( 'Checked in', 'ferry-booking-manager' ),
					'value' => $travelling['checked_in'],
					'hint'  => '',
					'pro'   => true,
				),
				'boarded'    => array(
					'label' => __( 'Boarded', 'ferry-booking-manager' ),
					'value' => $travelling['boarded'],
					'hint'  => '',
					'pro'   => true,
				),
				'pending'    => array(
					'label' => __( 'Awaiting payment', 'ferry-booking-manager' ),
					'value' => $outstanding['amount'],
					'money' => true,
					'hint'  => $this->count_hint( $outstanding['count'] ),
					'tone'  => $outstanding['count'] > 0 ? 'warning' : 'muted',
				),
				'cancelled'  => array(
					'label' => __( 'Cancelled today', 'ferry-booking-manager' ),
					'value' => $sold['cancelled'],
					'hint'  => '',
				),
				'refunds'    => array(
					'label' => __( 'Refunded today', 'ferry-booking-manager' ),
					'value' => $sold['refunded'],
					'money' => true,
					'hint'  => '',
					'tone'  => $sold['refunded'] > 0 ? 'warning' : 'muted',
				),
			),
			'departures' => $departures,
			'recent'     => $this->recent(),
			'capped'     => $travelling['capped'] || $sold['capped'],
		);

		/**
		 * Filters the dashboard snapshot.
		 *
		 * Pro fills in the check-in and boarding figures here, which the Free
		 * plugin reports as zero because it does not run a check-in desk.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $snapshot Dashboard snapshot.
		 * @param string               $date     Operating day.
		 */
		return (array) apply_filters( 'fbm_dashboard_snapshot', $snapshot, $date );
	}

	/**
	 * Lists the day's departures with their load.
	 *
	 * @param string $date Operating day, Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	private function departures( string $date ): array {
		$rows = array();

		foreach ( $this->sailings->on_date( $date ) as $sailing ) {
			if ( count( $rows ) >= self::MAX_DEPARTURES ) {
				break;
			}

			$availability = $this->availability->for_sailing_entity( $sailing );
			$seats        = $availability->bucket( Availability::PASSENGERS );
			$capacity     = isset( $seats['capacity'] ) ? (int) $seats['capacity'] : 0;
			$used         = isset( $seats['used'] ) ? (int) $seats['used'] : 0;

			$route  = $this->routes->find( $sailing->route_id() );
			$vessel = $this->vessels->find( $sailing->vessel_id() );

			$rows[] = array(
				'id'        => $sailing->id,
				'departure' => Time::display( (string) $sailing->get( 'departure_datetime' ), true ),
				'time'      => substr( (string) $sailing->get( 'departure_datetime' ), 11, 5 ),
				'route'     => null !== $route ? $route->name : '',
				'vessel'    => null !== $vessel ? $vessel->name : '',
				'status'    => (string) $sailing->get( 'status' ),
				'booked'    => $used,
				'capacity'  => $capacity,
				// A vessel with no declared capacity has no load factor, and
				// showing 0% would read as "empty" rather than "not measured".
				'load'      => $capacity > 0 ? (int) round( ( $used / $capacity ) * 100 ) : -1,
				'vehicles'  => (int) ( $availability->bucket( Availability::VEHICLES )['used'] ?? 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Sums the people and vehicles travelling on a day.
	 *
	 * @param string $date Operating day, Y-m-d.
	 * @return array{passengers: int, vehicles: int, checked_in: int, boarded: int, capped: bool}
	 */
	private function travelling( string $date ): array {
		$ids = $this->bookings->ids(
			array(
				'posts_per_page' => self::MAX_BOOKINGS,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dedicated scalar keys, bounded and cached.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_fbm_departure_ts',
						'value'   => array( Time::local_to_timestamp( $date . ' 00:00:00' ), Time::local_to_timestamp( $date . ' 23:59:59' ) ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_fbm_booking_status',
						'value'   => Booking::CONSUMING_STATUSES,
						'compare' => 'IN',
					),
				),
			),
			self::MAX_BOOKINGS
		);

		$totals = array(
			'passengers' => 0,
			'vehicles'   => 0,
			'checked_in' => 0,
			'boarded'    => 0,
			'capped'     => count( $ids ) >= self::MAX_BOOKINGS,
		);

		foreach ( $this->bookings->find_many( $ids ) as $booking ) {
			if ( ! $booking instanceof Booking || ! $booking->consumes_capacity() ) {
				continue;
			}

			$totals['passengers'] += (int) $booking->get( 'passenger_count' );
			$totals['vehicles']   += (int) $booking->get( 'vehicle_count' );
		}

		return $totals;
	}

	/**
	 * Sums what was sold, cancelled and refunded on a day.
	 *
	 * Measured by when the booking was taken, not when it sails: this is the
	 * commercial figure an operator reconciles at the end of the day.
	 *
	 * @param string $date Operating day, Y-m-d.
	 * @return array{total: int, count: int, cancelled: int, refunded: int, capped: bool}
	 */
	private function sold_on( string $date ): array {
		$ids = $this->bookings->ids(
			array(
				'posts_per_page' => self::MAX_BOOKINGS,
				'date_query'     => array(
					array(
						'year'  => (int) substr( $date, 0, 4 ),
						'month' => (int) substr( $date, 5, 2 ),
						'day'   => (int) substr( $date, 8, 2 ),
					),
				),
			),
			self::MAX_BOOKINGS
		);

		$totals = array(
			'total'     => 0,
			'count'     => 0,
			'cancelled' => 0,
			'refunded'  => 0,
			'capped'    => count( $ids ) >= self::MAX_BOOKINGS,
		);

		foreach ( $this->bookings->find_many( $ids ) as $booking ) {
			if ( ! $booking instanceof Booking ) {
				continue;
			}

			$status = (string) $booking->get( 'booking_status' );

			if ( Booking::STATUS_CANCELLED === $status ) {
				++$totals['cancelled'];
			}

			$totals['refunded'] += (int) $booking->get( 'refunded' );

			// A cancelled or failed booking was not a sale, so counting it in
			// the day's takings would overstate them.
			if ( in_array( $status, array( Booking::STATUS_CANCELLED, Booking::STATUS_FAILED ), true ) ) {
				continue;
			}

			$totals['total'] += (int) $booking->total();
			++$totals['count'];
		}

		return $totals;
	}

	/**
	 * Sums the money still owed on live bookings.
	 *
	 * @return array{amount: int, count: int}
	 */
	private function outstanding(): array {
		$ids = $this->bookings->ids(
			array(
				'posts_per_page' => self::MAX_BOOKINGS,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dedicated scalar keys, bounded and cached.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_fbm_booking_status',
						'value'   => array( Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED, Booking::STATUS_ON_HOLD ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_fbm_payment_status',
						'value'   => array( Booking::PAYMENT_UNPAID, Booking::PAYMENT_PARTIAL ),
						'compare' => 'IN',
					),
				),
			),
			self::MAX_BOOKINGS
		);

		$totals = array(
			'amount' => 0,
			'count'  => 0,
		);

		foreach ( $this->bookings->find_many( $ids ) as $booking ) {
			if ( ! $booking instanceof Booking ) {
				continue;
			}

			$balance = $booking->balance();

			if ( $balance > 0 ) {
				$totals['amount'] += $balance;
				++$totals['count'];
			}
		}

		return $totals;
	}

	/**
	 * Lists the most recent bookings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recent(): array {
		$result = $this->bookings->query(
			array(
				'per_page' => self::MAX_RECENT,
				'orderby'  => 'created',
				'order'    => 'desc',
			)
		);

		$rows = array();

		foreach ( $result['items'] as $booking ) {
			if ( ! $booking instanceof Booking ) {
				continue;
			}

			$rows[] = array(
				'id'        => $booking->id,
				'reference' => $booking->number(),
				'customer'  => (string) $booking->get( 'customer_name' ),
				'status'    => (string) $booking->get( 'booking_status' ),
				'payment'   => (string) $booking->get( 'payment_status' ),
				'total'     => $booking->total(),
				'departure' => Time::display( $this->departure_of( $booking ), true ),
			);
		}

		return $rows;
	}

	/**
	 * Returns a booking's outbound departure in local time.
	 *
	 * @param Booking $booking Booking entity.
	 * @return string
	 */
	private function departure_of( Booking $booking ): string {
		$ts = (int) $booking->get( 'departure_ts' );

		return $ts > 0 ? Time::timestamp_to_local( $ts ) : '';
	}

	/**
	 * Describes the shape of the day's schedule.
	 *
	 * @param array<int, array<string, mixed>> $departures Departure rows.
	 * @return string
	 */
	private function departure_hint( array $departures ): string {
		if ( array() === $departures ) {
			return __( 'Nothing scheduled', 'ferry-booking-manager' );
		}

		$first = $departures[0]['time'] ?? '';
		$last  = $departures[ count( $departures ) - 1 ]['time'] ?? '';

		return $first === $last
			? (string) $first
			: sprintf(
				/* translators: 1: first departure time, 2: last departure time. */
				__( '%1$s to %2$s', 'ferry-booking-manager' ),
				(string) $first,
				(string) $last
			);
	}

	/**
	 * Describes how full the day is overall.
	 *
	 * @param array<int, array<string, mixed>> $departures Departure rows.
	 * @return string
	 */
	private function load_hint( array $departures ): string {
		$capacity = 0;
		$booked   = 0;

		foreach ( $departures as $row ) {
			$capacity += max( 0, (int) $row['capacity'] );
			$booked   += max( 0, (int) $row['booked'] );
		}

		if ( $capacity < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: percentage of the day's seats sold. */
			__( '%d%% of today’s seats', 'ferry-booking-manager' ),
			(int) round( ( $booked / $capacity ) * 100 )
		);
	}

	/**
	 * Renders a booking count as a hint.
	 *
	 * @param int $count Number of bookings.
	 * @return string
	 */
	private function count_hint( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of bookings. */
			_n( '%d booking', '%d bookings', $count, 'ferry-booking-manager' ),
			$count
		);
	}

	/**
	 * Falls back to today for anything unusable.
	 *
	 * @param string $date Requested date.
	 * @return string
	 */
	private function normalise_date( string $date ): string {
		$date = trim( $date );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return Time::now( 'Y-m-d' );
		}

		$parts = array_map( 'intval', explode( '-', $date ) );

		return checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : Time::now( 'Y-m-d' );
	}
}
