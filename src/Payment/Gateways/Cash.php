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
 * Paying in cash at the terminal on arrival.
 */
final class Cash implements PaymentGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'cash';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Cash', 'magepeople-ferry-booking-system' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Pay with cash at the terminal before boarding.', 'magepeople-ferry-booking-system' );
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
