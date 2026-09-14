<?php
/**
 * Core service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Providers;

use FBM\Cache\CacheManager;
use FBM\Contracts\ContainerInterface;
use FBM\Contracts\LoggerInterface;
use FBM\Core\Activator;
use FBM\Core\ServiceProvider;
use FBM\Settings\SettingsBridge;
use FBM\Support\Logger;
use FBM\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the infrastructure services every other provider depends on.
 */
final class CoreServiceProvider extends ServiceProvider {
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
			LoggerInterface::class,
			static function (): LoggerInterface {
				return new Logger();
			}
		);

		$container->singleton(
			CacheManager::class,
			static function (): CacheManager {
				return new CacheManager();
			}
		);

		$container->singleton(
			SettingsBridge::class,
			static function (): SettingsBridge {
				return new SettingsBridge();
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
		add_action(
			Activator::MAINTENANCE_HOOK,
			static function () use ( $container ): void {
				$logger = $container->get( LoggerInterface::class );

				if ( $logger instanceof Logger ) {
					$logger->purge_expired();
				}

				/**
				 * Fires during the daily Ferry Booking Manager maintenance run.
				 *
				 * @since 1.0.0
				 *
				 * @param ContainerInterface $container Plugin container.
				 */
				do_action( 'fbm_daily_maintenance_run', $container );
			}
		);

		$bridge = $container->get( SettingsBridge::class );

		if ( $bridge instanceof SettingsBridge ) {
			$bridge->hooks();
		}

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Runs version-change housekeeping.
	 *
	 * Keeps a plugin updated by FTP or by a host's auto-updater in the same state
	 * as one activated through wp-admin, without a separate migration step.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$stored = Options::get_string( 'version', '' );

		if ( FBM_VERSION === $stored ) {
			return;
		}

		\FBM\Security\Roles::install();

		Options::set( 'version', FBM_VERSION, true );
		Options::set( 'needs_rewrite_flush', 1 );

		/**
		 * Fires when the stored plugin version differs from the running version.
		 *
		 * @since 1.0.0
		 *
		 * @param string $from Previously stored version, empty on first run.
		 * @param string $to   Version now running.
		 */
		do_action( 'fbm_upgraded', $stored, FBM_VERSION );
	}
}
