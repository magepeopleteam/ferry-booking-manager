<?php
/**
 * Bulk schedule generation.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Sailing;

use MPFBS\Models\Route;
use MPFBS\Models\Sailing;
use MPFBS\Repositories\RouteRepository;
use MPFBS\Repositories\SailingRepository;
use MPFBS\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a repeating pattern into individual sailings.
 *
 * A seasonal timetable is the same handful of departures repeated across
 * months; entering them one at a time is where operational mistakes come from.
 * The generator always produces a preview first, marking each candidate as new,
 * already scheduled, or in conflict with another sailing on the same vessel, so
 * nothing is written until a human has seen exactly what will happen.
 */
final class ScheduleGenerator {

	/**
	 * Hard ceiling on sailings produced by one run.
	 *
	 * A full year of hourly departures is a plausible typo, and creating tens of
	 * thousands of posts in one request is not something a shared host survives.
	 */
	public const MAX_SAILINGS = 500;

	public const OUTCOME_NEW       = 'new';
	public const OUTCOME_DUPLICATE = 'duplicate';
	public const OUTCOME_CONFLICT  = 'conflict';

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
	 * Constructor.
	 *
	 * @param SailingRepository $sailings Sailing repository.
	 * @param RouteRepository   $routes   Route repository.
	 */
	public function __construct( SailingRepository $sailings, RouteRepository $routes ) {
		$this->sailings = $sailings;
		$this->routes   = $routes;
	}

	/**
	 * Builds the list of sailings a pattern would produce.
	 *
	 * @param array<string, mixed> $pattern Generation pattern.
	 * @return array{candidates: array<int, array<string, mixed>>, summary: array<string, int>}|WP_Error
	 */
	public function preview( array $pattern ) {
		$normalised = $this->normalise( $pattern );

		if ( is_wp_error( $normalised ) ) {
			return $normalised;
		}

		/** @var Route $route */
		$route      = $normalised['route'];
		$vessel_id  = $normalised['vessel_id'];
		$candidates = array();
		$summary    = array(
			self::OUTCOME_NEW       => 0,
			self::OUTCOME_DUPLICATE => 0,
			self::OUTCOME_CONFLICT  => 0,
		);

		foreach ( $normalised['departures'] as $departure ) {
			$arrival = Time::add_minutes( $departure, $route->duration() );
			$outcome = $this->classify( $departure, $route->id, $vessel_id, $route->duration() );

			$candidates[] = array(
				'departure_datetime' => $departure,
				'arrival_datetime'   => $arrival,
				'weekday'            => (int) gmdate( 'w', Time::local_to_timestamp( $departure ) ),
				'outcome'            => $outcome['outcome'],
				'conflict_with'      => $outcome['conflict_with'],
			);

			++$summary[ $outcome['outcome'] ];
		}

		return array(
			'candidates' => $candidates,
			'summary'    => $summary,
		);
	}

	/**
	 * Creates the sailings a pattern produces.
	 *
	 * Candidates that already exist or that would double-book the vessel are
	 * skipped rather than silently written, and reported back so the operator
	 * knows what was left out.
	 *
	 * @param array<string, mixed> $pattern Generation pattern.
	 * @return array{created: int, skipped: array<int, array<string, mixed>>, ids: int[]}|WP_Error
	 */
	public function generate( array $pattern ) {
		$preview = $this->preview( $pattern );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$normalised = $this->normalise( $pattern );

		if ( is_wp_error( $normalised ) ) {
			return $normalised;
		}

		/** @var Route $route */
		$route   = $normalised['route'];
		$created = array();
		$skipped = array();

		foreach ( $preview['candidates'] as $candidate ) {
			if ( self::OUTCOME_NEW !== $candidate['outcome'] ) {
				$skipped[] = $candidate;
				continue;
			}

			$attributes = array(
				'route_id'                    => $route->id,
				'vessel_id'                   => $normalised['vessel_id'],
				'departure_datetime'          => $candidate['departure_datetime'],
				'arrival_datetime'            => $candidate['arrival_datetime'],
				'booking_open'                => '',
				'booking_close'               => $this->booking_close( $candidate['departure_datetime'], $normalised['booking_close_minutes'] ),
				'passenger_capacity_override' => $normalised['passenger_capacity_override'],
				'vehicle_capacity_override'   => $normalised['vehicle_capacity_override'],
				'deck_capacity_override'      => $normalised['deck_capacity_override'],
				'notes'                       => $normalised['notes'],
				'status'                      => Sailing::STATUS_SCHEDULED,
			);

			$sailing = $this->sailings->save( $attributes );

			if ( is_wp_error( $sailing ) ) {
				$candidate['outcome'] = 'failed';
				$candidate['message'] = $sailing->get_error_message();
				$skipped[]            = $candidate;
				continue;
			}

			$created[] = $sailing->id;
		}

		/**
		 * Fires after a bulk schedule run has created sailings.
		 *
		 * @since 1.0.0
		 *
		 * @param int[]                $created Created sailing ids.
		 * @param array<string, mixed> $pattern Generation pattern.
		 */
		do_action( 'mpfbs_schedule_generated', $created, $pattern );

		return array(
			'created' => count( $created ),
			'skipped' => $skipped,
			'ids'     => $created,
		);
	}

	/**
	 * Validates and expands a pattern into concrete departure times.
	 *
	 * @param array<string, mixed> $pattern Raw pattern.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalise( array $pattern ) {
		$route_id  = isset( $pattern['route_id'] ) ? (int) $pattern['route_id'] : 0;
		$vessel_id = isset( $pattern['vessel_id'] ) ? (int) $pattern['vessel_id'] : 0;
		$from      = isset( $pattern['date_from'] ) ? sanitize_text_field( (string) $pattern['date_from'] ) : '';
		$to        = isset( $pattern['date_to'] ) ? sanitize_text_field( (string) $pattern['date_to'] ) : '';
		$weekdays  = isset( $pattern['weekdays'] ) ? array_map( 'intval', (array) $pattern['weekdays'] ) : array();
		$times     = isset( $pattern['times'] ) ? array_map( 'sanitize_text_field', (array) $pattern['times'] ) : array();

		$fields = array();

		/** @var Route|null $route */
		$route = $this->routes->find( $route_id );

		if ( null === $route ) {
			$fields['route_id'] = __( 'Choose a route.', 'magepeople-ferry-booking-system' );
		}

		if ( $vessel_id < 1 ) {
			$fields['vessel_id'] = __( 'Choose a vessel.', 'magepeople-ferry-booking-system' );
		}

		if ( ! Time::is_valid( $from ) ) {
			$fields['date_from'] = __( 'Enter a start date.', 'magepeople-ferry-booking-system' );
		}

		if ( ! Time::is_valid( $to ) ) {
			$fields['date_to'] = __( 'Enter an end date.', 'magepeople-ferry-booking-system' );
		}

		$weekdays = array_values( array_unique( array_filter( $weekdays, static fn( $day ) => $day >= 0 && $day <= 6 ) ) );

		if ( array() === $weekdays ) {
			$fields['weekdays'] = __( 'Choose at least one day of the week.', 'magepeople-ferry-booking-system' );
		}

		$times = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( string $time ): string {
							return preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', trim( $time ) ) ? trim( $time ) : '';
						},
						$times
					)
				)
			)
		);

		if ( array() === $times ) {
			$fields['times'] = __( 'Enter at least one departure time as HH:MM.', 'magepeople-ferry-booking-system' );
		}

		if ( array() !== $fields ) {
			return new WP_Error(
				'mpfbs_invalid_schedule',
				__( 'The schedule could not be generated. Check the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 422,
					'fields' => $fields,
				)
			);
		}

		$start = Time::local_to_timestamp( $from . ' 00:00:00' );
		$end   = Time::local_to_timestamp( $to . ' 00:00:00' );

		if ( $end < $start ) {
			return new WP_Error(
				'mpfbs_invalid_schedule',
				__( 'The end date falls before the start date.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 422,
					'fields' => array( 'date_to' => __( 'Choose a date on or after the start date.', 'magepeople-ferry-booking-system' ) ),
				)
			);
		}

		sort( $times );

		$departures = array();

		for ( $day = $start; $day <= $end; $day += DAY_IN_SECONDS ) {
			$date = Time::timestamp_to_local( $day, 'Y-m-d' );

			if ( ! in_array( (int) Time::timestamp_to_local( $day, 'w' ), $weekdays, true ) ) {
				continue;
			}

			foreach ( $times as $time ) {
				$departures[] = $date . ' ' . $time . ':00';

				if ( count( $departures ) > self::MAX_SAILINGS ) {
					return new WP_Error(
						'mpfbs_schedule_too_large',
						sprintf(
							/* translators: %d: maximum number of sailings. */
							__( 'That pattern would create more than %d sailings. Narrow the date range or the number of departure times and run it again.', 'magepeople-ferry-booking-system' ),
							self::MAX_SAILINGS
						),
						array( 'status' => 422 )
					);
				}
			}
		}

		return array(
			'route'                       => $route,
			'vessel_id'                   => $vessel_id,
			'departures'                  => $departures,
			'booking_close_minutes'       => isset( $pattern['booking_close_minutes'] ) ? max( 0, (int) $pattern['booking_close_minutes'] ) : 0,
			'passenger_capacity_override' => isset( $pattern['passenger_capacity_override'] ) ? max( 0, (int) $pattern['passenger_capacity_override'] ) : 0,
			'vehicle_capacity_override'   => isset( $pattern['vehicle_capacity_override'] ) ? max( 0, (int) $pattern['vehicle_capacity_override'] ) : 0,
			'deck_capacity_override'      => isset( $pattern['deck_capacity_override'] ) ? max( 0.0, (float) $pattern['deck_capacity_override'] ) : 0.0,
			'notes'                       => isset( $pattern['notes'] ) ? sanitize_textarea_field( (string) $pattern['notes'] ) : '',
		);
	}

	/**
	 * Decides what would happen to one candidate departure.
	 *
	 * @param string $departure Local departure date/time.
	 * @param int    $route_id  Route id.
	 * @param int    $vessel_id Vessel id.
	 * @param int    $duration  Journey duration in minutes.
	 * @return array{outcome: string, conflict_with: int}
	 */
	private function classify( string $departure, int $route_id, int $vessel_id, int $duration ): array {
		$timestamp = Time::local_to_timestamp( $departure );

		$existing = $this->sailings->ids(
			array(
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed scalar keys, single-row lookup.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_mpfbs_route_id',
						'value'   => $route_id,
						'compare' => '=',
					),
					array(
						'key'     => '_mpfbs_departure_ts',
						'value'   => $timestamp,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		if ( array() !== $existing ) {
			return array(
				'outcome'       => self::OUTCOME_DUPLICATE,
				'conflict_with' => (int) $existing[0],
			);
		}

		$conflicts = $this->sailings->find_vessel_conflicts( $vessel_id, $departure, $duration );

		if ( array() !== $conflicts ) {
			return array(
				'outcome'       => self::OUTCOME_CONFLICT,
				'conflict_with' => (int) $conflicts[0],
			);
		}

		return array(
			'outcome'       => self::OUTCOME_NEW,
			'conflict_with' => 0,
		);
	}

	/**
	 * Derives the booking cut-off for a departure.
	 *
	 * @param string $departure Local departure date/time.
	 * @param int    $minutes   Minutes before departure that bookings close.
	 * @return string
	 */
	private function booking_close( string $departure, int $minutes ): string {
		return $minutes > 0 ? Time::add_minutes( $departure, -$minutes ) : '';
	}
}
