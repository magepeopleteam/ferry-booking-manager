<?php
/**
 * Sailing repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Models\Booking;
use FBM\Models\Entity;
use FBM\Models\Sailing;
use FBM\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for sailings.
 * Departure filtering runs against the derived UTC timestamp rather than a
 * formatted string, so range queries stay numeric and index-friendly and behave
 * correctly for journeys that cross midnight.
 */
final class SailingRepository extends AbstractRepository {
	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return Sailing::class;
	}

	/**
	 * Returns an error when an entity must not be deleted.
	 *
	 * Concrete repositories override this to protect referential integrity —
	 * a port still used by a route, a sailing that already has bookings.
	 *
	 * @param Entity $entity Entity being deleted.
	 * @return WP_Error|null
	 */
	protected function deletion_blocker( Entity $entity ): ?WP_Error {
		$bookings = $this->count_references( Booking::POST_TYPE, '_fbm_sailing_id', $entity->id );

		if ( $bookings > 0 ) {
			return new WP_Error(
				'fbm_sailing_has_bookings',
				sprintf(
					/* translators: %d: number of bookings. */
					_n(
						'This sailing has %d booking and cannot be deleted. Cancel the sailing instead so passengers are notified.',
						'This sailing has %d bookings and cannot be deleted. Cancel the sailing instead so passengers are notified.',
						$bookings,
						'magepeople-ferry-booking-system'
					),
					$bookings
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Builds the meta query for a listing.
	 *
	 * @param array<string, mixed> $args Repository arguments.
	 * @return array<int|string, mixed>
	 */
	protected function build_meta_query( array $args ): array {
		$meta_query = parent::build_meta_query( $args );

		foreach ( array(
			'route_id'  => '_fbm_route_id',
			'vessel_id' => '_fbm_vessel_id',
		) as $arg => $meta_key ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => (int) $args[ $arg ],
					'compare' => '=',
				);
			}
		}

		$from = isset( $args['from'] ) ? (string) $args['from'] : '';
		$to   = isset( $args['to'] ) ? (string) $args['to'] : '';

		if ( '' !== $from ) {
			$meta_query[] = array(
				'key'     => '_fbm_departure_ts',
				'value'   => Time::local_to_timestamp( $from ),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		if ( '' !== $to ) {
			$meta_query[] = array(
				'key'     => '_fbm_departure_ts',
				'value'   => Time::local_to_timestamp( $to ),
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

		if ( ! empty( $args['date'] ) ) {
			$meta_query[] = array(
				'key'     => '_fbm_departure_date',
				'value'   => sanitize_text_field( (string) $args['date'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $args['statuses'] ) && is_array( $args['statuses'] ) ) {
			$meta_query[] = array(
				'key'     => '_fbm_status',
				'value'   => array_map( 'sanitize_key', $args['statuses'] ),
				'compare' => 'IN',
			);
		}

		return $meta_query;
	}

	/**
	 * Applies ordering to the query arguments.
	 *
	 * @param array<string, mixed> $query_args WP_Query arguments.
	 * @param string               $orderby    Requested order key.
	 * @param string               $order      Requested direction.
	 * @return array<string, mixed>
	 */
	protected function apply_order( array $query_args, string $orderby, string $order ): array {
		if ( '' === $orderby || 'title' === $orderby || 'departure' === $orderby ) {
			$orderby = 'departure_ts';
		}

		return parent::apply_order( $query_args, $orderby, $order );
	}

	/**
	 * Produces a fallback display name for entities saved without one.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return string
	 */
	protected function derive_name( Entity $entity ): string {
		$route     = get_the_title( (int) $entity->get( 'route_id' ) );
		$departure = (string) $entity->get( 'departure_datetime' );

		if ( '' !== $route && '' !== $departure ) {
			return sprintf( '%s · %s', $route, Time::display( $departure ) );
		}

		return parent::derive_name( $entity );
	}

	/**
	 * Detects a vessel double-booking.
	 *
	 * A vessel cannot be in two places at once, so an overlapping sailing on the
	 * same vessel is an operational error worth catching at save time rather
	 * than on the quayside.
	 *
	 * Two journeys overlap when each begins before the other ends. Testing only
	 * whether the other sailing *starts* inside this one's window misses the
	 * more common case: a crossing that departed earlier and is still at sea
	 * when the new one is due to leave.
	 *
	 * @param int    $vessel_id  Vessel id.
	 * @param string $departure  Local departure date/time.
	 * @param int    $duration   Journey duration in minutes.
	 * @param int    $exclude_id Sailing id to ignore, when updating.
	 * @return int[] Conflicting sailing ids.
	 */
	public function find_vessel_conflicts( int $vessel_id, string $departure, int $duration, int $exclude_id = 0 ): array {
		if ( $vessel_id < 1 || '' === $departure ) {
			return array();
		}

		$start = Time::local_to_timestamp( $departure );

		if ( 0 === $start ) {
			return array();
		}

		// A zero-length window still has to catch an exact-time collision.
		$end = $start + max( 1, max( 0, $duration ) * MINUTE_IN_SECONDS );

		$ids = $this->ids(
			array(
				'posts_per_page' => 50,
				'post__not_in'   => $exclude_id > 0 ? array( $exclude_id ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single sailing excluded from a schedule-conflict check, not a bulk exclusion.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed scalar keys, bounded result set.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_fbm_vessel_id',
						'value'   => $vessel_id,
						'compare' => '=',
					),
					array(
						'key'     => '_fbm_status',
						'value'   => array( Sailing::STATUS_CANCELLED ),
						'compare' => 'NOT IN',
					),
					array(
						'key'     => '_fbm_departure_ts',
						'value'   => $end,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_fbm_arrival_ts',
						'value'   => $start,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return $ids;
	}

	/**
	 * Loads sailings departing on a given local date.
	 *
	 * @param string $date Local date, "Y-m-d".
	 * @return Sailing[]
	 */
	public function on_date( string $date ): array {
		$result = $this->query(
			array(
				'per_page' => 100,
				'date'     => $date,
				'orderby'  => 'departure_ts',
				'order'    => 'asc',
			)
		);

		/** @var Sailing[] $items */
		$items = $result['items'];

		return $items;
	}
}
