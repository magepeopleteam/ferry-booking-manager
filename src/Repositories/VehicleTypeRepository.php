<?php
/**
 * Vehicle type repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Models\Booking;
use FBM\Models\Entity;
use FBM\Models\VehicleType;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for vehicle types.
 */
final class VehicleTypeRepository extends AbstractRepository {
	/**
	 * Meta key bookings use to index the vehicle types they contain.
	 */
	public const BOOKING_INDEX = '_fbm_booking_vehicle_type';

	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return VehicleType::class;
	}

	/**
	 * Returns every sellable vehicle type, in display order.
	 *
	 * @return VehicleType[]
	 */
	public function active(): array {
		$result = $this->query(
			array(
				'per_page' => 100,
				'status'   => VehicleType::STATUS_ACTIVE,
				'orderby'  => 'sort_order',
				'order'    => 'asc',
			)
		);

		/** @var VehicleType[] $items */
		$items = $result['items'];

		return $items;
	}

	/**
	 * Finds an active vehicle type by its code.
	 *
	 * @param string $code Vehicle type code.
	 * @return VehicleType|null
	 */
	public function find_by_code( string $code ): ?VehicleType {
		$code = strtoupper( trim( $code ) );

		if ( '' === $code ) {
			return null;
		}

		foreach ( $this->active() as $type ) {
			if ( strtoupper( $type->code() ) === $code ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Returns an error when an entity must not be deleted.
	 *
	 * @param Entity $entity Entity being deleted.
	 * @return WP_Error|null
	 */
	protected function deletion_blocker( Entity $entity ): ?WP_Error {
		$references = $this->count_references( Booking::POST_TYPE, self::BOOKING_INDEX, $entity->id );

		if ( $references > 0 ) {
			return new WP_Error(
				'fbm_vehicle_type_in_use',
				sprintf(
					/* translators: %d: number of bookings. */
					_n(
						'This vehicle type is used by %d booking and cannot be deleted. Set it to inactive instead so it stops being sold.',
						'This vehicle type is used by %d bookings and cannot be deleted. Set it to inactive instead so it stops being sold.',
						$references,
						'magepeople-ferry-booking-system'
					),
					$references
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Produces a fallback display name for entities saved without one.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return string
	 */
	protected function derive_name( Entity $entity ): string {
		$code = (string) $entity->get( 'code' );

		return '' !== $code ? ucfirst( strtolower( $code ) ) : __( 'Vehicle type', 'magepeople-ferry-booking-system' );
	}
}
