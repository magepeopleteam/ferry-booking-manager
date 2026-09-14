<?php
/**
 * Plugin Name:       Ferry Booking Manager
 * Plugin URI:        https://mage-people.com/ferry-booking-manager/
 * Description:       Production-grade ferry booking and ferry operations management for WordPress. Vessels, ports, routes, sailings, passengers, vehicles, availability, pricing, bookings, WooCommerce and native checkout.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MagePeople Team
 * Author URI:        https://mage-people.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ferry-booking-manager
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

define( 'FBM_VERSION', '1.0.0' );
define( 'FBM_PLUGIN_FILE', __FILE__ );
define( 'FBM_PATH', plugin_dir_path( __FILE__ ) );
define( 'FBM_URL', plugin_dir_url( __FILE__ ) );
define( 'FBM_BASENAME', plugin_basename( __FILE__ ) );
define( 'FBM_MIN_PHP', '8.0' );
define( 'FBM_MIN_WP', '6.0' );

/**
 * Collects an environment failure and renders it as a dismissible admin notice.
 *
 * @param string $message Already-translated message.
 * @return void
 */
function fbm_environment_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			echo '<div class="notice notice-error"><p><strong>Ferry Booking Manager</strong> &mdash; ' . esc_html( $message ) . '</p></div>';
		}
	);
}

/**
 * Verifies the runtime is capable of loading the plugin.
 *
 * @return bool
 */
function fbm_environment_is_supported() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, FBM_MIN_PHP, '<' ) ) {
		fbm_environment_notice(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin has not been loaded.', 'ferry-booking-manager' ),
				FBM_MIN_PHP,
				PHP_VERSION
			)
		);

		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, FBM_MIN_WP, '<' ) ) {
		fbm_environment_notice(
			sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'requires WordPress %1$s or newer. This site runs WordPress %2$s, so the plugin has not been loaded.', 'ferry-booking-manager' ),
				FBM_MIN_WP,
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
function fbm_register_autoloader() {
	if ( file_exists( FBM_PATH . 'vendor/autoload.php' ) ) {
		require_once FBM_PATH . 'vendor/autoload.php';

		return;
	}

	require_once FBM_PATH . 'src/Core/Autoloader.php';
	FBM\Core\Autoloader::register( 'FBM\\', FBM_PATH . 'src/' );
}

/**
 * Boots the plugin container once WordPress has loaded its own plugins.
 *
 * @return void
 */
function fbm_boot() {
	if ( ! fbm_environment_is_supported() ) {
		return;
	}

	fbm_register_autoloader();

	FBM\Core\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'fbm_boot', 5 );

register_activation_hook(
	__FILE__,
	function () {
		if ( ! fbm_environment_is_supported() ) {
			return;
		}

		fbm_register_autoloader();
		FBM\Core\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( ! fbm_environment_is_supported() ) {
			return;
		}

		fbm_register_autoloader();
		FBM\Core\Deactivator::deactivate();
	}
);
