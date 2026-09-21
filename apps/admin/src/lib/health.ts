/**
 * Health resource.
 *
 * A single request is shared by every consumer so the shell status indicator and
 * the dashboard screen never hit the endpoint twice.
 */

import { useCallback, useEffect, useState } from 'react';

import { mpfbsRequest, MpfbsApiError } from './api';

export interface MpfbsHealth {
	status: string;
	version: string;
	php: string;
	wp: string;
	timezone: string;
	locale: string;
	is_rtl: boolean;
	woocommerce: boolean;
	capabilities: Record< string, boolean >;
	server_time: string;
}

let inflight: Promise< MpfbsHealth > | null = null;
let cached: MpfbsHealth | null = null;

/**
 * Fetches the health resource, reusing an in-flight or completed request.
 */
export function mpfbsFetchHealth( force = false ): Promise< MpfbsHealth > {
	if ( force ) {
		inflight = null;
		cached = null;
	}

	if ( cached ) {
		return Promise.resolve( cached );
	}

	if ( ! inflight ) {
		inflight = mpfbsRequest< MpfbsHealth >( 'health' )
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
	health: MpfbsHealth | null;
	error: MpfbsApiError | null;
	loading: boolean;
	reload: () => void;
}

/**
 * Subscribes a component to the health resource.
 */
export function useMpfbsHealth(): UseHealthResult {
	const [ health, setHealth ] = useState< MpfbsHealth | null >( cached );
	const [ error, setError ] = useState< MpfbsApiError | null >( null );
	const [ loading, setLoading ] = useState( cached === null );
	const [ nonce, setNonce ] = useState( 0 );

	useEffect( () => {
		let active = true;

		setLoading( true );
		setError( null );

		mpfbsFetchHealth( nonce > 0 )
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
					caught instanceof MpfbsApiError
						? caught
						: new MpfbsApiError( 'mpfbs_error', String( caught ), 0 )
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
