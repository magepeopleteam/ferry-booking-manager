/**
 * REST client for the `fbm/v1` namespace.
 *
 * Every request carries the WordPress REST nonce; every response is unwrapped
 * from the plugin envelope so callers deal in plain data or a typed error.
 */

import { fbmConfig } from './config';
import { fbmText } from './i18n';

export interface FbmMeta {
	page?: number;
	per_page?: number;
	total?: number;
	total_pages?: number;
	[ key: string ]: unknown;
}

export interface FbmResult< T > {
	data: T;
	meta: FbmMeta;
}

export class FbmApiError extends Error {
	public readonly code: string;

	public readonly status: number;

	public readonly details: Record< string, unknown >;

	public constructor(
		code: string,
		message: string,
		status: number,
		details: Record< string, unknown > = {}
	) {
		super( message );
		this.name = 'FbmApiError';
		this.code = code;
		this.status = status;
		this.details = details;
	}
}

interface EnvelopeSuccess< T > {
	success: true;
	data: T;
	meta?: FbmMeta;
}

interface EnvelopeError {
	success: false;
	code: string;
	message: string;
	data?: Record< string, unknown >;
}

type Envelope< T > = EnvelopeSuccess< T > | EnvelopeError;

export interface FbmRequestOptions {
	method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
	query?: Record< string, string | number | boolean | undefined | null >;
	body?: unknown;
	signal?: AbortSignal;
}

/**
 * Builds an absolute endpoint URL with an encoded query string.
 *
 * The base is not always a clean path. A site that has not chosen a permalink
 * structure — WordPress's own default, and what every fresh install has — serves
 * the API from `index.php?rest_route=/fbm/v1/`, so the base already carries a
 * query string. Appending ours with a second `?` puts it inside the route value:
 * `?rest_route=/fbm/v1/bookings?page=1` asks for a route literally named
 * "bookings?page=1", which matches nothing, and every listing in the dashboard
 * fails with `rest_no_route` while the endpoints that take no parameters carry
 * on working.
 *
 * Exported because download links are built by hand on four screens and have to
 * agree with this; a URL assembled with a bare `?` is broken the same way.
 */
export function fbmRestUrl( path: string, query?: FbmRequestOptions[ 'query' ] ): string {
	const base = fbmConfig().restUrl.replace( /\/+$/, '' );
	const search = new URLSearchParams();

	/*
	 * A path is allowed to carry its own query string, and it is pulled off and
	 * merged rather than left where it is. On a site with plain permalinks the
	 * REST base is already `index.php?rest_route=/fbm/v1/`, so a second `?`
	 * lands inside the route name — `rest_route=/fbm/v1/reports?from=…` asks
	 * for a route called "reports?from=…", and every such call 404s. Handling
	 * it here rather than only at the call sites means the next one cannot
	 * reintroduce it.
	 */
	const [ rawPath, rawQuery = '' ] = path.split( '?' );
	const clean = ( rawPath ?? '' ).replace( /^\/+/, '' );

	if ( rawQuery !== '' ) {
		new URLSearchParams( rawQuery ).forEach( ( value, key ) => search.append( key, value ) );
	}

	if ( query ) {
		Object.entries( query ).forEach( ( [ key, value ] ) => {
			if ( value === undefined || value === null || value === '' ) {
				return;
			}

			search.append( key, String( value ) );
		} );
	}

	const suffix = search.toString();

	if ( '' === suffix ) {
		return `${ base }/${ clean }`;
	}

	return `${ base }/${ clean }${ base.includes( '?' ) ? '&' : '?' }${ suffix }`;
}

/**
 * Performs a request against the plugin REST namespace.
 */
export async function fbmRequest< T >(
	path: string,
	options: FbmRequestOptions = {}
): Promise< FbmResult< T > > {
	const { method = 'GET', query, body, signal } = options;
	const headers: Record< string, string > = {
		Accept: 'application/json',
		'X-WP-Nonce': fbmConfig().restNonce,
	};

	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	let response: Response;

	try {
		response = await fetch( fbmRestUrl( path, query ), {
			method,
			headers,
			credentials: 'same-origin',
			signal,
			body: body === undefined ? undefined : JSON.stringify( body ),
		} );
	} catch ( error ) {
		if ( error instanceof DOMException && error.name === 'AbortError' ) {
			throw error;
		}

		throw new FbmApiError( 'fbm_network_error', fbmText( 'Something went wrong.' ), 0 );
	}

	let payload: Envelope< T > | null = null;

	try {
		payload = ( await response.json() ) as Envelope< T >;
	} catch {
		payload = null;
	}

	if ( ! payload ) {
		throw new FbmApiError( 'fbm_invalid_response', fbmText( 'Something went wrong.' ), response.status );
	}

	if ( payload.success === false ) {
		throw new FbmApiError(
			payload.code || 'fbm_error',
			payload.message || fbmText( 'Something went wrong.' ),
			response.status,
			payload.data ?? {}
		);
	}

	if ( ! response.ok ) {
		throw new FbmApiError( 'fbm_error', fbmText( 'Something went wrong.' ), response.status );
	}

	return { data: payload.data, meta: payload.meta ?? {} };
}
