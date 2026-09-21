<?php
/**
 * Availability snapshot.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * What is left on one sailing, at one moment.
 *
 * A snapshot is a value: it is computed once, answers questions without going
 * back to the database, and is safe to cache. Every number is expressed the same
 * way — capacity, sold, held, remaining — so a caller never has to know which
 * measure it is looking at.
 */
final class Availability {

	/**
	 * Passenger seats.
	 */
	public const PASSENGERS = 'passengers';

	/**
	 * Vehicle slots.
	 */
	public const VEHICLES = 'vehicles';

	/**
	 * Vehicle deck length.
	 */
	public const LANE_METRES = 'lane_metres';

	/**
	 * Sailing this snapshot describes.
	 *
	 * @var int
	 */
	public int $sailing_id;

	/**
	 * UTC timestamp the snapshot was taken at.
	 *
	 * @var int
	 */
	public int $as_of;

	/**
	 * Whether the sailing is open for new bookings at all.
	 *
	 * @var bool
	 */
	public bool $bookable = false;

	/**
	 * Machine-readable reason the sailing is closed, if it is.
	 *
	 * @var string
	 */
	public string $reason = '';

	/**
	 * Buckets keyed by measure.
	 *
	 * Each holds capacity, sold, held, remaining and unlimited.
	 *
	 * @var array<string, array<string, float|int|bool>>
	 */
	private array $buckets = array();

	/**
	 * Constructor.
	 *
	 * @param int $sailing_id Sailing id.
	 * @param int $as_of      UTC timestamp.
	 */
	public function __construct( int $sailing_id, int $as_of ) {
		$this->sailing_id = $sailing_id;
		$this->as_of      = $as_of;
	}

	/**
	 * Records a measure.
	 *
	 * A capacity below zero means the measure is not limited on this sailing —
	 * a passenger-only crossing does not cap lane metres, it simply has none to
	 * sell, and those are different answers.
	 *
	 * @param string    $measure   One of the class constants.
	 * @param float|int $capacity  Total capacity, or a negative number for unlimited.
	 * @param float|int $sold      Capacity held by confirmed and pending bookings.
	 * @param float|int $held      Capacity held by unexpired temporary holds.
	 * @return void
	 */
	public function set( string $measure, $capacity, $sold, $held ): void {
		$unlimited = $capacity < 0;
		$used      = $sold + $held;

		$this->buckets[ $measure ] = array(
			'capacity'  => $unlimited ? -1 : $capacity,
			'sold'      => $sold,
			'held'      => $held,
			'used'      => $used,
			'remaining' => $unlimited ? -1 : max( 0, $capacity - $used ),
			'unlimited' => $unlimited,
		);
	}

	/**
	 * Returns the remaining amount of a measure.
	 *
	 * @param string $measure Measure name.
	 * @return float|int Remaining amount; -1 when the measure is unlimited.
	 */
	public function remaining( string $measure ) {
		return $this->buckets[ $measure ]['remaining'] ?? -1;
	}

	/**
	 * Determines whether a measure can absorb a requested amount.
	 *
	 * @param string    $measure Measure name.
	 * @param float|int $amount  Amount requested.
	 * @return bool
	 */
	public function fits( string $measure, $amount ): bool {
		if ( $amount <= 0 ) {
			return true;
		}

		$remaining = $this->remaining( $measure );

		if ( $remaining < 0 ) {
			return true;
		}

		// Lane metres are fractional, so a strict comparison would refuse a
		// booking that fits by a rounding error of a millimetre.
		return $amount <= $remaining + 0.0001;
	}

	/**
	 * Returns one bucket.
	 *
	 * @param string $measure Measure name.
	 * @return array<string, float|int|bool>
	 */
	public function bucket( string $measure ): array {
		return $this->buckets[ $measure ] ?? array();
	}

	/**
	 * Determines whether the sailing has nothing left to sell.
	 *
	 * @return bool
	 */
	public function is_sold_out(): bool {
		$passengers = $this->remaining( self::PASSENGERS );

		return $passengers >= 0 && $passengers <= 0;
	}

	/**
	 * Serialises the snapshot for an API response.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$payload = array(
			'sailing_id' => $this->sailing_id,
			'as_of'      => $this->as_of,
			'bookable'   => $this->bookable,
			'reason'     => $this->reason,
			'sold_out'   => $this->is_sold_out(),
		);

		foreach ( $this->buckets as $measure => $bucket ) {
			$payload[ $measure ] = $bucket;
		}

		/**
		 * Filters a serialised availability snapshot.
		 *
		 * Extensions add measures of their own here, such as deck space.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload      Serialised snapshot.
		 * @param Availability         $availability Snapshot instance.
		 */
		return (array) apply_filters( 'mpfbs_serialize_availability', $payload, $this );
	}
}
