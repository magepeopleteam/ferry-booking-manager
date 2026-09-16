<?php
/**
 * Pricing endpoints.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\Availability\AvailabilityService;
use FBM\Pricing\PricingService;
use FBM\Pricing\PricingSettings;
use FBM\REST\AbstractController;
use FBM\REST\Response;
use FBM\Security\Capabilities;
use FBM\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Prices a party, and stores the commercial rules used to do it.
 *
 * Quoting is deliberately a POST even though it changes nothing: a party is a
 * nested structure, and squeezing it into a query string would mean two ways of
 * expressing the same request and two places to get it wrong.
 */
final class PricingController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'pricing';

	/**
	 * Pricing engine.
	 *
	 * @var PricingService
	 */
	private PricingService $pricing;

	/**
	 * Availability engine.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * Constructor.
	 *
	 * @param Permissions         $permissions  Permission service.
	 * @param PricingService      $pricing      Pricing engine.
	 * @param AvailabilityService $availability Availability engine.
	 */
	public function __construct(
		Permissions $permissions,
		PricingService $pricing,
		AvailabilityService $availability
	) {
		parent::__construct( $permissions );

		$this->pricing      = $pricing;
		$this->availability = $availability;
	}

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/quote',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_quote' ),
					// A customer has to see a price before they have an account,
					// and this returns nothing but arithmetic over public fares.
					'permission_callback' => $this->permissions->rest_public_callback( 'quote', 90 ),
					'args'                => array(
						'sailing_id'        => array(
							'description'       => __( 'Outbound sailing id.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'return_sailing_id' => array(
							'description'       => __( 'Return sailing id.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'passengers'        => array(
							'description' => __( 'Passenger type id to quantity.', 'magepeople-ferry-booking-system' ),
							'type'        => array( 'object', 'array' ),
						),
						'vehicles'          => array(
							'description' => __( 'Vehicle type id to quantity.', 'magepeople-ferry-booking-system' ),
							'type'        => array( 'object', 'array' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_PRICING ),
				),
				array(
					'methods'             => 'PUT, PATCH, POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_PRICING ),
				),
			)
		);
	}

	/**
	 * Prices a party and reports whether it still fits.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_quote( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		/*
		 * The whole payload is carried through, with the values this controller
		 * validates put back on top. An add-on that prices something extra —
		 * a cabin, a meal, a bag — reads its own keys from the request handed
		 * to the fbm_quote filter, and rebuilding the array from four known
		 * fields would silently drop them.
		 */
		$quote = $this->pricing->quote(
			array_merge(
				$payload,
				array(
					'sailing_id'        => (int) $request->get_param( 'sailing_id' ),
					'return_sailing_id' => (int) $request->get_param( 'return_sailing_id' ),
					'passengers'        => $payload['passengers'] ?? array(),
					'vehicles'          => $payload['vehicles'] ?? array(),
				)
			)
		);

		if ( is_wp_error( $quote ) ) {
			return Response::from_wp_error( $quote );
		}

		$body = $quote->to_array();

		/*
		 * A price the customer cannot act on is worse than no price, so the
		 * quote carries the answer to "can I actually book this" alongside the
		 * total, from the same engine the booking itself will consult.
		 */
		foreach ( array_filter( array( $quote->sailing_id, $quote->return_sailing_id ) ) as $sailing_id ) {
			$availability = $this->availability->for_sailing( (int) $sailing_id );

			if ( is_wp_error( $availability ) ) {
				continue;
			}

			$verdict = $this->availability->check( $availability, $quote->usage );

			$body['availability'][ (string) $sailing_id ] = array(
				'fits'    => ! is_wp_error( $verdict ),
				'message' => is_wp_error( $verdict ) ? $verdict->get_error_message() : '',
			);
		}

		return $this->respond( $body );
	}

	/**
	 * Returns the pricing settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond( PricingSettings::all() );
	}

	/**
	 * Stores the pricing settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();

		return $this->respond( PricingSettings::save( is_array( $payload ) ? $payload : array() ) );
	}
}
