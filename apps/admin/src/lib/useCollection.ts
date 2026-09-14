/**
 * Paginated collection state.
 *
 * Owns the list lifecycle for a resource screen: query parameters, the request
 * itself, pagination metadata and refreshing after a write. Search is debounced
 * and every request aborts the one before it, so fast typing cannot leave a
 * stale response overwriting a newer list.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { fbmRequest, FbmApiError, type FbmMeta } from './api';

export interface CollectionQuery {
	page: number;
	per_page: number;
	search: string;
	status: string;
	orderby: string;
	order: 'asc' | 'desc';
	[ key: string ]: string | number;
}

export interface UseCollectionResult< T > {
	items: T[];
	meta: FbmMeta;
	query: CollectionQuery;
	loading: boolean;
	refreshing: boolean;
	error: FbmApiError | null;
	setQuery: ( patch: Partial< CollectionQuery > ) => void;
	setSearch: ( value: string ) => void;
	setPage: ( page: number ) => void;
	toggleSort: ( field: string ) => void;
	reload: () => void;
}

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Loads and manages one paginated resource collection.
 */
export function useFbmCollection< T >(
	endpoint: string,
	initial: Partial< CollectionQuery > = {}
): UseCollectionResult< T > {
	const [ query, setQueryState ] = useState< CollectionQuery >( {
		page: 1,
		per_page: 20,
		search: '',
		status: '',
		orderby: 'title',
		order: 'asc',
		...initial,
	} );
	const [ searchDraft, setSearchDraft ] = useState( query.search );
	const [ items, setItems ] = useState< T[] >( [] );
	const [ meta, setMeta ] = useState< FbmMeta >( {} );
	const [ error, setError ] = useState< FbmApiError | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ nonce, setNonce ] = useState( 0 );
	const loadedOnce = useRef( false );

	// Debounce only the search term; every other change applies immediately.
	useEffect( () => {
		if ( searchDraft === query.search ) {
			return;
		}

		const timer = setTimeout( () => {
			setQueryState( ( current ) => ( { ...current, search: searchDraft, page: 1 } ) );
		}, SEARCH_DEBOUNCE_MS );

		return () => clearTimeout( timer );
	}, [ searchDraft, query.search ] );

	// A change of endpoint means the current rows belong to a different
	// resource; showing them under the new heading would be worse than showing
	// nothing, so they are dropped before the new request starts.
	useEffect( () => {
		setItems( [] );
		setMeta( {} );
		loadedOnce.current = false;
	}, [ endpoint ] );

	useEffect( () => {
		const controller = new AbortController();

		if ( loadedOnce.current ) {
			setRefreshing( true );
		} else {
			setLoading( true );
		}

		setError( null );

		fbmRequest< T[] >( endpoint, { query, signal: controller.signal } )
			.then( ( result ) => {
				setItems( Array.isArray( result.data ) ? result.data : [] );
				setMeta( result.meta );
				loadedOnce.current = true;
				setLoading( false );
				setRefreshing( false );
			} )
			.catch( ( caught: unknown ) => {
				if ( caught instanceof DOMException && caught.name === 'AbortError' ) {
					return;
				}

				setError(
					caught instanceof FbmApiError ? caught : new FbmApiError( 'fbm_error', String( caught ), 0 )
				);
				setLoading( false );
				setRefreshing( false );
			} );

		return () => controller.abort();
	}, [ endpoint, query, nonce ] );

	const setQuery = useCallback( ( patch: Partial< CollectionQuery > ) => {
		setQueryState( ( current ) => ( { ...current, page: 1, ...patch } ) );
	}, [] );

	const setPage = useCallback( ( page: number ) => {
		setQueryState( ( current ) => ( { ...current, page: Math.max( 1, page ) } ) );
	}, [] );

	const toggleSort = useCallback( ( field: string ) => {
		setQueryState( ( current ) => ( {
			...current,
			orderby: field,
			order: current.orderby === field && current.order === 'asc' ? 'desc' : 'asc',
			page: 1,
		} ) );
	}, [] );

	const reload = useCallback( () => setNonce( ( value ) => value + 1 ), [] );

	return useMemo(
		() => ( {
			items,
			meta,
			query: { ...query, search: searchDraft },
			loading,
			refreshing,
			error,
			setQuery,
			setSearch: setSearchDraft,
			setPage,
			toggleSort,
			reload,
		} ),
		[ items, meta, query, searchDraft, loading, refreshing, error, setQuery, setPage, toggleSort, reload ]
	);
}
