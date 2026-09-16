<?php
/**
 * Service provider contract.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a unit of plugin functionality.
 *
 * Providers are the single extension point used by MagePeople Ferry Booking System Pro and
 * by third parties; they never contain business logic themselves.
 */
interface ServiceProviderInterface {

	/**
	 * Binds services into the container.
	 *
	 * Must not touch WordPress hooks or the database.
	 *
	 * @param ContainerInterface $container Plugin container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void;

	/**
	 * Attaches WordPress hooks once every provider has been registered.
	 *
	 * @param ContainerInterface $container Plugin container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void;
}
