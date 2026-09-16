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
			'plural'   => __( 'Ports', 'magepeople-ferry-booking-system' ),
			'singular' => __( 'Port', 'magepeople-ferry-booking-system' ),
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
				'description' => __( 'Port code', 'magepeople-ferry-booking-system' ),
			),
			'city'                  => array(
				'type'        => 'string',
				'meta'        => '_fbm_city',
				'max'         => 120,
				'searchable'  => true,
				'description' => __( 'City', 'magepeople-ferry-booking-system' ),
			),
			'country'               => array(
				'type'        => 'string',
				'meta'        => '_fbm_country',
				'max'         => 2,
				'description' => __( 'Country code', 'magepeople-ferry-booking-system' ),
			),
			'address'               => array(
				'type'        => 'text',
				'meta'        => '_fbm_address',
				'max'         => 500,
				'description' => __( 'Address', 'magepeople-ferry-booking-system' ),
			),
			'latitude'              => array(
				'type'        => 'latitude',
				'meta'        => '_fbm_latitude',
				'description' => __( 'Latitude', 'magepeople-ferry-booking-system' ),
			),
			'longitude'             => array(
				'type'        => 'longitude',
				'meta'        => '_fbm_longitude',
				'description' => __( 'Longitude', 'magepeople-ferry-booking-system' ),
			),
			'checkin_instructions'  => array(
				'type'        => 'text',
				'meta'        => '_fbm_checkin_instructions',
				'max'         => 2000,
				'description' => __( 'Check-in instructions', 'magepeople-ferry-booking-system' ),
			),
			'boarding_instructions' => array(
				'type'        => 'text',
				'meta'        => '_fbm_boarding_instructions',
				'max'         => 2000,
				'description' => __( 'Boarding instructions', 'magepeople-ferry-booking-system' ),
			),
			'checkin_minutes'       => array(
				'type'        => 'int',
				'meta'        => '_fbm_checkin_minutes',
				'default'     => 30,
				'min'         => 0,
				'max'         => 1440,
				'description' => __( 'Check-in closes (minutes before departure)', 'magepeople-ferry-booking-system' ),
			),
			'contact_phone'         => array(
				'type'        => 'string',
				'meta'        => '_fbm_contact_phone',
				'max'         => 40,
				'description' => __( 'Contact phone', 'magepeople-ferry-booking-system' ),
			),
			'contact_email'         => array(
				'type'        => 'email',
				'meta'        => '_fbm_contact_email',
				'description' => __( 'Contact email', 'magepeople-ferry-booking-system' ),
			),
			'timezone'              => array(
				'type'        => 'string',
				'meta'        => '_fbm_timezone',
				'max'         => 64,
				'description' => __( 'Timezone', 'magepeople-ferry-booking-system' ),
			),
			'status'                => array(
				'type'        => 'enum',
				'meta'        => '_fbm_status',
				'enum'        => array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ),
				'default'     => self::STATUS_ACTIVE,
				'description' => __( 'Status', 'magepeople-ferry-booking-system' ),
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
