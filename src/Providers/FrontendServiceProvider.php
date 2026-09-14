<?php
/**
 * Front-end service provider.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Providers;

use FBM\Booking\BookingPresenter;
use FBM\Booking\PartyPresenter;
use FBM\Booking\BookingService;
use FBM\Contracts\ContainerInterface;
use FBM\Core\ServiceProvider;
use FBM\Frontend\Assets;
use FBM\Frontend\Components;
use FBM\Frontend\Pages;
use FBM\Notification\NotificationService;
use FBM\Payment\PaymentGatewayRegistry;
use FBM\Repositories\BookingRepository;
use FBM\REST\Controllers\BookingController;
use FBM\REST\Controllers\CustomerController;
use FBM\REST\Controllers\SearchController;
use FBM\REST\Controllers\SettingsController;
use FBM\REST\RestServer;
use FBM\Search\SearchService;
use FBM\Security\Permissions;
use FBM\WooCommerce\WooCommerceIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the customer-facing side of the plugin: the booking application,
 * its pages, its REST endpoints and the notifications they trigger.
 */
final class FrontendServiceProvider extends ServiceProvider {

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
			PaymentGatewayRegistry::class,
			static function (): PaymentGatewayRegistry {
				return new PaymentGatewayRegistry();
			}
		);

		$container->singleton(
			Pages::class,
			static function (): Pages {
				return new Pages();
			}
		);

		$container->singleton(
			Assets::class,
			static function (): Assets {
				return new Assets();
			}
		);

		$container->singleton(
			Components::class,
			static function ( ContainerInterface $c ): Components {
				return new Components( $c->get( Assets::class ), $c->get( Pages::class ) );
			}
		);

		$container->singleton(
			SearchService::class,
			static function ( ContainerInterface $c ): SearchService {
				return new SearchService(
					$c->get( \FBM\Repositories\SailingRepository::class ),
					$c->get( \FBM\Repositories\RouteRepository::class ),
					$c->get( \FBM\Repositories\VesselRepository::class ),
					$c->get( \FBM\Repositories\PortRepository::class ),
					$c->get( \FBM\Availability\AvailabilityService::class ),
					$c->get( \FBM\Pricing\PricingService::class ),
					$c->get( \FBM\Cache\CacheManager::class )
				);
			}
		);

		$container->singleton(
			NotificationService::class,
			static function ( ContainerInterface $c ): NotificationService {
				return new NotificationService(
					$c->get( \FBM\Repositories\SailingRepository::class ),
					$c->get( \FBM\Repositories\RouteRepository::class ),
					$c->get( \FBM\Repositories\VesselRepository::class )
				);
			}
		);

		$container->singleton(
			BookingService::class,
			static function ( ContainerInterface $c ): BookingService {
				return new BookingService(
					$c->get( BookingRepository::class ),
					$c->get( \FBM\Repositories\SailingRepository::class ),
					$c->get( \FBM\Repositories\RouteRepository::class ),
					$c->get( \FBM\Repositories\VesselRepository::class ),
					$c->get( \FBM\Repositories\PassengerTypeRepository::class ),
					$c->get( \FBM\Repositories\VehicleTypeRepository::class ),
					$c->get( \FBM\Pricing\PricingService::class ),
					$c->get( \FBM\Availability\AvailabilityService::class ),
					$c->get( \FBM\Availability\HoldManager::class ),
					$c->get( PaymentGatewayRegistry::class ),
					$c->get( NotificationService::class ),
					$c->get( \FBM\Contracts\LoggerInterface::class )
				);
			}
		);

		$container->singleton(
			WooCommerceIntegration::class,
			static function ( ContainerInterface $c ): WooCommerceIntegration {
				return new WooCommerceIntegration(
					$c->get( BookingService::class ),
					$c->get( BookingRepository::class ),
					$c->get( \FBM\Contracts\LoggerInterface::class )
				);
			}
		);

		$container->singleton(
			SearchController::class,
			static function ( ContainerInterface $c ): SearchController {
				return new SearchController(
					$c->get( Permissions::class ),
					$c->get( SearchService::class ),
					$c->get( \FBM\Repositories\PassengerTypeRepository::class ),
					$c->get( \FBM\Repositories\VehicleTypeRepository::class )
				);
			}
		);

		$container->singleton(
			BookingPresenter::class,
			static function ( ContainerInterface $c ): BookingPresenter {
				return new BookingPresenter(
					$c->get( \FBM\Repositories\SailingRepository::class ),
					$c->get( \FBM\Repositories\RouteRepository::class ),
					$c->get( \FBM\Repositories\PortRepository::class ),
					$c->get( \FBM\Repositories\VesselRepository::class )
				);
			}
		);

		$container->singleton(
			PartyPresenter::class,
			static function ( ContainerInterface $c ): PartyPresenter {
				return new PartyPresenter(
					$c->get( \FBM\Repositories\PassengerTypeRepository::class ),
					$c->get( \FBM\Repositories\VehicleTypeRepository::class )
				);
			}
		);

		$container->singleton(
			BookingController::class,
			static function ( ContainerInterface $c ): BookingController {
				return new BookingController(
					$c->get( Permissions::class ),
					$c->get( BookingService::class ),
					$c->get( BookingRepository::class ),
					$c->get( BookingPresenter::class ),
					$c->get( PartyPresenter::class )
				);
			}
		);

		$container->singleton(
			CustomerController::class,
			static function ( ContainerInterface $c ): CustomerController {
				return new CustomerController( $c->get( Permissions::class ) );
			}
		);

		$container->singleton(
			SettingsController::class,
			static function ( ContainerInterface $c ): SettingsController {
				return new SettingsController(
					$c->get( Permissions::class ),
					$c->get( PaymentGatewayRegistry::class ),
					$c->get( Pages::class )
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
		$container->get( Pages::class )->hooks();
		$container->get( Components::class )->hooks();
		$container->get( Assets::class )->hooks();
		$container->get( WooCommerceIntegration::class )->hooks();

		$server = $container->get( RestServer::class );

		$server->add_controller( SearchController::class );
		$server->add_controller( BookingController::class );
		$server->add_controller( CustomerController::class );
		$server->add_controller( SettingsController::class );
	}
}
