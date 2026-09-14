<?php
/**
 * Health endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\REST\AbstractController;
use FBM\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Reports plugin runtime state to the admin application.
 * The admin shell calls this once on boot to confirm connectivity, verify the
 * signed-in user's capabilities and discover which optional integrations are
 * present. It never exposes secrets or configuration values.
 */
final class HealthController extends AbstractController {
	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'health';

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
					'callback'            => array( $this, 'get_health' ),
					'permission_callback' => $this->can( Capabilities::ACCESS_DASHBOARD ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Returns the health payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$payload = array(
			'status'       => 'ok',
			'version'      => FBM_VERSION,
			'php'          => PHP_VERSION,
			'wp'           => get_bloginfo( 'version' ),
			'timezone'     => wp_timezone_string(),
			'locale'       => determine_locale(),
			'is_rtl'       => is_rtl(),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'pro_active'   => (bool) apply_filters( 'fbm_pro_active', false ),
			'capabilities' => $this->permissions->current_user_capabilities(),
			'server_time'  => gmdate( 'c' ),
		);

		/**
		 * Filters the payload returned by GET /fbm/v1/health.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Health payload.
		 */
		$payload = (array) apply_filters( 'fbm_rest_health_payload', $payload );

		return $this->respond( $payload );
	}

	/**
	 * Returns the JSON schema for the health resource.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fbm_health',
			'type'       => 'object',
			'properties' => array(
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'Always "ok" when the plugin is running.', 'ferry-booking-manager' ),
					'readonly'    => true,
				),
				'version'      => array(
					'type'        => 'string',
					'description' => __( 'Plugin version.', 'ferry-booking-manager' ),
					'readonly'    => true,
				),
				'php'          => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'wp'           => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'timezone'     => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'locale'       => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'is_rtl'       => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'woocommerce'  => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'pro_active'   => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'capabilities' => array(
					'type'     => 'object',
					'readonly' => true,
				),
				'server_time'  => array(
					'type'     => 'string',
					'readonly' => true,
				),
			),
		);
	}
}
