/**
 * REST client.
 *
 * Speaks the plugin's success/error envelope and turns a failure into a typed
 * error carrying the per-field messages the server produced, so a form can show
 * them next to the fields that caused them.
 */

import { config } from './config';
import { t } from './i18n';

export interface Envelope< T > {
	success: boolean;
	data: T;
	meta?: Record< string, unknown >;
	code?: string;
	message?: string;
}

export class ApiError extends Error {
	public readonly code: string;

	public readonly status: number;

	public readonly fields: Record< string, string >;

	public readonly details: Record< string, unknown >;

	public constructor( code: string, message: string, status: number, details: Record< string, unknown > = {} ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.details = details;
		this.fields =
			details.fields && typeof details.fields === 'object'
				? ( details.fields as Record< string, string > )
				: {};
	}
}

export interface RequestOptions {
	method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
	query?: Record< string, string | number | undefined >;
	body?: unknown;
	signal?: AbortSignal;
}

/**
 * Builds an absolute endpoint URL.
 */
function url( path: string, query?: RequestOptions[ 'query' ] ): string {
	const base = config().restUrl.replace( /\/$/, '' );
	const target = new URL( `${ base }/${ path.replace( /^\//, '' ) }`, window.location.origin );

	if ( query ) {
		Object.entries( query ).forEach( ( [ key, value ] ) => {
			if ( value !== undefined && value !== '' ) {
				target.searchParams.set( key, String( value ) );
			}
		} );
	}

	return target.toString();
}

export interface Result< T > {
	data: T;
	meta: Record< string, unknown >;
}

/**
 * Calls an endpoint and unwraps the envelope.
 */
export async function request< T >( path: string, options: RequestOptions = {} ): Promise< T > {
	return ( await requestWithMeta< T >( path, options ) ).data;
}

/**
 * Calls an endpoint and keeps the envelope's meta alongside the payload.
 *
 * Paged listings put the page, the total and the page count in `meta`, so a
 * caller that needs to draw a pager cannot use the plain request().
 */
export async function requestWithMeta< T >( path: string, options: RequestOptions = {} ): Promise< Result< T > > {
	const { method = 'GET', query, body, signal } = options;
	const headers: Record< string, string > = { Accept: 'application/json' };

	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	// The nonce authenticates a signed-in customer so their booking attaches to
	// their account. A guest has none, and the public endpoints do not need one.
	if ( config().restNonce ) {
		headers[ 'X-WP-Nonce' ] = config().restNonce;
	}

	let response: Response;

	try {
		response = await fetch( url( path, query ), {
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

		throw new ApiError( 'fbm_network_error', t( 'Something went wrong.' ), 0 );
	}

	let payload: Envelope< T > | null = null;

	try {
		payload = ( await response.json() ) as Envelope< T >;
	} catch {
		payload = null;
	}

	if ( ! payload ) {
		throw new ApiError( 'fbm_invalid_response', t( 'Something went wrong.' ), response.status );
	}

	if ( payload.success === false ) {
		throw new ApiError(
			payload.code ?? 'fbm_error',
			payload.message ?? t( 'Something went wrong.' ),
			response.status,
			( payload.data ?? {} ) as Record< string, unknown >
		);
	}

	if ( ! response.ok ) {
		throw new ApiError( 'fbm_error', t( 'Something went wrong.' ), response.status );
	}

	return {
		data: payload.data,
		meta: payload.meta ?? {},
	};
}
