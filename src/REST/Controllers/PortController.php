<?php
/**
 * Ports endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\REST\EntityController;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for ports.
 */
final class PortController extends EntityController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'ports';
}
