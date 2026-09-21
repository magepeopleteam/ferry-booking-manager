/**
 * Reference lists shared by every form.
 *
 * Ports, vessels and routes are needed by most drawers in the dashboard. They
 * are fetched once per session and reused, then invalidated whenever one of
 * those resources is written, so a port created a moment ago appears in the
 * next picker without a page reload.
 */

import { useCallback, useEffect, useState } from 'react';

import { mpfbsRequest } from './api';

export interface PortReference {
	id: number;
	name: string;
	code: string;
	city: string;
	status: string;
}

export interface VesselReference {
	id: number;
	name: string;
	code: string;
	passenger_capacity: number;
	vehicle_capacity: number;
	deck_capacity: number;
	status: string;
}

export interface RouteReference {
	id: number;
	name: string;
	code: string;
	origin_port: number;
	destination_port: number;
	duration: number;
	default_vessel: number;
	allows_vehicles: boolean;
	status: string;
}

export interface PassengerTypeReference {
	id: number;
	name: string;
	code: string;
	min_age: number;
	max_age: number;
	requires_dob: boolean;
	requires_adult: boolean;
	occupies_seat: boolean;
	is_base: boolean;
	price_mode: string;
	base_price: number;
	price_percent: number;
	min_per_booking: number;
	max_per_booking: number;
	status: string;
}

export interface VehicleTypeReference {
	id: number;
	name: string;
	code: string;
	category: string;
	length: number;
	lane_metres: number;
	capacity_units: number;
	included_passengers: number;
	base_price: number;
	price_per_metre: number;
	requires_registration: boolean;
	requires_driver: boolean;
	allows_trailer: boolean;
	max_per_booking: number;
	status: string;
}

export interface MpfbsReferences {
	ports: PortReference[];
	vessels: VesselReference[];
	routes: RouteReference[];
	passenger_types: PassengerTypeReference[];
	vehicle_types: VehicleTypeReference[];
}

const EMPTY: MpfbsReferences = { ports: [], vessels: [], routes: [], passenger_types: [], vehicle_types: [] };

let cache: MpfbsReferences | null = null;
let inflight: Promise< MpfbsReferences > | null = null;
const listeners = new Set< ( value: MpfbsReferences ) => void >();

/**
 * Fetches the reference lists, reusing the cached copy when present.
 */
export function mpfbsLoadReferences(): Promise< MpfbsReferences > {
	if ( cache ) {
		return Promise.resolve( cache );
	}

	if ( ! inflight ) {
		inflight = mpfbsRequest< MpfbsReferences >( 'references' )
			.then( ( result ) => {
				cache = { ...EMPTY, ...result.data };
				listeners.forEach( ( listener ) => listener( cache as MpfbsReferences ) );

				return cache;
			} )
			.catch( ( error: unknown ) => {
				inflight = null;

				throw error;
			} );
	}

	return inflight;
}

/**
 * Drops the cached lists so the next read refetches them.
 */
export function mpfbsInvalidateReferences(): void {
	cache = null;
	inflight = null;
	void mpfbsLoadReferences().catch( () => undefined );
}

/**
 * Subscribes a component to the reference lists.
 */
export function useMpfbsReferences(): { references: MpfbsReferences; loading: boolean; reload: () => void } {
	const [ references, setReferences ] = useState< MpfbsReferences >( cache ?? EMPTY );
	const [ loading, setLoading ] = useState( cache === null );

	useEffect( () => {
		let active = true;

		const listener = ( value: MpfbsReferences ): void => {
			if ( active ) {
				setReferences( value );
			}
		};

		listeners.add( listener );

		mpfbsLoadReferences()
			.then( ( value ) => {
				if ( active ) {
					setReferences( value );
					setLoading( false );
				}
			} )
			.catch( () => {
				if ( active ) {
					setLoading( false );
				}
			} );

		return () => {
			active = false;
			listeners.delete( listener );
		};
	}, [] );

	const reload = useCallback( () => {
		setLoading( true );
		mpfbsInvalidateReferences();
	}, [] );

	return { references, loading, reload };
}
