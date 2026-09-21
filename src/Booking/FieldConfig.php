<?php
/**
 * Configurable passenger and vehicle capture fields.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Booking;

use MPFBS\Support\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which details a booking must collect about each traveller and vehicle.
 *
 * Requirements differ wildly by operator: a domestic river shuttle wants a name
 * and nothing else, while an international crossing is legally obliged to
 * record a travel document for every person aboard. Rather than shipping a
 * fixed form and a pile of filters, the catalogue below is data, each entry has
 * three states, and one validator enforces whatever the operator chose — so the
 * booking form, the staff booking form and the REST API cannot disagree about what
 * "required" means.
 */
final class FieldConfig {

	/**
	 * The field is not collected at all.
	 */
	public const MODE_OFF = 'off';

	/**
	 * The field is shown and may be left blank.
	 */
	public const MODE_OPTIONAL = 'optional';

	/**
	 * The field is shown and must be filled in.
	 */
	public const MODE_REQUIRED = 'required';

	/**
	 * Field group covering people.
	 */
	public const GROUP_PASSENGER = 'passenger';

	/**
	 * Field group covering vehicles.
	 */
	public const GROUP_VEHICLE = 'vehicle';

	/**
	 * Option storing the passenger field modes.
	 */
	private const PASSENGER_OPTION = 'passenger_fields';

	/**
	 * Option storing the vehicle field modes.
	 */
	private const VEHICLE_OPTION = 'vehicle_fields';

	/**
	 * Option storing operator-defined extra fields.
	 */
	private const CUSTOM_OPTION = 'custom_fields';

	/**
	 * Maximum number of custom fields per group.
	 */
	private const MAX_CUSTOM = 20;

	/**
	 * Returns every mode a field can be in.
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_OFF, self::MODE_OPTIONAL, self::MODE_REQUIRED );
	}

	/**
	 * Returns the built-in field catalogue for a group.
	 *
	 * `always_on` marks a field the plugin will not let an operator switch off,
	 * because the rest of the product depends on it: every booking has to name
	 * the people travelling.
	 *
	 * @param string $group One of the GROUP_* constants.
	 * @return array<string, array{label: string, type: string, default: string, always_on?: bool, hint?: string}>
	 */
	public static function catalogue( string $group ): array {
		$catalogue = self::GROUP_VEHICLE === $group ? self::vehicle_catalogue() : self::passenger_catalogue();

		/**
		 * Filters the built-in capture field catalogue for a group.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $catalogue Field definitions keyed by field key.
		 * @param string                              $group     Field group.
		 */
		return (array) apply_filters( 'mpfbs_field_catalogue', $catalogue, $group );
	}

	/**
	 * Returns the passenger field catalogue.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function passenger_catalogue(): array {
		return array(
			'first_name'              => array(
				'label'     => __( 'First name', 'magepeople-ferry-booking-system' ),
				'type'      => 'text',
				'default'   => self::MODE_REQUIRED,
				'always_on' => true,
				'hint'      => __( 'Always collected: every traveller needs a name.', 'magepeople-ferry-booking-system' ),
			),
			'last_name'               => array(
				'label'     => __( 'Last name', 'magepeople-ferry-booking-system' ),
				'type'      => 'text',
				'default'   => self::MODE_REQUIRED,
				'always_on' => true,
			),
			'gender'                  => array(
				'label'   => __( 'Gender', 'magepeople-ferry-booking-system' ),
				'type'    => 'select',
				'default' => self::MODE_OFF,
			),
			'date_of_birth'           => array(
				'label'   => __( 'Date of birth', 'magepeople-ferry-booking-system' ),
				'type'    => 'date',
				'default' => self::MODE_OPTIONAL,
				'hint'    => __( 'Passenger types that verify an age band ask for this regardless of the setting here.', 'magepeople-ferry-booking-system' ),
			),
			'nationality'             => array(
				'label'   => __( 'Nationality', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OFF,
			),
			'phone'                   => array(
				'label'   => __( 'Phone', 'magepeople-ferry-booking-system' ),
				'type'    => 'tel',
				'default' => self::MODE_OFF,
			),
			'email'                   => array(
				'label'   => __( 'Email', 'magepeople-ferry-booking-system' ),
				'type'    => 'email',
				'default' => self::MODE_OFF,
			),
			'document_type'           => array(
				'label'   => __( 'Document type', 'magepeople-ferry-booking-system' ),
				'type'    => 'select',
				'default' => self::MODE_OFF,
			),
			'document_number'         => array(
				'label'   => __( 'Document number', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OFF,
				'hint'    => __( 'Required by law on most international crossings.', 'magepeople-ferry-booking-system' ),
			),
			'document_expiry'         => array(
				'label'   => __( 'Document expiry', 'magepeople-ferry-booking-system' ),
				'type'    => 'date',
				'default' => self::MODE_OFF,
			),
			'emergency_contact_name'  => array(
				'label'   => __( 'Emergency contact name', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OFF,
			),
			'emergency_contact_phone' => array(
				'label'   => __( 'Emergency contact phone', 'magepeople-ferry-booking-system' ),
				'type'    => 'tel',
				'default' => self::MODE_OFF,
			),
		);
	}

	/**
	 * Returns the vehicle field catalogue.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function vehicle_catalogue(): array {
		return array(
			'registration'    => array(
				'label'   => __( 'Registration number', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_REQUIRED,
				'hint'    => __( 'Vehicle types can also demand this individually.', 'magepeople-ferry-booking-system' ),
			),
			'make'            => array(
				'label'   => __( 'Make', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OPTIONAL,
			),
			'model'           => array(
				'label'   => __( 'Model', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OPTIONAL,
			),
			'colour'          => array(
				'label'   => __( 'Colour', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OFF,
			),
			'length'          => array(
				'label'   => __( 'Length', 'magepeople-ferry-booking-system' ),
				'type'    => 'number',
				'default' => self::MODE_OFF,
				'hint'    => __( 'Collect this when you sell deck space by lane metre.', 'magepeople-ferry-booking-system' ),
			),
			'width'           => array(
				'label'   => __( 'Width', 'magepeople-ferry-booking-system' ),
				'type'    => 'number',
				'default' => self::MODE_OFF,
			),
			'height'          => array(
				'label'   => __( 'Height', 'magepeople-ferry-booking-system' ),
				'type'    => 'number',
				'default' => self::MODE_OFF,
			),
			'weight'          => array(
				'label'   => __( 'Weight', 'magepeople-ferry-booking-system' ),
				'type'    => 'number',
				'default' => self::MODE_OFF,
			),
			'driver_name'     => array(
				'label'   => __( 'Driver name', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OPTIONAL,
			),
			'driver_document' => array(
				'label'   => __( 'Driver licence number', 'magepeople-ferry-booking-system' ),
				'type'    => 'text',
				'default' => self::MODE_OFF,
			),
			'trailer'         => array(
				'label'   => __( 'Trailer', 'magepeople-ferry-booking-system' ),
				'type'    => 'switch',
				'default' => self::MODE_OFF,
			),
			'trailer_length'  => array(
				'label'   => __( 'Trailer length', 'magepeople-ferry-booking-system' ),
				'type'    => 'number',
				'default' => self::MODE_OFF,
			),
		);
	}

	/**
	 * Returns the stored mode for every field in a group.
	 *
	 * @param string $group One of the GROUP_* constants.
	 * @return array<string, string> Field key => mode.
	 */
	public static function modes_for( string $group ): array {
		$catalogue = self::catalogue( $group );
		$stored    = Options::get_array( self::option_for( $group ) );
		$modes     = array();

		foreach ( $catalogue as $key => $definition ) {
			if ( ! empty( $definition['always_on'] ) ) {
				$modes[ $key ] = (string) $definition['default'];
				continue;
			}

			$candidate     = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : (string) $definition['default'];
			$modes[ $key ] = in_array( $candidate, self::modes(), true ) ? $candidate : (string) $definition['default'];
		}

		return $modes;
	}

	/**
	 * Stores the mode for every field in a group.
	 *
	 * Unknown keys are dropped and always-on fields keep their shipped mode, so a
	 * crafted request cannot switch off a field the product depends on.
	 *
	 * A field the payload does not mention keeps the mode it already has. The
	 * option holds the whole group, so building it from the submitted keys alone
	 * meant a partial save reset every other field in the group back to its
	 * shipped default — an operator who turned off passport collection would
	 * find it switched back on the next time anything else was saved.
	 *
	 * @param string                $group One of the GROUP_* constants.
	 * @param array<string, string> $modes Field key => mode.
	 * @return array<string, string> The modes actually stored.
	 */
	public static function save_modes( string $group, array $modes ): array {
		$catalogue = self::catalogue( $group );
		$clean     = Options::get_array( self::option_for( $group ) );

		foreach ( $catalogue as $key => $definition ) {
			if ( ! empty( $definition['always_on'] ) ) {
				// An always-on field has no stored mode to keep, and must not gain
				// one that later diverges from the shipped value.
				unset( $clean[ $key ] );
				continue;
			}

			if ( ! isset( $modes[ $key ] ) ) {
				continue;
			}

			$candidate = sanitize_key( (string) $modes[ $key ] );

			if ( in_array( $candidate, self::modes(), true ) ) {
				$clean[ $key ] = $candidate;
			}
		}

		// Anything no longer in the catalogue is dropped, so a removed custom
		// field does not linger in the option for ever.
		$clean = array_intersect_key( $clean, $catalogue );

		Options::set( self::option_for( $group ), $clean );

		return self::modes_for( $group );
	}

	/**
	 * Returns the operator-defined extra fields for a group.
	 *
	 * @param string $group One of the GROUP_* constants.
	 * @return array<int, array{key: string, label: string, type: string, mode: string}>
	 */
	public static function custom_fields( string $group ): array {
		$all   = Options::get_array( self::CUSTOM_OPTION );
		$group = self::normalise_group( $group );

		return isset( $all[ $group ] ) && is_array( $all[ $group ] ) ? array_values( $all[ $group ] ) : array();
	}

	/**
	 * Stores the operator-defined extra fields for a group.
	 *
	 * @param string                   $group  One of the GROUP_* constants.
	 * @param array<int, array<mixed>> $fields Submitted custom fields.
	 * @return array<int, array<string, string>> The fields actually stored.
	 */
	public static function save_custom_fields( string $group, array $fields ): array {
		$group     = self::normalise_group( $group );
		$catalogue = self::catalogue( $group );
		$allowed   = array( 'text', 'textarea', 'number', 'date', 'email', 'tel', 'switch' );
		$clean     = array();
		$seen      = array();

		foreach ( array_slice( $fields, 0, self::MAX_CUSTOM ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$key = isset( $field['key'] ) ? sanitize_key( (string) $field['key'] ) : '';

			if ( '' === $key ) {
				// "Loyalty number" becomes loyalty_number rather than
				// loyaltynumber, so a label matching a built-in field derives
				// the same key and is caught by the collision check below.
				$key = sanitize_key( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $label ) );
			}

			// A custom field that collides with a built-in one would overwrite
			// it in the stored payload, so it gets its own namespace.
			if ( '' === $key || isset( $catalogue[ $key ] ) ) {
				$key = 'custom_' . $key;
			}

			if ( '' === trim( $key, '_' ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
			$mode = isset( $field['mode'] ) ? sanitize_key( (string) $field['mode'] ) : self::MODE_OPTIONAL;

			$clean[] = array(
				'key'     => $key,
				'label'   => $label,
				'type'    => in_array( $type, $allowed, true ) ? $type : 'text',
				'mode'    => in_array( $mode, self::modes(), true ) ? $mode : self::MODE_OPTIONAL,
			);
		}

		$all           = Options::get_array( self::CUSTOM_OPTION );
		$all[ $group ] = $clean;

		Options::set( self::CUSTOM_OPTION, $all );

		return $clean;
	}

	/**
	 * Returns the full, resolved form definition for a group.
	 *
	 * @param string $group One of the GROUP_* constants.
	 * @return array<int, array{key: string, label: string, type: string, mode: string, always_on: bool, custom: bool, hint: string}>
	 */
	public static function form( string $group ): array {
		$group = self::normalise_group( $group );
		$modes = self::modes_for( $group );
		$form  = array();

		foreach ( self::catalogue( $group ) as $key => $definition ) {
			$form[] = array(
				'key'       => $key,
				'label'     => (string) $definition['label'],
				'type'      => (string) $definition['type'],
				'mode'      => $modes[ $key ] ?? self::MODE_OFF,
				'always_on' => ! empty( $definition['always_on'] ),
				'custom'    => false,
				'hint'      => isset( $definition['hint'] ) ? (string) $definition['hint'] : '',
			);
		}

		foreach ( self::custom_fields( $group ) as $field ) {
			$form[] = array(
				'key'       => (string) $field['key'],
				'label'     => (string) $field['label'],
				'type'      => (string) $field['type'],
				'mode'      => (string) $field['mode'],
				'always_on' => false,
				'custom'    => true,
				'hint'      => '',
			);
		}

		return $form;
	}

	/**
	 * Validates a submitted detail payload against the configured form.
	 *
	 * @param string               $group   One of the GROUP_* constants.
	 * @param array<string, mixed> $payload Submitted values keyed by field key.
	 * @param array<string, bool>  $force   Field keys made mandatory by another rule.
	 * @return array<string, string>|WP_Error Sanitised values, or the fields that failed.
	 */
	public static function validate( string $group, array $payload, array $force = array() ) {
		$errors = array();
		$clean  = array();

		foreach ( self::form( $group ) as $field ) {
			$key      = $field['key'];
			$required = self::MODE_REQUIRED === $field['mode'] || ! empty( $force[ $key ] );

			if ( self::MODE_OFF === $field['mode'] && empty( $force[ $key ] ) ) {
				continue;
			}

			$raw   = isset( $payload[ $key ] ) ? $payload[ $key ] : '';
			$value = self::sanitize_value( (string) $field['type'], $raw );

			if ( '' === $value && $required ) {
				$errors[ $key ] = sprintf(
					/* translators: %s: field label. */
					__( '%s is required.', 'magepeople-ferry-booking-system' ),
					$field['label']
				);
				continue;
			}

			if ( '' !== $value ) {
				$problem = self::validate_value( (string) $field['type'], $value, (string) $field['label'] );

				if ( '' !== $problem ) {
					$errors[ $key ] = $problem;
					continue;
				}
			}

			$clean[ $key ] = $value;
		}

		if ( array() !== $errors ) {
			return new WP_Error(
				'mpfbs_validation_failed',
				__( 'Please correct the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return $clean;
	}

	/**
	 * Coerces one submitted value to its stored representation.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	private static function sanitize_value( string $type, $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		switch ( $type ) {
			case 'email':
				return sanitize_email( (string) $value );

			case 'number':
				return is_numeric( $value ) ? (string) ( 0 + $value ) : '';

			case 'switch':
				return in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true ) ? '1' : '';

			case 'date':
				$date = trim( (string) $value );

				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Returns a message when a sanitised value is still unusable.
	 *
	 * @param string $type  Field type.
	 * @param string $value Sanitised value.
	 * @param string $label Field label.
	 * @return string Empty when the value is fine.
	 */
	private static function validate_value( string $type, string $value, string $label ): string {
		if ( 'email' === $type && ! is_email( $value ) ) {
			return sprintf(
				/* translators: %s: field label. */
				__( '%s must be a valid email address.', 'magepeople-ferry-booking-system' ),
				$label
			);
		}

		if ( 'date' === $type ) {
			$parts = array_map( 'intval', explode( '-', $value ) );

			if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
				return sprintf(
					/* translators: %s: field label. */
					__( '%s must be a real date.', 'magepeople-ferry-booking-system' ),
					$label
				);
			}
		}

		if ( 'number' === $type && ! is_numeric( $value ) ) {
			return sprintf(
				/* translators: %s: field label. */
				__( '%s must be a number.', 'magepeople-ferry-booking-system' ),
				$label
			);
		}

		return '';
	}

	/**
	 * Returns the option key backing a group.
	 *
	 * @param string $group Field group.
	 * @return string
	 */
	private static function option_for( string $group ): string {
		return self::GROUP_VEHICLE === self::normalise_group( $group ) ? self::VEHICLE_OPTION : self::PASSENGER_OPTION;
	}

	/**
	 * Falls back to the passenger group for anything unrecognised.
	 *
	 * @param string $group Field group.
	 * @return string
	 */
	private static function normalise_group( string $group ): string {
		// The plural forms are accepted because they are what a booking request
		// carries, and silently treating "vehicles" as the passenger group is a
		// far worse outcome than accepting a name that was obviously meant.
		if ( in_array( $group, array( self::GROUP_VEHICLE, 'vehicles' ), true ) ) {
			return self::GROUP_VEHICLE;
		}

		return self::GROUP_PASSENGER;
	}
}
