<?php
/**
 * Health endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\REST\AbstractController;
use MPFBS\Security\Capabilities;
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
					'permission_callback' => static function () {
						return current_user_can( Capabilities::ACCESS_DASHBOARD );
					},
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
			'version'      => MPFBS_VERSION,
			'php'          => PHP_VERSION,
			'wp'           => get_bloginfo( 'version' ),
			'timezone'     => wp_timezone_string(),
			'locale'       => determine_locale(),
			'is_rtl'       => is_rtl(),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'capabilities' => $this->permissions->current_user_capabilities(),
			'server_time'  => gmdate( 'c' ),
		);

		/**
		 * Filters the payload returned by GET /mpfbs/v1/health.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Health payload.
		 */
		$payload = (array) apply_filters( 'mpfbs_rest_health_payload', $payload );

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
			'title'      => 'mpfbs_health',
			'type'       => 'object',
			'properties' => array(
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'Always "ok" when the plugin is running.', 'magepeople-ferry-booking-system' ),
					'readonly'    => true,
				),
				'version'      => array(
					'type'        => 'string',
					'description' => __( 'Plugin version.', 'magepeople-ferry-booking-system' ),
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
