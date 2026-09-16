<?php
/**
 * Demo content.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Demo;

use FBM\Models\Port;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use FBM\Repositories\PassengerTypeRepository;
use FBM\Repositories\PortRepository;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VehicleTypeRepository;
use FBM\Repositories\VesselRepository;
use FBM\Settings\Settings;
use FBM\Support\Options;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Installs a working ferry operation an operator can click around in.
 *
 * An empty install is a bad first impression and a bad test: there is nothing to
 * search, no price to quote and no departure to book, so nothing on the
 * dashboard or the booking form can be judged. This builds a real one — a New
 * York harbour and Long Island Sound operator, with foot-passenger hops and
 * vehicle crossings, priced, scheduled in both directions and open for booking.
 *
 * Two rules make it safe to offer on a live site:
 *
 * 1. Every record carries `_fbm_demo`, so removing the demo removes exactly what
 *    the demo created and never touches an operator's own data.
 * 2. Records are matched by their code, so importing twice updates rather than
 *    duplicates.
 *
 * The work is stepped rather than done in one request. Ten days of schedule is
 * several hundred posts, and a shared host with a thirty-second limit would time
 * out halfway and leave a half-built catalogue behind.
 */
final class DemoContent {

	/**
	 * Marks a record as belonging to the demo.
	 */
	public const FLAG_META = '_fbm_demo';

	/**
	 * Option recording that the operator does not want to be asked again.
	 */
	private const OPTION_DISMISSED = 'demo_prompt_dismissed';

	/**
	 * Days of schedule the demo covers, starting today.
	 */
	private const DAYS = 10;

	/**
	 * Port repository.
	 *
	 * @var PortRepository
	 */
	private PortRepository $ports;

	/**
	 * Vessel repository.
	 *
	 * @var VesselRepository
	 */
	private VesselRepository $vessels;

	/**
	 * Route repository.
	 *
	 * @var RouteRepository
	 */
	private RouteRepository $routes;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Passenger type repository.
	 *
	 * @var PassengerTypeRepository
	 */
	private PassengerTypeRepository $passenger_types;

	/**
	 * Vehicle type repository.
	 *
	 * @var VehicleTypeRepository
	 */
	private VehicleTypeRepository $vehicle_types;

	/**
	 * Constructor.
	 *
	 * @param PortRepository          $ports           Port repository.
	 * @param VesselRepository        $vessels         Vessel repository.
	 * @param RouteRepository         $routes          Route repository.
	 * @param SailingRepository       $sailings        Sailing repository.
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param VehicleTypeRepository   $vehicle_types   Vehicle type repository.
	 */
	public function __construct(
		PortRepository $ports,
		VesselRepository $vessels,
		RouteRepository $routes,
		SailingRepository $sailings,
		PassengerTypeRepository $passenger_types,
		VehicleTypeRepository $vehicle_types
	) {
		$this->ports           = $ports;
		$this->vessels         = $vessels;
		$this->routes          = $routes;
		$this->sailings        = $sailings;
		$this->passenger_types = $passenger_types;
		$this->vehicle_types   = $vehicle_types;
	}

	/**
	 * Returns how many steps a full import takes.
	 *
	 * @return int
	 */
	public function steps(): int {
		return 2 + self::DAYS;
	}

	/**
	 * Describes the demo and what is currently installed.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$counts = $this->counts();
		$total  = array_sum( $counts );

		return array(
			'title'       => __( 'New York Harbor & Sound Ferries', 'magepeople-ferry-booking-system' ),
			'description' => __( 'A complete demo operation: harbour crossings to Staten Island, Governors Island and DUMBO, plus vehicle ferries across Long Island Sound. Priced, scheduled ten days ahead in both directions, and ready to book.', 'magepeople-ferry-booking-system' ),
			'includes'    => array(
				__( '8 terminals in New York and Connecticut', 'magepeople-ferry-booking-system' ),
				__( '5 vessels, two of them carrying vehicles', 'magepeople-ferry-booking-system' ),
				__( '10 routes — every crossing has its return', 'magepeople-ferry-booking-system' ),
				__( 'Fares for every passenger and vehicle type', 'magepeople-ferry-booking-system' ),
				__( '10 days of departures, open for booking', 'magepeople-ferry-booking-system' ),
			),
			'installed'   => $total > 0,
			'counts'      => $counts,
			'steps'       => $this->steps(),
			'empty'       => $this->is_empty(),
			'dismissed'   => Options::get_bool( self::OPTION_DISMISSED ),
		);
	}

	/**
	 * Reports whether the operator has no catalogue of their own yet.
	 *
	 * The prompt is only worth showing on an install with nothing in it. Once
	 * there is a single port or route, the operator has started work and an
	 * offer to add eight more is an interruption.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		foreach ( array( Port::POST_TYPE, Route::POST_TYPE, Sailing::POST_TYPE, Vessel::POST_TYPE ) as $type ) {
			$found = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			if ( array() !== $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Records that the operator does not want to be offered the demo again.
	 *
	 * @param bool $dismissed Whether to stop asking.
	 * @return void
	 */
	public function dismiss( bool $dismissed = true ): void {
		Options::set( self::OPTION_DISMISSED, $dismissed ? 1 : 0, true );
	}

	/**
	 * Runs one step of the import.
	 *
	 * @param int $index Zero-based step.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_step( int $index ) {
		if ( $index < 0 || $index >= $this->steps() ) {
			return new WP_Error(
				'fbm_demo_step',
				__( 'That import step does not exist.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 === $index ) {
			$label = $this->import_places();
		} elseif ( 1 === $index ) {
			$label = $this->import_routes();
		} else {
			$label = $this->import_day( $index - 2 );
		}

		$done = $index + 1 >= $this->steps();

		if ( $done ) {
			$this->dismiss();

			// A working operation leaves nothing to set up, so the first-run
			// steps that would otherwise lock the dashboard are skipped.
			\FBM\Setup\SetupStatus::mark_complete();
		}

		return array(
			'step'   => $index,
			'steps'  => $this->steps(),
			'label'  => $label,
			'done'   => $done,
			'counts' => $this->counts(),
		);
	}

	/**
	 * Deletes everything the demo created, and nothing else.
	 *
	 * @return array<string, int>
	 */
	public function remove(): array {
		$removed = array();

		// Sailings first: they reference routes, and a route deleted from under
		// a sailing would leave the schedule pointing at nothing while the rest
		// of the removal ran.
		foreach ( array( Sailing::POST_TYPE, Route::POST_TYPE, Vessel::POST_TYPE, Port::POST_TYPE ) as $type ) {
			$removed[ $type ] = 0;

			do {
				$ids = get_posts(
					array(
						'post_type'      => $type,
						'post_status'    => 'any',
						'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Deleted in bounded batches; the loop repeats until none are left.
						'fields'         => 'ids',
						'no_found_rows'  => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded to the demo's own records; there is no other way to find them.
						'meta_key'       => self::FLAG_META,
						'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
					)
				);

				foreach ( $ids as $id ) {
					if ( wp_delete_post( (int) $id, true ) ) {
						++$removed[ $type ];
					}
				}
			} while ( array() !== $ids );
		}

		Options::set( self::OPTION_DISMISSED, 0, true );

		return $removed;
	}

	/**
	 * Counts the demo records currently installed, by type.
	 *
	 * @return array<string, int>
	 */
	private function counts(): array {
		$counts = array();

		foreach ( array( Port::POST_TYPE, Vessel::POST_TYPE, Route::POST_TYPE, Sailing::POST_TYPE ) as $type ) {
			// Asking for one row and reading the total off the query is the
			// whole point: a demo schedule runs to hundreds of sailings, and
			// counting them by fetching them would load every one to throw
			// them all away.
			$query = new WP_Query(
				array(
					'post_type'              => $type,
					'post_status'            => 'any',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counting the demo's own records; there is no other way to find them.
					'meta_key'               => self::FLAG_META,
					'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				)
			);

			$counts[ $type ] = (int) $query->found_posts;
		}

		return $counts;
	}

	/**
	 * Creates the terminals and the fleet.
	 *
	 * @return string
	 */
	private function import_places(): string {
		foreach ( self::ports() as $port ) {
			$id = $this->ports->find_conflicting_id( '_fbm_port_code', $port['code'] );
			$this->remember( $this->ports->save( $port['fields'], $id, $port['name'] ) );
		}

		foreach ( self::vessels() as $vessel ) {
			$id = $this->vessels->find_conflicting_id( '_fbm_vessel_code', $vessel['code'] );
			$this->remember( $this->vessels->save( $vessel['fields'], $id, $vessel['name'] ) );
		}

		$this->apply_branding();

		return __( 'Terminals and vessels', 'magepeople-ferry-booking-system' );
	}

	/**
	 * Creates the routes and prices them.
	 *
	 * @return string
	 */
	private function import_routes(): string {
		$passenger_codes = array();
		$vehicle_codes   = array();

		foreach ( self::routes() as $route ) {
			$passenger_codes = array_merge( $passenger_codes, array_keys( $route['passenger_prices'] ) );
			$vehicle_codes   = array_merge( $vehicle_codes, array_keys( $route['vehicle_prices'] ) );
		}

		$passenger_types = $this->type_ids( $this->passenger_types, '_fbm_pt_code', $passenger_codes );
		$vehicle_types   = $this->type_ids( $this->vehicle_types, '_fbm_vt_code', $vehicle_codes );

		foreach ( self::routes() as $route ) {
			$origin      = $this->port_id( $route['origin'] );
			$destination = $this->port_id( $route['destination'] );
			$vessel      = $this->vessel_id( $route['vessel'] );

			if ( 0 === $origin || 0 === $destination || 0 === $vessel ) {
				continue;
			}

			$fields = array(
				'code'             => $route['code'],
				'origin_port'      => $origin,
				'destination_port' => $destination,
				'duration'         => $route['duration'],
				'distance'         => $route['distance'],
				'default_vessel'   => $vessel,
				'allows_vehicles'  => $route['vehicles'],
				'description'      => $route['description'],
				'status'           => 'active',
				'passenger_prices' => $this->price_map( $route['passenger_prices'], $passenger_types ),
				'vehicle_prices'   => $this->price_map( $route['vehicle_prices'], $vehicle_types ),
			);

			$id = $this->routes->find_conflicting_id( '_fbm_route_code', $route['code'] );
			$this->remember( $this->routes->save( $fields, $id, $route['name'] ) );
		}

		return __( 'Routes and fares', 'magepeople-ferry-booking-system' );
	}

	/**
	 * Schedules one day of departures across every route.
	 *
	 * @param int $offset Days from today.
	 * @return string
	 */
	private function import_day( int $offset ): string {
		$day = gmdate( 'Y-m-d', strtotime( "+$offset day", $this->today() ) );

		foreach ( self::routes() as $route ) {
			$route_id  = $this->route_id( $route['code'] );
			$vessel_id = $this->vessel_id( $route['vessel'] );

			if ( 0 === $route_id || 0 === $vessel_id ) {
				continue;
			}

			foreach ( $route['departures'] as $time ) {
				$departure = $day . ' ' . $time . ':00';
				$arrival   = gmdate( 'Y-m-d H:i:s', strtotime( $departure ) + ( $route['duration'] * MINUTE_IN_SECONDS ) );

				// A sailing has no code of its own, so a repeat import is
				// matched on the one thing that identifies it: this vessel
				// leaving on this route at this moment.
				$existing = $this->existing_sailing( $route_id, $departure );

				$this->remember(
					$this->sailings->save(
						array(
							'route_id'           => $route_id,
							'vessel_id'          => $vessel_id,
							'departure_datetime' => $departure,
							'arrival_datetime'   => $arrival,
							'status'             => 'scheduled',
						),
						$existing
					)
				);
			}
		}

		/* translators: %s: date the departures were scheduled for. */
		return sprintf( __( 'Departures for %s', 'magepeople-ferry-booking-system' ), $day );
	}

	/**
	 * Returns midnight today, in the site's timezone.
	 *
	 * @return int
	 */
	private function today(): int {
		return (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00' );
	}

	/**
	 * Finds a sailing already scheduled on a route at a moment.
	 *
	 * @param int    $route_id  Route.
	 * @param string $departure Local departure datetime.
	 * @return int
	 */
	private function existing_sailing( int $route_id, string $departure ): int {
		$found = get_posts(
			array(
				'post_type'      => Sailing::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Two indexed keys; the alternative is duplicating the schedule on every import.
					'relation' => 'AND',
					array(
						'key'   => '_fbm_route_id',
						'value' => $route_id,
					),
					array(
						'key'   => '_fbm_departure_datetime',
						'value' => $departure,
					),
				),
			)
		);

		return array() === $found ? 0 : (int) $found[0];
	}

	/**
	 * Gives the demo a currency and a name, without overwriting real choices.
	 *
	 * A United States operator priced in euros reads as a mistake, but an
	 * operator who has already set their own currency has made a decision the
	 * demo has no business reversing.
	 *
	 * @return void
	 */
	private function apply_branding(): void {
		$changes = array();

		if ( '' === (string) Settings::get( 'currency', '' ) ) {
			$changes['currency'] = 'USD';
		}

		if ( '' === (string) Settings::get( 'company_name', '' ) ) {
			$changes['company_name'] = __( 'New York Harbor & Sound Ferries', 'magepeople-ferry-booking-system' );
		}

		if ( array() !== $changes ) {
			Settings::save( $changes );
		}
	}

	/**
	 * Turns a code-keyed price list into the id-keyed map a route stores.
	 *
	 * @param array<string, int> $prices Prices in minor units, keyed by code.
	 * @param array<string, int> $ids    Type ids keyed by code.
	 * @return array<string, int>
	 */
	private function price_map( array $prices, array $ids ): array {
		$map = array();

		foreach ( $prices as $code => $amount ) {
			if ( isset( $ids[ $code ] ) ) {
				$map[ (string) $ids[ $code ] ] = (int) $amount;
			}
		}

		return $map;
	}

	/**
	 * Resolves passenger or vehicle type ids from the codes the fares name.
	 *
	 * Looked up one code at a time rather than by listing the catalogue: the
	 * demo prices the types it ships with, and an operator who has renamed or
	 * removed one should simply not get a fare for it, not a fare attached to
	 * whichever type happened to come back in that position.
	 *
	 * @param PassengerTypeRepository|VehicleTypeRepository $repository Repository.
	 * @param string                                        $meta       Code meta key.
	 * @param array<int, string>                            $codes      Codes to resolve.
	 * @return array<string, int>
	 */
	private function type_ids( $repository, string $meta, array $codes ): array {
		$ids = array();

		foreach ( array_unique( $codes ) as $code ) {
			$id = $repository->find_conflicting_id( $meta, $code );

			if ( $id > 0 ) {
				$ids[ $code ] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Resolves a port id from its code.
	 *
	 * @param string $code Port code.
	 * @return int
	 */
	private function port_id( string $code ): int {
		return $this->ports->find_conflicting_id( '_fbm_port_code', $code );
	}

	/**
	 * Resolves a vessel id from its code.
	 *
	 * @param string $code Vessel code.
	 * @return int
	 */
	private function vessel_id( string $code ): int {
		return $this->vessels->find_conflicting_id( '_fbm_vessel_code', $code );
	}

	/**
	 * Resolves a route id from its code.
	 *
	 * @param string $code Route code.
	 * @return int
	 */
	private function route_id( string $code ): int {
		return $this->routes->find_conflicting_id( '_fbm_route_code', $code );
	}

	/**
	 * Marks a saved record as part of the demo.
	 *
	 * @param mixed $result Repository save result.
	 * @return void
	 */
	private function remember( $result ): void {
		if ( is_wp_error( $result ) || ! is_object( $result ) || ! isset( $result->id ) ) {
			return;
		}

		update_post_meta( (int) $result->id, self::FLAG_META, '1' );
	}

	/**
	 * The terminals the demo operator sails from.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function ports(): array {
		$common = array(
			'country'  => 'US',
			'timezone' => 'America/New_York',
			'status'   => 'active',
		);

		$rows = array(
			array( 'NYBAT', 'Battery Park Slip', 'New York, NY', '4 South St, New York, NY 10004', 40.7010, -74.0140, 15 ),
			array( 'NYSTG', 'St. George Terminal', 'Staten Island, NY', '1 Bay St, Staten Island, NY 10301', 40.6437, -74.0736, 15 ),
			array( 'NYDUM', 'Pier 1 · DUMBO', 'Brooklyn, NY', '1 Water St, Brooklyn, NY 11201', 40.7033, -73.9962, 10 ),
			array( 'NYGOV', 'Yankee Pier · Governors Island', 'New York, NY', 'Governors Island, New York, NY 10004', 40.6892, -74.0165, 10 ),
			array( 'NYPTJ', 'Port Jefferson Harbor', 'Port Jefferson, NY', '102 W Broadway, Port Jefferson, NY 11777', 40.9490, -73.0709, 45 ),
			array( 'CTBRI', 'Bridgeport Ferry Terminal', 'Bridgeport, CT', '1 Ferry Access Rd, Bridgeport, CT 06608', 41.1730, -73.1815, 45 ),
			array( 'NYORP', 'Orient Point Terminal', 'Orient, NY', '41270 Main Rd, Orient, NY 11957', 41.1618, -72.2379, 45 ),
			array( 'CTNLO', 'New London City Pier', 'New London, CT', '2 Ferry St, New London, CT 06320', 41.3552, -72.0940, 45 ),
		);

		$ports = array();

		foreach ( $rows as $row ) {
			list( $code, $name, $city, $address, $latitude, $longitude, $checkin ) = $row;

			$ports[] = array(
				'code'   => $code,
				'name'   => $name,
				'fields' => array_merge(
					$common,
					array(
						'code'                 => $code,
						'city'                 => $city,
						'address'              => $address,
						'latitude'             => $latitude,
						'longitude'            => $longitude,
						'checkin_minutes'      => $checkin,
						'checkin_instructions' => __( 'Bring your booking reference. Foot passengers may check in at the kiosk; vehicles use the marshalling lanes.', 'magepeople-ferry-booking-system' ),
						'contact_email'        => 'terminal@example.com',
					)
				),
			);
		}

		return $ports;
	}

	/**
	 * The fleet.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function vessels(): array {
		$rows = array(
			array( 'SIF-01', 'MV Staten Islander', 4400, 0, 16.0, __( 'Step-free access, open upper deck, snack bar', 'magepeople-ferry-booking-system' ) ),
			array( 'HRB-02', 'MV Harbor Spirit', 399, 0, 24.0, __( 'Wi-Fi, bar service, bicycle racks', 'magepeople-ferry-booking-system' ) ),
			array( 'GOV-03', 'MV Governors Belle', 600, 0, 14.0, __( 'Open deck, bicycle racks, step-free access', 'magepeople-ferry-booking-system' ) ),
			array( 'SND-04', 'MV Grand Republic', 1000, 100, 17.0, __( 'Vehicle deck, cafeteria, sun deck, pet area', 'magepeople-ferry-booking-system' ) ),
			array( 'SND-05', 'MV Cross Sound', 950, 120, 18.0, __( 'Vehicle deck, cafeteria, quiet lounge', 'magepeople-ferry-booking-system' ) ),
		);

		$vessels = array();

		foreach ( $rows as $row ) {
			list( $code, $name, $passengers, $vehicles, $knots, $facilities ) = $row;

			$vessels[] = array(
				'code'   => $code,
				'name'   => $name,
				'fields' => array(
					'code'               => $code,
					'registration'       => 'US-' . str_replace( '-', '', $code ),
					'passenger_capacity' => $passengers,
					'vehicle_capacity'   => $vehicles,
					'crew_capacity'      => (int) max( 6, round( $passengers / 120 ) ),
					'speed_knots'        => $knots,
					'facilities'         => array_map( 'trim', explode( ',', $facilities ) ),
					'status'             => 'active',
				),
			);
		}

		return $vessels;
	}

	/**
	 * Moves a list of departure times on by a number of minutes.
	 *
	 * A time that runs past midnight is dropped rather than wrapped: a 23:40
	 * departure shifted by two hours belongs to the next day's timetable, and
	 * silently filing it under this one would put the vessel in two places at
	 * once all over again.
	 *
	 * @param array<int, string> $times   Departure times as "HH:MM".
	 * @param int                $minutes Minutes to add.
	 * @return array<int, string>
	 */
	private static function shifted( array $times, int $minutes ): array {
		if ( 0 === $minutes ) {
			return $times;
		}

		$shifted = array();

		foreach ( $times as $time ) {
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$total                 = ( $hour * 60 ) + $minute + $minutes;

			if ( $total >= 24 * 60 ) {
				continue;
			}

			$shifted[] = sprintf( '%02d:%02d', intdiv( $total, 60 ), $total % 60 );
		}

		return $shifted;
	}

	/**
	 * The routes, their fares and their timetable.
	 *
	 * Every crossing is declared in both directions. A return journey is a
	 * separate sailing on the opposite route, so an operator whose catalogue
	 * only runs one way cannot sell a return at all.
	 *
	 * Fares are in minor units. Only the base passenger type is priced per
	 * route: the others are shipped as a percentage of it, so a child fare
	 * follows the route it is on without being stated twice.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array {
		$harbour = array( '06:30', '08:00', '10:30', '13:00', '16:30', '19:00' );
		$sound   = array( '07:00', '11:00', '15:00', '19:00' );

		$vehicle_fares = array(
			'BICYCLE'     => 700,
			'MOTORCYCLE'  => 3900,
			'CAR'         => 6900,
			'SUV'         => 7900,
			'VAN'         => 8900,
			'CAR_TRAILER' => 10900,
			'CAMPER'      => 12900,
			'MINIBUS'     => 14900,
			'BUS'         => 24900,
			'TRUCK'       => 29900,
		);

		/*
		 * The last number on each row is how many minutes after the outbound
		 * this direction leaves. A crossing is served by one vessel in both
		 * directions, so the return cannot depart at the same minute as the
		 * outbound — the boat is at the other end of it. Sailing both ways at
		 * once is a state the plugin's own vessel-conflict guard refuses, and
		 * demo data has to be data the product would accept. The offset is the
		 * crossing time plus a turnaround long enough to unload and load.
		 */
		$rows = array(
			array( 'NY-BAT-STG', 'Battery Park → St. George', 'NYBAT', 'NYSTG', 25, 5.2, 'SIF-01', false, $harbour, 400, __( 'The classic harbour crossing, past the Statue of Liberty.', 'magepeople-ferry-booking-system' ), 0 ),
			array( 'NY-STG-BAT', 'St. George → Battery Park', 'NYSTG', 'NYBAT', 25, 5.2, 'SIF-01', false, $harbour, 400, __( 'The return crossing into Lower Manhattan.', 'magepeople-ferry-booking-system' ), 40 ),
			array( 'NY-BAT-GOV', 'Battery Park → Governors Island', 'NYBAT', 'NYGOV', 8, 1.1, 'GOV-03', false, $harbour, 400, __( 'A short hop to the island parks and the Hills.', 'magepeople-ferry-booking-system' ), 0 ),
			array( 'NY-GOV-BAT', 'Governors Island → Battery Park', 'NYGOV', 'NYBAT', 8, 1.1, 'GOV-03', false, $harbour, 400, __( 'Back to the Battery.', 'magepeople-ferry-booking-system' ), 25 ),
			array( 'NY-DUM-BAT', 'DUMBO → Battery Park', 'NYDUM', 'NYBAT', 12, 2.4, 'HRB-02', false, $harbour, 425, __( 'Under the Brooklyn Bridge to Lower Manhattan.', 'magepeople-ferry-booking-system' ), 0 ),
			array( 'NY-BAT-DUM', 'Battery Park → DUMBO', 'NYBAT', 'NYDUM', 12, 2.4, 'HRB-02', false, $harbour, 425, __( 'Across the East River to Brooklyn Bridge Park.', 'magepeople-ferry-booking-system' ), 30 ),
			array( 'LI-PTJ-BRI', 'Port Jefferson → Bridgeport', 'NYPTJ', 'CTBRI', 75, 16.0, 'SND-04', true, $sound, 1950, __( 'Across Long Island Sound with your vehicle.', 'magepeople-ferry-booking-system' ), 0 ),
			array( 'LI-BRI-PTJ', 'Bridgeport → Port Jefferson', 'CTBRI', 'NYPTJ', 75, 16.0, 'SND-04', true, $sound, 1950, __( 'The Connecticut side of the Sound crossing.', 'magepeople-ferry-booking-system' ), 105 ),
			array( 'LI-ORP-NLO', 'Orient Point → New London', 'NYORP', 'CTNLO', 80, 16.5, 'SND-05', true, $sound, 2100, __( 'The North Fork crossing to Connecticut.', 'magepeople-ferry-booking-system' ), 0 ),
			array( 'LI-NLO-ORP', 'New London → Orient Point', 'CTNLO', 'NYORP', 80, 16.5, 'SND-05', true, $sound, 2100, __( 'Back to the North Fork of Long Island.', 'magepeople-ferry-booking-system' ), 110 ),
		);

		$routes = array();

		foreach ( $rows as $row ) {
			list( $code, $name, $origin, $destination, $duration, $distance, $vessel, $vehicles, $departures, $adult, $description, $shift ) = $row;

			$departures = self::shifted( $departures, (int) $shift );

			$routes[] = array(
				'code'             => $code,
				'name'             => $name,
				'origin'           => $origin,
				'destination'      => $destination,
				'duration'         => $duration,
				'distance'         => $distance,
				'vessel'           => $vessel,
				'vehicles'         => $vehicles,
				'departures'       => $departures,
				'description'      => $description,
				'passenger_prices' => array( 'ADULT' => $adult ),
				'vehicle_prices'   => $vehicles ? $vehicle_fares : array(),
			);
		}

		return $routes;
	}
}
