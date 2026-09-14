/**
 * First-run setup progress.
 *
 * Shared by the shell, which decides from it whether to lock the dashboard, the
 * sidebar, which shows how far along it is, and the Get started screen, which
 * advances it. One request serves all three.
 */

import { useCallback, useEffect, useState } from 'react';

import { fbmRequest } from './api';

export interface FbmSetupStatus {
	completed: boolean;
	locked: boolean;
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

type Listener = ( status: FbmSetupStatus ) => void;

let cached: FbmSetupStatus | null = null;
let inflight: Promise< FbmSetupStatus > | null = null;
const listeners = new Set< Listener >();

/**
 * Stores a fresh status and tells every subscriber.
 *
 * Called with the body of any setup write, so the lock lifts the moment the
 * server says it may without another round trip.
 */
export function fbmSetSetupStatus( status: FbmSetupStatus ): void {
	cached = status;
	listeners.forEach( ( listener ) => listener( status ) );
}

/**
 * Fetches the status, reusing an in-flight or completed request.
 */
export function fbmFetchSetup( force = false ): Promise< FbmSetupStatus > {
	if ( cached && ! force ) {
		return Promise.resolve( cached );
	}

	if ( ! inflight || force ) {
		inflight = fbmRequest< FbmSetupStatus >( 'setup' )
			.then( ( result ) => {
				fbmSetSetupStatus( result.data );

				return result.data;
			} )
			.finally( () => {
				inflight = null;
			} );
	}

	return inflight;
}

export interface UseSetupResult {
	setup: FbmSetupStatus | null;
	/** True when the status could not be read. The dashboard then stays open. */
	failed: boolean;
	reload: () => void;
}

/**
 * Subscribes a component to the setup status.
 */
export function useFbmSetup(): UseSetupResult {
	const [ setup, setSetup ] = useState< FbmSetupStatus | null >( cached );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		let active = true;
		const listener: Listener = ( status ) => {
			if ( active ) {
				setSetup( status );
			}
		};

		listeners.add( listener );

		fbmFetchSetup().catch( () => {
			// A dashboard that cannot read its setup state is not locked: the
			// lock protects operators from empty screens, and an outage is not
			// a reason to take their bookings away from them.
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
		fbmFetchSetup( true ).catch( () => undefined );
	}, [] );

	return { setup, failed, reload };
}

/**
 * Destinations a manager can still reach while setup is unfinished.
 *
 * The screens setup itself sends people to: the catalogue the wizard builds,
 * the passenger and vehicle types it prices, and the payment and page settings
 * the last step checks. Everything else waits until there is something to sell.
 */
export const FBM_SETUP_OPEN_ROUTES = [ 'get-started', 'setup', 'settings', 'passengers', 'vehicles', 'payments' ];

/**
 * Whether a destination is out of reach while setup is unfinished.
 */
export function fbmSetupBlocks( setup: FbmSetupStatus | null, routeId: string, canManage: boolean ): boolean {
	if ( ! setup || ! setup.locked ) {
		return false;
	}

	return ! ( canManage && FBM_SETUP_OPEN_ROUTES.includes( routeId ) );
}
