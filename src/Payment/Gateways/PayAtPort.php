<?php
/**
 * Offline payment gateways shipped with the Free plugin.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Payment\Gateways;

use FBM\Payment\PaymentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Paying at the departure port, at a terminal or counter.
 */
final class PayAtPort implements PaymentGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'pay_at_port';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Pay at the port', 'magepeople-ferry-booking-system' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Pay at the terminal before boarding.', 'magepeople-ferry-booking-system' );
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
