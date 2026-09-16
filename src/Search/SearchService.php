<?php
/**
 * Sailing search.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Search;

use FBM\Availability\Availability;
use FBM\Availability\AvailabilityService;
use FBM\Cache\CacheManager;
use FBM\Models\Port;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use FBM\Pricing\PricingService;
use FBM\Repositories\PortRepository;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VesselRepository;
use FBM\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the crossings a customer can actually buy.
 *
 * A search result is only useful if it is true: every row carries the price the
 * booking will be written at and the availability the booking will be checked
 * against, both from the same engines the booking itself uses. Nothing here
 * estimates.
 *
 * Sold-out sailings are returned rather than hidden. A customer who cannot find
 * the 08:00 crossing assumes the website is broken; one who sees it marked full
 * picks a different time.
 */
final class SearchService {

	/**
	 * Largest date range one search may span, in days.
	 */
	private const MAX_RANGE_DAYS = 90;

	/**
	 * Largest number of sailings one search returns.
	 */
	private const MAX_RESULTS = 200;

	/**
	 * Seconds a search result stays cached.
	 */
	private const TTL = 120;

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
	 * Port repository.
	 *
	 * @var PortRepository
	 */
	private PortRepository $ports;

	/**
	 * Availability engine.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * Pricing engine.
	 *
	 * @var PricingService
	 */
	private PricingService $pricing;

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param SailingRepository   $sailings     Sailing repository.
	 * @param RouteRepository     $routes       Route repository.
	 * @param VesselRepository    $vessels      Vessel repository.
	 * @param PortRepository      $ports        Port repository.
	 * @param AvailabilityService $availability Availability engine.
	 * @param PricingService      $pricing      Pricing engine.
	 * @param CacheManager        $cache        Cache manager.
	 */
	public function __construct(
		SailingRepository $sailings,
		RouteRepository $routes,
		VesselRepository $vessels,
		PortRepository $ports,
		AvailabilityService $availability,
		PricingService $pricing,
		CacheManager $cache
	) {
		$this->sailings     = $sailings;
		$this->routes       = $routes;
		$this->vessels      = $vessels;
		$this->ports        = $ports;
		$this->availability = $availability;
		$this->pricing      = $pricing;
		$this->cache        = $cache;
	}

	/**
	 * Searches for crossings.
	 *
	 * @param array<string, mixed> $query Search query.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search( array $query ) {
		$origin      = (int) ( $query['origin'] ?? 0 );
		$destination = (int) ( $query['destination'] ?? 0 );
		$date        = $this->normalise_date( (string) ( $query['date'] ?? '' ) );
		$return_date = $this->normalise_date( (string) ( $query['return_date'] ?? '' ) );
		$flexible    = max( 0, min( 7, (int) ( $query['flexible_days'] ?? 0 ) ) );
		$passengers  = is_array( $query['passengers'] ?? null ) ? $query['passengers'] : array();
		$vehicles    = is_array( $query['vehicles'] ?? null ) ? $query['vehicles'] : array();

		if ( $origin < 1 || $destination < 1 ) {
			return new WP_Error(
				'fbm_missing_ports',
				__( 'Choose where you are travelling from and to.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		if ( $origin === $destination ) {
			return new WP_Error(
				'fbm_same_port',
				__( 'The departure and arrival ports have to be different.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $date ) {
			return new WP_Error(
				'fbm_missing_date',
				__( 'Choose a departure date.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $return_date && $return_date < $date ) {
			return new WP_Error(
				'fbm_return_before_departure',
				__( 'The return date cannot be before the departure date.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		$outbound = $this->leg( $origin, $destination, $date, $flexible, $passengers, $vehicles );

		if ( is_wp_error( $outbound ) ) {
			return $outbound;
		}

		$inbound = array();

		if ( '' !== $return_date ) {
			$leg = $this->leg( $destination, $origin, $return_date, $flexible, $passengers, $vehicles );

			if ( is_wp_error( $leg ) ) {
				return $leg;
			}

			$inbound = $leg;
		}

		return array(
			'query'    => array(
				'origin'        => $origin,
				'destination'   => $destination,
				'date'          => $date,
				'return_date'   => $return_date,
				'flexible_days' => $flexible,
				'passengers'    => $passengers,
				'vehicles'      => $vehicles,
			),
			'outbound' => $outbound,
			'inbound'  => $inbound,
			'ports'    => $this->port_labels( array( $origin, $destination ) ),
		);
	}

	/**
	 * Returns the guide fare of every sellable type between two ports.
	 *
	 * A thin pass-through to the pricing engine: the booking form already talks
	 * to this service for everything else it draws, and standing up a second
	 * endpoint on a second service for one number would cost more than the
	 * extra method here.
	 *
	 * @param int $origin      Departure port id, or zero.
	 * @param int $destination Arrival port id, or zero.
	 * @return array<string, mixed>
	 */
	public function fares( int $origin, int $destination ): array {
		return $this->pricing->fares( $origin, $destination );
	}

	/**
	 * Returns the ports, routes and connections a search form needs.
	 *
	 * @return array<string, mixed>
	 */
	public function options(): array {
		return (array) $this->cache->remember(
			CacheManager::GROUP_SEARCH,
			'options',
			function (): array {
				$ports       = array();
				$connections = array();

				$routes = $this->routes->query(
					array(
						'per_page' => 100,
						'status'   => Route::STATUS_ACTIVE,
						'orderby'  => 'title',
						'order'    => 'asc',
					)
				);

				$used = array();

				foreach ( $routes['items'] as $route ) {
					$origin      = (int) $route->get( 'origin_port' );
					$destination = (int) $route->get( 'destination_port' );

					if ( $origin < 1 || $destination < 1 ) {
						continue;
					}

					$used[ $origin ]      = true;
					$used[ $destination ] = true;

					$connections[ (string) $origin ][] = $destination;

					// A route is sold in both directions unless an operator
					// models the return leg as its own route, so the reverse
					// pairing only appears if a route actually provides it.
					if ( ! isset( $connections[ (string) $destination ] ) ) {
						$connections[ (string) $destination ] = array();
					}
				}

				foreach ( $connections as $key => $list ) {
					$connections[ $key ] = array_values( array_unique( $list ) );
				}

				$port_result = $this->ports->query(
					array(
						'per_page' => 100,
						'status'   => Port::STATUS_ACTIVE,
						'orderby'  => 'title',
						'order'    => 'asc',
					)
				);

				foreach ( $port_result['items'] as $port ) {
					if ( ! isset( $used[ $port->id ] ) ) {
						continue;
					}

					$ports[] = array(
						'id'      => $port->id,
						'name'    => $port->name,
						'code'    => (string) $port->get( 'code' ),
						'city'    => (string) $port->get( 'city' ),
						'country' => (string) $port->get( 'country' ),
					);
				}

				return array(
					'ports'       => $ports,
					'connections' => $connections,
				);
			},
			600
		);
	}

	/**
	 * Searches one direction.
	 *
	 * @param int                  $origin      Origin port id.
	 * @param int                  $destination Destination port id.
	 * @param string               $date        Departure date, Y-m-d.
	 * @param int                  $flexible    Days either side to include.
	 * @param array<string, mixed> $passengers  Passenger type id => quantity.
	 * @param array<string, mixed> $vehicles    Vehicle type id => quantity.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function leg( int $origin, int $destination, string $date, int $flexible, array $passengers, array $vehicles ) {
		$routes = $this->routes->between( $origin, $destination );

		if ( array() === $routes ) {
			return array();
		}

		$from = 0 === $flexible ? $date : gmdate( 'Y-m-d', strtotime( $date . ' -' . $flexible . ' days' ) );
		$to   = 0 === $flexible ? $date : gmdate( 'Y-m-d', strtotime( $date . ' +' . $flexible . ' days' ) );

		if ( ( strtotime( $to ) - strtotime( $from ) ) > self::MAX_RANGE_DAYS * DAY_IN_SECONDS ) {
			return new WP_Error(
				'fbm_range_too_wide',
				__( 'That is too wide a date range to search.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		$results = array();

		foreach ( $routes as $route ) {
			$page = $this->sailings->query(
				array(
					'per_page' => 100,
					'route_id' => $route->id,
					'from'     => $from . ' 00:00:00',
					'to'       => $to . ' 23:59:59',
					'statuses' => array( Sailing::STATUS_SCHEDULED, Sailing::STATUS_DELAYED ),
					'orderby'  => 'departure_ts',
					'order'    => 'asc',
				)
			);

			foreach ( $page['items'] as $sailing ) {
				if ( count( $results ) >= self::MAX_RESULTS ) {
					break 2;
				}

				$row = $this->present( $sailing, $route, $passengers, $vehicles );

				if ( null !== $row ) {
					$results[] = $row;
				}
			}
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				return $a['departure_ts'] <=> $b['departure_ts'];
			}
		);

		return $results;
	}

	/**
	 * Builds one search result row.
	 *
	 * @param Sailing              $sailing    Sailing entity.
	 * @param Route                $route      Route entity.
	 * @param array<string, mixed> $passengers Passenger type id => quantity.
	 * @param array<string, mixed> $vehicles   Vehicle type id => quantity.
	 * @return array<string, mixed>|null
	 */
	private function present( Sailing $sailing, Route $route, array $passengers, array $vehicles ): ?array {
		$availability = $this->availability->for_sailing_entity( $sailing );

		// A departure whose booking window has closed is over, not full, and
		// showing it as an option a customer can pick is a dead end.
		if ( ! $availability->bookable ) {
			return null;
		}

		$vessel = $this->vessels->find( $sailing->vessel_id() );

		$row = array(
			'sailing_id'     => $sailing->id,
			'route_id'       => $route->id,
			'route_name'     => $route->name,
			'departure'      => (string) $sailing->get( 'departure_datetime' ),
			'arrival'        => (string) $sailing->get( 'arrival_datetime' ),
			'departure_ts'   => $sailing->departure_timestamp(),
			'duration'       => $this->duration( $sailing, $route ),
			'vessel'         => array(
				'id'   => $vessel instanceof Vessel ? $vessel->id : 0,
				'name' => $vessel instanceof Vessel ? $vessel->name : '',
				'code' => $vessel instanceof Vessel ? (string) $vessel->get( 'code' ) : '',
			),
			'takes_vehicles' => (bool) $route->get( 'allows_vehicles' ),
			'availability'   => array(
				'passengers'  => $availability->bucket( Availability::PASSENGERS ),
				'vehicles'    => $availability->bucket( Availability::VEHICLES ),
				'lane_metres' => $availability->bucket( Availability::LANE_METRES ),
				'sold_out'    => $availability->is_sold_out(),
			),
		);

		if ( array() === $passengers && array() === $vehicles ) {
			return $row;
		}

		$quote = $this->pricing->quote(
			array(
				'sailing_id' => $sailing->id,
				'passengers' => $passengers,
				'vehicles'   => $vehicles,
			)
		);

		if ( is_wp_error( $quote ) ) {
			// A party this sailing cannot price — a vehicle on a foot-passenger
			// crossing — is not an error to show the customer, it simply is not
			// one of their options.
			$row['price']       = null;
			$row['unavailable'] = $quote->get_error_message();

			return $row;
		}

		$verdict = $this->availability->check( $availability, $quote->usage );

		$row['price'] = array(
			'total'    => $quote->total(),
			'subtotal' => $quote->subtotal(),
			'tax'      => $quote->tax(),
			'fees'     => $quote->fees(),
			'currency' => $quote->currency,
			'lines'    => $quote->lines(),
		);

		$row['usage'] = $quote->usage;
		$row['fits']  = ! is_wp_error( $verdict );

		if ( is_wp_error( $verdict ) ) {
			$row['unavailable'] = $verdict->get_error_message();
		}

		return $row;
	}

	/**
	 * Returns the crossing time in minutes.
	 *
	 * @param Sailing $sailing Sailing entity.
	 * @param Route   $route   Route entity.
	 * @return int
	 */
	private function duration( Sailing $sailing, Route $route ): int {
		$departure = $sailing->departure_timestamp();
		$arrival   = (int) $sailing->get( 'arrival_ts' );

		if ( $arrival > $departure ) {
			return (int) round( ( $arrival - $departure ) / MINUTE_IN_SECONDS );
		}

		return max( 0, (int) $route->get( 'duration' ) );
	}

	/**
	 * Returns display labels for a set of ports.
	 *
	 * @param int[] $ids Port ids.
	 * @return array<string, string>
	 */
	private function port_labels( array $ids ): array {
		$labels = array();

		foreach ( $this->ports->find_many( array_filter( $ids ) ) as $port ) {
			$labels[ (string) $port->id ] = $port->name;
		}

		return $labels;
	}

	/**
	 * Normalises a submitted date, rejecting anything unusable.
	 *
	 * @param string $date Raw date.
	 * @return string Y-m-d, or an empty string.
	 */
	private function normalise_date( string $date ): string {
		$date = trim( $date );

		if ( '' === $date ) {
			return '';
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parts = array_map( 'intval', explode( '-', $date ) );

		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return '';
		}

		unset( $parts );

		return $date;
	}

	/**
	 * Returns today's date in the site's timezone.
	 *
	 * @return string
	 */
	public function today(): string {
		return Time::now( 'Y-m-d' );
	}
}
