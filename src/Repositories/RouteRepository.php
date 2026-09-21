<?php
/**
 * Route repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Repositories;

use MPFBS\Models\Entity;
use MPFBS\Models\Route;
use MPFBS\Models\Sailing;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for routes.
 */
final class RouteRepository extends AbstractRepository {
	/**
	 * Repeated meta key listing every port a route calls at.
	 */
	public const PORT_INDEX = '_mpfbs_route_port';

	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return Route::class;
	}

	/**
	 * Returns repeated meta rows that make list-valued properties queryable.
	 *
	 * A serialised array cannot be searched with an indexed query, so every
	 * relationship the system needs to look up in reverse — "which routes serve
	 * this port?" — is mirrored into repeated scalar meta rows alongside the
	 * authored list.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return array<string, array<int|string>> Meta key => values.
	 */
	protected function index_meta( Entity $entity ): array {
		$ports = array_merge(
			array( (int) $entity->get( 'origin_port' ), (int) $entity->get( 'destination_port' ) ),
			array_map( 'intval', (array) $entity->get( 'intermediate_ports' ) )
		);

		return array(
			self::PORT_INDEX => array_values( array_filter( $ports ) ),
		);
	}

	/**
	 * Returns an error when an entity must not be deleted.
	 *
	 * Concrete repositories override this to protect referential integrity —
	 * a port still used by a route, a sailing that already has bookings.
	 *
	 * @param Entity $entity Entity being deleted.
	 * @return WP_Error|null
	 */
	protected function deletion_blocker( Entity $entity ): ?WP_Error {
		$sailings = $this->count_references( Sailing::POST_TYPE, '_mpfbs_route_id', $entity->id );

		if ( $sailings > 0 ) {
			return new WP_Error(
				'mpfbs_route_in_use',
				sprintf(
					/* translators: %d: number of sailings. */
					_n(
						'This route has %d scheduled sailing and cannot be deleted. Remove the sailing first.',
						'This route has %d scheduled sailings and cannot be deleted. Remove those sailings first.',
						$sailings,
						'magepeople-ferry-booking-system'
					),
					$sailings
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Builds the meta query for a listing.
	 *
	 * @param array<string, mixed> $args Repository arguments.
	 * @return array<int|string, mixed>
	 */
	protected function build_meta_query( array $args ): array {
		$meta_query = parent::build_meta_query( $args );

		if ( ! empty( $args['origin_port'] ) ) {
			$meta_query[] = array(
				'key'     => '_mpfbs_origin_port',
				'value'   => (int) $args['origin_port'],
				'compare' => '=',
			);
		}

		if ( ! empty( $args['destination_port'] ) ) {
			$meta_query[] = array(
				'key'     => '_mpfbs_destination_port',
				'value'   => (int) $args['destination_port'],
				'compare' => '=',
			);
		}

		if ( ! empty( $args['serves_port'] ) ) {
			$meta_query[] = array(
				'key'     => self::PORT_INDEX,
				'value'   => (int) $args['serves_port'],
				'compare' => '=',
			);
		}

		return $meta_query;
	}

	/**
	 * Finds active routes between two ports.
	 *
	 * @param int $origin      Origin port id.
	 * @param int $destination Destination port id.
	 * @return Route[]
	 */
	public function between( int $origin, int $destination ): array {
		if ( $origin < 1 || $destination < 1 ) {
			return array();
		}

		$result = $this->query(
			array(
				'per_page'         => 100,
				'status'           => Route::STATUS_ACTIVE,
				'origin_port'      => $origin,
				'destination_port' => $destination,
			)
		);

		/** @var Route[] $routes */
		$routes = $result['items'];

		return $routes;
	}

	/**
	 * Names a route after its terminals when no code was supplied.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return string
	 */
	protected function derive_name( Entity $entity ): string {
		$origin      = get_the_title( (int) $entity->get( 'origin_port' ) );
		$destination = get_the_title( (int) $entity->get( 'destination_port' ) );

		if ( '' !== $origin && '' !== $destination ) {
			return sprintf( '%s - %s', $origin, $destination );
		}

		return parent::derive_name( $entity );
	}
}
