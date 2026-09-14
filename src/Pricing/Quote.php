<?php
/**
 * Priced quote.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Pricing;

use FBM\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An itemised price, in minor units, that adds up.
 *
 * Every amount here is an integer number of cents. Nothing is stored as a float
 * and nothing is rounded twice: a quote that disagrees with the payment taken by
 * one cent is a reconciliation problem someone has to do by hand.
 *
 * The line list is the audit trail. It is what the customer sees, what the
 * confirmation email prints, and what an operator reads back when a passenger
 * asks why they were charged what they were charged.
 */
final class Quote {

	public const LINE_PASSENGER = 'passenger';
	public const LINE_VEHICLE   = 'vehicle';
	public const LINE_EXTRA     = 'extra';
	public const LINE_DISCOUNT  = 'discount';
	public const LINE_FEE       = 'fee';
	public const LINE_TAX       = 'tax';

	/**
	 * Itemised lines.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lines = array();

	/**
	 * Currency code the quote is expressed in.
	 *
	 * @var string
	 */
	public string $currency = '';

	/**
	 * Sailing the quote is for.
	 *
	 * @var int
	 */
	public int $sailing_id = 0;

	/**
	 * Return sailing, when the quote covers a round trip.
	 *
	 * @var int
	 */
	public int $return_sailing_id = 0;

	/**
	 * Inventory the quote consumes, keyed by availability measure.
	 *
	 * @var array<string, float|int>
	 */
	public array $usage = array();

	/**
	 * Adds a line.
	 *
	 * @param string               $type      One of the LINE_* constants.
	 * @param string               $label     Human readable label.
	 * @param int                  $quantity  Number of units.
	 * @param int                  $unit      Price per unit in minor units.
	 * @param array<string, mixed> $context   Extra data, e.g. the type id.
	 * @return void
	 */
	public function add( string $type, string $label, int $quantity, int $unit, array $context = array() ): void {
		$this->lines[] = array_merge(
			array(
				'type'     => $type,
				'label'    => $label,
				'quantity' => $quantity,
				'unit'     => $unit,
				'amount'   => $quantity * $unit,
			),
			$context
		);
	}

	/**
	 * Adds a line whose total is not a simple quantity times unit price.
	 *
	 * @param string               $type    One of the LINE_* constants.
	 * @param string               $label   Human readable label.
	 * @param int                  $amount  Total in minor units; negative for a reduction.
	 * @param array<string, mixed> $context Extra data.
	 * @return void
	 */
	public function add_amount( string $type, string $label, int $amount, array $context = array() ): void {
		$this->lines[] = array_merge(
			array(
				'type'     => $type,
				'label'    => $label,
				'quantity' => 1,
				'unit'     => $amount,
				'amount'   => $amount,
			),
			$context
		);
	}

	/**
	 * Returns every line.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * Sums the lines of one type.
	 *
	 * @param string ...$types Line types.
	 * @return int
	 */
	public function sum( string ...$types ): int {
		$total = 0;

		foreach ( $this->lines as $line ) {
			if ( in_array( $line['type'], $types, true ) ) {
				$total += (int) $line['amount'];
			}
		}

		return $total;
	}

	/**
	 * Returns the fare before discounts, fees and tax.
	 *
	 * @return int
	 */
	public function gross(): int {
		return $this->sum( self::LINE_PASSENGER, self::LINE_VEHICLE, self::LINE_EXTRA );
	}

	/**
	 * Returns the discount as a positive amount.
	 *
	 * @return int
	 */
	public function discount(): int {
		return abs( $this->sum( self::LINE_DISCOUNT ) );
	}

	/**
	 * Returns the fee total.
	 *
	 * @return int
	 */
	public function fees(): int {
		return $this->sum( self::LINE_FEE );
	}

	/**
	 * Returns the tax total.
	 *
	 * @return int
	 */
	public function tax(): int {
		return $this->sum( self::LINE_TAX );
	}

	/**
	 * Returns the net fare after discount, before fees and tax.
	 *
	 * @return int
	 */
	public function subtotal(): int {
		return max( 0, $this->gross() - $this->discount() );
	}

	/**
	 * Returns the amount actually payable.
	 *
	 * @return int
	 */
	public function total(): int {
		return max( 0, $this->subtotal() + $this->fees() + $this->tax() );
	}

	/**
	 * Serialises the quote for an API response.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$payload = array(
			'sailing_id'        => $this->sailing_id,
			'return_sailing_id' => $this->return_sailing_id,
			'currency'          => $this->currency,
			'lines'             => $this->lines,
			'usage'             => $this->usage,
			'gross'             => $this->gross(),
			'discount'          => $this->discount(),
			'subtotal'          => $this->subtotal(),
			'fees'              => $this->fees(),
			'tax'               => $this->tax(),
			'total'             => $this->total(),
			'formatted'         => array(
				'gross'    => Money::to_major( $this->gross() ),
				'discount' => Money::to_major( $this->discount() ),
				'subtotal' => Money::to_major( $this->subtotal() ),
				'fees'     => Money::to_major( $this->fees() ),
				'tax'      => Money::to_major( $this->tax() ),
				'total'    => Money::to_major( $this->total() ),
			),
		);

		/**
		 * Filters a serialised quote.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Serialised quote.
		 * @param Quote                $quote   Quote instance.
		 */
		return (array) apply_filters( 'fbm_serialize_quote', $payload, $this );
	}
}
