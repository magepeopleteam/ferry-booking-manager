<?php
/**
 * Domain service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Providers;

use FBM\Availability\AvailabilityService;
use FBM\Availability\HoldManager;
use FBM\Cache\CacheManager;
use FBM\Contracts\ContainerInterface;
use FBM\Contracts\LoggerInterface;
use FBM\Core\PostTypes;
use FBM\Core\Seeder;
use FBM\Dashboard\MetricsService;
use FBM\Demo\DemoContent;
use FBM\Core\ServiceProvider;
use FBM\Repositories\BookingRepository;
use FBM\Repositories\PassengerTypeRepository;
use FBM\Repositories\PortRepository;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VehicleTypeRepository;
use FBM\Repositories\VesselRepository;
use FBM\REST\Controllers\AvailabilityController;
use FBM\REST\Controllers\DashboardController;
use FBM\REST\Controllers\DemoController;
use FBM\REST\Controllers\FieldConfigController;
use FBM\REST\Controllers\PassengerTypeController;
use FBM\Frontend\Pages;
use FBM\Pricing\PricingService;
use FBM\REST\Controllers\PortController;
use FBM\REST\Controllers\PricingController;
use FBM\REST\Controllers\ReferenceController;
use FBM\REST\Controllers\RouteController;
use FBM\REST\Controllers\SailingController;
use FBM\REST\Controllers\SetupController;
use FBM\REST\Controllers\VehicleTypeController;
use FBM\REST\Controllers\VesselController;
use FBM\REST\RestServer;
use FBM\Sailing\ScheduleGenerator;
use FBM\Security\Permissions;
use FBM\Setup\SetupStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the storage layer: post types, repositories and their endpoints.
 */
final class DomainServiceProvider extends ServiceProvider {
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
			PostTypes::class,
			static function ( ContainerInterface $c ): PostTypes {
				return new PostTypes( $c->get( Permissions::class ) );
			}
		);

		$repositories = array(
			PortRepository::class,
			VesselRepository::class,
			RouteRepository::class,
			SailingRepository::class,
			PassengerTypeRepository::class,
			VehicleTypeRepository::class,
			BookingRepository::class,
		);

		foreach ( $repositories as $repository ) {
			$container->singleton(
				$repository,
				static function ( ContainerInterface $c ) use ( $repository ) {
					return new $repository( $c->get( CacheManager::class ) );
				}
			);
		}

		$controllers = array(
			PortController::class          => PortRepository::class,
			VesselController::class        => VesselRepository::class,
			RouteController::class         => RouteRepository::class,

			PassengerTypeController::class => PassengerTypeRepository::class,
			VehicleTypeController::class   => VehicleTypeRepository::class,
		);

		foreach ( $controllers as $controller => $repository ) {
			$container->singleton(
				$controller,
				static function ( ContainerInterface $c ) use ( $controller, $repository ) {
					return new $controller( $c->get( Permissions::class ), $c->get( $repository ) );
				}
			);
		}

		$container->singleton(
			AvailabilityService::class,
			static function ( ContainerInterface $c ): AvailabilityService {
				return new AvailabilityService(
					$c->get( SailingRepository::class ),
					$c->get( VesselRepository::class ),
					$c->get( BookingRepository::class ),
					$c->get( CacheManager::class )
				);
			}
		);

		$container->singleton(
			HoldManager::class,
			static function ( ContainerInterface $c ): HoldManager {
				return new HoldManager(
					$c->get( BookingRepository::class ),
					$c->get( SailingRepository::class ),
					$c->get( AvailabilityService::class ),
					$c->get( LoggerInterface::class )
				);
			}
		);

		$container->singleton(
			AvailabilityController::class,
			static function ( ContainerInterface $c ): AvailabilityController {
				return new AvailabilityController(
					$c->get( Permissions::class ),
					$c->get( AvailabilityService::class ),
					$c->get( SailingRepository::class )
				);
			}
		);

		$container->singleton(
			PricingService::class,
			static function ( ContainerInterface $c ): PricingService {
				return new PricingService(
					$c->get( SailingRepository::class ),
					$c->get( RouteRepository::class ),
					$c->get( PassengerTypeRepository::class ),
					$c->get( VehicleTypeRepository::class )
				);
			}
		);

		$container->singleton(
			PricingController::class,
			static function ( ContainerInterface $c ): PricingController {
				return new PricingController(
					$c->get( Permissions::class ),
					$c->get( PricingService::class ),
					$c->get( AvailabilityService::class )
				);
			}
		);

		$container->singleton(
			MetricsService::class,
			static function ( ContainerInterface $c ): MetricsService {
				return new MetricsService(
					$c->get( BookingRepository::class ),
					$c->get( SailingRepository::class ),
					$c->get( RouteRepository::class ),
					$c->get( VesselRepository::class ),
					$c->get( AvailabilityService::class ),
					$c->get( CacheManager::class )
				);
			}
		);

		$container->singleton(
			DashboardController::class,
			static function ( ContainerInterface $c ): DashboardController {
				return new DashboardController( $c->get( Permissions::class ), $c->get( MetricsService::class ) );
			}
		);

		$container->singleton(
			DemoContent::class,
			static function ( ContainerInterface $c ): DemoContent {
				return new DemoContent(
					$c->get( PortRepository::class ),
					$c->get( VesselRepository::class ),
					$c->get( RouteRepository::class ),
					$c->get( SailingRepository::class ),
					$c->get( PassengerTypeRepository::class ),
					$c->get( VehicleTypeRepository::class )
				);
			}
		);

		$container->singleton(
			DemoController::class,
			static function ( ContainerInterface $c ): DemoController {
				return new DemoController( $c->get( Permissions::class ), $c->get( DemoContent::class ) );
			}
		);

		$container->singleton(
			SetupStatus::class,
			static function ( ContainerInterface $c ): SetupStatus {
				return new SetupStatus(
					$c->get( PricingService::class ),
					$c->get( PassengerTypeRepository::class ),
					$c->get( Pages::class ),
					$c->get( DemoContent::class )
				);
			}
		);

		$container->singleton(
			SetupController::class,
			static function ( ContainerInterface $c ): SetupController {
				return new SetupController( $c->get( Permissions::class ), $c->get( SetupStatus::class ) );
			}
		);

		$container->singleton(
			Seeder::class,
			static function ( ContainerInterface $c ): Seeder {
				return new Seeder( $c->get( PassengerTypeRepository::class ), $c->get( VehicleTypeRepository::class ) );
			}
		);

		$container->singleton(
			ScheduleGenerator::class,
			static function ( ContainerInterface $c ): ScheduleGenerator {
				return new ScheduleGenerator( $c->get( SailingRepository::class ), $c->get( RouteRepository::class ) );
			}
		);

		$container->singleton(
			SailingController::class,
			static function ( ContainerInterface $c ): SailingController {
				return new SailingController(
					$c->get( Permissions::class ),
					$c->get( SailingRepository::class ),
					$c->get( RouteRepository::class ),
					$c->get( VesselRepository::class ),
					$c->get( ScheduleGenerator::class )
				);
			}
		);

		$container->singleton(
			FieldConfigController::class,
			static function ( ContainerInterface $c ): FieldConfigController {
				return new FieldConfigController( $c->get( Permissions::class ) );
			}
		);

		$container->singleton(
			ReferenceController::class,
			static function ( ContainerInterface $c ): ReferenceController {
				return new ReferenceController(
					$c->get( Permissions::class ),
					$c->get( PortRepository::class ),
					$c->get( VesselRepository::class ),
					$c->get( RouteRepository::class ),
					$c->get( PassengerTypeRepository::class ),
					$c->get( VehicleTypeRepository::class )
				);
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
		$container->get( PostTypes::class )->hooks();
		$container->get( Seeder::class )->hooks();
		$container->get( SetupStatus::class )->hooks();
		$container->get( HoldManager::class )->hooks();

		/*
		 * Availability is derived from bookings, so any write to one — from the
		 * dashboard, the booking engine, WP-CLI or an import — has to drop the
		 * snapshot. Hooking the repository's own save action means no write path
		 * can forget to.
		 */
		$availability = $container->get( AvailabilityService::class );

		add_action(
			'fbm_booking_saved',
			static function ( $booking ) use ( $availability ): void {
				$availability->invalidate( (int) $booking->get( 'sailing_id' ) );
			}
		);

		add_action(
			'fbm_booking_deleted',
			static function () use ( $availability ): void {
				$availability->invalidate();
			}
		);

		add_action(
			'fbm_sailing_saved',
			static function ( $sailing ) use ( $availability ): void {
				$availability->invalidate( (int) $sailing->id );
			}
		);

		$server = $container->get( RestServer::class );

		$controllers = array(
			PortController::class,
			VesselController::class,
			RouteController::class,
			SailingController::class,
			PassengerTypeController::class,
			VehicleTypeController::class,
			FieldConfigController::class,
			AvailabilityController::class,
			DashboardController::class,
			DemoController::class,
			SetupController::class,
			PricingController::class,
			ReferenceController::class,
		);

		foreach ( $controllers as $controller ) {
			$server->add_controller( $controller );
		}
	}
}
