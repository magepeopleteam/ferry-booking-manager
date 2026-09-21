<?php
/**
 * Base domain entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Models;

use MPFBS\Support\Schema\EntitySchema;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A hydrated domain record.
 *
 * Entities are plain data carriers. They know how to describe themselves (their
 * schema) and how to present themselves (serialisation), but they never touch
 * the database — that is the repository's job.
 */
abstract class Entity {

	/**
	 * Post id, or 0 for an unsaved entity.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Display name, stored as the post title.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Post slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Creation timestamp in MySQL GMT format.
	 *
	 * @var string
	 */
	public string $created = '';

	/**
	 * Last modification timestamp in MySQL GMT format.
	 *
	 * @var string
	 */
	public string $updated = '';

	/**
	 * Author user id.
	 *
	 * @var int
	 */
	public int $author = 0;

	/**
	 * Field values keyed by property name.
	 *
	 * @var array<string, mixed>
	 */
	protected array $attributes = array();

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	abstract public static function key(): string;

	/**
	 * Returns the post type storing the entity.
	 *
	 * @return string
	 */
	abstract public static function post_type(): string;

	/**
	 * Returns the capability required to write the entity.
	 *
	 * @return string
	 */
	abstract public static function capability(): string;

	/**
	 * Returns the raw field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract public static function definition(): array;

	/**
	 * Returns the plural and singular labels for the entity's post type.
	 *
	 * Overridden by every shipped entity so the labels are translatable. The
	 * fallback exists so an add-on that registers an entity without them still
	 * gets a working post type rather than none at all.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		$name = ucwords( str_replace( '_', ' ', static::key() ) );

		return array(
			'plural'   => $name,
			'singular' => $name,
		);
	}

	/**
	 * Returns the memoised schema for this entity.
	 *
	 * @return EntitySchema
	 */
	public static function schema(): EntitySchema {
		static $schemas = array();

		$class = static::class;

		if ( ! isset( $schemas[ $class ] ) ) {
			$schemas[ $class ] = new EntitySchema(
				static::key(),
				static::post_type(),
				static::capability(),
				static::definition()
			);
		}

		return $schemas[ $class ];
	}

	/**
	 * Reads a field value, falling back to the field default.
	 *
	 * @param string $field Property name.
	 * @return mixed
	 */
	public function get( string $field ) {
		if ( array_key_exists( $field, $this->attributes ) ) {
			return $this->attributes[ $field ];
		}

		$definition = static::schema()->field( $field );

		return $definition ? $definition->default : null;
	}

	/**
	 * Writes a field value without sanitisation.
	 *
	 * Callers are expected to pass values that already went through the schema.
	 *
	 * @param string $field Property name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set( string $field, $value ): void {
		$this->attributes[ $field ] = $value;
	}

	/**
	 * Merges an attribute set into the entity.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return void
	 */
	public function fill( array $attributes ): void {
		foreach ( $attributes as $field => $value ) {
			if ( null !== static::schema()->field( $field ) ) {
				$this->attributes[ $field ] = $value;
			}
		}
	}

	/**
	 * Returns every field value.
	 *
	 * @return array<string, mixed>
	 */
	public function attributes(): array {
		$values = array();

		foreach ( static::schema()->fields() as $name => $field ) {
			$values[ $name ] = array_key_exists( $name, $this->attributes )
				? $this->attributes[ $name ]
				: $field->default;
		}

		return $values;
	}

	/**
	 * Serialises the entity for an API response.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$payload = array_merge(
			array(
				'id'      => $this->id,
				'name'    => $this->name,
				'slug'    => $this->slug,
				'created' => $this->created,
				'updated' => $this->updated,
			),
			$this->attributes()
		);

		/**
		 * Filters the serialised representation of an entity.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Serialised entity.
		 * @param Entity               $entity  Entity instance.
		 */
		return (array) apply_filters( 'mpfbs_serialize_' . static::key(), $payload, $this );
	}

	/**
	 * Recomputes derived fields immediately before the entity is persisted.
	 *
	 * Entities with sortable or grouped projections of an authored value — a
	 * sailing's UTC timestamp, for instance — override this so the projection
	 * can never drift from its source.
	 *
	 * @return void
	 */
	public function derive(): void {
		// Nothing to derive by default.
	}

	/**
	 * Hydrates the post-table portion of the entity.
	 *
	 * @param WP_Post $post Source post.
	 * @return void
	 */
	public function hydrate_post( WP_Post $post ): void {
		$this->id      = (int) $post->ID;
		$this->name    = (string) $post->post_title;
		$this->slug    = (string) $post->post_name;
		$this->created = (string) $post->post_date_gmt;
		$this->updated = (string) $post->post_modified_gmt;
		$this->author  = (int) $post->post_author;
	}
}
