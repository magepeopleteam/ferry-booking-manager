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
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsConfig } from '../lib/config';
import { mpfbsAddDays, mpfbsFormatDate, mpfbsFormatTime, mpfbsFromInputValue, mpfbsToInputValue, mpfbsToday } from '../lib/datetime';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsMoneyStep, mpfbsToMajor, mpfbsToMinor } from '../lib/money';
import { useMpfbsReferences } from '../lib/references';
import { useMpfbsCollection } from '../lib/useCollection';
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

	return <span className={ `mpfbs-pill mpfbs-pill--${ tone }` }>{ mpfbsText( label ) }</span>;
}

/**
 * Renders the sailings screen.
 */
export function SailingsScreen(): JSX.Element {
	const { references } = useMpfbsReferences();
	const toast = useMpfbsToast();
	const collection = useMpfbsCollection< SailingRecord >( 'sailings', {
		orderby: 'departure_ts',
		order: 'asc',
		from: mpfbsToday(),
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
		date_from: mpfbsToday(),
		date_to: mpfbsAddDays( mpfbsToday(), 30 ),
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
			await mpfbsRequest( editing ? `sailings/${ editing.id }` : 'sailings', {
				method: editing ? 'PUT' : 'POST',
				body: values,
			} );

			toast.notify( editing ? mpfbsText( 'Sailing updated.' ) : mpfbsText( 'Sailing created.' ), 'success' );
			setDrawerOpen( false );
			setEditing( null );
			collection.reload();
		} catch ( caught: unknown ) {
			if ( caught instanceof MpfbsApiError ) {
				const fields = caught.details.fields;
				setErrors( fields && typeof fields === 'object' ? ( fields as Record< string, string > ) : {} );
				setFormError( caught.message );
			} else {
				setFormError( mpfbsText( 'Something went wrong.' ) );
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
			await mpfbsRequest( `sailings/${ deleting.id }`, { method: 'DELETE' } );
			toast.notify( mpfbsText( 'Sailing deleted.' ), 'success' );
			setDeleting( null );
			collection.reload();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
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
				const result = await mpfbsRequest< {
					committed: boolean;
					candidates?: ScheduleCandidate[];
					summary?: Record< string, number >;
					created?: number;
					skipped?: ScheduleCandidate[];
				} >( 'sailings/schedule', { method: 'POST', body: { ...pattern, commit } } );

				if ( commit ) {
					toast.notify(
						mpfbsFormat( '%s sailings created.', String( result.data.created ?? 0 ) ),
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
				if ( caught instanceof MpfbsApiError ) {
					const fields = caught.details.fields;
					setPatternErrors( fields && typeof fields === 'object' ? ( fields as Record< string, string > ) : {} );
					setPatternError( caught.message );
				} else {
					setPatternError( mpfbsText( 'Something went wrong.' ) );
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
				label: mpfbsText( 'Departure' ),
				sortBy: 'departure_ts',
				render: ( item ) => (
					<div className="mpfbs-cell-primary">
						<span className="mpfbs-cell-primary__title">{ mpfbsFormatDate( item.departure_datetime ) }</span>
						<span className="mpfbs-cell-primary__meta">
							{ mpfbsFormatTime( item.departure_datetime ) }
							{ item.arrival_datetime ? ` → ${ mpfbsFormatTime( item.arrival_datetime ) }` : '' }
							{ item.duration ? ` · ${ formatDuration( item.duration ) }` : '' }
						</span>
					</div>
				),
			},
			{
				key: 'route',
				label: mpfbsText( 'Route' ),
				render: ( item ) => <span>{ item.route_name || '—' }</span>,
			},
			{
				key: 'vessel',
				label: mpfbsText( 'Vessel' ),
				width: '160px',
				render: ( item ) => <span>{ item.vessel_name || '—' }</span>,
			},
			{
				key: 'capacity',
				label: mpfbsText( 'Capacity' ),
				width: '170px',
				align: 'end',
				render: ( item ) => (
					<span className="mpfbs-capacity">
						<span title={ mpfbsText( 'Passengers' ) }>
							<Icon name="users" size={ 13 } /> { item.capacity.passengers }
							{ item.capacity.passengers_override ? '*' : '' }
						</span>
						<span title={ mpfbsText( 'Vehicles' ) }>
							<Icon name="car" size={ 13 } /> { item.capacity.vehicles }
							{ item.capacity.vehicles_override ? '*' : '' }
						</span>
					</span>
				),
			},
			{
				key: 'status',
				label: mpfbsText( 'Status' ),
				width: '130px',
				render: ( item ) => statusPill( item.status ),
			},
		],
		[]
	);

	if ( collection.error ) {
		return (
			<>
				<PageHeader title={ mpfbsText( 'Sailings' ) } />
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
				title={ mpfbsText( 'Sailings' ) }
				description={ mpfbsText( 'Every dated departure you operate.' ) }
				actions={
					<>
						<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => setScheduleOpen( true ) }>
							<Icon name="calendarPlus" size={ 16 } />
							{ mpfbsText( 'Bulk schedule' ) }
						</button>
						<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ openCreate }>
							{ mpfbsText( 'Add sailing' ) }
						</button>
					</>
				}
			/>

			<div className="mpfbs-panel">
				<FilterBar
					search={ collection.query.search }
					onSearch={ collection.setSearch }
					searchPlaceholder={ mpfbsText( 'Search sailings' ) }
					statusOptions={ STATUS_OPTIONS.map( ( option ) => ( { ...option, label: mpfbsText( option.label ) } ) ) }
					status={ collection.query.status }
					onStatus={ ( value ) => collection.setQuery( { status: value } ) }
					filters={
						<>
							<div className="mpfbs-filter-bar__filter">
								<Listbox
									value={ String( collection.query.route_id ?? 0 ) }
									options={ routeOptions }
									placeholder={ mpfbsText( 'All routes' ) }
									ariaLabel={ mpfbsText( 'Route' ) }
									onChange={ ( value ) => collection.setQuery( { route_id: Number( value ) } ) }
								/>
							</div>

							<label className="mpfbs-filter-bar__filter">
								<span className="mpfbs-screen-reader-text">{ mpfbsText( 'From' ) }</span>
								<input
									type="date"
									className="mpfbs-input"
									value={ String( collection.query.from ?? '' ) }
									onChange={ ( event ) => collection.setQuery( { from: event.target.value } ) }
								/>
							</label>

							<label className="mpfbs-filter-bar__filter">
								<span className="mpfbs-screen-reader-text">{ mpfbsText( 'To' ) }</span>
								<input
									type="date"
									className="mpfbs-input"
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
						{ key: 'edit', label: mpfbsText( 'Edit' ), onSelect: openEdit },
						{ key: 'delete', label: mpfbsText( 'Delete' ), tone: 'danger', onSelect: ( item ) => setDeleting( item ) },
					] }
					emptyState={
						<EmptyState
							icon="route"
							title={ filtered ? mpfbsText( 'No matching records.' ) : mpfbsText( 'No sailings scheduled.' ) }
							description={
								filtered
									? mpfbsText( 'Try a different search or filter.' )
									: mpfbsText( 'Use bulk scheduling to lay out a season in one go, or add a single departure.' )
							}
							action={
								filtered ? null : (
									<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => setScheduleOpen( true ) }>
										{ mpfbsText( 'Bulk schedule' ) }
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
				title={ editing ? mpfbsText( 'Edit sailing' ) : mpfbsText( 'Add sailing' ) }
				description={ editing ? editing.name : mpfbsText( 'Schedule one departure.' ) }
				onClose={ () => setDrawerOpen( false ) }
				footer={
					<>
						<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => setDrawerOpen( false ) } disabled={ saving }>
							{ mpfbsText( 'Cancel' ) }
						</button>
						<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ save } disabled={ saving }>
							{ saving ? mpfbsText( 'Saving…' ) : mpfbsText( 'Save' ) }
						</button>
					</>
				}
			>
				<form
					className="mpfbs-form"
					onSubmit={ ( event ) => {
						event.preventDefault();
						void save();
					} }
				>
					{ formError ? (
						<div className="mpfbs-alert mpfbs-alert--error" role="alert">
							{ formError }
						</div>
					) : null }

					<SelectField
						label={ mpfbsText( 'Route' ) }
						name="route_id"
						value={ String( values.route_id ?? 0 ) }
						options={ routeOptions }
						placeholder={ mpfbsText( 'Select a route' ) }
						onChange={ ( value ) => setValue( 'route_id', Number( value ) ) }
						error={ errors.route_id }
						required
					/>

					<SelectField
						label={ mpfbsText( 'Vessel' ) }
						name="vessel_id"
						value={ String( values.vessel_id ?? 0 ) }
						options={ vesselOptions }
						placeholder={ mpfbsText( 'Select a vessel' ) }
						onChange={ ( value ) => setValue( 'vessel_id', Number( value ) ) }
						error={ errors.vessel_id }
						required
					/>

					<TextField
						label={ mpfbsText( 'Departure' ) }
						name="departure_datetime"
						type="datetime-local"
						value={ mpfbsToInputValue( String( values.departure_datetime ?? '' ) ) }
						onChange={ ( value ) => setValue( 'departure_datetime', mpfbsFromInputValue( value ) ) }
						error={ errors.departure_datetime }
						required
					/>

					<TextField
						label={ mpfbsText( 'Arrival' ) }
						name="arrival_datetime"
						type="datetime-local"
						value={ mpfbsToInputValue( String( values.arrival_datetime ?? '' ) ) }
						onChange={ ( value ) => setValue( 'arrival_datetime', mpfbsFromInputValue( value ) ) }
						error={ errors.arrival_datetime }
						hint={ mpfbsText( 'Leave blank to derive it from the route duration.' ) }
					/>

					<TextField
						label={ mpfbsText( 'Bookings open' ) }
						name="booking_open"
						type="datetime-local"
						value={ mpfbsToInputValue( String( values.booking_open ?? '' ) ) }
						onChange={ ( value ) => setValue( 'booking_open', mpfbsFromInputValue( value ) ) }
						error={ errors.booking_open }
					/>

					<TextField
						label={ mpfbsText( 'Bookings close' ) }
						name="booking_close"
						type="datetime-local"
						value={ mpfbsToInputValue( String( values.booking_close ?? '' ) ) }
						onChange={ ( value ) => setValue( 'booking_close', mpfbsFromInputValue( value ) ) }
						error={ errors.booking_close }
						hint={ mpfbsText( 'Leave blank to accept bookings until departure.' ) }
					/>

					<NumberField
						label={ mpfbsText( 'Passenger capacity override' ) }
						name="passenger_capacity_override"
						value={ Number( values.passenger_capacity_override ?? 0 ) }
						onChange={ ( value ) => setValue( 'passenger_capacity_override', value ) }
						min={ 0 }
						suffix={ mpfbsText( 'seats' ) }
						error={ errors.passenger_capacity_override }
						hint={ mpfbsText( 'Leave at 0 to use the vessel’s own capacity.' ) }
					/>

					<NumberField
						label={ mpfbsText( 'Vehicle capacity override' ) }
						name="vehicle_capacity_override"
						value={ Number( values.vehicle_capacity_override ?? 0 ) }
						onChange={ ( value ) => setValue( 'vehicle_capacity_override', value ) }
						min={ 0 }
						suffix={ mpfbsText( 'vehicles' ) }
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
						label={ mpfbsText( 'Lane metre override' ) }
						name="deck_capacity_override"
						value={ Number( values.deck_capacity_override ?? 0 ) }
						onChange={ ( value ) => setValue( 'deck_capacity_override', value ) }
						min={ 0 }
						step={ 0.5 }
						suffix="m"
						error={ errors.deck_capacity_override }
						hint={ mpfbsText( 'Leave at 0 to use the vessel’s own lane metres.' ) }
					/>

					<SelectField
						label={ mpfbsText( 'Status' ) }
						name="status"
						value={ String( values.status ?? 'scheduled' ) }
						options={ STATUS_OPTIONS.map( ( option ) => ( { value: option.value, label: mpfbsText( option.label ) } ) ) }
						onChange={ ( value ) => setValue( 'status', value ) }
						error={ errors.status }
					/>

					<SelectField
						label={ mpfbsText( 'Fare adjustment' ) }
						name="price_adjustment_type"
						value={ String( values.price_adjustment_type ?? 'none' ) }
						options={ [
							{ value: 'none', label: mpfbsText( 'Standard route fares' ) },
							{ value: 'percent', label: mpfbsText( 'Adjust by a percentage' ) },
							{ value: 'fixed', label: mpfbsText( 'Adjust by a fixed amount' ) },
						] }
						onChange={ ( value ) => setValue( 'price_adjustment_type', value ) }
						error={ errors.price_adjustment_type }
						hint={ mpfbsText( 'Applies to fares on this departure only. A negative value reduces them.' ) }
					/>

					{ values.price_adjustment_type === 'percent' ? (
						<NumberField
							label={ mpfbsText( 'Percentage adjustment' ) }
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
							label={ mpfbsText( 'Fixed adjustment per fare' ) }
							name="price_adjustment"
							value={ mpfbsToMajor( Number( values.price_adjustment ?? 0 ) ) }
							step={ mpfbsMoneyStep() }
							suffix={ mpfbsConfig().currency.code }
							onChange={ ( value ) => setValue( 'price_adjustment', mpfbsToMinor( value ) ) }
							error={ errors.price_adjustment }
						/>
					) : null }

					<TextAreaField
						label={ mpfbsText( 'Operational notes' ) }
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
				title={ mpfbsText( 'Bulk schedule' ) }
				description={ mpfbsText( 'Repeat a departure pattern across a date range.' ) }
				onClose={ () => setScheduleOpen( false ) }
				footer={
					<>
						<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => setScheduleOpen( false ) } disabled={ scheduleBusy }>
							{ mpfbsText( 'Cancel' ) }
						</button>
						<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => void runSchedule( false ) } disabled={ scheduleBusy }>
							{ mpfbsText( 'Preview' ) }
						</button>
						<button
							type="button"
							className="mpfbs-button mpfbs-button--primary"
							onClick={ () => void runSchedule( true ) }
							disabled={ scheduleBusy || preview === null || ( preview.summary.new ?? 0 ) === 0 }
						>
							{ preview
								? mpfbsFormat( 'Create %s sailings', String( preview.summary.new ?? 0 ) )
								: mpfbsText( 'Create sailings' ) }
						</button>
					</>
				}
			>
				<form className="mpfbs-form" onSubmit={ ( event ) => event.preventDefault() }>
					{ patternError ? (
						<div className="mpfbs-alert mpfbs-alert--error" role="alert">
							{ patternError }
						</div>
					) : null }

					<SelectField
						label={ mpfbsText( 'Route' ) }
						name="route_id"
						value={ String( pattern.route_id ?? 0 ) }
						options={ routeOptions }
						placeholder={ mpfbsText( 'Select a route' ) }
						onChange={ ( value ) => setPatternValue( 'route_id', Number( value ) ) }
						error={ patternErrors.route_id }
						required
					/>

					<SelectField
						label={ mpfbsText( 'Vessel' ) }
						name="vessel_id"
						value={ String( pattern.vessel_id ?? 0 ) }
						options={ vesselOptions }
						placeholder={ mpfbsText( 'Select a vessel' ) }
						onChange={ ( value ) => setPatternValue( 'vessel_id', Number( value ) ) }
						error={ patternErrors.vessel_id }
						required
					/>

					<div className="mpfbs-form__row">
						<TextField
							label={ mpfbsText( 'From' ) }
							name="date_from"
							type="date"
							value={ String( pattern.date_from ?? '' ) }
							onChange={ ( value ) => setPatternValue( 'date_from', value ) }
							error={ patternErrors.date_from }
							required
						/>
						<TextField
							label={ mpfbsText( 'To' ) }
							name="date_to"
							type="date"
							value={ String( pattern.date_to ?? '' ) }
							onChange={ ( value ) => setPatternValue( 'date_to', value ) }
							error={ patternErrors.date_to }
							required
						/>
					</div>

					<WeekdayField
						label={ mpfbsText( 'Days of the week' ) }
						values={ ( pattern.weekdays as number[] ) ?? [] }
						onChange={ ( value ) => setPatternValue( 'weekdays', value ) }
						startOfWeek={ mpfbsConfig().startOfWeek }
						error={ patternErrors.weekdays }
					/>

					<TimeListField
						label={ mpfbsText( 'Departure times' ) }
						values={ ( pattern.times as string[] ) ?? [] }
						onChange={ ( value ) => setPatternValue( 'times', value ) }
						error={ patternErrors.times }
						hint={ mpfbsText( 'Each time runs on every selected day.' ) }
					/>

					<NumberField
						label={ mpfbsText( 'Bookings close' ) }
						name="booking_close_minutes"
						value={ Number( pattern.booking_close_minutes ?? 0 ) }
						onChange={ ( value ) => setPatternValue( 'booking_close_minutes', value ) }
						min={ 0 }
						suffix={ mpfbsText( 'min before departure' ) }
					/>

					{ scheduleBusy && preview === null ? <LoadingState rows={ 2 } /> : null }

					{ preview ? (
						<div className="mpfbs-preview">
							<div className="mpfbs-preview__summary">
								<span className="mpfbs-pill mpfbs-pill--positive">
									{ mpfbsFormat( '%s new', String( preview.summary.new ?? 0 ) ) }
								</span>
								<span className="mpfbs-pill mpfbs-pill--muted">
									{ mpfbsFormat( '%s already scheduled', String( preview.summary.duplicate ?? 0 ) ) }
								</span>
								<span className="mpfbs-pill mpfbs-pill--danger">
									{ mpfbsFormat( '%s in conflict', String( preview.summary.conflict ?? 0 ) ) }
								</span>
							</div>

							<ul className="mpfbs-preview__list">
								{ preview.candidates.slice( 0, 60 ).map( ( candidate ) => (
									<li className={ `mpfbs-preview__item is-${ candidate.outcome }` } key={ candidate.departure_datetime }>
										<span className="mpfbs-preview__when">
											{ mpfbsFormatDate( candidate.departure_datetime ) } · { mpfbsFormatTime( candidate.departure_datetime ) }
										</span>
										<span className="mpfbs-preview__outcome">
											{ candidate.outcome === 'new'
												? mpfbsText( 'Will be created' )
												: candidate.outcome === 'duplicate'
													? mpfbsText( 'Already scheduled' )
													: candidate.conflict_name
														? mpfbsFormat( 'Vessel busy: %s', candidate.conflict_name )
														: mpfbsText( 'Vessel busy' ) }
										</span>
									</li>
								) ) }
							</ul>

							{ preview.candidates.length > 60 ? (
								<p className="mpfbs-field__hint">
									{ mpfbsFormat( 'Showing the first 60 of %s.', String( preview.candidates.length ) ) }
								</p>
							) : null }
						</div>
					) : null }
				</form>
			</Drawer>

			<ConfirmDialog
				open={ deleting !== null }
				title={ mpfbsText( 'Delete sailing?' ) }
				message={ mpfbsText(
					'The sailing will be moved to the trash. Sailings that already carry bookings cannot be deleted — cancel them instead so passengers are notified.'
				) }
				confirmLabel={ mpfbsText( 'Delete' ) }
				busy={ deleteBusy }
				onConfirm={ confirmDelete }
				onCancel={ () => setDeleting( null ) }
			/>
		</>
	);
}
