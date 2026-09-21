<?php
/**
 * Passenger types endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Models\PassengerType;
use MPFBS\REST\EntityController;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for passenger types.
 */
final class PassengerTypeController extends EntityController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'passenger-types';

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

		$min = isset( $attributes['min_age'] ) ? (int) $attributes['min_age'] : PassengerType::NO_AGE_LIMIT;
		$max = isset( $attributes['max_age'] ) ? (int) $attributes['max_age'] : PassengerType::NO_AGE_LIMIT;

		if ( PassengerType::NO_AGE_LIMIT !== $min && PassengerType::NO_AGE_LIMIT !== $max && $max < $min ) {
			$fields['max_age'] = __( 'The maximum age must be the same as or above the minimum age.', 'magepeople-ferry-booking-system' );
		}

		$min_per = isset( $attributes['min_per_booking'] ) ? (int) $attributes['min_per_booking'] : 0;
		$max_per = isset( $attributes['max_per_booking'] ) ? (int) $attributes['max_per_booking'] : 0;

		if ( $max_per > 0 && $min_per > $max_per ) {
			$fields['min_per_booking'] = __( 'The minimum per booking cannot be above the maximum.', 'magepeople-ferry-booking-system' );
		}

		$mode = isset( $attributes['price_mode'] ) ? (string) $attributes['price_mode'] : PassengerType::PRICE_FIXED;

		if ( PassengerType::PRICE_PERCENT === $mode && ! empty( $attributes['is_base'] ) ) {
			$fields['price_mode'] = __( 'The base passenger type sets the fare that percentages are taken from, so it cannot itself be a percentage.', 'magepeople-ferry-booking-system' );
		}

		if ( array() !== $fields ) {
			return new WP_Error(
				'mpfbs_validation_failed',
				__( 'Please correct the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => $fields,
				)
			);
		}

		return true;
	}
}
