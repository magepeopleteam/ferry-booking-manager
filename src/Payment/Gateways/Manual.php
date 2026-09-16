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
 * A manual, offline or custom payment arrangement.
 */
final class Manual implements PaymentGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'manual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Manual payment', 'magepeople-ferry-booking-system' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'An offline or custom payment arrangement with the operator.', 'magepeople-ferry-booking-system' );
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
