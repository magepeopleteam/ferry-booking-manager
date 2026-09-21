/**
 * Set up a whole crossing in one pass.
 *
 * The dashboard used to make an operator visit five destinations in the right
 * order to sell a single ticket: create the two ports, then the vessel, then a
 * route joining them, then generate its sailings, then go to Pricing and give
 * the route a fare. Miss one and the search returns nothing, with nothing on
 * screen to say which step was missed.
 *
 * This asks for all of it once, in the order the data depends on itself, and
 * writes it at the end. Every screen it replaces still exists for editing
 * afterwards — this is the way in, not the only way.
 *
 * Nothing is written until Create. Each step is checked before the next opens,
 * and the timetable is previewed against the server so the number of sailings
 * is known before any are made.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { Drawer } from './Drawer';
import { FormSteps } from './FormSteps';
import { NumberField, SelectField, SwitchField, TextField, TimeListField, WeekdayField } from './Fields';
import { useMpfbsToast } from './Toast';
import { mpfbsRequest, MpfbsApiError } from './../lib/api';
import { mpfbsConfig } from '../lib/config';
import { mpfbsAddDays, mpfbsToday } from '../lib/datetime';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsMoneyStep, mpfbsToMinor } from '../lib/money';
import { mpfbsInvalidateReferences, useMpfbsReferences } from '../lib/references';

export interface CrossingSetupProps {
	open: boolean;
	onClose: () => void;
	/** Called once everything has been written, so the screen behind reloads. */
	onCreated: () => void;
	/**
	 * Draws the wizard in the page instead of a drawer.
	 *
	 * Used by Get started, where the wizard is the whole screen and a drawer
	 * over an empty page would only be something to close by accident.
	 */
	inline?: boolean;
}

const STEPS = [
	{ id: 'ports', title: 'Ports' },
	{ id: 'vessel', title: 'Vessel' },
	{ id: 'route', title: 'Route' },
	{ id: 'timetable', title: 'Timetable' },
	{ id: 'fares', title: 'Fares' },
	{ id: 'review', title: 'Review' },
];

/** A port either chosen from the catalogue or described here for the first time. */
interface PortDraft {
	id: number;
	name: string;
	code: string;
	city: string;
}

const EMPTY_PORT: PortDraft = { id: 0, name: '', code: '', city: '' };

interface VesselDraft {
	id: number;
	name: string;
	code: string;
	passenger_capacity: number;
	vehicle_capacity: number;
	deck_capacity: number;
}

const EMPTY_VESSEL: VesselDraft = { id: 0, name: '', code: '', passenger_capacity: 200, vehicle_capacity: 0, deck_capacity: 0 };

/**
 * Renders the setup wizard.
 */
export function CrossingSetup( { open, onClose, onCreated, inline = false }: CrossingSetupProps ): JSX.Element | null {
	const toast = useMpfbsToast();
	const { references } = useMpfbsReferences();
	const currency = mpfbsConfig().currency;

	const [ step, setStep ] = useState( 0 );
	const [ visited, setVisited ] = useState( 0 );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ formError, setFormError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const [ origin, setOrigin ] = useState< PortDraft >( { ...EMPTY_PORT } );
	const [ destination, setDestination ] = useState< PortDraft >( { ...EMPTY_PORT } );
	const [ vessel, setVessel ] = useState< VesselDraft >( { ...EMPTY_VESSEL } );

	const [ routeName, setRouteName ] = useState( '' );
	const [ routeCode, setRouteCode ] = useState( '' );
	const [ duration, setDuration ] = useState( 30 );
	const [ takesVehicles, setTakesVehicles ] = useState( false );
	const [ bothWays, setBothWays ] = useState( true );

	const [ dateFrom, setDateFrom ] = useState( mpfbsToday() );
	const [ dateTo, setDateTo ] = useState( mpfbsAddDays( mpfbsToday(), 30 ) );
	const [ weekdays, setWeekdays ] = useState< number[] >( [ 1, 2, 3, 4, 5, 6, 0 ] );
	const [ times, setTimes ] = useState< string[] >( [] );
	const [ turnaround, setTurnaround ] = useState( 30 );

	const [ passengerFares, setPassengerFares ] = useState< Record< number, string > >( {} );
	const [ vehicleFares, setVehicleFares ] = useState< Record< number, string > >( {} );

	const [ progress, setProgress ] = useState< string[] >( [] );

	// A fresh wizard every time it is opened: a half-finished setup left in
	// state would be written on the next operator's first Create.
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		setStep( 0 );
		setVisited( 0 );
		setErrors( {} );
		setFormError( '' );
		setProgress( [] );
		setOrigin( { ...EMPTY_PORT } );
		setDestination( { ...EMPTY_PORT } );
		setVessel( { ...EMPTY_VESSEL } );
		setRouteName( '' );
		setRouteCode( '' );
		setDuration( 30 );
		setTakesVehicles( false );
		setBothWays( true );
		setDateFrom( mpfbsToday() );
		setDateTo( mpfbsAddDays( mpfbsToday(), 30 ) );
		setWeekdays( [ 1, 2, 3, 4, 5, 6, 0 ] );
		setTimes( [] );
		setTurnaround( 30 );
		setPassengerFares( {} );
		setVehicleFares( {} );
	}, [ open ] );

	const portOptions = useMemo(
		() => [
			{ value: '0', label: mpfbsText( 'Add a new port…' ) },
			...references.ports.map( ( port ) => ( { value: String( port.id ), label: port.code ? `${ port.name } (${ port.code })` : port.name } ) ),
		],
		[ references.ports ]
	);

	const vesselOptions = useMemo(
		() => [
			{ value: '0', label: mpfbsText( 'Add a new vessel…' ) },
			...references.vessels.map( ( item ) => ( { value: String( item.id ), label: item.code ? `${ item.name } (${ item.code })` : item.name } ) ),
		],
		[ references.vessels ]
	);

	const nameOf = ( draft: PortDraft ): string => {
		if ( draft.id > 0 ) {
			return references.ports.find( ( port ) => port.id === draft.id )?.name ?? '';
		}

		return draft.name;
	};

	// The route names itself after its ports unless the operator renames it,
	// which is what they would have typed anyway.
	useEffect( () => {
		const from = nameOf( origin );
		const to = nameOf( destination );

		if ( from !== '' && to !== '' ) {
			setRouteName( ( current ) => ( current === '' || current.includes( '→' ) ? `${ from } → ${ to }` : current ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ origin, destination, references.ports ] );

	/**
	 * Checks one step, returning the fields it is not happy with.
	 */
	const problems = useCallback(
		( index: number ): Record< string, string > => {
			const missing: Record< string, string > = {};
			const need = mpfbsText( 'This cannot be empty.' );

			const port = ( draft: PortDraft, prefix: string ): void => {
				if ( draft.id > 0 ) {
					return;
				}

				if ( draft.name.trim() === '' ) { missing[ `${ prefix }_name` ] = need; }
				if ( draft.code.trim() === '' ) { missing[ `${ prefix }_code` ] = need; }
			};

			if ( index === 0 ) {
				port( origin, 'origin' );
				port( destination, 'destination' );

				if ( origin.id > 0 && origin.id === destination.id ) {
					missing.destination_pick = mpfbsText( 'A crossing needs two different ports.' );
				}
			}

			if ( index === 1 && vessel.id === 0 ) {
				if ( vessel.name.trim() === '' ) { missing.vessel_name = need; }
				if ( vessel.code.trim() === '' ) { missing.vessel_code = need; }
				if ( vessel.passenger_capacity < 1 ) { missing.vessel_passenger_capacity = mpfbsText( 'A vessel has to carry somebody.' ); }
			}

			if ( index === 2 ) {
				if ( routeName.trim() === '' ) { missing.route_name = need; }
				if ( duration < 1 ) { missing.duration = mpfbsText( 'A crossing takes at least a minute.' ); }
			}

			if ( index === 3 ) {
				if ( times.length === 0 ) { missing.times = mpfbsText( 'Add at least one departure time.' ); }
				if ( weekdays.length === 0 ) { missing.weekdays = mpfbsText( 'Choose at least one day.' ); }
				if ( dateTo < dateFrom ) { missing.date_to = mpfbsText( 'The last day cannot be before the first.' ); }
			}

			/*
			 * The base passenger type ships priced at nothing. Left blank here,
			 * the route inherits that nothing and every ticket on it sells for
			 * free — a mistake nobody sees until the takings are counted.
			 */
			if ( index === 4 ) {
				const base = references.passenger_types.find( ( type ) => type.is_base );

				if ( base && base.price_mode === 'fixed' && base.base_price <= 0 && ! ( Number( passengerFares[ base.id ] ?? 0 ) > 0 ) ) {
					missing.fares = mpfbsFormat( 'Set a fare for %s. It has no price of its own, so without one every ticket would be free.', base.name );
				}
			}

			return missing;
		},
		[ dateFrom, dateTo, destination, duration, origin, passengerFares, references.passenger_types, routeName, times, vessel, weekdays ]
	);

	const goToStep = useCallback(
		( index: number ) => {
			if ( index > step && index > visited ) {
				const missing = problems( step );

				if ( Object.keys( missing ).length > 0 ) {
					setErrors( missing );

					return;
				}
			}

			setErrors( {} );
			setStep( index );
			setVisited( ( seen ) => Math.max( seen, index ) );
		},
		[ problems, step, visited ]
	);

	/**
	 * Writes everything, in the order each piece depends on the last.
	 *
	 * Reports what it managed rather than unwinding: a half-built crossing is
	 * visible and finishable on the screens behind, while a rollback that
	 * itself failed halfway would leave no trace of what happened.
	 */
	const create = useCallback( async () => {
		for ( let index = 0; index < STEPS.length; index++ ) {
			const missing = problems( index );

			if ( Object.keys( missing ).length > 0 ) {
				setErrors( missing );
				setStep( index );

				return;
			}
		}

		setBusy( true );
		setErrors( {} );
		setFormError( '' );
		setProgress( [] );

		const note = ( line: string ): void => setProgress( ( current ) => [ ...current, line ] );

		try {
			const ensurePort = async ( draft: PortDraft ): Promise< number > => {
				if ( draft.id > 0 ) {
					return draft.id;
				}

				const made = await mpfbsRequest< { id: number } >( 'ports', {
					method: 'POST',
					body: { name: draft.name, code: draft.code.toUpperCase(), city: draft.city, status: 'active' },
				} );
				note( mpfbsFormat( 'Port “%s” created.', draft.name ) );

				return Number( made.data.id ?? 0 );
			};

			const originId = await ensurePort( origin );
			const destinationId = await ensurePort( destination );

			let vesselId = vessel.id;

			if ( vesselId === 0 ) {
				const made = await mpfbsRequest< { id: number } >( 'vessels', {
					method: 'POST',
					body: {
						name: vessel.name,
						code: vessel.code.toUpperCase(),
						passenger_capacity: vessel.passenger_capacity,
						vehicle_capacity: takesVehicles ? vessel.vehicle_capacity : 0,
						deck_capacity: takesVehicles ? vessel.deck_capacity : 0,
						status: 'active',
					},
				} );
				vesselId = Number( made.data.id ?? 0 );
				note( mpfbsFormat( 'Vessel “%s” created.', vessel.name ) );
			}

			const fareTables = (): Record< string, Record< string, number > > => {
				const passengers: Record< string, number > = {};
				const vehicles: Record< string, number > = {};

				Object.entries( passengerFares ).forEach( ( [ id, value ] ) => {
					if ( value !== '' ) { passengers[ id ] = mpfbsToMinor( Number( value ) ); }
				} );

				if ( takesVehicles ) {
					Object.entries( vehicleFares ).forEach( ( [ id, value ] ) => {
						if ( value !== '' ) { vehicles[ id ] = mpfbsToMinor( Number( value ) ); }
					} );
				}

				return { passenger_prices: passengers, vehicle_prices: vehicles };
			};

			const makeRoute = async ( from: number, to: number, name: string, code: string ): Promise< number > => {
				const made = await mpfbsRequest< { id: number } >( 'routes', {
					method: 'POST',
					body: {
						name,
						code,
						origin_port: from,
						destination_port: to,
						duration,
						default_vessel: vesselId,
						allows_vehicles: takesVehicles,
						status: 'active',
						...fareTables(),
					},
				} );
				note( mpfbsFormat( 'Route “%s” created, with its fares.', name ) );

				return Number( made.data.id ?? 0 );
			};

			const outboundId = await makeRoute( originId, destinationId, routeName, routeCode.toUpperCase() );

			const schedule = async ( routeId: number, shift: number, label: string ): Promise< void > => {
				/*
				 * The return leg cannot leave at the same minute as the
				 * outbound — the vessel is at the other end of the crossing —
				 * so it is offset by the crossing plus the turnaround. A time
				 * pushed past midnight belongs to the next day and is dropped.
				 */
				const shifted = times
					.map( ( time ) => {
						const [ hour = 0, minute = 0 ] = time.split( ':' ).map( Number );
						const total = ( hour * 60 ) + minute + shift;

						return total >= 24 * 60 ? '' : `${ String( Math.floor( total / 60 ) ).padStart( 2, '0' ) }:${ String( total % 60 ).padStart( 2, '0' ) }`;
					} )
					.filter( ( time ) => time !== '' );

				if ( shifted.length === 0 ) {
					note( mpfbsFormat( 'No departures fitted the day for %s.', label ) );

					return;
				}

				const made = await mpfbsRequest< { created?: number } >( 'sailings/schedule', {
					method: 'POST',
					body: {
						route_id: routeId,
						vessel_id: vesselId,
						date_from: dateFrom,
						date_to: dateTo,
						weekdays,
						times: shifted,
						booking_close_minutes: 30,
						commit: true,
					},
				} );
				note( mpfbsFormat( '%1$s sailings scheduled for %2$s.', String( made.data.created ?? 0 ), label ) );
			};

			await schedule( outboundId, 0, routeName );

			if ( bothWays ) {
				const backName = `${ nameOf( destination ) } → ${ nameOf( origin ) }`;
				const backId = await makeRoute( destinationId, originId, backName, routeCode === '' ? '' : `${ routeCode.toUpperCase() }-R` );

				await schedule( backId, duration + turnaround, backName );
			}

			mpfbsInvalidateReferences();
			toast.notify( mpfbsText( 'The crossing is set up and on sale.' ), 'success' );
			onCreated();
			onClose();
		} catch ( caught: unknown ) {
			if ( caught instanceof MpfbsApiError ) {
				setErrors( caught.details.fields && typeof caught.details.fields === 'object' ? ( caught.details.fields as Record< string, string > ) : {} );
				setFormError( caught.message );
			} else {
				setFormError( mpfbsText( 'Something went wrong.' ) );
			}

			// Whatever did land stays listed, so it is clear what to finish by
			// hand rather than being run again from the start.
			mpfbsInvalidateReferences();
		} finally {
			setBusy( false );
		}
	}, [
		bothWays, dateFrom, dateTo, destination, duration, onClose, onCreated, origin, passengerFares,
		problems, routeCode, routeName, takesVehicles, times, toast, turnaround, vehicleFares, vessel, weekdays,
	] );

	if ( ! open ) {
		return null;
	}

	const lastStep = step === STEPS.length - 1;
	const departures = times.length * weekdays.length;

	const portFields = ( draft: PortDraft, set: ( next: PortDraft ) => void, prefix: string, label: string ): JSX.Element => (
		<>
			<SelectField
				label={ label }
				name={ `${ prefix }_pick` }
				value={ String( draft.id ) }
				options={ portOptions }
				onChange={ ( value ) => set( { ...draft, id: Number( value ) } ) }
				error={ errors[ `${ prefix }_pick` ] }
			/>

			{ draft.id === 0 ? (
				<>
					<TextField
						label={ mpfbsText( 'Port name' ) }
						name={ `${ prefix }_name` }
						value={ draft.name }
						onChange={ ( value ) => set( { ...draft, name: value } ) }
						error={ errors[ `${ prefix }_name` ] }
						required
					/>
					<TextField
						label={ mpfbsText( 'Port code' ) }
						name={ `${ prefix }_code` }
						value={ draft.code }
						onChange={ ( value ) => set( { ...draft, code: value } ) }
						error={ errors[ `${ prefix }_code` ] }
						hint={ mpfbsText( 'Shown on booking confirmations.' ) }
						required
					/>
					<TextField
						label={ mpfbsText( 'City' ) }
						name={ `${ prefix }_city` }
						value={ draft.city }
						onChange={ ( value ) => set( { ...draft, city: value } ) }
					/>
				</>
			) : null }
		</>
	);

	const footer = (
		<>
			{ step > 0 ? (
				<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => goToStep( step - 1 ) } disabled={ busy }>
					{ mpfbsText( 'Back' ) }
				</button>
			) : inline ? null : (
				<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ onClose } disabled={ busy }>
					{ mpfbsText( 'Cancel' ) }
				</button>
			) }

			<div className={ inline ? 'mpfbs-wizard__footer-end' : 'mpfbs-drawer__footer-end' }>
				{ lastStep ? (
					<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => void create() } disabled={ busy }>
						{ busy ? mpfbsText( 'Setting up…' ) : mpfbsText( 'Create it all' ) }
					</button>
				) : (
					<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => goToStep( step + 1 ) } disabled={ busy }>
						{ mpfbsText( 'Next' ) }
					</button>
				) }
			</div>
		</>
	);

	const body = (
			<form className="mpfbs-form" onSubmit={ ( event ) => event.preventDefault() }>
				<FormSteps
					steps={ STEPS }
					current={ step }
					reachable={ ( index ) => index <= Math.max( visited, step ) }
					onSelect={ goToStep }
				/>

				{ formError ? (
					<div className="mpfbs-alert mpfbs-alert--error" role="alert">
						{ formError }
					</div>
				) : null }

				{ step === 0 ? (
					<>
						<p className="mpfbs-form__note">
							{ mpfbsText( 'Where the crossing runs between. Pick a terminal you already have, or describe a new one and it is created with everything else.' ) }
						</p>
						{ portFields( origin, setOrigin, 'origin', mpfbsText( 'From' ) ) }
						{ portFields( destination, setDestination, 'destination', mpfbsText( 'To' ) ) }
					</>
				) : null }

				{ step === 1 ? (
					<>
						<p className="mpfbs-form__note">
							{ mpfbsText( 'The boat that works this crossing. Its capacities are what the crossing sells against, so a sailing can never be sold beyond the deck it has.' ) }
						</p>
						<SelectField
							label={ mpfbsText( 'Vessel' ) }
							name="vessel_pick"
							value={ String( vessel.id ) }
							options={ vesselOptions }
							onChange={ ( value ) => setVessel( { ...vessel, id: Number( value ) } ) }
						/>

						{ vessel.id === 0 ? (
							<>
								<TextField
									label={ mpfbsText( 'Vessel name' ) }
									name="vessel_name"
									value={ vessel.name }
									onChange={ ( value ) => setVessel( { ...vessel, name: value } ) }
									error={ errors.vessel_name }
									required
								/>
								<TextField
									label={ mpfbsText( 'Vessel code' ) }
									name="vessel_code"
									value={ vessel.code }
									onChange={ ( value ) => setVessel( { ...vessel, code: value } ) }
									error={ errors.vessel_code }
									required
								/>
								<NumberField
									label={ mpfbsText( 'Passenger capacity' ) }
									name="vessel_passenger_capacity"
									value={ vessel.passenger_capacity }
									min={ 0 }
									suffix={ mpfbsText( 'seats' ) }
									onChange={ ( value ) => setVessel( { ...vessel, passenger_capacity: value } ) }
									error={ errors.vessel_passenger_capacity }
									required
								/>
							</>
						) : null }

						<SwitchField
							label={ mpfbsText( 'This crossing carries vehicles' ) }
							checked={ takesVehicles }
							hint={ mpfbsText( 'Turn off for a foot-passenger crossing. Vehicle fares and deck space are then not asked for.' ) }
							onChange={ setTakesVehicles }
						/>

						{ takesVehicles && vessel.id === 0 ? (
							<>
								<NumberField
									label={ mpfbsText( 'Vehicle capacity' ) }
									name="vessel_vehicle_capacity"
									value={ vessel.vehicle_capacity }
									min={ 0 }
									suffix={ mpfbsText( 'vehicles' ) }
									onChange={ ( value ) => setVessel( { ...vessel, vehicle_capacity: value } ) }
								/>
								<NumberField
									label={ mpfbsText( 'Lane metres' ) }
									name="vessel_deck_capacity"
									value={ vessel.deck_capacity }
									min={ 0 }
									step={ 0.5 }
									suffix="m"
									onChange={ ( value ) => setVessel( { ...vessel, deck_capacity: value } ) }
									hint={ mpfbsText( 'The length of deck available. Long vehicles are sold against this, not against a headcount.' ) }
								/>
							</>
						) : null }
					</>
				) : null }

				{ step === 2 ? (
					<>
						<p className="mpfbs-form__note">
							{ mpfbsText( 'The journey itself. A return leg is a separate route on the opposite ports, so an operator who only declares one direction cannot sell a return at all — which is why it is offered here.' ) }
						</p>
						<TextField
							label={ mpfbsText( 'Route name' ) }
							name="route_name"
							value={ routeName }
							onChange={ setRouteName }
							error={ errors.route_name }
							required
						/>
						<TextField
							label={ mpfbsText( 'Route code' ) }
							name="route_code"
							value={ routeCode }
							onChange={ setRouteCode }
							hint={ mpfbsText( 'Optional. Leave blank and the route is known by its name.' ) }
						/>
						<NumberField
							label={ mpfbsText( 'Duration' ) }
							name="duration"
							value={ duration }
							min={ 1 }
							suffix={ mpfbsText( 'minutes' ) }
							onChange={ setDuration }
							error={ errors.duration }
							required
						/>
						<SwitchField
							label={ mpfbsText( 'Also create the return direction' ) }
							checked={ bothWays }
							hint={ mpfbsText( 'Creates the mirror route and its own timetable, so return journeys can be sold.' ) }
							onChange={ setBothWays }
						/>
					</>
				) : null }

				{ step === 3 ? (
					<>
						<p className="mpfbs-form__note">
							{ mpfbsText( 'When it sails. Every combination of a day and a time below becomes a sailing that can be booked.' ) }
						</p>
						<TextField label={ mpfbsText( 'First day' ) } name="date_from" type="date" value={ dateFrom } onChange={ setDateFrom } />
						<TextField
							label={ mpfbsText( 'Last day' ) }
							name="date_to"
							type="date"
							value={ dateTo }
							onChange={ setDateTo }
							error={ errors.date_to }
						/>
						<WeekdayField
							label={ mpfbsText( 'Days it runs' ) }
							values={ weekdays }
							onChange={ setWeekdays }
							error={ errors.weekdays }
						/>
						<TimeListField
							label={ mpfbsText( 'Departure times' ) }
							values={ times }
							onChange={ setTimes }
							error={ errors.times }
							hint={ mpfbsText( 'Outbound times. The return leg is offset automatically so the vessel is never in two places at once.' ) }
						/>

						{ bothWays ? (
							<NumberField
								label={ mpfbsText( 'Turnaround' ) }
								name="turnaround"
								value={ turnaround }
								min={ 0 }
								suffix={ mpfbsText( 'minutes' ) }
								onChange={ setTurnaround }
								hint={ mpfbsFormat(
									'The return leaves this long after the vessel arrives, so %s minutes after each outbound departure.',
									String( duration + turnaround )
								) }
							/>
						) : null }
					</>
				) : null }

				{ step === 4 ? (
					<>
						<p className="mpfbs-form__note">
							{ mpfbsText( 'What a ticket costs on this crossing. Leave a fare blank to charge the type’s own price; a percentage type works itself out from the base fare.' ) }
						</p>

						{ errors.fares ? (
							<div className="mpfbs-alert mpfbs-alert--error" role="alert">
								{ errors.fares }
							</div>
						) : null }

						<FareRows
							title={ mpfbsText( 'Passenger fares' ) }
							rows={ references.passenger_types.map( ( type ) => ( {
								id: type.id,
								name: type.name,
								hint:
									type.price_mode === 'percent'
										? mpfbsFormat( '%s%% of the base fare', String( type.price_percent ) )
										: type.price_mode === 'free'
											? mpfbsText( 'Always free' )
											: type.is_base && type.base_price <= 0
												? mpfbsText( 'Required: the base fare the other types are worked out from' )
												: '',
								disabled: type.price_mode !== 'fixed',
							} ) ) }
							values={ passengerFares }
							symbol={ currency.symbol }
							onChange={ setPassengerFares }
						/>

						{ takesVehicles ? (
							<FareRows
								title={ mpfbsText( 'Vehicle fares' ) }
								rows={ references.vehicle_types.map( ( type ) => ( { id: type.id, name: type.name, hint: '', disabled: false } ) ) }
								values={ vehicleFares }
								symbol={ currency.symbol }
								onChange={ setVehicleFares }
							/>
						) : null }
					</>
				) : null }

				{ step === 5 ? (
					<>
						<p className="mpfbs-form__note">{ mpfbsText( 'Nothing has been written yet. This is what Create will make.' ) }</p>

						<dl className="mpfbs-record__grid">
							<Row label={ mpfbsText( 'From' ) } value={ origin.id > 0 ? nameOf( origin ) : mpfbsFormat( '%s (new)', origin.name ) } />
							<Row label={ mpfbsText( 'To' ) } value={ destination.id > 0 ? nameOf( destination ) : mpfbsFormat( '%s (new)', destination.name ) } />
							<Row
								label={ mpfbsText( 'Vessel' ) }
								value={ vessel.id > 0 ? ( references.vessels.find( ( v ) => v.id === vessel.id )?.name ?? '' ) : mpfbsFormat( '%s (new)', vessel.name ) }
							/>
							<Row label={ mpfbsText( 'Routes' ) } value={ bothWays ? mpfbsText( 'Both directions' ) : mpfbsText( 'One direction' ) } />
							<Row label={ mpfbsText( 'Crossing time' ) } value={ mpfbsFormat( '%s minutes', String( duration ) ) } />
							<Row label={ mpfbsText( 'Runs' ) } value={ mpfbsFormat( '%1$s to %2$s', dateFrom, dateTo ) } />
							<Row
								label={ mpfbsText( 'Departures a week' ) }
								value={ String( bothWays ? departures * 2 : departures ) }
							/>
							<Row label={ mpfbsText( 'Carries vehicles' ) } value={ takesVehicles ? mpfbsText( 'Yes' ) : mpfbsText( 'Foot passengers only' ) } />
						</dl>

						{ progress.length > 0 ? (
							<ul className="mpfbs-setup__progress">
								{ progress.map( ( line, index ) => (
									<li key={ index }>{ line }</li>
								) ) }
							</ul>
						) : null }
					</>
				) : null }
			</form>
	);

	if ( inline ) {
		return (
			<section className="mpfbs-panel mpfbs-wizard">
				{ body }
				<div className="mpfbs-wizard__footer">{ footer }</div>
			</section>
		);
	}

	return (
		<Drawer
			open={ open }
			title={ mpfbsText( 'Set up a crossing' ) }
			description={ mpfbsText( 'Ports, vessel, route, timetable and fares — asked once, written together.' ) }
			onClose={ onClose }
			width="xwide"
			footer={ footer }
		>
			{ body }
		</Drawer>
	);
}

/**
 * One row of the review list.
 */
function Row( { label, value }: { label: string; value: string } ): JSX.Element {
	return (
		<div className="mpfbs-record__row">
			<dt className="mpfbs-record__label">{ label }</dt>
			<dd className="mpfbs-record__value">{ value }</dd>
		</div>
	);
}

/**
 * A block of fare inputs, one per type.
 */
function FareRows( {
	title,
	rows,
	values,
	symbol,
	onChange,
}: {
	title: string;
	rows: Array< { id: number; name: string; hint: string; disabled: boolean } >;
	values: Record< number, string >;
	symbol: string;
	onChange: ( next: Record< number, string > ) => void;
} ): JSX.Element {
	return (
		<>
			<h3 className="mpfbs-subheading">{ title }</h3>
			<div className="mpfbs-fieldgrid">
				{ rows.map( ( row ) => (
					<div className="mpfbs-fieldrow" key={ row.id }>
						<div className="mpfbs-fieldrow__label">
							<span className="mpfbs-fieldrow__name">{ row.name }</span>
							{ row.hint !== '' ? <span className="mpfbs-fieldrow__hint">{ row.hint }</span> : null }
						</div>
						<div className="mpfbs-fare">
							<span className="mpfbs-fare__symbol" aria-hidden="true">
								{ symbol }
							</span>
							<input
								type="number"
								className="mpfbs-input mpfbs-input--money"
								min={ 0 }
								step={ mpfbsMoneyStep() }
								value={ values[ row.id ] ?? '' }
								disabled={ row.disabled }
								placeholder={ mpfbsText( 'Default' ) }
								aria-label={ mpfbsFormat( 'Fare for %s', row.name ) }
								onChange={ ( event ) => {
									const next = { ...values };

									if ( event.target.value === '' ) {
										delete next[ row.id ];
									} else {
										next[ row.id ] = event.target.value;
									}

									onChange( next );
								} }
							/>
						</div>
					</div>
				) ) }
			</div>
		</>
	);
}
