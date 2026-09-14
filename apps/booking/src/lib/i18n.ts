/**
 * Translation helpers.
 *
 * Strings are translated in PHP and delivered in the runtime configuration, so
 * the booking flow works with the site's existing translation plugin and every
 * string still lands in the plugin's .pot file.
 */

import { config } from './config';

/**
 * Translates a source string.
 */
export function t( source: string ): string {
	return config().i18n[ source ] ?? source;
}

/**
 * Translates a source string and substitutes its placeholders.
 *
 * Supports `%s`, the numbered `%1$s` form a translator needs to reorder
 * arguments, and `%%` for a literal percent sign — the same rules as PHP's
 * sprintf(), because the catalogue is shared with PHP.
 */
export function tf( source: string, ...args: Array< string | number > ): string {
	let sequential = 0;

	return t( source ).replace( /%%|%(?:(\d+)\$)?s/g, ( match, position?: string ) => {
		if ( match === '%%' ) {
			return '%';
		}

		const index = position ? Number( position ) - 1 : sequential++;
		const value = args[ index ];

		return value === undefined ? '' : String( value );
	} );
}
