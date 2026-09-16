<?php
/**
 * Plugin kernel.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Contracts\ContainerInterface;
use FBM\Contracts\ServiceProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin: owns the container, the provider list and the boot lifecycle.
 *
 * The kernel is intentionally thin. It knows how to wire providers together and
 * nothing about ferries, bookings or pricing.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Registered provider instances.
	 *
	 * @var ServiceProviderInterface[]
	 */
	private array $providers = array();

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor; use {@see Plugin::instance()}.
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Returns the shared kernel instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Exposes the container so Pro and third parties can resolve Free services.
	 *
	 * @return ContainerInterface
	 */
	public function container(): ContainerInterface {
		return $this->container;
	}

	/**
	 * Registers every provider and attaches WordPress hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->container->instance( 'plugin', $this );
		$this->container->instance( 'container', $this->container );

		foreach ( $this->provider_classes() as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$provider = new $class_name();

			if ( ! $provider instanceof ServiceProviderInterface ) {
				continue;
			}

			$provider->register( $this->container );
			$this->providers[ $class_name ] = $provider;
		}

		foreach ( $this->providers as $provider ) {
			$provider->boot( $this->container );
		}

		/**
		 * Fires once the Free plugin container is fully booted.
		 *
		 * This is the supported entry point for MagePeople Ferry Booking System Pro and for
		 * third-party integrations that need to resolve or decorate Free services.
		 *
		 * @since 1.0.0
		 *
		 * @param ContainerInterface $container Plugin service container.
		 * @param Plugin             $plugin    Plugin kernel.
		 */
		do_action( 'fbm_booted', $this->container, $this );
	}

	/**
	 * Returns the ordered list of provider classes.
	 *
	 * @return string[]
	 */
	private function provider_classes(): array {
		$providers = array(
			\FBM\Providers\CoreServiceProvider::class,
			\FBM\Providers\SecurityServiceProvider::class,
			\FBM\Providers\RestServiceProvider::class,
			\FBM\Providers\DomainServiceProvider::class,
			\FBM\Providers\AdminServiceProvider::class,
			\FBM\Providers\FrontendServiceProvider::class,
		);

		/**
		 * Filters the service providers loaded by the Free plugin.
		 *
		 * Pro registers its own providers through this filter rather than by
		 * duplicating or replacing Free services.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $providers Fully qualified provider class names, in load order.
		 */
		$providers = apply_filters( 'fbm_service_providers', $providers );

		return array_values( array_filter( (array) $providers, 'is_string' ) );
	}

	/**
	 * Prevents cloning of the kernel.
	 *
	 * @return void
	 */
	public function __clone() {
		_doing_it_wrong( __METHOD__, esc_html__( 'The MagePeople Ferry Booking System kernel cannot be cloned.', 'magepeople-ferry-booking-system' ), '1.0.0' );
	}

	/**
	 * Prevents unserialising the kernel.
	 *
	 * @return void
	 */
	public function __wakeup() {
		_doing_it_wrong( __METHOD__, esc_html__( 'The MagePeople Ferry Booking System kernel cannot be unserialised.', 'magepeople-ferry-booking-system' ), '1.0.0' );
	}
}
