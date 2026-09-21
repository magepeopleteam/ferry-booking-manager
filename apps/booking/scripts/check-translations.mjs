/**
 * Translation dictionary guard for the booking application.
 *
 * Customer-facing strings are translated in PHP and handed to the bundle in the
 * runtime configuration, keyed by their English source text. That keeps every
 * string in the plugin's .pot file, but it also means a string the dictionary
 * has never heard of falls back to English silently — which is how two thirds
 * of this form came to be untranslatable without anyone noticing.
 *
 * The dashboard has had this guard since it was built. The booking form is the
 * half a customer actually reads, so it gets one too.
 */

import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const sourceDir = path.resolve( here, '..', 'src' );
const assetsFile = path.resolve( here, '..', '..', '..', 'src', 'Frontend', 'Assets.php' );

/**
 * Recursively lists .ts and .tsx files.
 *
 * @param {string} dir Directory to walk.
 * @returns {Promise<string[]>}
 */
async function sources( dir ) {
	const entries = await readdir( dir, { withFileTypes: true } );
	const files = [];

	for ( const entry of entries ) {
		const full = path.join( dir, entry.name );

		if ( entry.isDirectory() ) {
			files.push( ...( await sources( full ) ) );
		} else if ( /\.tsx?$/.test( entry.name ) ) {
			files.push( full );
		}
	}

	return files;
}

/**
 * Returns the first top-level argument of a call's argument list.
 *
 * @param {string} args Argument text between the call parentheses.
 * @returns {string}
 */
function firstArgument( args ) {
	let depth = 0;
	let quote = null;

	for ( let index = 0; index < args.length; index += 1 ) {
		const character = args[ index ];
		const escaped = index > 0 && args[ index - 1 ] === '\\';

		if ( quote ) {
			if ( character === quote && ! escaped ) {
				quote = null;
			}

			continue;
		}

		if ( character === "'" || character === '"' || character === '`' ) {
			quote = character;
		} else if ( '([{'.includes( character ) ) {
			depth += 1;
		} else if ( ')]}'.includes( character ) ) {
			depth -= 1;
		} else if ( character === ',' && depth === 0 ) {
			return args.slice( 0, index );
		}
	}

	return args;
}

/**
 * Unescapes a JavaScript single-quoted literal.
 *
 * @param {string} value Raw literal body.
 * @returns {string}
 */
function unescape( value ) {
	return value.replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
}

/**
 * Extracts every string literal passed to t()/tf().
 *
 * The first argument is scanned as a whole rather than matched as one literal,
 * so a conditional call such as t( flag ? 'A' : 'B' ) contributes both branches.
 *
 * @param {string} code Source text.
 * @returns {string[]}
 */
function literals( code ) {
	const found = [];
	// A bare t(/tf(, not the tail of an identifier such as format( or split(.
	const call = /(?<![\w$.])tf?\(/g;
	let match;

	while ( ( match = call.exec( code ) ) !== null ) {
		let depth = 1;
		let index = match.index + match[ 0 ].length;

		while ( index < code.length && depth > 0 ) {
			const character = code[ index ];

			if ( character === '(' ) {
				depth += 1;
			} else if ( character === ')' ) {
				depth -= 1;
			}

			index += 1;
		}

		const argument = firstArgument( code.slice( match.index + match[ 0 ].length, index - 1 ) );
		const literal = /'((?:[^'\\]|\\.)*)'/g;
		let inner;

		while ( ( inner = literal.exec( argument ) ) !== null ) {
			found.push( unescape( inner[ 1 ] ) );
		}
	}

	return found;
}

const files = await sources( sourceDir );
const used = new Set();

for ( const file of files ) {
	// The i18n module defines t() itself; its own literals are not UI strings.
	if ( /lib[\\/]i18n\.ts$/.test( file ) ) {
		continue;
	}

	literals( await readFile( file, 'utf8' ) ).forEach( ( value ) => used.add( value ) );
}

const php = await readFile( assetsFile, 'utf8' );
const dictionary = new Set();
const keyPattern = /^\s*'((?:[^'\\]|\\.)*)'\s*=>\s*__\(/gm;
let keyMatch;

while ( ( keyMatch = keyPattern.exec( php ) ) !== null ) {
	dictionary.add( keyMatch[ 1 ].replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' ) );
}

used.delete( '' );

const missing = [ ...used ].filter( ( value ) => ! dictionary.has( value ) ).sort();
const unused = [ ...dictionary ].filter( ( value ) => ! used.has( value ) ).sort();

if ( unused.length > 0 ) {
	console.log( `[mpfbs] ${ unused.length } dictionary entries are not referenced by the booking form:` );
	unused.forEach( ( value ) => console.log( `      · ${ value }` ) );
}

if ( missing.length > 0 ) {
	console.error( `\n[mpfbs] ${ missing.length } booking form strings are missing from the PHP dictionary:` );
	missing.forEach( ( value ) => console.error( `      · ${ value }` ) );
	console.error( '\nAdd them to MPFBS\\Frontend\\Assets::translations() so they reach translators.\n' );
	process.exit( 1 );
}

console.log( `[mpfbs] Booking translations are complete: ${ used.size } strings.` );
