<?php
/**
 * Plugin Name:       MagePeople Ferry Booking System
 * Plugin URI:        https://github.com/magepeopleteam/ferry-booking-manager
 * Description:       Production-grade ferry booking and ferry operations management for WordPress. Vessels, ports, routes, sailings, passengers, vehicles, availability, pricing, bookings, WooCommerce and native checkout.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MagePeople Team
 * Author URI:        https://mage-people.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       magepeople-ferry-booking-system
 * Domain Path:       /languages
 *
 * @package FerryBookingManager
 */

/*
 * This file must remain parseable by PHP 7.0 so that the compatibility gate below
 * can render a friendly notice instead of a fatal error on legacy hosts.
 * All modern PHP (8.0+) lives inside src/.
 */

defined( 'ABSPATH' ) || exit;

define( 'MPFBS_VERSION', '1.0.0' );
define( 'MPFBS_PLUGIN_FILE', __FILE__ );
define( 'MPFBS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MPFBS_URL', plugin_dir_url( __FILE__ ) );
define( 'MPFBS_BASENAME', plugin_basename( __FILE__ ) );
define( 'MPFBS_MIN_PHP', '8.0' );
define( 'MPFBS_MIN_WP', '6.0' );

/**
 * Collects an environment failure and renders it as a dismissible admin notice.
 *
 * @param string $message Already-translated message.
 * @return void
 */
function mpfbs_environment_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			echo '<div class="notice notice-error"><p><strong>MagePeople Ferry Booking System</strong> &mdash; ' . esc_html( $message ) . '</p></div>';
		}
	);
}

/**
 * Verifies the runtime is capable of loading the plugin.
 *
 * @return bool
 */
function mpfbs_environment_is_supported() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, MPFBS_MIN_PHP, '<' ) ) {
		mpfbs_environment_notice(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin has not been loaded.', 'magepeople-ferry-booking-system' ),
				MPFBS_MIN_PHP,
				PHP_VERSION
			)
		);

		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, MPFBS_MIN_WP, '<' ) ) {
		mpfbs_environment_notice(
			sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'requires WordPress %1$s or newer. This site runs WordPress %2$s, so the plugin has not been loaded.', 'magepeople-ferry-booking-system' ),
				MPFBS_MIN_WP,
				$wp_version
			)
		);

		return false;
	}

	return true;
}

/**
 * Registers the class autoloader.
 *
 * Uses Composer when the package has been installed, otherwise falls back to the
 * bundled PSR-4 autoloader so the shipped plugin never depends on Composer.
 *
 * @return void
 */
function mpfbs_register_autoloader() {
	if ( file_exists( MPFBS_PATH . 'vendor/autoload.php' ) ) {
		require_once MPFBS_PATH . 'vendor/autoload.php';

		return;
	}

	require_once MPFBS_PATH . 'src/Core/Autoloader.php';
	MPFBS\Core\Autoloader::register( 'MPFBS\\', MPFBS_PATH . 'src/' );
}

/**
 * Boots the plugin container once WordPress has loaded its own plugins.
 *
 * @return void
 */
function mpfbs_boot() {
	if ( ! mpfbs_environment_is_supported() ) {
		return;
	}

	mpfbs_register_autoloader();

	MPFBS\Core\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'mpfbs_boot', 5 );

register_activation_hook(
	__FILE__,
	function () {
		if ( ! mpfbs_environment_is_supported() ) {
			return;
		}

		mpfbs_register_autoloader();
		MPFBS\Core\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( ! mpfbs_environment_is_supported() ) {
			return;
		}

		mpfbs_register_autoloader();
		MPFBS\Core\Deactivator::deactivate();
	}
);
