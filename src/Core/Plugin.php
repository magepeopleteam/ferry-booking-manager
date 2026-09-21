<?php
/**
 * Plugin kernel.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Core;

use MPFBS\Contracts\ContainerInterface;
use MPFBS\Contracts\ServiceProviderInterface;

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
	 * Exposes the container so third-party extensions can resolve plugin services.
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
		 * Fires once the plugin container is fully booted.
		 *
		 * This is the supported entry point for integrations that need to
		 * resolve or decorate plugin services.
		 *
		 * @since 1.0.0
		 *
		 * @param ContainerInterface $container Plugin service container.
		 * @param Plugin             $plugin    Plugin kernel.
		 */
		do_action( 'mpfbs_booted', $this->container, $this );
	}

	/**
	 * Returns the ordered list of provider classes.
	 *
	 * @return string[]
	 */
	private function provider_classes(): array {
		$providers = array(
			\MPFBS\Providers\CoreServiceProvider::class,
			\MPFBS\Providers\SecurityServiceProvider::class,
			\MPFBS\Providers\RestServiceProvider::class,
			\MPFBS\Providers\DomainServiceProvider::class,
			\MPFBS\Providers\AdminServiceProvider::class,
			\MPFBS\Providers\FrontendServiceProvider::class,
		);

		/**
		 * Filters the service providers loaded by the plugin.
		 *
		 * Extensions register their own providers through this filter rather
		 * than by duplicating or replacing plugin services.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $providers Fully qualified provider class names, in load order.
		 */
		$providers = apply_filters( 'mpfbs_service_providers', $providers );

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
