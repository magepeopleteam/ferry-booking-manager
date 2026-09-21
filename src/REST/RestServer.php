<?php
/**
 * REST bootstrap.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST;

use MPFBS\Contracts\ContainerInterface;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `mpfbs/v1` REST namespace.
 *
 * Controllers are resolved lazily from the container so that a plain front-end
 * page request never constructs the admin controller graph.
 */
final class RestServer {

	/**
	 * Container used to resolve controllers.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Container identifiers of registered controllers.
	 *
	 * @var string[]
	 */
	private array $controllers = array();

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Plugin container.
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Adds a controller service id to the registry.
	 *
	 * @param string $service_id Container identifier resolving to an AbstractController.
	 * @return void
	 */
	public function add_controller( string $service_id ): void {
		if ( ! in_array( $service_id, $this->controllers, true ) ) {
			$this->controllers[] = $service_id;
		}
	}

	/**
	 * Attaches the REST hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'normalize_response' ), 10, 3 );
	}

	/**
	 * Registers every controller's routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		/**
		 * Filters the REST controllers registered under `mpfbs/v1`.
		 *
		 * Extensions append their controller service ids here.
		 *
		 * @since 1.0.0
		 *
		 * @param string[]           $controllers Container service identifiers.
		 * @param ContainerInterface $container   Plugin container.
		 */
		$controllers = (array) apply_filters( 'mpfbs_rest_controllers', $this->controllers, $this->container );

		foreach ( $controllers as $service_id ) {
			if ( ! is_string( $service_id ) || ! $this->container->has( $service_id ) ) {
				continue;
			}

			$controller = $this->container->get( $service_id );

			if ( $controller instanceof AbstractController ) {
				$controller->register_routes();
			}
		}

		/**
		 * Fires after MagePeople Ferry Booking System has registered its REST routes.
		 *
		 * @since 1.0.0
		 *
		 * @param ContainerInterface $container Plugin container.
		 */
		do_action( 'mpfbs_rest_routes_registered', $this->container );
	}

	/**
	 * Forces the plugin envelope on every `mpfbs/v1` response.
	 *
	 * @param WP_HTTP_Response|WP_Error $result  Dispatch result.
	 * @param WP_REST_Server            $server  REST server.
	 * @param WP_REST_Request           $request Request.
	 * @return WP_HTTP_Response|WP_Error
	 */
	public function normalize_response( $result, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 0 !== strpos( ltrim( (string) $request->get_route(), '/' ), AbstractController::NAMESPACE ) ) {
			return $result;
		}

		return Response::normalize( $result );
	}
}
