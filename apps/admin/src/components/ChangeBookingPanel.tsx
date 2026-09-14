/**
 * Amend a booking.
 *
 * Staff change bookings constantly — a party grows, someone misses the boat and
 * wants the next one, a car is added to a foot booking. Every one of those is
 * the same operation: re-price the party against a crossing, check the crossing
 * can still take them, and settle the difference. Doing it by editing the total
 * by hand is how a manifest ends up disagreeing with the money.
 *
 * Nothing is committed until Apply. The figures come back from the server for
 * every keystroke, so what is on screen is exactly what will be written, and
 * the customer is only told about it if staff say so.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { NumberField, SelectField, SwitchField, TextField } from './Fields';
import { LoadingState } from './States';
import { useFbmToast } from './Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';

interface PartyType {
	id: number;
	name: string;
	max_per_booking: number;
}

interface ChangeOptions {
	ports: Array< { id: number; name: string } >;
	connections: Record< string, number[] >;
	passenger_types: PartyType[];
	vehicle_types: PartyType[];
	today: string;
}

interface SailingRow {
	sailing_id: number;
	route_name: string;
	departure: string;
	vessel: { name: string };
	availability: {
		passengers: { remaining: number; unlimited: boolean };
		sold_out: boolean;
	};
}

interface ChangeLine {
	label: string;
	quantity: number;
	amount: number;
}

interface ChangeResult {
	booking: string;
	previous_total: number;
	new_total: number;
	difference: number;
	paid: number;
	balance: number;
	outcome: 'payment_due' | 'refund_due' | 'no_change';
	lines: ChangeLine[];
	moved: boolean;
	applied: boolean;
}

export interface ChangeBookingPanelProps {
	bookingId: number;
	/** The party the booking currently holds, as type id to quantity. */
	passengers: Record< number, number >;
	vehicles: Record< number, number >;
	/** Called once a change has been written, so the list and drawer reload. */
	onApplied: () => void;
}

/**
 * Renders the amendment panel.
 */
export function ChangeBookingPanel( {
	bookingId,
	passengers: currentPassengers,
	vehicles: currentVehicles,
	onApplied,
}: ChangeBookingPanelProps ): JSX.Element {
	const toast = useFbmToast();

	const [ options, setOptions ] = useState< ChangeOptions | null >( null );
	const [ passengers, setPassengers ] = useState< Record< number, number > >( currentPassengers );
	const [ vehicles, setVehicles ] = useState< Record< number, number > >( currentVehicles );

	const [ moving, setMoving ] = useState( false );
	const [ origin, setOrigin ] = useState( 0 );
	const [ destination, setDestination ] = useState( 0 );
	const [ date, setDate ] = useState( '' );
	const [ searching, setSearching ] = useState( false );
	const [ results, setResults ] = useState< SailingRow[] | null >( null );
	const [ sailingId, setSailingId ] = useState( 0 );

	const [ reason, setReason ] = useState( '' );
	const [ notify, setNotify ] = useState( true );

	const [ result, setResult ] = useState< ChangeResult | null >( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		fbmRequest< ChangeOptions >( 'booking-options' )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setOptions( response.data );
				setDate( ( current ) => ( current === '' ? response.data.today : current ) );
			} )
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [] );

	/*
	 * Only what actually differs is sent. A change request carrying the party
	 * it already has would still be a party change as far as the server is
	 * concerned, and would flatten the traveller details staff had captured
	 * back to blanks for no reason.
	 */
	const changes = useMemo( () => {
		const body: Record< string, unknown > = {};

		if ( ! same( passengers, currentPassengers ) || ! same( vehicles, currentVehicles ) ) {
			body.passengers = trim( passengers );
			body.vehicles = trim( vehicles );
		}

		if ( moving && sailingId > 0 ) {
			body.sailing_id = sailingId;
		}

		return body;
	}, [ currentPassengers, currentVehicles, moving, passengers, sailingId, vehicles ] );

	const nothingToDo = Object.keys( changes ).length === 0;

	// Re-priced on every edit, so staff are always looking at the current
	// answer rather than one from two keystrokes ago.
	useEffect( () => {
		if ( nothingToDo ) {
			setResult( null );
			setError( '' );

			return undefined;
		}

		let cancelled = false;
		setError( '' );

		fbmRequest< ChangeResult >( `bookings/${ bookingId }/change/preview`, { method: 'POST', body: changes } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setResult( response.data );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setResult( null );
					setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ bookingId, changes, nothingToDo ] );

	const search = useCallback( async () => {
		if ( origin === 0 || destination === 0 || date === '' ) {
			return;
		}

		setSearching( true );
		setResults( null );
		setSailingId( 0 );

		try {
			const response = await fbmRequest< { outbound: SailingRow[] } >(
				'search',
				{ query: { origin, destination, date } }
			);
			setResults( response.data.outbound ?? [] );
		} catch ( caught: unknown ) {
			setResults( [] );
			setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		} finally {
			setSearching( false );
		}
	}, [ date, destination, origin ] );

	const apply = useCallback( async () => {
		setBusy( true );

		try {
			const response = await fbmRequest< ChangeResult >( `bookings/${ bookingId }/change/apply`, {
				method: 'POST',
				body: { ...changes, reason, notify },
			} );

			toast.notify(
				response.data.difference === 0
					? fbmText( 'Booking updated.' )
					: fbmFormat( 'Booking updated. New total %s.', fbmFormatMoney( response.data.new_total ) ),
				'success'
			);
			onApplied();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setBusy( false );
		}
	}, [ bookingId, changes, notify, onApplied, reason, toast ] );

	if ( ! options ) {
		return <LoadingState rows={ 3 } />;
	}

	const reachable =
		origin === 0
			? options.ports
			: options.ports.filter( ( port ) => ( options.connections[ String( origin ) ] ?? [] ).includes( port.id ) );

	return (
		<div className="fbm-change">
			<h3 className="fbm-subheading">{ fbmText( 'Who is travelling' ) }</h3>

			<div className="fbm-settings">
				{ options.passenger_types.map( ( type ) => (
					<NumberField
						key={ `p-${ type.id }` }
						label={ type.name }
						name={ `change_passenger_${ type.id }` }
						value={ passengers[ type.id ] ?? 0 }
						min={ 0 }
						max={ type.max_per_booking > 0 ? type.max_per_booking : undefined }
						onChange={ ( value ) => setPassengers( ( current ) => ( { ...current, [ type.id ]: value } ) ) }
					/>
				) ) }
				{ options.vehicle_types.map( ( type ) => (
					<NumberField
						key={ `v-${ type.id }` }
						label={ type.name }
						name={ `change_vehicle_${ type.id }` }
						value={ vehicles[ type.id ] ?? 0 }
						min={ 0 }
						max={ type.max_per_booking > 0 ? type.max_per_booking : undefined }
						onChange={ ( value ) => setVehicles( ( current ) => ( { ...current, [ type.id ]: value } ) ) }
					/>
				) ) }
			</div>

			<h3 className="fbm-subheading">{ fbmText( 'Crossing' ) }</h3>

			<SwitchField
				label={ fbmText( 'Move to another crossing' ) }
				checked={ moving }
				hint={ fbmText( 'The new crossing is checked for room before the change is written. Leave off to keep the booking where it is.' ) }
				onChange={ ( checked ) => {
					setMoving( checked );

					if ( ! checked ) {
						setSailingId( 0 );
					}
				} }
			/>

			{ moving ? (
				<>
					<div className="fbm-settings">
						<SelectField
							label={ fbmText( 'From' ) }
							name="change_origin"
							value={ String( origin ) }
							options={ [
								{ value: '0', label: fbmText( 'Choose a port' ) },
								...options.ports.map( ( port ) => ( { value: String( port.id ), label: port.name } ) ),
							] }
							onChange={ ( value ) => {
								setOrigin( Number( value ) );
								setDestination( 0 );
								setResults( null );
								setSailingId( 0 );
							} }
						/>
						<SelectField
							label={ fbmText( 'To' ) }
							name="change_destination"
							value={ String( destination ) }
							options={ [
								{ value: '0', label: fbmText( 'Choose a port' ) },
								...reachable
									.filter( ( port ) => port.id !== origin )
									.map( ( port ) => ( { value: String( port.id ), label: port.name } ) ),
							] }
							onChange={ ( value ) => {
								setDestination( Number( value ) );
								setResults( null );
								setSailingId( 0 );
							} }
						/>
						<TextField label={ fbmText( 'Date' ) } name="change_date" type="date" value={ date } onChange={ setDate } />
						<div className="fbm-settings__wide">
							<button
								type="button"
								className="fbm-button"
								onClick={ () => void search() }
								disabled={ searching || origin === 0 || destination === 0 }
							>
								{ searching ? fbmText( 'Searching…' ) : fbmText( 'Find sailings' ) }
							</button>
						</div>
					</div>

					{ results !== null ? (
						<ul className="fbm-sailinglist">
							{ results.length === 0 ? (
								<li className="fbm-sailinglist__empty">{ fbmText( 'No sailings on that date.' ) }</li>
							) : null }
							{ results.map( ( row ) => (
								<li key={ row.sailing_id }>
									<label
										className={ `fbm-sailinglist__row${ sailingId === row.sailing_id ? ' is-selected' : '' }` }
									>
										<input
											type="radio"
											name="fbm-change-sailing"
											checked={ sailingId === row.sailing_id }
											disabled={ row.availability.sold_out }
											onChange={ () => setSailingId( row.sailing_id ) }
										/>
										<span className="fbm-sailinglist__time">{ row.departure.slice( 11, 16 ) }</span>
										<span className="fbm-sailinglist__route">{ row.route_name }</span>
										<span className="fbm-sailinglist__vessel">{ row.vessel.name }</span>
										<span className="fbm-sailinglist__seats">
											{ row.availability.sold_out
												? fbmText( 'Sold out' )
												: fbmFormat(
														'%s seats left',
														String( row.availability.passengers.remaining )
												  ) }
										</span>
									</label>
								</li>
							) ) }
						</ul>
					) : null }
				</>
			) : null }

			<h3 className="fbm-subheading">{ fbmText( 'The money' ) }</h3>

			{ error !== '' ? (
				<div className="fbm-alert fbm-alert--error" role="alert">
					{ error }
				</div>
			) : null }

			{ nothingToDo ? (
				<p className="fbm-record__empty">
					{ fbmText( 'Change the party or pick another crossing to see what it comes to.' ) }
				</p>
			) : null }

			{ result ? (
				<>
					<dl className="fbm-record__grid">
						{ result.lines.map( ( line, index ) => (
							<div className="fbm-record__row" key={ index }>
								<dt className="fbm-record__label">
									{ line.quantity > 1 ? `${ line.label } × ${ line.quantity }` : line.label }
								</dt>
								<dd className="fbm-record__value">{ fbmFormatMoney( line.amount ) }</dd>
							</div>
						) ) }
						<div className="fbm-record__row">
							<dt className="fbm-record__label">{ fbmText( 'Was' ) }</dt>
							<dd className="fbm-record__value">{ fbmFormatMoney( result.previous_total ) }</dd>
						</div>
						<div className="fbm-record__row is-total">
							<dt className="fbm-record__label">{ fbmText( 'New total' ) }</dt>
							<dd className="fbm-record__value">{ fbmFormatMoney( result.new_total ) }</dd>
						</div>
						<div className="fbm-record__row">
							<dt className="fbm-record__label">{ fbmText( 'Paid' ) }</dt>
							<dd className="fbm-record__value">{ fbmFormatMoney( result.paid ) }</dd>
						</div>
						<div className={ `fbm-record__row${ result.balance !== 0 ? ' is-due' : '' }` }>
							<dt className="fbm-record__label">
								{ result.balance < 0 ? fbmText( 'Refund due' ) : fbmText( 'Balance due' ) }
							</dt>
							<dd className="fbm-record__value">{ fbmFormatMoney( Math.abs( result.balance ) ) }</dd>
						</div>
					</dl>

					<p className="fbm-change__outcome">{ outcomeLabel( result ) }</p>
				</>
			) : null }

			<TextField
				label={ fbmText( 'Reason' ) }
				name="change_reason"
				value={ reason }
				placeholder={ fbmText( 'Kept on the booking’s history' ) }
				onChange={ setReason }
			/>

			<SwitchField
				label={ fbmText( 'Tell the customer' ) }
				checked={ notify }
				hint={ fbmText( 'Turn off to correct a booking quietly. Nothing is sent unless an automation is set up for a changed booking.' ) }
				onChange={ setNotify }
			/>

			<div className="fbm-drawer__footer">
				<span />
				<button
					type="button"
					className="fbm-button fbm-button--primary"
					onClick={ () => void apply() }
					disabled={ busy || nothingToDo || result === null }
				>
					{ busy ? fbmText( 'Saving…' ) : fbmText( 'Apply change' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * Says what staff have to do about the difference.
 */
function outcomeLabel( result: ChangeResult ): string {
	if ( result.outcome === 'payment_due' ) {
		return fbmFormat( 'The customer owes a further %s.', fbmFormatMoney( result.difference ) );
	}

	if ( result.outcome === 'refund_due' ) {
		return fbmFormat( 'The booking is %s cheaper. Record the refund on the Refund tab.', fbmFormatMoney( -result.difference ) );
	}

	return fbmText( 'The price is unchanged.' );
}

/**
 * Drops the zeroes, so an untouched type is absent rather than sent as none.
 */
function trim( party: Record< number, number > ): Record< string, number > {
	return Object.fromEntries(
		Object.entries( party ).filter( ( [ , quantity ] ) => Number( quantity ) > 0 )
	);
}

/**
 * Compares two parties by what they contain, ignoring zeroes and key order.
 */
function same( a: Record< number, number >, b: Record< number, number > ): boolean {
	const left = trim( a );
	const right = trim( b );
	const keys = new Set( [ ...Object.keys( left ), ...Object.keys( right ) ] );

	for ( const key of keys ) {
		if ( ( left[ key ] ?? 0 ) !== ( right[ key ] ?? 0 ) ) {
			return false;
		}
	}

	return true;
}
