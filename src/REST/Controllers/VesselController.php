<?php
/**
 * Vessels endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\REST\EntityController;

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
