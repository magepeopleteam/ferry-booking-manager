<?php
/**
 * First-run configuration seeding.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Models\PassengerType;
use FBM\Models\VehicleType;
use FBM\Repositories\PassengerTypeRepository;
use FBM\Repositories\VehicleTypeRepository;
use FBM\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the passenger and vehicle types every ferry operator starts from.
 *
 * Seeding runs on the first `init` after activation rather than inside the
 * activation hook itself: post types are registered on `init`, and the plugin
 * is not loaded when that fires during the activation request.
 *
 * The seed runs exactly once and is never repaired afterwards. An operator who
 * deletes "Student" meant to delete it, and having it silently reappear on the
 * next upgrade would be worse than having no defaults at all.
 */
final class Seeder {

	/**
	 * Option marking the seed as done.
	 */
	private const FLAG = 'defaults_seeded';

	/**
	 * Passenger type repository.
	 *
	 * @var PassengerTypeRepository
	 */
	private PassengerTypeRepository $passenger_types;

	/**
	 * Vehicle type repository.
	 *
	 * @var VehicleTypeRepository
	 */
	private VehicleTypeRepository $vehicle_types;

	/**
	 * Constructor.
	 *
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param VehicleTypeRepository   $vehicle_types   Vehicle type repository.
	 */
	public function __construct( PassengerTypeRepository $passenger_types, VehicleTypeRepository $vehicle_types ) {
		$this->passenger_types = $passenger_types;
		$this->vehicle_types   = $vehicle_types;
	}

	/**
	 * Attaches the seeding hook.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'maybe_seed' ), 20 );
	}

	/**
	 * Seeds the shipped defaults once.
	 *
	 * @return void
	 */
	public function maybe_seed(): void {
		if ( Options::get_bool( self::FLAG ) ) {
			return;
		}

		// Claim the flag before writing anything. Two concurrent requests
		// arriving on the first page load after activation would otherwise
		// both find an empty catalogue and seed it twice.
		Options::set( self::FLAG, 1, true );

		$this->seed_passenger_types();
		$this->seed_vehicle_types();

		/**
		 * Fires after the shipped passenger and vehicle types are installed.
		 *
		 * @since 1.0.0
		 */
		do_action( 'fbm_defaults_seeded' );
	}

	/**
	 * Returns the passenger types the plugin ships with.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function passenger_type_defaults(): array {
		$defaults = array(
			array(
				'name'       => __( 'Adult', 'ferry-booking-manager' ),
				'code'       => 'ADULT',
				'min_age'    => 18,
				'max_age'    => PassengerType::NO_AGE_LIMIT,
				'is_base'    => true,
				'sort_order' => 10,
			),
			array(
				'name'       => __( 'Senior', 'ferry-booking-manager' ),
				'code'       => 'SENIOR',
				'min_age'    => 65,
				'max_age'    => PassengerType::NO_AGE_LIMIT,
				'price_mode' => PassengerType::PRICE_PERCENT,
				'percent'    => 80.0,
				'sort_order' => 20,
			),
			array(
				'name'       => __( 'Student', 'ferry-booking-manager' ),
				'code'       => 'STUDENT',
				'min_age'    => 16,
				'max_age'    => 30,
				'price_mode' => PassengerType::PRICE_PERCENT,
				'percent'    => 85.0,
				'sort_order' => 30,
			),
			array(
				'name'       => __( 'Child', 'ferry-booking-manager' ),
				'code'       => 'CHILD',
				'min_age'    => 2,
				'max_age'    => 17,
				'price_mode' => PassengerType::PRICE_PERCENT,
				'percent'    => 50.0,
				'dob'        => true,
				'adult'      => true,
				'sort_order' => 40,
			),
			array(
				'name'       => __( 'Infant', 'ferry-booking-manager' ),
				'code'       => 'INFANT',
				'min_age'    => 0,
				'max_age'    => 1,
				'price_mode' => PassengerType::PRICE_FREE,
				'dob'        => true,
				'adult'      => true,
				'seat'       => false,
				'max_each'   => 2,
				'sort_order' => 50,
			),
		);

		/**
		 * Filters the passenger types installed on first run.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $defaults Seed definitions.
		 */
		return (array) apply_filters( 'fbm_passenger_type_defaults', $defaults );
	}

	/**
	 * Returns the vehicle types the plugin ships with.
	 *
	 * Lane metres are the shipped measure of deck impact because that is what
	 * a vehicle deck is actually limited by; an operator who counts slots
	 * instead can ignore the column entirely.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function vehicle_type_defaults(): array {
		$defaults = array(
			array(
				'name'       => __( 'Bicycle', 'ferry-booking-manager' ),
				'code'       => 'BICYCLE',
				'category'   => 'bicycle',
				'length'     => 1.8,
				'lane'       => 0.6,
				'reg'        => false,
				'driver'     => false,
				'max_each'   => 10,
				'sort_order' => 10,
			),
			array(
				'name'       => __( 'Motorcycle', 'ferry-booking-manager' ),
				'code'       => 'MOTORCYCLE',
				'category'   => 'motorcycle',
				'length'     => 2.2,
				'lane'       => 1.2,
				'sort_order' => 20,
			),
			array(
				'name'       => __( 'Car', 'ferry-booking-manager' ),
				'code'       => 'CAR',
				'category'   => 'car',
				'length'     => 4.5,
				'height'     => 1.9,
				'sort_order' => 30,
			),
			array(
				'name'       => __( 'Car with trailer', 'ferry-booking-manager' ),
				'code'       => 'CAR_TRAILER',
				'category'   => 'car',
				'length'     => 8.0,
				'height'     => 1.9,
				'trailer'    => true,
				'sort_order' => 40,
			),
			array(
				'name'       => __( 'SUV or 4x4', 'ferry-booking-manager' ),
				'code'       => 'SUV',
				'category'   => 'suv',
				'length'     => 5.0,
				'height'     => 2.2,
				'sort_order' => 50,
			),
			array(
				'name'       => __( 'Van', 'ferry-booking-manager' ),
				'code'       => 'VAN',
				'category'   => 'van',
				'length'     => 6.0,
				'height'     => 2.6,
				'sort_order' => 60,
			),
			array(
				'name'       => __( 'Camper or motorhome', 'ferry-booking-manager' ),
				'code'       => 'CAMPER',
				'category'   => 'camper',
				'length'     => 7.5,
				'height'     => 3.2,
				'units'      => 2,
				'dimensions' => true,
				'sort_order' => 70,
			),
			array(
				'name'       => __( 'Minibus', 'ferry-booking-manager' ),
				'code'       => 'MINIBUS',
				'category'   => 'minibus',
				'length'     => 7.0,
				'height'     => 2.8,
				'units'      => 2,
				'sort_order' => 80,
			),
			array(
				'name'       => __( 'Coach or bus', 'ferry-booking-manager' ),
				'code'       => 'BUS',
				'category'   => 'bus',
				'length'     => 12.0,
				'height'     => 4.0,
				'units'      => 3,
				'dimensions' => true,
				'max_each'   => 2,
				'sort_order' => 90,
			),
			array(
				'name'       => __( 'Truck', 'ferry-booking-manager' ),
				'code'       => 'TRUCK',
				'category'   => 'truck',
				'length'     => 16.5,
				'height'     => 4.0,
				'units'      => 4,
				'dimensions' => true,
				'trailer'    => true,
				'max_each'   => 2,
				'sort_order' => 100,
			),
		);

		/**
		 * Filters the vehicle types installed on first run.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $defaults Seed definitions.
		 */
		return (array) apply_filters( 'fbm_vehicle_type_defaults', $defaults );
	}

	/**
	 * Creates the shipped passenger types.
	 *
	 * @return void
	 */
	private function seed_passenger_types(): void {
		foreach ( self::passenger_type_defaults() as $seed ) {
			$code = isset( $seed['code'] ) ? (string) $seed['code'] : '';

			if ( '' === $code || $this->passenger_types->find_conflicting_id( '_fbm_pt_code', $code ) > 0 ) {
				continue;
			}

			$this->passenger_types->save(
				array(
					'code'            => $code,
					'min_age'         => isset( $seed['min_age'] ) ? (int) $seed['min_age'] : PassengerType::NO_AGE_LIMIT,
					'max_age'         => isset( $seed['max_age'] ) ? (int) $seed['max_age'] : PassengerType::NO_AGE_LIMIT,
					'requires_dob'    => ! empty( $seed['dob'] ),
					'requires_adult'  => ! empty( $seed['adult'] ),
					'occupies_seat'   => ! isset( $seed['seat'] ) || (bool) $seed['seat'],
					'is_base'         => ! empty( $seed['is_base'] ),
					'price_mode'      => isset( $seed['price_mode'] ) ? (string) $seed['price_mode'] : PassengerType::PRICE_FIXED,
					'base_price'      => 0,
					'price_percent'   => isset( $seed['percent'] ) ? (float) $seed['percent'] : 100.0,
					'min_per_booking' => 0,
					'max_per_booking' => isset( $seed['max_each'] ) ? (int) $seed['max_each'] : 9,
					'sort_order'      => isset( $seed['sort_order'] ) ? (int) $seed['sort_order'] : 0,
					'description'     => '',
					'status'          => PassengerType::STATUS_ACTIVE,
				),
				0,
				isset( $seed['name'] ) ? (string) $seed['name'] : $code
			);
		}
	}

	/**
	 * Creates the shipped vehicle types.
	 *
	 * @return void
	 */
	private function seed_vehicle_types(): void {
		foreach ( self::vehicle_type_defaults() as $seed ) {
			$code = isset( $seed['code'] ) ? (string) $seed['code'] : '';

			if ( '' === $code || $this->vehicle_types->find_conflicting_id( '_fbm_vt_code', $code ) > 0 ) {
				continue;
			}

			$length = isset( $seed['length'] ) ? (float) $seed['length'] : 0.0;

			$this->vehicle_types->save(
				array(
					'code'                  => $code,
					'category'              => isset( $seed['category'] ) ? (string) $seed['category'] : 'car',
					'length'                => $length,
					'width'                 => 0.0,
					'height'                => isset( $seed['height'] ) ? (float) $seed['height'] : 0.0,
					'weight'                => 0.0,
					'lane_metres'           => isset( $seed['lane'] ) ? (float) $seed['lane'] : $length,
					'capacity_units'        => isset( $seed['units'] ) ? (int) $seed['units'] : 1,
					'included_passengers'   => 0,
					'base_price'            => 0,
					'price_per_metre'       => 0,
					'requires_registration' => ! isset( $seed['reg'] ) || (bool) $seed['reg'],
					'requires_driver'       => ! isset( $seed['driver'] ) || (bool) $seed['driver'],
					'requires_dimensions'   => ! empty( $seed['dimensions'] ),
					'allows_trailer'        => ! empty( $seed['trailer'] ),
					'max_per_booking'       => isset( $seed['max_each'] ) ? (int) $seed['max_each'] : 4,
					'sort_order'            => isset( $seed['sort_order'] ) ? (int) $seed['sort_order'] : 0,
					'description'           => '',
					'status'                => VehicleType::STATUS_ACTIVE,
				),
				0,
				isset( $seed['name'] ) ? (string) $seed['name'] : $code
			);
		}
	}
}
