<?php
/**
 * Booking repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Models\Booking;
use FBM\Models\Entity;
use FBM\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for bookings.
 */
final class BookingRepository extends AbstractRepository {
	/**
	 * Repeated meta key listing every sailing a booking travels on.
	 * A return booking touches two sailings; indexing both means capacity for a
	 * sailing can be counted with one equality query instead of two.
	 */
	public const SAILING_INDEX = '_fbm_booking_sailing';

	/**
	 * Meta key written last, marking a booking as fully persisted.
	 *
	 * A booking is a post row plus a dozen meta rows, and they are not written
	 * in one atomic step. Between the two, the booking exists but looks like it
	 * consumes nothing — which is exactly long enough for a concurrent request
	 * to miss it and oversell the sailing. This marker is the last thing
	 * written, so its presence means every other row is already there.
	 */
	public const COMPLETE_FLAG = '_fbm_write_complete';

	/**
	 * Seconds after which an unmarked booking is treated as abandoned.
	 */
	private const WRITE_GRACE = 30;

	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return Booking::class;
	}

	/**
	 * Returns repeated meta rows that make list-valued properties queryable.
	 *
	 * A serialised array cannot be searched with an indexed query, so every
	 * relationship the system needs to look up in reverse — "which routes serve
	 * this port?" — is mirrored into repeated scalar meta rows alongside the
	 * authored list.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return array<string, array<int|string>> Meta key => values.
	 */
	protected function index_meta( Entity $entity ): array {
		$sailings = array(
			(int) $entity->get( 'sailing_id' ),
			(int) $entity->get( 'return_sailing_id' ),
		);

		return array(
			self::SAILING_INDEX => array_values( array_filter( $sailings ) ),
		);
	}

	/**
	 * Builds the meta query for a listing.
	 *
	 * @param array<string, mixed> $args Repository arguments.
	 * @return array<int|string, mixed>
	 */
	protected function build_meta_query( array $args ): array {
		$meta_query = array();

		$scalars = array(
			'sailing_id'     => '_fbm_sailing_id',
			'customer_id'    => '_fbm_customer_id',
			'agent_id'       => '_fbm_agent_id',
			'wc_order_id'    => '_fbm_wc_order_id',
			'booking_status' => '_fbm_booking_status',
			'payment_status' => '_fbm_payment_status',
			'channel'        => '_fbm_channel',
		);

		foreach ( $scalars as $arg => $meta_key ) {
			if ( isset( $args[ $arg ] ) && '' !== $args[ $arg ] && 0 !== $args[ $arg ] ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => is_numeric( $args[ $arg ] ) ? (int) $args[ $arg ] : sanitize_key( (string) $args[ $arg ] ),
					'compare' => '=',
				);
			}
		}

		if ( ! empty( $args['travels_on'] ) ) {
			$meta_query[] = array(
				'key'     => self::SAILING_INDEX,
				'value'   => (int) $args['travels_on'],
				'compare' => '=',
			);
		}

		if ( ! empty( $args['booking_statuses'] ) && is_array( $args['booking_statuses'] ) ) {
			$meta_query[] = array(
				'key'     => '_fbm_booking_status',
				'value'   => array_map( 'sanitize_key', $args['booking_statuses'] ),
				'compare' => 'IN',
			);
		}

		foreach ( array(
			'from' => '>=',
			'to'   => '<=',
		) as $arg => $compare ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$meta_query[] = array(
					'key'     => '_fbm_departure_ts',
					'value'   => Time::local_to_timestamp( (string) $args[ $arg ] ),
					'compare' => $compare,
					'type'    => 'NUMERIC',
				);
			}
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
		if ( '' === $orderby || 'title' === $orderby ) {
			$orderby = 'created';
			$order   = '' === $order ? 'desc' : $order;
		}

		return parent::apply_order( $query_args, $orderby, $order );
	}

	/**
	 * Loads every booking that could be holding inventory on a sailing.
	 *
	 * Returns bookings in any capacity-consuming status; expired holds are
	 * filtered by the availability engine, which owns that rule.
	 *
	 * @param int $sailing_id Sailing id.
	 * @return Booking[]
	 */
	public function consuming_for_sailing( int $sailing_id ): array {
		if ( $sailing_id < 1 ) {
			return array();
		}

		$ids = $this->ids(
			array(
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Ids only, bounded, and cached by the availability engine.
				'posts_per_page' => 2000,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed scalar keys; bounded and cached by the availability engine.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::SAILING_INDEX,
						'value'   => $sailing_id,
						'compare' => '=',
					),
					array(
						'key'     => '_fbm_booking_status',
						'value'   => Booking::CONSUMING_STATUSES,
						'compare' => 'IN',
					),
				),
			)
		);

		/** @var Booking[] $bookings */
		$bookings = array_values( $this->find_many( $ids ) );

		return $bookings;
	}

	/**
	 * Marks a booking as completely written.
	 *
	 * @param int $id Booking id.
	 * @return void
	 */
	public function mark_complete( int $id ): void {
		update_post_meta( $id, self::COMPLETE_FLAG, '1' );
	}

	/**
	 * Lists bookings created just now that are still being written.
	 *
	 * Only bookings below a given id matter to a caller checking capacity:
	 * post ids are handed out at insert time, so anything with a lower id
	 * started before the caller did and has the earlier claim on the space.
	 *
	 * @param int $below_id Only consider bookings with a lower id.
	 * @param int $now      UTC timestamp.
	 * @return int[] Ids still mid-write.
	 */
	public function incomplete_before( int $below_id, int $now ): array {
		$recent = $this->ids(
			array(
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'date_query'     => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', $now - self::WRITE_GRACE ),
						'inclusive' => true,
					),
				),
			),
			100
		);

		$pending = array();

		foreach ( $recent as $id ) {
			if ( $id >= $below_id ) {
				continue;
			}

			// The meta cache can hold a miss recorded a moment ago, and the
			// whole point of asking again is that the value may have landed
			// since.
			wp_cache_delete( $id, 'post_meta' );

			if ( '1' !== (string) get_post_meta( $id, self::COMPLETE_FLAG, true ) ) {
				$pending[] = $id;
			}
		}

		return $pending;
	}

	/**
	 * Returns the bookings belonging to one customer, latest departure first.
	 *
	 * Matched on the account id and on the account's email address, because a
	 * customer who booked as a guest and registered afterwards still considers
	 * those their bookings — and would otherwise be told they have none. The
	 * email side is only ever the signed-in user's own address, taken from their
	 * account rather than from the request, so it cannot be used to ask for
	 * somebody else's history.
	 *
	 * @param int    $user_id Account id.
	 * @param string $email   Account email address.
	 * @param int    $limit   Largest number of bookings to return.
	 * @return Booking[]
	 */
	public function for_customer( int $user_id, string $email, int $limit = 50 ): array {
		$clauses = array( 'relation' => 'OR' );

		if ( $user_id > 0 ) {
			$clauses[] = array(
				'key'     => '_fbm_customer_id',
				'value'   => $user_id,
				'compare' => '=',
			);
		}

		if ( '' !== $email ) {
			$clauses[] = array(
				'key'     => '_fbm_customer_email',
				'value'   => $email,
				'compare' => '=',
			);
		}

		// Neither identifier: there is no query to run, and certainly not one
		// with an empty OR clause that would match every booking on the site.
		if ( 1 === count( $clauses ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'              => $this->post_type(),
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => max( 1, min( 200, $limit ) ),
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'meta_value_num',
				'order'                  => 'DESC',
				'meta_key'               => '_fbm_departure_ts', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Ordering by departure needs the key, and the result set is one customer's own bookings.
				'meta_query'             => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Two dedicated scalar keys, bounded by the limit above.
			)
		);

		$bookings = array();

		foreach ( (array) $ids as $id ) {
			$booking = $this->find( (int) $id );

			if ( $booking instanceof Booking ) {
				$bookings[] = $booking;
			}
		}

		return $bookings;
	}

	/**
	 * Finds a booking by its public reference.
	 *
	 * @param string $number Booking reference.
	 * @return Booking|null
	 */
	public function find_by_number( string $number ): ?Booking {
		$number = sanitize_text_field( $number );

		if ( '' === $number ) {
			return null;
		}

		$ids = $this->ids(
			array(
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Unique indexed reference lookup.
				'meta_query'     => array(
					array(
						'key'     => '_fbm_booking_number',
						'value'   => $number,
						'compare' => '=',
					),
				),
			)
		);

		if ( array() === $ids ) {
			return null;
		}

		/** @var Booking|null $booking */
		$booking = $this->find( (int) $ids[0] );

		return $booking;
	}

	/**
	 * Determines whether a booking reference is already taken.
	 *
	 * @param string $number Candidate reference.
	 * @return bool
	 */
	public function number_exists( string $number ): bool {
		return null !== $this->find_by_number( $number );
	}
}
