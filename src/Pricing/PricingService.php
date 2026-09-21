<?php
/**
 * Pricing engine.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Pricing;

use MPFBS\Availability\Availability;
use MPFBS\Models\PassengerType;
use MPFBS\Models\Route;
use MPFBS\Models\Sailing;
use MPFBS\Models\VehicleType;
use MPFBS\Repositories\PassengerTypeRepository;
use MPFBS\Repositories\RouteRepository;
use MPFBS\Repositories\SailingRepository;
use MPFBS\Repositories\VehicleTypeRepository;
use MPFBS\Support\Money;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The single authority on what a booking costs.
 *
 * The browser may show a running total, but it is a preview: this class decides
 * the price, and a booking is written with the figure it returns. Nothing else
 * multiplies a fare by a quantity.
 *
 * Pricing is deterministic. The same sailing, the same party and the same
 * settings produce the same integer total every time, which is what lets a quote
 * shown at step two be trusted at step four, and lets a refund be recomputed
 * months later and match to the cent.
 *
 * ## How a fare is resolved
 *
 * Most specific wins, in this order:
 *
 * 1. the route's own fare for that passenger or vehicle type;
 * 2. the type's own fare — for a passenger type that is a percentage, the
 *    percentage is taken from whatever the base type resolved to on this route,
 *    so "child is half an adult" keeps meaning that on every crossing;
 * 3. nothing, which is a fare of zero rather than an error, because a new
 *    installation with no prices set should still be able to take a booking.
 *
 * The sailing's own adjustment is applied last, to the fare lines only. It never
 * touches fees or tax: a peak-season surcharge is part of the fare, and taxing
 * it follows from that rather than being a separate decision.
 */
final class PricingService {

	/**
	 * Largest party a single quote may cover.
	 */
	private const MAX_UNITS = 999;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Route repository.
	 *
	 * @var RouteRepository
	 */
	private RouteRepository $routes;

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
	 * @param SailingRepository       $sailings        Sailing repository.
	 * @param RouteRepository         $routes          Route repository.
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param VehicleTypeRepository   $vehicle_types   Vehicle type repository.
	 */
	public function __construct(
		SailingRepository $sailings,
		RouteRepository $routes,
		PassengerTypeRepository $passenger_types,
		VehicleTypeRepository $vehicle_types
	) {
		$this->sailings        = $sailings;
		$this->routes          = $routes;
		$this->passenger_types = $passenger_types;
		$this->vehicle_types   = $vehicle_types;
	}

	/**
	 * Prices a party on a sailing.
	 *
	 * @param array<string, mixed> $request Quote request.
	 * @return Quote|WP_Error
	 */
	public function quote( array $request ) {
		$sailing = $this->sailings->find( (int) ( $request['sailing_id'] ?? 0 ) );

		if ( ! $sailing instanceof Sailing ) {
			return new WP_Error(
				'mpfbs_sailing_not_found',
				__( 'That sailing could not be found.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 404 )
			);
		}

		$passengers = $this->normalise_party( $request['passengers'] ?? array() );
		$vehicles   = $this->normalise_party( $request['vehicles'] ?? array() );

		if ( array() === $passengers && array() === $vehicles ) {
			return new WP_Error(
				'mpfbs_empty_quote',
				__( 'Add at least one passenger or vehicle before asking for a price.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		$party = $this->validate_party( $passengers, $vehicles, $sailing );

		if ( is_wp_error( $party ) ) {
			return $party;
		}

		$return_sailing = null;
		$return_id      = (int) ( $request['return_sailing_id'] ?? 0 );

		if ( $return_id > 0 ) {
			$return_sailing = $this->sailings->find( $return_id );

			if ( ! $return_sailing instanceof Sailing ) {
				return new WP_Error(
					'mpfbs_return_sailing_not_found',
					__( 'That return sailing could not be found.', 'magepeople-ferry-booking-system' ),
					array( 'status' => 404 )
				);
			}
		}

		$settings = PricingSettings::all();

		$quote                    = new Quote();
		$quote->sailing_id        = $sailing->id;
		$quote->return_sailing_id = $return_sailing instanceof Sailing ? $return_sailing->id : 0;
		$quote->currency          = $this->currency();

		$this->add_leg( $quote, $sailing, $passengers, $vehicles, __( 'Outbound', 'magepeople-ferry-booking-system' ) );

		if ( $return_sailing instanceof Sailing ) {
			$this->add_leg( $quote, $return_sailing, $passengers, $vehicles, __( 'Return', 'magepeople-ferry-booking-system' ) );
		}

		$quote->usage = $this->usage( $passengers, $vehicles );

		$this->add_discounts( $quote, $settings, $return_sailing instanceof Sailing, $quote->usage );
		$this->add_fees( $quote, $settings, $passengers, $vehicles );
		$this->add_tax( $quote, $settings );

		/**
		 * Filters a finished quote before it is returned.
		 *
		 * Extensions attach their own pricing adjustments here, after the
		 * base fare is known and before anything is stored.
		 *
		 * @since 1.0.0
		 *
		 * @param Quote                $quote   Finished quote.
		 * @param array<string, mixed> $request Original request.
		 * @param Sailing              $sailing Outbound sailing.
		 */
		return apply_filters( 'mpfbs_quote', $quote, $request, $sailing );
	}

	/**
	 * Returns the per-unit fare of every sellable type, before a sailing is picked.
	 *
	 * The booking form asks for this so a customer can see what a child or a car
	 * costs while they are still building their party, rather than finding out
	 * two screens later. It is deliberately a *guide*: a sailing may carry its
	 * own fare adjustment, and discounts, fees and tax are all decided on the
	 * finished basket, so the number here is a floor and never a total. Where
	 * more than one route joins the two ports the cheapest is quoted, which is
	 * what "from" has to mean if it is to stay honest.
	 *
	 * With no ports chosen the types price against their own base fare, which is
	 * the best that can be said before a crossing is known.
	 *
	 * @param int $origin      Departure port id, or zero.
	 * @param int $destination Arrival port id, or zero.
	 * @return array{currency: string, routed: bool, vehicles_allowed: bool, passengers: array<string, int>, vehicles: array<string, int>}
	 */
	public function fares( int $origin = 0, int $destination = 0 ): array {
		$routes = $origin > 0 && $destination > 0 ? $this->routes->between( $origin, $destination ) : array();

		// Without a route there is still one honest answer: what each type
		// charges on its own terms. Priced against a null route, which is the
		// same path a sailing whose route has been deleted takes.
		$candidates = array() === $routes ? array( null ) : $routes;

		$passengers = array();
		$vehicles   = array();
		$allows     = array() === $routes;

		foreach ( $candidates as $route ) {
			$base = $this->base_fare( $route );

			foreach ( $this->passenger_types->active() as $type ) {
				$fare = $this->passenger_fare( $type, $route, $base );
				$key  = (string) $type->id;

				$passengers[ $key ] = isset( $passengers[ $key ] ) ? min( $passengers[ $key ], $fare ) : $fare;
			}

			if ( null !== $route && ! $route->get( 'allows_vehicles' ) ) {
				continue;
			}

			$allows = true;

			foreach ( $this->vehicle_types->active() as $type ) {
				$fare = $this->vehicle_fare( $type, $route );
				$key  = (string) $type->id;

				$vehicles[ $key ] = isset( $vehicles[ $key ] ) ? min( $vehicles[ $key ], $fare ) : $fare;
			}
		}

		return array(
			'currency'         => $this->currency(),
			'routed'           => array() !== $routes,
			'vehicles_allowed' => $allows,
			// Cast so an empty fare table reaches the browser as {} and not as
			// [], which is what PHP would otherwise encode it to and what would
			// make the client's lookups depend on whether anything was priced.
			'passengers'       => (object) $passengers,
			'vehicles'         => (object) $vehicles,
		);
	}

	/**
	 * Returns the inventory a party consumes.
	 *
	 * @param array<int, int> $passengers Passenger type id => quantity.
	 * @param array<int, int> $vehicles   Vehicle type id => quantity.
	 * @return array<string, float|int>
	 */
	public function usage( array $passengers, array $vehicles ): array {
		$seats  = 0;
		$slots  = 0;
		$metres = 0.0;

		foreach ( $passengers as $type_id => $quantity ) {
			$type = $this->passenger_types->find( (int) $type_id );

			// A lap infant is a passenger on the manifest but not a seat on the
			// deck plan, and availability counts seats.
			if ( $type instanceof PassengerType && $type->occupies_seat() ) {
				$seats += $quantity;
			}
		}

		foreach ( $vehicles as $type_id => $quantity ) {
			$type = $this->vehicle_types->find( (int) $type_id );

			if ( $type instanceof VehicleType ) {
				$slots  += $type->capacity_units() * $quantity;
				$metres += $type->lane_metres() * $quantity;
			}
		}

		return array(
			Availability::PASSENGERS  => $seats,
			Availability::VEHICLES    => $slots,
			Availability::LANE_METRES => round( $metres, 2 ),
		);
	}

	/**
	 * Adds the fare lines for one leg of a journey.
	 *
	 * @param Quote           $quote      Quote being built.
	 * @param Sailing         $sailing    Sailing for this leg.
	 * @param array<int, int> $passengers Passenger type id => quantity.
	 * @param array<int, int> $vehicles   Vehicle type id => quantity.
	 * @param string          $leg        Leg label.
	 * @return void
	 */
	private function add_leg( Quote $quote, Sailing $sailing, array $passengers, array $vehicles, string $leg ): void {
		$route = $this->routes->find( $sailing->route_id() );
		$route = $route instanceof Route ? $route : null;

		$base = $this->base_fare( $route );

		foreach ( $passengers as $type_id => $quantity ) {
			$type = $this->passenger_types->find( (int) $type_id );

			if ( ! $type instanceof PassengerType ) {
				continue;
			}

			$unit = $this->adjust( $this->passenger_fare( $type, $route, $base ), $sailing );

			$quote->add(
				Quote::LINE_PASSENGER,
				$this->leg_label( $leg, $type->name ),
				$quantity,
				$unit,
				array(
					'type_id'    => $type->id,
					'code'       => $type->code(),
					'sailing_id' => $sailing->id,
					'leg'        => $leg,
				)
			);
		}

		foreach ( $vehicles as $type_id => $quantity ) {
			$type = $this->vehicle_types->find( (int) $type_id );

			if ( ! $type instanceof VehicleType ) {
				continue;
			}

			$unit = $this->adjust( $this->vehicle_fare( $type, $route ), $sailing );

			$quote->add(
				Quote::LINE_VEHICLE,
				$this->leg_label( $leg, $type->name ),
				$quantity,
				$unit,
				array(
					'type_id'    => $type->id,
					'code'       => $type->code(),
					'sailing_id' => $sailing->id,
					'leg'        => $leg,
				)
			);
		}
	}

	/**
	 * Returns the fare of the base passenger type on a route.
	 *
	 * @param Route|null $route Route, when it still exists.
	 * @return int
	 */
	private function base_fare( ?Route $route ): int {
		$base = $this->passenger_types->base_type();

		if ( ! $base instanceof PassengerType ) {
			return 0;
		}

		$override = $this->route_price( $route, 'passenger_prices', $base->id );

		return null === $override ? $base->fare( 0 ) : $override;
	}

	/**
	 * Resolves the fare for one passenger type.
	 *
	 * @param PassengerType $type  Passenger type.
	 * @param Route|null    $route Route, when it still exists.
	 * @param int           $base  Base type fare on this route.
	 * @return int
	 */
	private function passenger_fare( PassengerType $type, ?Route $route, int $base ): int {
		$override = $this->route_price( $route, 'passenger_prices', $type->id );

		if ( null !== $override ) {
			return $override;
		}

		return $type->fare( $base );
	}

	/**
	 * Resolves the fare for one vehicle type.
	 *
	 * @param VehicleType $type  Vehicle type.
	 * @param Route|null  $route Route, when it still exists.
	 * @return int
	 */
	private function vehicle_fare( VehicleType $type, ?Route $route ): int {
		$override = $this->route_price( $route, 'vehicle_prices', $type->id );

		return null === $override ? $type->fare() : $override;
	}

	/**
	 * Reads a route's fare table entry.
	 *
	 * @param Route|null $route Route, when it still exists.
	 * @param string     $field Fare table field name.
	 * @param int        $id    Passenger or vehicle type id.
	 * @return int|null Null when the route sets no fare for that type.
	 */
	private function route_price( ?Route $route, string $field, int $id ): ?int {
		if ( ! $route instanceof Route ) {
			return null;
		}

		$table = $route->get( $field );

		if ( ! is_array( $table ) ) {
			return null;
		}

		// Map keys survive storage as strings, and an entry that is present but
		// blank means "no route fare", not "free".
		$key = (string) $id;

		if ( ! array_key_exists( $key, $table ) || '' === $table[ $key ] ) {
			return null;
		}

		return max( 0, (int) $table[ $key ] );
	}

	/**
	 * Applies a sailing's own fare adjustment to one unit price.
	 *
	 * @param int     $amount  Unit price in minor units.
	 * @param Sailing $sailing Sailing entity.
	 * @return int
	 */
	private function adjust( int $amount, Sailing $sailing ): int {
		$type = (string) $sailing->get( 'price_adjustment_type' );

		// A zero fare stays zero under a percentage, but a fixed adjustment on
		// a zero fare is how an operator charges a flat supplement.
		if ( 'none' === $type || ( 0 === $amount && 'fixed' !== $type ) ) {
			return $amount;
		}

		$adjustment = (float) $sailing->get( 'price_adjustment' );

		if ( 'percent' === $type ) {
			return max( 0, $amount + Money::percentage( $amount, $adjustment ) );
		}

		return max( 0, $amount + (int) round( $adjustment ) );
	}

	/**
	 * Adds the discount lines.
	 *
	 * @param Quote                    $quote    Quote being built.
	 * @param array<string, mixed>     $settings Pricing settings.
	 * @param bool                     $is_return Whether the quote covers a round trip.
	 * @param array<string, float|int> $usage     Inventory consumed.
	 * @return void
	 */
	private function add_discounts( Quote $quote, array $settings, bool $is_return, array $usage ): void {
		$gross = $quote->gross();

		if ( $gross <= 0 ) {
			return;
		}

		if ( $is_return && (float) $settings['return_discount'] > 0 ) {
			$quote->add_amount(
				Quote::LINE_DISCOUNT,
				__( 'Return journey discount', 'magepeople-ferry-booking-system' ),
				-Money::percentage( $gross, (float) $settings['return_discount'] ),
				array( 'rule' => 'return' )
			);
		}

		$threshold = (int) $settings['group_discount_from'];
		$seats     = (int) ( $usage[ Availability::PASSENGERS ] ?? 0 );

		if ( $threshold > 0 && $seats >= $threshold && (float) $settings['group_discount'] > 0 ) {
			/*
			 * Taken from the gross rather than from what the return discount
			 * left, so the two rules cannot compound into a bigger reduction
			 * than either was meant to give, and the order they are configured
			 * in never changes the answer.
			 */
			$quote->add_amount(
				Quote::LINE_DISCOUNT,
				sprintf(
					/* translators: %d: number of passengers needed for the discount. */
					__( 'Group discount (%d or more)', 'magepeople-ferry-booking-system' ),
					$threshold
				),
				-Money::percentage( $gross, (float) $settings['group_discount'] ),
				array( 'rule' => 'group' )
			);
		}

		// A stack of percentages must never hand money back.
		if ( $quote->discount() > $gross ) {
			$quote->add_amount(
				Quote::LINE_DISCOUNT,
				__( 'Discount cap', 'magepeople-ferry-booking-system' ),
				$quote->discount() - $gross,
				array( 'rule' => 'cap' )
			);
		}
	}

	/**
	 * Adds the fee lines.
	 *
	 * @param Quote                $quote      Quote being built.
	 * @param array<string, mixed> $settings   Pricing settings.
	 * @param array<int, int>      $passengers Passenger type id => quantity.
	 * @param array<int, int>      $vehicles   Vehicle type id => quantity.
	 * @return void
	 */
	private function add_fees( Quote $quote, array $settings, array $passengers, array $vehicles ): void {
		$label = (string) $settings['fee_label'];

		if ( (int) $settings['booking_fee'] > 0 ) {
			$quote->add_amount( Quote::LINE_FEE, $label, (int) $settings['booking_fee'], array( 'rule' => 'booking' ) );
		}

		$heads = array_sum( $passengers );

		if ( (int) $settings['passenger_fee'] > 0 && $heads > 0 ) {
			$quote->add(
				Quote::LINE_FEE,
				__( 'Passenger fee', 'magepeople-ferry-booking-system' ),
				(int) $heads,
				(int) $settings['passenger_fee'],
				array( 'rule' => 'passenger' )
			);
		}

		$units = array_sum( $vehicles );

		if ( (int) $settings['vehicle_fee'] > 0 && $units > 0 ) {
			$quote->add(
				Quote::LINE_FEE,
				__( 'Vehicle fee', 'magepeople-ferry-booking-system' ),
				(int) $units,
				(int) $settings['vehicle_fee'],
				array( 'rule' => 'vehicle' )
			);
		}
	}

	/**
	 * Adds the tax line.
	 *
	 * @param Quote                $quote    Quote being built.
	 * @param array<string, mixed> $settings Pricing settings.
	 * @return void
	 */
	private function add_tax( Quote $quote, array $settings ): void {
		if ( empty( $settings['tax_enabled'] ) || (float) $settings['tax_rate'] <= 0 ) {
			return;
		}

		$rate    = (float) $settings['tax_rate'];
		$taxable = $quote->subtotal() + ( empty( $settings['tax_applies_to_fees'] ) ? 0 : $quote->fees() );

		if ( $taxable <= 0 ) {
			return;
		}

		$inclusive = PricingSettings::TAX_INCLUSIVE === $settings['tax_mode'];

		/*
		 * An inclusive rate is already inside the fare, so the tax is the part
		 * of the amount that is tax — not the rate applied on top of it. Taking
		 * 20% of a gross figure that already contains 20% would overcharge by a
		 * sixth.
		 */
		$amount = $inclusive
			? (int) round( $taxable - ( $taxable / ( 1 + ( $rate / 100 ) ) ) )
			: Money::percentage( $taxable, $rate );

		if ( 0 === $amount ) {
			return;
		}

		$whole_rate = 0.0 === fmod( $rate, 1.0 );

		$quote->add_amount(
			Quote::LINE_TAX,
			sprintf(
				/* translators: 1: tax label, 2: tax rate. */
				__( '%1$s at %2$s%%', 'magepeople-ferry-booking-system' ),
				(string) $settings['tax_label'],
				number_format_i18n( $rate, $whole_rate ? 0 : 2 )
			),
			$inclusive ? 0 : $amount,
			array(
				'rule'      => 'tax',
				'rate'      => $rate,
				'inclusive' => $inclusive,
				'included'  => $inclusive ? $amount : 0,
			)
		);
	}

	/**
	 * Reduces a submitted party to type id => quantity.
	 *
	 * @param mixed $party Submitted party.
	 * @return array<int, int>
	 */
	private function normalise_party( $party ): array {
		if ( ! is_array( $party ) ) {
			return array();
		}

		$normalised = array();

		foreach ( $party as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$type_id  = (int) ( $entry['type_id'] ?? $entry['id'] ?? $key );
				$quantity = (int) ( $entry['quantity'] ?? $entry['qty'] ?? 0 );
			} else {
				$type_id  = (int) $key;
				$quantity = (int) $entry;
			}

			if ( $type_id < 1 || $quantity < 1 ) {
				continue;
			}

			$normalised[ $type_id ] = ( $normalised[ $type_id ] ?? 0 ) + $quantity;
		}

		return $normalised;
	}

	/**
	 * Applies the per-type booking rules to a party.
	 *
	 * @param array<int, int> $passengers Passenger type id => quantity.
	 * @param array<int, int> $vehicles   Vehicle type id => quantity.
	 * @param Sailing         $sailing    Sailing being priced.
	 * @return true|WP_Error
	 */
	private function validate_party( array $passengers, array $vehicles, Sailing $sailing ) {
		$fields = array();
		$adults = 0;

		if ( array_sum( $passengers ) + array_sum( $vehicles ) > self::MAX_UNITS ) {
			return new WP_Error(
				'mpfbs_party_too_large',
				__( 'That is too large a party for one booking. Please split it.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $passengers as $type_id => $quantity ) {
			$type = $this->passenger_types->find( (int) $type_id );

			if ( ! $type instanceof PassengerType || ! $type->is_active() ) {
				$fields[ 'passengers.' . $type_id ] = __( 'That passenger type is not on sale.', 'magepeople-ferry-booking-system' );
				continue;
			}

			$max = (int) $type->get( 'max_per_booking' );
			$min = (int) $type->get( 'min_per_booking' );

			if ( $max > 0 && $quantity > $max ) {
				$fields[ 'passengers.' . $type_id ] = sprintf(
					/* translators: 1: passenger type, 2: maximum allowed. */
					__( 'At most %2$d %1$s can be booked at once.', 'magepeople-ferry-booking-system' ),
					$type->name,
					$max
				);
			}

			if ( $min > 0 && $quantity < $min ) {
				$fields[ 'passengers.' . $type_id ] = sprintf(
					/* translators: 1: passenger type, 2: minimum required. */
					__( 'At least %2$d %1$s must be booked.', 'magepeople-ferry-booking-system' ),
					$type->name,
					$min
				);
			}

			if ( ! (bool) $type->get( 'requires_adult' ) ) {
				$adults += $quantity;
			}
		}

		// A child or infant travelling alone is not a pricing problem, it is a
		// safeguarding one, so it is refused before a fare is ever shown.
		if ( 0 === $adults && array() !== $passengers ) {
			$fields['passengers'] = __( 'These passenger types must travel with an accompanying adult.', 'magepeople-ferry-booking-system' );
		}

		foreach ( $vehicles as $type_id => $quantity ) {
			$type = $this->vehicle_types->find( (int) $type_id );

			if ( ! $type instanceof VehicleType || ! $type->is_active() ) {
				$fields[ 'vehicles.' . $type_id ] = __( 'That vehicle type is not on sale.', 'magepeople-ferry-booking-system' );
				continue;
			}

			$max = (int) $type->get( 'max_per_booking' );

			if ( $max > 0 && $quantity > $max ) {
				$fields[ 'vehicles.' . $type_id ] = sprintf(
					/* translators: 1: vehicle type, 2: maximum allowed. */
					__( 'At most %2$d %1$s can be booked at once.', 'magepeople-ferry-booking-system' ),
					$type->name,
					$max
				);
			}
		}

		if ( array() !== $vehicles ) {
			$route = $this->routes->find( $sailing->route_id() );

			if ( $route instanceof Route && ! (bool) $route->get( 'allows_vehicles' ) ) {
				$fields['vehicles'] = __( 'This crossing does not carry vehicles.', 'magepeople-ferry-booking-system' );
			}
		}

		if ( array() !== $fields ) {
			return new WP_Error(
				'mpfbs_validation_failed',
				__( 'Please correct the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => $fields,
				)
			);
		}

		return true;
	}

	/**
	 * Builds a fare line label.
	 *
	 * @param string $leg  Leg label.
	 * @param string $name Type name.
	 * @return string
	 */
	private function leg_label( string $leg, string $name ): string {
		return sprintf(
			/* translators: 1: journey leg, 2: passenger or vehicle type. */
			__( '%1$s — %2$s', 'magepeople-ferry-booking-system' ),
			$leg,
			$name
		);
	}

	/**
	 * Returns the currency quotes are expressed in.
	 *
	 * @return string
	 */
	private function currency(): string {
		/*
		 * The same resolution the interface formats with: WooCommerce where it
		 * is active, otherwise the operator's own currency setting. Reading
		 * WooCommerce alone and falling back to a hardcoded EUR stamped every
		 * booking on a site without WooCommerce with a currency the operator
		 * had never chosen, while every screen displayed their real one.
		 */
		$currency = (string) Money::currency()['code'];

		/**
		 * Filters the currency used for quotes.
		 *
		 * @since 1.0.0
		 *
		 * @param string $currency ISO 4217 code.
		 */
		return (string) apply_filters( 'mpfbs_currency', $currency );
	}
}
