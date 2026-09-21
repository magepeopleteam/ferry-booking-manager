<?php
/**
 * Dashboard endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Dashboard\MetricsService;
use MPFBS\REST\AbstractController;
use MPFBS\Security\Capabilities;
use MPFBS\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the operational figures the dashboard opens with.
 */
final class DashboardController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'dashboard';

	/**
	 * Metrics service.
	 *
	 * @var MetricsService
	 */
	private MetricsService $metrics;

	/**
	 * Constructor.
	 *
	 * @param Permissions    $permissions Permission service.
	 * @param MetricsService $metrics     Metrics service.
	 */
	public function __construct( Permissions $permissions, MetricsService $metrics ) {
		parent::__construct( $permissions );

		$this->metrics = $metrics;
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
					'callback'            => array( $this, 'get_snapshot' ),
					'permission_callback' => $this->can( Capabilities::ACCESS_DASHBOARD ),
					'args'                => array(
						'date' => array(
							'description'       => __( 'Operating day, YYYY-MM-DD. Defaults to today.', 'magepeople-ferry-booking-system' ),
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
	 * Returns the dashboard snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_snapshot( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( $this->metrics->snapshot( (string) $request->get_param( 'date' ) ) );
	}
}
