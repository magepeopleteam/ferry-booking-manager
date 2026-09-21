/**
 * Currency conversion and formatting.
 *
 * Money crosses the REST boundary as an integer number of minor units — cents,
 * not euros — because that is the only representation that survives arithmetic
 * without rounding drift. Major units exist for two reasons only: to show an
 * operator a price, and to let them type one. Both conversions live here so
 * neither can be done differently on two screens.
 */

import { mpfbsConfig } from './config';

/**
 * Returns the multiplier between major and minor units.
 */
function factor(): number {
	return 10 ** Math.max( 0, mpfbsConfig().currency.decimals );
}

/**
 * Converts minor units to the major-unit number shown in a price input.
 */
export function mpfbsToMajor( minor: number ): number {
	const value = Number( minor );

	return Number.isFinite( value ) ? value / factor() : 0;
}

/**
 * Converts a major-unit number typed by an operator into minor units.
 */
export function mpfbsToMinor( major: number ): number {
	const value = Number( major );

	return Number.isFinite( value ) ? Math.round( value * factor() ) : 0;
}

/**
 * Formats minor units as a currency string using the site's settings.
 *
 * WooCommerce owns these settings when it is active, so a price rendered by the
 * dashboard matches the one the customer sees at checkout.
 */
export function mpfbsFormatMoney( minor: number ): string {
	const currency = mpfbsConfig().currency;
	const value = mpfbsToMajor( minor );
	const negative = value < 0;
	const fixed = Math.abs( value ).toFixed( Math.max( 0, currency.decimals ) );
	const [ whole = '0', fraction = '' ] = fixed.split( '.' );

	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator );
	const amount = fraction === '' ? grouped : `${ grouped }${ currency.decimalSeparator }${ fraction }`;

	const withSymbol =
		currency.position === 'right'
			? `${ amount }${ currency.symbol }`
			: currency.position === 'right_space'
				? `${ amount } ${ currency.symbol }`
				: currency.position === 'left_space'
					? `${ currency.symbol } ${ amount }`
					: `${ currency.symbol }${ amount }`;

	return negative ? `-${ withSymbol }` : withSymbol;
}

/**
 * Returns the step a price input should move in, given the currency decimals.
 */
export function mpfbsMoneyStep(): number {
	return 1 / factor();
}
