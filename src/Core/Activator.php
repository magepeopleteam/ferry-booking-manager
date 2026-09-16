<?php
/**
 * Activation routine.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Security\Roles;
use FBM\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 *
 * Creates no database tables: WordPress's own post, meta, option and user
 * storage is the persistence layer for the entire product.
 */
final class Activator {

	/**
	 * Cron hook running daily housekeeping.
	 */
	public const MAINTENANCE_HOOK = 'fbm_daily_maintenance';

	/**
	 * Performs activation work.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Roles::install();

		if ( null === Options::get( 'installed_at' ) ) {
			Options::set( 'installed_at', gmdate( 'c' ) );
		}

		Options::set( 'version', FBM_VERSION, true );
		Options::set( 'needs_rewrite_flush', 1 );

		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}

		/**
		 * Fires at the end of MagePeople Ferry Booking System activation.
		 *
		 * @since 1.0.0
		 */
		do_action( 'fbm_activated' );
	}
}
