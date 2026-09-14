<?php
/**
 * Temporary capacity holds.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Availability;

use FBM\Contracts\LoggerInterface;
use FBM\Models\Booking;
use FBM\Models\Sailing;
use FBM\Repositories\BookingRepository;
use FBM\Repositories\SailingRepository;
use FBM\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reserves capacity while a customer pays for it.
 *
 * A hold is an ordinary booking in the `on_hold` status carrying an expiry
 * timestamp — not a row in a side table — so it is visible in the bookings list,
 * survives a backup and restore, and stops consuming capacity the moment it
 * lapses, whether or not the cleanup job has run.
 *
 * ## Why holds are verified after they are written
 *
 * Two customers can pass the same capacity check a microsecond apart and both
 * be told there is one seat left. Checking first and writing second cannot fix
 * that, and this plugin may not create the lock table that normally would.
 *
 * So the hold is written first and verified second. Every request recomputes
 * consumption counting only bookings whose id is at or below its own, which is
 * a total order every racing request agrees on. Each request then asks one
 * question: "given everyone who got here before me, is there still room for
 * me?" Exactly the bookings that fit survive, the rest roll themselves back,
 * and no two requests can both believe they took the last seat.
 */
final class HoldManager {

	/**
	 * Default minutes a hold survives without payment.
	 */
	public const DEFAULT_MINUTES = 15;

	/**
	 * Cron hook that releases lapsed holds.
	 */
	public const CLEANUP_HOOK = 'fbm_release_expired_holds';

	/**
	 * Custom cron schedule the cleanup runs on.
	 */
	public const CLEANUP_SCHEDULE = 'fbm_five_minutes';

	/**
	 * Maximum holds released in a single cleanup pass.
	 */
	private const CLEANUP_BATCH = 200;

	/**
	 * How many times verification waits for an in-flight sibling to finish.
	 */
	private const VERIFY_ATTEMPTS = 8;

	/**
	 * Microseconds between verification attempts.
	 */
	private const VERIFY_BACKOFF = 60000;

	/**
	 * Booking repository.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Availability engine.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param BookingRepository   $bookings     Booking repository.
	 * @param SailingRepository   $sailings     Sailing repository.
	 * @param AvailabilityService $availability Availability engine.
	 * @param LoggerInterface     $logger       Logger.
	 */
	public function __construct(
		BookingRepository $bookings,
		SailingRepository $sailings,
		AvailabilityService $availability,
		LoggerInterface $logger
	) {
		$this->bookings     = $bookings;
		$this->sailings     = $sailings;
		$this->availability = $availability;
		$this->logger       = $logger;
	}

	/**
	 * Attaches the cleanup schedule.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The sweep only tidies abandoned checkouts out of the bookings list; capacity is already correct without it, and staff should not be looking at holds that lapsed twenty minutes ago.
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ), 30 );
		add_action( self::CLEANUP_HOOK, array( $this, 'release_expired' ) );
	}

	/**
	 * Schedules the cleanup sweep if it is not already booked.
	 *
	 * This cannot happen in the activation hook: the custom interval is added
	 * through `cron_schedules`, and during activation the plugin's own filters
	 * have not been attached, so WordPress would reject the schedule name.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CLEANUP_SCHEDULE, self::CLEANUP_HOOK );
	}

	/**
	 * Adds the five-minute schedule used by hold cleanup.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::CLEANUP_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Ferry Booking Manager)', 'ferry-booking-manager' ),
		);

		return $schedules;
	}

	/**
	 * Returns how long a new hold lasts, in minutes.
	 *
	 * @return int
	 */
	public function hold_minutes(): int {
		// From the settings store, which is what the Booking tab writes. This
		// used to read a standalone option that nothing ever wrote, so the
		// configured hold time had no effect at all.
		$configured = Settings::all()['hold_minutes'] ?? null;
		$minutes    = null === $configured ? self::DEFAULT_MINUTES : (int) $configured;

		/**
		 * Filters how long a temporary hold survives without payment.
		 *
		 * @since 1.0.0
		 *
		 * @param int $minutes Hold duration in minutes.
		 */
		$minutes = (int) apply_filters( 'fbm_hold_minutes', $minutes );

		return max( 1, min( 240, $minutes ) );
	}

	/**
	 * Places a hold on a sailing's capacity.
	 *
	 * @param int                      $sailing_id Sailing to reserve on.
	 * @param array<string, float|int> $usage      Inventory requested per measure.
	 * @param array<string, mixed>     $attributes Extra booking attributes.
	 * @param string                   $key        Idempotency key; a repeat returns the same hold.
	 * @return Booking|WP_Error
	 */
	public function reserve( int $sailing_id, array $usage, array $attributes = array(), string $key = '' ) {
		$key = sanitize_text_field( $key );

		if ( '' !== $key ) {
			$existing = $this->find_by_key( $key );

			// A double-clicked Pay button, a retried request after a timeout,
			// and a customer refreshing the page all arrive here. None of them
			// should produce a second booking.
			if ( $existing instanceof Booking ) {
				return $existing;
			}
		}

		$sailing = $this->sailings->find( $sailing_id );

		if ( ! $sailing instanceof Sailing ) {
			return new WP_Error(
				'fbm_sailing_not_found',
				__( 'That sailing could not be found.', 'ferry-booking-manager' ),
				array( 'status' => 404 )
			);
		}

		$now   = time();
		$usage = $this->normalise_usage( $usage );

		$before = $this->availability->fresh( $sailing, $now );
		$check  = $this->availability->check( $before, $usage );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$booking = $this->write_hold( $sailing, $usage, $attributes, $key, $now );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$verdict = $this->verify( $sailing, $booking, $now );

		if ( is_wp_error( $verdict ) ) {
			$this->rollback( $booking );

			return $verdict;
		}

		$this->availability->invalidate( $sailing->id );

		/**
		 * Fires once a hold has been placed and verified.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking $booking Held booking.
		 * @param Sailing $sailing Sailing the hold is on.
		 */
		do_action( 'fbm_hold_created', $booking, $sailing );

		return $booking;
	}

	/**
	 * Pushes a hold's expiry further into the future.
	 *
	 * @param Booking  $booking Held booking.
	 * @param int|null $minutes Minutes from now, or null for the configured duration.
	 * @return Booking|WP_Error
	 */
	public function extend( Booking $booking, ?int $minutes = null ) {
		if ( Booking::STATUS_ON_HOLD !== $booking->get( 'booking_status' ) ) {
			return new WP_Error(
				'fbm_not_on_hold',
				__( 'That booking is not being held.', 'ferry-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		$minutes = null === $minutes ? $this->hold_minutes() : max( 1, min( 240, $minutes ) );

		return $this->bookings->save(
			array( 'hold_expires_ts' => time() + ( $minutes * MINUTE_IN_SECONDS ) ),
			$booking->id
		);
	}

	/**
	 * Converts a hold into a booking that keeps its capacity permanently.
	 *
	 * @param Booking $booking Held booking.
	 * @param string  $status  Target status.
	 * @return Booking|WP_Error
	 */
	public function confirm( Booking $booking, string $status = Booking::STATUS_CONFIRMED ) {
		if ( ! in_array( $status, Booking::CONSUMING_STATUSES, true ) ) {
			return new WP_Error(
				'fbm_invalid_status',
				__( 'A booking cannot be confirmed into that status.', 'ferry-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$expires = (int) $booking->get( 'hold_expires_ts' );

		// A hold that lapsed before payment landed may have had its seats sold
		// to somebody else in the meantime, so the capacity has to be proven
		// again rather than assumed.
		if ( Booking::STATUS_ON_HOLD === $booking->get( 'booking_status' ) && $expires > 0 && time() >= $expires ) {
			$sailing = $this->sailings->find( (int) $booking->get( 'sailing_id' ) );

			if ( $sailing instanceof Sailing ) {
				/*
				 * Judged against everyone currently holding capacity, not just
				 * those who arrived first. The arrival-order rule that settles a
				 * creation race does not apply here: this hold forfeited its
				 * place when it lapsed, so a booking made afterwards outranks it.
				 * Its own lapsed row is already excluded from the count.
				 */
				$current = $this->availability->fresh( $sailing, time() );
				$recheck = $this->availability->check( $current, $this->usage_of( $booking ) );

				if ( is_wp_error( $recheck ) ) {
					return new WP_Error(
						'fbm_hold_expired',
						__( 'This booking was held for too long and the space has since been taken. Please start again.', 'ferry-booking-manager' ),
						array(
							'status' => 409,
							'detail' => $recheck->get_error_message(),
						)
					);
				}
			}
		}

		$confirmed = $this->bookings->save(
			array(
				'booking_status'  => $status,
				'hold_expires_ts' => 0,
			),
			$booking->id
		);

		if ( ! is_wp_error( $confirmed ) ) {
			$this->availability->invalidate( (int) $booking->get( 'sailing_id' ) );

			/**
			 * Fires when a held booking becomes permanent.
			 *
			 * @since 1.0.0
			 *
			 * @param Booking $confirmed Confirmed booking.
			 */
			do_action( 'fbm_hold_confirmed', $confirmed );
		}

		return $confirmed;
	}

	/**
	 * Releases a hold so its capacity returns to the pool.
	 *
	 * @param Booking $booking Held booking.
	 * @param string  $reason  Why it was released, for the log.
	 * @return Booking|WP_Error
	 */
	public function release( Booking $booking, string $reason = 'released' ) {
		$released = $this->bookings->save(
			array(
				'booking_status'  => Booking::STATUS_CANCELLED,
				'payment_status'  => Booking::PAYMENT_CANCELLED,
				'hold_expires_ts' => 0,
			),
			$booking->id
		);

		if ( ! is_wp_error( $released ) ) {
			$this->availability->invalidate( (int) $booking->get( 'sailing_id' ) );

			$this->logger->info(
				'Released a capacity hold.',
				array(
					'booking' => $booking->id,
					'sailing' => (int) $booking->get( 'sailing_id' ),
					'reason'  => $reason,
				)
			);

			/**
			 * Fires when a hold is released without becoming a booking.
			 *
			 * @since 1.0.0
			 *
			 * @param Booking $released Released booking.
			 * @param string  $reason   Release reason.
			 */
			do_action( 'fbm_hold_released', $released, $reason );
		}

		return $released;
	}

	/**
	 * Cancels every hold whose expiry has passed.
	 *
	 * Expired holds already stop consuming capacity by computation; this keeps
	 * the bookings list honest rather than keeping the numbers correct.
	 *
	 * @return int Number of holds released.
	 */
	public function release_expired(): int {
		$now = time();

		$ids = $this->bookings->ids(
			array(
				'posts_per_page' => self::CLEANUP_BATCH,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dedicated scalar keys, bounded batch, runs on cron.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_fbm_booking_status',
						'value'   => Booking::STATUS_ON_HOLD,
						'compare' => '=',
					),
					array(
						'key'     => '_fbm_hold_expires_ts',
						'value'   => $now,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_fbm_hold_expires_ts',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			),
			self::CLEANUP_BATCH
		);

		$released = 0;

		foreach ( $this->bookings->find_many( $ids ) as $booking ) {
			if ( $booking instanceof Booking && ! is_wp_error( $this->release( $booking, 'expired' ) ) ) {
				++$released;
			}
		}

		return $released;
	}

	/**
	 * Finds a booking previously created with an idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return Booking|null
	 */
	public function find_by_key( string $key ): ?Booking {
		if ( '' === $key ) {
			return null;
		}

		$ids = $this->bookings->ids(
			array(
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single indexed key lookup.
				'meta_query'     => array(
					array(
						'key'     => '_fbm_idempotency_key',
						'value'   => $key,
						'compare' => '=',
					),
				),
			),
			1
		);

		if ( array() === $ids ) {
			return null;
		}

		$booking = $this->bookings->find( (int) $ids[0] );

		return $booking instanceof Booking ? $booking : null;
	}

	/**
	 * Returns the inventory a booking currently consumes.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array<string, float|int>
	 */
	public function usage_of( Booking $booking ): array {
		return array(
			Availability::PASSENGERS  => (int) $booking->get( 'passenger_count' ),
			Availability::VEHICLES    => (int) $booking->get( 'vehicle_count' ),
			Availability::LANE_METRES => (float) $booking->get( 'lane_metres' ),
		);
	}

	/**
	 * Creates the held booking record.
	 *
	 * @param Sailing                  $sailing    Sailing entity.
	 * @param array<string, float|int> $usage      Inventory requested.
	 * @param array<string, mixed>     $attributes Extra booking attributes.
	 * @param string                   $key        Idempotency key.
	 * @param int                      $now        UTC timestamp.
	 * @return Booking|WP_Error
	 */
	private function write_hold( Sailing $sailing, array $usage, array $attributes, string $key, int $now ) {
		$attributes = array_merge(
			$attributes,
			array(
				'sailing_id'      => $sailing->id,
				'passenger_count' => (int) $usage[ Availability::PASSENGERS ],
				'vehicle_count'   => (int) $usage[ Availability::VEHICLES ],
				'lane_metres'     => (float) $usage[ Availability::LANE_METRES ],
				'booking_status'  => Booking::STATUS_ON_HOLD,
				'hold_expires_ts' => $now + ( $this->hold_minutes() * MINUTE_IN_SECONDS ),
				'idempotency_key' => $key,
			)
		);

		$booking = $this->bookings->save( $attributes );

		if ( $booking instanceof Booking ) {
			// Written last, and only once every other row is in place, so a
			// concurrent request can tell a finished booking from one that is
			// still being written.
			$this->bookings->mark_complete( $booking->id );
		}

		return $booking;
	}

	/**
	 * Confirms the hold still fits once it is in the database.
	 *
	 * @param Sailing $sailing Sailing entity.
	 * @param Booking $booking Newly written hold.
	 * @param int     $now     UTC timestamp.
	 * @return true|WP_Error
	 */
	private function verify( Sailing $sailing, Booking $booking, int $now ) {
		/*
		 * Counting only bookings up to and including this one is what makes the
		 * outcome deterministic: every concurrent request evaluates the same
		 * prefix of the same ordered list, so they cannot all conclude they were
		 * the one that fitted.
		 *
		 * That only holds once every earlier booking is fully written, though —
		 * a sibling caught mid-write looks like it consumes nothing, and two
		 * requests that each miss the other would both believe they fitted. So
		 * the count waits for them, and refuses rather than guessing if they
		 * never finish.
		 */
		$settled = $this->await_earlier_writes( $booking->id, $now );

		if ( ! $settled ) {
			return new WP_Error(
				'fbm_capacity_busy',
				__( 'This sailing is being booked by several people at once. Please try again in a moment.', 'ferry-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		$after = $this->availability->fresh( $sailing, $now, $booking->id );
		$mine  = $this->availability->usage_of( $booking, $sailing->id );

		foreach ( $this->availability->measures() as $measure ) {
			$bucket = $after->bucket( $measure );

			if ( array() === $bucket || true === $bucket['unlimited'] ) {
				continue;
			}

			/*
			 * Only a measure this booking actually consumes can have been
			 * oversold by it. A measure that is over for another reason — an
			 * operator reducing a cabin count or a deck length after the fact —
			 * is a real problem, but it is not this customer's, and refusing
			 * every subsequent booking on the sailing would turn one
			 * configuration change into an outage.
			 */
			if ( ( $mine[ $measure ] ?? 0 ) <= 0 ) {
				continue;
			}

			if ( $bucket['used'] > $bucket['capacity'] + 0.0001 ) {
				$this->logger->info(
					'Rolled back a hold that lost a capacity race.',
					array(
						'booking'  => $booking->id,
						'sailing'  => $sailing->id,
						'measure'  => $measure,
						'used'     => $bucket['used'],
						'capacity' => $bucket['capacity'],
					)
				);

				return new WP_Error(
					'fbm_capacity_taken',
					__( 'Someone else booked that space while you were checking out. Please choose again.', 'ferry-booking-manager' ),
					array(
						'status'  => 409,
						'measure' => $measure,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Waits until every booking created before this one has finished writing.
	 *
	 * @param int $booking_id Booking doing the waiting.
	 * @param int $now        UTC timestamp.
	 * @return bool False when a sibling never finished.
	 */
	private function await_earlier_writes( int $booking_id, int $now ): bool {
		for ( $attempt = 0; $attempt < self::VERIFY_ATTEMPTS; $attempt++ ) {
			$pending = $this->bookings->incomplete_before( $booking_id, $now );

			if ( array() === $pending ) {
				return true;
			}

			usleep( self::VERIFY_BACKOFF );
		}

		return false;
	}

	/**
	 * Removes a hold that never should have existed.
	 *
	 * @param Booking $booking Booking to remove.
	 * @return void
	 */
	private function rollback( Booking $booking ): void {
		$this->bookings->delete( $booking->id, true );
		$this->availability->invalidate( (int) $booking->get( 'sailing_id' ) );
	}

	/**
	 * Fills in every measure the engine tracks.
	 *
	 * @param array<string, float|int> $usage Requested inventory.
	 * @return array<string, float|int>
	 */
	private function normalise_usage( array $usage ): array {
		$normalised = array();

		foreach ( $this->availability->measures() as $measure ) {
			$value = isset( $usage[ $measure ] ) ? $usage[ $measure ] : 0;

			$normalised[ $measure ] = Availability::LANE_METRES === $measure
				? max( 0.0, (float) $value )
				: max( 0, (int) $value );
		}

		return $normalised;
	}
}
