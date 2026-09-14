/**
 * Sailings screen.
 *
 * The operational heart of the timetable: every dated departure, filtered by
 * route, vessel, status and date range, plus the bulk scheduler that turns a
 * repeating pattern into individual sailings.
 */

import { useCallback, useMemo, useState, type JSX } from 'react';
import { Listbox } from '../components/Listbox';

import { ConfirmDialog } from '../components/ConfirmDialog';
import { DataTable, type Column } from '../components/DataTable';
import { Drawer } from '../components/Drawer';
import {
	NumberField,
	SelectField,
	TextAreaField,
	TextField,
	TimeListField,
	WeekdayField,
} from '../components/Fields';
import { FilterBar } from '../components/FilterBar';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { Pagination } from '../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmAddDays, fbmFormatDate, fbmFormatTime, fbmFromInputValue, fbmToInputValue, fbmToday } from '../lib/datetime';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmMoneyStep, fbmToMajor, fbmToMinor } from '../lib/money';
import { useFbmReferences } from '../lib/references';
import { useFbmCollection } from '../lib/useCollection';
import { formatDuration } from '../config/resources';

interface SailingRecord {
	id: number;
	name: string;
	route_id: number;
	vessel_id: number;
	route_name: string;
	vessel_name: string;
	departure_datetime: string;
	arrival_datetime: string;
	booking_open: string;
	booking_close: string;
	passenger_capacity_override: number;
	vehicle_capacity_override: number;
	deck_capacity_override: number;
	price_adjustment_type: string;
	price_adjustment: number;
	notes: string;
	status: string;
	duration: number;
	is_bookable: boolean;
	capacity: {
		passengers: number;
		vehicles: number;
		lane_metres: number;
		passengers_override: boolean;
		vehicles_override: boolean;
		lane_metres_override: boolean;
	};
	[ key: string ]: unknown;
}

interface ScheduleCandidate {
	departure_datetime: string;
	arrival_datetime: string;
	outcome: 'new' | 'duplicate' | 'conflict' | 'failed';
	conflict_with: number;
	conflict_name: string;
	message?: string;
}

const STATUS_OPTIONS = [
	{ value: 'scheduled', label: 'Scheduled' },
	{ value: 'delayed', label: 'Delayed' },
	{ value: 'departed', label: 'Departed' },
	{ value: 'arrived', label: 'Arrived' },
	{ value: 'cancelled', label: 'Cancelled' },
];

const EMPTY_SAILING: Record< string, unknown > = {
	route_id: 0,
	vessel_id: 0,
	departure_datetime: '',
	arrival_datetime: '',
	booking_open: '',
	booking_close: '',
	passenger_capacity_override: 0,
	vehicle_capacity_override: 0,
	deck_capacity_override: 0,
	price_adjustment_type: 'none',
	price_adjustment: 0,
	notes: '',
	status: 'scheduled',
};

/**
 * Renders a status pill for a sailing.
 */
function statusPill( status: string ): JSX.Element {
	const tone =
		status === 'scheduled'
			? 'positive'
			: status === 'delayed'
				? 'warning'
				: status === 'cancelled'
					? 'danger'
					: 'muted';

	const label = STATUS_OPTIONS.find( ( option ) => option.value === status )?.label ?? status;

	return <span className={ `fbm-pill fbm-pill--${ tone }` }>{ fbmText( label ) }</span>;
}

/**
 * Renders the sailings screen.
 */
export function SailingsScreen(): JSX.Element {
	const { references } = useFbmReferences();
	const toast = useFbmToast();
	const collection = useFbmCollection< SailingRecord >( 'sailings', {
		orderby: 'departure_ts',
		order: 'asc',
		from: fbmToday(),
		to: '',
		route_id: 0,
		vessel_id: 0,
	} );

	const [ drawerOpen, setDrawerOpen ] = useState( false );
	const [ editing, setEditing ] = useState< SailingRecord | null >( null );
	const [ values, setValues ] = useState< Record< string, unknown > >( EMPTY_SAILING );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ formError, setFormError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ deleting, setDeleting ] = useState< SailingRecord | null >( null );
	const [ deleteBusy, setDeleteBusy ] = useState( false );

	const [ scheduleOpen, setScheduleOpen ] = useState( false );
	const [ pattern, setPattern ] = useState< Record< string, unknown > >( {
		route_id: 0,
		vessel_id: 0,
		date_from: fbmToday(),
		date_to: fbmAddDays( fbmToday(), 30 ),
		weekdays: [ 1, 2, 3, 4, 5 ],
		times: [] as string[],
		booking_close_minutes: 30,
	} );
	const [ patternErrors, setPatternErrors ] = useState< Record< string, string > >( {} );
	const [ patternError, setPatternError ] = useState( '' );
	const [ preview, setPreview ] = useState< { candidates: ScheduleCandidate[]; summary: Record< string, number > } | null >( null );
	const [ scheduleBusy, setScheduleBusy ] = useState( false );

	const routeOptions = useMemo(
		() => references.routes.map( ( route ) => ( { value: route.id, label: route.name } ) ),
		[ references.routes ]
	);
	const vesselOptions = useMemo(
		() => references.vessels.map( ( vessel ) => ( { value: vessel.id, label: vessel.name } ) ),
		[ references.vessels ]
	);

	const setValue = useCallback( ( name: string, value: unknown ) => {
		setValues( ( current ) => ( { ...current, [ name ]: value } ) );
		setErrors( ( current ) => {
			const next = { ...current };
			delete next[ name ];

			return next;
		} );
	}, [] );

	const openCreate = useCallback( () => {
		setEditing( null );
		setValues( { ...EMPTY_SAILING } );
		setErrors( {} );
		setFormError( '' );
		setDrawerOpen( true );
	}, [] );

	const openEdit = useCallback( ( sailing: SailingRecord ) => {
		setEditing( sailing );
		setValues( {
			route_id: sailing.route_id,
			vessel_id: sailing.vessel_id,
			departure_datetime: sailing.departure_datetime,
			arrival_datetime: sailing.arrival_datetime,
			booking_open: sailing.booking_open,
			booking_close: sailing.booking_close,
			passenger_capacity_override: sailing.passenger_capacity_override,
			vehicle_capacity_override: sailing.vehicle_capacity_override,
			deck_capacity_override: sailing.deck_capacity_override,
			price_adjustment_type: sailing.price_adjustment_type,
			price_adjustment: sailing.price_adjustment,
			notes: sailing.notes,
			status: sailing.status,
		} );
		setErrors( {} );
		setFormError( '' );
		setDrawerOpen( true );
	}, [] );

	const save = useCallback( async () => {
		setSaving( true );
		setErrors( {} );
		setFormError( '' );

		try {
			await fbmRequest( editing ? `sailings/${ editing.id }` : 'sailings', {
				method: editing ? 'PUT' : 'POST',
				body: values,
			} );

			toast.notify( editing ? fbmText( 'Sailing updated.' ) : fbmText( 'Sailing created.' ), 'success' );
			setDrawerOpen( false );
			setEditing( null );
			collection.reload();
		} catch ( caught: unknown ) {
			if ( caught instanceof FbmApiError ) {
				const fields = caught.details.fields;
				setErrors( fields && typeof fields === 'object' ? ( fields as Record< string, string > ) : {} );
				setFormError( caught.message );
			} else {
				setFormError( fbmText( 'Something went wrong.' ) );
			}
		} finally {
			setSaving( false );
		}
	}, [ editing, values, toast, collection ] );

	const confirmDelete = useCallback( async () => {
		if ( ! deleting ) {
			return;
		}

		setDeleteBusy( true );

		try {
			await fbmRequest( `sailings/${ deleting.id }`, { method: 'DELETE' } );
			toast.notify( fbmText( 'Sailing deleted.' ), 'success' );
			setDeleting( null );
			collection.reload();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
			setDeleting( null );
		} finally {
			setDeleteBusy( false );
		}
	}, [ deleting, toast, collection ] );

	const runSchedule = useCallback(
		async ( commit: boolean ) => {
			setScheduleBusy( true );
			setPatternErrors( {} );
			setPatternError( '' );

			try {
				const result = await fbmRequest< {
					committed: boolean;
					candidates?: ScheduleCandidate[];
					summary?: Record< string, number >;
					created?: number;
					skipped?: ScheduleCandidate[];
				} >( 'sailings/schedule', { method: 'POST', body: { ...pattern, commit } } );

				if ( commit ) {
					toast.notify(
						fbmFormat( '%s sailings created.', String( result.data.created ?? 0 ) ),
						'success'
					);
					setScheduleOpen( false );
					setPreview( null );
					collection.reload();
				} else {
					setPreview( {
						candidates: result.data.candidates ?? [],
						summary: result.data.summary ?? {},
					} );
				}
			} catch ( caught: unknown ) {
				if ( caught instanceof FbmApiError ) {
					const fields = caught.details.fields;
					setPatternErrors( fields && typeof fields === 'object' ? ( fields as Record< string, string > ) : {} );
					setPatternError( caught.message );
				} else {
					setPatternError( fbmText( 'Something went wrong.' ) );
				}
			} finally {
				setScheduleBusy( false );
			}
		},
		[ pattern, toast, collection ]
	);

	const setPatternValue = useCallback( ( name: string, value: unknown ) => {
		setPattern( ( current ) => ( { ...current, [ name ]: value } ) );
		setPreview( null );
		setPatternErrors( ( current ) => {
			const next = { ...current };
			delete next[ name ];

			return next;
		} );
	}, [] );

	const columns: Array< Column< SailingRecord > > = useMemo(
		() => [
			{
				key: 'departure',
				label: fbmText( 'Departure' ),
				sortBy: 'departure_ts',
				render: ( item ) => (
					<div className="fbm-cell-primary">
						<span className="fbm-cell-primary__title">{ fbmFormatDate( item.departure_datetime ) }</span>
						<span className="fbm-cell-primary__meta">
							{ fbmFormatTime( item.departure_datetime ) }
							{ item.arrival_datetime ? ` → ${ fbmFormatTime( item.arrival_datetime ) }` : '' }
							{ item.duration ? ` · ${ formatDuration( item.duration ) }` : '' }
						</span>
					</div>
				),
			},
			{
				key: 'route',
				label: fbmText( 'Route' ),
				render: ( item ) => <span>{ item.route_name || '—' }</span>,
			},
			{
				key: 'vessel',
				label: fbmText( 'Vessel' ),
				width: '160px',
				render: ( item ) => <span>{ item.vessel_name || '—' }</span>,
			},
			{
				key: 'capacity',
				label: fbmText( 'Capacity' ),
				width: '170px',
				align: 'end',
				render: ( item ) => (
					<span className="fbm-capacity">
						<span title={ fbmText( 'Passengers' ) }>
							<Icon name="users" size={ 13 } /> { item.capacity.passengers }
							{ item.capacity.passengers_override ? '*' : '' }
						</span>
						<span title={ fbmText( 'Vehicles' ) }>
							<Icon name="car" size={ 13 } /> { item.capacity.vehicles }
							{ item.capacity.vehicles_override ? '*' : '' }
						</span>
					</span>
				),
			},
			{
				key: 'status',
				label: fbmText( 'Status' ),
				width: '130px',
				render: ( item ) => statusPill( item.status ),
			},
		],
		[]
	);

	if ( collection.error ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Sailings' ) } />
				<ErrorState message={ collection.error.message } code={ collection.error.code } onRetry={ collection.reload } />
			</>
		);
	}

	const filtered =
		collection.query.search !== '' ||
		collection.query.status !== '' ||
		Number( collection.query.route_id ) > 0 ||
		Number( collection.query.vessel_id ) > 0;

	return (
		<>
			<PageHeader
				title={ fbmText( 'Sailings' ) }
				description={ fbmText( 'Every dated departure you operate.' ) }
				actions={
					<>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => setScheduleOpen( true ) }>
							<Icon name="calendarPlus" size={ 16 } />
							{ fbmText( 'Bulk schedule' ) }
						</button>
						<button type="button" className="fbm-button fbm-button--primary" onClick={ openCreate }>
							{ fbmText( 'Add sailing' ) }
						</button>
					</>
				}
			/>

			<div className="fbm-panel">
				<FilterBar
					search={ collection.query.search }
					onSearch={ collection.setSearch }
					searchPlaceholder={ fbmText( 'Search sailings' ) }
					statusOptions={ STATUS_OPTIONS.map( ( option ) => ( { ...option, label: fbmText( option.label ) } ) ) }
					status={ collection.query.status }
					onStatus={ ( value ) => collection.setQuery( { status: value } ) }
					filters={
						<>
							<div className="fbm-filter-bar__filter">
								<Listbox
									value={ String( collection.query.route_id ?? 0 ) }
									options={ routeOptions }
									placeholder={ fbmText( 'All routes' ) }
									ariaLabel={ fbmText( 'Route' ) }
									onChange={ ( value ) => collection.setQuery( { route_id: Number( value ) } ) }
								/>
							</div>

							<label className="fbm-filter-bar__filter">
								<span className="fbm-screen-reader-text">{ fbmText( 'From' ) }</span>
								<input
									type="date"
									className="fbm-input"
									value={ String( collection.query.from ?? '' ) }
									onChange={ ( event ) => collection.setQuery( { from: event.target.value } ) }
								/>
							</label>

							<label className="fbm-filter-bar__filter">
								<span className="fbm-screen-reader-text">{ fbmText( 'To' ) }</span>
								<input
									type="date"
									className="fbm-input"
									value={ String( collection.query.to ?? '' ) }
									onChange={ ( event ) => collection.setQuery( { to: event.target.value } ) }
								/>
							</label>
						</>
					}
				/>

				<DataTable< SailingRecord >
					columns={ columns }
					items={ collection.items }
					rowKey={ ( item ) => item.id }
					loading={ collection.loading }
					refreshing={ collection.refreshing }
					orderby={ collection.query.orderby }
					order={ collection.query.order }
					onSort={ collection.toggleSort }
					onRowClick={ openEdit }
					actions={ [
						{ key: 'edit', label: fbmText( 'Edit' ), onSelect: openEdit },
						{ key: 'delete', label: fbmText( 'Delete' ), tone: 'danger', onSelect: ( item ) => setDeleting( item ) },
					] }
					emptyState={
						<EmptyState
							icon="route"
							title={ filtered ? fbmText( 'No matching records.' ) : fbmText( 'No sailings scheduled.' ) }
							description={
								filtered
									? fbmText( 'Try a different search or filter.' )
									: fbmText( 'Use bulk scheduling to lay out a season in one go, or add a single departure.' )
							}
							action={
								filtered ? null : (
									<button type="button" className="fbm-button fbm-button--primary" onClick={ () => setScheduleOpen( true ) }>
										{ fbmText( 'Bulk schedule' ) }
									</button>
								)
							}
						/>
					}
				/>

				<Pagination
					page={ Number( collection.meta.page ?? 1 ) }
					perPage={ Number( collection.meta.per_page ?? 20 ) }
					total={ Number( collection.meta.total ?? 0 ) }
					totalPages={ Number( collection.meta.total_pages ?? 1 ) }
					onPage={ collection.setPage }
					onPerPage={ ( perPage ) => collection.setQuery( { per_page: perPage } ) }
				/>
			</div>

			<Drawer
				open={ drawerOpen }
				title={ editing ? fbmText( 'Edit sailing' ) : fbmText( 'Add sailing' ) }
				description={ editing ? editing.name : fbmText( 'Schedule one departure.' ) }
				onClose={ () => setDrawerOpen( false ) }
				footer={
					<>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => setDrawerOpen( false ) } disabled={ saving }>
							{ fbmText( 'Cancel' ) }
						</button>
						<button type="button" className="fbm-button fbm-button--primary" onClick={ save } disabled={ saving }>
							{ saving ? fbmText( 'Saving…' ) : fbmText( 'Save' ) }
						</button>
					</>
				}
			>
				<form
					className="fbm-form"
					onSubmit={ ( event ) => {
						event.preventDefault();
						void save();
					} }
				>
					{ formError ? (
						<div className="fbm-alert fbm-alert--error" role="alert">
							{ formError }
						</div>
					) : null }

					<SelectField
						label={ fbmText( 'Route' ) }
						name="route_id"
						value={ String( values.route_id ?? 0 ) }
						options={ routeOptions }
						placeholder={ fbmText( 'Select a route' ) }
						onChange={ ( value ) => setValue( 'route_id', Number( value ) ) }
						error={ errors.route_id }
						required
					/>

					<SelectField
						label={ fbmText( 'Vessel' ) }
						name="vessel_id"
						value={ String( values.vessel_id ?? 0 ) }
						options={ vesselOptions }
						placeholder={ fbmText( 'Select a vessel' ) }
						onChange={ ( value ) => setValue( 'vessel_id', Number( value ) ) }
						error={ errors.vessel_id }
						required
					/>

					<TextField
						label={ fbmText( 'Departure' ) }
						name="departure_datetime"
						type="datetime-local"
						value={ fbmToInputValue( String( values.departure_datetime ?? '' ) ) }
						onChange={ ( value ) => setValue( 'departure_datetime', fbmFromInputValue( value ) ) }
						error={ errors.departure_datetime }
						required
					/>

					<TextField
						label={ fbmText( 'Arrival' ) }
						name="arrival_datetime"
						type="datetime-local"
						value={ fbmToInputValue( String( values.arrival_datetime ?? '' ) ) }
						onChange={ ( value ) => setValue( 'arrival_datetime', fbmFromInputValue( value ) ) }
						error={ errors.arrival_datetime }
						hint={ fbmText( 'Leave blank to derive it from the route duration.' ) }
					/>

					<TextField
						label={ fbmText( 'Bookings open' ) }
						name="booking_open"
						type="datetime-local"
						value={ fbmToInputValue( String( values.booking_open ?? '' ) ) }
						onChange={ ( value ) => setValue( 'booking_open', fbmFromInputValue( value ) ) }
						error={ errors.booking_open }
					/>

					<TextField
						label={ fbmText( 'Bookings close' ) }
						name="booking_close"
						type="datetime-local"
						value={ fbmToInputValue( String( values.booking_close ?? '' ) ) }
						onChange={ ( value ) => setValue( 'booking_close', fbmFromInputValue( value ) ) }
						error={ errors.booking_close }
						hint={ fbmText( 'Leave blank to accept bookings until departure.' ) }
					/>

					<NumberField
						label={ fbmText( 'Passenger capacity override' ) }
						name="passenger_capacity_override"
						value={ Number( values.passenger_capacity_override ?? 0 ) }
						onChange={ ( value ) => setValue( 'passenger_capacity_override', value ) }
						min={ 0 }
						suffix={ fbmText( 'seats' ) }
						error={ errors.passenger_capacity_override }
						hint={ fbmText( 'Leave at 0 to use the vessel’s own capacity.' ) }
					/>

					<NumberField
						label={ fbmText( 'Vehicle capacity override' ) }
						name="vehicle_capacity_override"
						value={ Number( values.vehicle_capacity_override ?? 0 ) }
						onChange={ ( value ) => setValue( 'vehicle_capacity_override', value ) }
						min={ 0 }
						suffix={ fbmText( 'vehicles' ) }
						error={ errors.vehicle_capacity_override }
					/>

					{ /*
					 * The third of the three overrides. It was loaded, kept and
					 * saved by this screen and read by the availability engine,
					 * but there was no input for it — so a sailing whose deck is
					 * shorter than its vessel's could not be told so, and it
					 * went on selling lane metres it did not have.
					 */ }
					<NumberField
						label={ fbmText( 'Lane metre override' ) }
						name="deck_capacity_override"
						value={ Number( values.deck_capacity_override ?? 0 ) }
						onChange={ ( value ) => setValue( 'deck_capacity_override', value ) }
						min={ 0 }
						step={ 0.5 }
						suffix="m"
						error={ errors.deck_capacity_override }
						hint={ fbmText( 'Leave at 0 to use the vessel’s own lane metres.' ) }
					/>

					<SelectField
						label={ fbmText( 'Status' ) }
						name="status"
						value={ String( values.status ?? 'scheduled' ) }
						options={ STATUS_OPTIONS.map( ( option ) => ( { value: option.value, label: fbmText( option.label ) } ) ) }
						onChange={ ( value ) => setValue( 'status', value ) }
						error={ errors.status }
					/>

					<SelectField
						label={ fbmText( 'Fare adjustment' ) }
						name="price_adjustment_type"
						value={ String( values.price_adjustment_type ?? 'none' ) }
						options={ [
							{ value: 'none', label: fbmText( 'Standard route fares' ) },
							{ value: 'percent', label: fbmText( 'Adjust by a percentage' ) },
							{ value: 'fixed', label: fbmText( 'Adjust by a fixed amount' ) },
						] }
						onChange={ ( value ) => setValue( 'price_adjustment_type', value ) }
						error={ errors.price_adjustment_type }
						hint={ fbmText( 'Applies to fares on this departure only. A negative value reduces them.' ) }
					/>

					{ values.price_adjustment_type === 'percent' ? (
						<NumberField
							label={ fbmText( 'Percentage adjustment' ) }
							name="price_adjustment"
							value={ Number( values.price_adjustment ?? 0 ) }
							min={ -100 }
							max={ 500 }
							step={ 0.5 }
							suffix="%"
							onChange={ ( value ) => setValue( 'price_adjustment', value ) }
							error={ errors.price_adjustment }
						/>
					) : null }

					{ values.price_adjustment_type === 'fixed' ? (
						<NumberField
							label={ fbmText( 'Fixed adjustment per fare' ) }
							name="price_adjustment"
							value={ fbmToMajor( Number( values.price_adjustment ?? 0 ) ) }
							step={ fbmMoneyStep() }
							suffix={ fbmConfig().currency.code }
							onChange={ ( value ) => setValue( 'price_adjustment', fbmToMinor( value ) ) }
							error={ errors.price_adjustment }
						/>
					) : null }

					<TextAreaField
						label={ fbmText( 'Operational notes' ) }
						name="notes"
						value={ String( values.notes ?? '' ) }
						onChange={ ( value ) => setValue( 'notes', value ) }
						rows={ 3 }
					/>
				</form>
			</Drawer>

			<Drawer
				open={ scheduleOpen }
				width="wide"
				title={ fbmText( 'Bulk schedule' ) }
				description={ fbmText( 'Repeat a departure pattern across a date range.' ) }
				onClose={ () => setScheduleOpen( false ) }
				footer={
					<>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => setScheduleOpen( false ) } disabled={ scheduleBusy }>
							{ fbmText( 'Cancel' ) }
						</button>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => void runSchedule( false ) } disabled={ scheduleBusy }>
							{ fbmText( 'Preview' ) }
						</button>
						<button
							type="button"
							className="fbm-button fbm-button--primary"
							onClick={ () => void runSchedule( true ) }
							disabled={ scheduleBusy || preview === null || ( preview.summary.new ?? 0 ) === 0 }
						>
							{ preview
								? fbmFormat( 'Create %s sailings', String( preview.summary.new ?? 0 ) )
								: fbmText( 'Create sailings' ) }
						</button>
					</>
				}
			>
				<form className="fbm-form" onSubmit={ ( event ) => event.preventDefault() }>
					{ patternError ? (
						<div className="fbm-alert fbm-alert--error" role="alert">
							{ patternError }
						</div>
					) : null }

					<SelectField
						label={ fbmText( 'Route' ) }
						name="route_id"
						value={ String( pattern.route_id ?? 0 ) }
						options={ routeOptions }
						placeholder={ fbmText( 'Select a route' ) }
						onChange={ ( value ) => setPatternValue( 'route_id', Number( value ) ) }
						error={ patternErrors.route_id }
						required
					/>

					<SelectField
						label={ fbmText( 'Vessel' ) }
						name="vessel_id"
						value={ String( pattern.vessel_id ?? 0 ) }
						options={ vesselOptions }
						placeholder={ fbmText( 'Select a vessel' ) }
						onChange={ ( value ) => setPatternValue( 'vessel_id', Number( value ) ) }
						error={ patternErrors.vessel_id }
						required
					/>

					<div className="fbm-form__row">
						<TextField
							label={ fbmText( 'From' ) }
							name="date_from"
							type="date"
							value={ String( pattern.date_from ?? '' ) }
							onChange={ ( value ) => setPatternValue( 'date_from', value ) }
							error={ patternErrors.date_from }
							required
						/>
						<TextField
							label={ fbmText( 'To' ) }
							name="date_to"
							type="date"
							value={ String( pattern.date_to ?? '' ) }
							onChange={ ( value ) => setPatternValue( 'date_to', value ) }
							error={ patternErrors.date_to }
							required
						/>
					</div>

					<WeekdayField
						label={ fbmText( 'Days of the week' ) }
						values={ ( pattern.weekdays as number[] ) ?? [] }
						onChange={ ( value ) => setPatternValue( 'weekdays', value ) }
						startOfWeek={ fbmConfig().startOfWeek }
						error={ patternErrors.weekdays }
					/>

					<TimeListField
						label={ fbmText( 'Departure times' ) }
						values={ ( pattern.times as string[] ) ?? [] }
						onChange={ ( value ) => setPatternValue( 'times', value ) }
						error={ patternErrors.times }
						hint={ fbmText( 'Each time runs on every selected day.' ) }
					/>

					<NumberField
						label={ fbmText( 'Bookings close' ) }
						name="booking_close_minutes"
						value={ Number( pattern.booking_close_minutes ?? 0 ) }
						onChange={ ( value ) => setPatternValue( 'booking_close_minutes', value ) }
						min={ 0 }
						suffix={ fbmText( 'min before departure' ) }
					/>

					{ scheduleBusy && preview === null ? <LoadingState rows={ 2 } /> : null }

					{ preview ? (
						<div className="fbm-preview">
							<div className="fbm-preview__summary">
								<span className="fbm-pill fbm-pill--positive">
									{ fbmFormat( '%s new', String( preview.summary.new ?? 0 ) ) }
								</span>
								<span className="fbm-pill fbm-pill--muted">
									{ fbmFormat( '%s already scheduled', String( preview.summary.duplicate ?? 0 ) ) }
								</span>
								<span className="fbm-pill fbm-pill--danger">
									{ fbmFormat( '%s in conflict', String( preview.summary.conflict ?? 0 ) ) }
								</span>
							</div>

							<ul className="fbm-preview__list">
								{ preview.candidates.slice( 0, 60 ).map( ( candidate ) => (
									<li className={ `fbm-preview__item is-${ candidate.outcome }` } key={ candidate.departure_datetime }>
										<span className="fbm-preview__when">
											{ fbmFormatDate( candidate.departure_datetime ) } · { fbmFormatTime( candidate.departure_datetime ) }
										</span>
										<span className="fbm-preview__outcome">
											{ candidate.outcome === 'new'
												? fbmText( 'Will be created' )
												: candidate.outcome === 'duplicate'
													? fbmText( 'Already scheduled' )
													: candidate.conflict_name
														? fbmFormat( 'Vessel busy: %s', candidate.conflict_name )
														: fbmText( 'Vessel busy' ) }
										</span>
									</li>
								) ) }
							</ul>

							{ preview.candidates.length > 60 ? (
								<p className="fbm-field__hint">
									{ fbmFormat( 'Showing the first 60 of %s.', String( preview.candidates.length ) ) }
								</p>
							) : null }
						</div>
					) : null }
				</form>
			</Drawer>

			<ConfirmDialog
				open={ deleting !== null }
				title={ fbmText( 'Delete sailing?' ) }
				message={ fbmText(
					'The sailing will be moved to the trash. Sailings that already carry bookings cannot be deleted — cancel them instead so passengers are notified.'
				) }
				confirmLabel={ fbmText( 'Delete' ) }
				busy={ deleteBusy }
				onConfirm={ confirmDelete }
				onCancel={ () => setDeleting( null ) }
			/>
		</>
	);
}
