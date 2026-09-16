/**
 * Operations calendar.
 *
 * The screen the day is run from. A month view answers "how is the season
 * filling?", a week answers "where are the gaps?", and a day answers "what is
 * happening in the next four hours?" — so all three read from one dataset and
 * differ only in how much of it is on screen at once.
 *
 * Occupancy is shown as a bar rather than a number alone, because the question
 * an operator is actually asking is "which of these needs attention", and a
 * shape answers that across thirty crossings faster than thirty percentages do.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { Drawer } from '../components/Drawer';
import { SelectField, TextField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { useFbmReferences } from '../lib/references';

type CalendarView = 'day' | 'week' | 'month';

interface Measure {
	key: string;
	label: string;
	capacity: number;
	used: number;
	remaining: number;
	unlimited: boolean;
	occupancy: number;
}

interface CalendarSailing {
	id: number;
	name: string;
	date: string;
	departure: string;
	time: string;
	arrival_time: string;
	route_id: number;
	route: string;
	origin: string;
	destination: string;
	vessel_id: number;
	vessel: string;
	status: string;
	status_label: string;
	bookable: boolean;
	reason: string;
	measures: Measure[];
	passengers: number;
	vehicles: number;
	capacity: number;
	occupancy: number;
	sold_out: boolean;
}

interface CalendarDay {
	date: string;
	weekday: number;
	day: number;
	label: string;
	in_range: boolean;
	is_today: boolean;
	is_past: boolean;
	sailings: CalendarSailing[];
	totals: { sailings: number; passengers: number; vehicles: number; capacity: number; cancelled: number; sold_out: number };
}

interface Calendar {
	view: CalendarView;
	date: string;
	from: string;
	to: string;
	label: string;
	previous: string;
	next: string;
	today: string;
	weekdays: Array< { weekday: number; short: string; long: string } >;
	days: CalendarDay[];
	totals: { sailings: number; passengers: number; vehicles: number; capacity: number; cancelled: number };
	occupancy: number;
	truncated: boolean;
	statuses: Record< string, string >;
}

interface SailingDetail extends CalendarSailing {
	bookings: Array< {
		id: number;
		reference: string;
		customer: string;
		passengers: number;
		vehicles: number;
		status: string;
		payment_status: string;
		total_display: string;
		channel: string;
	} >;
	revenue_display: string;
	notes: string;
	statuses: Record< string, string >;
	vessels: Array< { id: number; name: string } >;
}

interface ChangeResult {
	kind: string;
	changes: Array< { field: string; from: string; to: string } >;
	bookings: number;
	passengers: number;
	notify: boolean;
}

/**
 * Renders the calendar screen.
 */
export function CalendarScreen(): JSX.Element {
	const config = fbmConfig();
	const toast = useFbmToast();
	const { references } = useFbmReferences();

	const [ view, setView ] = useState< CalendarView >( 'month' );
	const [ date, setDate ] = useState( '' );
	const [ routeId, setRouteId ] = useState( '0' );
	const [ vesselId, setVesselId ] = useState( '0' );
	const [ calendar, setCalendar ] = useState< Calendar | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ openId, setOpenId ] = useState( 0 );

	const load = useCallback( () => {
		if ( ! config.proActive ) {
			setLoading( false );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );
		setError( '' );

		fbmRequest< Calendar >( 'calendar', {
			query: { view, date, route_id: routeId, vessel_id: vesselId },
		} )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setCalendar( response.data );
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
	}, [ config.proActive, view, date, routeId, vesselId ] );

	useEffect( () => load(), [ load ] );

	if ( ! config.proActive ) {
		return (
			<>
				<PageHeader
					title={ fbmText( 'Calendar' ) }
					badge={ <span className="fbm-badge fbm-badge--pro">PRO</span> }
				/>
				<EmptyState
					icon="calendar"
					title={ fbmText( 'Available in MagePeople Ferry Booking System Pro.' ) }
					description={ fbmText(
						'A day, week and month view of every crossing, showing how full each one is, with delays and cancellations made from the same screen.'
					) }
				/>
			</>
		);
	}

	const routeOptions = [
		{ value: '0', label: fbmText( 'All routes' ) },
		...references.routes.map( ( row ) => ( { value: String( row.id ), label: row.name } ) ),
	];

	const vesselOptions = [
		{ value: '0', label: fbmText( 'All vessels' ) },
		...references.vessels.map( ( row ) => ( { value: String( row.id ), label: row.name } ) ),
	];

	return (
		<>
			<PageHeader
				title={ fbmText( 'Calendar' ) }
				description={ fbmText( 'Every crossing, and how full it is.' ) }
			/>

			<div className="fbm-panel fbm-calendar__bar">
				<div className="fbm-calendar__nav">
					<button
						type="button"
						className="fbm-button fbm-button--secondary"
						aria-label={ fbmText( 'Previous' ) }
						onClick={ () => setDate( calendar?.previous ?? '' ) }
					>
						←
					</button>
					<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => setDate( '' ) }>
						{ fbmText( 'Today' ) }
					</button>
					<button
						type="button"
						className="fbm-button fbm-button--secondary"
						aria-label={ fbmText( 'Next' ) }
						onClick={ () => setDate( calendar?.next ?? '' ) }
					>
						→
					</button>
					<h2 className="fbm-calendar__title">{ calendar?.label ?? '' }</h2>
				</div>

				<div className="fbm-calendar__controls">
					<div className="fbm-segmented" role="group" aria-label={ fbmText( 'Calendar view' ) }>
						{ ( [ 'day', 'week', 'month' ] as CalendarView[] ).map( ( option ) => (
							<button
								key={ option }
								type="button"
								className={ `fbm-segmented__option${ view === option ? ' is-selected' : '' }` }
								aria-pressed={ view === option }
								onClick={ () => setView( option ) }
							>
								{ fbmText( option === 'day' ? 'Day' : option === 'week' ? 'Week' : 'Month' ) }
							</button>
						) ) }
					</div>

					<SelectField
						label={ fbmText( 'Route' ) }
						name="calendar_route"
						value={ routeId }
						options={ routeOptions }
						onChange={ setRouteId }
					/>
					<SelectField
						label={ fbmText( 'Vessel' ) }
						name="calendar_vessel"
						value={ vesselId }
						options={ vesselOptions }
						onChange={ setVesselId }
					/>
				</div>
			</div>

			{ calendar ? (
				<div className="fbm-stat-grid">
					<Stat label={ fbmText( 'Crossings' ) } value={ String( calendar.totals.sailings ) } />
					<Stat label={ fbmText( 'Passengers booked' ) } value={ String( calendar.totals.passengers ) } />
					<Stat label={ fbmText( 'Vehicles booked' ) } value={ String( calendar.totals.vehicles ) } />
					<Stat
						label={ fbmText( 'Seats filled' ) }
						value={ calendar.totals.capacity > 0 ? `${ calendar.occupancy }%` : '—' }
						hint={
							calendar.totals.capacity > 0
								? fbmFormat( '%1$s of %2$s seats', String( calendar.totals.passengers ), String( calendar.totals.capacity ) )
								: fbmText( 'No capacity set on these crossings.' )
						}
					/>
					<Stat label={ fbmText( 'Cancelled' ) } value={ String( calendar.totals.cancelled ) } />
				</div>
			) : null }

			{ calendar?.truncated ? (
				<div className="fbm-alert fbm-alert--warning" role="status">
					{ fbmText( 'This period has more crossings than one view can show. Narrow it by route or vessel, or use the week view.' ) }
				</div>
			) : null }

			{ error !== '' ? <ErrorState message={ error } onRetry={ load } /> : null }
			{ loading ? <LoadingState rows={ 6 } /> : null }

			{ ! loading && calendar && view === 'month' ? (
				<MonthGrid calendar={ calendar } onOpen={ setOpenId } />
			) : null }

			{ ! loading && calendar && view !== 'month' ? (
				<AgendaList calendar={ calendar } onOpen={ setOpenId } />
			) : null }

			<SailingDrawer
				sailingId={ openId }
				onClose={ () => setOpenId( 0 ) }
				onChanged={ ( result ) => {
					toast.notify(
						result.notify && result.bookings > 0
							? fbmFormat( 'Crossing updated. %s passengers are being told.', String( result.passengers ) )
							: fbmText( 'Crossing updated.' ),
						'success'
					);
					load();
				} }
			/>
		</>
	);
}

/**
 * Renders one headline figure.
 */
function Stat( { label, value, hint }: { label: string; value: string; hint?: string } ): JSX.Element {
	return (
		<div className="fbm-stat">
			<span className="fbm-stat__label">{ label }</span>
			<span className="fbm-stat__value">{ value }</span>
			{ hint ? <span className="fbm-stat__hint">{ hint }</span> : null }
		</div>
	);
}

/**
 * Renders the month grid.
 */
function MonthGrid( { calendar, onOpen }: { calendar: Calendar; onOpen: ( id: number ) => void } ): JSX.Element {
	return (
		<div className="fbm-panel fbm-calendar">
			<div className="fbm-calendar__weekdays" aria-hidden="true">
				{ calendar.weekdays.map( ( day ) => (
					<span key={ day.weekday }>{ day.short }</span>
				) ) }
			</div>
			<div className="fbm-calendar__grid">
				{ calendar.days.map( ( day ) => (
					<div
						key={ day.date }
						className={ [
							'fbm-calendar__day',
							day.in_range ? '' : 'is-outside',
							day.is_today ? 'is-today' : '',
							day.is_past ? 'is-past' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
					>
						<div className="fbm-calendar__daynum">
							<span>{ day.day }</span>
							{ day.totals.sailings > 0 ? (
								<span className="fbm-calendar__daycount">
									{ fbmFormat( '%s booked', String( day.totals.passengers ) ) }
								</span>
							) : null }
						</div>

						{ day.sailings.map( ( sailing ) => (
							<SailingChip key={ sailing.id } sailing={ sailing } onOpen={ onOpen } />
						) ) }
					</div>
				) ) }
			</div>
		</div>
	);
}

/**
 * Renders the day and week views as a list.
 */
function AgendaList( { calendar, onOpen }: { calendar: Calendar; onOpen: ( id: number ) => void } ): JSX.Element {
	const days = calendar.days.filter( ( day ) => calendar.view === 'day' || day.sailings.length > 0 );

	if ( days.length === 0 ) {
		return (
			<div className="fbm-panel">
				<EmptyState
					icon="calendar"
					title={ fbmText( 'Nothing is scheduled in this period.' ) }
					description={ fbmText( 'Add sailings, or generate a timetable from the Sailings screen.' ) }
				/>
			</div>
		);
	}

	return (
		<>
			{ days.map( ( day ) => (
				<div className="fbm-panel" key={ day.date }>
					<div className="fbm-panel__header">
						<div>
							<h2 className="fbm-panel__title">
								{ day.label }
								{ day.is_today ? <span className="fbm-pill fbm-pill--positive">{ fbmText( 'Today' ) }</span> : null }
							</h2>
							<p className="fbm-panel__description">
								{ day.totals.sailings === 0
									? fbmText( 'No crossings.' )
									: fbmFormat(
											'%1$s crossings · %2$s passengers · %3$s vehicles',
											String( day.totals.sailings ),
											String( day.totals.passengers ),
											String( day.totals.vehicles )
									  ) }
							</p>
						</div>
					</div>

					{ day.sailings.length > 0 ? (
						<div className="fbm-calendar__agenda">
							{ day.sailings.map( ( sailing ) => (
								<button
									type="button"
									key={ sailing.id }
									className="fbm-calendar__row"
									onClick={ () => onOpen( sailing.id ) }
								>
									<span className="fbm-calendar__rowtime">{ sailing.time }</span>
									<span className="fbm-calendar__rowmain">
										<span className="fbm-calendar__rowroute">{ sailing.route }</span>
										<span className="fbm-calendar__rowmeta">
											{ [ sailing.vessel, sailing.arrival_time ? fbmFormat( 'arrives %s', sailing.arrival_time ) : '' ]
												.filter( Boolean )
												.join( ' · ' ) }
										</span>
									</span>
									<span className="fbm-calendar__rowload">
										<OccupancyBar sailing={ sailing } />
									</span>
									<StatusPill sailing={ sailing } />
								</button>
							) ) }
						</div>
					) : null }
				</div>
			) ) }
		</>
	);
}

/**
 * Renders one sailing inside a month cell.
 */
function SailingChip( { sailing, onOpen }: { sailing: CalendarSailing; onOpen: ( id: number ) => void } ): JSX.Element {
	return (
		<button
			type="button"
			className={ `fbm-calendar__chip fbm-calendar__chip--${ tone( sailing ) }` }
			onClick={ () => onOpen( sailing.id ) }
			title={ `${ sailing.time } ${ sailing.route }` }
		>
			<span className="fbm-calendar__chiptime">{ sailing.time }</span>
			<span className="fbm-calendar__chiproute">{ sailing.route }</span>
			{ sailing.capacity > 0 ? <span className="fbm-calendar__chipload">{ `${ sailing.occupancy }%` }</span> : null }
		</button>
	);
}

/**
 * Renders the seats-filled bar.
 */
function OccupancyBar( { sailing }: { sailing: CalendarSailing } ): JSX.Element {
	if ( sailing.capacity <= 0 ) {
		return <span className="fbm-calendar__nocap">{ fbmText( 'No seat limit' ) }</span>;
	}

	const level = sailing.occupancy >= 90 ? 'full' : sailing.occupancy >= 60 ? 'busy' : 'quiet';

	return (
		<span className="fbm-calendar__load">
			<span
				className={ `fbm-meter fbm-meter--${ level }` }
				role="img"
				aria-label={ fbmFormat( '%1$s of %2$s seats booked', String( sailing.passengers ), String( sailing.capacity ) ) }
			>
				<span
					className="fbm-meter__fill"
					style={ { inlineSize: `${ Math.min( 100, Math.max( 0, sailing.occupancy ) ) }%` } }
				/>
			</span>
			<span className="fbm-calendar__loadtext">
				{ fbmFormat( '%1$s / %2$s', String( sailing.passengers ), String( sailing.capacity ) ) }
			</span>
		</span>
	);
}

/**
 * Renders the status of a crossing.
 */
function StatusPill( { sailing }: { sailing: CalendarSailing } ): JSX.Element {
	const modifier =
		sailing.status === 'cancelled' ? 'danger' : sailing.status === 'delayed' ? 'warning' : sailing.sold_out ? 'positive' : 'muted';

	return (
		<span className={ `fbm-pill fbm-pill--${ modifier }` }>
			{ sailing.sold_out && sailing.status === 'scheduled' ? fbmText( 'Full' ) : sailing.status_label }
		</span>
	);
}

/**
 * Picks the colour a crossing is drawn in.
 */
function tone( sailing: CalendarSailing ): string {
	if ( sailing.status === 'cancelled' ) {
		return 'danger';
	}

	if ( sailing.status === 'delayed' ) {
		return 'warning';
	}

	if ( sailing.capacity > 0 && sailing.occupancy >= 90 ) {
		return 'full';
	}

	return 'normal';
}

/**
 * Renders the side drawer for one crossing.
 */
function SailingDrawer( {
	sailingId,
	onClose,
	onChanged,
}: {
	sailingId: number;
	onClose: () => void;
	onChanged: ( result: ChangeResult ) => void;
} ): JSX.Element | null {
	const toast = useFbmToast();
	const [ detail, setDetail ] = useState< SailingDetail | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ departure, setDeparture ] = useState( '' );
	const [ vessel, setVessel ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const [ notify, setNotify ] = useState( true );
	const [ preview, setPreview ] = useState< ChangeResult | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ problem, setProblem ] = useState( '' );

	useEffect( () => {
		if ( sailingId === 0 ) {
			setDetail( null );
			setPreview( null );
			setProblem( '' );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );

		fbmRequest< SailingDetail >( `calendar/sailings/${ sailingId }` )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setDetail( response.data );
				setStatus( response.data.status );
				setDeparture( response.data.departure.slice( 0, 16 ).replace( ' ', 'T' ) );
				setVessel( String( response.data.vessel_id ) );
				setReason( '' );
				setNotify( true );
				setPreview( null );
				setProblem( '' );
			} )
			.catch( () => undefined )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ sailingId ] );

	const payload = useMemo(
		() => ( {
			status,
			departure_datetime: departure.replace( 'T', ' ' ),
			vessel_id: Number( vessel ),
			reason,
			notify,
		} ),
		[ status, departure, vessel, reason, notify ]
	);

	const describe = useCallback( async () => {
		setProblem( '' );

		try {
			const response = await fbmRequest< ChangeResult >( `calendar/sailings/${ sailingId }/preview`, {
				method: 'POST',
				body: payload,
			} );
			setPreview( response.data );
		} catch ( caught: unknown ) {
			setPreview( null );
			setProblem( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		}
	}, [ payload, sailingId ] );

	const apply = useCallback( async () => {
		setSaving( true );
		setProblem( '' );

		try {
			const response = await fbmRequest< { change: ChangeResult; sailing: SailingDetail | null } >(
				`calendar/sailings/${ sailingId }`,
				{ method: 'POST', body: payload }
			);

			if ( response.data.sailing ) {
				setDetail( response.data.sailing );
			}

			setPreview( null );
			onChanged( response.data.change );
			onClose();
		} catch ( caught: unknown ) {
			const message = caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' );
			setProblem( message );
			toast.notify( message, 'error' );
		} finally {
			setSaving( false );
		}
	}, [ payload, sailingId, onChanged, onClose, toast ] );

	if ( sailingId === 0 ) {
		return null;
	}

	return (
		<Drawer
			open={ true }
			title={ detail ? `${ detail.time } · ${ detail.route }` : fbmText( 'Crossing' ) }
			description={ detail ? [ detail.origin, detail.destination ].filter( Boolean ).join( ' → ' ) : '' }
			onClose={ onClose }
			width="wide"
			footer={
				detail ? (
					<>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ onClose }>
							{ fbmText( 'Close' ) }
						</button>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ describe }>
							{ fbmText( 'Check what this changes' ) }
						</button>
						<button type="button" className="fbm-button fbm-button--primary" onClick={ apply } disabled={ saving }>
							{ saving ? fbmText( 'Saving…' ) : fbmText( 'Apply the change' ) }
						</button>
					</>
				) : null
			}
		>
			{ loading ? <LoadingState rows={ 4 } /> : null }

			{ detail ? (
				<>
					<div className="fbm-stat-grid">
						<Stat label={ fbmText( 'Bookings' ) } value={ String( detail.bookings.length ) } />
						<Stat label={ fbmText( 'Passengers' ) } value={ String( detail.passengers ) } />
						<Stat label={ fbmText( 'Vehicles' ) } value={ String( detail.vehicles ) } />
						<Stat label={ fbmText( 'Taken' ) } value={ detail.revenue_display } />
					</div>

					<h3 className="fbm-subheading">{ fbmText( 'Capacity' ) }</h3>
					<table className="fbm-table">
						<thead>
							<tr>
								<th scope="col">{ fbmText( 'Measure' ) }</th>
								<th scope="col">{ fbmText( 'Booked' ) }</th>
								<th scope="col">{ fbmText( 'Capacity' ) }</th>
								<th scope="col">{ fbmText( 'Left' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ detail.measures.map( ( measure ) => (
								<tr key={ measure.key }>
									<td>{ measure.label }</td>
									<td>{ round( measure.used ) }</td>
									<td>{ measure.unlimited ? fbmText( 'No limit' ) : round( measure.capacity ) }</td>
									<td>{ measure.unlimited ? '—' : round( measure.remaining ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>

					<h3 className="fbm-subheading">{ fbmText( 'Change this crossing' ) }</h3>
					<p className="fbm-panel__description">
						{ fbmText(
							'Changing a crossing that already has passengers on it updates the sailing, keeps every ticket valid, and — if you ask it to — tells the people booked.'
						) }
					</p>

					<div className="fbm-settings">
						<SelectField
							label={ fbmText( 'Status' ) }
							name="change_status"
							value={ status }
							options={ Object.entries( detail.statuses ).map( ( [ value, label ] ) => ( { value, label } ) ) }
							onChange={ setStatus }
						/>
						<TextField
							label={ fbmText( 'Departure' ) }
							name="change_departure"
							type="datetime-local"
							value={ departure }
							onChange={ setDeparture }
							hint={ fbmText( 'Moving the departure moves the arrival by the same amount.' ) }
						/>
						<SelectField
							label={ fbmText( 'Vessel' ) }
							name="change_vessel"
							value={ vessel }
							options={ detail.vessels.map( ( row ) => ( { value: String( row.id ), label: row.name } ) ) }
							onChange={ setVessel }
						/>
						<TextField
							label={ fbmText( 'Reason' ) }
							name="change_reason"
							value={ reason }
							onChange={ setReason }
							hint={ fbmText( 'Shown to passengers in the message they receive.' ) }
						/>
						<label className="fbm-checkbox">
							<input type="checkbox" checked={ notify } onChange={ ( event ) => setNotify( event.target.checked ) } />
							<span>{ fbmText( 'Tell the passengers already booked' ) }</span>
						</label>
					</div>

					{ problem !== '' ? (
						<div className="fbm-alert fbm-alert--error" role="alert">
							{ problem }
						</div>
					) : null }

					{ preview ? (
						<div className="fbm-alert fbm-alert--info" role="status">
							<strong>{ fbmText( 'This would:' ) }</strong>
							<ul className="fbm-list">
								{ preview.changes.length === 0 ? (
									<li>{ fbmText( 'Change nothing — the values are the same as they are now.' ) }</li>
								) : null }
								{ preview.changes.map( ( change ) => (
									<li key={ change.field }>
										{ fbmFormat( '%1$s: %2$s → %3$s', change.field, change.from || '—', change.to || '—' ) }
									</li>
								) ) }
								<li>
									{ preview.bookings === 0
										? fbmText( 'Nobody is booked on this crossing, so nobody is affected.' )
										: preview.notify
										? fbmFormat( 'Notify %1$s bookings covering %2$s passengers', String( preview.bookings ), String( preview.passengers ) )
										: fbmFormat( 'Affect %s bookings, without telling anyone', String( preview.bookings ) ) }
								</li>
							</ul>
						</div>
					) : null }

					<h3 className="fbm-subheading">{ fbmText( 'Who is booked' ) }</h3>
					{ detail.bookings.length === 0 ? (
						<EmptyState icon="ticket" title={ fbmText( 'Nobody is booked on this crossing yet.' ) } />
					) : (
						<div className="fbm-table__scroll">
							<table className="fbm-table">
								<thead>
									<tr>
										<th scope="col">{ fbmText( 'Reference' ) }</th>
										<th scope="col">{ fbmText( 'Customer' ) }</th>
										<th scope="col">{ fbmText( 'Passengers' ) }</th>
										<th scope="col">{ fbmText( 'Vehicles' ) }</th>
										<th scope="col">{ fbmText( 'Total' ) }</th>
										<th scope="col">{ fbmText( 'Status' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ detail.bookings.map( ( row ) => (
										<tr key={ row.id }>
											<td>{ row.reference }</td>
											<td>{ row.customer }</td>
											<td>{ row.passengers }</td>
											<td>{ row.vehicles }</td>
											<td>{ row.total_display }</td>
											<td>{ row.status }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) : null }
		</Drawer>
	);
}

/**
 * Rounds a measure for display, keeping one decimal for fractional ones.
 */
function round( value: number ): string {
	return Number.isInteger( value ) ? String( value ) : String( Math.round( value * 10 ) / 10 );
}
