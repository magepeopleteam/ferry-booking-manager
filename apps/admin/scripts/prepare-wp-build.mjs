/**
 * Turns the Next.js static export into something WordPress can enqueue.
 *
 * `next build` writes a complete HTML document to `out/`. WordPress cannot serve
 * that document directly — it has to inject the application into an existing
 * admin page — so this step:
 *
 *   1. copies the emitted `_next/` assets into the plugin's assets directory;
 *   2. records the stylesheets and script chunks in document order;
 *   3. extracts the pre-rendered shell markup and the `__NEXT_DATA__` payload.
 *
 * The plugin then enqueues those files with real URLs and prints the shell, so
 * the exported bundle works from any wp-content location without rebuilding.
 */

import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const appRoot = path.resolve( here, '..' );
const exportDir = path.join( appRoot, 'out' );
const pluginRoot = path.resolve( appRoot, '..', '..' );
const targetDir = path.join( pluginRoot, 'assets', 'admin', 'app' );

const ROOT_OPEN = /<div id="__next"[^>]*>/;

/**
 * Fails the build with a readable message.
 *
 * @param {string} message Reason.
 */
function fail( message ) {
	console.error( `\n[fbm] ${ message }\n` );
	process.exit( 1 );
}

/**
 * Extracts every stylesheet href from the exported document.
 *
 * @param {string} html Exported document.
 * @returns {string[]}
 */
function collectStyles( html ) {
	const styles = [];
	const pattern = /<link[^>]+rel="stylesheet"[^>]*>/g;
	let match;

	while ( ( match = pattern.exec( html ) ) !== null ) {
		const href = /href="([^"]+)"/.exec( match[ 0 ] );

		if ( href && href[ 1 ] ) {
			styles.push( href[ 1 ] );
		}
	}

	return styles;
}

/**
 * Extracts every external script src from the exported document, in order.
 *
 * @param {string} html Exported document.
 * @returns {string[]}
 */
function collectScripts( html ) {
	const scripts = [];
	const pattern = /<script[^>]+src="([^"]+)"[^>]*>/g;
	let match;

	while ( ( match = pattern.exec( html ) ) !== null ) {
		if ( match[ 1 ] ) {
			scripts.push( match[ 1 ] );
		}
	}

	return scripts;
}

/**
 * Normalises an emitted asset path to a path relative to the app directory.
 *
 * @param {string} value Raw href or src.
 * @returns {string}
 */
function toRelative( value ) {
	return value.replace( /^\.?\//, '' );
}

/**
 * Pulls the pre-rendered shell out of the exported document.
 *
 * Next always closes the root element immediately before the `__NEXT_DATA__`
 * script, so the last closing tag before it bounds the shell reliably without
 * pulling in an HTML parser.
 *
 * @param {string} html Exported document.
 * @returns {string}
 */
function extractShell( html ) {
	const open = ROOT_OPEN.exec( html );

	if ( ! open || open.index === undefined ) {
		fail( 'Could not find the #__next root in the exported document.' );
	}

	const start = open.index + open[ 0 ].length;
	const dataIndex = html.indexOf( '<script id="__NEXT_DATA__"' );

	if ( dataIndex === -1 ) {
		fail( 'Could not find the __NEXT_DATA__ payload in the exported document.' );
	}

	const end = html.lastIndexOf( '</div>', dataIndex );

	if ( end === -1 || end < start ) {
		fail( 'Could not determine the end of the #__next root.' );
	}

	return html.slice( start, end );
}

/**
 * Pulls the hydration payload out of the exported document.
 *
 * @param {string} html Exported document.
 * @returns {Record<string, unknown>}
 */
function extractNextData( html ) {
	const match = /<script id="__NEXT_DATA__"[^>]*>([\s\S]*?)<\/script>/.exec( html );

	if ( ! match || ! match[ 1 ] ) {
		fail( 'Could not read the __NEXT_DATA__ payload.' );
	}

	try {
		return JSON.parse( match[ 1 ] );
	} catch ( error ) {
		fail( `__NEXT_DATA__ is not valid JSON: ${ error.message }` );
	}

	return {};
}

/**
 * Rejects a shell that carries anything executable.
 *
 * The plugin performs the same check before printing; failing here keeps a bad
 * build from ever shipping.
 *
 * @param {string} shell Shell markup.
 */
function assertInertShell( shell ) {
	const forbidden = [ /<\s*script/i, /<\s*iframe/i, /\son[a-z]+\s*=/i, /javascript\s*:/i ];

	for ( const pattern of forbidden ) {
		if ( pattern.test( shell ) ) {
			fail( `The pre-rendered shell contains executable markup (${ pattern }). Remove it before shipping.` );
		}
	}
}

/**
 * Runs the build step.
 */
async function main() {
	if ( ! existsSync( exportDir ) ) {
		fail( 'No export found. Run "next build" first.' );
	}

	const documentPath = path.join( exportDir, 'index.html' );

	if ( ! existsSync( documentPath ) ) {
		fail( 'The export does not contain index.html.' );
	}

	const html = await readFile( documentPath, 'utf8' );

	const styles = collectStyles( html ).map( toRelative );
	const scripts = collectScripts( html ).map( toRelative );

	if ( scripts.length === 0 ) {
		fail( 'The export produced no script chunks.' );
	}

	const shell = extractShell( html );
	assertInertShell( shell );

	await rm( targetDir, { recursive: true, force: true } );
	await mkdir( targetDir, { recursive: true } );
	await cp( path.join( exportDir, '_next' ), path.join( targetDir, '_next' ), { recursive: true } );

	const manifest = {
		generated: new Date().toISOString(),
		styles,
		scripts,
		html: shell,
		nextData: extractNextData( html ),
	};

	await writeFile( path.join( targetDir, 'fbm-app.json' ), `${ JSON.stringify( manifest, null, '\t' ) }\n`, 'utf8' );
	await writeFile( path.join( targetDir, 'index.html' ), '', 'utf8' );

	console.log(
		`[fbm] Dashboard bundle ready: ${ styles.length } stylesheet(s), ${ scripts.length } script(s) -> assets/admin/app/`
	);
}

main().catch( ( error ) => {
	fail( error.stack || String( error ) );
} );
