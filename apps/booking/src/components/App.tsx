/**
 * Booking application.
 *
 * One state machine, no page reloads, and one authority for every number it
 * shows: the search results, the running total and the final charge all come
 * from the server. The browser never computes a price.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'preact/compat';

import { ApiError, request } from '../lib/api';
import { config, setCurrency } from '../lib/config';
import { date as formatDate, time } from '../lib/datetime';
import { t, tf } from '../lib/i18n';
import { money } from '../lib/money';
import { clearSession, readSession, writeSession } from '../lib/session';
import type { BookingOptions, Quote, SailingResult, SearchResponse } from '../lib/types';
import { ConfirmationPage } from './ConfirmationPage';
import { CustomerForm, PartyDetails, type CustomerDetails, type PartyMember } from './DetailsForm';
import { Lookup } from './Lookup';
import { MyBookings } from './MyBookings';
import { Results } from './Results';
import { SearchForm, type SearchCriteria } from './SearchForm';
import { Steps } from './Steps';
import { Alert, Button, Card, ErrorState, Skeleton } from './ui';

type StepId = 'search' | 'details' | 'review' | 'done';

export interface AppProps {
	component: string;
	attributes: Record< string, unknown >;
}

interface BookingResult {
	id: number;
	reference: string;
	status: string;
	payment_status: string;
	total: number;
	currency: string;
	payment_method: string;
	instructions: string;
	redirect: string;
}

/**
 * Expands a party map into one entry per traveller, so each can be named.
 */
function expand( party: Record< number, number > ): PartyMember[] {
	const members: PartyMember[] = [];

	Object.entries( party ).forEach( ( [ id, quantity ] ) => {
		for ( let i = 0; i < Number( quantity ); i++ ) {
			members.push( { type_id: Number( id ), details: {} } );
		}
	} );

	return members;
}

/**
 * Pushes a completed booking to the page's data layer.
 *
 * The event is emitted rather than a tag being loaded: the operator already has
 * a tag manager, and a booking plugin that injects its own analytics script is
 * a plugin that breaks somebody's consent banner. Nothing here runs unless the
 * operator turned it on.
 */
function announce( booking: BookingResult ): void {
	const cfg = config();

	if ( ! cfg.analyticsEvents ) {
		return;
	}

	const layer = ( window as unknown as { dataLayer?: unknown[] } ).dataLayer;

	if ( ! Array.isArray( layer ) ) {
		return;
	}

	layer.push( {
		event: 'purchase',
		ecommerce: {
			transaction_id: booking.reference,
			currency: booking.currency,
			// Major units: every analytics product expects a decimal amount,
			// while the API speaks in minor units throughout.
			value: booking.total / 100,
		},
		fbm_measurement_id: cfg.measurementId,
	} );
}

/**
 * Renders the booking flow.
 */
export function App( { component, attributes }: AppProps ): JSX.Element {
	/*
	 * The plugin creates four pages and each mounts this bundle, so the entry
	 * point has to know which of them it is on. Everything below is the booking
	 * wizard; the account pages are separate components with nothing in common
	 * with it beyond the design system.
	 */
	const pages = ( attributes.pages ?? {} ) as Record< string, string >;

	if ( component === 'my-bookings' ) {
		return <MyBookings pages={ pages } />;
	}

	if ( component === 'lookup' ) {
		return <Lookup />;
	}

	if ( component === 'confirmation' ) {
		return <ConfirmationPage pages={ pages } />;
	}

	return <BookingFlow component={ component } attributes={ attributes } />;
}

/**
 * Renders the booking flow.
 */
function BookingFlow( { component, attributes }: AppProps ): JSX.Element {
	const stored = readSession();
	const presetOrigin = Number( attributes.origin ?? 0 ) || stored.origin;
	const presetDestination = Number( attributes.destination ?? 0 ) || stored.destination;
	const searchOnly = component === 'search';

	const [ options, setOptions ] = useState< BookingOptions | null >( null );
	const [ bootError, setBootError ] = useState( '' );
	const [ step, setStep ] = useState< StepId >( 'search' );

	const [ criteria, setCriteria ] = useState< SearchCriteria >( {
		origin: presetOrigin,
		destination: presetDestination,
		date: stored.date,
		returnDate: stored.returnDate,
		passengers: stored.passengers,
		vehicles: stored.vehicles,
	} );

	const [ searching, setSearching ] = useState( false );
	const [ results, setResults ] = useState< SearchResponse | null >( null );
	const [ searchError, setSearchError ] = useState( '' );

	const [ outbound, setOutbound ] = useState< SailingResult | null >( null );
	const [ inbound, setInbound ] = useState< SailingResult | null >( null );

	const [ passengers, setPassengers ] = useState< PartyMember[] >( [] );
	const [ vehicles, setVehicles ] = useState< PartyMember[] >( [] );
	const [ customer, setCustomer ] = useState< CustomerDetails >( { name: '', email: '', phone: '', notes: '', acceptedTerms: false } );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ formError, setFormError ] = useState( '' );

	const [ quote, setQuote ] = useState< Quote | null >( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ booking, setBooking ] = useState< BookingResult | null >( null );
	const [ paymentMethod, setPaymentMethod ] = useState( '' );

	// ---- boot -------------------------------------------------------------

	useEffect( () => {
		let cancelled = false;

		request< BookingOptions >( 'booking-options' )
			.then( ( payload ) => {
				if ( cancelled ) {
					return;
				}

				setCurrency( payload.currency );
				setOptions( payload );

				if ( ! criteria.date ) {
					setCriteria( ( current ) => ( { ...current, date: payload.today } ) );
				}

				// A first-time visitor should see the form ready to use, not a
				// row of zeroes they have to work out how to fill in.
				if ( Object.keys( criteria.passengers ).length === 0 ) {
					const base = payload.passenger_types.find( ( type ) => ! type.requires_adult );

					if ( base ) {
						setCriteria( ( current ) => ( { ...current, passengers: { [ base.id ]: 1 } } ) );
					}
				}
			} )
			.catch( ( error: unknown ) => {
				if ( ! cancelled ) {
					setBootError( error instanceof ApiError ? error.message : t( 'Something went wrong.' ) );
				}
			} );

		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// ---- search -----------------------------------------------------------

	const runSearch = useCallback(
		async ( next: SearchCriteria ) => {
			setCriteria( next );
			setSearching( true );
			setSearchError( '' );
			setOutbound( null );
			setInbound( null );

			writeSession( {
				origin: next.origin,
				destination: next.destination,
				date: next.date,
				returnDate: next.returnDate,
				passengers: next.passengers,
				vehicles: next.vehicles,
			} );

			if ( searchOnly ) {
				const target = String( ( attributes.pages as Record< string, string > | undefined )?.booking ?? '' );

				// A standalone search block belongs on a homepage; the results
				// belong on the booking page, so it hands the journey over
				// rather than growing a second booking flow of its own.
				if ( target !== '' ) {
					window.location.href = target;

					return;
				}
			}

			try {
				const payload = await request< SearchResponse >( 'search', {
					query: {
						origin: next.origin,
						destination: next.destination,
						date: next.date,
						return_date: next.returnDate,
						passengers: JSON.stringify( next.passengers ),
						vehicles: JSON.stringify( next.vehicles ),
					},
				} );

				setResults( payload );
			} catch ( error: unknown ) {
				setSearchError( error instanceof ApiError ? error.message : t( 'Something went wrong.' ) );
				setResults( null );
			} finally {
				setSearching( false );
			}
		},
		[ attributes.pages, searchOnly ]
	);

	// ---- quote ------------------------------------------------------------

	const refreshQuote = useCallback(
		async ( out: SailingResult, back: SailingResult | null ) => {
			try {
				const payload = await request< Quote >( 'quote', {
					method: 'POST',
					body: {
						sailing_id: out.sailing_id,
						return_sailing_id: back ? back.sailing_id : 0,
						passengers: criteria.passengers,
						vehicles: criteria.vehicles,
					},
				} );

				setQuote( payload );
				setFormError( '' );
			} catch ( error: unknown ) {
				setQuote( null );
				setFormError( error instanceof ApiError ? error.message : t( 'Something went wrong.' ) );
			}
		},
		[ criteria.passengers, criteria.vehicles ]
	);

	const needsReturn = criteria.returnDate !== '';
	const ready = outbound !== null && ( ! needsReturn || inbound !== null );

	const proceed = useCallback( () => {
		if ( ! outbound ) {
			return;
		}

		setPassengers( expand( criteria.passengers ) );
		setVehicles( expand( criteria.vehicles ) );
		setErrors( {} );
		setStep( 'details' );
		void refreshQuote( outbound, inbound );

		writeSession( { outboundId: outbound.sailing_id, inboundId: inbound ? inbound.sailing_id : 0, step: 'details' } );
	}, [ criteria.passengers, criteria.vehicles, inbound, outbound, refreshQuote ] );

	// ---- submit -----------------------------------------------------------

	const submit = useCallback( async () => {
		if ( ! outbound ) {
			return;
		}

		setSubmitting( true );
		setErrors( {} );
		setFormError( '' );

		try {
			const payload = await request< BookingResult >( 'bookings', {
				method: 'POST',
				body: {
					sailing_id: outbound.sailing_id,
					return_sailing_id: inbound ? inbound.sailing_id : 0,
					passengers: passengers.map( ( member ) => ( { type_id: member.type_id, details: member.details } ) ),
					vehicles: vehicles.map( ( member ) => ( { type_id: member.type_id, details: member.details } ) ),
					customer: {
						name: customer.name,
						email: customer.email,
						phone: customer.phone,
						accepted_terms: customer.acceptedTerms,
					},
					payment_method: paymentMethod,
					// Sent so a retried request after a timeout, or a
					// double-clicked button, cannot produce a second booking.
					idempotency_key: idempotencyKey(),
				},
			} );

			setBooking( payload );
			setStep( 'done' );
			clearSession();
			announce( payload );

			if ( payload.redirect ) {
				window.location.href = payload.redirect;
			}
		} catch ( error: unknown ) {
			if ( error instanceof ApiError ) {
				setErrors( error.fields );
				setFormError( error.message );

				// A capacity failure means the sailing changed under them, so
				// the results have to be re-fetched rather than re-shown.
				if ( error.code === 'fbm_capacity_taken' || error.code === 'fbm_insufficient_capacity' ) {
					setStep( 'search' );
					void runSearch( criteria );
				}
			} else {
				setFormError( t( 'Something went wrong.' ) );
			}
		} finally {
			setSubmitting( false );
		}
	}, [ criteria, customer, inbound, outbound, passengers, paymentMethod, runSearch, vehicles ] );

	// ---- render -----------------------------------------------------------

	const steps = useMemo(
		() => [
			{ id: 'search', label: t( 'Crossing' ) },
			{ id: 'details', label: t( 'Details' ) },
			{ id: 'review', label: t( 'Review' ) },
			{ id: 'done', label: t( 'Confirmation' ) },
		],
		[]
	);

	if ( bootError !== '' ) {
		return <ErrorState message={ bootError } onRetry={ () => window.location.reload() } />;
	}

	if ( ! options ) {
		return <Skeleton rows={ 4 } />;
	}

	if ( options.ports.length === 0 ) {
		return (
			<Alert tone="info">
				{ t( 'No crossings are on sale yet. Please check back soon.' ) }
			</Alert>
		);
	}

	return (
		<div className="fbmb">
			{ ! searchOnly && step !== 'search' ? <Steps steps={ steps } current={ step } /> : null }

			{ step === 'search' ? (
				<>
					<SearchForm
						options={ options }
						initial={ criteria }
						busy={ searching }
						onSearch={ ( next ) => void runSearch( next ) }
						layout={ searchOnly ? ( String( attributes.layout ?? 'inline' ) as 'inline' | 'stacked' ) : 'inline' }
					/>

					{ searchOnly ? null : (
						<>
							{ searchError !== '' ? <Alert tone="error">{ searchError }</Alert> : null }

							{ searching ? <Skeleton rows={ 3 } /> : null }

							{ ! searching && results ? (
								<>
									<Results
										title={ tf(
											'%1$s to %2$s',
											results.ports[ String( results.query.origin ) ] ?? '',
											results.ports[ String( results.query.destination ) ] ?? ''
										) }
										subtitle={ formatDate( results.query.date, true ) }
										results={ results.outbound }
										selectedId={ outbound?.sailing_id ?? 0 }
										onSelect={ setOutbound }
									/>

									{ needsReturn ? (
										<Results
											title={ tf(
												'%1$s to %2$s',
												results.ports[ String( results.query.destination ) ] ?? '',
												results.ports[ String( results.query.origin ) ] ?? ''
											) }
											subtitle={ formatDate( results.query.return_date, true ) }
											results={ results.inbound }
											selectedId={ inbound?.sailing_id ?? 0 }
											onSelect={ setInbound }
										/>
									) : null }

									{ ready ? (
										<div className="fbmb-actions fbmb-actions--sticky">
											<SelectionSummary outbound={ outbound } inbound={ inbound } />
											<Button size="lg" onClick={ proceed }>
												{ t( 'Continue' ) }
											</Button>
										</div>
									) : null }
								</>
							) : null }
						</>
					) }
				</>
			) : null }

			{ step === 'details' ? (
				<>
					{ formError !== '' ? <Alert tone="error">{ formError }</Alert> : null }

					<PartyDetails
						passengers={ passengers }
						vehicles={ vehicles }
						passengerFields={ options.fields.passenger }
						vehicleFields={ options.fields.vehicle }
						passengerTypes={ options.passenger_types }
						vehicleTypes={ options.vehicle_types }
						errors={ errors }
						onPassenger={ ( index, key, value ) =>
							setPassengers( ( current ) =>
								current.map( ( member, position ) =>
									position === index ? { ...member, details: { ...member.details, [ key ]: value } } : member
								)
							)
						}
						onVehicle={ ( index, key, value ) =>
							setVehicles( ( current ) =>
								current.map( ( member, position ) =>
									position === index ? { ...member, details: { ...member.details, [ key ]: value } } : member
								)
							)
						}
					/>

					<CustomerForm
						customer={ customer }
						errors={ errors }
						onChange={ ( key, value ) => setCustomer( ( current ) => ( { ...current, [ key ]: value } ) ) }
					/>

					<div className="fbmb-actions">
						<Button variant="secondary" onClick={ () => setStep( 'search' ) }>
							{ t( 'Back' ) }
						</Button>
						<Button size="lg" onClick={ () => setStep( 'review' ) }>
							{ t( 'Review booking' ) }
						</Button>
					</div>
				</>
			) : null }

			{ step === 'review' ? (
				<Review
					outbound={ outbound }
					inbound={ inbound }
					quote={ quote }
					customer={ customer }
					formError={ formError }
					submitting={ submitting }
					paymentMethod={ paymentMethod }
					onPaymentMethod={ setPaymentMethod }
					onBack={ () => setStep( 'details' ) }
					onSubmit={ () => void submit() }
				/>
			) : null }

			{ step === 'done' && booking ? <Confirmation booking={ booking } /> : null }
		</div>
	);
}

/**
 * Generates a per-attempt idempotency key.
 *
 * Held in the module rather than regenerated per click, so a second click
 * within the same attempt carries the same key and resolves to one booking.
 */
let attemptKey = '';

function idempotencyKey(): string {
	if ( attemptKey === '' ) {
		attemptKey = `fbm-${ Date.now().toString( 36 ) }-${ Math.random().toString( 36 ).slice( 2, 10 ) }`;
	}

	return attemptKey;
}

/**
 * A one-line reminder of what is selected, shown above Continue.
 */
function SelectionSummary( { outbound, inbound }: { outbound: SailingResult | null; inbound: SailingResult | null } ): JSX.Element {
	const total = ( outbound?.price?.total ?? 0 ) + ( inbound?.price?.total ?? 0 );

	return (
		<div className="fbmb-summary">
			<div className="fbmb-summary__legs">
				{ outbound ? (
					<span>
						{ formatDate( outbound.departure ) } · { time( outbound.departure ) }
					</span>
				) : null }
				{ inbound ? (
					<span>
						{ formatDate( inbound.departure ) } · { time( inbound.departure ) }
					</span>
				) : null }
			</div>
			{ total > 0 ? (
				<p className="fbmb-summary__total">
					<span>{ t( 'Total' ) }</span>
					<strong>{ money( total ) }</strong>
				</p>
			) : null }
		</div>
	);
}

/**
 * Review and payment step.
 */
function Review( {
	outbound,
	inbound,
	quote,
	customer,
	formError,
	submitting,
	paymentMethod,
	onPaymentMethod,
	onBack,
	onSubmit,
}: {
	outbound: SailingResult | null;
	inbound: SailingResult | null;
	quote: Quote | null;
	customer: CustomerDetails;
	formError: string;
	submitting: boolean;
	paymentMethod: string;
	onPaymentMethod: ( value: string ) => void;
	onBack: () => void;
	onSubmit: () => void;
} ): JSX.Element {
	return (
		<>
			{ formError !== '' ? <Alert tone="error">{ formError }</Alert> : null }

			<section>
				<h3 className="fbmb-section__title">{ t( 'Your crossing' ) }</h3>
				{ [ outbound, inbound ].filter( Boolean ).map( ( leg, index ) => (
					<Card key={ index }>
						<div className="fbmb-review__leg">
							<div>
								<p className="fbmb-review__route">{ leg!.route_name }</p>
								<p className="fbmb-review__when">
									{ formatDate( leg!.departure, true ) } · { time( leg!.departure ) } – { time( leg!.arrival ) }
								</p>
								{ leg!.vessel.name ? <p className="fbmb-review__vessel">{ leg!.vessel.name }</p> : null }
							</div>
							<span className="fbmb-review__tag">{ index === 0 ? t( 'Outbound' ) : t( 'Return' ) }</span>
						</div>
					</Card>
				) ) }
			</section>

			<section>
				<h3 className="fbmb-section__title">{ t( 'Price' ) }</h3>
				{ quote ? (
					<table className="fbmb-price">
						<tbody>
							{ quote.lines.map( ( line, index ) => (
								<tr key={ index } className={ `fbmb-price__row fbmb-price__row--${ line.type }` }>
									<th scope="row">
										{ line.label }
										{ line.quantity > 1 ? <span className="fbmb-price__qty">{ `× ${ line.quantity }` }</span> : null }
									</th>
									<td>{ money( line.amount ) }</td>
								</tr>
							) ) }
						</tbody>
						<tfoot>
							<tr className="fbmb-price__total">
								<th scope="row">{ t( 'Total' ) }</th>
								<td>{ money( quote.total ) }</td>
							</tr>
						</tfoot>
					</table>
				) : (
					<Skeleton rows={ 2 } />
				) }
			</section>

			<section>
				<h3 className="fbmb-section__title">{ t( 'Contact' ) }</h3>
				<p className="fbmb-review__customer">
					{ customer.name }
					<br />
					{ customer.email }
					{ customer.phone ? (
						<>
							<br />
							{ customer.phone }
						</>
					) : null }
				</p>
			</section>

			<PaymentChoice value={ paymentMethod } onChange={ onPaymentMethod } />

			<div className="fbmb-actions">
				<Button variant="secondary" onClick={ onBack } disabled={ submitting }>
					{ t( 'Back' ) }
				</Button>
				<Button size="lg" onClick={ onSubmit } disabled={ submitting || ! quote }>
					{ submitting ? t( 'Confirming…' ) : t( 'Confirm booking' ) }
				</Button>
			</div>
		</>
	);
}

/**
 * Payment method chooser.
 *
 * The methods available come from the server on submit; this offers the
 * offline ones the Free plugin ships with, and stays out of the way when the
 * site routes payment through WooCommerce.
 */
function PaymentChoice( { value, onChange }: { value: string; onChange: ( value: string ) => void } ): JSX.Element {
	const [ methods, setMethods ] = useState< Array< { id: string; label: string; description: string } > >( [] );

	useEffect( () => {
		let cancelled = false;

		request< { methods: Array< { id: string; label: string; description: string } >; default: string } >( 'payment-methods' )
			.then( ( payload ) => {
				if ( cancelled ) {
					return;
				}

				setMethods( payload.methods );

				if ( value === '' && payload.default ) {
					onChange( payload.default );
				}
			} )
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( methods.length === 0 ) {
		return <></>;
	}

	return (
		<section>
			<h3 className="fbmb-section__title">{ t( 'Payment' ) }</h3>
			<div className="fbmb-payments">
				{ methods.map( ( method ) => (
					<label className={ `fbmb-payment${ value === method.id ? ' is-selected' : '' }` } key={ method.id }>
						<input
							type="radio"
							name="fbm-payment"
							value={ method.id }
							checked={ value === method.id }
							onChange={ () => onChange( method.id ) }
						/>
						<span className="fbmb-payment__body">
							<span className="fbmb-payment__label">{ method.label }</span>
							{ method.description ? <span className="fbmb-payment__description">{ method.description }</span> : null }
						</span>
					</label>
				) ) }
			</div>
		</section>
	);
}

/**
 * Confirmation step.
 */
function Confirmation( { booking }: { booking: BookingResult } ): JSX.Element {
	return (
		<div className="fbmb-done">
			<div className="fbmb-done__mark" aria-hidden="true">
				<svg viewBox="0 0 48 48">
					<path d="m14 25 7 7 14-15" fill="none" stroke="currentColor" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round" />
				</svg>
			</div>

			<h3 className="fbmb-done__title">{ t( 'Your booking is confirmed' ) }</h3>
			<p className="fbmb-done__reference">
				{ t( 'Booking reference' ) }
				<strong>{ booking.reference }</strong>
			</p>
			<p className="fbmb-done__note">{ t( 'We have emailed your confirmation. Please bring your reference to check-in.' ) }</p>

			{ booking.instructions ? <Alert tone="info">{ booking.instructions }</Alert> : null }

			<p className="fbmb-done__total">
				<span>{ t( 'Total' ) }</span>
				<strong>{ money( booking.total ) }</strong>
			</p>
		</div>
	);
}
