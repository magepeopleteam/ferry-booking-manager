<?php
/**
 * Reference data endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Repositories\PassengerTypeRepository;
use MPFBS\Repositories\PortRepository;
use MPFBS\Repositories\RouteRepository;
use MPFBS\Repositories\VehicleTypeRepository;
use MPFBS\Repositories\VesselRepository;
use MPFBS\REST\AbstractController;
use MPFBS\Security\Capabilities;
use MPFBS\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the small lists every form in the dashboard needs.
 *
 * Ports, vessels and routes are picked from in half a dozen different drawers.
 * Serving them from one cached endpoint keeps form drawers to a single request
 * instead of three, and stops each screen inventing its own shape for them.
 */
final class ReferenceController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'references';

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
	 * @param Permissions             $permissions     Permission service.
	 * @param PortRepository          $ports           Port repository.
	 * @param VesselRepository        $vessels         Vessel repository.
	 * @param RouteRepository         $routes          Route repository.
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param VehicleTypeRepository   $vehicle_types   Vehicle type repository.
	 */
	public function __construct(
		Permissions $permissions,
		PortRepository $ports,
		VesselRepository $vessels,
		RouteRepository $routes,
		PassengerTypeRepository $passenger_types,
		VehicleTypeRepository $vehicle_types
	) {
		parent::__construct( $permissions );

		$this->ports           = $ports;
		$this->vessels         = $vessels;
		$this->routes          = $routes;
		$this->passenger_types = $passenger_types;
		$this->vehicle_types   = $vehicle_types;
	}

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_references' ),
					'permission_callback' => $this->can( Capabilities::ACCESS_DASHBOARD ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Returns the reference lists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_references( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond(
			array(
				'ports'           => $this->options(
					$this->ports->query(
						array(
							'per_page' => 100,
							'orderby'  => 'title',
							'order'    => 'asc',
						)
					),
					array( 'code', 'city', 'status' )
				),
				'vessels'         => $this->options(
					$this->vessels->query(
						array(
							'per_page' => 100,
							'orderby'  => 'title',
							'order'    => 'asc',
						)
					),
					array( 'code', 'passenger_capacity', 'vehicle_capacity', 'deck_capacity', 'status' )
				),
				'routes'          => $this->options(
					$this->routes->query(
						array(
							'per_page' => 100,
							'orderby'  => 'title',
							'order'    => 'asc',
						)
					),
					array( 'code', 'origin_port', 'destination_port', 'duration', 'default_vessel', 'allows_vehicles', 'status' )
				),

				'passenger_types' => $this->options(
					$this->passenger_types->query(
						array(
							'per_page' => 100,
							'orderby'  => 'sort_order',
							'order'    => 'asc',
						)
					),
					array( 'code', 'min_age', 'max_age', 'requires_dob', 'requires_adult', 'occupies_seat', 'is_base', 'price_mode', 'base_price', 'price_percent', 'min_per_booking', 'max_per_booking', 'status' )
				),

				'vehicle_types'   => $this->options(
					$this->vehicle_types->query(
						array(
							'per_page' => 100,
							'orderby'  => 'sort_order',
							'order'    => 'asc',
						)
					),
					array( 'code', 'category', 'length', 'lane_metres', 'capacity_units', 'included_passengers', 'base_price', 'price_per_metre', 'requires_registration', 'requires_driver', 'allows_trailer', 'max_per_booking', 'status' )
				),
			)
		);
	}

	/**
	 * Reduces a repository result to id, name and the fields a picker needs.
	 *
	 * @param array{items: array<int, \MPFBS\Models\Entity>, total: int, page: int, per_page: int} $result Repository result.
	 * @param string[]                                                                           $fields Field names to include.
	 * @return array<int, array<string, mixed>>
	 */
	private function options( array $result, array $fields ): array {
		$options = array();

		foreach ( $result['items'] as $entity ) {
			$option = array(
				'id'   => $entity->id,
				'name' => $entity->name,
			);

			foreach ( $fields as $field ) {
				$option[ $field ] = $entity->get( $field );
			}

			$options[] = $option;
		}

		return $options;
	}
}
