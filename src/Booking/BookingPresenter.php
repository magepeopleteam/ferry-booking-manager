<?php
/**
 * Customer-facing booking representation.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Booking;

use FBM\Models\Booking;
use FBM\Models\Port;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use FBM\Repositories\PortRepository;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VesselRepository;
use FBM\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a booking into something a customer can read.
 *
 * The staff view of a booking is a row of ids and slugs, which is right for a
 * dashboard and useless on a confirmation page: nobody wants to be told their
 * crossing is sailing 947. This resolves the route, the ports, the vessel and
 * the times once, and is shared by the confirmation page, the booking history
 * and the guest lookup so all three describe a crossing the same way.
 *
 * Nothing here exposes anything the customer did not already give us. Internal
 * notes, staff fields and other customers' details never appear.
 */
final class BookingPresenter {

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Route repository.
	 *
	 * @var RouteRepository
	 */
	private RouteRepository $routes;

	/**
	 * Port repository.
	 *
	 * @var PortRepository
	 */
	private PortRepository $ports;

	/**
	 * Vessel repository.
	 *
	 * @var VesselRepository
	 */
	private VesselRepository $vessels;

	/**
	 * Ports already resolved during this request.
	 *
	 * @var array<int, string>
	 */
	private array $port_names = array();

	/**
	 * Legs already described during this request, keyed by sailing id.
	 *
	 * A list of bookings repeats the same crossings constantly — a busy morning
	 * departure is fifty rows — and each one costs a sailing, a route, a vessel
	 * and two ports to describe.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $legs = array();

	/**
	 * Constructor.
	 *
	 * @param SailingRepository $sailings Sailing repository.
	 * @param RouteRepository   $routes   Route repository.
	 * @param PortRepository    $ports    Port repository.
	 * @param VesselRepository  $vessels  Vessel repository.
	 */
	public function __construct(
		SailingRepository $sailings,
		RouteRepository $routes,
		PortRepository $ports,
		VesselRepository $vessels
	) {
		$this->sailings = $sailings;
		$this->routes   = $routes;
		$this->ports    = $ports;
		$this->vessels  = $vessels;
	}

	/**
	 * Describes one booking for its owner.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array<string, mixed>
	 */
	public function present( Booking $booking ): array {
		$status  = (string) $booking->get( 'booking_status' );
		$payment = (string) $booking->get( 'payment_status' );
		$legs    = $this->legs( $booking );

		$data = array(
			'reference'       => $booking->number(),
			'status'          => $status,
			'status_label'    => self::status_label( $status ),
			'payment_status'  => $payment,
			'payment_label'   => self::payment_label( $payment ),
			'booking_type'    => (string) $booking->get( 'booking_type' ),
			'passenger_count' => (int) $booking->get( 'passenger_count' ),
			'vehicle_count'   => (int) $booking->get( 'vehicle_count' ),
			'total'           => (int) $booking->get( 'total' ),
			'paid'            => (int) $booking->get( 'paid' ),
			'balance'         => $this->owing( $booking ),
			'currency'        => (string) $booking->get( 'currency' ),
			'customer_name'   => (string) $booking->get( 'customer_name' ),
			'payment_method'  => (string) $booking->get( 'payment_method' ),
			'booked_on'       => Time::timestamp_to_local( (int) get_post_time( 'U', true, $booking->id ) ),
			'departure_ts'    => (int) $booking->get( 'departure_ts' ),
			'upcoming'        => (int) $booking->get( 'departure_ts' ) >= time(),
			'cancellable'     => $this->cancellable( $booking ),
			'legs'            => $legs,
		);

		/**
		 * Filters the customer-facing view of a booking.
		 *
		 * Pro attaches ticket and boarding-pass links here.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, mixed> $data    Customer-facing booking data.
		 * @param Booking              $booking Booking entity.
		 */
		return (array) apply_filters( 'fbm_customer_booking', $data, $booking );
	}

	/**
	 * Warms the caches for a whole page of bookings at once.
	 *
	 * Describing a journey costs a sailing, its route, its vessel and two ports.
	 * Left to itself that is five single-row lookups per booking, and a page of
	 * twenty crossings that share nothing is seventy queries to draw one column.
	 * Priming pulls each layer in one query instead, so the lookups below all
	 * come back from the object cache.
	 *
	 * Optional in every sense: nothing breaks without it, the results are
	 * identical either way, and a caller that does not know its sailings up
	 * front simply does not call it.
	 *
	 * @param array<int, int> $sailing_ids Sailing ids about to be described.
	 * @return void
	 */
	public function prime( array $sailing_ids ): void {
		$ids = $this->usable( $sailing_ids );

		if ( array() === $ids ) {
			return;
		}

		_prime_post_caches( $ids, false, true );

		$related = array();

		foreach ( $ids as $id ) {
			$sailing = $this->sailings->find( $id );

			if ( $sailing instanceof Sailing ) {
				$related[] = $sailing->route_id();
				$related[] = $sailing->vessel_id();
			}
		}

		$related = $this->usable( $related );

		if ( array() === $related ) {
			return;
		}

		_prime_post_caches( $related, false, true );

		$ports = array();

		foreach ( $related as $id ) {
			$route = $this->routes->find( $id );

			if ( $route instanceof Route ) {
				$ports[] = $route->origin_port();
				$ports[] = $route->destination_port();
			}
		}

		$ports = $this->usable( $ports );

		if ( array() !== $ports ) {
			_prime_post_caches( $ports, false, true );
		}
	}

	/**
	 * Reduces a list of ids to the distinct, positive ones.
	 *
	 * @param array<int, mixed> $ids Raw ids.
	 * @return array<int, int>
	 */
	private function usable( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Describes every leg of a booking's journey, outbound first.
	 *
	 * Shared with the dashboard rather than kept for the customer view: staff
	 * asking "where is this booking going" want the same answer the customer
	 * was given, resolved the same way, or the two screens disagree about a
	 * crossing whose route has since been renamed.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array<int, array<string, mixed>>
	 */
	public function legs( Booking $booking ): array {
		$legs = array();

		foreach ( array(
			array( (int) $booking->get( 'sailing_id' ), __( 'Outbound', 'magepeople-ferry-booking-system' ) ),
			array( (int) $booking->get( 'return_sailing_id' ), __( 'Return', 'magepeople-ferry-booking-system' ) ),
		) as $candidate ) {
			$leg = $this->leg( $candidate[0], $candidate[1] );

			if ( array() !== $leg ) {
				$legs[] = $leg;
			}
		}

		return $legs;
	}

	/**
	 * Describes one leg of a journey.
	 *
	 * @param int    $sailing_id Sailing id.
	 * @param string $label      Leg label.
	 * @return array<string, mixed> Empty when the sailing no longer exists.
	 */
	private function leg( int $sailing_id, string $label ): array {
		if ( $sailing_id < 1 ) {
			return array();
		}

		// The label is the only part that depends on which way round the leg is,
		// so it is put back on afterwards and everything else is remembered.
		if ( isset( $this->legs[ $sailing_id ] ) ) {
			return array_merge( $this->legs[ $sailing_id ], array( 'label' => $label ) );
		}

		$sailing = $this->sailings->find( $sailing_id );

		if ( ! $sailing instanceof Sailing ) {
			return array();
		}

		$route  = $this->routes->find( $sailing->route_id() );
		$vessel = $this->vessels->find( $sailing->vessel_id() );

		$this->legs[ $sailing_id ] = array(
			'label'       => $label,
			'origin'      => $route instanceof Route ? $this->port_name( $route->origin_port() ) : '',
			'destination' => $route instanceof Route ? $this->port_name( $route->destination_port() ) : '',
			'route'       => $route instanceof Route ? $route->name : '',
			'vessel'      => $vessel instanceof Vessel ? $vessel->name : '',
			'departure'   => (string) $sailing->get( 'departure_datetime' ),
			'arrival'     => (string) $sailing->get( 'arrival_datetime' ),
			'status'      => (string) $sailing->get( 'status' ),
		);

		return $this->legs[ $sailing_id ];
	}

	/**
	 * Returns a port's name, resolving each port at most once per request.
	 *
	 * A round trip asks for the same two ports twice, and a booking history
	 * asks for the same handful over and over.
	 *
	 * @param int $port_id Port id.
	 * @return string
	 */
	private function port_name( int $port_id ): string {
		if ( $port_id < 1 ) {
			return '';
		}

		if ( ! isset( $this->port_names[ $port_id ] ) ) {
			$port = $this->ports->find( $port_id );

			$this->port_names[ $port_id ] = $port instanceof Port ? $port->name : '';
		}

		return $this->port_names[ $port_id ];
	}

	/**
	 * Says whether the customer may still cancel this booking themselves.
	 *
	 * Free does not offer self-service cancellation, so this is always false
	 * here. It is published so that the interface can be built once and Pro can
	 * turn it on through the filter rather than the view being rewritten.
	 *
	 * @param Booking $booking Booking entity.
	 * @return bool
	 */
	private function cancellable( Booking $booking ): bool {
		unset( $booking );

		return false;
	}

	/**
	 * Returns the customer-facing label for a booking status.
	 *
	 * @param string $status Booking status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			Booking::STATUS_PENDING   => __( 'Awaiting payment', 'magepeople-ferry-booking-system' ),
			Booking::STATUS_ON_HOLD   => __( 'Held', 'magepeople-ferry-booking-system' ),
			Booking::STATUS_CONFIRMED => __( 'Confirmed', 'magepeople-ferry-booking-system' ),
			Booking::STATUS_COMPLETED => __( 'Travelled', 'magepeople-ferry-booking-system' ),
			Booking::STATUS_CANCELLED => __( 'Cancelled', 'magepeople-ferry-booking-system' ),
			Booking::STATUS_REFUNDED  => __( 'Refunded', 'magepeople-ferry-booking-system' ),
			Booking::STATUS_FAILED    => __( 'Not completed', 'magepeople-ferry-booking-system' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Returns the customer-facing label for a payment status.
	 *
	 * @param string $status Payment status.
	 * @return string
	 */
	public static function payment_label( string $status ): string {
		$labels = array(
			Booking::PAYMENT_UNPAID    => __( 'Unpaid', 'magepeople-ferry-booking-system' ),
			Booking::PAYMENT_PARTIAL   => __( 'Part paid', 'magepeople-ferry-booking-system' ),
			Booking::PAYMENT_PAID      => __( 'Paid', 'magepeople-ferry-booking-system' ),
			Booking::PAYMENT_REFUNDED  => __( 'Refunded', 'magepeople-ferry-booking-system' ),
			Booking::PAYMENT_CANCELLED => __( 'Cancelled', 'magepeople-ferry-booking-system' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Returns what the customer still owes, in minor units.
	 *
	 * The stored balance is the arithmetic one — the fare, less what has been
	 * paid, plus anything given back. That is the right sum for a live booking
	 * and a misleading one for a cancelled or refunded crossing, where the fare
	 * is no longer payable at all: a refund would otherwise leave the customer
	 * looking at their own money listed as a debt.
	 *
	 * @param Booking $booking Booking entity.
	 * @return int
	 */
	private function owing( Booking $booking ): int {
		$settled = array(
			Booking::STATUS_CANCELLED,
			Booking::STATUS_REFUNDED,
			Booking::STATUS_FAILED,
		);

		if ( in_array( (string) $booking->get( 'booking_status' ), $settled, true ) ) {
			return 0;
		}

		return max( 0, $booking->balance() );
	}
}
