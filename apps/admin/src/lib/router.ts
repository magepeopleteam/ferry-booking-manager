/**
 * Hash router.
 *
 * The dashboard owns a single WordPress admin page, so navigation happens in the
 * fragment (`#/bookings/123`). That keeps deep links shareable and the browser
 * back button working without ever reloading wp-admin.
 */

import { useCallback, useEffect, useState } from 'react';

export interface MpfbsLocation {
	/** Normalised path, always starting with a slash, never trailing. */
	path: string;
	/** Path split into segments, e.g. ["bookings", "123"]. */
	segments: string[];
	/** Parsed query string that followed the path inside the fragment. */
	query: Record< string, string >;
}

export const MPFBS_DEFAULT_PATH = '/dashboard';

/**
 * Parses a raw location fragment into a route descriptor.
 */
export function mpfbsParseHash( hash: string ): MpfbsLocation {
	const raw = hash.replace( /^#/, '' );
	const [ rawPath = '', rawQuery = '' ] = raw.split( '?' );

	let path = decodeURI( rawPath ).trim();

	if ( ! path.startsWith( '/' ) ) {
		path = `/${ path }`;
	}

	path = path.replace( /\/+$/, '' );

	if ( path === '' ) {
		path = MPFBS_DEFAULT_PATH;
	}

	const query: Record< string, string > = {};

	new URLSearchParams( rawQuery ).forEach( ( value, key ) => {
		query[ key ] = value;
	} );

	return {
		path,
		segments: path.split( '/' ).filter( Boolean ),
		query,
	};
}

/**
 * Navigates to a dashboard path.
 */
export function mpfbsNavigate( path: string, replace = false ): void {
	if ( typeof window === 'undefined' ) {
		return;
	}

	const target = `#${ path.startsWith( '/' ) ? path : `/${ path }` }`;

	if ( window.location.hash === target ) {
		return;
	}

	if ( replace ) {
		const url = `${ window.location.pathname }${ window.location.search }${ target }`;
		window.history.replaceState( window.history.state, '', url );
		window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );

		return;
	}

	window.location.hash = target;
}

/**
 * Subscribes a component to fragment changes.
 *
 * Returns `null` until the component has mounted so that the exported markup and
 * the first client render agree; the shell renders its loading state meanwhile.
 */
export function useMpfbsLocation(): MpfbsLocation | null {
	const [ location, setLocation ] = useState< MpfbsLocation | null >( null );

	useEffect( () => {
		const read = (): void => {
			setLocation( mpfbsParseHash( window.location.hash ) );
		};

		read();

		window.addEventListener( 'hashchange', read );

		return () => {
			window.removeEventListener( 'hashchange', read );
		};
	}, [] );

	return location;
}

/**
 * Returns a stable navigate callback.
 */
export function useMpfbsNavigate(): ( path: string, replace?: boolean ) => void {
	return useCallback( ( path: string, replace = false ) => {
		mpfbsNavigate( path, replace );
	}, [] );
}
