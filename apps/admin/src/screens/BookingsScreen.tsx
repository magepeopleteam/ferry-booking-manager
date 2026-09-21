/**
 * Bookings screen.
 *
 * The staff list of every booking: reference, customer, departure, totals and
 * status, with search and status filters. Selecting a row opens a drawer with
 * the booking's full record and the option to cancel it, which releases its
 * capacity back to the sailing.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { ConfirmDialog } from '../components/ConfirmDialog';
import { DataTable, type Column } from '../components/DataTable';
import { Drawer } from '../components/Drawer';
import { SelectField, TextAreaField, TextField } from '../components/Fields';
import { FilterBar } from '../components/FilterBar';
import { PageHeader } from '../components/PageHeader';
import { Pagination } from '../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, mpfbsRestUrl, MpfbsApiError } from '../lib/api';
import { mpfbsCan, mpfbsConfig } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsFormatMoney } from '../lib/money';
import { mpfbsNavigate } from '../lib/router';
import { useMpfbsCollection } from '../lib/useCollection';

interface BookingRow {
	id: number;
	name: string;
	number: string;
	customer_name: string;
	customer_email: string;
	customer_phone: string;
	sailing_id: number;
	return_sailing_id: number;
	booking_type: string;
	passenger_count: number;
	vehicle_count: number;
	subtotal: number;
	discount: number;
	tax: number;
	fees: number;
	total: number;
	paid: number;
	currency: string;
	payment_method: string;
	payment_status: string;
	booking_status: string;
	departure: string;
	created: string;
	origin: string;
	destination: string;
	route_name: string;
	balance: number;
	[ key: string ]: unknown;
}

/**
 * One leg of a booking's journey, as the single-booking route resolves it.
 */
interface BookingLeg {
	label: string;
	origin: string;
	destination: string;
	route: string;
	vessel: string;
	departure: string;
	arrival: string;
	status: string;
}

/**
 * One traveller, with the detail fields the operator asked them for.
 */
interface BookingTraveller {
	type_id: number;
	type_name: string;
	details: Array< { key: string; label: string; value: string } >;
}

/**
 * The full record behind a list row: everything the drawer shows.
 */
interface BookingRecord extends BookingRow {
	legs: BookingLeg[];
	passengers: BookingTraveller[];
	vehicles: BookingTraveller[];
}

const STATUS_OPTIONS = [
	{ value: 'pending', label: 'Pending' },
	{ value: 'on_hold', label: 'On hold' },
	{ value: 'confirmed', label: 'Confirmed' },
	{ value: 'cancelled', label: 'Cancelled' },
];

/**
 * Renders a status pill.
 */
function statusPill( status: string, payment: string ): JSX.Element {
	const bookingTone =
		status === 'confirmed' || status === 'completed'
			? 'positive'
			: status === 'cancelled' || status === 'refunded' || status === 'failed'
				? 'danger'
				: status === 'on_hold'
					? 'warning'
					: 'muted';

	const paymentTone = payment === 'paid' ? 'positive' : payment === 'unpaid' ? 'warning' : 'muted';

	return (
		<span className="mpfbs-stack">
			<span className={ `mpfbs-pill mpfbs-pill--${ bookingTone }` }>{ mpfbsText( label( status ) ) }</span>
			<span className={ `mpfbs-pill mpfbs-pill--${ paymentTone }` }>{ mpfbsText( label( payment ) ) }</span>
		</span>
	);
}

/**
 * Maps a stored slug to its display label.
 */
function label( slug: string ): string {
	const labels: Record< string, string > = {
		pending: 'Pending',
		on_hold: 'On hold',
		confirmed: 'Confirmed',
		completed: 'Completed',
		cancelled: 'Cancelled',
		failed: 'Failed',
		refunded: 'Refunded',
		unpaid: 'Unpaid',
		paid: 'Paid',
		partially_paid: 'Partially paid',
	};

	return labels[ slug ] ?? slug;
}

/**
 * Labels a booking type.
 */
function journeyLabel( type: string ): string {
	return type === RETURN_TYPE ? mpfbsText( 'Return' ) : mpfbsText( 'One way' );
}

const RETURN_TYPE = 'return';

/**
 * Renders the bookings list.
 */
export function BookingsScreen(): JSX.Element {
	const toast = useMpfbsToast();
	const collection = useMpfbsCollection< BookingRow >( 'bookings', {
		orderby: 'created',
		order: 'desc',
	} );
	const [ selected, setSelected ] = useState< BookingRow | null >( null );
	const [ cancelling, setCancelling ] = useState< BookingRow | null >( null );
	const [ cancelBusy, setCancelBusy ] = useState( false );

	const columns = useMemo(
		() =>
			[
				{
					key: 'number',
					label: mpfbsText( 'Reference' ),
					width: '150px',
					render: ( item: BookingRow ) => <code className="mpfbs-code">{ item.number }</code>,
				},
				{
					key: 'name',
					label: mpfbsText( 'Customer' ),
					render: ( item: BookingRow ) => (
						<div className="mpfbs-cell-primary">
							<span className="mpfbs-cell-primary__title">{ item.customer_name || '—' }</span>
							<span className="mpfbs-cell-primary__meta">{ item.customer_email }</span>
						</div>
					),
				},
				{
					key: 'journey',
					label: mpfbsText( 'Journey' ),
					render: ( item: BookingRow ) => (
						<div className="mpfbs-cell-primary">
							<span className="mpfbs-cell-primary__title">
								{ item.origin && item.destination
									? `${ item.origin } → ${ item.destination }`
									: item.route_name || '—' }
							</span>
							<span className="mpfbs-cell-primary__meta">
								{ journeyLabel( String( item.booking_type ?? '' ) ) }
							</span>
						</div>
					),
				},
				{
					key: 'departure',
					label: mpfbsText( 'Departure' ),
					width: '170px',
					render: ( item: BookingRow ) => <span>{ item.departure || '—' }</span>,
				},
				{
					key: 'passenger_count',
					label: mpfbsText( 'Party' ),
					width: '110px',
					align: 'end',
					render: ( item: BookingRow ) => (
						<span>
							{ Number( item.passenger_count ?? 0 ) }
							{ Number( item.vehicle_count ?? 0 ) > 0 ? ` + ${ Number( item.vehicle_count ) }` : '' }
						</span>
					),
				},
				{
					key: 'total',
					label: mpfbsText( 'Total' ),
					sortBy: 'total',
					width: '130px',
					align: 'end',
					render: ( item: BookingRow ) => <span>{ mpfbsFormatMoney( Number( item.total ?? 0 ) ) }</span>,
				},
				{
					key: 'status',
					label: mpfbsText( 'Status' ),
					width: '160px',
					render: ( item: BookingRow ) => statusPill( String( item.booking_status ?? '' ), String( item.payment_status ?? '' ) ),
				},
			] as Array< Column< BookingRow > >,
		[]
	);

	const cancel = useCallback( async () => {
		if ( ! cancelling ) {
			return;
		}

		setCancelBusy( true );

		try {
			await mpfbsRequest( `bookings/${ cancelling.id }`, { method: 'DELETE' } );
			toast.notify( mpfbsText( 'Booking cancelled.' ), 'success' );
			setSelected( null );
			setCancelling( null );
			collection.reload();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
		} finally {
			setCancelBusy( false );
		}
	}, [ cancelling, collection, toast ] );

	const canCreate = mpfbsCan( 'mpfbs_create_booking' );

	const exportUrl = mpfbsRestUrl( 'bookings/export', {
		search: collection.query.search,
		status: collection.query.status,
		_wpnonce: mpfbsConfig().restNonce,
	} );

	const canCancel = ( item: BookingRow ): boolean => {
		const status = String( item.booking_status ?? '' );

		return status !== 'cancelled' && status !== 'refunded' && status !== 'completed';
	};

	if ( collection.error ) {
		return (
			<>
				<PageHeader title={ mpfbsText( 'Bookings' ) } />
				<ErrorState message={ collection.error.message } code={ collection.error.code } onRetry={ collection.reload } />
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ mpfbsText( 'Bookings' ) }
				description={ mpfbsText( 'Every crossing sold, and what still needs to happen before departure.' ) }
				actions={
					<>
						{ /*
						 * Exports what the filters currently select rather than
						 * the whole book: the list on screen is the answer
						 * somebody just built, and it is almost always the one
						 * they want in a spreadsheet.
						 */ }
						<a className="mpfbs-button" href={ exportUrl }>
							{ mpfbsText( 'Export CSV' ) }
						</a>

						{ canCreate ? (
							<button
								type="button"
								className="mpfbs-button mpfbs-button--primary"
								onClick={ () => mpfbsNavigate( '/bookings/new' ) }
							>
								{ mpfbsText( 'New booking' ) }
							</button>
						) : null }
					</>
				}
			/>

			<FilterBar
				search={ collection.query.search }
				onSearch={ collection.setSearch }
				searchPlaceholder={ mpfbsText( 'Search by reference, customer or email' ) }
				statusOptions={ STATUS_OPTIONS }
				status={ collection.query.status }
				onStatus={ ( value ) => collection.setQuery( { status: value } ) }
			/>

			<div className="mpfbs-panel">
				{ collection.loading ? (
					<LoadingState rows={ 6 } />
				) : (
					<>
						<DataTable
							columns={ columns }
							items={ collection.items }
							rowKey={ ( item ) => item.id }
							loading={ collection.loading }
							refreshing={ collection.refreshing }
							orderby={ collection.query.orderby }
							order={ collection.query.order }
							onSort={ collection.toggleSort }
							onRowClick={ setSelected }
							emptyState={
								<EmptyState
									icon="ticket"
									title={ mpfbsText( 'No bookings yet.' ) }
									description={
										collection.query.search !== '' || collection.query.status !== ''
											? mpfbsText( 'Try a different search or filter.' )
											: mpfbsText( 'Bookings made on the website or by staff appear here.' )
									}
								/>
							}
						/>

						<Pagination
							page={ Number( collection.meta.page ?? 1 ) }
							totalPages={ Number( collection.meta.total_pages ?? 1 ) }
							total={ Number( collection.meta.total ?? 0 ) }
							perPage={ Number( collection.meta.per_page ?? 20 ) }
							onPage={ collection.setPage }
							onPerPage={ ( perPage ) => collection.setQuery( { per_page: perPage } ) }
						/>
					</>
				) }
			</div>

			<Drawer open={ selected !== null } onClose={ () => setSelected( null ) } title={ selected ? selected.number : '' }>
				{ selected ? (
					<BookingDetails
						row={ selected }
						onCancel={ () => setCancelling( selected ) }
						canCancel={ canCancel( selected ) }
						canEdit={ mpfbsCan( 'mpfbs_modify_booking' ) }
						onSaved={ ( updated ) => {
							setSelected( updated );
							collection.reload();
						} }
					/>
				) : null }
			</Drawer>

			<ConfirmDialog
				open={ cancelling !== null }
				title={ mpfbsText( 'Cancel booking?' ) }
				message={
					cancelling
						? mpfbsFormat( 'Cancel booking %s? Its capacity returns to the sailing and the customer is notified.', cancelling.number )
						: ''
				}
				confirmLabel={ mpfbsText( 'Cancel booking' ) }
				tone="danger"
				busy={ cancelBusy }
				onConfirm={ cancel }
				onCancel={ () => setCancelling( null ) }
			/>
		</>
	);
}

interface BookingDetailsProps {
	row: BookingRow;
	onCancel: () => void;
	canCancel: boolean;
	canEdit: boolean;
	onSaved: ( row: BookingRow ) => void;
}

/**
 * The fields staff may change on a booking.
 *
 * The party and the sailing are deliberately absent: changing either is a
 * re-price and a fresh capacity check, which is a different operation from
 * correcting a misspelled surname.
 */
interface EditableBooking {
	customer_name: string;
	customer_email: string;
	customer_phone: string;
	booking_status: string;
	payment_status: string;
	payment_method: string;
	internal_notes: string;
}

const BOOKING_STATUSES = [
	{ value: 'pending', label: 'Pending' },
	{ value: 'on_hold', label: 'On hold' },
	{ value: 'confirmed', label: 'Confirmed' },
	{ value: 'completed', label: 'Completed' },
	{ value: 'cancelled', label: 'Cancelled' },
	{ value: 'refunded', label: 'Refunded' },
	{ value: 'failed', label: 'Failed' },
];

const PAYMENT_STATUSES = [
	{ value: 'unpaid', label: 'Unpaid' },
	{ value: 'partially_paid', label: 'Partially paid' },
	{ value: 'paid', label: 'Paid' },
	{ value: 'refunded', label: 'Refunded' },
	{ value: 'cancelled', label: 'Cancelled' },
];

/**
 * Reads the editable subset out of a booking row.
 */
function editableFrom( row: BookingRow ): EditableBooking {
	return {
		customer_name: String( row.customer_name ?? '' ),
		customer_email: String( row.customer_email ?? '' ),
		customer_phone: String( row.customer_phone ?? '' ),
		booking_status: String( row.booking_status ?? 'pending' ),
		payment_status: String( row.payment_status ?? 'unpaid' ),
		payment_method: String( row.payment_method ?? '' ),
		internal_notes: String( row.internal_notes ?? '' ),
	};
}

/**
 * Renders one booking as an editable record.
 */
function BookingDetails( { row, onCancel, canCancel, canEdit, onSaved }: BookingDetailsProps ): JSX.Element {
	const toast = useMpfbsToast();
	const [ values, setValues ] = useState< EditableBooking >( () => editableFrom( row ) );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ formError, setFormError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ refreshed, setRefreshed ] = useState( 0 );

	const [ record, setRecord ] = useState< BookingRecord | null >( null );

	// A different booking in the same drawer instance has to reset the form,
	// or an edit typed against one reference would be saved onto another.
	useEffect( () => {
		setValues( editableFrom( row ) );
		setErrors( {} );
		setFormError( '' );
	}, [ row ] );

	/*
	 * The list row is deliberately thin — it carries no itinerary and no
	 * travellers, because sending them for twenty rows nobody has opened is
	 * twenty journeys and twenty parties resolved for nothing. The drawer is
	 * the moment staff actually asked, so it reads the full record then.
	 */
	useEffect( () => {
		let cancelled = false;

		setRecord( null );

		mpfbsRequest< BookingRecord >( `bookings/${ row.id }` )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setRecord( response.data );
				}
			} )
			// The form above still works without it: the sections it feeds
			// simply stay out of the way rather than showing an error over a
			// booking staff can otherwise edit.
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [ row.id, refreshed ] );

	const dirty = useMemo( () => {
		const original = editableFrom( row );

		return ( Object.keys( original ) as Array< keyof EditableBooking > ).some( ( key ) => original[ key ] !== values[ key ] );
	}, [ row, values ] );

	const set = useCallback( ( key: keyof EditableBooking, value: string ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => {
			if ( ! current[ key ] ) {
				return current;
			}

			const next = { ...current };
			delete next[ key ];

			return next;
		} );
	}, [] );

	const save = useCallback( async () => {
		setSaving( true );
		setErrors( {} );
		setFormError( '' );

		try {
			const response = await mpfbsRequest< BookingRow >( `bookings/${ row.id }`, { method: 'PUT', body: values } );
			toast.notify( mpfbsFormat( 'Booking %s updated.', row.number ), 'success' );
			setRefreshed( ( count ) => count + 1 );
			onSaved( response.data );
		} catch ( caught: unknown ) {
			if ( caught instanceof MpfbsApiError ) {
				setErrors( caught.details.fields && typeof caught.details.fields === 'object' ? ( caught.details.fields as Record< string, string > ) : {} );
				setFormError( caught.message );
			} else {
				setFormError( mpfbsText( 'Something went wrong.' ) );
			}
		} finally {
			setSaving( false );
		}
	}, [ onSaved, row.id, row.number, toast, values ] );

	const summary = [
		{ label: mpfbsText( 'Departure' ), value: row.departure || '—' },
		{ label: mpfbsText( 'Journey' ), value: journeyLabel( String( row.booking_type ?? '' ) ) },
		{
			label: mpfbsText( 'Party' ),
			value: mpfbsFormat(
				'%1$s passengers, %2$s vehicles',
				String( Number( row.passenger_count ?? 0 ) ),
				String( Number( row.vehicle_count ?? 0 ) )
			),
		},
		{ label: mpfbsText( 'Created' ), value: row.created ? new Date( row.created + 'Z' ).toLocaleString() : '—' },
	];

	const money = [
		{ label: mpfbsText( 'Subtotal' ), value: mpfbsFormatMoney( Number( row.subtotal ?? 0 ) ) },
		{ label: mpfbsText( 'Discount' ), value: mpfbsFormatMoney( -Number( row.discount ?? 0 ) ) },
		{ label: mpfbsText( 'Tax' ), value: mpfbsFormatMoney( Number( row.tax ?? 0 ) ) },
		{ label: mpfbsText( 'Fees' ), value: mpfbsFormatMoney( Number( row.fees ?? 0 ) ) },
	];

	// What is still owed is the number staff are usually looking for, and it is
	// the one figure the record never stored: it is total less paid plus
	// anything refunded, worked out by the server so both halves agree.
	const balance = Number( row.balance ?? Number( row.total ?? 0 ) - Number( row.paid ?? 0 ) );

	return (
		<div className="mpfbs-drawer__body">
			{ formError ? (
				<div className="mpfbs-alert mpfbs-alert--error" role="alert">
					{ formError }
				</div>
			) : null }

			<div className="mpfbs-record">
				<div className="mpfbs-record__head">
					<div>
						<p className="mpfbs-record__ref">{ row.number }</p>
						<p className="mpfbs-record__meta">{ row.departure || '—' }</p>
					</div>
					<p className="mpfbs-record__total">{ mpfbsFormatMoney( Number( row.total ?? 0 ) ) }</p>
				</div>

				<dl className="mpfbs-record__grid">
					{ summary.map( ( item ) => (
						<div className="mpfbs-record__row" key={ item.label }>
							<dt className="mpfbs-record__label">{ item.label }</dt>
							<dd className="mpfbs-record__value">{ item.value }</dd>
						</div>
					) ) }
				</dl>
			</div>

			{ record && record.legs.length > 0 ? (
				<section className="mpfbs-record__section">
					<h3 className="mpfbs-subheading">{ mpfbsText( 'Journey' ) }</h3>
					<ol className="mpfbs-legs">
						{ record.legs.map( ( leg, index ) => (
							<li className="mpfbs-legs__item" key={ index }>
								<span className="mpfbs-legs__label">{ leg.label }</span>
								<span className="mpfbs-legs__ports">{ `${ leg.origin } → ${ leg.destination }` }</span>
								<span className="mpfbs-legs__meta">
									{ [ mpfbsDate( leg.departure ), mpfbsClock( leg.departure ), leg.arrival ? `– ${ mpfbsClock( leg.arrival ) }` : '', leg.vessel ]
										.filter( Boolean )
										.join( ' · ' ) }
								</span>
							</li>
						) ) }
					</ol>
				</section>
			) : null }

			{ record ? (
				<>
					<TravellerList
						title={ mpfbsText( 'Passengers' ) }
						travellers={ record.passengers }
						empty={ mpfbsText( 'No passenger details were captured for this booking.' ) }
					/>

					{ record.vehicles.length > 0 ? (
						<TravellerList title={ mpfbsText( 'Vehicles' ) } travellers={ record.vehicles } empty="" />
					) : null }
				</>
			) : null }

			<form
				className="mpfbs-form"
				onSubmit={ ( event ) => {
					event.preventDefault();

					if ( canEdit ) {
						void save();
					}
				} }
			>
				<h3 className="mpfbs-subheading">{ mpfbsText( 'Customer' ) }</h3>

				<TextField
					label={ mpfbsText( 'Full name' ) }
					name="customer_name"
					value={ values.customer_name }
					onChange={ ( value ) => set( 'customer_name', value ) }
					error={ errors.customer_name }
					disabled={ ! canEdit }
					required
				/>

				<TextField
					label={ mpfbsText( 'Email' ) }
					name="customer_email"
					type="email"
					value={ values.customer_email }
					onChange={ ( value ) => set( 'customer_email', value ) }
					error={ errors.customer_email }
					disabled={ ! canEdit }
					required
				/>

				<TextField
					label={ mpfbsText( 'Phone' ) }
					name="customer_phone"
					type="tel"
					value={ values.customer_phone }
					onChange={ ( value ) => set( 'customer_phone', value ) }
					error={ errors.customer_phone }
					disabled={ ! canEdit }
				/>

				<h3 className="mpfbs-subheading">{ mpfbsText( 'Status' ) }</h3>

				<SelectField
					label={ mpfbsText( 'Booking status' ) }
					name="booking_status"
					value={ values.booking_status }
					options={ BOOKING_STATUSES.map( ( option ) => ( { value: option.value, label: mpfbsText( option.label ) } ) ) }
					onChange={ ( value ) => set( 'booking_status', value ) }
					error={ errors.booking_status }
					disabled={ ! canEdit }
					hint={ mpfbsText( 'Reinstating a cancelled booking is checked against the sailing’s remaining capacity.' ) }
				/>

				<SelectField
					label={ mpfbsText( 'Payment status' ) }
					name="payment_status"
					value={ values.payment_status }
					options={ PAYMENT_STATUSES.map( ( option ) => ( { value: option.value, label: mpfbsText( option.label ) } ) ) }
					onChange={ ( value ) => set( 'payment_status', value ) }
					error={ errors.payment_status }
					disabled={ ! canEdit }
				/>

				<TextField
					label={ mpfbsText( 'Payment method' ) }
					name="payment_method"
					value={ values.payment_method }
					onChange={ ( value ) => set( 'payment_method', value ) }
					error={ errors.payment_method }
					disabled={ ! canEdit }
				/>

				<h3 className="mpfbs-subheading">{ mpfbsText( 'Price' ) }</h3>

				<dl className="mpfbs-record__grid">
					{ money.map( ( item ) => (
						<div className="mpfbs-record__row" key={ item.label }>
							<dt className="mpfbs-record__label">{ item.label }</dt>
							<dd className="mpfbs-record__value">{ item.value }</dd>
						</div>
					) ) }
					<div className="mpfbs-record__row is-total">
						<dt className="mpfbs-record__label">{ mpfbsText( 'Total' ) }</dt>
						<dd className="mpfbs-record__value">{ mpfbsFormatMoney( Number( row.total ?? 0 ) ) }</dd>
					</div>
					<div className="mpfbs-record__row">
						<dt className="mpfbs-record__label">{ mpfbsText( 'Paid' ) }</dt>
						<dd className="mpfbs-record__value">{ mpfbsFormatMoney( Number( row.paid ?? 0 ) ) }</dd>
					</div>
					<div className={ `mpfbs-record__row${ balance > 0 ? ' is-due' : '' }` }>
						<dt className="mpfbs-record__label">
							{ balance < 0 ? mpfbsText( 'Refund due' ) : mpfbsText( 'Balance due' ) }
						</dt>
						<dd className="mpfbs-record__value">{ mpfbsFormatMoney( Math.abs( balance ) ) }</dd>
					</div>
				</dl>

				<h3 className="mpfbs-subheading">{ mpfbsText( 'Internal notes' ) }</h3>

				<TextAreaField
					label={ mpfbsText( 'Notes' ) }
					name="internal_notes"
					value={ values.internal_notes }
					onChange={ ( value ) => set( 'internal_notes', value ) }
					rows={ 3 }
					hint={ mpfbsText( 'Staff only. Never shown to the customer.' ) }
				/>
			</form>

			<div className="mpfbs-drawer__footer">
				{ canCancel ? (
					<button type="button" className="mpfbs-button mpfbs-button--danger" onClick={ onCancel } disabled={ saving }>
						{ mpfbsText( 'Cancel booking' ) }
					</button>
				) : (
					<span />
				) }

				{ canEdit ? (
					<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ save } disabled={ saving || ! dirty }>
						{ saving ? mpfbsText( 'Saving…' ) : mpfbsText( 'Save changes' ) }
					</button>
				) : null }
			</div>
		</div>
	);
}

/**
 * Lists one half of a booking's party.
 *
 * Each traveller is printed with the fields the operator configured, in the
 * order the form asks for them, so a member of staff checking a passport reads
 * the same labels the customer filled in.
 */
function TravellerList( {
	title,
	travellers,
	empty,
}: {
	title: string;
	travellers: BookingTraveller[];
	empty: string;
} ): JSX.Element | null {
	if ( travellers.length === 0 ) {
		return empty === '' ? null : (
			<section className="mpfbs-record__section">
				<h3 className="mpfbs-subheading">{ title }</h3>
				<p className="mpfbs-record__empty">{ empty }</p>
			</section>
		);
	}

	return (
		<section className="mpfbs-record__section">
			<h3 className="mpfbs-subheading">{ title }</h3>
			<ol className="mpfbs-travellers">
				{ travellers.map( ( traveller, index ) => (
					<li className="mpfbs-travellers__item" key={ index }>
						<p className="mpfbs-travellers__head">
							<span className="mpfbs-travellers__index">{ index + 1 }</span>
							<span className="mpfbs-travellers__type">{ traveller.type_name || mpfbsText( 'Unknown type' ) }</span>
						</p>
						{ traveller.details.length === 0 ? (
							<p className="mpfbs-travellers__none">{ mpfbsText( 'No details were captured.' ) }</p>
						) : (
							<dl className="mpfbs-travellers__details">
								{ traveller.details.map( ( detail ) => (
									<div className="mpfbs-travellers__detail" key={ detail.key }>
										<dt>{ detail.label }</dt>
										<dd>{ detail.value }</dd>
									</div>
								) ) }
							</dl>
						) }
					</li>
				) ) }
			</ol>
		</section>
	);
}

/**
 * Formats a stored "Y-m-d H:i:s" sailing time as a date.
 */
function mpfbsDate( value: string ): string {
	const date = mpfbsParse( value );

	return date === null ? value : date.toLocaleDateString();
}

/**
 * Formats a stored "Y-m-d H:i:s" sailing time as a clock time.
 */
function mpfbsClock( value: string ): string {
	const date = mpfbsParse( value );

	return date === null ? '' : date.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
}

/**
 * Parses a sailing time.
 *
 * Sailing times are stored and returned in the operator's own timezone with no
 * offset on them, so they are read as local rather than handed to Date, which
 * would take the bare string as UTC and move every departure by the site's
 * offset.
 */
function mpfbsParse( value: string ): Date | null {
	const parts = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec( value );

	if ( parts === null ) {
		return null;
	}

	return new Date(
		Number( parts[ 1 ] ),
		Number( parts[ 2 ] ) - 1,
		Number( parts[ 3 ] ),
		Number( parts[ 4 ] ),
		Number( parts[ 5 ] )
	);
}
