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

FBM\Core\Autoloader::register( 'FBM\\', __DIR__ . '/src/' );

if ( ! defined( 'FBM_VERSION' ) ) {
	define( 'FBM_VERSION', '1.0.0' );
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
function fbm_uninstall_site() {
	FBM\Security\Roles::uninstall();

	wp_clear_scheduled_hook( 'fbm_daily_maintenance' );

	$delete_everything = in_array( get_option( 'fbm_delete_data_on_uninstall' ), array( true, 1, '1', 'yes' ), true );

	// Always remove plugin bookkeeping options, never customer data.
	$bookkeeping = array(
		'fbm_roles_signature',
		'fbm_log_hash',
		'fbm_log_level',
		'fbm_needs_rewrite_flush',
	);

	foreach ( $bookkeeping as $option ) {
		delete_option( $option );
	}

	if ( ! $delete_everything ) {
		return;
	}

	foreach ( FBM\Support\Options::registry() as $option ) {
		delete_option( $option );
	}

	delete_option( FBM\Support\Options::REGISTRY );

	foreach ( array( 'fbm_vessel', 'fbm_port', 'fbm_route', 'fbm_sailing', 'fbm_booking', 'fbm_passenger_type', 'fbm_vehicle_type' ) as $post_type ) {
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
		$directory = trailingslashit( $uploads['basedir'] ) . 'fbm-logs/';

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
	$fbm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $fbm_site_ids as $fbm_site_id ) {
		switch_to_blog( (int) $fbm_site_id );
		fbm_uninstall_site();
		restore_current_blog();
	}
} else {
	fbm_uninstall_site();
}
