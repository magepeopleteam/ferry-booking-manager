<?php
/**
 * Booking entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Models;

use MPFBS\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A sold journey: who travels, on which sailing, for how much, and paid how.
 * Every property a listing filters, sorts or reports on has its own scalar meta
 * key. Passenger and vehicle collections are the only structured values, and
 * they are never queried — they are read once a booking is already identified.
 */
final class Booking extends Entity {
	public const POST_TYPE = 'mpfbs_booking';

	public const STATUS_PENDING = 'pending';

	public const STATUS_ON_HOLD = 'on_hold';

	public const STATUS_CONFIRMED = 'confirmed';

	public const STATUS_CANCELLED = 'cancelled';

	public const STATUS_COMPLETED = 'completed';

	public const STATUS_REFUNDED = 'refunded';

	public const STATUS_FAILED = 'failed';

	public const PAYMENT_UNPAID = 'unpaid';

	public const PAYMENT_PARTIAL = 'partially_paid';

	public const PAYMENT_PAID = 'paid';

	public const PAYMENT_REFUNDED = 'refunded';

	public const PAYMENT_CANCELLED = 'cancelled';

	public const TYPE_ONE_WAY = 'one_way';

	public const TYPE_RETURN = 'return';

	/**
	 * Statuses that hold inventory on a sailing.
	 *
	 * @var string[]
	 */
	public const CONSUMING_STATUSES = array(
		self::STATUS_ON_HOLD,
		self::STATUS_PENDING,
		self::STATUS_CONFIRMED,
		self::STATUS_COMPLETED,
	);

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'booking';
	}

	/**
	 * Returns the post type storing the entity.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * Returns the capability required to write the entity.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::MANAGE_BOOKINGS;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Bookings', 'magepeople-ferry-booking-system' ),
			'singular' => __( 'Booking', 'magepeople-ferry-booking-system' ),
		);
	}

	/**
	 * Returns the raw field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definition(): array {
		return array(
			'booking_number'    => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_booking_number',
				'max'         => 32,
				'searchable'  => true,
				'description' => __( 'Booking reference', 'magepeople-ferry-booking-system' ),
			),
			'customer_id'       => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_customer_id',
				'min'         => 0,
				'description' => __( 'Customer user id', 'magepeople-ferry-booking-system' ),
			),
			'customer_email'    => array(
				'type'        => 'email',
				'meta'        => '_mpfbs_customer_email',
				'searchable'  => true,
				'description' => __( 'Customer email', 'magepeople-ferry-booking-system' ),
			),
			'customer_name'     => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_customer_name',
				'max'         => 200,
				'searchable'  => true,
				'description' => __( 'Customer name', 'magepeople-ferry-booking-system' ),
			),
			'customer_phone'    => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_customer_phone',
				'max'         => 40,
				'description' => __( 'Customer phone', 'magepeople-ferry-booking-system' ),
			),
			'sailing_id'        => array(
				'type'        => 'id',
				'meta'        => '_mpfbs_sailing_id',
				'references'  => Sailing::POST_TYPE,
				'required'    => true,
				'description' => __( 'Outbound sailing', 'magepeople-ferry-booking-system' ),
			),
			'return_sailing_id' => array(
				'type'        => 'id',
				'meta'        => '_mpfbs_return_sailing_id',
				'references'  => Sailing::POST_TYPE,
				'description' => __( 'Return sailing', 'magepeople-ferry-booking-system' ),
			),
			'booking_type'      => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_booking_type',
				'enum'        => array( self::TYPE_ONE_WAY, self::TYPE_RETURN ),
				'default'     => self::TYPE_ONE_WAY,
				'description' => __( 'Booking type', 'magepeople-ferry-booking-system' ),
			),
			'passenger_count'   => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_passenger_count',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Passenger count', 'magepeople-ferry-booking-system' ),
			),
			'vehicle_count'     => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_vehicle_count',
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Vehicle count', 'magepeople-ferry-booking-system' ),
			),
			'lane_metres'       => array(
				'type'        => 'float',
				'meta'        => '_mpfbs_lane_metres',
				'min'         => 0,
				'description' => __( 'Lane metres consumed', 'magepeople-ferry-booking-system' ),
			),
			'subtotal'          => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_subtotal',
				'description' => __( 'Subtotal (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'discount'          => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_discount',
				'description' => __( 'Discount (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'tax'               => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_tax',
				'description' => __( 'Tax (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'fees'              => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_fees',
				'description' => __( 'Fees (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'total'             => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_total',
				'description' => __( 'Total (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'paid'              => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_paid',
				'description' => __( 'Amount paid (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'refunded'          => array(
				'type'        => 'money',
				'meta'        => '_mpfbs_refunded',
				'description' => __( 'Amount refunded (minor units)', 'magepeople-ferry-booking-system' ),
			),
			'currency'          => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_currency',
				'max'         => 3,
				'description' => __( 'Currency code', 'magepeople-ferry-booking-system' ),
			),
			'payment_method'    => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_payment_method',
				'max'         => 64,
				'description' => __( 'Payment method', 'magepeople-ferry-booking-system' ),
			),
			'payment_status'    => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_payment_status',
				'enum'        => array(
					self::PAYMENT_UNPAID,
					self::PAYMENT_PARTIAL,
					self::PAYMENT_PAID,
					self::PAYMENT_REFUNDED,
					self::PAYMENT_CANCELLED,
				),
				'default'     => self::PAYMENT_UNPAID,
				'description' => __( 'Payment status', 'magepeople-ferry-booking-system' ),
			),
			'booking_status'    => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_booking_status',
				'enum'        => array(
					self::STATUS_PENDING,
					self::STATUS_ON_HOLD,
					self::STATUS_CONFIRMED,
					self::STATUS_CANCELLED,
					self::STATUS_COMPLETED,
					self::STATUS_REFUNDED,
					self::STATUS_FAILED,
				),
				'default'     => self::STATUS_PENDING,
				'description' => __( 'Booking status', 'magepeople-ferry-booking-system' ),
			),
			'channel'           => array(
				'type'        => 'enum',
				'meta'        => '_mpfbs_channel',
				'enum'        => array( 'web', 'backend', 'api' ),
				'default'     => 'web',
				'description' => __( 'Sales channel', 'magepeople-ferry-booking-system' ),
			),
			'wc_order_id'       => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_wc_order_id',
				'min'         => 0,
				'description' => __( 'WooCommerce order id', 'magepeople-ferry-booking-system' ),
			),
			'created_by'        => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_created_by',
				'min'         => 0,
				'description' => __( 'Created by user id', 'magepeople-ferry-booking-system' ),
			),
			'departure_ts'      => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_departure_ts',
				'readonly'    => true,
				'description' => __( 'Outbound departure timestamp (UTC)', 'magepeople-ferry-booking-system' ),
			),
			'idempotency_key'   => array(
				'type'        => 'string',
				'meta'        => '_mpfbs_idempotency_key',
				'max'         => 64,
				'description' => __( 'Idempotency key', 'magepeople-ferry-booking-system' ),
			),
			'hold_expires_ts'   => array(
				'type'        => 'int',
				'meta'        => '_mpfbs_hold_expires_ts',
				'min'         => 0,
				'description' => __( 'Hold expiry timestamp (UTC)', 'magepeople-ferry-booking-system' ),
			),
			'passengers'        => array(
				'type'        => 'string_list',
				'meta'        => '_mpfbs_passengers',
				'description' => __( 'Passengers', 'magepeople-ferry-booking-system' ),
			),
			'vehicles'          => array(
				'type'        => 'string_list',
				'meta'        => '_mpfbs_vehicles',
				'description' => __( 'Vehicles', 'magepeople-ferry-booking-system' ),
			),
			'internal_notes'    => array(
				'type'        => 'text',
				'meta'        => '_mpfbs_internal_notes',
				'max'         => 5000,
				'description' => __( 'Internal notes', 'magepeople-ferry-booking-system' ),
			),
		);
	}

	/**
	 * Returns the booking reference.
	 *
	 * @return string
	 */
	public function number(): string {
		return (string) $this->get( 'booking_number' );
	}

	/**
	 * Returns the outbound sailing id.
	 *
	 * @return int
	 */
	public function sailing_id(): int {
		return (int) $this->get( 'sailing_id' );
	}

	/**
	 * Returns the total in minor units.
	 *
	 * @return int
	 */
	public function total(): int {
		return (int) $this->get( 'total' );
	}

	/**
	 * Returns the outstanding balance in minor units.
	 *
	 * @return int
	 */
	public function balance(): int {
		return $this->total() - (int) $this->get( 'paid' ) + (int) $this->get( 'refunded' );
	}

	/**
	 * Determines whether the booking currently holds inventory.
	 *
	 * An expired hold stops consuming capacity the moment it lapses, without
	 * waiting for a cleanup job to notice.
	 *
	 * @param int|null $now Optional UTC timestamp.
	 * @return bool
	 */
	public function consumes_capacity( ?int $now = null ): bool {
		$status = (string) $this->get( 'booking_status' );

		if ( ! in_array( $status, self::CONSUMING_STATUSES, true ) ) {
			return false;
		}

		if ( self::STATUS_ON_HOLD === $status ) {
			$expires = (int) $this->get( 'hold_expires_ts' );

			return 0 === $expires || ( null === $now ? time() : $now ) < $expires;
		}

		return true;
	}
}
