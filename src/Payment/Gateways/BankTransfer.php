<?php
/**
 * Offline payment gateways shipped with the plugin.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Payment\Gateways;

use MPFBS\Payment\PaymentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Paying by bank transfer, with the reference attached to the booking.
 */
final class BankTransfer implements PaymentGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'bank_transfer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Bank transfer', 'magepeople-ferry-booking-system' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Transfer the total to our account and quote your booking reference.', 'magepeople-ferry-booking-system' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_instant(): bool {
		return false;
	}
}
