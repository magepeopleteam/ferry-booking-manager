<?php
/**
 * Entity field definition.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Support\Schema;

use FBM\Support\Money;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One persisted property of a domain entity.
 *
 * A field owns everything the rest of the plugin needs to know about a
 * property: where it is stored, how untrusted input is coerced, what makes it
 * valid, and how it is described to the REST layer. Declaring it once is what
 * keeps controllers thin and stops sanitisation rules drifting apart between
 * the admin API, the booking flow and the importer.
 */
final class Field {

	public const TYPE_STRING   = 'string';
	public const TYPE_TEXT     = 'text';
	public const TYPE_EMAIL    = 'email';
	public const TYPE_URL      = 'url';
	public const TYPE_INT      = 'int';
	public const TYPE_FLOAT    = 'float';
	public const TYPE_MONEY    = 'money';
	public const TYPE_BOOL     = 'bool';
	public const TYPE_ENUM     = 'enum';
	public const TYPE_ID       = 'id';
	public const TYPE_ID_LIST  = 'id_list';
	public const TYPE_STR_LIST = 'string_list';
	public const TYPE_DATETIME = 'datetime';
	public const TYPE_DATE     = 'date';
	public const TYPE_TIME     = 'time';
	public const TYPE_LAT      = 'latitude';
	public const TYPE_LNG      = 'longitude';
	public const TYPE_MAP      = 'map';

	/**
	 * Property name exposed through the API.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Field type, one of the TYPE_* constants.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Post meta key, or an empty string for post-table properties.
	 *
	 * @var string
	 */
	public string $meta_key;

	/**
	 * Whether a value must be supplied when creating an entity.
	 *
	 * @var bool
	 */
	public bool $required;

	/**
	 * Default applied when no value is stored.
	 *
	 * @var mixed
	 */
	public $default;

	/**
	 * Allowed values for enum fields.
	 *
	 * @var string[]
	 */
	public array $enum;

	/**
	 * Minimum numeric value or string length.
	 *
	 * @var float|null
	 */
	public ?float $min;

	/**
	 * Maximum numeric value or string length.
	 *
	 * @var float|null
	 */
	public ?float $max;

	/**
	 * Post type an `id` field must reference, if any.
	 *
	 * @var string
	 */
	public string $references;

	/**
	 * Human readable description used in the REST schema.
	 *
	 * @var string
	 */
	public string $description;

	/**
	 * Whether the field is computed and may never be written by a client.
	 *
	 * @var bool
	 */
	public bool $readonly;

	/**
	 * Whether the field contributes to the entity's search index.
	 *
	 * @var bool
	 */
	public bool $searchable;

	/**
	 * Whether no two records of this type may share a value.
	 *
	 * @var bool
	 */
	public bool $unique;

	/**
	 * Value type held by a `map` field, e.g. "money" for a price table.
	 *
	 * @var string
	 */
	public string $map_of;

	/**
	 * Constructor.
	 *
	 * @param string               $name       Property name.
	 * @param array<string, mixed> $definition Raw definition.
	 */
	public function __construct( string $name, array $definition ) {
		$this->name        = $name;
		$this->type        = isset( $definition['type'] ) ? (string) $definition['type'] : self::TYPE_STRING;
		$this->meta_key    = isset( $definition['meta'] ) ? (string) $definition['meta'] : '';
		$this->required    = ! empty( $definition['required'] );
		$this->enum        = isset( $definition['enum'] ) ? array_values( array_map( 'strval', (array) $definition['enum'] ) ) : array();
		$this->min         = isset( $definition['min'] ) ? (float) $definition['min'] : null;
		$this->max         = isset( $definition['max'] ) ? (float) $definition['max'] : null;
		$this->references  = isset( $definition['references'] ) ? (string) $definition['references'] : '';
		$this->description = isset( $definition['description'] ) ? (string) $definition['description'] : '';
		$this->readonly    = ! empty( $definition['readonly'] );
		$this->searchable  = ! empty( $definition['searchable'] );
		$this->unique      = ! empty( $definition['unique'] );
		$this->map_of      = isset( $definition['map_of'] ) ? (string) $definition['map_of'] : self::TYPE_STRING;
		$this->default     = array_key_exists( 'default', $definition ) ? $definition['default'] : $this->type_default();
	}

	/**
	 * Coerces an untrusted value into the field's storage representation.
	 *
	 * Always returns a value of the declared type; validity is a separate
	 * question answered by {@see Field::validate()}.
	 *
	 * @param mixed $value Raw input.
	 * @return mixed
	 */
	public function sanitize( $value ) {
		switch ( $this->type ) {
			case self::TYPE_TEXT:
				return sanitize_textarea_field( (string) $this->scalar( $value ) );

			case self::TYPE_EMAIL:
				return sanitize_email( (string) $this->scalar( $value ) );

			case self::TYPE_URL:
				return esc_url_raw( (string) $this->scalar( $value ) );

			case self::TYPE_INT:
			case self::TYPE_ID:
				return (int) $this->scalar( $value );

			case self::TYPE_FLOAT:
			case self::TYPE_LAT:
			case self::TYPE_LNG:
				return (float) $this->scalar( $value );

			case self::TYPE_MONEY:
				$amount = $this->scalar( $value );

				/*
				 * Money crosses the wire in minor units, which is exactly what
				 * the API emits, so a JSON number is taken at face value.
				 * A string is a human-entered major amount instead — that is
				 * what an importer, a CSV column or a settings box contains —
				 * so "12.50" and "12,50" both become 1250. Without this split,
				 * 12 would mean twelve cents and 12.5 twelve-fifty, and the
				 * same payload would price a fare two orders of magnitude apart.
				 */
				if ( is_int( $amount ) || is_float( $amount ) ) {
					return (int) round( (float) $amount );
				}

				return Money::to_minor( $amount );

			case self::TYPE_BOOL:
				return in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );

			case self::TYPE_ENUM:
				$candidate = sanitize_key( (string) $this->scalar( $value ) );

				return in_array( $candidate, $this->enum, true ) ? $candidate : (string) $this->default;

			case self::TYPE_ID_LIST:
				return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );

			case self::TYPE_STR_LIST:
				$items = array_map(
					static function ( $item ) {
						return sanitize_text_field( is_scalar( $item ) ? (string) $item : '' );
					},
					(array) $value
				);

				return array_values( array_filter( $items, static fn( $item ) => '' !== $item ) );

			case self::TYPE_MAP:
				return $this->sanitize_map( $value );

			case self::TYPE_DATETIME:
				return $this->sanitize_datetime( $value, 'Y-m-d H:i:s' );

			case self::TYPE_DATE:
				return $this->sanitize_datetime( $value, 'Y-m-d' );

			case self::TYPE_TIME:
				return $this->sanitize_datetime( $value, 'H:i' );

			case self::TYPE_STRING:
			default:
				return sanitize_text_field( (string) $this->scalar( $value ) );
		}
	}

	/**
	 * Validates an already-sanitised value.
	 *
	 * @param mixed $value Sanitised value.
	 * @return true|WP_Error
	 */
	public function validate( $value ) {
		if ( $this->is_empty( $value ) ) {
			if ( $this->required ) {
				return $this->error(
					/* translators: %s: field name. */
					sprintf( __( '%s is required.', 'ferry-booking-manager' ), $this->label() )
				);
			}

			return true;
		}

		switch ( $this->type ) {
			case self::TYPE_EMAIL:
				if ( ! is_email( (string) $value ) ) {
					return $this->error(
						/* translators: %s: field name. */
						sprintf( __( '%s must be a valid email address.', 'ferry-booking-manager' ), $this->label() )
					);
				}
				break;

			case self::TYPE_ENUM:
				if ( ! in_array( (string) $value, $this->enum, true ) ) {
					return $this->error(
						sprintf(
							/* translators: 1: field name, 2: comma separated list of allowed values. */
							__( '%1$s must be one of: %2$s.', 'ferry-booking-manager' ),
							$this->label(),
							implode( ', ', $this->enum )
						)
					);
				}
				break;

			case self::TYPE_ID:
				if ( '' !== $this->references && get_post_type( (int) $value ) !== $this->references ) {
					return $this->error(
						/* translators: %s: field name. */
						sprintf( __( '%s does not refer to an existing record.', 'ferry-booking-manager' ), $this->label() )
					);
				}
				break;

			case self::TYPE_LAT:
				return $this->validate_range( (float) $value, -90.0, 90.0 );

			case self::TYPE_LNG:
				return $this->validate_range( (float) $value, -180.0, 180.0 );

			case self::TYPE_INT:
			case self::TYPE_FLOAT:
			case self::TYPE_MONEY:
				return $this->validate_range( (float) $value, $this->min, $this->max );

			case self::TYPE_STRING:
			case self::TYPE_TEXT:
				$length = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );

				return $this->validate_range( (float) $length, $this->min, $this->max, true );
		}

		return true;
	}

	/**
	 * Returns the representation written to post meta.
	 *
	 * Booleans are the reason this exists. `update_post_meta()` stores `false`
	 * as an empty string, which is indistinguishable from a row that was never
	 * written — so a field defaulting to true could never be turned off. Storing
	 * "1" and "0" makes the stored value say what it means.
	 *
	 * @param mixed $value Value held by the entity.
	 * @return mixed
	 */
	public function to_storage( $value ) {
		if ( self::TYPE_BOOL === $this->type ) {
			return $value ? '1' : '0';
		}

		return $value;
	}

	/**
	 * Casts a stored value back into its API representation.
	 *
	 * Only `null` — meaning no meta row exists at all — falls back to the
	 * declared default. An empty stored value is a value the operator chose.
	 *
	 * @param mixed $value Stored value, or null when nothing is stored.
	 * @return mixed
	 */
	public function cast( $value ) {
		if ( null === $value ) {
			return $this->default;
		}

		if ( self::TYPE_BOOL === $this->type ) {
			return in_array( $value, array( true, 1, '1', 'yes', 'true' ), true );
		}

		if ( '' === $value && ! in_array( $this->type, array( self::TYPE_STRING, self::TYPE_TEXT, self::TYPE_EMAIL, self::TYPE_URL, self::TYPE_DATE, self::TYPE_TIME, self::TYPE_DATETIME ), true ) ) {
			return $this->default;
		}

		switch ( $this->type ) {
			case self::TYPE_INT:
			case self::TYPE_ID:
			case self::TYPE_MONEY:
				return (int) $value;

			case self::TYPE_FLOAT:
			case self::TYPE_LAT:
			case self::TYPE_LNG:
				return (float) $value;

			case self::TYPE_BOOL:
				return in_array( $value, array( true, 1, '1', 'yes', 'true' ), true );

			case self::TYPE_ID_LIST:
				return array_values( array_map( 'intval', (array) $value ) );

			case self::TYPE_STR_LIST:
			case self::TYPE_MAP:
				return is_array( $value ) ? $value : $this->default;

			default:
				return is_scalar( $value ) ? (string) $value : $this->default;
		}
	}

	/**
	 * Returns the REST argument definition for this field.
	 *
	 * @return array<string, mixed>
	 */
	public function rest_arg(): array {
		$arg = array(
			'description' => $this->description,
			'type'        => $this->rest_type(),
			'required'    => false,
		);

		if ( array() !== $this->enum ) {
			$arg['enum'] = $this->enum;
		}

		if ( in_array( $this->type, array( self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_MONEY ), true ) ) {
			if ( null !== $this->min ) {
				$arg['minimum'] = $this->min;
			}

			if ( null !== $this->max ) {
				$arg['maximum'] = $this->max;
			}
		}

		if ( in_array( $this->type, array( self::TYPE_ID_LIST, self::TYPE_STR_LIST ), true ) ) {
			$arg['items'] = array( 'type' => self::TYPE_ID_LIST === $this->type ? 'integer' : 'string' );
		}

		return $arg;
	}

	/**
	 * Returns the JSON Schema type for this field.
	 *
	 * @return string
	 */
	public function rest_type(): string {
		switch ( $this->type ) {
			case self::TYPE_INT:
			case self::TYPE_ID:
			case self::TYPE_MONEY:
				return 'integer';

			case self::TYPE_FLOAT:
			case self::TYPE_LAT:
			case self::TYPE_LNG:
				return 'number';

			case self::TYPE_BOOL:
				return 'boolean';

			case self::TYPE_ID_LIST:
			case self::TYPE_STR_LIST:
				return 'array';

			case self::TYPE_MAP:
				return 'object';

			default:
				return 'string';
		}
	}

	/**
	 * Returns a human readable label for error messages.
	 *
	 * @return string
	 */
	public function label(): string {
		if ( '' !== $this->description ) {
			return $this->description;
		}

		return ucfirst( str_replace( '_', ' ', $this->name ) );
	}

	/**
	 * Determines whether a value counts as "not supplied".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function is_empty( $value ): bool {
		if ( null === $value || '' === $value || array() === $value ) {
			return true;
		}

		if ( self::TYPE_ID === $this->type ) {
			return 0 === (int) $value;
		}

		// Zero is a meaningful amount, so it never counts as "not supplied".
		if ( self::TYPE_MONEY === $this->type ) {
			return false;
		}

		return false;
	}

	/**
	 * Returns the natural default for the field type.
	 *
	 * @return mixed
	 */
	private function type_default() {
		switch ( $this->type ) {
			case self::TYPE_INT:
			case self::TYPE_ID:
			case self::TYPE_MONEY:
				return 0;

			case self::TYPE_FLOAT:
			case self::TYPE_LAT:
			case self::TYPE_LNG:
				return 0.0;

			case self::TYPE_BOOL:
				return false;

			case self::TYPE_ID_LIST:
			case self::TYPE_STR_LIST:
			case self::TYPE_MAP:
				return array();

			case self::TYPE_ENUM:
				return $this->enum[0] ?? '';

			default:
				return '';
		}
	}

	/**
	 * Reduces a possibly non-scalar value to something castable.
	 *
	 * @param mixed $value Raw value.
	 * @return string|int|float|bool
	 */
	private function scalar( $value ) {
		return is_scalar( $value ) ? $value : '';
	}

	/**
	 * Sanitises a shallow key/value map.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, string>
	 */
	private function sanitize_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $key => $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$value = $this->sanitize_map_value( $item );

			// An entry that could not be read is dropped rather than stored as
			// zero. In a fare table, zero means "this crossing is free", and
			// that is not what a malformed value was trying to say.
			if ( '' === $value ) {
				continue;
			}

			$clean[ sanitize_key( (string) $key ) ] = $value;
		}

		return $clean;
	}

	/**
	 * Coerces one value inside a map to the map's declared value type.
	 *
	 * A price table is a map of money, and money that arrives as a string
	 * would compare and add like a string. Declaring the value type once on
	 * the field keeps every entry in a map the same shape.
	 *
	 * @param scalar $item Raw value.
	 * @return float|int|string
	 */
	private function sanitize_map_value( $item ) {
		switch ( $this->map_of ) {
			case self::TYPE_MONEY:
				if ( is_int( $item ) || is_float( $item ) ) {
					return (int) round( (float) $item );
				}

				$raw = trim( (string) $item );

				return '' === $raw || ! is_numeric( str_replace( array( ' ', ',' ), array( '', '.' ), $raw ) )
					? ''
					: Money::to_minor( $raw );

			case self::TYPE_INT:
				return (int) $item;

			case self::TYPE_FLOAT:
				return (float) $item;

			case self::TYPE_BOOL:
				return in_array( $item, array( true, 1, '1', 'yes', 'true', 'on' ), true ) ? 1 : 0;

			default:
				return sanitize_text_field( (string) $item );
		}
	}

	/**
	 * Normalises a date/time input to a canonical format.
	 *
	 * @param mixed  $value  Raw value.
	 * @param string $format Target format.
	 * @return string Empty string when the input is not a usable date.
	 */
	private function sanitize_datetime( $value, string $format ): string {
		$raw = trim( (string) $this->scalar( $value ) );

		if ( '' === $raw ) {
			return '';
		}

		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( $format, $timestamp );
	}

	/**
	 * Validates a numeric range.
	 *
	 * @param float      $value    Value to check.
	 * @param float|null $min      Lower bound.
	 * @param float|null $max      Upper bound.
	 * @param bool       $a_length Whether the value represents a string length.
	 * @return true|WP_Error
	 */
	private function validate_range( float $value, ?float $min, ?float $max, bool $a_length = false ) {
		if ( null !== $min && $value < $min ) {
			return $this->error(
				$a_length
					/* translators: 1: field name, 2: minimum number of characters. */
					? sprintf( __( '%1$s must be at least %2$d characters.', 'ferry-booking-manager' ), $this->label(), (int) $min )
					/* translators: 1: field name, 2: minimum value. */
					: sprintf( __( '%1$s must be %2$s or more.', 'ferry-booking-manager' ), $this->label(), (string) $min )
			);
		}

		if ( null !== $max && $value > $max ) {
			return $this->error(
				$a_length
					/* translators: 1: field name, 2: maximum number of characters. */
					? sprintf( __( '%1$s must be %2$d characters or fewer.', 'ferry-booking-manager' ), $this->label(), (int) $max )
					/* translators: 1: field name, 2: maximum value. */
					: sprintf( __( '%1$s must be %2$s or less.', 'ferry-booking-manager' ), $this->label(), (string) $max )
			);
		}

		return true;
	}

	/**
	 * Builds a field-scoped validation error.
	 *
	 * @param string $message Translated message.
	 * @return WP_Error
	 */
	private function error( string $message ): WP_Error {
		return new WP_Error(
			'fbm_invalid_field',
			$message,
			array(
				'status' => 422,
				'field'  => $this->name,
			)
		);
	}
}
