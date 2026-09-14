/**
 * Staff booking form.
 *
 * Built as one screen rather than a wizard. A wizard is right when the person
 * filling it in is unfamiliar and needs to be led; the person using this has a
 * customer standing in front of them and does the same thing forty times a day,
 * so everything is visible at once and the price updates as they go.
 *
 * The server prices every quote. Nothing here computes a total — the figure on
 * screen is the figure the booking will be made at, because it came from the
 * same engine that will make it.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { NumberField, SelectField, TextAreaField, TextField } from '../components/Fields';
import { FormSteps } from '../components/FormSteps';
import { PageHeader } from '../components/PageHeader';
import { QuantityGrid } from '../components/QuantityGrid';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';
import { fbmNavigate } from '../lib/router';

interface PortOption {
	id: number;
	name: string;
}

interface PartyType {
	id: number;
	name: string;
	max_per_booking: number;
	is_free?: boolean;
}

/** Guide fares for a route, as the public fares endpoint returns them. */
interface RouteFares {
	routed: boolean;
	vehicles_allowed: boolean;
	passengers: Record< string, number >;
	vehicles: Record< string, number >;
}

interface CaptureField {
	key: string;
	label: string;
	type: string;
	required: boolean;
}

interface BookingOptions {
	ports: PortOption[];
	connections: Record< string, number[] >;
	passenger_types: PartyType[];
	vehicle_types: PartyType[];
	fields: { passenger: CaptureField[]; vehicle: CaptureField[] };
	today: string;
}

/** One traveller or vehicle, with whatever the operator asks about it. */
interface PartyMember {
	type_id: number;
	type_name: string;
	details: Record< string, string >;
}

/**
 * Expands party counts into one entry per traveller, keeping what has already
 * been typed.
 *
 * Reducing "3 adults" to 2 must drop the third row, not the first two — a
 * member of staff who mistypes a count should not lose the names they just
 * entered.
 */
function expand(
	counts: Record< number, number >,
	types: PartyType[],
	current: PartyMember[]
): PartyMember[] {
	const members: PartyMember[] = [];

	types.forEach( ( type ) => {
		const wanted = counts[ type.id ] ?? 0;
		const existing = current.filter( ( member ) => member.type_id === type.id );

		for ( let index = 0; index < wanted; index += 1 ) {
			members.push(
				existing[ index ] ?? { type_id: type.id, type_name: type.name, details: {} }
			);
		}
	} );

	return members;
}

interface SailingRow {
	sailing_id: number;
	route_name: string;
	departure: string;
	arrival: string;
	vessel: { name: string };
	availability: {
		passengers: { remaining: number; unlimited: boolean };
		vehicles: { remaining: number; unlimited: boolean };
		sold_out: boolean;
	};
	fits?: boolean;
	unavailable?: string;
}

interface QuoteLine {
	label: string;
	quantity: number;
	amount: number;
}

interface Quote {
	lines: QuoteLine[];
	subtotal: number;
	discount: number;
	fees: number;
	tax: number;
	total: number;
}

interface Customer {
	id: number;
	name: string;
	email: string;
	phone: string;
}

const BOOKING_STATUSES = [
	{ value: 'pending', label: 'Awaiting payment' },
	{ value: 'on_hold', label: 'Held' },
	{ value: 'confirmed', label: 'Confirmed' },
];

const PAYMENT_STATUSES = [
	{ value: 'unpaid', label: 'Unpaid' },
	{ value: 'partially_paid', label: 'Part paid' },
	{ value: 'paid', label: 'Paid' },
];

/**
 * Renders the staff booking form.
 */
export function NewBookingScreen(): JSX.Element {
	const toast = useFbmToast();

	const [ options, setOptions ] = useState< BookingOptions | null >( null );
	const [ methods, setMethods ] = useState< Array< { id: string; label: string } > >( [] );

	const [ origin, setOrigin ] = useState( 0 );
	const [ destination, setDestination ] = useState( 0 );
	const [ date, setDate ] = useState( '' );

	const [ passengers, setPassengers ] = useState< Record< number, number > >( {} );
	const [ vehicles, setVehicles ] = useState< Record< number, number > >( {} );
	const [ travellers, setTravellers ] = useState< PartyMember[] >( [] );
	const [ vehicleDetails, setVehicleDetails ] = useState< PartyMember[] >( [] );

	const [ searching, setSearching ] = useState( false );
	const [ results, setResults ] = useState< SailingRow[] | null >( null );
	const [ sailingId, setSailingId ] = useState( 0 );

	const [ customerSearch, setCustomerSearch ] = useState( '' );
	const [ matches, setMatches ] = useState< Customer[] >( [] );
	const [ customer, setCustomer ] = useState< Customer >( { id: 0, name: '', email: '', phone: '' } );

	const [ discount, setDiscount ] = useState( 0 );
	const [ discountReason, setDiscountReason ] = useState( '' );
	const [ notes, setNotes ] = useState( '' );
	const [ method, setMethod ] = useState( '' );
	const [ bookingStatus, setBookingStatus ] = useState( 'pending' );
	const [ paymentStatus, setPaymentStatus ] = useState( 'unpaid' );
	const [ amountPaid, setAmountPaid ] = useState( 0 );

	const [ quote, setQuote ] = useState< Quote | null >( null );
	const [ fares, setFares ] = useState< RouteFares | null >( null );
	const [ quoteError, setQuoteError ] = useState( '' );
	const [ quoting, setQuoting ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ step, setStep ] = useState( 0 );
	const [ visited, setVisited ] = useState( 0 );
	const [ stepError, setStepError ] = useState( '' );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );

	// ---- boot -------------------------------------------------------------

	useEffect( () => {
		let cancelled = false;

		fbmRequest< BookingOptions >( 'booking-options' )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setOptions( response.data );
				setDate( ( current ) => ( current !== '' ? current : response.data.today ) );
			} )
			.catch( () => undefined );

		fbmRequest< { methods: Array< { id: string; label: string } >; default: string } >( 'payment-methods' )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setMethods( response.data.methods ?? [] );
				setMethod( ( current ) => ( current !== '' ? current : response.data.default ) );
			} )
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! options ) {
			return;
		}

		setTravellers( ( current ) => expand( passengers, options.passenger_types, current ) );
		setVehicleDetails( ( current ) => expand( vehicles, options.vehicle_types, current ) );
	}, [ options, passengers, vehicles ] );

	// ---- customer search --------------------------------------------------

	useEffect( () => {
		if ( customerSearch.trim().length < 2 ) {
			setMatches( [] );
			return undefined;
		}

		let cancelled = false;
		// Typing a name should not fire a request per keystroke.
		const timer = window.setTimeout( () => {
			fbmRequest< { customers: Customer[] } >( 'customers', { query: { search: customerSearch.trim() } } )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setMatches( response.data.customers ?? [] );
					}
				} )
				.catch( () => undefined );
		}, 300 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ customerSearch ] );

	// ---- price ------------------------------------------------------------

	/*
	 * Pricing counts a party rather than naming it, so it takes type to quantity.
	 * The booking itself is made from the detail rows below, one per traveller.
	 */
	const counts = useMemo(
		() => ( {
			passengers: Object.fromEntries( Object.entries( passengers ).filter( ( [ , n ] ) => n > 0 ) ),
			vehicles: Object.fromEntries( Object.entries( vehicles ).filter( ( [ , n ] ) => n > 0 ) ),
		} ),
		[ passengers, vehicles ]
	);

	/*
	 * The fare of each type on this crossing, shown against its row so staff
	 * can answer "what does a child cost" before adding one. The same endpoint
	 * the customer booking form uses, so the desk and the website quote the
	 * same unit fare. Only asked once both ports are known: with no route there
	 * is no fare, and an empty column says that better than a row of zeroes.
	 */
	useEffect( () => {
		if ( origin === 0 || destination === 0 ) {
			setFares( null );

			return undefined;
		}

		let cancelled = false;

		fbmRequest< RouteFares >( 'fares', { query: { origin, destination } } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setFares( response.data.routed ? response.data : null );
				}
			} )
			// A missing guide price costs a glance at the rail, not a booking.
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [ origin, destination ] );

	useEffect( () => {
		if (
			sailingId === 0 ||
			( Object.keys( counts.passengers ).length === 0 && Object.keys( counts.vehicles ).length === 0 )
		) {
			setQuote( null );
			setQuoteError( '' );

			return undefined;
		}

		let cancelled = false;
		setQuoting( true );
		setQuoteError( '' );

		fbmRequest< Quote >( 'quote', {
			method: 'POST',
			body: { sailing_id: sailingId, passengers: counts.passengers, vehicles: counts.vehicles },
		} )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setQuote( response.data );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( cancelled ) {
					return;
				}

				/*
				 * A refused quote always has a reason — a vehicle on a
				 * foot-passenger crossing, a party bigger than the deck, a type
				 * the route does not price — and it is the one thing the
				 * operator needs. Swallowing it left the rail saying "choose a
				 * sailing and who is travelling" to somebody who had just done
				 * both, which sends them hunting for a mistake they did not
				 * make.
				 */
				setQuote( null );

				/*
				 * The envelope message is a generic "correct the highlighted
				 * fields", and nothing here is highlighted — the reason lives
				 * in the per-field messages ("This crossing does not carry
				 * vehicles."), so those are what gets shown.
				 */
				if ( caught instanceof FbmApiError ) {
					const fields =
						caught.details.fields && typeof caught.details.fields === 'object'
							? Object.values( caught.details.fields as Record< string, string > ).filter( Boolean )
							: [];

					setQuoteError( fields.length > 0 ? fields.join( ' ' ) : caught.message );
				} else {
					setQuoteError( fbmText( 'The fare could not be worked out.' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setQuoting( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ sailingId, counts ] );

	// ---- actions ----------------------------------------------------------

	const reachable = useMemo( () => {
		if ( ! options || origin === 0 ) {
			return options?.ports ?? [];
		}

		const ids = options.connections[ String( origin ) ] ?? [];

		return options.ports.filter( ( port ) => ids.includes( port.id ) );
	}, [ options, origin ] );

	const search = useCallback( async () => {
		if ( origin === 0 || destination === 0 || date === '' ) {
			toast.notify( fbmText( 'Choose both ports and a date first.' ), 'error' );
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
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSearching( false );
		}
	}, [ origin, destination, date, toast ] );

	/*
	 * A field the server rejected is usually not on the step being looked at,
	 * so the message would arrive about something invisible. This says which
	 * step each field belongs to.
	 */
	const stepOfField = useCallback( ( field: string ): number => {
		if ( field.startsWith( 'customer' ) ) {
			return 2;
		}

		if ( field.startsWith( 'passengers' ) || field.startsWith( 'vehicles' ) ) {
			return 1;
		}

		if ( field.startsWith( 'sailing' ) ) {
			return 0;
		}

		return 3;
	}, [] );

	const confirm = useCallback( async () => {
		setErrors( {} );

		if ( sailingId === 0 ) {
			toast.notify( fbmText( 'Choose a sailing first.' ), 'error' );
			return;
		}

		setSaving( true );

		try {
			const response = await fbmRequest< { reference: string; id: number } >( 'bookings/staff', {
				method: 'POST',
				body: {
					sailing_id: sailingId,
					passengers: travellers.map( ( member ) => ( { type_id: member.type_id, details: member.details } ) ),
					vehicles: vehicleDetails.map( ( member ) => ( { type_id: member.type_id, details: member.details } ) ),
					customer: {
						name: customer.name,
						email: customer.email,
						phone: customer.phone,
						id: customer.id,
					},
					manual_discount: Math.round( discount * 100 ),
					amount_paid: Math.round( amountPaid * 100 ),
					discount_reason: discountReason,
					internal_notes: notes,
					payment_method: method,
					booking_status: bookingStatus,
					payment_status: paymentStatus,
					channel: 'backend',
					idempotency_key: `staff-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`,
				},
			} );

			toast.notify( fbmFormat( 'Booking %s created.', response.data.reference ), 'success' );
			fbmNavigate( '/bookings' );
		} catch ( caught: unknown ) {
			if ( caught instanceof FbmApiError ) {
				const named =
					caught.details.fields && typeof caught.details.fields === 'object'
						? ( caught.details.fields as Record< string, string > )
						: {};

				setErrors( named );

				/*
				 * Land on the step holding whatever the server rejected. A
				 * message about a missing passport number is no use while the
				 * payment step is on screen.
				 */
				const first = Object.keys( named )[ 0 ];

				if ( first !== undefined ) {
					const target = stepOfField( first );

					setStep( target );
					setVisited( ( seen ) => Math.max( seen, target ) );
				}

				toast.notify( caught.message, 'error' );
			} else {
				toast.notify( fbmText( 'Something went wrong.' ), 'error' );
			}
		} finally {
			setSaving( false );
		}
	}, [
		sailingId,
		travellers,
		vehicleDetails,
		customer,
		discount,
		discountReason,
		notes,
		method,
		bookingStatus,
		paymentStatus,
		amountPaid,
		toast,
		stepOfField,
	] );

	const seats = Object.values( passengers ).reduce( ( sum, n ) => sum + n, 0 );
	const units = Object.values( vehicles ).reduce( ( sum, n ) => sum + n, 0 );
	const ready = sailingId > 0 && ( seats > 0 || units > 0 ) && customer.name !== '' && customer.email !== '';

	/*
	 * Four steps rather than five stacked panels. Everything a desk booking
	 * needs is still asked, in the order it depends on itself — a fare cannot
	 * be quoted before a crossing and a party are known — but only the question
	 * being answered is on screen, so the page stops being something to scroll
	 * past to reach the button.
	 */
	const STEPS = useMemo(
		() => [
			{ id: 'crossing', title: 'Crossing' },
			{ id: 'party', title: 'Who is travelling' },
			{ id: 'customer', title: 'Customer' },
			{ id: 'payment', title: 'Payment' },
		],
		[]
	);

	/** What stops a step being left, or an empty string when nothing does. */
	const blocker = useCallback(
		( index: number ): string => {
			if ( index === 0 && sailingId === 0 ) {
				return fbmText( 'Pick the sailing they are travelling on.' );
			}

			if ( index === 1 && seats === 0 && units === 0 ) {
				return fbmText( 'Add at least one passenger or vehicle.' );
			}

			if ( index === 2 && ( customer.name.trim() === '' || customer.email.trim() === '' ) ) {
				return fbmText( 'A booking needs a name and an email to send the confirmation to.' );
			}

			return '';
		},
		[ customer.email, customer.name, sailingId, seats, units ]
	);

	const goToStep = useCallback(
		( index: number ) => {
			// Forward only past a step that is complete. Going back, or
			// returning to one already reached, is never gated.
			if ( index > step && index > visited ) {
				const stopped = blocker( step );

				if ( stopped !== '' ) {
					setStepError( stopped );

					return;
				}
			}

			setStepError( '' );
			setStep( index );
			setVisited( ( seen ) => Math.max( seen, index ) );
		},
		[ blocker, step, visited ]
	);


	/*
	 * Offering to copy the customer's name onto the first traveller saves the
	 * commonest keystrokes at a busy desk: most bookings are made by somebody
	 * who is travelling on them.
	 */
	const canCopyLead =
		travellers.length > 0 && customer.name !== '' && ( travellers[ 0 ]?.details.first_name ?? '' ) === '';

	const copyLead = useCallback( () => {
		const parts = customer.name.trim().split( /\s+/ );

		setTravellers( ( current ) =>
			current.map( ( member, index ) =>
				index === 0
					? {
							...member,
							details: {
								...member.details,
								first_name: parts[ 0 ] ?? '',
								last_name: parts.slice( 1 ).join( ' ' ),
							},
					  }
					: member
			)
		);
	}, [ customer.name ] );

	if ( ! options ) {
		return (
			<>
				<PageHeader title={ fbmText( 'New booking' ) } />
				<div className="fbm-panel">
					<div className="fbm-settings" aria-hidden="true">
						<span className="fbm-skeleton fbm-skeleton--text" />
						<span className="fbm-skeleton fbm-skeleton--text" />
					</div>
				</div>
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ fbmText( 'New booking' ) }
				description={ fbmText( 'Take a booking at the desk or over the phone.' ) }
				actions={
					<button type="button" className="fbm-button" onClick={ () => fbmNavigate( '/bookings' ) }>
						{ fbmText( 'Back to bookings' ) }
					</button>
				}
			/>

			<div className="fbm-booking-form fbm-booking-form--stepped">
				<div className="fbm-booking-form__main">
					<section className="fbm-panel fbm-wizard">
						<FormSteps
							steps={ STEPS }
							current={ step }
							reachable={ ( index ) => index <= Math.max( visited, step ) }
							invalid={ ( index ) => Object.keys( errors ).some( ( field ) => stepOfField( field ) === index ) }
							onSelect={ goToStep }
						/>

						{ /*
						  * Shown only while what it complains about is still
						  * true. Left as plain state it stayed on screen after
						  * the operator had fixed it, which teaches people to
						  * ignore the errors on this form.
						  */ }
						{ stepError !== '' && blocker( step ) !== '' ? (
							<div className="fbm-alert fbm-alert--error" role="alert">
								{ blocker( step ) }
							</div>
						) : null }

						{ step === 0 ? (
							<>
								<p className="fbm-wizard__note">
									{ fbmText( 'Where they are going and when. Pick the departure they are actually travelling on — the fare and the deck space both come from it.' ) }
								</p>
						<div className="fbm-settings">
							<SelectField
								label={ fbmText( 'From' ) }
								name="origin"
								value={ String( origin ) }
								options={ [
									{ value: '0', label: fbmText( 'Choose a port' ) },
									...options.ports.map( ( port ) => ( { value: String( port.id ), label: port.name } ) ),
								] }
								onChange={ ( value ) => {
									setOrigin( Number( value ) );
									setDestination( 0 );
								} }
							/>
							<SelectField
								label={ fbmText( 'To' ) }
								name="destination"
								value={ String( destination ) }
								options={ [
									{ value: '0', label: fbmText( 'Choose a port' ) },
									...reachable
										.filter( ( port ) => port.id !== origin )
										.map( ( port ) => ( { value: String( port.id ), label: port.name } ) ),
								] }
								onChange={ ( value ) => setDestination( Number( value ) ) }
							/>
							<TextField
								label={ fbmText( 'Date' ) }
								name="date"
								type="date"
								value={ date }
								onChange={ setDate }
							/>
							<div className="fbm-settings__wide">
								<button
									type="button"
									className="fbm-button fbm-button--primary"
									onClick={ search }
									disabled={ searching }
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
								{ results.map( ( row ) => {
									const full = row.availability.sold_out;

									return (
										<li key={ row.sailing_id }>
											<label className={ `fbm-sailinglist__row${ sailingId === row.sailing_id ? ' is-selected' : '' }` }>
												<input
													type="radio"
													name="fbm-sailing"
													checked={ sailingId === row.sailing_id }
													disabled={ full }
													onChange={ () => setSailingId( row.sailing_id ) }
												/>
												<span className="fbm-sailinglist__time">{ row.departure.slice( 11, 16 ) }</span>
												<span className="fbm-sailinglist__route">{ row.route_name }</span>
												<span className="fbm-sailinglist__vessel">{ row.vessel.name }</span>
												<span className="fbm-sailinglist__seats">
													{ full
														? fbmText( 'Sold out' )
														: fbmFormat(
															'%s seats left',
															String( row.availability.passengers.remaining )
														) }
												</span>
											</label>
										</li>
									);
								} ) }
							</ul>
						) : null }
							</>
						) : null }

						{ step === 1 ? (
							<>
								<p className="fbm-wizard__note">
									{ fbmText( 'How many of each, then a name for every one of them. A manifest is a named list, so the details below are what makes it one.' ) }
								</p>

								<div className="fbm-qty-groups">
									<QuantityGrid
										title={ fbmText( 'Passengers' ) }
										types={ options.passenger_types }
										values={ passengers }
										onChange={ setPassengers }
										fares={ fares?.passengers }
									/>
									<QuantityGrid
										title={ fbmText( 'Vehicles' ) }
										types={ options.vehicle_types }
										values={ vehicles }
										onChange={ setVehicles }
										empty={ fbmText( 'No vehicle types are set up.' ) }
										fares={ fares && fares.vehicles_allowed ? fares.vehicles : undefined }
									/>
								</div>

								{ travellers.length > 0 || vehicleDetails.length > 0 ? (
									<>
										<div className="fbm-wizard__subhead">
											<h3 className="fbm-subheading">{ fbmText( 'Traveller details' ) }</h3>
								{ canCopyLead ? (
									<button type="button" className="fbm-button" onClick={ copyLead }>
										{ fbmText( 'Use the customer name' ) }
									</button>
								) : null }
										</div>
							{ travellers.map( ( member, index ) => (
								<div className="fbm-party-row" key={ `t-${ index }` }>
									<p className="fbm-party-row__title">
										{ fbmFormat( '%1$s %2$s', member.type_name, String( index + 1 ) ) }
									</p>
									<div className="fbm-settings">
										{ options.fields.passenger.map( ( field ) => (
											<TextField
												key={ field.key }
												label={ field.label }
												name={ `passenger_${ index }_${ field.key }` }
												type={ field.type === 'date' ? 'date' : 'text' }
												required={ field.required }
												value={ member.details[ field.key ] ?? '' }
												error={ errors[ `passengers.${ index }.${ field.key }` ] }
												onChange={ ( value ) =>
													setTravellers( ( current ) =>
														current.map( ( entry, position ) =>
															position === index
																? { ...entry, details: { ...entry.details, [ field.key ]: value } }
																: entry
														)
													)
												}
											/>
										) ) }
									</div>
								</div>
							) ) }

							{ vehicleDetails.map( ( member, index ) => (
								<div className="fbm-party-row" key={ `v-${ index }` }>
									<p className="fbm-party-row__title">
										{ fbmFormat( '%1$s %2$s', member.type_name, String( index + 1 ) ) }
									</p>
									<div className="fbm-settings">
										{ options.fields.vehicle.map( ( field ) => (
											<TextField
												key={ field.key }
												label={ field.label }
												name={ `vehicle_${ index }_${ field.key }` }
												type={ field.type === 'date' ? 'date' : 'text' }
												required={ field.required }
												value={ member.details[ field.key ] ?? '' }
												error={ errors[ `vehicles.${ index }.${ field.key }` ] }
												onChange={ ( value ) =>
													setVehicleDetails( ( current ) =>
														current.map( ( entry, position ) =>
															position === index
																? { ...entry, details: { ...entry.details, [ field.key ]: value } }
																: entry
														)
													)
												}
											/>
										) ) }
									</div>
								</div>
							) ) }
									</>
								) : null }
							</>
						) : null }

						{ step === 2 ? (
							<>
								<p className="fbm-wizard__note">
									{ fbmText( 'Search for a returning customer, or type the details of a new one. The confirmation goes to this address.' ) }
								</p>
						<div className="fbm-settings">
							<TextField
								label={ fbmText( 'Search customers' ) }
								name="customer_search"
								value={ customerSearch }
								placeholder={ fbmText( 'Name or email' ) }
								onChange={ setCustomerSearch }
							/>

							{ matches.length > 0 ? (
								<ul className="fbm-matchlist fbm-settings__wide">
									{ matches.map( ( match ) => (
										<li key={ match.id }>
											<button
												type="button"
												className="fbm-matchlist__row"
												onClick={ () => {
													setCustomer( match );
													setMatches( [] );
													setCustomerSearch( '' );
												} }
											>
												<span className="fbm-matchlist__name">{ match.name }</span>
												<span className="fbm-matchlist__email">{ match.email }</span>
											</button>
										</li>
									) ) }
								</ul>
							) : null }

							<TextField
								label={ fbmText( 'Full name' ) }
								name="customer_name"
								value={ customer.name }
								error={ errors.customer_name }
								onChange={ ( value ) => setCustomer( { ...customer, name: value } ) }
							/>
							<TextField
								label={ fbmText( 'Email' ) }
								name="customer_email"
								type="email"
								value={ customer.email }
								error={ errors.customer_email }
								onChange={ ( value ) => setCustomer( { ...customer, email: value } ) }
							/>
							<TextField
								label={ fbmText( 'Phone' ) }
								name="customer_phone"
								type="tel"
								value={ customer.phone }
								error={ errors.customer_phone }
								onChange={ ( value ) => setCustomer( { ...customer, phone: value } ) }
							/>
						</div>
							</>
						) : null }

						{ step === 3 ? (
							<>
								<p className="fbm-wizard__note">
									{ fbmText( 'How it was paid for, and anything staff need to record against it.' ) }
								</p>
						<div className="fbm-settings">
							<SelectField
								label={ fbmText( 'Payment method' ) }
								name="payment_method"
								value={ method }
								options={ methods.map( ( entry ) => ( { value: entry.id, label: entry.label } ) ) }
								onChange={ setMethod }
							/>
							<SelectField
								label={ fbmText( 'Booking status' ) }
								name="booking_status"
								value={ bookingStatus }
								options={ BOOKING_STATUSES.map( ( entry ) => ( {
									value: entry.value,
									label: fbmText( entry.label ),
								} ) ) }
								onChange={ setBookingStatus }
							/>
							<SelectField
								label={ fbmText( 'Payment status' ) }
								name="payment_status"
								value={ paymentStatus }
								options={ PAYMENT_STATUSES.map( ( entry ) => ( {
									value: entry.value,
									label: fbmText( entry.label ),
								} ) ) }
								onChange={ setPaymentStatus }
							/>
							{ paymentStatus === 'partially_paid' ? (
								<NumberField
									label={ fbmText( 'Amount taken' ) }
									name="amount_paid"
									value={ amountPaid }
									min={ 0 }
									step={ 0.01 }
									hint={ fbmText( 'What the customer has handed over so far.' ) }
									onChange={ setAmountPaid }
								/>
							) : null }
							<NumberField
								label={ fbmText( 'Discount' ) }
								name="manual_discount"
								value={ discount }
								min={ 0 }
								step={ 0.01 }
								error={ errors.manual_discount }
								hint={ fbmText( 'Taken off the fare. Recorded against your account.' ) }
								onChange={ setDiscount }
							/>
							<TextField
								label={ fbmText( 'Reason for the discount' ) }
								name="discount_reason"
								value={ discountReason }
								onChange={ setDiscountReason }
							/>
							<div className="fbm-settings__wide">
								<TextAreaField
									label={ fbmText( 'Internal note' ) }
									name="internal_notes"
									value={ notes }
									hint={ fbmText( 'Staff only. The customer never sees this.' ) }
									onChange={ setNotes }
								/>
							</div>
						</div>
							</>
						) : null }

						<div className="fbm-wizard__footer">
							{ step > 0 ? (
								<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => goToStep( step - 1 ) } disabled={ saving }>
									{ fbmText( 'Back' ) }
								</button>
							) : (
								<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => fbmNavigate( '/bookings' ) } disabled={ saving }>
									{ fbmText( 'Cancel' ) }
								</button>
							) }

							<div className="fbm-wizard__footer-end">
								{ step < STEPS.length - 1 ? (
									<button type="button" className="fbm-button fbm-button--primary" onClick={ () => goToStep( step + 1 ) } disabled={ saving }>
										{ fbmText( 'Next' ) }
									</button>
								) : (
									<button
										type="button"
										className="fbm-button fbm-button--primary"
										onClick={ confirm }
										disabled={ ! ready || saving }
									>
										{ saving ? fbmText( 'Creating…' ) : fbmText( 'Create booking' ) }
									</button>
								) }
							</div>
						</div>
					</section>
				</div>

				<aside className="fbm-booking-form__side">
					<div className="fbm-panel fbm-quote">
						<div className="fbm-panel__header">
							<h2 className="fbm-panel__title">{ fbmText( 'Price' ) }</h2>
							{ quote ? <p className="fbm-quote__headline">{ fbmFormatMoney( quote.total ) }</p> : null }
						</div>

						{ quote ? (
							<>
								<ul className="fbm-quote__lines">
									{ quote.lines.map( ( line, index ) => (
										<li key={ `${ line.label }-${ index }` }>
											<span>
												{ line.label }
												{ line.quantity > 1 ? ` × ${ line.quantity }` : '' }
											</span>
											<span>{ fbmFormatMoney( line.amount ) }</span>
										</li>
									) ) }
								</ul>
								<p className="fbm-quote__total">
									<span>{ fbmText( 'Total' ) }</span>
									<span>{ fbmFormatMoney( quote.total ) }</span>
								</p>
								{ discount > 0 ? (
									<p className="fbm-quote__note">
										{ fbmFormat( 'Less %s discount at confirmation.', fbmFormatMoney( Math.round( discount * 100 ) ) ) }
									</p>
								) : null }
							</>
						) : quoting ? (
							<p className="fbm-quote__empty">{ fbmText( 'Working out the fare…' ) }</p>
						) : quoteError !== '' ? (
							<div className="fbm-alert fbm-alert--error" role="alert">
								{ quoteError }
							</div>
						) : (
							<p className="fbm-quote__empty">
								{ fbmText( 'Choose a sailing and who is travelling to see the fare.' ) }
							</p>
						) }

						{ ! ready ? (
							<p className="fbm-quote__note">
								{ fbmText( 'Pick a sailing, add at least one traveller, and give a name and email.' ) }
							</p>
						) : null }
					</div>
				</aside>
			</div>
		</>
	);
}
