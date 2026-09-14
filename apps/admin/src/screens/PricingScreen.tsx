/**
 * Pricing screen.
 *
 * Three related jobs behind one destination: what each type costs on each
 * route, what is added on top, and what comes off. They are tabs rather than
 * separate menu entries because an operator setting up a season's prices moves
 * between all three in one sitting.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { NumberField, SelectField, SwitchField, TextField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { SaveBar } from '../components/SaveBar';
import { EmptyState } from '../components/States';
import { Tabs } from '../components/Tabs';
import { CabinsPanel } from './CabinsPanel';
import { ExtrasPanel } from './ExtrasPanel';
import { PricingRulesPanel } from './PricingRulesPanel';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney, fbmMoneyStep, fbmToMajor, fbmToMinor } from '../lib/money';
import { fbmNavigate } from '../lib/router';
import { fbmInvalidateReferences, useFbmReferences } from '../lib/references';

interface PricingSettings {
	tax_enabled: boolean;
	tax_rate: number;
	tax_mode: string;
	tax_label: string;
	tax_applies_to_fees: boolean;
	booking_fee: number;
	passenger_fee: number;
	vehicle_fee: number;
	fee_label: string;
	return_discount: number;
	group_discount_from: number;
	group_discount: number;
}

type FareTable = Record< string, number | string >;

interface RouteFares {
	id: number;
	name: string;
	passenger_prices: FareTable;
	vehicle_prices: FareTable;
}

/**
 * Renders the pricing destination.
 */
export function PricingScreen( { tab }: { tab: string } ): JSX.Element {
	// Price rules, extras and cabins are Pro panels. Without Pro they have
	// nothing to show, so the tabs are not offered rather than opened onto an
	// advertisement.
	const proActive = fbmConfig().proActive;
	const panels = useMemo( () => {
		// Labels stay in English here: Tabs translates what it is given.
		const free = [
			{ id: 'fares', label: 'Fares' },
			{ id: 'charges', label: 'Taxes and fees' },
			{ id: 'discounts', label: 'Discounts' },
		];

		if ( ! proActive ) {
			return free;
		}

		return [
			...free,
			{ id: 'rules', label: 'Price rules' },
			{ id: 'extras', label: 'Extras' },
			{ id: 'cabins', label: 'Cabins' },
		];
	}, [ proActive ] );

	const active = panels.some( ( panel ) => panel.id === tab ) ? tab : 'fares';

	const select = useCallback( ( id: string ) => {
		fbmNavigate( id === 'fares' ? '/pricing' : `/pricing/${ id }` );
	}, [] );

	return (
		<>
			<PageHeader
				title={ fbmText( 'Pricing' ) }
				description={ fbmText( 'What a crossing costs, what is added on top, and what comes off.' ) }
			/>

			<Tabs label="Pricing" active={ active } onSelect={ select } tabs={ panels } />

			<div id={ `fbm-tabpanel-${ active }` } role="tabpanel" aria-labelledby={ `fbm-tab-${ active }` }>
				{ active === 'fares' ? <FaresPanel /> : null }
				{ active === 'rules' ? <PricingRulesPanel /> : null }
				{ active === 'extras' ? <ExtrasPanel /> : null }
				{ active === 'cabins' ? <CabinsPanel /> : null }
				{ active === 'charges' || active === 'discounts' ? <SettingsPanel section={ active } /> : null }
			</div>
		</>
	);
}

/**
 * Per-route fare table for every passenger and vehicle type.
 */
function FaresPanel(): JSX.Element {
	const { references, loading: referencesLoading } = useFbmReferences();
	const toast = useFbmToast();
	const [ routeId, setRouteId ] = useState( 0 );
	const [ route, setRoute ] = useState< RouteFares | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	const routes = references.routes;

	useEffect( () => {
		if ( routeId === 0 && routes.length > 0 ) {
			setRouteId( routes[ 0 ]!.id );
		}
	}, [ routeId, routes ] );

	useEffect( () => {
		if ( routeId === 0 ) {
			return;
		}

		let cancelled = false;
		setLoading( true );

		fbmRequest< RouteFares >( `routes/${ routeId }` )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setRoute( response.data );
					setDirty( false );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setRoute( null );
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
	}, [ routeId ] );

	const setFare = useCallback( ( table: 'passenger_prices' | 'vehicle_prices', typeId: number, value: string ) => {
		setRoute( ( current ) => {
			if ( ! current ) {
				return current;
			}

			const next: FareTable = { ...current[ table ] };

			if ( value === '' ) {
				delete next[ String( typeId ) ];
			} else {
				next[ String( typeId ) ] = fbmToMinor( Number( value ) );
			}

			return { ...current, [ table ]: next };
		} );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! route ) {
			return;
		}

		setSaving( true );

		try {
			await fbmRequest( `routes/${ route.id }`, {
				method: 'PUT',
				body: { passenger_prices: route.passenger_prices, vehicle_prices: route.vehicle_prices },
			} );
			toast.notify( fbmText( 'Fares saved.' ), 'success' );
			setDirty( false );
			fbmInvalidateReferences();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ route, toast ] );

	const routeOptions = useMemo(
		() => routes.map( ( item ) => ( { value: item.id, label: item.name } ) ),
		[ routes ]
	);

	if ( ! referencesLoading && routes.length === 0 ) {
		return (
			<div className="fbm-panel">
				<EmptyState
					icon="compass"
					title={ fbmText( 'No routes yet.' ) }
					description={ fbmText( 'Fares are set per route, so create a route first.' ) }
				/>
			</div>
		);
	}

	return (
		<>
		<div className="fbm-panel">
			<div className="fbm-panel__header">
				<div>
					<h2 className="fbm-panel__title">{ fbmText( 'Fares by route' ) }</h2>
					<p className="fbm-panel__description">
						{ fbmText(
							'A blank fare falls back to the type’s own price. Percentage passenger types are worked out from the base type’s fare on this route.'
						) }
					</p>
				</div>
			</div>

			<div className="fbm-settings">
				<SelectField
					label={ fbmText( 'Route' ) }
					name="route"
					value={ String( routeId ) }
					options={ routeOptions }
					onChange={ ( value ) => setRouteId( Number( value ) ) }
				/>
			</div>

			{ loading || ! route ? (
				<div className="fbm-fieldgrid" aria-hidden="true">
					{ [ 0, 1, 2, 3 ].map( ( index ) => (
						<div key={ index } className="fbm-fieldrow fbm-fieldrow--skeleton">
							<span className="fbm-skeleton fbm-skeleton--text" />
						</div>
					) ) }
				</div>
			) : (
				<>
					<FareGroup
						title="Passenger fares"
						types={ references.passenger_types.map( ( type ) => ( {
							id: type.id,
							name: type.name,
							hint:
								type.price_mode === 'percent'
									? fbmFormat( '%s%% of the base fare unless set here', String( type.price_percent ) )
									: type.price_mode === 'free'
										? fbmText( 'Always free' )
										: fbmFormat( 'Type default %s', fbmFormatMoney( type.base_price ) ),
							disabled: type.price_mode === 'free',
						} ) ) }
						table={ route.passenger_prices }
						onChange={ ( id, value ) => setFare( 'passenger_prices', id, value ) }
					/>

					<FareGroup
						title="Vehicle fares"
						types={ references.vehicle_types.map( ( type ) => ( {
							id: type.id,
							name: type.name,
							hint: fbmFormat( 'Type default %s', fbmFormatMoney( type.base_price ) ),
							disabled: false,
						} ) ) }
						table={ route.vehicle_prices }
						onChange={ ( id, value ) => setFare( 'vehicle_prices', id, value ) }
					/>
				</>
			) }
		</div>

		<SaveBar dirty={ dirty } saving={ saving } onSave={ save } />
		</>
	);
}

interface FareGroupProps {
	title: string;
	types: Array< { id: number; name: string; hint: string; disabled: boolean } >;
	table: FareTable;
	onChange: ( id: number, value: string ) => void;
}

/**
 * One block of fare inputs.
 */
function FareGroup( { title, types, table, onChange }: FareGroupProps ): JSX.Element {
	const currency = fbmConfig().currency;

	return (
		<>
			<h3 className="fbm-subheading">{ fbmText( title ) }</h3>
			<div className="fbm-fieldgrid">
				{ types.map( ( type ) => {
					const stored = table[ String( type.id ) ];
					const value = stored === undefined || stored === '' ? '' : String( fbmToMajor( Number( stored ) ) );

					return (
						<div className="fbm-fieldrow" key={ type.id }>
							<div className="fbm-fieldrow__label">
								<span className="fbm-fieldrow__name">{ type.name }</span>
								<span className="fbm-fieldrow__hint">{ type.hint }</span>
							</div>
							<div className="fbm-fare">
								<span className="fbm-fare__symbol" aria-hidden="true">
									{ currency.symbol }
								</span>
								<input
									type="number"
									className="fbm-input fbm-input--money"
									min={ 0 }
									step={ fbmMoneyStep() }
									value={ value }
									disabled={ type.disabled }
									placeholder={ fbmText( 'Default' ) }
									aria-label={ fbmFormat( 'Fare for %s', type.name ) }
									onChange={ ( event ) => onChange( type.id, event.target.value ) }
								/>
							</div>
						</div>
					);
				} ) }
			</div>
		</>
	);
}

/**
 * Tax, fee and discount settings.
 */
function SettingsPanel( { section }: { section: 'charges' | 'discounts' } ): JSX.Element {
	const toast = useFbmToast();
	const [ settings, setSettings ] = useState< PricingSettings | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		fbmRequest< PricingSettings >( 'pricing/settings' )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setSettings( response.data );
					setDirty( false );
				}
			} )
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [] );

	const set = useCallback( < K extends keyof PricingSettings >( key: K, value: PricingSettings[ K ] ) => {
		setSettings( ( current ) => ( current ? { ...current, [ key ]: value } : current ) );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! settings ) {
			return;
		}

		setSaving( true );

		try {
			const response = await fbmRequest< PricingSettings >( 'pricing/settings', { method: 'PUT', body: settings } );
			setSettings( response.data );
			setDirty( false );
			toast.notify( fbmText( 'Pricing saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ settings, toast ] );

	const charges = section === 'charges';

	if ( ! settings ) {
		return (
			<div className="fbm-panel">
				<div className="fbm-settings" aria-hidden="true">
					<span className="fbm-skeleton fbm-skeleton--text" />
					<span className="fbm-skeleton fbm-skeleton--text" />
				</div>
			</div>
		);
	}

	return (
		<>
		<div className="fbm-panel">
			<div className="fbm-panel__header">
				<div>
					<h2 className="fbm-panel__title">{ charges ? fbmText( 'Taxes and fees' ) : fbmText( 'Discounts' ) }</h2>
					<p className="fbm-panel__description">
						{ charges
							? fbmText( 'Applied on top of the fare. Every total the customer sees is worked out on the server with these rules.' )
							: fbmText( 'Reductions are always taken from the fare before tax, and can never exceed it.' ) }
					</p>
				</div>
			</div>

			<div className="fbm-settings">
				{ charges ? (
					<>
						<SwitchField
							label={ fbmText( 'Charge tax' ) }
							checked={ settings.tax_enabled }
							onChange={ ( checked ) => set( 'tax_enabled', checked ) }
						/>
						{ settings.tax_enabled ? (
							<>
								<TextField
									label={ fbmText( 'Tax name' ) }
									name="tax_label"
									value={ settings.tax_label }
									onChange={ ( value ) => set( 'tax_label', value ) }
									hint={ fbmText( 'Shown on tickets and invoices, for example VAT or GST.' ) }
								/>
								<NumberField
									label={ fbmText( 'Tax rate' ) }
									name="tax_rate"
									value={ settings.tax_rate }
									min={ 0 }
									max={ 100 }
									step={ 0.01 }
									suffix="%"
									onChange={ ( value ) => set( 'tax_rate', value ) }
								/>
								<SelectField
									label={ fbmText( 'Fares include tax' ) }
									name="tax_mode"
									value={ settings.tax_mode }
									options={ [
										{ value: 'exclusive', label: fbmText( 'No — add tax on top' ) },
										{ value: 'inclusive', label: fbmText( 'Yes — tax is already in the fare' ) },
									] }
									onChange={ ( value ) => set( 'tax_mode', value ) }
								/>
								<SwitchField
									label={ fbmText( 'Tax booking fees too' ) }
									checked={ settings.tax_applies_to_fees }
									onChange={ ( checked ) => set( 'tax_applies_to_fees', checked ) }
								/>
							</>
						) : null }

						<h3 className="fbm-subheading">{ fbmText( 'Fees' ) }</h3>
						<TextField
							label={ fbmText( 'Fee name' ) }
							name="fee_label"
							value={ settings.fee_label }
							onChange={ ( value ) => set( 'fee_label', value ) }
						/>
						<MoneyField
							label="Fee per booking"
							value={ settings.booking_fee }
							onChange={ ( minor ) => set( 'booking_fee', minor ) }
						/>
						<MoneyField
							label="Fee per passenger"
							value={ settings.passenger_fee }
							onChange={ ( minor ) => set( 'passenger_fee', minor ) }
						/>
						<MoneyField
							label="Fee per vehicle"
							value={ settings.vehicle_fee }
							onChange={ ( minor ) => set( 'vehicle_fee', minor ) }
						/>
					</>
				) : (
					<>
						<NumberField
							label={ fbmText( 'Return journey discount' ) }
							name="return_discount"
							value={ settings.return_discount }
							min={ 0 }
							max={ 100 }
							step={ 0.5 }
							suffix="%"
							hint={ fbmText( 'Taken off the whole round trip when both legs are booked together.' ) }
							onChange={ ( value ) => set( 'return_discount', value ) }
						/>
						<NumberField
							label={ fbmText( 'Group discount from' ) }
							name="group_discount_from"
							value={ settings.group_discount_from }
							min={ 0 }
							max={ 99 }
							suffix={ fbmText( 'passengers' ) }
							hint={ fbmText( 'Use 0 to turn the group discount off. Passengers who take no seat do not count.' ) }
							onChange={ ( value ) => set( 'group_discount_from', value ) }
						/>
						<NumberField
							label={ fbmText( 'Group discount' ) }
							name="group_discount"
							value={ settings.group_discount }
							min={ 0 }
							max={ 100 }
							step={ 0.5 }
							suffix="%"
							onChange={ ( value ) => set( 'group_discount', value ) }
						/>
					</>
				) }
			</div>
		</div>

		<SaveBar dirty={ dirty } saving={ saving } onSave={ save } />
		</>
	);
}

interface MoneyFieldProps {
	label: string;
	value: number;
	onChange: ( minor: number ) => void;
}

/**
 * A price input that works in major units and reports minor ones.
 */
function MoneyField( { label, value, onChange }: MoneyFieldProps ): JSX.Element {
	return (
		<NumberField
			label={ fbmText( label ) }
			name={ label }
			value={ fbmToMajor( value ) }
			min={ 0 }
			step={ fbmMoneyStep() }
			suffix={ fbmConfig().currency.code }
			onChange={ ( next ) => onChange( fbmToMinor( next ) ) }
		/>
	);
}
