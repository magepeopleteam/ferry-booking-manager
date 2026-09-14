<?php
/**
 * Ports endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\REST\EntityController;

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
