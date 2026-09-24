<?php
/**
 * Availability endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Availability\Availability;
use MPFBS\Availability\AvailabilityService;
use MPFBS\Repositories\SailingRepository;
use MPFBS\REST\AbstractController;
use MPFBS\REST\Response;
use MPFBS\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Reports what is left to sell on one sailing or a set of them.
 *
 * Reads only. Capacity is consumed by placing a hold through the booking
 * endpoints, never by asking this controller a question.
 */
final class AvailabilityController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'availability';

	/**
	 * Largest number of sailings one request may ask about.
	 */
	private const MAX_BATCH = 100;

	/**
	 * Availability engine.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Constructor.
	 *
	 * @param Permissions         $permissions  Permission service.
	 * @param AvailabilityService $availability Availability engine.
	 * @param SailingRepository   $sailings     Sailing repository.
	 */
	public function __construct(
		Permissions $permissions,
		AvailabilityService $availability,
		SailingRepository $sailings
	) {
		parent::__construct( $permissions );

		$this->availability = $availability;
		$this->sailings     = $sailings;
	}

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description'       => __( 'Sailing id.', 'magepeople-ferry-booking-system' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_one' ),
					'permission_callback' => '__return_true',
					'args'                => $this->quote_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_many' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'sailings' => array(
							'description'       => __( 'Comma-separated sailing ids.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Returns availability for one sailing, optionally testing a request against it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_one( WP_REST_Request $request ): WP_REST_Response {
		$throttle = $this->permissions->public_access( 'availability', 120 );

		if ( is_wp_error( $throttle ) ) {
			return Response::from_wp_error( $throttle );
		}

		$availability = $this->availability->for_sailing( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $availability ) ) {
			return Response::from_wp_error( $availability );
		}

		$payload = $availability->to_array();

		$wanted = array(
			Availability::PASSENGERS  => (int) $request->get_param( 'passengers' ),
			Availability::VEHICLES    => (int) $request->get_param( 'vehicles' ),
			Availability::LANE_METRES => (float) $request->get_param( 'lane_metres' ),
		);

		if ( array_filter( $wanted ) ) {
			$verdict = $this->availability->check( $availability, $wanted );

			$payload['requested'] = $wanted;
			$payload['fits']      = ! is_wp_error( $verdict );
			$payload['message']   = is_wp_error( $verdict ) ? $verdict->get_error_message() : '';
		}

		return $this->respond( $payload );
	}

	/**
	 * Returns availability for a batch of sailings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_many( WP_REST_Request $request ): WP_REST_Response {
		$throttle = $this->permissions->public_access( 'availability', 120 );

		if ( is_wp_error( $throttle ) ) {
			return Response::from_wp_error( $throttle );
		}

		$raw = (string) $request->get_param( 'sailings' );
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', explode( ',', $raw ) )
				)
			)
		);

		if ( array() === $ids ) {
			return $this->fail(
				'mpfbs_missing_sailings',
				__( 'List the sailing ids you want availability for.', 'magepeople-ferry-booking-system' ),
				400
			);
		}

		if ( count( $ids ) > self::MAX_BATCH ) {
			return $this->fail(
				'mpfbs_batch_too_large',
				sprintf(
					/* translators: %d: maximum number of sailings per request. */
					__( 'Ask about at most %d sailings at a time.', 'magepeople-ferry-booking-system' ),
					self::MAX_BATCH
				),
				400
			);
		}

		$sailings = $this->sailings->find_many( $ids );
		$payload  = array();

		foreach ( $this->availability->for_sailings( array_values( $sailings ) ) as $sailing_id => $availability ) {
			$payload[ (string) $sailing_id ] = $availability->to_array();
		}

		return $this->respond( $payload, array( 'total' => count( $payload ) ) );
	}

	/**
	 * Returns the optional "will this fit" arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function quote_args(): array {
		return array(
			'passengers'  => array(
				'description'       => __( 'Passenger seats requested.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'vehicles'    => array(
				'description'       => __( 'Vehicle spaces requested.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'lane_metres' => array(
				'description' => __( 'Lane metres requested.', 'magepeople-ferry-booking-system' ),
				'type'        => 'number',
				'default'     => 0,
				'minimum'     => 0,
			),
		);
	}
}
