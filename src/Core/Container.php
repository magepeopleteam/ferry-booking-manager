<?php
/**
 * Service container.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Contracts\ContainerInterface;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Small, explicit dependency injection container.
 * Deliberately free of reflection-based auto-wiring: every binding is declared in a
 * service provider, which keeps resolution cheap and the dependency graph readable.
 */
final class Container implements ContainerInterface {
	/**
	 * Registered factories.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Identifiers that should only ever be resolved once.
	 *
	 * @var array<string, bool>
	 */
	private array $shared = array();

	/**
	 * Resolved shared instances.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Identifiers currently being resolved, used for cycle detection.
	 *
	 * @var array<string, bool>
	 */
	private array $resolving = array();

	/**
	 * Registers a factory that is resolved on every request.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory receiving the container.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->shared[ $id ], $this->resolved[ $id ] );
	}

	/**
	 * Registers a factory whose result is cached for the request lifetime.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory receiving the container.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Registers an already-constructed service.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $instance Service instance.
	 * @return void
	 */
	public function instance( string $id, $instance ): void {
		$this->resolved[ $id ] = $instance;
		$this->shared[ $id ]   = true;
		unset( $this->factories[ $id ] );
	}

	/**
	 * Resolves a service.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws InvalidArgumentException When the identifier is unknown or circular.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Ferry Booking Manager: service "%s" is not registered.', esc_html( $id ) )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Ferry Booking Manager: circular dependency detected while resolving "%s".', esc_html( $id ) )
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$object = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( isset( $this->shared[ $id ] ) ) {
			$this->resolved[ $id ] = $object;
		}

		return $object;
	}

	/**
	 * Determines whether a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->resolved );
	}

	/**
	 * Lists every registered service identifier.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_values( array_unique( array_merge( array_keys( $this->factories ), array_keys( $this->resolved ) ) ) );
	}
}
