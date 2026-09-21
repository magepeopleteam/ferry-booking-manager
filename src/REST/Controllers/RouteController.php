<?php
/**
 * Routes endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Models\Port;
use MPFBS\REST\EntityController;
use MPFBS\Models\Entity;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for routes.
 */
final class RouteController extends EntityController {
	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'routes';

	/**
	 * Applies domain rules that span more than one field.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param int                  $id         Existing id, or 0 when creating.
	 * @return true|WP_Error
	 */
	protected function guard( array $attributes, int $id ) {
		unset( $id );

		$origin      = isset( $attributes['origin_port'] ) ? (int) $attributes['origin_port'] : 0;
		$destination = isset( $attributes['destination_port'] ) ? (int) $attributes['destination_port'] : 0;

		if ( $origin > 0 && $origin === $destination ) {
			return new WP_Error(
				'mpfbs_invalid_route',
				__( 'A route must start and finish at different ports.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 422,
					'fields' => array(
						'destination_port' => __( 'Choose a different destination port.', 'magepeople-ferry-booking-system' ),
					),
				)
			);
		}

		$intermediate = isset( $attributes['intermediate_ports'] ) ? array_map( 'intval', (array) $attributes['intermediate_ports'] ) : array();

		if ( in_array( $origin, $intermediate, true ) || in_array( $destination, $intermediate, true ) ) {
			return new WP_Error(
				'mpfbs_invalid_route',
				__( 'A port cannot be both a terminus and an intermediate call on the same route.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 422,
					'fields' => array(
						'intermediate_ports' => __( 'Remove the origin and destination from the intermediate calls.', 'magepeople-ferry-booking-system' ),
					),
				)
			);
		}

		foreach ( $intermediate as $port_id ) {
			if ( get_post_type( $port_id ) !== Port::POST_TYPE ) {
				return new WP_Error(
					'mpfbs_invalid_route',
					__( 'One of the intermediate calls does not refer to an existing port.', 'magepeople-ferry-booking-system' ),
					array(
						'status' => 422,
						'fields' => array( 'intermediate_ports' => __( 'Unknown port.', 'magepeople-ferry-booking-system' ) ),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Returns repository arguments derived from controller-specific filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	protected function extra_query_args( WP_REST_Request $request ): array {
		return array(
			'origin_port'      => (int) $request->get_param( 'origin_port' ),
			'destination_port' => (int) $request->get_param( 'destination_port' ),
			'serves_port'      => (int) $request->get_param( 'serves_port' ),
		);
	}

	/**
	 * Returns the collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_collection_params(): array {
		$params = parent::get_collection_params();

		foreach ( array( 'origin_port', 'destination_port', 'serves_port' ) as $param ) {
			$params[ $param ] = array(
				'description'       => __( 'Filter by port id.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			);
		}

		return $params;
	}

	/**
	 * Serialises an entity for a response.
	 *
	 * Routes are always displayed with their terminals, so the port names are
	 * embedded here rather than forcing the interface into a second request per
	 * row.
	 *
	 * @param Entity $entity Entity.
	 * @return array<string, mixed>
	 */
	protected function prepare_item( Entity $entity ): array {
		$payload = parent::prepare_item( $entity );

		$payload['origin_port_name']      = get_the_title( (int) $entity->get( 'origin_port' ) );
		$payload['destination_port_name'] = get_the_title( (int) $entity->get( 'destination_port' ) );
		$payload['default_vessel_name']   = get_the_title( (int) $entity->get( 'default_vessel' ) );

		return $payload;
	}
}
