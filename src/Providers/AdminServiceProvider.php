<?php
/**
 * Admin service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Providers;

use FBM\Admin\AppRenderer;
use FBM\Admin\Menu;
use FBM\Contracts\ContainerInterface;
use FBM\Contracts\LoggerInterface;
use FBM\Core\Assets;
use FBM\Core\ServiceProvider;
use FBM\Security\Permissions;

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
