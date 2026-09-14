<?php
/**
 * Booking party representation.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Booking;

use FBM\Models\Booking;
use FBM\Models\PassengerType;
use FBM\Models\VehicleType;
use FBM\Repositories\PassengerTypeRepository;
use FBM\Repositories\VehicleTypeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the travellers stored on a booking into something staff can read.
 *
 * A booking keeps its party as one JSON row per traveller: a type id and
 * whatever detail fields the operator asked for. That is the right thing to
 * store and the wrong thing to show — nobody can check a passport against
 * `{"type_id":6,"details":{"document_number":"…"}}`. This resolves the type
 * names and the field labels once, so the dashboard, the manifest and the
 * ticket all describe a traveller the same way.
 *
 * Only fields the operator has actually turned on are returned. A detail left
 * over from a form field that was later switched off is dropped rather than
 * shown under a key nobody recognises.
 */
final class PartyPresenter {

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
	 * Type names already resolved during this request, keyed by group and id.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $names = array(
		FieldConfig::GROUP_PASSENGER => array(),
		FieldConfig::GROUP_VEHICLE   => array(),
	);

	/**
	 * Field labels already resolved during this request, keyed by group.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $labels = array();

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
	 * Describes both halves of a booking's party.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array{passengers: array<int, array<string, mixed>>, vehicles: array<int, array<string, mixed>>}
	 */
	public function describe( Booking $booking ): array {
		return array(
			'passengers' => $this->travellers( $booking, 'passengers', FieldConfig::GROUP_PASSENGER ),
			'vehicles'   => $this->travellers( $booking, 'vehicles', FieldConfig::GROUP_VEHICLE ),
		);
	}

	/**
	 * Describes one stored list of travellers.
	 *
	 * @param Booking $booking Booking entity.
	 * @param string  $field   Booking field holding the list.
	 * @param string  $group   Field configuration group the details belong to.
	 * @return array<int, array<string, mixed>>
	 */
	private function travellers( Booking $booking, string $field, string $group ): array {
		$rows = array();

		foreach ( (array) $booking->get( $field ) as $stored ) {
			if ( ! is_string( $stored ) || '' === $stored ) {
				continue;
			}

			$decoded = json_decode( $stored, true );

			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$type_id = (int) ( $decoded['type_id'] ?? 0 );
			$details = isset( $decoded['details'] ) && is_array( $decoded['details'] ) ? $decoded['details'] : array();

			$rows[] = array(
				'type_id'   => $type_id,
				'type_name' => $this->type_name( $group, $type_id ),
				'details'   => $this->details( $group, $details ),
			);
		}

		return $rows;
	}

	/**
	 * Labels the details of one traveller, in the order the form asks for them.
	 *
	 * @param string              $group   Field configuration group.
	 * @param array<string,mixed> $details Stored detail values.
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	private function details( string $group, array $details ): array {
		$labelled = array();

		foreach ( $this->field_labels( $group ) as $key => $label ) {
			$value = isset( $details[ $key ] ) ? trim( (string) $details[ $key ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			$labelled[] = array(
				'key'   => $key,
				'label' => $label,
				'value' => $value,
			);
		}

		return $labelled;
	}

	/**
	 * Returns the configured field labels for a group, keyed by field key.
	 *
	 * @param string $group Field configuration group.
	 * @return array<string, string>
	 */
	private function field_labels( string $group ): array {
		if ( isset( $this->labels[ $group ] ) ) {
			return $this->labels[ $group ];
		}

		$labels = array();

		foreach ( FieldConfig::form( $group ) as $field ) {
			if ( FieldConfig::MODE_OFF === ( $field['mode'] ?? FieldConfig::MODE_OFF ) ) {
				continue;
			}

			$labels[ (string) $field['key'] ] = (string) $field['label'];
		}

		$this->labels[ $group ] = $labels;

		return $labels;
	}

	/**
	 * Returns a type's name, resolving each type at most once per request.
	 *
	 * @param string $group   Field configuration group.
	 * @param int    $type_id Passenger or vehicle type id.
	 * @return string
	 */
	private function type_name( string $group, int $type_id ): string {
		if ( $type_id < 1 ) {
			return '';
		}

		if ( isset( $this->names[ $group ][ $type_id ] ) ) {
			return $this->names[ $group ][ $type_id ];
		}

		$type = FieldConfig::GROUP_VEHICLE === $group
			? $this->vehicle_types->find( $type_id )
			: $this->passenger_types->find( $type_id );

		$name = $type instanceof PassengerType || $type instanceof VehicleType ? $type->name : '';

		$this->names[ $group ][ $type_id ] = $name;

		return $name;
	}
}
