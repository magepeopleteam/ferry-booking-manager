<?php
/**
 * Port repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Models\Entity;
use FBM\Models\Port;
use FBM\Models\Route;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for ports.
 */
final class PortRepository extends AbstractRepository {
	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return Port::class;
	}

	/**
	 * Returns an error when an entity must not be deleted.
	 *
	 * Concrete repositories override this to protect referential integrity —
	 * a port still used by a route, a sailing that already has bookings.
	 *
	 * A port that a route still calls at cannot be removed: deleting it would
	 * leave sailings pointing at a terminal that no longer exists.
	 *
	 * @param Entity $entity Entity being deleted.
	 * @return WP_Error|null
	 */
	protected function deletion_blocker( Entity $entity ): ?WP_Error {
		$references = $this->count_references( Route::POST_TYPE, '_fbm_route_port', $entity->id );

		if ( $references > 0 ) {
			return new WP_Error(
				'fbm_port_in_use',
				sprintf(
					/* translators: %d: number of routes. */
					_n(
						'This port is used by %d route and cannot be deleted. Remove it from the route first.',
						'This port is used by %d routes and cannot be deleted. Remove it from those routes first.',
						$references,
						'magepeople-ferry-booking-system'
					),
					$references
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Returns active ports as id => name, for pickers.
	 *
	 * @return array<int, string>
	 */
	public function options(): array {
		$result  = $this->query(
			array(
				'per_page' => 100,
				'status'   => Port::STATUS_ACTIVE,
				'orderby'  => 'title',
				'order'    => 'asc',
			)
		);
		$options = array();

		foreach ( $result['items'] as $port ) {
			$options[ $port->id ] = $port->name;
		}

		return $options;
	}
}
