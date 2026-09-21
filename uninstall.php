<?php
/**
 * Uninstall routine.
 *
 * Runs when the site owner deletes the plugin from wp-admin.
 *
 * @package FerryBookingManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Core/Autoloader.php';

MPFBS\Core\Autoloader::register( 'MPFBS\\', __DIR__ . '/src/' );

if ( ! defined( 'MPFBS_VERSION' ) ) {
	define( 'MPFBS_VERSION', '1.0.0' );
}

/**
 * Removes every trace of the plugin from the current site.
 *
 * Operational data (bookings, sailings, vessels) is only deleted when the site
 * owner has explicitly opted in, because deleting a plugin is a routine action
 * and losing a ferry operator's booking history would not be recoverable.
 *
 * @return void
 */
function mpfbs_uninstall_site() {
	MPFBS\Security\Roles::uninstall();

	wp_clear_scheduled_hook( 'mpfbs_daily_maintenance' );

	$delete_everything = in_array( get_option( 'mpfbs_delete_data_on_uninstall' ), array( true, 1, '1', 'yes' ), true );

	// Always remove plugin bookkeeping options, never customer data.
	$bookkeeping = array(
		'mpfbs_roles_signature',
		'mpfbs_log_hash',
		'mpfbs_log_level',
		'mpfbs_needs_rewrite_flush',
	);

	foreach ( $bookkeeping as $option ) {
		delete_option( $option );
	}

	if ( ! $delete_everything ) {
		return;
	}

	foreach ( MPFBS\Support\Options::registry() as $option ) {
		delete_option( $option );
	}

	delete_option( MPFBS\Support\Options::REGISTRY );

	foreach ( array( 'mpfbs_vessel', 'mpfbs_port', 'mpfbs_route', 'mpfbs_sailing', 'mpfbs_booking', 'mpfbs_passenger_type', 'mpfbs_vehicle_type' ) as $post_type ) {
		$ids = get_posts(
			array(
				'post_type'     => $post_type,
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		foreach ( (array) $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	// Remove the protected log directory.
	$uploads = wp_upload_dir( null, false );

	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		$directory = trailingslashit( $uploads['basedir'] ) . 'mpfbs-logs/';

		if ( is_dir( $directory ) ) {
			$files = glob( $directory . '*' );

			foreach ( (array) $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Removing an empty log directory during uninstall; failure is not actionable.
			@rmdir( $directory );
		}
	}
}

if ( is_multisite() ) {
	$mpfbs_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $mpfbs_site_ids as $mpfbs_site_id ) {
		switch_to_blog( (int) $mpfbs_site_id );
		mpfbs_uninstall_site();
		restore_current_blog();
	}
} else {
	mpfbs_uninstall_site();
}
