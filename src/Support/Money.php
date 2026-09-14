<?php
/**
 * Money handling.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Integer-minor-unit money arithmetic.
 *
 * Every amount the plugin stores or calculates is an integer number of minor
 * units (cents, pence, paise). Binary floating point cannot represent most
 * decimal fractions exactly, so a fare built by adding a passenger price, a
 * vehicle price and a percentage discount in floats will eventually be a cent
 * out — and a booking total that is a cent out is a payment reconciliation
 * problem, not a rounding curiosity.
 *
 * Amounts cross the API boundary as integers too; the interface formats them.
 */
final class Money {

	/**
	 * Converts a user-supplied or stored amount into minor units.
	 *
	 * Accepts "12.50", "12,50", 12.5 and 1250 minor units already normalised by
	 * an earlier call, distinguished by the caller passing an integer.
	 *
	 * @param mixed $value    Amount in major units, or an integer of minor units.
	 * @param int   $decimals Currency decimal places.
	 * @return int
	 */
	public static function to_minor( $value, int $decimals = 2 ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return 0;
		}

		// Accept both decimal separators, and strip grouping characters.
		$raw = str_replace( array( ' ', "\xc2\xa0" ), '', $raw );

		if ( false !== strpos( $raw, ',' ) && false !== strpos( $raw, '.' ) ) {
			// Whichever separator is last is the decimal one.
			$raw = strrpos( $raw, ',' ) > strrpos( $raw, '.' )
				? str_replace( array( '.', ',' ), array( '', '.' ), $raw )
				: str_replace( ',', '', $raw );
		} elseif ( false !== strpos( $raw, ',' ) ) {
			$raw = str_replace( ',', '.', $raw );
		}

		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		$factor = 10 ** max( 0, $decimals );

		// round() before casting: (int) truncates, which would lose a cent.
		return (int) round( ( (float) $raw ) * $factor );
	}

	/**
	 * Converts minor units back to a major-unit decimal string.
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $decimals Currency decimal places.
	 * @return string
	 */
	public static function to_major( int $minor, int $decimals = 2 ): string {
		$factor   = 10 ** max( 0, $decimals );
		$negative = $minor < 0;
		$absolute = abs( $minor );

		$whole    = intdiv( $absolute, $factor );
		$fraction = $absolute % $factor;

		$formatted = 0 === $decimals
			? (string) $whole
			: $whole . '.' . str_pad( (string) $fraction, $decimals, '0', STR_PAD_LEFT );

		return $negative ? '-' . $formatted : $formatted;
	}

	/**
	 * Applies a percentage to an amount, rounding half up.
	 *
	 * @param int   $minor   Amount in minor units.
	 * @param float $percent Percentage, e.g. 12.5 for 12.5%.
	 * @return int
	 */
	public static function percentage( int $minor, float $percent ): int {
		return (int) round( ( $minor * $percent ) / 100 );
	}

	/**
	 * Distributes an amount across n shares without losing or inventing units.
	 *
	 * The remainder is spread one unit at a time across the first shares, so the
	 * parts always sum back to the original amount.
	 *
	 * @param int $minor  Amount in minor units.
	 * @param int $shares Number of shares.
	 * @return int[]
	 */
	public static function allocate( int $minor, int $shares ): array {
		if ( $shares < 1 ) {
			return array();
		}

		$sign      = $minor < 0 ? -1 : 1;
		$absolute  = abs( $minor );
		$base      = intdiv( $absolute, $shares );
		$remainder = $absolute % $shares;
		$parts     = array();

		for ( $index = 0; $index < $shares; $index++ ) {
			$parts[] = $sign * ( $base + ( $index < $remainder ? 1 : 0 ) );
		}

		return $parts;
	}

	/**
	 * Returns the site's currency display settings.
	 *
	 * One resolver for the dashboard, the booking form, tickets and emails, so
	 * a price cannot be shown with a symbol in one place and without it in
	 * another. WooCommerce wins where it is active — it is the thing actually
	 * taking the payment — and otherwise the operator's own currency setting
	 * decides, which is the setting they were asked for.
	 *
	 * @return array<string, mixed>
	 */
	public static function currency(): array {
		$settings = class_exists( '\\FBM\\Settings\\Settings' ) ? \FBM\Settings\Settings::all() : array();
		$code     = strtoupper( trim( (string) ( $settings['currency'] ?? '' ) ) );

		if ( '' === $code ) {
			$code = 'EUR';
		}

		// An operator who has typed their own symbol gets theirs. The table is
		// only a convenience for the codes that have an established one, and it
		// cannot know about a local mark or a code minted after this shipped.
		$symbol = trim( (string) ( $settings['currency_symbol'] ?? '' ) );

		$decimal  = (string) ( $settings['currency_decimal_separator'] ?? '.' );
		$thousand = (string) ( $settings['currency_thousand_separator'] ?? ',' );

		// The two cannot be the same character: "1,284,00" is not a price
		// anybody can read. Grouping is what gives way, because losing it
		// leaves the amount correct where losing the decimal mark does not.
		if ( $decimal === $thousand ) {
			$thousand = '';
		}

		$currency = array(
			'code'              => $code,
			'symbol'            => '' !== $symbol ? $symbol : self::symbol( $code ),
			'position'          => (string) ( $settings['currency_position'] ?? 'left' ),
			'decimals'          => max( 0, min( 4, (int) ( $settings['currency_decimals'] ?? 2 ) ) ),
			'decimalSeparator'  => $decimal,
			'thousandSeparator' => $thousand,
		);

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency['code']              = (string) get_woocommerce_currency();
			$currency['symbol']            = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			$currency['position']          = (string) get_option( 'woocommerce_currency_pos', 'left' );
			$currency['decimals']          = (int) wc_get_price_decimals();
			$currency['decimalSeparator']  = (string) wc_get_price_decimal_separator();
			$currency['thousandSeparator'] = (string) wc_get_price_thousand_separator();
		}

		/**
		 * Filters the currency display settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $currency Currency settings.
		 */
		return (array) apply_filters( 'fbm_currency_settings', $currency );
	}

	/**
	 * Returns a display symbol for an ISO currency code.
	 *
	 * Only the codes a symbol is genuinely expected for are listed. Anything
	 * else falls back to the code itself, which reads as "CHF 84.00" — less
	 * pretty than a symbol and never wrong, which is the right way round for
	 * money.
	 *
	 * @param string $code Three-letter ISO code.
	 * @return string
	 */
	public static function symbol( string $code ): string {
		$symbols = array(
			'EUR' => '€',
			'GBP' => '£',
			'USD' => '$',
			'CAD' => '$',
			'AUD' => '$',
			'NZD' => '$',
			'JPY' => '¥',
			'CNY' => '¥',
			'INR' => '₹',
			'TRY' => '₺',
			'RUB' => '₽',
			'BRL' => 'R$',
			'PHP' => '₱',
			'THB' => '฿',
			'KRW' => '₩',
			'ILS' => '₪',
			'NGN' => '₦',
			'VND' => '₫',
			'UAH' => '₴',
			'PLN' => 'zł',
			'SEK' => 'kr',
			'NOK' => 'kr',
			'DKK' => 'kr',
			'ISK' => 'kr',
			'CHF' => 'CHF',
			'HRK' => 'kn',
			'CZK' => 'Kč',
			'HUF' => 'Ft',
			'RON' => 'lei',
			'BGN' => 'лв',
			'ZAR' => 'R',
			'MAD' => 'MAD',
			'AED' => 'AED',
		);

		/**
		 * Filters the currency code to symbol map.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $symbols ISO code => symbol.
		 */
		$symbols = (array) apply_filters( 'fbm_currency_symbols', $symbols );

		$code = strtoupper( trim( $code ) );

		return isset( $symbols[ $code ] ) ? (string) $symbols[ $code ] : $code;
	}

	/**
	 * Formats an amount for display using the site's currency settings.
	 *
	 * @param int                  $minor    Amount in minor units.
	 * @param array<string, mixed> $currency Currency settings.
	 * @return string
	 */
	public static function format( int $minor, array $currency = array() ): string {
		$decimals  = isset( $currency['decimals'] ) ? (int) $currency['decimals'] : 2;
		$decimal   = isset( $currency['decimalSeparator'] ) ? (string) $currency['decimalSeparator'] : '.';
		$thousand  = isset( $currency['thousandSeparator'] ) ? (string) $currency['thousandSeparator'] : ',';
		$symbol    = isset( $currency['symbol'] ) ? (string) $currency['symbol'] : '';
		$position  = isset( $currency['position'] ) ? (string) $currency['position'] : 'left';
		$major     = self::to_major( $minor, $decimals );
		$formatted = number_format( (float) $major, $decimals, $decimal, $thousand );

		switch ( $position ) {
			case 'right':
				return $formatted . $symbol;

			case 'right_space':
				return $formatted . ' ' . $symbol;

			case 'left_space':
				return $symbol . ' ' . $formatted;

			default:
				return $symbol . $formatted;
		}
	}
}
