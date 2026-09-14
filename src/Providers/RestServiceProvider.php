<?php
/**
 * REST service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Providers;

use FBM\Contracts\ContainerInterface;
use FBM\Core\ServiceProvider;
use FBM\REST\Controllers\HealthController;
use FBM\REST\RestServer;
use FBM\Security\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `fbm/v1` REST namespace and its controllers.
 */
final class RestServiceProvider extends ServiceProvider {
	/**
	 * Binds services into the container.
	 *
	 * Must not touch WordPress hooks or the database.
	 *
	 * @param ContainerInterface $container Plugin container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		$container->singleton(
			RestServer::class,
			static function ( ContainerInterface $c ): RestServer {
				return new RestServer( $c );
			}
		);

		$container->singleton(
			HealthController::class,
			static function ( ContainerInterface $c ): HealthController {
				return new HealthController( $c->get( Permissions::class ) );
			}
		);
	}

	/**
	 * Attaches WordPress hooks once every provider has been registered.
	 *
	 * @param ContainerInterface $container Plugin container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		$server = $container->get( RestServer::class );

		$server->add_controller( HealthController::class );
		$server->hooks();
	}
}
