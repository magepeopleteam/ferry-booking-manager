/**
 * Reduces Vite's manifest to the two filenames PHP needs.
 *
 * PHP should not have to understand Rollup's manifest shape to enqueue a
 * script, and it should not guess filenames that carry a content hash. This
 * writes one small file with the entry script and stylesheet, and fails the
 * build if either is missing — a half-built bundle that loads without styles
 * looks like a broken site, not a broken build.
 */

import { readFileSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const outDir = join( here, '..', '..', '..', 'assets', 'frontend' );
const vitePath = join( outDir, '.vite', 'manifest.json' );

if ( ! existsSync( vitePath ) ) {
	console.error( '[fbm] Vite produced no manifest at ' + vitePath );
	process.exit( 1 );
}

const manifest = JSON.parse( readFileSync( vitePath, 'utf8' ) );
const entry = Object.values( manifest ).find( ( chunk ) => chunk.isEntry );

if ( ! entry ) {
	console.error( '[fbm] No entry chunk in the Vite manifest.' );
	process.exit( 1 );
}

// With cssCodeSplit disabled the stylesheet usually sits in entry.css, but
// some Vite versions emit it as its own manifest chunk. Either way there is
// exactly one CSS asset in the build.
let css = ( entry.css ?? [] )[ 0 ];

if ( ! css ) {
	const styleChunk = Object.values( manifest ).find( ( chunk ) => String( chunk.file ).endsWith( '.css' ) );
	css = styleChunk ? String( styleChunk.file ) : '';
}

if ( ! css ) {
	console.error( '[fbm] The entry chunk has no stylesheet. Is the stylesheet imported from main.tsx?' );
	process.exit( 1 );
}

writeFileSync(
	join( outDir, 'manifest.json' ),
	JSON.stringify( { js: entry.file, css, built: new Date().toISOString() }, null, '\t' ) + '\n',
	'utf8'
);

rmSync( join( outDir, '.vite' ), { recursive: true, force: true } );

console.log( `[fbm] Booking bundle ready: ${ entry.file } + ${ css }` );
