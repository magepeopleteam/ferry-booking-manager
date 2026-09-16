<?php
/**
 * Vehicle type entity.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Models;

use FBM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A class of vehicle that may be carried on a sailing.
 *
 * Deck space is the scarce resource on a ferry, and vehicles consume it very
 * unequally: a bicycle and an articulated truck are not "one vehicle" each.
 * Every type therefore declares both how many vehicle slots it takes and how
 * many lane metres, so an operator can sell against whichever measure their
 * vessel is actually limited by.
 */
final class VehicleType extends Entity {
	public const POST_TYPE = 'fbm_vehicle_type';

	public const STATUS_ACTIVE = 'active';

	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Vehicle categories the plugin ships with.
	 */
	public const CATEGORIES = array(
		'bicycle',
		'motorcycle',
		'car',
		'suv',
		'van',
		'camper',
		'minibus',
		'bus',
		'truck',
		'trailer',
		'other',
	);

	/**
	 * Returns the entity key, e.g. "vessel".
	 *
	 * @return string
	 */
	public static function key(): string {
		return 'vehicle_type';
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
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Returns the plural and singular labels for the post type.
	 *
	 * @return array{plural: string, singular: string}
	 */
	public static function labels(): array {
		return array(
			'plural'   => __( 'Vehicle types', 'magepeople-ferry-booking-system' ),
			'singular' => __( 'Vehicle type', 'magepeople-ferry-booking-system' ),
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
				'meta'        => '_fbm_vt_code',
				'unique'      => true,
				'required'    => true,
				'min'         => 2,
				'max'         => 20,
				'searchable'  => true,
				'description' => __( 'Vehicle type code', 'magepeople-ferry-booking-system' ),
			),
			'category'              => array(
				'type'        => 'enum',
				'meta'        => '_fbm_vt_category',
				'enum'        => self::CATEGORIES,
				'default'     => 'car',
				'description' => __( 'Category', 'magepeople-ferry-booking-system' ),
			),
			'length'                => array(
				'type'        => 'float',
				'meta'        => '_fbm_vt_length',
				'default'     => 0.0,
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Maximum length in metres', 'magepeople-ferry-booking-system' ),
			),
			'width'                 => array(
				'type'        => 'float',
				'meta'        => '_fbm_vt_width',
				'default'     => 0.0,
				'min'         => 0,
				'max'         => 20,
				'description' => __( 'Maximum width in metres', 'magepeople-ferry-booking-system' ),
			),
			'height'                => array(
				'type'        => 'float',
				'meta'        => '_fbm_vt_height',
				'default'     => 0.0,
				'min'         => 0,
				'max'         => 20,
				'description' => __( 'Maximum height in metres', 'magepeople-ferry-booking-system' ),
			),
			'weight'                => array(
				'type'        => 'float',
				'meta'        => '_fbm_vt_weight',
				'default'     => 0.0,
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Maximum weight in kilograms', 'magepeople-ferry-booking-system' ),
			),
			'lane_metres'           => array(
				'type'        => 'float',
				'meta'        => '_fbm_vt_lane_metres',
				'default'     => 0.0,
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Lane metres consumed', 'magepeople-ferry-booking-system' ),
			),
			'capacity_units'        => array(
				'type'        => 'int',
				'meta'        => '_fbm_vt_capacity_units',
				'default'     => 1,
				'min'         => 0,
				'max'         => 50,
				'description' => __( 'Vehicle slots consumed', 'magepeople-ferry-booking-system' ),
			),
			'included_passengers'   => array(
				'type'        => 'int',
				'meta'        => '_fbm_vt_included_passengers',
				'default'     => 0,
				'min'         => 0,
				'max'         => 99,
				'description' => __( 'Passenger fares included in the vehicle fare', 'magepeople-ferry-booking-system' ),
			),
			'base_price'            => array(
				'type'        => 'money',
				'meta'        => '_fbm_vt_base_price',
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Fare', 'magepeople-ferry-booking-system' ),
			),
			'price_per_metre'       => array(
				'type'        => 'money',
				'meta'        => '_fbm_vt_price_per_metre',
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Additional fare per lane metre', 'magepeople-ferry-booking-system' ),
			),
			'requires_registration' => array(
				'type'        => 'bool',
				'meta'        => '_fbm_vt_requires_registration',
				'default'     => true,
				'description' => __( 'Require a registration number', 'magepeople-ferry-booking-system' ),
			),
			'requires_driver'       => array(
				'type'        => 'bool',
				'meta'        => '_fbm_vt_requires_driver',
				'default'     => true,
				'description' => __( 'Require driver details', 'magepeople-ferry-booking-system' ),
			),
			'requires_dimensions'   => array(
				'type'        => 'bool',
				'meta'        => '_fbm_vt_requires_dimensions',
				'default'     => false,
				'description' => __( 'Require the exact dimensions', 'magepeople-ferry-booking-system' ),
			),
			'allows_trailer'        => array(
				'type'        => 'bool',
				'meta'        => '_fbm_vt_allows_trailer',
				'default'     => false,
				'description' => __( 'Allow a trailer', 'magepeople-ferry-booking-system' ),
			),
			'max_per_booking'       => array(
				'type'        => 'int',
				'meta'        => '_fbm_vt_max_per_booking',
				'default'     => 4,
				'min'         => 0,
				'max'         => 99,
				'description' => __( 'Maximum per booking', 'magepeople-ferry-booking-system' ),
			),
			'sort_order'            => array(
				'type'        => 'int',
				'meta'        => '_fbm_vt_sort_order',
				'default'     => 0,
				'min'         => 0,
				'max'         => 999,
				'description' => __( 'Display order', 'magepeople-ferry-booking-system' ),
			),
			'description'           => array(
				'type'        => 'text',
				'meta'        => '_fbm_vt_description',
				'max'         => 500,
				'description' => __( 'Description shown to customers', 'magepeople-ferry-booking-system' ),
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
	 * Returns the vehicle type code.
	 *
	 * @return string
	 */
	public function code(): string {
		return (string) $this->get( 'code' );
	}

	/**
	 * Determines whether the type may be sold.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->get( 'status' );
	}

	/**
	 * Returns the number of vehicle slots one of these consumes.
	 *
	 * @return int
	 */
	public function capacity_units(): int {
		return max( 0, (int) $this->get( 'capacity_units' ) );
	}

	/**
	 * Returns the lane metres one of these consumes.
	 *
	 * @return float
	 */
	public function lane_metres(): float {
		return max( 0.0, (float) $this->get( 'lane_metres' ) );
	}

	/**
	 * Returns the fare for one vehicle of this type, in minor units.
	 *
	 * @return int
	 */
	public function fare(): int {
		$fare      = (int) $this->get( 'base_price' );
		$per_metre = (int) $this->get( 'price_per_metre' );

		if ( $per_metre > 0 ) {
			$fare += (int) round( $per_metre * $this->lane_metres() );
		}

		return $fare;
	}

	/**
	 * Recomputes derived fields immediately before the entity is persisted.
	 *
	 * @return void
	 */
	public function derive(): void {
		// Lane metres default to the declared length. Operators who sell by
		// lane metre almost always mean "as long as the vehicle", and leaving
		// this at zero would let an unlimited number of them onto the deck.
		if ( (float) $this->get( 'lane_metres' ) <= 0.0 ) {
			$this->set( 'lane_metres', max( 0.0, (float) $this->get( 'length' ) ) );
		}
	}
}
