<?php
/**
 * Port entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Models;

use FBM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A terminal a sailing departs from, calls at or arrives into.
 */
final class Port extends Entity {
	public const POST_TYPE = 'fbm_port';

	public const STATUS_ACTIVE = 'active';

	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'port';
	}

	/**
	 * Returns the post type storing the entity.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * Returns the capability required to write the entity.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::MANAGE_PORTS;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Ports', 'ferry-booking-manager' ),
			'singular' => __( 'Port', 'ferry-booking-manager' ),
		);
	}

	/**
	 * Returns the raw field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definition(): array {
		return array(
			'code'                  => array(
				'type'        => 'string',
				'meta'        => '_fbm_port_code',
				'unique'      => true,
				'required'    => true,
				'min'         => 2,
				'max'         => 16,
				'searchable'  => true,
				'description' => __( 'Port code', 'ferry-booking-manager' ),
			),
			'city'                  => array(
				'type'        => 'string',
				'meta'        => '_fbm_city',
				'max'         => 120,
				'searchable'  => true,
				'description' => __( 'City', 'ferry-booking-manager' ),
			),
			'country'               => array(
				'type'        => 'string',
				'meta'        => '_fbm_country',
				'max'         => 2,
				'description' => __( 'Country code', 'ferry-booking-manager' ),
			),
			'address'               => array(
				'type'        => 'text',
				'meta'        => '_fbm_address',
				'max'         => 500,
				'description' => __( 'Address', 'ferry-booking-manager' ),
			),
			'latitude'              => array(
				'type'        => 'latitude',
				'meta'        => '_fbm_latitude',
				'description' => __( 'Latitude', 'ferry-booking-manager' ),
			),
			'longitude'             => array(
				'type'        => 'longitude',
				'meta'        => '_fbm_longitude',
				'description' => __( 'Longitude', 'ferry-booking-manager' ),
			),
			'checkin_instructions'  => array(
				'type'        => 'text',
				'meta'        => '_fbm_checkin_instructions',
				'max'         => 2000,
				'description' => __( 'Check-in instructions', 'ferry-booking-manager' ),
			),
			'boarding_instructions' => array(
				'type'        => 'text',
				'meta'        => '_fbm_boarding_instructions',
				'max'         => 2000,
				'description' => __( 'Boarding instructions', 'ferry-booking-manager' ),
			),
			'checkin_minutes'       => array(
				'type'        => 'int',
				'meta'        => '_fbm_checkin_minutes',
				'default'     => 30,
				'min'         => 0,
				'max'         => 1440,
				'description' => __( 'Check-in closes (minutes before departure)', 'ferry-booking-manager' ),
			),
			'contact_phone'         => array(
				'type'        => 'string',
				'meta'        => '_fbm_contact_phone',
				'max'         => 40,
				'description' => __( 'Contact phone', 'ferry-booking-manager' ),
			),
			'contact_email'         => array(
				'type'        => 'email',
				'meta'        => '_fbm_contact_email',
				'description' => __( 'Contact email', 'ferry-booking-manager' ),
			),
			'timezone'              => array(
				'type'        => 'string',
				'meta'        => '_fbm_timezone',
				'max'         => 64,
				'description' => __( 'Timezone', 'ferry-booking-manager' ),
			),
			'status'                => array(
				'type'        => 'enum',
				'meta'        => '_fbm_status',
				'enum'        => array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ),
				'default'     => self::STATUS_ACTIVE,
				'description' => __( 'Status', 'ferry-booking-manager' ),
			),
		);
	}

	/**
	 * Returns the port code.
	 *
	 * @return string
	 */
	public function code(): string {
		return (string) $this->get( 'code' );
	}

	/**
	 * Determines whether the port may be used in new bookings.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->get( 'status' );
	}
}
