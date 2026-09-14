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
		return __( 'Cash', 'ferry-booking-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Pay with cash at the terminal before boarding.', 'ferry-booking-manager' );
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
