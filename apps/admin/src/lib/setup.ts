/**
 * First-run setup progress.
 *
 * Shared by the shell, which decides from it whether to lock the dashboard, the
 * sidebar, which shows how far along it is, and the Get started screen, which
 * advances it. One request serves all three.
 */

import { useCallback, useEffect, useState } from 'react';

import { mpfbsRequest } from './api';

export interface MpfbsSetupStatus {
	completed: boolean;
	business: boolean;
	crossing: {
		ports: number;
		vessels: number;
		routes: number;
		sailings: number;
		fare: boolean;
		ready: boolean;
	};
	/** Steps finished, 0 to 3. */
	done: number;
	booking_url: string;
	woocommerce: boolean;
	currency: string;
	/** Present only for a user who may edit the settings. */
	details?: {
		company_name: string;
		support_email: string;
		support_phone: string;
		currency: string;
		currency_symbol: string;
	};
}

type Listener = ( status: MpfbsSetupStatus ) => void;

let cached: MpfbsSetupStatus | null = null;
let inflight: Promise< MpfbsSetupStatus > | null = null;
const listeners = new Set< Listener >();

/**
 * Stores a fresh status and tells every subscriber.
 *
 * Called with the body of any setup write, so the lock lifts the moment the
 * server says it may without another round trip.
 */
export function mpfbsSetSetupStatus( status: MpfbsSetupStatus ): void {
	cached = status;
	listeners.forEach( ( listener ) => listener( status ) );
}

/**
 * Fetches the status, reusing an in-flight or completed request.
 */
export function mpfbsFetchSetup( force = false ): Promise< MpfbsSetupStatus > {
	if ( cached && ! force ) {
		return Promise.resolve( cached );
	}

	if ( ! inflight || force ) {
		inflight = mpfbsRequest< MpfbsSetupStatus >( 'setup' )
			.then( ( result ) => {
				mpfbsSetSetupStatus( result.data );

				return result.data;
			} )
			.finally( () => {
				inflight = null;
			} );
	}

	return inflight;
}

export interface UseSetupResult {
	setup: MpfbsSetupStatus | null;
	/** True when the status could not be read. The dashboard then stays open. */
	failed: boolean;
	reload: () => void;
}

/**
 * Subscribes a component to the setup status.
 */
export function useMpfbsSetup(): UseSetupResult {
	const [ setup, setSetup ] = useState< MpfbsSetupStatus | null >( cached );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		let active = true;
		const listener: Listener = ( status ) => {
			if ( active ) {
				setSetup( status );
			}
		};

		listeners.add( listener );

		mpfbsFetchSetup().catch( () => {
			// A dashboard that cannot read its setup state simply does not
			// show setup progress.
			if ( active ) {
				setFailed( true );
			}
		} );

		return () => {
			active = false;
			listeners.delete( listener );
		};
	}, [] );

	const reload = useCallback( () => {
		mpfbsFetchSetup( true ).catch( () => undefined );
	}, [] );

	return { setup, failed, reload };
}
