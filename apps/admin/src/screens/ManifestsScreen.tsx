/**
 * Manifests.
 *
 * The list of who and what is aboard. It is a safety document before it is an
 * operational one, so the counts sit at the top where they can be checked
 * against a headcount, and the export buttons are always reachable: the copy
 * that matters is the one somebody carries up the gangway when the wifi fails.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { SelectField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { Tabs } from '../components/Tabs';
import { FbmApiError, fbmRequest, fbmRestUrl } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmText } from '../lib/i18n';

interface PassengerRow {
	booking: string;
	last_name: string;
	first_name: string;
	age: string;
	date_of_birth: string;
	nationality: string;
	type: string;
	vehicle: string;
	checked_in: string;
	boarded: string;
}

interface VehicleRow {
	booking: string;
	registration: string;
	type: string;
	driver: string;
	length: number;
	height: number;
	lane_metres: number;
	boarded: string;
}

interface Manifest {
	sailing: {
		id: number;
		departure: string;
		arrival: string;
		status: string;
		route: string;
		origin: string;
		destination: string;
		vessel: string;
	};
	passengers: PassengerRow[];
	vehicles: VehicleRow[];
	totals: {
		passengers: number;
		vehicles: number;
		checked_in: number;
		boarded: number;
		lane_metres: number;
	};
}

interface Departure {
	id: number;
	time: string;
	route: string;
	vessel: string;
}

/**
 * Renders the manifests screen.
 */
export function ManifestsScreen(): JSX.Element {
	const config = fbmConfig();

	const [ departures, setDepartures ] = useState< Departure[] >( [] );
	const [ sailingId, setSailingId ] = useState( 0 );
	const [ manifest, setManifest ] = useState< Manifest | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ tab, setTab ] = useState( 'passengers' );

	useEffect( () => {
		if ( ! config.proActive ) {
			return undefined;
		}

		let cancelled = false;

		fbmRequest< { departures?: Departure[] } >( 'dashboard' )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				const rows = response.data.departures ?? [];
				setDepartures( rows );

				// Pre-select the next crossing: on a manifests screen that is
				// almost always the one being asked about.
				if ( rows.length > 0 ) {
					setSailingId( ( current ) => ( current > 0 ? current : ( rows[ 0 ]?.id ?? 0 ) ) );
				}
			} )
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [ config.proActive ] );

	const load = useCallback( () => {
		if ( sailingId === 0 ) {
			setManifest( null );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );
		setError( '' );

		fbmRequest< Manifest >( `manifests/${ sailingId }` )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setManifest( response.data );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ sailingId ] );

	useEffect( () => load(), [ load ] );

	if ( ! config.proActive ) {
		return (
			<>
				<PageHeader
					title={ fbmText( 'Manifests' ) }
					badge={ <span className="fbm-badge fbm-badge--pro">PRO</span> }
				/>
				<EmptyState
					icon="list"
					title={ fbmText( 'Available in MagePeople Ferry Booking System Pro.' ) }
					description={ fbmText( 'A named passenger and vehicle list for every sailing, showing who has checked in and boarded, exportable as CSV or PDF.' ) }
				/>
			</>
		);
	}

	const exportUrl = ( format: string, kind: string ): string =>
		fbmRestUrl( `manifests/${ sailingId }/export`, { format, kind, _wpnonce: config.restNonce } );

	return (
		<>
			<PageHeader
				title={ fbmText( 'Manifests' ) }
				description={ fbmText( 'Who and what is aboard each crossing.' ) }
				actions={
					sailingId > 0 ? (
						<>
							<a className="fbm-button" href={ exportUrl( 'csv', tab ) }>
								{ fbmText( 'Download CSV' ) }
							</a>
							<a className="fbm-button fbm-button--primary" href={ exportUrl( 'pdf', tab ) }>
								{ fbmText( 'Print manifest' ) }
							</a>
						</>
					) : null
				}
			/>

			<div className="fbm-panel">
				<div className="fbm-settings">
					<SelectField
						label={ fbmText( 'Sailing' ) }
						name="manifest_sailing"
						value={ String( sailingId ) }
						options={
							departures.length === 0
								? [ { value: '0', label: fbmText( 'No sailings today' ) } ]
								: departures.map( ( row ) => ( {
										value: String( row.id ),
										label: `${ row.time } · ${ row.route }${ row.vessel ? ` · ${ row.vessel }` : '' }`,
								  } ) )
						}
						onChange={ ( value ) => setSailingId( Number( value ) ) }
					/>
				</div>
			</div>

			{ error !== '' ? <ErrorState message={ error } onRetry={ load } /> : null }

			{ loading ? <LoadingState rows={ 6 } /> : null }

			{ ! loading && manifest ? (
				<>
					<div className="fbm-stat-grid">
						<Count label={ fbmText( 'Passengers' ) } value={ manifest.totals.passengers } />
						<Count label={ fbmText( 'Vehicles' ) } value={ manifest.totals.vehicles } />
						<Count label={ fbmText( 'Checked in' ) } value={ manifest.totals.checked_in } />
						<Count label={ fbmText( 'Boarded' ) } value={ manifest.totals.boarded } />
						<Count
							label={ fbmText( 'Lane metres' ) }
							value={ Math.round( manifest.totals.lane_metres * 10 ) / 10 }
						/>
					</div>

					<Tabs
						label="Manifest"
						active={ tab }
						onSelect={ setTab }
						tabs={ [
							{ id: 'passengers', label: 'Passengers' },
							{ id: 'vehicles', label: 'Vehicles' },
						] }
					/>

					<div className="fbm-panel">
						<div className="fbm-table__scroll">
							{ tab === 'passengers' ? (
								<table className="fbm-table fbm-table--manifest">
									<thead>
										<tr>
											<th scope="col">{ fbmText( 'Booking' ) }</th>
											<th scope="col">{ fbmText( 'Surname' ) }</th>
											<th scope="col">{ fbmText( 'First name' ) }</th>
											<th scope="col">{ fbmText( 'Age' ) }</th>
											<th scope="col">{ fbmText( 'Nationality' ) }</th>
											<th scope="col">{ fbmText( 'Fare type' ) }</th>
											<th scope="col">{ fbmText( 'Vehicle' ) }</th>
											<th scope="col">{ fbmText( 'Checked in' ) }</th>
											<th scope="col">{ fbmText( 'Boarded' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ manifest.passengers.map( ( row, index ) => (
											<tr key={ `${ row.booking }-${ index }` }>
												<td>{ row.booking }</td>
												<td>{ row.last_name }</td>
												<td>{ row.first_name }</td>
												<td>{ row.age }</td>
												<td>{ row.nationality }</td>
												<td>{ row.type }</td>
												<td>{ row.vehicle }</td>
												<td>{ row.checked_in }</td>
												<td>
													{ row.boarded !== '' ? (
														<span className="fbm-pill fbm-pill--positive">{ row.boarded }</span>
													) : null }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) : (
								<table className="fbm-table fbm-table--manifest">
									<thead>
										<tr>
											<th scope="col">{ fbmText( 'Booking' ) }</th>
											<th scope="col">{ fbmText( 'Registration' ) }</th>
											<th scope="col">{ fbmText( 'Type' ) }</th>
											<th scope="col">{ fbmText( 'Driver' ) }</th>
											<th scope="col">{ fbmText( 'Length' ) }</th>
											<th scope="col">{ fbmText( 'Lane metres' ) }</th>
											<th scope="col">{ fbmText( 'Boarded' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ manifest.vehicles.map( ( row, index ) => (
											<tr key={ `${ row.booking }-${ index }` }>
												<td>{ row.booking }</td>
												<td>{ row.registration }</td>
												<td>{ row.type }</td>
												<td>{ row.driver }</td>
												<td>{ row.length > 0 ? `${ row.length } m` : '' }</td>
												<td>{ row.lane_metres > 0 ? `${ row.lane_metres } m` : '' }</td>
												<td>
													{ row.boarded !== '' ? (
														<span className="fbm-pill fbm-pill--positive">{ row.boarded }</span>
													) : null }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
						</div>

						{ ( tab === 'passengers' ? manifest.passengers : manifest.vehicles ).length === 0 ? (
							<EmptyState
								icon="list"
								title={ fbmText( 'Nobody is booked on this sailing yet.' ) }
							/>
						) : null }
					</div>
				</>
			) : null }

			{ ! loading && ! manifest && error === '' && departures.length === 0 ? (
				<EmptyState
					icon="list"
					title={ fbmText( 'No sailings today' ) }
					description={ fbmText( 'Manifests appear once a crossing is scheduled.' ) }
				/>
			) : null }
		</>
	);
}

/**
 * Renders one headline count.
 */
function Count( { label, value }: { label: string; value: number } ): JSX.Element {
	return (
		<div className="fbm-stat">
			<span className="fbm-stat__label">{ label }</span>
			<span className="fbm-stat__value">{ value }</span>
		</div>
	);
}
