/**
 * Per-route fares for one vehicle type.
 *
 * A vehicle type carries a default fare, and almost every operator then
 * charges something different for the same car on a twelve-minute harbour hop
 * and an eighty-minute Sound crossing. Those overrides live on the route — one
 * fare table per route — which is the right place to store them and the wrong
 * place to be sent to halfway through adding a vehicle type.
 *
 * So this edits the same tables from the other side: one row per route, for
 * this type only. Pricing → Fares by route still edits them by route, and both
 * screens write the same field, so neither is a second copy of the numbers.
 */

import { useEffect, useState, type JSX } from 'react';

import { LoadingState } from './States';
import { mpfbsRequest } from '../lib/api';
import { mpfbsConfig } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsMoneyStep, mpfbsToMajor } from '../lib/money';
import { useMpfbsReferences } from '../lib/references';

/** Route fares keyed by type id, in minor units. A blank means "no override". */
export type FareOverrides = Record< number, string >;

export interface RouteFaresFieldProps {
	/** The vehicle type being edited, or 0 while it is still being created. */
	typeId: number;
	/** The type's own default fare, in the major units the form works in. */
	defaultFare: number;
	values: FareOverrides;
	onChange: ( values: FareOverrides ) => void;
}

interface RouteFares {
	id: number;
	name: string;
	allows_vehicles?: boolean;
	vehicle_prices?: Record< string, unknown >;
}

/**
 * Renders one fare input per route.
 */
export function RouteFaresField( { typeId, defaultFare, values, onChange }: RouteFaresFieldProps ): JSX.Element {
	const { references } = useMpfbsReferences();
	const [ routes, setRoutes ] = useState< RouteFares[] | null >( null );
	const currency = mpfbsConfig().currency;

	/*
	 * The reference list carries route names but not their fare tables, so the
	 * routes are read in full once the step is opened. Reading them here rather
	 * than with the form means an operator who never reaches this step never
	 * pays for it.
	 */
	useEffect( () => {
		let cancelled = false;

		Promise.all(
			references.routes.map( ( route ) =>
				mpfbsRequest< RouteFares >( `routes/${ route.id }` )
					.then( ( response ) => response.data )
					.catch( () => null )
			)
		).then( ( loaded ) => {
			if ( cancelled ) {
				return;
			}

			setRoutes( loaded.filter( ( route ): route is RouteFares => route !== null ) );
		} );

		return () => {
			cancelled = true;
		};
	}, [ references.routes ] );

	// Seeds the inputs from what each route already charges for this type, and
	// only once the routes have arrived — before that there is nothing to seed.
	useEffect( () => {
		if ( routes === null || typeId < 1 ) {
			return;
		}

		const seeded: FareOverrides = {};

		routes.forEach( ( route ) => {
			const stored = ( route.vehicle_prices ?? {} )[ String( typeId ) ];

			if ( stored !== undefined && stored !== '' ) {
				seeded[ route.id ] = String( mpfbsToMajor( Number( stored ) ) );
			}
		} );

		onChange( seeded );
		// The seed runs once per set of routes: re-running it on every change
		// would overwrite what the operator is typing.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ routes, typeId ] );

	if ( routes === null ) {
		return <LoadingState rows={ 3 } />;
	}

	if ( routes.length === 0 ) {
		return (
			<p className="mpfbs-record__empty">
				{ mpfbsText( 'There are no routes yet. Add one and its fare for this vehicle can be set here.' ) }
			</p>
		);
	}

	return (
		<>
			<h3 className="mpfbs-subheading">{ mpfbsText( 'Fare on each route' ) }</h3>
			<p className="mpfbs-form__note">
				{ mpfbsFormat(
					'Leave a route blank to charge this type’s own fare of %s. A route that carries no vehicles is not listed.',
					`${ currency.symbol }${ defaultFare.toFixed( currency.decimals ) }`
				) }
			</p>

			<div className="mpfbs-fieldgrid">
				{ routes
					.filter( ( route ) => route.allows_vehicles !== false )
					.map( ( route ) => (
						<div className="mpfbs-fieldrow" key={ route.id }>
							<div className="mpfbs-fieldrow__label">
								<span className="mpfbs-fieldrow__name">{ route.name }</span>
							</div>
							<div className="mpfbs-fare">
								<span className="mpfbs-fare__symbol" aria-hidden="true">
									{ currency.symbol }
								</span>
								<input
									type="number"
									className="mpfbs-input mpfbs-input--money"
									min={ 0 }
									step={ mpfbsMoneyStep() }
									value={ values[ route.id ] ?? '' }
									placeholder={ mpfbsText( 'Default' ) }
									aria-label={ mpfbsFormat( 'Fare for %s', route.name ) }
									onChange={ ( event ) => {
										const next = { ...values };

										if ( event.target.value === '' ) {
											delete next[ route.id ];
										} else {
											next[ route.id ] = event.target.value;
										}

										onChange( next );
									} }
								/>
							</div>
						</div>
					) ) }
			</div>
		</>
	);
}
