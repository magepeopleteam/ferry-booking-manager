<?php
/**
 * Demo content endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Demo\DemoContent;
use MPFBS\REST\AbstractController;
use MPFBS\Security\Capabilities;
use MPFBS\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Installs, describes and removes the demo operation.
 *
 * The import is driven one step at a time by the client rather than run in a
 * single call. Ten days of schedule across ten routes is several hundred posts,
 * and a shared host that stops the request at thirty seconds would leave a
 * half-built catalogue with no way to tell what had been done.
 */
final class DemoController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'demo';

	/**
	 * Demo content service.
	 *
	 * @var DemoContent
	 */
	private DemoContent $demo;

	/**
	 * Constructor.
	 *
	 * @param Permissions $permissions Permission service.
	 * @param DemoContent $demo        Demo content service.
	 */
	public function __construct( Permissions $permissions, DemoContent $demo ) {
		parent::__construct( $permissions );

		$this->demo = $demo;
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
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run_step' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
					'args'                => array(
						'step' => array(
							'description'       => __( 'Zero-based import step.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/dismiss',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'dismiss' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	/**
	 * Describes the demo and what is installed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond( $this->demo->status() );
	}

	/**
	 * Runs one import step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_step( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->demo->run_step( (int) $request->get_param( 'step' ) );

		if ( is_wp_error( $result ) ) {
			return $this->fail(
				(string) $result->get_error_code(),
				(string) $result->get_error_message(),
				(int) ( $result->get_error_data()['status'] ?? 400 )
			);
		}

		return $this->respond( $result );
	}

	/**
	 * Removes everything the demo installed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond(
			array(
				'removed' => $this->demo->remove(),
				'status'  => $this->demo->status(),
			)
		);
	}

	/**
	 * Records that the operator does not want to be offered the demo again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$this->demo->dismiss();

		return $this->respond( $this->demo->status() );
	}
}
