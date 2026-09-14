<?php
/**
 * Vehicle types endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\REST\EntityController;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for vehicle types.
 */
final class VehicleTypeController extends EntityController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'vehicle-types';

	/**
	 * Field the collection is sorted by when the client asks for nothing.
	 *
	 * @var string
	 */
	protected string $default_orderby = 'sort_order';

	/**
	 * Direction applied alongside the default sort field.
	 *
	 * @var string
	 */
	protected string $default_order = 'asc';

	/**
	 * Applies domain rules that span more than one field.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param int                  $id         Existing id, or 0 when creating.
	 * @return true|WP_Error
	 */
	protected function guard( array $attributes, int $id ) {
		unset( $id );

		$fields = array();

		$units = isset( $attributes['capacity_units'] ) ? (int) $attributes['capacity_units'] : 1;
		$lane  = isset( $attributes['lane_metres'] ) ? (float) $attributes['lane_metres'] : 0.0;
		$len   = isset( $attributes['length'] ) ? (float) $attributes['length'] : 0.0;

		// A type that consumes neither a vehicle slot nor deck space is
		// unlimited on every sailing. That is almost never intended, and the
		// failure only shows up as an overloaded deck on sailing day.
		if ( 0 === $units && $lane <= 0.0 && $len <= 0.0 ) {
			$fields['capacity_units'] = __( 'Set a vehicle slot count, a length or the lane metres, otherwise this type consumes no deck space and can be sold without limit.', 'ferry-booking-manager' );
		}

		if ( isset( $attributes['price_per_metre'] ) && (int) $attributes['price_per_metre'] > 0 && $lane <= 0.0 && $len <= 0.0 ) {
			$fields['price_per_metre'] = __( 'A per-metre fare needs a length or lane metres to multiply by.', 'ferry-booking-manager' );
		}

		if ( array() !== $fields ) {
			return new WP_Error(
				'fbm_validation_failed',
				__( 'Please correct the highlighted fields.', 'ferry-booking-manager' ),
				array(
					'status' => 400,
					'fields' => $fields,
				)
			);
		}

		return true;
	}
}
