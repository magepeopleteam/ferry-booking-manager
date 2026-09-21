/**
 * Translation dictionary guard.
 *
 * Dashboard strings are translated in PHP and delivered to the interface in the
 * runtime configuration, keyed by their English source text. That keeps every
 * user-facing string in the plugin's .pot file, but it also means a typo on
 * either side silently falls back to English instead of failing loudly.
 *
 * This compares every mpfbsText()/mpfbsFormat() literal in the TypeScript sources
 * against the keys the PHP dictionary publishes, and fails the build on a
 * mismatch.
 */

import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const sourceDir = path.resolve( here, '..', 'src' );
const assetsFile = path.resolve( here, '..', '..', '..', 'src', 'Core', 'Assets.php' );

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
 * Extracts every string literal passed to mpfbsText/mpfbsFormat.
 *
 * The first argument is scanned as a whole rather than matched as a single
 * literal, so conditional calls such as mpfbsText( flag ? 'A' : 'B' ) contribute
 * both branches.
 *
 * @param {string} code Source text.
 * @returns {string[]}
 */
function literals( code ) {
	const found = [];
	const call = /mpfbs(?:Text|Format)\(/g;
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

		// Only the first argument carries translatable text; later arguments of
		// mpfbsFormat() are substituted values.
		const argument = firstArgument( code.slice( match.index + match[ 0 ].length, index - 1 ) );
		const literal = /'((?:[^'\\]|\\.)*)'/g;
		let inner;

		while ( ( inner = literal.exec( argument ) ) !== null ) {
			found.push( unescape( inner[ 1 ] ) );
		}
	}

	return found;
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
 * Harvests the config-declared strings that reach mpfbsText() indirectly.
 *
 * Resource screens are driven by declarations, so their labels arrive at
 * mpfbsText() as variables. These keys are translated the same way and must be in
 * the dictionary too.
 *
 * @param {string} code Source of the resource configuration module.
 * @returns {string[]}
 */
function configLiterals( code ) {
	const keys = [
		'label',
		'singularLabel',
		'description',
		'searchPlaceholder',
		'emptyHint',
		'hint',
		'placeholder',
		'nameLabel',
	];
	const found = [];

	for ( const key of keys ) {
		const pattern = new RegExp( `\\b${ key }:\\s*'((?:[^'\\\\]|\\\\.)*)'`, 'g' );
		let match;

		while ( ( match = pattern.exec( code ) ) !== null ) {
			found.push( unescape( match[ 1 ] ) );
		}
	}

	// Status slugs are mapped to labels inside the same module.
	const statusMap = /^\s*[a-z_]+:\s*'([A-Z][^']*)',?$/gm;
	let statusMatch;

	while ( ( statusMatch = statusMap.exec( code ) ) !== null ) {
		found.push( statusMatch[ 1 ] );
	}

	return found;
}

const files = await sources( sourceDir );
const used = new Set();

for ( const file of files ) {
	literals( await readFile( file, 'utf8' ) ).forEach( ( value ) => used.add( value ) );
}

const php = await readFile( assetsFile, 'utf8' );
const dictionary = new Set();
const keyPattern = /^\s*'((?:[^'\\]|\\.)*)'\s*=>\s*__\(/gm;
let keyMatch;

while ( ( keyMatch = keyPattern.exec( php ) ) !== null ) {
	dictionary.add( keyMatch[ 1 ].replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' ) );
}

// Route labels come from the route registry, not from an mpfbsText() literal.
const routeLabels = /label:\s*'([^']+)'/g;
const routesFile = await readFile( path.join( sourceDir, 'lib', 'routes.ts' ), 'utf8' );
let labelMatch;

while ( ( labelMatch = routeLabels.exec( routesFile ) ) !== null ) {
	used.add( labelMatch[ 1 ] );
}

// Resource screens are declaration-driven; their labels reach mpfbsText() as
// variables, so they are harvested from the declarations themselves.
const resourcesFile = await readFile( path.join( sourceDir, 'config', 'resources.tsx' ), 'utf8' );
configLiterals( resourcesFile ).forEach( ( value ) => used.add( value ) );

// Empty values appear in default-value objects; they are never translatable.
used.delete( '' );

const missing = [ ...used ].filter( ( value ) => ! dictionary.has( value ) ).sort();
const unused = [ ...dictionary ].filter( ( value ) => ! used.has( value ) ).sort();

if ( unused.length > 0 ) {
	console.log( `[mpfbs] ${ unused.length } dictionary entries are not referenced by the interface:` );
	unused.forEach( ( value ) => console.log( `      · ${ value }` ) );
}

if ( missing.length > 0 ) {
	console.error( `\n[mpfbs] ${ missing.length } interface strings are missing from the PHP dictionary:` );
	missing.forEach( ( value ) => console.error( `      · ${ value }` ) );
	console.error( '\nAdd them to MPFBS\\Core\\Assets::translations() so they reach translators.\n' );
	process.exit( 1 );
}

console.log( `[mpfbs] Translation dictionary is complete: ${ used.size } strings.` );
