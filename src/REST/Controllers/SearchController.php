<?php
/**
 * Public search endpoints.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Booking\FieldConfig;
use MPFBS\Models\PassengerType;
use MPFBS\Repositories\PassengerTypeRepository;
use MPFBS\Repositories\VehicleTypeRepository;
use MPFBS\REST\AbstractController;
use MPFBS\REST\Response;
use MPFBS\Search\SearchService;
use MPFBS\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;
use MPFBS\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What a customer can see without an account.
 *
 * Everything here is information a ferry operator publishes anyway: which ports
 * they serve, when the boats leave, how full they are and what a ticket costs.
 * No endpoint on this controller returns anything about a booking or a person.
 */
final class SearchController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'search';

	/**
	 * Search service.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

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
	 * @param SearchService           $search          Search service.
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param VehicleTypeRepository   $vehicle_types   Vehicle type repository.
	 */
	public function __construct(
		Permissions $permissions,
		SearchService $search,
		PassengerTypeRepository $passenger_types,
		VehicleTypeRepository $vehicle_types
	) {
		parent::__construct( $permissions );

		$this->search          = $search;
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
					'callback'            => $this->public_handler( 'search', 90, array( $this, 'get_results' ) ),
					'permission_callback' => '__return_true',
					'args'                => $this->search_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fares',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => $this->public_handler( 'fares', 120, array( $this, 'get_fares' ) ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'origin'      => array(
							'description'       => __( 'Departure port id.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'destination' => array(
							'description'       => __( 'Arrival port id.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/booking-options',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => $this->public_handler( 'options', 120, array( $this, 'get_options' ) ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Returns matching crossings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_results( WP_REST_Request $request ): WP_REST_Response {
		$results = $this->search->search(
			array(
				'origin'        => (int) $request->get_param( 'origin' ),
				'destination'   => (int) $request->get_param( 'destination' ),
				'date'          => (string) $request->get_param( 'date' ),
				'return_date'   => (string) $request->get_param( 'return_date' ),
				'flexible_days' => (int) $request->get_param( 'flexible_days' ),
				'passengers'    => $this->party( $request->get_param( 'passengers' ) ),
				'vehicles'      => $this->party( $request->get_param( 'vehicles' ) ),
			)
		);

		if ( is_wp_error( $results ) ) {
			return Response::from_wp_error( $results );
		}

		return $this->respond(
			$results,
			array(
				'outbound' => count( $results['outbound'] ),
				'inbound'  => count( $results['inbound'] ),
			)
		);
	}

	/**
	 * Returns the guide fare of every sellable type between two ports.
	 *
	 * Answers "what does a child cost" while the customer is still building
	 * their party. It is a floor, not a total: a sailing may adjust its own
	 * fares, and discounts, fees and tax are settled on the finished basket, so
	 * the booking form labels these as prices to travel *from*.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_fares( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond(
			$this->search->fares(
				(int) $request->get_param( 'origin' ),
				(int) $request->get_param( 'destination' )
			)
		);
	}

	/**
	 * Returns everything a booking form needs to render.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_options( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$options = $this->search->options();

		return $this->respond(
			array(
				'ports'           => $options['ports'],
				'connections'     => $options['connections'],
				'passenger_types' => $this->passenger_type_payload(),
				'vehicle_types'   => $this->vehicle_type_payload(),
				'fields'          => array(
					'passenger' => $this->public_fields( FieldConfig::GROUP_PASSENGER ),
					'vehicle'   => $this->public_fields( FieldConfig::GROUP_VEHICLE ),
				),
				'today'           => $this->search->today(),
				'currency'        => $this->currency(),
			)
		);
	}

	/**
	 * Returns the sellable passenger types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function passenger_type_payload(): array {
		$payload = array();

		foreach ( $this->passenger_types->active() as $type ) {
			$payload[] = array(
				'id'              => $type->id,
				'name'            => $type->name,
				'code'            => $type->code(),
				'description'     => (string) $type->get( 'description' ),
				'min_age'         => (int) $type->get( 'min_age' ),
				'max_age'         => (int) $type->get( 'max_age' ),
				'requires_dob'    => (bool) $type->get( 'requires_dob' ),
				'requires_adult'  => (bool) $type->get( 'requires_adult' ),
				'occupies_seat'   => $type->occupies_seat(),
				'min_per_booking' => (int) $type->get( 'min_per_booking' ),
				'max_per_booking' => (int) $type->get( 'max_per_booking' ),
				'is_free'         => PassengerType::PRICE_FREE === $type->get( 'price_mode' ),
			);
		}

		return $payload;
	}

	/**
	 * Returns the sellable vehicle types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function vehicle_type_payload(): array {
		$payload = array();

		foreach ( $this->vehicle_types->active() as $type ) {
			$payload[] = array(
				'id'                    => $type->id,
				'name'                  => $type->name,
				'code'                  => $type->code(),
				'category'              => (string) $type->get( 'category' ),
				'description'           => (string) $type->get( 'description' ),
				'length'                => (float) $type->get( 'length' ),
				'height'                => (float) $type->get( 'height' ),
				'lane_metres'           => $type->lane_metres(),
				'requires_registration' => (bool) $type->get( 'requires_registration' ),
				'requires_driver'       => (bool) $type->get( 'requires_driver' ),
				'requires_dimensions'   => (bool) $type->get( 'requires_dimensions' ),
				'allows_trailer'        => (bool) $type->get( 'allows_trailer' ),
				'max_per_booking'       => (int) $type->get( 'max_per_booking' ),
			);
		}

		return $payload;
	}

	/**
	 * Returns the capture fields a customer will actually be shown.
	 *
	 * Fields the operator switched off are omitted entirely rather than sent
	 * with a flag, so the browser cannot render something it was told not to.
	 *
	 * @param string $group Field group.
	 * @return array<int, array<string, mixed>>
	 */
	private function public_fields( string $group ): array {
		$fields = array();

		foreach ( FieldConfig::form( $group ) as $field ) {
			if ( FieldConfig::MODE_OFF === $field['mode'] ) {
				continue;
			}

			$fields[] = array(
				'key'      => $field['key'],
				'label'    => $field['label'],
				'type'     => $field['type'],
				'required' => FieldConfig::MODE_REQUIRED === $field['mode'],
			);
		}

		return $fields;
	}

	/**
	 * Returns the currency presentation the frontend needs.
	 *
	 * The same resolver the dashboard uses, so a fare shown in the booking form
	 * is written exactly as it is written on the ticket and in the confirmation
	 * email.
	 *
	 * @return array<string, mixed>
	 */
	private function currency(): array {
		$currency = Money::currency();

		/**
		 * Filters the currency presentation sent to the booking frontend.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $currency Currency settings.
		 */
		return (array) apply_filters( 'mpfbs_frontend_currency', $currency );
	}

	/**
	 * Reduces a submitted party parameter to type id => quantity.
	 *
	 * @param mixed $party Raw parameter, either an object or a "id:qty" list.
	 * @return array<int, int>
	 */
	private function party( $party ): array {
		if ( is_string( $party ) ) {
			$decoded = json_decode( $party, true );

			if ( is_array( $decoded ) ) {
				$party = $decoded;
			} else {
				$pairs = array();

				foreach ( explode( ',', $party ) as $pair ) {
					$bits = explode( ':', $pair );

					if ( 2 === count( $bits ) ) {
						$pairs[ (int) $bits[0] ] = (int) $bits[1];
					}
				}

				$party = $pairs;
			}
		}

		if ( ! is_array( $party ) ) {
			return array();
		}

		$clean = array();

		foreach ( $party as $id => $quantity ) {
			$id       = (int) $id;
			$quantity = (int) $quantity;

			if ( $id > 0 && $quantity > 0 ) {
				$clean[ $id ] = min( 99, $quantity );
			}
		}

		return $clean;
	}

	/**
	 * Returns the search query arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function search_args(): array {
		return array(
			'origin'        => array(
				'description'       => __( 'Departure port id.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'destination'   => array(
				'description'       => __( 'Arrival port id.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'date'          => array(
				'description'       => __( 'Departure date, YYYY-MM-DD.', 'magepeople-ferry-booking-system' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'return_date'   => array(
				'description'       => __( 'Return date, YYYY-MM-DD.', 'magepeople-ferry-booking-system' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'flexible_days' => array(
				'description'       => __( 'Days either side of the chosen date to include.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'maximum'           => 7,
				'sanitize_callback' => 'absint',
			),
			'passengers'    => array(
				'description' => __( 'Passenger type id to quantity.', 'magepeople-ferry-booking-system' ),
				'type'        => array( 'object', 'string' ),
				'default'     => array(),
			),
			'vehicles'      => array(
				'description' => __( 'Vehicle type id to quantity.', 'magepeople-ferry-booking-system' ),
				'type'        => array( 'object', 'string' ),
				'default'     => array(),
			),
		);
	}
}
