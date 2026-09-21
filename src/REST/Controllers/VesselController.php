<?php
/**
 * Vessels endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\REST\EntityController;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for vessels.
 */
final class VesselController extends EntityController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'vessels';
}
