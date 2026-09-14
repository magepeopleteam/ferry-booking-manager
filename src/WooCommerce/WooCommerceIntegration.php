<?php
/**
 * WooCommerce integration.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\WooCommerce;

use FBM\Booking\BookingService;
use FBM\Contracts\LoggerInterface;
use FBM\Models\Booking;
use FBM\Repositories\BookingRepository;
use FBM\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a booking and its WooCommerce order in step.
 *
 * A booking handed to WooCommerce is confirmed the moment its order is paid,
 * and cancelled when the order is cancelled. The link lives on both records:
 * `_fbm_wc_order_id` on the booking and `_fbm_booking_id` on the order, so
 * either side can find the other.
 */
final class WooCommerceIntegration {

	/**
	 * Booking service.
	 *
	 * @var BookingService
	 */
	private BookingService $bookings;

	/**
	 * Booking repository.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $repository;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param BookingService    $bookings   Booking service.
	 * @param BookingRepository $repository Booking repository.
	 * @param LoggerInterface   $logger     Logger.
	 */
	public function __construct( BookingService $bookings, BookingRepository $repository, LoggerInterface $logger ) {
		$this->bookings   = $bookings;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * Attaches the hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'order_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_cancelled' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'order_refunded' ), 10, 2 );
	}

	/**
	 * Confirms the booking behind a paid order.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order   Order object.
	 * @return void
	 */
	public function order_paid( int $order_id, $order ): void {
		// WooCommerce passes the order object alongside the id on this hook.
		// The booking is found from the id, so the object is not needed, but
		// the parameter has to stay for the callback to match the hook.
		unset( $order );

		$booking_id = (int) get_post_meta( $order_id, '_fbm_booking_id', true );

		if ( $booking_id < 1 ) {
			return;
		}

		$booking = $this->repository->find( $booking_id );

		if ( ! $booking instanceof Booking ) {
			return;
		}

		// Only orders the plugin created carry the marker; an order that
		// happens to mention a booking reference is not this integration's job.
		if ( (int) $booking->get( 'wc_order_id' ) !== (int) $order_id ) {
			return;
		}

		$result = $this->bookings->confirm_wc_paid( $booking_id );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Could not confirm a booking after WooCommerce payment.',
				array(
					'booking' => $booking_id,
					'order'   => $order_id,
					'error'   => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Handles a refunded order.
	 *
	 * A refund is not always the end of a crossing. An operator who refunds a
	 * cabin upgrade, or gives back a fare difference after a downgrade, still
	 * expects the passenger to sail, and releasing the seat would sell it to
	 * somebody else. So this is a setting rather than an assumption.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order object.
	 * @return void
	 */
	public function order_refunded( int $order_id, $order ): void {
		if ( empty( Settings::all()['wc_cancel_on_refund'] ) ) {
			return;
		}

		$this->order_cancelled( $order_id, $order );
	}

	/**
	 * Cancels the booking behind a cancelled or refunded order.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order   Order object.
	 * @return void
	 */
	public function order_cancelled( int $order_id, $order ): void {
		unset( $order );

		$booking_id = (int) get_post_meta( $order_id, '_fbm_booking_id', true );

		if ( $booking_id < 1 ) {
			return;
		}

		$booking = $this->repository->find( $booking_id );

		if ( ! $booking instanceof Booking || (int) $booking->get( 'wc_order_id' ) !== (int) $order_id ) {
			return;
		}

		$result = $this->bookings->cancel( $booking_id, __( 'The WooCommerce order was cancelled.', 'ferry-booking-manager' ) );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Could not cancel a booking after its WooCommerce order was cancelled.',
				array(
					'booking' => $booking_id,
					'order'   => $order_id,
					'error'   => $result->get_error_message(),
				)
			);
		}
	}
}
