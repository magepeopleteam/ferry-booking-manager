/**
 * Search form.
 *
 * The first thing a customer touches, so it does the least it can get away
 * with: two ports, a date, optionally a return, and how many of what. Fares are
 * not shown here because they depend on the sailing, and a price that changes
 * on the next screen costs more trust than it saves time.
 */

import { useEffect, useMemo, useRef, useState, type JSX } from 'preact/compat';

import { request } from '../lib/api';
import { config } from '../lib/config';
import { addDays, today } from '../lib/datetime';
import { t } from '../lib/i18n';
import { money } from '../lib/money';
import type { BookingOptions, Fares } from '../lib/types';
import { Button, Field, Input, Select, Stepper } from './ui';

export interface SearchCriteria {
	origin: number;
	destination: number;
	date: string;
	returnDate: string;
	passengers: Record< number, number >;
	vehicles: Record< number, number >;
}

export interface SearchFormProps {
	options: BookingOptions;
	initial: SearchCriteria;
	busy: boolean;
	onSearch: ( criteria: SearchCriteria ) => void;
	layout?: 'inline' | 'stacked';
}

/**
 * Sums a party map.
 */
function total( party: Record< number, number > ): number {
	return Object.values( party ).reduce( ( sum, value ) => sum + value, 0 );
}

/**
 * Renders the search form.
 */
export function SearchForm( { options, initial, busy, onSearch, layout = 'inline' }: SearchFormProps ): JSX.Element {
	const cfg = config();
	const [ origin, setOrigin ] = useState( initial.origin );
	const [ destination, setDestination ] = useState( initial.destination );
	const [ date, setDate ] = useState( initial.date || options.today || today() );
	const [ returnDate, setReturnDate ] = useState( initial.returnDate );
	const [ wantsReturn, setWantsReturn ] = useState( initial.returnDate !== '' );
	const [ passengers, setPassengers ] = useState< Record< number, number > >( initial.passengers );
	const [ vehicles, setVehicles ] = useState< Record< number, number > >( initial.vehicles );
	const [ partyOpen, setPartyOpen ] = useState( false );
	const [ fares, setFares ] = useState< Fares | null >( null );
	const [ error, setError ] = useState( '' );
	const [ partyError, setPartyError ] = useState( false );
	const partyRef = useRef< HTMLDivElement | null >( null );
	const triggerRef = useRef< HTMLButtonElement | null >( null );

	/*
	 * A panel this size has to behave like a popover or it feels stuck: a click
	 * anywhere else closes it, Escape closes it, and focus goes back to the
	 * control that opened it so keyboard users are not dropped at the top of
	 * the page. The listener is bound on click rather than mousedown so the
	 * very click that opened the panel cannot immediately close it again.
	 */
	useEffect( () => {
		if ( ! partyOpen ) {
			return;
		}

		const onDocumentClick = ( event: MouseEvent ): void => {
			const target = event.target as Node | null;

			if ( ! target ) {
				return;
			}

			if ( partyRef.current?.contains( target ) || triggerRef.current?.contains( target ) ) {
				return;
			}

			setPartyOpen( false );
		};

		const onKeyDown = ( event: KeyboardEvent ): void => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				setPartyOpen( false );
				triggerRef.current?.focus();
			}
		};

		document.addEventListener( 'click', onDocumentClick );
		document.addEventListener( 'keydown', onKeyDown );

		return () => {
			document.removeEventListener( 'click', onDocumentClick );
			document.removeEventListener( 'keydown', onKeyDown );
		};
	}, [ partyOpen ] );

	/*
	 * The defaults arrive after this form is on screen: the passenger types come
	 * from the server, so the parent cannot know which type to put a 1 against
	 * until the options request lands. Without this the party the customer sees
	 * ("1 passenger") and the party the form holds ({}) disagree, and a search
	 * made in that window prices nothing at all — the fare column reads "Add a
	 * passenger to see fares" for a form that plainly shows one. Adopted only
	 * while the party is still empty, so it can never overwrite a real choice.
	 */
	useEffect( () => {
		if ( Object.keys( initial.passengers ).length === 0 ) {
			return;
		}

		setPassengers( ( current ) => ( Object.keys( current ).length === 0 ? initial.passengers : current ) );
	}, [ initial.passengers ] );

	/*
	 * What each type costs on the chosen crossing, so the party panel can put a
	 * figure against every row instead of asking the customer to add people and
	 * find out on the next screen. Only fetched once both ports are known: a
	 * fare with no route behind it is not a cheaper fare, it is no fare at all.
	 */
	useEffect( () => {
		if ( ! origin || ! destination ) {
			setFares( null );

			return;
		}

		const controller = new AbortController();

		request< Fares >( 'fares', {
			query: { origin, destination },
			signal: controller.signal,
		} )
			.then( setFares )
			// A missing guide price costs the customer a click, so a failure
			// here is silent: the panel simply goes back to showing names.
			.catch( () => undefined );

		return () => controller.abort();
	}, [ destination, origin ] );

	const portOptions = useMemo(
		() => options.ports.map( ( port ) => ( { value: port.id, label: port.code ? `${ port.name } (${ port.code })` : port.name } ) ),
		[ options.ports ]
	);

	/*
	 * Only destinations actually reachable from the chosen origin are offered.
	 * A customer who picks a pair with no route and is then told "no sailings
	 * found" cannot tell whether the crossing is sold out or does not exist.
	 */
	const destinationOptions = useMemo( () => {
		const reachable = options.connections[ String( origin ) ];

		if ( ! origin || ! reachable || reachable.length === 0 ) {
			return portOptions.filter( ( option ) => option.value !== origin );
		}

		return portOptions.filter( ( option ) => reachable.includes( Number( option.value ) ) );
	}, [ options.connections, origin, portOptions ] );

	const seats = total( passengers );
	const cars = total( vehicles );

	// Known only once a crossing is in view; before that every crossing is
	// still possible, so nothing is refused.
	const vehiclesRefused = fares !== null && fares.routed && ! fares.vehicles_allowed;

	/*
	 * A party carried over from a vehicle crossing has to be dropped when the
	 * customer switches to a foot-passenger one, or the form would keep sending
	 * a car the route cannot take and the search would fail with nothing on
	 * screen to explain why.
	 */
	useEffect( () => {
		if ( vehiclesRefused ) {
			setVehicles( ( current ) => ( Object.keys( current ).length === 0 ? current : {} ) );
		}
	}, [ vehiclesRefused ] );

	const partyLabel = [
		seats === 1 ? t( '1 passenger' ) : `${ seats } ${ t( 'passengers' ) }`,
		cars > 0 ? ( cars === 1 ? t( '1 vehicle' ) : `${ cars } ${ t( 'vehicles' ) }` ) : '',
	]
		.filter( Boolean )
		.join( ', ' );

	const submit = (): void => {
		if ( ! origin || ! destination ) {
			setError( t( 'Choose where you are travelling from and to.' ) );

			return;
		}

		if ( origin === destination ) {
			setError( t( 'The departure and arrival ports have to be different.' ) );

			return;
		}

		if ( ! date ) {
			setError( t( 'Choose a departure date.' ) );

			return;
		}

		if ( seats < 1 ) {
			// Marked on the field rather than at the foot of the form, and the
			// trigger takes focus: the party sits behind a popover, so a message
			// printed under the search button alone leaves the customer hunting
			// for a field that is not on screen.
			setPartyError( true );
			setError( '' );
			triggerRef.current?.focus();

			return;
		}

		setPartyError( false );
		setError( '' );
		setPartyOpen( false );

		onSearch( {
			origin,
			destination,
			date,
			returnDate: wantsReturn ? returnDate : '',
			passengers,
			vehicles,
		} );
	};

	return (
		<form
			className={ `mpfbsb-search mpfbsb-search--${ layout }` }
			onSubmit={ ( event ) => {
				event.preventDefault();
				submit();
			} }
		>
			<section className="mpfbsb-search__section" aria-label={ t( 'Journey type' ) }>
				<h3 className="mpfbsb-search__section-title">{ t( 'Journey' ) }</h3>
				<div className="mpfbsb-search__trip" role="group" aria-label={ t( 'Journey type' ) }>
					<button
						type="button"
						className={ `mpfbsb-toggle${ ! wantsReturn ? ' is-active' : '' }` }
						aria-pressed={ ! wantsReturn }
						onClick={ () => setWantsReturn( false ) }
					>
						{ t( 'One way' ) }
					</button>
					<button
						type="button"
						className={ `mpfbsb-toggle${ wantsReturn ? ' is-active' : '' }` }
						aria-pressed={ wantsReturn }
						onClick={ () => {
							setWantsReturn( true );

							if ( returnDate === '' && date !== '' ) {
								setReturnDate( addDays( date, 7 ) );
							}
						} }
					>
						{ t( 'Return journey' ) }
					</button>
				</div>
			</section>

			<div className="mpfbsb-search__body">
			<section className="mpfbsb-search__section" aria-label={ t( 'Route and date' ) }>
				<h3 className="mpfbsb-search__section-title">{ t( 'Route and date' ) }</h3>
				<div className="mpfbsb-search__grid">
					<Field label={ t( 'From' ) } htmlFor="mpfbs-origin" required>
						<Select
							id="mpfbs-origin"
							value={ origin || '' }
							options={ portOptions }
							placeholder={ t( 'Select a port' ) }
							onChange={ ( value ) => {
								const next = Number( value );
								setOrigin( next );

								if ( next === destination ) {
									setDestination( 0 );
								}
							} }
						/>
					</Field>

					<button
						type="button"
						className="mpfbsb-swap"
						aria-label={ t( 'Swap ports' ) }
						onClick={ () => {
							setOrigin( destination );
							setDestination( origin );
						} }
					>
						<svg viewBox="0 0 20 20" aria-hidden="true">
							<path d="M3 7h12l-3-3M17 13H5l3 3" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
						</svg>
					</button>

					<Field
						label={ t( 'To' ) }
						htmlFor="mpfbs-destination"
						required
						hint={
							origin && destinationOptions.length === 0
								? t( 'No crossings run from that port yet.' )
								: undefined
						}
					>
						<Select
							id="mpfbs-destination"
							value={ destination || '' }
							options={ destinationOptions }
							// With one destination there is nothing to choose
							// between, so the empty first row is dropped rather
							// than offering "none of them" as an option.
							placeholder={ t( 'Select a port' ) }
							onChange={ ( value ) => setDestination( Number( value ) ) }
							disabled={ ! origin || destinationOptions.length === 0 }
						/>
					</Field>

					<Field label={ t( 'Departure' ) } htmlFor="mpfbs-date" required>
						<Input
							id="mpfbs-date"
							type="date"
							value={ date }
							min={ options.today || today() }
							onChange={ ( value ) => {
								setDate( value );

								if ( wantsReturn && returnDate !== '' && returnDate < value ) {
									setReturnDate( value );
								}
							} }
						/>
					</Field>

					{ wantsReturn ? (
						<Field label={ t( 'Return' ) } htmlFor="mpfbs-return">
							<Input
								id="mpfbs-return"
								type="date"
								value={ returnDate }
								min={ date || options.today }
								onChange={ setReturnDate }
							/>
						</Field>
					) : null }
				</div>
			</section>

			<section className="mpfbsb-search__section" aria-label={ t( 'Travellers' ) }>
				<h3 className="mpfbsb-search__section-title">{ t( 'Travellers' ) }</h3>
				<div className="mpfbsb-search__travellers">
					<Field
						label={ t( 'Passengers' ) }
						htmlFor="mpfbs-party"
						required
						error={ partyError && seats < 1 ? t( 'Add at least one passenger.' ) : undefined }
					>
						<button
							type="button"
							id="mpfbs-party"
							ref={ triggerRef }
							className={ `mpfbsb-party-trigger${ partyOpen ? ' is-open' : '' }` }
							aria-expanded={ partyOpen }
							aria-controls="mpfbs-party-panel"
							onClick={ () => setPartyOpen( ! partyOpen ) }
						>
							<span>{ partyLabel }</span>
							<svg viewBox="0 0 20 20" aria-hidden="true">
								<path d="M5 8l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
							</svg>
						</button>
					</Field>

					<div className="mpfbsb-search__submit">
						<Button type="submit" size="lg" disabled={ busy } block>
							{ busy ? t( 'Searching…' ) : t( 'Search sailings' ) }
						</Button>
					</div>
				</div>
			</section>
			</div>

			{ partyOpen ? (
				<div className="mpfbsb-party" id="mpfbs-party-panel" ref={ partyRef }>
					<div className="mpfbsb-party__group">
						<h4 className="mpfbsb-party__heading">{ t( 'Passengers' ) }</h4>
						{ options.passenger_types.map( ( type ) => (
							<div className="mpfbsb-party__row" key={ type.id }>
								<div>
									<span className="mpfbsb-party__name">{ type.name }</span>
									<span className="mpfbsb-party__meta">{ ageBand( type.min_age, type.max_age ) }</span>
								</div>
								<FareTag fare={ fareFor( fares, 'passengers', type.id ) } free={ type.is_free } />
								<Stepper
									label={ type.name }
									value={ passengers[ type.id ] ?? 0 }
									min={ 0 }
									max={ headroom( passengers, type.id, type.max_per_booking, cfg.maxPassengers ) }
									onChange={ ( next ) => setPassengers( { ...passengers, [ type.id ]: next } ) }
								/>
							</div>
						) ) }
					</div>

					{ options.vehicle_types.length > 0 && cfg.vehiclesEnabled ? (
						<div className={ `mpfbsb-party__group${ vehiclesRefused ? ' is-unavailable' : '' }` }>
							<h4 className="mpfbsb-party__heading">{ t( 'Vehicles' ) }</h4>
							{ /*
							 * Said once, at the top, rather than left for the
							 * customer to infer from a column of blank fares:
							 * a party built here would only be refused at the
							 * search, two clicks later.
							 */ }
							{ vehiclesRefused ? (
								<p className="mpfbsb-party__note">{ t( 'This crossing carries foot passengers only.' ) }</p>
							) : null }
							{ options.vehicle_types.map( ( type ) => (
								<div className="mpfbsb-party__row" key={ type.id }>
									<div>
										<span className="mpfbsb-party__name">{ type.name }</span>
										{ type.length > 0 ? (
											<span className="mpfbsb-party__meta">{ `${ t( 'up to' ) } ${ type.length } m` }</span>
										) : null }
									</div>
									<FareTag fare={ fareFor( fares, 'vehicles', type.id ) } free={ false } />
									<Stepper
										label={ type.name }
										value={ vehicles[ type.id ] ?? 0 }
										min={ 0 }
										max={ vehiclesRefused ? 0 : headroom( vehicles, type.id, type.max_per_booking, cfg.maxVehicles ) }
										onChange={ ( next ) => setVehicles( { ...vehicles, [ type.id ]: next } ) }
									/>
								</div>
							) ) }
						</div>
					) : null }

					<div className="mpfbsb-party__footer">
						<span className="mpfbsb-party__summary">
							{ partyLabel }
							{ fares?.routed ? (
								<span className="mpfbsb-party__note">
									{ t( 'Fares shown are per person, one way. Your total is confirmed when you choose a sailing.' ) }
								</span>
							) : null }
							{ cfg.maxPassengers > 0 && seats >= cfg.maxPassengers ? (
								<span className="mpfbsb-party__limit">
									{ `${ t( 'Largest party we can book online is' ) } ${ cfg.maxPassengers }` }
								</span>
							) : null }
						</span>
						<Button
							onClick={ () => {
								setPartyOpen( false );
								triggerRef.current?.focus();
							} }
						>
							{ t( 'Done' ) }
						</Button>
					</div>
				</div>
			) : null }

			{ error ? (
				<p className="mpfbsb-search__error" role="alert">
					{ error }
				</p>
			) : null }
		</form>
	);
}

/**
 * Returns the largest value a stepper may reach.
 *
 * Two ceilings apply: what the type itself allows, and what is left of the
 * site-wide limit on one booking once the rest of the party is counted. The
 * stepper stops at the lower of the two, so the customer cannot build a party
 * the server is going to refuse.
 */
function headroom( party: Record< number, number >, typeId: number, typeMax: number, partyMax: number ): number {
	// Both limits use zero to mean "no limit", so an unset one must not become
	// a cap of zero. Infinity is the honest value: the stepper only ever
	// compares against it to decide whether to disable the plus button.
	const perType = typeMax > 0 ? typeMax : Infinity;

	if ( partyMax <= 0 ) {
		return perType;
	}

	const others = Object.entries( party ).reduce(
		( sum, [ id, count ] ) => ( Number( id ) === typeId ? sum : sum + count ),
		0
	);

	return Math.max( 0, Math.min( perType, partyMax - others ) );
}

/**
 * Describes a passenger type's age band in a sentence a customer can act on.
 */
function ageBand( min: number, max: number ): string {
	if ( min < 0 && max < 0 ) {
		return '';
	}

	if ( min < 0 ) {
		return `${ t( 'under' ) } ${ max + 1 }`;
	}

	if ( max < 0 ) {
		return `${ min }+`;
	}

	return `${ min }–${ max }`;
}

/**
 * Reads one type's guide fare out of a fare table.
 *
 * Returns null rather than zero whenever the figure is not known — no ports
 * chosen, no crossing between them, or a type the route does not price. Zero is
 * reserved for a fare that really is nothing, which is a different statement.
 */
function fareFor( fares: Fares | null, group: 'passengers' | 'vehicles', typeId: number ): number | null {
	if ( ! fares || ! fares.routed ) {
		return null;
	}

	if ( group === 'vehicles' && ! fares.vehicles_allowed ) {
		return null;
	}

	const fare = fares[ group ][ String( typeId ) ];

	return typeof fare === 'number' ? fare : null;
}

/**
 * Prints one row's guide fare.
 *
 * A type priced at nothing only says so when the operator set it up that way;
 * a zero that came from an unpriced type says nothing at all, because "Free"
 * on a fare the customer would in fact be charged for is the worst thing this
 * panel could do.
 */
function FareTag( { fare, free }: { fare: number | null; free: boolean } ): JSX.Element | null {
	if ( fare === null ) {
		return null;
	}

	if ( fare === 0 ) {
		return free ? <span className="mpfbsb-party__fare is-free">{ t( 'Free' ) }</span> : null;
	}

	return <span className="mpfbsb-party__fare">{ money( fare ) }</span>;
}
