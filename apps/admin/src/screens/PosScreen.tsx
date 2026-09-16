/**
 * Counter terminal.
 *
 * Built for someone standing up, with a queue behind the customer. Everything
 * is on one screen in the order the conversation happens — where are you going,
 * which crossing, who is travelling, how are you paying — and the total is
 * quoted by the server before the sale, so the price on the screen is the price
 * that gets charged.
 *
 * Targets are large and the flow never leaves the page, because a counter that
 * needs a scroll and a page load between choosing a fare and taking the money
 * is slower than the paper it replaced.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { NumberField, SelectField, TextField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { FbmApiError, fbmRequest, fbmRestUrl } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';

interface TerminalSailing {
	id: number;
	time: string;
	route_id: number;
	route: string;
	vessel: string;
	status: string;
	bookable: boolean;
	reason: string;
	seats: number;
	vehicles: number;
	departed: boolean;
}

interface TypeOption {
	id: number;
	name: string;
	code?: string;
	from: number;
	from_display: string;
	price_mode?: string;
	occupies_seat?: boolean;
	lane_metres?: number;
}

interface PaymentOption {
	id: string;
	label: string;
	description: string;
	instant: boolean;
	tendered: boolean;
}

interface Terminal {
	date: string;
	today: string;
	route_id: number;
	routes: Array< { id: number; name: string; origin: string; destination: string } >;
	sailings: TerminalSailing[];
	passenger_types: TypeOption[];
	vehicle_types: TypeOption[];
	payment_methods: PaymentOption[];
	vehicles_enabled: boolean;
	max_passengers: number;
	max_vehicles: number;
	receipt_widths: Record< string, { label: string; mm: number } >;
}

interface Quote {
	total: number;
	subtotal: number;
	tax: number;
	discount: number;
	currency: string;
	lines?: Array< { label: string; amount: number } >;
}

interface Sale {
	id: number;
	reference: string;
	total_display: string;
	tendered_display: string;
	change_display: string;
	balance_display: string;
	route: string;
	departure: string;
	passengers: number;
	vehicles: number;
}

/**
 * Renders the counter terminal.
 */
export function PosScreen(): JSX.Element {
	const config = fbmConfig();
	const toast = useFbmToast();

	const [ terminal, setTerminal ] = useState< Terminal | null >( null );
	const [ date, setDate ] = useState( '' );
	const [ routeId, setRouteId ] = useState( 0 );
	const [ sailingId, setSailingId ] = useState( 0 );
	const [ passengers, setPassengers ] = useState< Record< number, number > >( {} );
	const [ vehicles, setVehicles ] = useState< Record< number, number > >( {} );
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const [ method, setMethod ] = useState( '' );
	const [ tendered, setTendered ] = useState( '' );
	const [ till, setTill ] = useState( '' );
	const [ quote, setQuote ] = useState< Quote | null >( null );
	const [ quoting, setQuoting ] = useState( false );
	const [ selling, setSelling ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ problem, setProblem ] = useState( '' );
	const [ sale, setSale ] = useState< Sale | null >( null );
	const [ width, setWidth ] = useState( '80' );

	const load = useCallback( () => {
		if ( ! config.proActive ) {
			setLoading( false );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );
		setError( '' );

		fbmRequest< Terminal >( 'pos/terminal', { query: { date, route_id: routeId } } )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setTerminal( response.data );

				const first = response.data.payment_methods[ 0 ];

				if ( method === '' && first ) {
					setMethod( first.id );
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
	}, [ config.proActive, date, routeId, method ] );

	useEffect( () => load(), [ load ] );

	const party = useMemo( () => {
		const rows: Array< { type_id: number; qty: number } > = [];

		Object.entries( passengers ).forEach( ( [ id, qty ] ) => {
			if ( qty > 0 ) {
				rows.push( { type_id: Number( id ), qty } );
			}
		} );

		return rows;
	}, [ passengers ] );

	const vehicleRows = useMemo( () => {
		const rows: Array< { type_id: number; qty: number } > = [];

		Object.entries( vehicles ).forEach( ( [ id, qty ] ) => {
			if ( qty > 0 ) {
				rows.push( { type_id: Number( id ), qty } );
			}
		} );

		return rows;
	}, [ vehicles ] );

	const passengerCount = party.reduce( ( total, row ) => total + row.qty, 0 );
	const vehicleCount = vehicleRows.reduce( ( total, row ) => total + row.qty, 0 );

	// The server prices the sale. Anything the counter worked out itself would
	// be a second pricing engine, and the one at the till is the one a customer
	// argues with.
	useEffect( () => {
		if ( sailingId === 0 || passengerCount === 0 ) {
			setQuote( null );
			return undefined;
		}

		let cancelled = false;
		setQuoting( true );

		const timer = window.setTimeout( () => {
			// The pricing endpoint counts a party; the booking endpoint records
			// each traveller. Same sale, two shapes, and sending the wrong one
			// prices it at nothing.
			fbmRequest< Quote >( 'quote', {
				method: 'POST',
				body: {
					sailing_id: sailingId,
					passengers: quantities( party ),
					vehicles: quantities( vehicleRows ),
				},
			} )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setQuote( response.data );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setQuote( null );
					}
				} )
				.finally( () => {
					if ( ! cancelled ) {
						setQuoting( false );
					}
				} );
		}, 250 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ sailingId, party, vehicleRows, passengerCount ] );

	const reset = useCallback( () => {
		setPassengers( {} );
		setVehicles( {} );
		setName( '' );
		setEmail( '' );
		setPhone( '' );
		setTendered( '' );
		setQuote( null );
		setProblem( '' );
	}, [] );

	const sell = useCallback( async () => {
		setSelling( true );
		setProblem( '' );

		try {
			const response = await fbmRequest< { booking: Sale } >( 'pos/sell', {
				method: 'POST',
				body: {
					sailing_id: sailingId,
					customer: { name, email, phone },
					passengers: expand( party ),
					vehicles: expand( vehicleRows ),
					payment_method: method,
					payment_status: 'paid',
					tendered,
					till,
				},
			} );

			setSale( response.data.booking );
			reset();
			toast.notify( fbmFormat( 'Sold. Reference %s.', response.data.booking.reference ), 'success' );
			load();
		} catch ( caught: unknown ) {
			const message = caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' );
			setProblem( message );
		} finally {
			setSelling( false );
		}
	}, [ sailingId, name, email, phone, party, vehicleRows, method, tendered, till, reset, toast, load ] );

	if ( ! config.proActive ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Counter' ) } badge={ <span className="fbm-badge fbm-badge--pro">PRO</span> } />
				<EmptyState
					icon="card"
					title={ fbmText( 'Available in MagePeople Ferry Booking System Pro.' ) }
					description={ fbmText( 'A fast till for selling at the quayside, taking cash or card and printing a ticket on a receipt printer.' ) }
				/>
			</>
		);
	}

	if ( loading && ! terminal ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Counter' ) } />
				<LoadingState rows={ 6 } />
			</>
		);
	}

	if ( error !== '' ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Counter' ) } />
				<ErrorState message={ error } onRetry={ load } />
			</>
		);
	}

	const chosenMethod = terminal?.payment_methods.find( ( row ) => row.id === method );
	const tenderedMinor = Math.round( Number( tendered.replace( ',', '.' ) ) * 100 );
	const change = quote && tenderedMinor > 0 ? tenderedMinor - quote.total : 0;
	const ready = sailingId > 0 && passengerCount > 0 && name.trim() !== '' && email.trim() !== '' && method !== '';

	return (
		<>
			<PageHeader
				title={ fbmText( 'Counter' ) }
				description={ fbmText( 'Sell a crossing at the quayside.' ) }
			/>

			{ sale ? (
				<div className="fbm-panel fbm-pos__done">
					<div>
						<h2 className="fbm-panel__title">{ fbmFormat( 'Sold — %s', sale.reference ) }</h2>
						<p className="fbm-panel__description">
							{ [ sale.route, sale.departure ].filter( Boolean ).join( ' · ' ) }
						</p>
						<p className="fbm-pos__change">
							{ sale.change_display !== '' && change >= 0
								? fbmFormat( 'Total %1$s · Tendered %2$s · Change %3$s', sale.total_display, sale.tendered_display, sale.change_display )
								: fbmFormat( 'Total %s', sale.total_display ) }
						</p>
					</div>
					<div className="fbm-pos__doneactions">
						<SelectField
							label={ fbmText( 'Paper' ) }
							name="receipt_width"
							value={ width }
							options={ Object.entries( terminal?.receipt_widths ?? {} ).map( ( [ value, row ] ) => ( { value, label: row.label } ) ) }
							onChange={ setWidth }
						/>
						<a
							className="fbm-button fbm-button--primary"
							href={ fbmRestUrl( `pos/receipt/${ sale.id }`, { width, _wpnonce: config.restNonce } ) }
							target="_blank"
							rel="noreferrer"
						>
							{ fbmText( 'Print the ticket' ) }
						</a>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => setSale( null ) }>
							{ fbmText( 'Next customer' ) }
						</button>
					</div>
				</div>
			) : null }

			<div className="fbm-pos">
				<div className="fbm-pos__main">
					<div className="fbm-panel">
						<div className="fbm-panel__header">
							<div>
								<h2 className="fbm-panel__title">{ fbmText( '1. Crossing' ) }</h2>
								<p className="fbm-panel__description">{ fbmText( 'Pick the day and route, then the departure.' ) }</p>
							</div>
						</div>

						<div className="fbm-settings">
							<TextField
								label={ fbmText( 'Date' ) }
								name="pos_date"
								type="date"
								value={ date !== '' ? date : ( terminal?.date ?? '' ) }
								onChange={ setDate }
							/>
							<SelectField
								label={ fbmText( 'Route' ) }
								name="pos_route"
								value={ String( routeId ) }
								options={ [
									{ value: '0', label: fbmText( 'All routes' ) },
									...( terminal?.routes ?? [] ).map( ( row ) => ( { value: String( row.id ), label: row.name } ) ),
								] }
								onChange={ ( value ) => {
									setRouteId( Number( value ) );
									setSailingId( 0 );
								} }
							/>
						</div>

						{ ( terminal?.sailings ?? [] ).length === 0 ? (
							<EmptyState icon="route" title={ fbmText( 'No crossings on this day.' ) } />
						) : (
							<div className="fbm-pos__sailings">
								{ ( terminal?.sailings ?? [] ).map( ( row ) => (
									<button
										type="button"
										key={ row.id }
										disabled={ ! row.bookable }
										className={ `fbm-pos__sailing${ sailingId === row.id ? ' is-selected' : '' }` }
										onClick={ () => setSailingId( row.id ) }
									>
										<span className="fbm-pos__sailingtime">{ row.time }</span>
										<span className="fbm-pos__sailingroute">{ row.route }</span>
										<span className="fbm-pos__sailingmeta">
											{ row.bookable
												? row.seats < 0
													? fbmText( 'Seats available' )
													: fbmFormat( '%s seats left', String( row.seats ) )
												: fbmText( 'Not on sale' ) }
										</span>
									</button>
								) ) }
							</div>
						) }
					</div>

					<div className="fbm-panel">
						<div className="fbm-panel__header">
							<div>
								<h2 className="fbm-panel__title">{ fbmText( '2. Who is travelling' ) }</h2>
								<p className="fbm-panel__description">
									{ fbmFormat( 'Up to %s passengers on one booking.', String( terminal?.max_passengers ?? 0 ) ) }
								</p>
							</div>
						</div>

						<div className="fbm-pos__types">
							{ ( terminal?.passenger_types ?? [] ).map( ( type ) => (
								<div className="fbm-pos__type" key={ type.id }>
									<span className="fbm-pos__typename">
										{ type.name }
										{ type.from_display !== '' ? (
											<span className="fbm-pos__typeprice">{ fbmFormat( 'from %s', type.from_display ) }</span>
										) : null }
									</span>
									<NumberField
										label={ fbmFormat( 'How many %s', type.name ) }
										name={ `pax_${ type.id }` }
										value={ passengers[ type.id ] ?? 0 }
										min={ 0 }
										max={ terminal?.max_passengers ?? undefined }
										onChange={ ( value ) => setPassengers( ( current ) => ( { ...current, [ type.id ]: Math.max( 0, value ) } ) ) }
									/>
								</div>
							) ) }
						</div>

						{ terminal?.vehicles_enabled && ( terminal?.vehicle_types ?? [] ).length > 0 ? (
							<>
								<h3 className="fbm-subheading">{ fbmText( 'Vehicles' ) }</h3>
								<div className="fbm-pos__types">
									{ ( terminal?.vehicle_types ?? [] ).map( ( type ) => (
										<div className="fbm-pos__type" key={ type.id }>
											<span className="fbm-pos__typename">
												{ type.name }
												{ type.from_display !== '' ? (
											<span className="fbm-pos__typeprice">{ fbmFormat( 'from %s', type.from_display ) }</span>
										) : null }
											</span>
											<NumberField
												label={ fbmFormat( 'How many %s', type.name ) }
												name={ `veh_${ type.id }` }
												value={ vehicles[ type.id ] ?? 0 }
												min={ 0 }
												max={ terminal?.max_vehicles ?? undefined }
												onChange={ ( value ) => setVehicles( ( current ) => ( { ...current, [ type.id ]: Math.max( 0, value ) } ) ) }
											/>
										</div>
									) ) }
								</div>
							</>
						) : null }
					</div>

					<div className="fbm-panel">
						<div className="fbm-panel__header">
							<div>
								<h2 className="fbm-panel__title">{ fbmText( '3. Customer' ) }</h2>
								<p className="fbm-panel__description">
									{ fbmText( 'The ticket and the confirmation go to this address.' ) }
								</p>
							</div>
						</div>

						<div className="fbm-settings">
							<TextField label={ fbmText( 'Full name' ) } name="pos_name" value={ name } onChange={ setName } required />
							<TextField label={ fbmText( 'Email' ) } name="pos_email" type="email" value={ email } onChange={ setEmail } required />
							<TextField label={ fbmText( 'Telephone' ) } name="pos_phone" type="tel" value={ phone } onChange={ setPhone } />
						</div>
					</div>
				</div>

				<aside className="fbm-pos__side">
					<div className="fbm-panel fbm-pos__till">
						<h2 className="fbm-panel__title">{ fbmText( '4. Take the money' ) }</h2>

						<div className="fbm-pos__total">
							<span className="fbm-pos__totallabel">{ fbmText( 'Total' ) }</span>
							<span className="fbm-pos__totalvalue">
								{ quoting ? '…' : quote ? fbmFormatMoney( quote.total ) : fbmFormatMoney( 0 ) }
							</span>
							<span className="fbm-pos__totalmeta">
								{ fbmFormat( '%1$s passengers · %2$s vehicles', String( passengerCount ), String( vehicleCount ) ) }
							</span>
						</div>

						<div className="fbm-settings">
							<SelectField
								label={ fbmText( 'Payment' ) }
								name="pos_method"
								value={ method }
								options={ ( terminal?.payment_methods ?? [] ).map( ( row ) => ( { value: row.id, label: row.label } ) ) }
								onChange={ setMethod }
								hint={ chosenMethod?.description ?? '' }
							/>

							{ chosenMethod?.tendered ? (
								<TextField
									label={ fbmText( 'Cash received' ) }
									name="pos_tendered"
									value={ tendered }
									onChange={ setTendered }
									placeholder="0.00"
									hint={
										quote && tenderedMinor > 0
											? change >= 0
												? fbmFormat( 'Change %s', fbmFormatMoney( change ) )
												: fbmFormat( 'Still %s short', fbmFormatMoney( Math.abs( change ) ) )
											: fbmText( 'Enter what the customer handed over and the change is worked out.' )
									}
								/>
							) : null }

							<TextField
								label={ fbmText( 'Till' ) }
								name="pos_till"
								value={ till }
								onChange={ setTill }
								placeholder={ fbmText( 'Desk 1' ) }
								hint={ fbmText( 'Printed on the receipt, for reconciling a shift.' ) }
							/>
						</div>

						{ problem !== '' ? (
							<div className="fbm-alert fbm-alert--error" role="alert">
								{ problem }
							</div>
						) : null }

						<button
							type="button"
							className="fbm-button fbm-button--primary fbm-pos__sell"
							onClick={ sell }
							disabled={ ! ready || selling || quoting }
						>
							{ selling ? fbmText( 'Selling…' ) : fbmText( 'Take payment' ) }
						</button>

						<button type="button" className="fbm-button fbm-button--secondary fbm-pos__clear" onClick={ reset }>
							{ fbmText( 'Clear' ) }
						</button>
					</div>
				</aside>
			</div>
		</>
	);
}

/**
 * Reduces the chosen party to type id => quantity, which is what pricing wants.
 */
function quantities( rows: Array< { type_id: number; qty: number } > ): Record< string, number > {
	const counted: Record< string, number > = {};

	rows.forEach( ( row ) => {
		counted[ String( row.type_id ) ] = row.qty;
	} );

	return counted;
}

/**
 * Turns quantities into the per-traveller rows the booking API expects.
 */
function expand( rows: Array< { type_id: number; qty: number } > ): Array< { type_id: number; details: Record< string, string > } > {
	const expanded: Array< { type_id: number; details: Record< string, string > } > = [];

	rows.forEach( ( row ) => {
		for ( let index = 0; index < row.qty; index++ ) {
			expanded.push( { type_id: row.type_id, details: {} } );
		}
	} );

	return expanded;
}
