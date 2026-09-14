/**
 * Currency formatting.
 *
 * Amounts arrive from the API as an integer number of minor units and are only
 * ever converted for display. Nothing in the booking flow does arithmetic on a
 * major-unit float.
 */

import { getCurrency } from './config';

/**
 * Formats minor units using the site's currency settings.
 */
export function money( minor: number ): string {
	const currency = getCurrency();
	const value = Number( minor ) / 10 ** Math.max( 0, currency.decimals );
	const negative = value < 0;
	const fixed = Math.abs( value ).toFixed( Math.max( 0, currency.decimals ) );
	const [ whole = '0', fraction = '' ] = fixed.split( '.' );
	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator );
	const amount = fraction === '' ? grouped : `${ grouped }${ currency.decimalSeparator }${ fraction }`;

	const withSymbol =
		currency.position === 'right'
			? `${ amount }${ currency.symbol }`
			: currency.position === 'right_space'
				? `${ amount } ${ currency.symbol }`
				: currency.position === 'left_space'
					? `${ currency.symbol } ${ amount }`
					: `${ currency.symbol }${ amount }`;

	return negative ? `-${ withSymbol }` : withSymbol;
}
