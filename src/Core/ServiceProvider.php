<?php
/**
 * Base service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Core;

use MPFBS\Contracts\ContainerInterface;
use MPFBS\Contracts\ServiceProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Convenience base class so providers only implement what they need.
 */
abstract class ServiceProvider implements ServiceProviderInterface {
	/**
	 * Binds services into the container.
	 *
	 * Must not touch WordPress hooks or the database.
	 *
	 * @param ContainerInterface $container Plugin container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Intentionally empty.
	}

	/**
	 * Attaches WordPress hooks once every provider has been registered.
	 *
	 * @param ContainerInterface $container Plugin container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Intentionally empty.
	}
}
