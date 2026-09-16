<?php
/**
 * Vessel repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Models\Entity;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for vessels.
 */
final class VesselRepository extends AbstractRepository {
	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	public function entity_class(): string {
		return Vessel::class;
	}

	/**
	 * Returns an error when an entity must not be deleted.
	 *
	 * Concrete repositories override this to protect referential integrity —
	 * a port still used by a route, a sailing that already has bookings.
	 *
	 * A vessel assigned to a sailing carries that sailing's capacity, so removing
	 * it would silently invalidate availability for every booking on board.
	 *
	 * @param Entity $entity Entity being deleted.
	 * @return WP_Error|null
	 */
	protected function deletion_blocker( Entity $entity ): ?WP_Error {
		$sailings = $this->count_references( Sailing::POST_TYPE, '_fbm_vessel_id', $entity->id );

		if ( $sailings > 0 ) {
			return new WP_Error(
				'fbm_vessel_in_use',
				sprintf(
					/* translators: %d: number of sailings. */
					_n(
						'This vessel is assigned to %d sailing and cannot be deleted. Reassign or cancel that sailing first.',
						'This vessel is assigned to %d sailings and cannot be deleted. Reassign or cancel those sailings first.',
						$sailings,
						'magepeople-ferry-booking-system'
					),
					$sailings
				),
				array( 'status' => 409 )
			);
		}

		$routes = $this->count_references( Route::POST_TYPE, '_fbm_default_vessel', $entity->id );

		if ( $routes > 0 ) {
			return new WP_Error(
				'fbm_vessel_in_use',
				sprintf(
					/* translators: %d: number of routes. */
					_n(
						'This vessel is the default for %d route and cannot be deleted.',
						'This vessel is the default for %d routes and cannot be deleted.',
						$routes,
						'magepeople-ferry-booking-system'
					),
					$routes
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Returns active vessels as id => name, for pickers.
	 *
	 * @return array<int, string>
	 */
	public function options(): array {
		$result  = $this->query(
			array(
				'per_page' => 100,
				'status'   => Vessel::STATUS_ACTIVE,
				'orderby'  => 'title',
				'order'    => 'asc',
			)
		);
		$options = array();

		foreach ( $result['items'] as $vessel ) {
			$options[ $vessel->id ] = $vessel->name;
		}

		return $options;
	}
}
