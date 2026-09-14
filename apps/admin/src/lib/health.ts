/**
 * Health resource.
 *
 * A single request is shared by every consumer so the shell status indicator and
 * the dashboard screen never hit the endpoint twice.
 */

import { useCallback, useEffect, useState } from 'react';

import { fbmRequest, FbmApiError } from './api';

export interface FbmHealth {
	status: string;
	version: string;
	php: string;
	wp: string;
	timezone: string;
	locale: string;
	is_rtl: boolean;
	woocommerce: boolean;
	pro_active: boolean;
	capabilities: Record< string, boolean >;
	server_time: string;
}

let inflight: Promise< FbmHealth > | null = null;
let cached: FbmHealth | null = null;

/**
 * Fetches the health resource, reusing an in-flight or completed request.
 */
export function fbmFetchHealth( force = false ): Promise< FbmHealth > {
	if ( force ) {
		inflight = null;
		cached = null;
	}

	if ( cached ) {
		return Promise.resolve( cached );
	}

	if ( ! inflight ) {
		inflight = fbmRequest< FbmHealth >( 'health' )
			.then( ( result ) => {
				cached = result.data;

				return result.data;
			} )
			.catch( ( error: unknown ) => {
				inflight = null;

				throw error;
			} );
	}

	return inflight;
}

export interface UseHealthResult {
	health: FbmHealth | null;
	error: FbmApiError | null;
	loading: boolean;
	reload: () => void;
}

/**
 * Subscribes a component to the health resource.
 */
export function useFbmHealth(): UseHealthResult {
	const [ health, setHealth ] = useState< FbmHealth | null >( cached );
	const [ error, setError ] = useState< FbmApiError | null >( null );
	const [ loading, setLoading ] = useState( cached === null );
	const [ nonce, setNonce ] = useState( 0 );

	useEffect( () => {
		let active = true;

		setLoading( true );
		setError( null );

		fbmFetchHealth( nonce > 0 )
			.then( ( result ) => {
				if ( ! active ) {
					return;
				}

				setHealth( result );
				setLoading( false );
			} )
			.catch( ( caught: unknown ) => {
				if ( ! active ) {
					return;
				}

				setError(
					caught instanceof FbmApiError
						? caught
						: new FbmApiError( 'fbm_error', String( caught ), 0 )
				);
				setLoading( false );
			} );

		return () => {
			active = false;
		};
	}, [ nonce ] );

	const reload = useCallback( () => {
		setNonce( ( value ) => value + 1 );
	}, [] );

	return { health, error, loading, reload };
}
