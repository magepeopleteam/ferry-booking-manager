<?php
/**
 * Service container contract.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the plugin service container.
 */
interface ContainerInterface {

	/**
	 * Registers a factory that is resolved on every request.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory receiving the container.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void;

	/**
	 * Registers a factory whose result is cached for the request lifetime.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory receiving the container.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void;

	/**
	 * Registers an already-constructed service.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $instance Service instance.
	 * @return void
	 */
	public function instance( string $id, $instance ): void;

	/**
	 * Resolves a service.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 */
	public function get( string $id );

	/**
	 * Determines whether a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool;
}
