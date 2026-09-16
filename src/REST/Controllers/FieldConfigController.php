<?php
/**
 * Capture field configuration endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\Booking\FieldConfig;
use FBM\REST\AbstractController;
use FBM\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes which details a booking collects.
 */
final class FieldConfigController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'field-config';

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<group>passenger|vehicle)',
			array(
				'args' => array(
					'group' => array(
						'description'       => __( 'Field group.', 'magepeople-ferry-booking-system' ),
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( FieldConfig::GROUP_PASSENGER, FieldConfig::GROUP_VEHICLE ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => 'PUT, PATCH, POST',
					'callback'            => array( $this, 'save_config' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
					'args'                => array(
						'modes'  => array(
							'description' => __( 'Field key to mode map.', 'magepeople-ferry-booking-system' ),
							'type'        => 'object',
						),
						'custom' => array(
							'description' => __( 'Operator-defined extra fields.', 'magepeople-ferry-booking-system' ),
							'type'        => 'array',
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the resolved form definition for a group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( $this->payload( (string) $request->get_param( 'group' ) ) );
	}

	/**
	 * Stores the field modes and custom fields for a group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_config( WP_REST_Request $request ): WP_REST_Response {
		$group = (string) $request->get_param( 'group' );
		$modes = $request->get_param( 'modes' );

		if ( is_array( $modes ) ) {
			FieldConfig::save_modes( $group, $modes );
		}

		$custom = $request->get_param( 'custom' );

		if ( is_array( $custom ) ) {
			FieldConfig::save_custom_fields( $group, $custom );
		}

		return $this->respond( $this->payload( $group ) );
	}

	/**
	 * Builds the response body for a group.
	 *
	 * @param string $group Field group.
	 * @return array<string, mixed>
	 */
	private function payload( string $group ): array {
		return array(
			'group'  => $group,
			'modes'  => FieldConfig::modes(),
			'fields' => FieldConfig::form( $group ),
		);
	}
}
