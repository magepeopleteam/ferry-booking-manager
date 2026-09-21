<?php
/**
 * Payment gateway contract.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * One way of taking money for a booking.
 *
 * A gateway knows how to present itself, whether it is available right now,
 * and what happens when a booking is confirmed through it. The plugin
 * ships offline gateways; third parties can register online gateways through
 * the registry without touching the booking engine.
 *
 * BookingService never hard-codes a gateway id: it consults the registry,
 * which is what keeps the booking path open to new payment methods.
 */
interface PaymentGatewayInterface {

	/**
	 * Returns the gateway id, e.g. "cash".
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Returns the gateway title, e.g. "Pay at the terminal".
	 *
	 * @return string
	 */
	public function get_title(): string;

	/**
	 * Returns a short description shown on the payment choice screen.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Determines whether the gateway can be offered right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Determines whether paying with this gateway confirms the booking
	 * immediately, or whether the booking stays pending payment.
	 *
	 * @return bool
	 */
	public function is_instant(): bool;
}
