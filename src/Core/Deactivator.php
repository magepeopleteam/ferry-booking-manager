<?php
/**
 * Deactivation routine.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Availability\HoldManager;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated.
 *
 * Deliberately non-destructive: no booking, sailing or configuration data is
 * removed. Data removal only happens from uninstall.php, and only when the site
 * owner has opted in.
 */
final class Deactivator {

	/**
	 * Performs deactivation work.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Activator::MAINTENANCE_HOOK );
		wp_clear_scheduled_hook( HoldManager::CLEANUP_HOOK );

		flush_rewrite_rules();

		/**
		 * Fires at the end of MagePeople Ferry Booking System deactivation.
		 *
		 * @since 1.0.0
		 */
		do_action( 'fbm_deactivated' );
	}
}
