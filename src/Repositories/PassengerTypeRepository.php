<?php
/**
 * Passenger type repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Models\Booking;
use FBM\Models\Entity;
use FBM\Models\PassengerType;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for passenger types.
 */
final class PassengerTypeRepository extends AbstractRepository {
	/**
	 * Meta key bookings use to index the passenger types they contain.
	 */
	public const BOOKING_INDEX = '_fbm_booking_passenger_type';

	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return PassengerType::class;
	}

	/**
	 * Returns every sellable passenger type, in display order.
	 *
	 * @return PassengerType[]
	 */
	public function active(): array {
		$result = $this->query(
			array(
				'per_page' => 100,
				'status'   => PassengerType::STATUS_ACTIVE,
				'orderby'  => 'sort_order',
				'order'    => 'asc',
			)
		);

		/** @var PassengerType[] $items */
		$items = $result['items'];

		return $items;
	}

	/**
	 * Returns the passenger type used as the reference fare.
	 *
	 * Percentage fares are meaningless without one, so this falls back to the
	 * first active type rather than returning null and forcing every caller to
	 * decide what a missing base means.
	 *
	 * @return PassengerType|null
	 */
	public function base_type(): ?PassengerType {
		$active = $this->active();

		foreach ( $active as $type ) {
			if ( (bool) $type->get( 'is_base' ) ) {
				return $type;
			}
		}

		return $active[0] ?? null;
	}

	/**
	 * Finds an active passenger type by its code.
	 *
	 * @param string $code Passenger type code.
	 * @return PassengerType|null
	 */
	public function find_by_code( string $code ): ?PassengerType {
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
	 * Persists a passenger type, keeping the base-fare flag unique.
	 *
	 * Percentage fares resolve against "the" base type, so two types carrying
	 * the flag would make every percentage fare depend on query order. The
	 * newest write wins and the others are cleared here rather than in the
	 * controller, so the invariant survives the importer and Pro as well.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param int                  $id         Existing id, or 0 to create.
	 * @param string               $name       Display name.
	 * @return Entity|WP_Error
	 */
	public function save( array $attributes, int $id = 0, string $name = '' ) {
		$entity = parent::save( $attributes, $id, $name );

		if ( ! is_wp_error( $entity ) && (bool) $entity->get( 'is_base' ) ) {
			$this->clear_other_base_flags( $entity->id );
		}

		return $entity;
	}

	/**
	 * Removes the base-fare flag from every type except one.
	 *
	 * @param int $keep_id Passenger type that keeps the flag.
	 * @return void
	 */
	private function clear_other_base_flags( int $keep_id ): void {
		$field = $this->schema()->field( 'is_base' );

		if ( null === $field ) {
			return;
		}

		$others = $this->ids(
			array(
				'post__not_in' => array( $keep_id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single record excluded on a small, fixed configuration set (passenger types).
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dedicated scalar key on a small configuration set.
					array(
						'key'     => $field->meta_key,
						'value'   => '1',
						'compare' => '=',
					),
				),
			),
			100
		);

		foreach ( $others as $other_id ) {
			update_post_meta( $other_id, $field->meta_key, $field->to_storage( false ) );
		}

		if ( array() !== $others ) {
			$this->flush();
		}
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
				'fbm_passenger_type_in_use',
				sprintf(
					/* translators: %d: number of bookings. */
					_n(
						'This passenger type is used by %d booking and cannot be deleted. Set it to inactive instead so it stops being sold.',
						'This passenger type is used by %d bookings and cannot be deleted. Set it to inactive instead so it stops being sold.',
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

		return '' !== $code ? ucfirst( strtolower( $code ) ) : __( 'Passenger type', 'magepeople-ferry-booking-system' );
	}
}
