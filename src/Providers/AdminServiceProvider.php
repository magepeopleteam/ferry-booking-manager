<?php
/**
 * Admin service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Providers;

use MPFBS\Admin\AppRenderer;
use MPFBS\Admin\Menu;
use MPFBS\Contracts\ContainerInterface;
use MPFBS\Contracts\LoggerInterface;
use MPFBS\Core\Assets;
use MPFBS\Core\ServiceProvider;
use MPFBS\Security\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the single-page dashboard.
 * Nothing here is constructed on a front-end request.
 */
final class AdminServiceProvider extends ServiceProvider {
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
			AppRenderer::class,
			static function ( ContainerInterface $c ): AppRenderer {
				return new AppRenderer( $c->get( LoggerInterface::class ) );
			}
		);

		$container->singleton(
			Assets::class,
			static function ( ContainerInterface $c ): Assets {
				return new Assets( $c->get( AppRenderer::class ), $c->get( Permissions::class ) );
			}
		);

		$container->singleton(
			Menu::class,
			static function ( ContainerInterface $c ): Menu {
				return new Menu( $c->get( AppRenderer::class ), $c->get( Assets::class ) );
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
		if ( ! is_admin() ) {
			return;
		}

		$container->get( Menu::class )->hooks();
	}
}
