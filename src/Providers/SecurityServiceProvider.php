<?php
/**
 * Security service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Providers;

use FBM\Contracts\ContainerInterface;
use FBM\Core\ServiceProvider;
use FBM\Security\Permissions;
use FBM\Security\RateLimiter;
use FBM\Security\RoleSettings;
use FBM\Security\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers capability and role services.
 */
final class SecurityServiceProvider extends ServiceProvider {
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
			RateLimiter::class,
			static function (): RateLimiter {
				return new RateLimiter();
			}
		);

		$container->singleton(
			Permissions::class,
			static function ( ContainerInterface $c ): Permissions {
				return new Permissions( $c->get( RateLimiter::class ) );
			}
		);

		$container->singleton(
			RoleSettings::class,
			static function (): RoleSettings {
				return new RoleSettings();
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
		// The overrides filter has to be attached before anything asks what a
		// role may do, including the refresh below.
		$roles = $container->get( RoleSettings::class );

		if ( $roles instanceof RoleSettings ) {
			$roles->hooks();
		}

		// Late priority so Pro has already registered its capabilities.
		add_action( 'admin_init', array( Roles::class, 'maybe_refresh' ), 20 );
	}
}
