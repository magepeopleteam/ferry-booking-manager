<?php
/**
 * Base REST controller.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST;

use MPFBS\Security\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for every `mpfbs/v1` controller.
 *
 * Controllers stay thin: they validate and sanitise input, delegate to a service
 * and serialise the result. Business rules never live here.
 */
abstract class AbstractController {

	/**
	 * REST namespace shared by the whole plugin.
	 */
	public const NAMESPACE = 'mpfbs/v1';

	/**
	 * Allowed page sizes.
	 *
	 * @var int[]
	 */
	public const PER_PAGE_OPTIONS = array( 20, 50, 100 );

	/**
	 * Route base, relative to the namespace.
	 *
	 * @var string
	 */
	protected string $rest_base = '';

	/**
	 * Permission service.
	 *
	 * @var Permissions
	 */
	protected Permissions $permissions;

	/**
	 * Constructor.
	 *
	 * @param Permissions $permissions Permission service.
	 */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Returns the controller route base.
	 *
	 * @return string
	 */
	public function get_rest_base(): string {
		return $this->rest_base;
	}

	/**
	 * Builds a permission callback for a capability.
	 *
	 * @param string $capability Capability slug.
	 * @return callable(): (true|WP_Error)
	 */
	protected function can( string $capability ): callable {
		return $this->permissions->rest_capability_callback( $capability );
	}

	/**
	 * Wraps the handler of an intentionally public endpoint.
	 *
	 * Public endpoints register `__return_true` as their permission callback.
	 * The handler still runs behind the public-API switch and the per-visitor
	 * rate limit, which this wrapper applies before calling it.
	 *
	 * @param string   $bucket  Rate-limit bucket name.
	 * @param int      $limit   Requests allowed per minute.
	 * @param callable $handler Route handler receiving the request.
	 * @return callable(WP_REST_Request): (WP_REST_Response|WP_Error)
	 */
	protected function public_handler( string $bucket, int $limit, callable $handler ): callable {
		return function ( WP_REST_Request $request ) use ( $bucket, $limit, $handler ) {
			$access = $this->permissions->public_access( $bucket, $limit );

			if ( true !== $access ) {
				return $access;
			}

			return $handler( $request );
		};
	}

	/**
	 * Returns the shared collection query arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function collection_params(): array {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items returned per page.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 20,
				'enum'              => self::PER_PAGE_OPTIONS,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'   => array(
				'description'       => __( 'Limit results to those matching a string.', 'magepeople-ferry-booking-system' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'    => array(
				'description'       => __( 'Sort direction.', 'magepeople-ferry-booking-system' ),
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Reads the requested page number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	protected function page( WP_REST_Request $request ): int {
		return max( 1, (int) $request->get_param( 'page' ) );
	}

	/**
	 * Reads the requested page size, clamped to the allowed options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	protected function per_page( WP_REST_Request $request ): int {
		$per_page = (int) $request->get_param( 'per_page' );

		return in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : 20;
	}

	/**
	 * Builds a success response.
	 *
	 * @param mixed                $data   Payload.
	 * @param array<string, mixed> $meta   Meta.
	 * @param int                  $status HTTP status.
	 * @return WP_REST_Response
	 */
	protected function respond( $data = null, array $meta = array(), int $status = 200 ): WP_REST_Response {
		return Response::success( $data, $meta, $status );
	}

	/**
	 * Builds an error response.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Context.
	 * @return WP_REST_Response
	 */
	protected function fail( string $code, string $message, int $status = 400, array $data = array() ): WP_REST_Response {
		return Response::error( $code, $message, $data, $status );
	}
}
