<?php
/**
 * Sailings endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\Models\Entity;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VesselRepository;
use FBM\REST\EntityController;
use FBM\REST\Response;
use FBM\Sailing\ScheduleGenerator;
use FBM\Security\Permissions;
use FBM\Support\Time;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD and bulk scheduling for sailings.
 */
final class SailingController extends EntityController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'sailings';

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
	 * Schedule generator.
	 *
	 * @var ScheduleGenerator
	 */
	private ScheduleGenerator $generator;

	/**
	 * Constructor.
	 *
	 * @param Permissions       $permissions Permission service.
	 * @param SailingRepository $sailings    Sailing repository.
	 * @param RouteRepository   $routes      Route repository.
	 * @param VesselRepository  $vessels     Vessel repository.
	 * @param ScheduleGenerator $generator   Schedule generator.
	 */
	public function __construct(
		Permissions $permissions,
		SailingRepository $sailings,
		RouteRepository $routes,
		VesselRepository $vessels,
		ScheduleGenerator $generator
	) {
		parent::__construct( $permissions, $sailings );

		$this->routes    = $routes;
		$this->vessels   = $vessels;
		$this->generator = $generator;
	}

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		parent::register_routes();

		$capability = $this->repository->schema()->capability();

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/schedule',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run_schedule' ),
					'permission_callback' => $this->can( $capability ),
					'args'                => $this->schedule_params(),
				),
			)
		);
	}

	/**
	 * Previews or creates a repeating schedule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_schedule( WP_REST_Request $request ): WP_REST_Response {
		$pattern = $this->request_payload( $request );
		$commit  = ! empty( $pattern['commit'] );

		if ( ! $commit ) {
			$preview = $this->generator->preview( $pattern );

			if ( is_wp_error( $preview ) ) {
				return Response::from_wp_error( $preview );
			}

			return $this->respond(
				array(
					'committed'  => false,
					'candidates' => $this->decorate_candidates( $preview['candidates'] ),
					'summary'    => $preview['summary'],
				)
			);
		}

		$result = $this->generator->generate( $pattern );

		if ( is_wp_error( $result ) ) {
			return Response::from_wp_error( $result );
		}

		return $this->respond(
			array(
				'committed' => true,
				'created'   => $result['created'],
				'skipped'   => $this->decorate_candidates( $result['skipped'] ),
			)
		);
	}

	/**
	 * Applies domain rules that span more than one field.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param int                  $id         Existing id, or 0 when creating.
	 * @return true|WP_Error
	 */
	protected function guard( array $attributes, int $id ) {
		$route_id  = isset( $attributes['route_id'] ) ? (int) $attributes['route_id'] : 0;
		$vessel_id = isset( $attributes['vessel_id'] ) ? (int) $attributes['vessel_id'] : 0;
		$departure = isset( $attributes['departure_datetime'] ) ? (string) $attributes['departure_datetime'] : '';
		$arrival   = isset( $attributes['arrival_datetime'] ) ? (string) $attributes['arrival_datetime'] : '';

		if ( '' !== $departure && '' !== $arrival ) {
			$departs = Time::local_to_timestamp( $departure );
			$arrives = Time::local_to_timestamp( $arrival );

			if ( $arrives <= $departs ) {
				return new WP_Error(
					'fbm_invalid_sailing',
					__( 'The arrival must be after the departure.', 'ferry-booking-manager' ),
					array(
						'status' => 422,
						'fields' => array(
							'arrival_datetime' => __( 'Arrival must be later than departure. Journeys that run past midnight are fine — use the next day’s date.', 'ferry-booking-manager' ),
						),
					)
				);
			}
		}

		$open  = isset( $attributes['booking_open'] ) ? (string) $attributes['booking_open'] : '';
		$close = isset( $attributes['booking_close'] ) ? (string) $attributes['booking_close'] : '';

		if ( '' !== $open && '' !== $close && Time::local_to_timestamp( $close ) <= Time::local_to_timestamp( $open ) ) {
			return new WP_Error(
				'fbm_invalid_sailing',
				__( 'Bookings would close before they open.', 'ferry-booking-manager' ),
				array(
					'status' => 422,
					'fields' => array( 'booking_close' => __( 'Choose a time after bookings open.', 'ferry-booking-manager' ) ),
				)
			);
		}

		if ( $vessel_id > 0 && '' !== $departure ) {
			/** @var Route|null $route */
			$route     = $route_id > 0 ? $this->routes->find( $route_id ) : null;
			$duration  = null === $route ? 0 : $route->duration();
			$conflicts = $this->sailing_repository()->find_vessel_conflicts( $vessel_id, $departure, $duration, $id );

			if ( array() !== $conflicts ) {
				$other = $this->sailing_repository()->find( (int) $conflicts[0] );

				return new WP_Error(
					'fbm_vessel_conflict',
					sprintf(
						/* translators: %s: the conflicting sailing's name. */
						__( 'That vessel is already sailing at this time on “%s”. A vessel cannot be in two places at once.', 'ferry-booking-manager' ),
						null === $other ? '' : $other->name
					),
					array(
						'status' => 409,
						'fields' => array(
							'vessel_id' => __( 'This vessel is already committed to another sailing in that window.', 'ferry-booking-manager' ),
						),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Creates or updates a sailing.
	 *
	 * Fills in the arrival from the route duration when it was left blank, so an
	 * operator only has to enter a departure time.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $id      Existing id, or 0 to create.
	 * @return WP_REST_Response
	 */
	protected function write( WP_REST_Request $request, int $id ): WP_REST_Response {
		$payload = $this->request_payload( $request );

		if ( empty( $payload['arrival_datetime'] ) && ! empty( $payload['departure_datetime'] ) && ! empty( $payload['route_id'] ) ) {
			/** @var Route|null $route */
			$route = $this->routes->find( (int) $payload['route_id'] );

			if ( null !== $route && $route->duration() > 0 ) {
				$payload['arrival_datetime'] = Time::add_minutes( (string) $payload['departure_datetime'], $route->duration() );
				$request->set_body( (string) wp_json_encode( $payload ) );
			}
		}

		return parent::write( $request, $id );
	}

	/**
	 * Returns repository arguments derived from the sailing filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	protected function extra_query_args( WP_REST_Request $request ): array {
		return array(
			'route_id'  => (int) $request->get_param( 'route_id' ),
			'vessel_id' => (int) $request->get_param( 'vessel_id' ),
			'from'      => (string) $request->get_param( 'from' ),
			'to'        => (string) $request->get_param( 'to' ),
			'date'      => (string) $request->get_param( 'date' ),
		);
	}

	/**
	 * Returns the collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_collection_params(): array {
		$params = parent::get_collection_params();

		$params['orderby']['default'] = 'departure_ts';

		foreach ( array( 'route_id', 'vessel_id' ) as $param ) {
			$params[ $param ] = array(
				'description'       => __( 'Filter by record id.', 'ferry-booking-manager' ),
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			);
		}

		foreach ( array( 'from', 'to', 'date' ) as $param ) {
			$params[ $param ] = array(
				'description'       => __( 'Filter by departure date.', 'ferry-booking-manager' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			);
		}

		return $params;
	}

	/**
	 * Serialises an entity for a response.
	 *
	 * A sailing row is meaningless without its route, vessel and effective
	 * capacity, so those are resolved here instead of leaving the interface to
	 * fetch them per row.
	 *
	 * @param Entity $entity Entity.
	 * @return array<string, mixed>
	 */
	protected function prepare_item( Entity $entity ): array {
		$payload = parent::prepare_item( $entity );

		/** @var Sailing $entity */
		$route  = $this->routes->find( $entity->route_id() );
		$vessel = $this->vessels->find( $entity->vessel_id() );

		$payload['route_name']  = null === $route ? '' : $route->name;
		$payload['vessel_name'] = null === $vessel ? '' : $vessel->name;
		$payload['duration']    = null === $route ? 0 : $route->duration();
		$payload['capacity']    = $this->effective_capacity( $entity, $vessel );
		$payload['is_bookable'] = $entity->is_bookable();

		return $payload;
	}

	/**
	 * Resolves the capacity a sailing actually offers.
	 *
	 * A sailing inherits its vessel's capacity unless an override is set, which
	 * is how a partial closure or a chartered block is modelled.
	 *
	 * @param Sailing     $sailing Sailing.
	 * @param Vessel|null $vessel  Assigned vessel.
	 * @return array<string, mixed>
	 */
	private function effective_capacity( Sailing $sailing, ?Vessel $vessel ): array {
		$passenger_override = (int) $sailing->get( 'passenger_capacity_override' );
		$vehicle_override   = (int) $sailing->get( 'vehicle_capacity_override' );
		$deck_override      = (float) $sailing->get( 'deck_capacity_override' );

		return array(
			'passengers'           => $passenger_override > 0 ? $passenger_override : ( null === $vessel ? 0 : $vessel->passenger_capacity() ),
			'vehicles'             => $vehicle_override > 0 ? $vehicle_override : ( null === $vessel ? 0 : $vessel->vehicle_capacity() ),
			'lane_metres'          => $deck_override > 0 ? $deck_override : ( null === $vessel ? 0.0 : $vessel->deck_capacity() ),
			'passengers_override'  => $passenger_override > 0,
			'vehicles_override'    => $vehicle_override > 0,
			'lane_metres_override' => $deck_override > 0,
		);
	}

	/**
	 * Adds human readable context to generator candidates.
	 *
	 * @param array<int, array<string, mixed>> $candidates Candidates.
	 * @return array<int, array<string, mixed>>
	 */
	private function decorate_candidates( array $candidates ): array {
		foreach ( $candidates as $index => $candidate ) {
			$conflict = isset( $candidate['conflict_with'] ) ? (int) $candidate['conflict_with'] : 0;

			$candidates[ $index ]['conflict_name'] = $conflict > 0 ? get_the_title( $conflict ) : '';
		}

		return $candidates;
	}

	/**
	 * Returns the sailing repository with its concrete type.
	 *
	 * @return SailingRepository
	 */
	private function sailing_repository(): SailingRepository {
		/** @var SailingRepository $repository */
		$repository = $this->repository;

		return $repository;
	}

	/**
	 * Returns the schedule endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schedule_params(): array {
		return array(
			'route_id'  => array(
				'description'       => __( 'Route to schedule.', 'ferry-booking-manager' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'vessel_id' => array(
				'description'       => __( 'Vessel to assign.', 'ferry-booking-manager' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'date_from' => array(
				'description'       => __( 'First date in the range.', 'ferry-booking-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to'   => array(
				'description'       => __( 'Last date in the range.', 'ferry-booking-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'weekdays'  => array(
				'description' => __( 'Days of the week to sail, 0 for Sunday through 6 for Saturday.', 'ferry-booking-manager' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
			),
			'times'     => array(
				'description' => __( 'Departure times as HH:MM.', 'ferry-booking-manager' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
			),
			'commit'    => array(
				'description' => __( 'Create the sailings instead of previewing them.', 'ferry-booking-manager' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}
}
