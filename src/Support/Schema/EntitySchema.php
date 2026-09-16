<?php
/**
 * Entity schema.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Support\Schema;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The complete description of one domain entity.
 *
 * Repositories persist through it, REST controllers derive their arguments and
 * response schema from it, validators run it, and the importer maps CSV columns
 * onto it. One declaration, one set of rules.
 */
final class EntitySchema {

	/**
	 * Entity key, e.g. "vessel".
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Post type storing the entity.
	 *
	 * @var string
	 */
	private string $post_type;

	/**
	 * Capability required to write the entity.
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * Field definitions, keyed by property name.
	 *
	 * @var array<string, Field>
	 */
	private array $fields = array();

	/**
	 * Constructor.
	 *
	 * @param string                              $key         Entity key.
	 * @param string                              $post_type   Post type.
	 * @param string                              $capability  Write capability.
	 * @param array<string, array<string, mixed>> $definitions Field definitions.
	 */
	public function __construct( string $key, string $post_type, string $capability, array $definitions ) {
		$this->key        = $key;
		$this->post_type  = $post_type;
		$this->capability = $capability;

		/**
		 * Filters the field definitions of a MagePeople Ferry Booking System entity.
		 *
		 * This is how Pro and third parties add persisted properties without
		 * touching the Free entity classes.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $definitions Field definitions.
		 * @param string                              $key         Entity key.
		 */
		$definitions = (array) apply_filters( 'fbm_entity_fields', $definitions, $key );

		foreach ( $definitions as $name => $definition ) {
			if ( ! is_string( $name ) || ! is_array( $definition ) ) {
				continue;
			}

			$this->fields[ $name ] = new Field( $name, $definition );
		}
	}

	/**
	 * Returns the entity key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Returns the post type.
	 *
	 * @return string
	 */
	public function post_type(): string {
		return $this->post_type;
	}

	/**
	 * Returns the capability required to create, update or delete the entity.
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Returns every field.
	 *
	 * @return array<string, Field>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Returns a single field.
	 *
	 * @param string $name Property name.
	 * @return Field|null
	 */
	public function field( string $name ): ?Field {
		return $this->fields[ $name ] ?? null;
	}

	/**
	 * Returns the fields backed by post meta.
	 *
	 * @return array<string, Field>
	 */
	public function meta_fields(): array {
		return array_filter(
			$this->fields,
			static function ( Field $field ): bool {
				return '' !== $field->meta_key;
			}
		);
	}

	/**
	 * Returns the fields that must hold a value unique across the post type.
	 *
	 * @return array<string, Field>
	 */
	public function unique_fields(): array {
		return array_filter(
			$this->fields,
			static function ( Field $field ): bool {
				return $field->unique && '' !== $field->meta_key;
			}
		);
	}

	/**
	 * Returns the field names that feed the search index.
	 *
	 * @return string[]
	 */
	public function search_fields(): array {
		$names = array();

		foreach ( $this->fields as $name => $field ) {
			if ( $field->searchable ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Sanitises a raw input array down to known, writable fields.
	 *
	 * Unknown keys are dropped rather than stored, so a client cannot smuggle
	 * arbitrary meta onto an entity.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$clean = array();

		foreach ( $this->fields as $name => $field ) {
			if ( $field->readonly || ! array_key_exists( $name, $input ) ) {
				continue;
			}

			$clean[ $name ] = $field->sanitize( $input[ $name ] );
		}

		return $clean;
	}

	/**
	 * Validates a sanitised attribute set.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param bool                 $partial    True for updates, where absent fields are left alone.
	 * @return true|WP_Error
	 */
	public function validate( array $attributes, bool $partial = false ) {
		$errors = new WP_Error();

		foreach ( $this->fields as $name => $field ) {
			if ( $field->readonly ) {
				continue;
			}

			if ( $partial && ! array_key_exists( $name, $attributes ) ) {
				continue;
			}

			$value  = $attributes[ $name ] ?? null;
			$result = $field->validate( $value );

			if ( is_wp_error( $result ) ) {
				$errors->add( 'fbm_invalid_field', $result->get_error_message(), array( 'field' => $name ) );
			}
		}

		if ( $errors->has_errors() ) {
			return new WP_Error(
				'fbm_validation_failed',
				$errors->get_error_message(),
				array(
					'status' => 422,
					'fields' => $this->error_map( $errors ),
				)
			);
		}

		/**
		 * Filters the result of validating an entity.
		 *
		 * Lets Pro add cross-field rules — a return sailing that departs before
		 * its outbound leg, for instance — without editing Free validators.
		 *
		 * @since 1.0.0
		 *
		 * @param true|WP_Error        $result     Validation result.
		 * @param array<string, mixed> $attributes Sanitised attributes.
		 * @param string               $key        Entity key.
		 * @param bool                 $partial    Whether this is a partial update.
		 */
		return apply_filters( 'fbm_validate_entity', true, $attributes, $this->key, $partial );
	}

	/**
	 * Returns the REST argument definitions for write endpoints.
	 *
	 * Required fields are deliberately not marked required here. WordPress
	 * rejects a missing required argument before the callback runs, with a
	 * generic message that names no field. Letting the schema validator handle
	 * presence alongside range, enum and cross-field rules means every failure
	 * comes back in the same structured shape, with one message per field —
	 * which is what the interface renders next to the input.
	 *
	 * Requirements are still published in the item schema for API consumers.
	 *
	 * @param bool $creating Whether the arguments describe a create request.
	 * @return array<string, array<string, mixed>>
	 */
	public function rest_args( bool $creating ): array {
		unset( $creating );

		$args = array();

		foreach ( $this->fields as $name => $field ) {
			if ( $field->readonly ) {
				continue;
			}

			$arg             = $field->rest_arg();
			$arg['required'] = false;
			$args[ $name ]   = $arg;
		}

		return $args;
	}

	/**
	 * Returns the JSON Schema describing a serialised entity.
	 *
	 * @return array<string, mixed>
	 */
	public function rest_schema(): array {
		$properties = array(
			'id'      => array(
				'type'     => 'integer',
				'readonly' => true,
			),
			'name'    => array( 'type' => 'string' ),
			'status'  => array( 'type' => 'string' ),
			'created' => array(
				'type'     => 'string',
				'readonly' => true,
			),
			'updated' => array(
				'type'     => 'string',
				'readonly' => true,
			),
		);

		$required = array();

		foreach ( $this->fields as $name => $field ) {
			$properties[ $name ] = array(
				'type'        => $field->rest_type(),
				'description' => $field->description,
				'readonly'    => $field->readonly,
			);

			if ( $field->required && ! $field->readonly ) {
				$required[] = $name;
			}

			if ( array() !== $field->enum ) {
				$properties[ $name ]['enum'] = $field->enum;
			}
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fbm_' . $this->key,
			'type'       => 'object',
			'required'   => $required,
			'properties' => $properties,
		);
	}

	/**
	 * Flattens a WP_Error into a field => message map.
	 *
	 * @param WP_Error $errors Collected errors.
	 * @return array<string, string>
	 */
	private function error_map( WP_Error $errors ): array {
		$map = array();

		foreach ( $errors->get_error_codes() as $code ) {
			foreach ( (array) $errors->get_all_error_data( $code ) as $index => $data ) {
				$messages = $errors->get_error_messages( $code );
				$field    = is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : (string) $index;

				$map[ $field ] = (string) ( $messages[ $index ] ?? reset( $messages ) );
			}
		}

		return $map;
	}
}
