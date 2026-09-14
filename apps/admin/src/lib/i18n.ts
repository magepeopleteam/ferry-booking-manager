/**
 * Translation helpers.
 *
 * Strings are translated in PHP and delivered in the runtime configuration, so
 * every user-facing string still lands in the plugin's .pot file and works with
 * WPML, Polylang and TranslatePress without a second catalogue.
 */

import { fbmConfig } from './config';

/**
 * Translates a source string.
 */
export function fbmText( source: string ): string {
	const dictionary = fbmConfig().i18n;

	return dictionary[ source ] ?? source;
}

/**
 * Translates a source string and substitutes its placeholders.
 *
 * Both `%s` and the numbered `%1$s` form are supported. The numbered form is
 * what lets a translator reorder arguments — many languages need a different
 * word order than English — so a translation may use it even where the source
 * string does not.
 *
 * `%%` is an escaped percent sign, exactly as in PHP's sprintf(). Source
 * strings are shared with the PHP catalogue, so a string that has to render a
 * literal "%" is written the way a translator already expects.
 */
export function fbmFormat( source: string, ...args: Array< string | number > ): string {
	let sequential = 0;

	return fbmText( source ).replace( /%%|%(?:(\d+)\$)?s/g, ( match, position?: string ) => {
		if ( match === '%%' ) {
			return '%';
		}

		const index = position ? Number( position ) - 1 : sequential++;
		const value = args[ index ];

		return value === undefined ? '' : String( value );
	} );
}
