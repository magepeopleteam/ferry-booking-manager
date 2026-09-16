/**
 * Reports.
 *
 * Opens on the last thirty days, because that is the question being asked
 * nine times out of ten and a screen that starts empty makes an operator work
 * before it tells them anything.
 *
 * Revenue and refunds are shown side by side rather than netted off. "We took
 * €40,000 and gave €3,000 back" is the shape of the question; a single net
 * figure hides the second half, which is the half worth watching.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { SelectField, TextField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { FbmApiError, fbmRequest, fbmRestUrl } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';
import { useFbmReferences } from '../lib/references';

interface Ranked {
	key: string;
	label: string;
	amount: number;
	count: number;
}

interface DayPoint {
	date: string;
	amount: number;
	count: number;
}

interface Report {
	range: { from: string; to: string; days: number };
	totals: {
		bookings: number;
		cancelled: number;
		passengers: number;
		vehicles: number;
		revenue: number;
		collected: number;
		refunded: number;
		outstanding: number;
	};
	capped: boolean;
	read: number;
	daily: DayPoint[];
	routes: Ranked[];
	vessels: Ranked[];
	methods: Ranked[];
	channels: Ranked[];
	passenger_types: Ranked[];
	vehicle_types: Ranked[];
	extras: Ranked[];
}

/** Ready-made ranges, because typing two dates to see last month is friction. */
const PRESETS = [
	{ id: '7', label: 'Last 7 days', days: 7 },
	{ id: '30', label: 'Last 30 days', days: 30 },
	{ id: '90', label: 'Last 90 days', days: 90 },
];

/**
 * Returns a date that many days before today, as Y-m-d.
 */
function daysAgo( days: number ): string {
	const date = new Date();
	date.setDate( date.getDate() - days );

	return date.toISOString().slice( 0, 10 );
}

/**
 * Returns today as Y-m-d.
 */
function today(): string {
	return new Date().toISOString().slice( 0, 10 );
}

/**
 * Renders the reports screen.
 */
export function ReportsScreen(): JSX.Element {
	const config = fbmConfig();
	const { references } = useFbmReferences();

	const [ from, setFrom ] = useState( () => daysAgo( 29 ) );
	const [ to, setTo ] = useState( () => today() );
	const [ routeId, setRouteId ] = useState( 0 );
	const [ report, setReport ] = useState< Report | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const load = useCallback( () => {
		if ( ! config.proActive ) {
			return undefined;
		}

		let cancelled = false;
		setLoading( true );
		setError( '' );

		const query = new URLSearchParams( {
			from,
			to,
			route_id: String( routeId ),
		} );

		fbmRequest< Report >( 'reports', { query: Object.fromEntries( query ) } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setReport( response.data );
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
	}, [ config.proActive, from, to, routeId ] );

	useEffect( () => load(), [ load ] );

	const peak = useMemo( () => {
		if ( ! report ) {
			return 0;
		}

		return report.daily.reduce( ( highest, day ) => Math.max( highest, day.amount ), 0 );
	}, [ report ] );

	if ( ! config.proActive ) {
		return (
			<>
				<PageHeader
					title={ fbmText( 'Reports' ) }
					badge={ <span className="fbm-badge fbm-badge--pro">PRO</span> }
				/>
				<EmptyState
					icon="chart"
					title={ fbmText( 'Available in MagePeople Ferry Booking System Pro.' ) }
					description={ fbmText( 'Revenue over time, and where it came from: by route, vessel, sales channel, payment method, fare type and extra.' ) }
				/>
			</>
		);
	}

	const exportUrl = fbmRestUrl( 'reports/export', { from, to, route_id: routeId, _wpnonce: config.restNonce } );

	return (
		<>
			<PageHeader
				title={ fbmText( 'Reports' ) }
				description={ fbmText( 'What you sold, and where it came from.' ) }
				actions={
					<a className="fbm-button" href={ exportUrl }>
						{ fbmText( 'Download CSV' ) }
					</a>
				}
			/>

			<div className="fbm-panel">
				<div className="fbm-settings">
					<div className="fbm-settings__wide">
						<div className="fbm-presets">
							{ PRESETS.map( ( preset ) => {
								const active = from === daysAgo( preset.days - 1 ) && to === today();

								return (
									<button
										type="button"
										key={ preset.id }
										className={ `fbm-preset${ active ? ' is-on' : '' }` }
										onClick={ () => {
											setFrom( daysAgo( preset.days - 1 ) );
											setTo( today() );
										} }
									>
										{ fbmText( preset.label ) }
									</button>
								);
							} ) }
						</div>
					</div>

					<TextField label={ fbmText( 'From' ) } name="report_from" type="date" value={ from } onChange={ setFrom } />
					<TextField label={ fbmText( 'To' ) } name="report_to" type="date" value={ to } onChange={ setTo } />

					<SelectField
						label={ fbmText( 'Route' ) }
						name="report_route"
						value={ String( routeId ) }
						options={ [
							{ value: '0', label: fbmText( 'Every route' ) },
							...( references?.routes ?? [] ).map( ( route ) => ( {
								value: String( route.id ),
								label: route.name,
							} ) ),
						] }
						onChange={ ( value ) => setRouteId( Number( value ) ) }
					/>
				</div>
			</div>

			{ error !== '' ? <ErrorState message={ error } onRetry={ load } /> : null }
			{ loading ? <LoadingState rows={ 5 } /> : null }

			{ ! loading && report ? (
				<>
					{ report.capped ? (
						<div className="fbm-alert fbm-alert--warning" role="status">
							{ fbmFormat(
								'This range holds more bookings than one report reads. The figures below cover the first %s and are a floor, not a total — narrow the dates for an exact answer.',
								String( report.read )
							) }
						</div>
					) : null }

					<div className="fbm-stat-grid">
						<Stat label={ fbmText( 'Revenue' ) } value={ fbmFormatMoney( report.totals.revenue ) } />
						<Stat label={ fbmText( 'Collected' ) } value={ fbmFormatMoney( report.totals.collected ) } />
						<Stat
							label={ fbmText( 'Awaiting payment' ) }
							value={ fbmFormatMoney( report.totals.outstanding ) }
							tone={ report.totals.outstanding > 0 ? 'warning' : '' }
						/>
						<Stat
							label={ fbmText( 'Refunded' ) }
							value={ fbmFormatMoney( report.totals.refunded ) }
							tone={ report.totals.refunded > 0 ? 'danger' : '' }
						/>
						<Stat label={ fbmText( 'Bookings' ) } value={ String( report.totals.bookings ) } />
						<Stat label={ fbmText( 'Passengers' ) } value={ String( report.totals.passengers ) } />
						<Stat label={ fbmText( 'Vehicles' ) } value={ String( report.totals.vehicles ) } />
						<Stat label={ fbmText( 'Cancelled' ) } value={ String( report.totals.cancelled ) } />
					</div>

					<div className="fbm-panel">
						<div className="fbm-panel__header">
							<div>
								<h2 className="fbm-panel__title">{ fbmText( 'Revenue by day' ) }</h2>
								<p className="fbm-panel__description">
									{ fbmText( 'By the day the booking was taken, not the day it sails.' ) }
								</p>
							</div>
						</div>

						{ peak > 0 ? (
							<div className="fbm-chart" role="img" aria-label={ fbmText( 'Revenue by day' ) }>
								{ report.daily.map( ( day ) => (
									<span
										className="fbm-chart__bar"
										key={ day.date }
										style={ { height: `${ Math.max( 2, ( day.amount / peak ) * 100 ) }%` } }
										title={ `${ day.date }: ${ fbmFormatMoney( day.amount ) }` }
									/>
								) ) }
							</div>
						) : (
							<p className="fbm-chart__empty">{ fbmText( 'Nothing was sold in this range.' ) }</p>
						) }

						{ report.daily.length > 0 ? (
							<div className="fbm-chart__axis">
								<span>{ report.daily[ 0 ]?.date }</span>
								<span>{ fbmFormat( 'Peak %s', fbmFormatMoney( peak ) ) }</span>
								<span>{ report.daily[ report.daily.length - 1 ]?.date }</span>
							</div>
						) : null }
					</div>

					<div className="fbm-breakdowns">
						<Breakdown title={ fbmText( 'Routes' ) } rows={ report.routes } money />
						<Breakdown title={ fbmText( 'Vessels' ) } rows={ report.vessels } money />
						<Breakdown title={ fbmText( 'Sales channel' ) } rows={ report.channels } money />
						<Breakdown title={ fbmText( 'Payment method' ) } rows={ report.methods } money />
						<Breakdown title={ fbmText( 'Fare types' ) } rows={ report.passenger_types } />
						<Breakdown title={ fbmText( 'Vehicle types' ) } rows={ report.vehicle_types } />
						{ report.extras.length > 0 ? (
							<Breakdown title={ fbmText( 'Extras' ) } rows={ report.extras } money />
						) : null }
					</div>
				</>
			) : null }
		</>
	);
}

/**
 * Renders one headline figure.
 */
function Stat( { label, value, tone = '' }: { label: string; value: string; tone?: string } ): JSX.Element {
	return (
		<div className={ `fbm-stat${ tone !== '' ? ` fbm-stat--${ tone }` : '' }` }>
			<span className="fbm-stat__label">{ label }</span>
			<span className="fbm-stat__value">{ value }</span>
		</div>
	);
}

/**
 * Renders one breakdown, as a ranked list with proportion bars.
 *
 * The bar is relative to the largest row, not to the total: the question is
 * "which of these is biggest and by how much", and a share-of-total bar answers
 * a different one badly when there are twenty rows.
 */
function Breakdown( { title, rows, money = false }: { title: string; rows: Ranked[]; money?: boolean } ): JSX.Element {
	const peak = rows.reduce( ( highest, row ) => Math.max( highest, money ? row.amount : row.count ), 0 );

	return (
		<div className="fbm-panel">
			<div className="fbm-panel__header">
				<h2 className="fbm-panel__title">{ title }</h2>
			</div>

			{ rows.length === 0 ? (
				<p className="fbm-breakdown__empty">{ fbmText( 'Nothing to show for this range.' ) }</p>
			) : (
				<ul className="fbm-breakdown">
					{ rows.slice( 0, 8 ).map( ( row ) => {
						const size = money ? row.amount : row.count;

						return (
							<li className="fbm-breakdown__row" key={ row.key }>
								<span className="fbm-breakdown__label">{ row.label }</span>
								<span className="fbm-breakdown__track">
									<span
										className="fbm-breakdown__fill"
										style={ { width: `${ peak > 0 ? Math.max( 2, ( size / peak ) * 100 ) : 0 }%` } }
									/>
								</span>
								<span className="fbm-breakdown__value">
									{ money ? fbmFormatMoney( row.amount ) : String( row.count ) }
								</span>
							</li>
						);
					} ) }
				</ul>
			) }
		</div>
	);
}
