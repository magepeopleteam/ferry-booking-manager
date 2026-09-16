<?php
/**
 * Base repository.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Repositories;

use FBM\Cache\CacheManager;
use FBM\Models\Entity;
use FBM\Support\Schema\EntitySchema;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Schema-driven persistence for a domain entity.
 *
 * This is the only layer in the plugin that talks to WordPress storage APIs.
 * Services and controllers never call get_posts(), get_post_meta() or
 * update_post_meta() directly, which keeps query shapes, cache invalidation and
 * meta priming in one reviewable place.
 *
 * Listings always fetch ids first and then prime post and meta caches in a
 * single pass, so rendering fifty rows costs two queries rather than fifty-one.
 */
abstract class AbstractRepository {

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	protected CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Returns the entity class this repository manages.
	 *
	 * @return class-string<Entity>
	 */
	abstract public function entity_class(): string;

	/**
	 * Returns the entity schema.
	 *
	 * @return EntitySchema
	 */
	public function schema(): EntitySchema {
		$class = $this->entity_class();

		return $class::schema();
	}

	/**
	 * Returns the post type.
	 *
	 * @return string
	 */
	public function post_type(): string {
		$class = $this->entity_class();

		return $class::post_type();
	}

	/**
	 * Loads a single entity.
	 *
	 * @param int $id Post id.
	 * @return Entity|null
	 */
	public function find( int $id ): ?Entity {
		if ( $id < 1 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || $post->post_type !== $this->post_type() ) {
			return null;
		}

		if ( 'trash' === $post->post_status ) {
			return null;
		}

		return $this->hydrate( $post );
	}

	/**
	 * Loads several entities in one pass, preserving the requested order.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, Entity> Keyed by id.
	 */
	public function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$this->prime( $ids );

		$entities = array();

		foreach ( $ids as $id ) {
			$entity = $this->find( $id );

			if ( null !== $entity ) {
				$entities[ $id ] = $entity;
			}
		}

		return $entities;
	}

	/**
	 * Runs a paginated query.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: Entity[], total: int, page: int, per_page: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
				'search'   => '',
				'status'   => '',
				'orderby'  => 'title',
				'order'    => 'asc',
				'include'  => array(),
				'exclude'  => array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Operator-scale listings (vessels, ports, routes), not a VIP-scale collection.
			)
		);

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$query_args = array(
			'post_type'              => $this->post_type(),
			'post_status'            => array( 'publish', 'draft' ),
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== (string) $args['search'] ) {
			$query_args['s'] = (string) $args['search'];
		}

		if ( array() !== (array) $args['include'] ) {
			$query_args['post__in'] = array_map( 'absint', (array) $args['include'] );
		}

		if ( array() !== (array) $args['exclude'] ) {
			$query_args['post__not_in'] = array_map( 'absint', (array) $args['exclude'] ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Operator-scale listings (vessels, ports, routes), not a VIP-scale collection.
		}

		$meta_query = $this->build_meta_query( $args );

		if ( array() !== $meta_query ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed scalar meta keys, paginated.
		}

		$query_args = $this->apply_order( $query_args, (string) $args['orderby'], (string) $args['order'] );

		/**
		 * Filters the WP_Query arguments used by a MagePeople Ferry Booking System listing.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $query_args WP_Query arguments.
		 * @param array<string, mixed> $args       Repository arguments.
		 * @param string               $post_type  Post type being queried.
		 */
		$query_args = (array) apply_filters( 'fbm_repository_query_args', $query_args, $args, $this->post_type() );

		$query = new WP_Query( $query_args );
		$ids   = array_map( 'absint', (array) $query->posts );

		$this->prime( $ids );

		$items = array();

		foreach ( $ids as $id ) {
			$entity = $this->find( $id );

			if ( null !== $entity ) {
				$items[] = $entity;
			}
		}

		return array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Returns only the ids matching a query, without hydrating entities.
	 *
	 * Used by aggregates — availability counts, report totals — where the
	 * records themselves are never read.
	 *
	 * @param array<string, mixed> $query_args Extra WP_Query arguments.
	 * @param int                  $limit      Maximum ids to return.
	 * @return int[]
	 */
	public function ids( array $query_args = array(), int $limit = 500 ): array {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'              => $this->post_type(),
					'post_status'            => array( 'publish', 'draft' ),
					'posts_per_page'         => $limit,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				),
				$query_args
			)
		);

		return array_map( 'absint', (array) $query->posts );
	}

	/**
	 * Creates or updates an entity from a sanitised attribute set.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param int                  $id         Existing post id, or 0 to create.
	 * @param string               $name       Display name; empty keeps the current title.
	 * @return Entity|WP_Error
	 */
	public function save( array $attributes, int $id = 0, string $name = '' ) {
		$class    = $this->entity_class();
		$existing = $id > 0 ? $this->find( $id ) : null;

		if ( $id > 0 && null === $existing ) {
			return new WP_Error(
				'fbm_not_found',
				__( 'The record could not be found.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 404 )
			);
		}

		/** @var Entity $entity */
		$entity = $existing ?? new $class();
		$entity->fill( $attributes );

		if ( '' !== $name ) {
			$entity->name = sanitize_text_field( $name );
		}

		if ( '' === $entity->name ) {
			$entity->name = $this->derive_name( $entity );
		}

		$entity->derive();

		$post_data = array(
			'post_type'    => $this->post_type(),
			'post_title'   => $entity->name,
			'post_excerpt' => $this->search_index( $entity ),
			'post_status'  => 'publish',
		);

		if ( $id > 0 ) {
			$post_data['ID'] = $id;
		} else {
			$post_data['post_author'] = get_current_user_id();
		}

		$result = $id > 0 ? wp_update_post( $post_data, true ) : wp_insert_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'fbm_save_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$post_id = (int) $result;

		foreach ( $this->schema()->meta_fields() as $field_name => $field ) {
			update_post_meta( $post_id, $field->meta_key, $field->to_storage( $entity->get( $field_name ) ) );
		}

		$this->write_index_meta( $post_id, $entity );

		$this->flush();

		$saved = $this->find( $post_id );

		if ( null === $saved ) {
			return new WP_Error(
				'fbm_save_failed',
				__( 'The record was saved but could not be read back.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a MagePeople Ferry Booking System entity is created or updated.
		 *
		 * @since 1.0.0
		 *
		 * @param Entity $saved   Saved entity.
		 * @param bool   $created Whether the entity was just created.
		 */
		do_action( 'fbm_' . $saved::key() . '_saved', $saved, 0 === $id );

		return $saved;
	}

	/**
	 * Deletes an entity.
	 *
	 * @param int  $id    Post id.
	 * @param bool $force Whether to bypass the trash.
	 * @return true|WP_Error
	 */
	public function delete( int $id, bool $force = false ) {
		$entity = $this->find( $id );

		if ( null === $entity ) {
			return new WP_Error(
				'fbm_not_found',
				__( 'The record could not be found.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 404 )
			);
		}

		$blocker = $this->deletion_blocker( $entity );

		if ( null !== $blocker ) {
			return $blocker;
		}

		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );

		if ( ! $result ) {
			return new WP_Error(
				'fbm_delete_failed',
				__( 'The record could not be deleted.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 500 )
			);
		}

		$this->flush();

		/**
		 * Fires after a MagePeople Ferry Booking System entity is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param Entity $entity Entity that was deleted.
		 * @param bool   $force  Whether the delete bypassed the trash.
		 */
		do_action( 'fbm_' . $entity::key() . '_deleted', $entity, $force );

		return true;
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
		unset( $entity );

		return array();
	}

	/**
	 * Rewrites the repeated index meta for an entity.
	 *
	 * @param int    $post_id Post id.
	 * @param Entity $entity  Entity being saved.
	 * @return void
	 */
	private function write_index_meta( int $post_id, Entity $entity ): void {
		foreach ( $this->index_meta( $entity ) as $meta_key => $values ) {
			delete_post_meta( $post_id, $meta_key );

			foreach ( array_unique( $values ) as $value ) {
				add_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Finds the record, if any, that already holds a value.
	 *
	 * Trashed records are included deliberately. Deletion moves a record to the
	 * trash rather than removing it, so allowing its code to be reused would
	 * mean restoring it later produces two records sharing one identifier —
	 * and that identifier is printed on tickets.
	 *
	 * @param string     $meta_key   Meta key storing the value.
	 * @param string|int $value      Value to look for.
	 * @param int        $exclude_id Record to ignore, when updating.
	 * @return int Conflicting post id, or 0 when the value is free.
	 */
	public function find_conflicting_id( string $meta_key, $value, int $exclude_id = 0 ): int {
		if ( '' === (string) $value ) {
			return 0;
		}

		$ids = $this->ids(
			array(
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft', 'trash' ),
				'post__not_in'   => $exclude_id > 0 ? array( $exclude_id ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single record excluded from a uniqueness check, not a bulk exclusion.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed scalar key, single-row lookup.
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => $value,
						'compare' => '=',
					),
				),
			)
		);

		return array() === $ids ? 0 : (int) $ids[0];
	}

	/**
	 * Counts records of another post type that reference this one.
	 *
	 * @param string     $post_type Referencing post type.
	 * @param string     $meta_key  Meta key holding the reference.
	 * @param int|string $value     Referenced value.
	 * @return int
	 */
	protected function count_references( string $post_type, string $meta_key, $value ): int {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed scalar key, count only.
				'meta_query'             => array(
					array(
						'key'     => $meta_key,
						'value'   => $value,
						'compare' => '=',
					),
				),
			)
		);

		return (int) $query->found_posts;
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
		unset( $entity );

		return null;
	}

	/**
	 * Builds the meta query for a listing.
	 *
	 * @param array<string, mixed> $args Repository arguments.
	 * @return array<int|string, mixed>
	 */
	protected function build_meta_query( array $args ): array {
		$meta_query = array();

		if ( '' !== (string) $args['status'] ) {
			$field = $this->schema()->field( 'status' );

			if ( null !== $field ) {
				$meta_query[] = array(
					'key'     => $field->meta_key,
					'value'   => sanitize_key( (string) $args['status'] ),
					'compare' => '=',
				);
			}
		}

		return $meta_query;
	}

	/**
	 * Applies ordering to the query arguments.
	 *
	 * @param array<string, mixed> $query_args WP_Query arguments.
	 * @param string               $orderby    Requested order key.
	 * @param string               $order      Requested direction.
	 * @return array<string, mixed>
	 */
	protected function apply_order( array $query_args, string $orderby, string $order ): array {
		$order                 = 'desc' === strtolower( $order ) ? 'DESC' : 'ASC';
		$query_args['order']   = $order;
		$query_args['orderby'] = 'title';

		switch ( $orderby ) {
			case 'created':
				$query_args['orderby'] = 'date';
				break;

			case 'updated':
				$query_args['orderby'] = 'modified';
				break;

			case 'id':
				$query_args['orderby'] = 'ID';
				break;

			case 'title':
			case 'name':
				$query_args['orderby'] = 'title';
				break;

			default:
				$field = $this->schema()->field( $orderby );

				if ( null !== $field && '' !== $field->meta_key ) {
					$numeric                = in_array( $field->rest_type(), array( 'integer', 'number' ), true );
					$query_args['meta_key'] = $field->meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Dedicated scalar key, paginated.
					$query_args['orderby']  = $numeric ? 'meta_value_num' : 'meta_value';
				}
		}

		return $query_args;
	}

	/**
	 * Builds the plain-text search index stored in the post excerpt.
	 *
	 * WordPress already searches the excerpt, so putting codes and references
	 * there makes them findable through a normal query with no custom SQL and
	 * no serialised-meta scanning.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return string
	 */
	protected function search_index( Entity $entity ): string {
		$parts = array();

		foreach ( $this->schema()->search_fields() as $name ) {
			$value = $entity->get( $name );

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$parts[] = (string) $value;
			}
		}

		return sanitize_text_field( implode( ' ', array_unique( $parts ) ) );
	}

	/**
	 * Produces a fallback display name for entities saved without one.
	 *
	 * @param Entity $entity Entity being saved.
	 * @return string
	 */
	protected function derive_name( Entity $entity ): string {
		$code = $entity->get( 'code' );

		if ( is_string( $code ) && '' !== $code ) {
			return $code;
		}

		return ucfirst( $entity::key() );
	}

	/**
	 * Hydrates an entity from a post and its primed meta.
	 *
	 * @param WP_Post $post Source post.
	 * @return Entity
	 */
	protected function hydrate( WP_Post $post ): Entity {
		$class = $this->entity_class();

		/** @var Entity $entity */
		$entity = new $class();
		$entity->hydrate_post( $post );

		foreach ( $this->schema()->meta_fields() as $name => $field ) {
			// The list form distinguishes "stored as empty" from "never stored",
			// which the single form collapses. A boolean the operator switched
			// off has to survive that distinction.
			$rows = get_post_meta( $post->ID, $field->meta_key, false );

			$entity->set( $name, $field->cast( is_array( $rows ) && array() !== $rows ? $rows[0] : null ) );
		}

		return $entity;
	}

	/**
	 * Primes the post and meta caches for a set of ids.
	 *
	 * @param int[] $ids Post ids.
	 * @return void
	 */
	protected function prime( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}

		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );
	}

	/**
	 * Invalidates the cache groups this entity participates in.
	 *
	 * @return void
	 */
	protected function flush(): void {
		$this->cache->flush_group( CacheManager::GROUP_ENTITIES );
		$this->cache->flush_group( CacheManager::GROUP_SEARCH );
		$this->cache->flush_group( CacheManager::GROUP_DASHBOARD );
	}
}
