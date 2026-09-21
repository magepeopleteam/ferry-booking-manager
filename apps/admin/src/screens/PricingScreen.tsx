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
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsConfig } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsFormatMoney, mpfbsMoneyStep, mpfbsToMajor, mpfbsToMinor } from '../lib/money';
import { mpfbsNavigate } from '../lib/router';
import { mpfbsInvalidateReferences, useMpfbsReferences } from '../lib/references';

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

const PANELS = [
	{ id: 'fares', label: 'Fares' },
	{ id: 'charges', label: 'Taxes and fees' },
	{ id: 'discounts', label: 'Discounts' },
];

/**
 * Renders the pricing destination.
 */
export function PricingScreen( { tab }: { tab: string } ): JSX.Element {
	// Labels stay in English here: Tabs translates what it is given.
	const panels = PANELS;

	const active = panels.some( ( panel ) => panel.id === tab ) ? tab : 'fares';

	const select = useCallback( ( id: string ) => {
		mpfbsNavigate( id === 'fares' ? '/pricing' : `/pricing/${ id }` );
	}, [] );

	return (
		<>
			<PageHeader
				title={ mpfbsText( 'Pricing' ) }
				description={ mpfbsText( 'What a crossing costs, what is added on top, and what comes off.' ) }
			/>

			<Tabs label="Pricing" active={ active } onSelect={ select } tabs={ panels } />

			<div id={ `mpfbs-tabpanel-${ active }` } role="tabpanel" aria-labelledby={ `mpfbs-tab-${ active }` }>
				{ active === 'fares' ? <FaresPanel /> : null }
				{ active === 'charges' || active === 'discounts' ? <SettingsPanel section={ active } /> : null }
			</div>
		</>
	);
}

/**
 * Per-route fare table for every passenger and vehicle type.
 */
function FaresPanel(): JSX.Element {
	const { references, loading: referencesLoading } = useMpfbsReferences();
	const toast = useMpfbsToast();
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

		mpfbsRequest< RouteFares >( `routes/${ routeId }` )
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
				next[ String( typeId ) ] = mpfbsToMinor( Number( value ) );
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
			await mpfbsRequest( `routes/${ route.id }`, {
				method: 'PUT',
				body: { passenger_prices: route.passenger_prices, vehicle_prices: route.vehicle_prices },
			} );
			toast.notify( mpfbsText( 'Fares saved.' ), 'success' );
			setDirty( false );
			mpfbsInvalidateReferences();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
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
			<div className="mpfbs-panel">
				<EmptyState
					icon="compass"
					title={ mpfbsText( 'No routes yet.' ) }
					description={ mpfbsText( 'Fares are set per route, so create a route first.' ) }
				/>
			</div>
		);
	}

	return (
		<>
		<div className="mpfbs-panel">
			<div className="mpfbs-panel__header">
				<div>
					<h2 className="mpfbs-panel__title">{ mpfbsText( 'Fares by route' ) }</h2>
					<p className="mpfbs-panel__description">
						{ mpfbsText(
							'A blank fare falls back to the type’s own price. Percentage passenger types are worked out from the base type’s fare on this route.'
						) }
					</p>
				</div>
			</div>

			<div className="mpfbs-settings">
				<SelectField
					label={ mpfbsText( 'Route' ) }
					name="route"
					value={ String( routeId ) }
					options={ routeOptions }
					onChange={ ( value ) => setRouteId( Number( value ) ) }
				/>
			</div>

			{ loading || ! route ? (
				<div className="mpfbs-fieldgrid" aria-hidden="true">
					{ [ 0, 1, 2, 3 ].map( ( index ) => (
						<div key={ index } className="mpfbs-fieldrow mpfbs-fieldrow--skeleton">
							<span className="mpfbs-skeleton mpfbs-skeleton--text" />
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
									? mpfbsFormat( '%s%% of the base fare unless set here', String( type.price_percent ) )
									: type.price_mode === 'free'
										? mpfbsText( 'Always free' )
										: mpfbsFormat( 'Type default %s', mpfbsFormatMoney( type.base_price ) ),
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
							hint: mpfbsFormat( 'Type default %s', mpfbsFormatMoney( type.base_price ) ),
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
	const currency = mpfbsConfig().currency;

	return (
		<>
			<h3 className="mpfbs-subheading">{ mpfbsText( title ) }</h3>
			<div className="mpfbs-fieldgrid">
				{ types.map( ( type ) => {
					const stored = table[ String( type.id ) ];
					const value = stored === undefined || stored === '' ? '' : String( mpfbsToMajor( Number( stored ) ) );

					return (
						<div className="mpfbs-fieldrow" key={ type.id }>
							<div className="mpfbs-fieldrow__label">
								<span className="mpfbs-fieldrow__name">{ type.name }</span>
								<span className="mpfbs-fieldrow__hint">{ type.hint }</span>
							</div>
							<div className="mpfbs-fare">
								<span className="mpfbs-fare__symbol" aria-hidden="true">
									{ currency.symbol }
								</span>
								<input
									type="number"
									className="mpfbs-input mpfbs-input--money"
									min={ 0 }
									step={ mpfbsMoneyStep() }
									value={ value }
									disabled={ type.disabled }
									placeholder={ mpfbsText( 'Default' ) }
									aria-label={ mpfbsFormat( 'Fare for %s', type.name ) }
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
	const toast = useMpfbsToast();
	const [ settings, setSettings ] = useState< PricingSettings | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		mpfbsRequest< PricingSettings >( 'pricing/settings' )
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
			const response = await mpfbsRequest< PricingSettings >( 'pricing/settings', { method: 'PUT', body: settings } );
			setSettings( response.data );
			setDirty( false );
			toast.notify( mpfbsText( 'Pricing saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ settings, toast ] );

	const charges = section === 'charges';

	if ( ! settings ) {
		return (
			<div className="mpfbs-panel">
				<div className="mpfbs-settings" aria-hidden="true">
					<span className="mpfbs-skeleton mpfbs-skeleton--text" />
					<span className="mpfbs-skeleton mpfbs-skeleton--text" />
				</div>
			</div>
		);
	}

	return (
		<>
		<div className="mpfbs-panel">
			<div className="mpfbs-panel__header">
				<div>
					<h2 className="mpfbs-panel__title">{ charges ? mpfbsText( 'Taxes and fees' ) : mpfbsText( 'Discounts' ) }</h2>
					<p className="mpfbs-panel__description">
						{ charges
							? mpfbsText( 'Applied on top of the fare. Every total the customer sees is worked out on the server with these rules.' )
							: mpfbsText( 'Reductions are always taken from the fare before tax, and can never exceed it.' ) }
					</p>
				</div>
			</div>

			<div className="mpfbs-settings">
				{ charges ? (
					<>
						<SwitchField
							label={ mpfbsText( 'Charge tax' ) }
							checked={ settings.tax_enabled }
							onChange={ ( checked ) => set( 'tax_enabled', checked ) }
						/>
						{ settings.tax_enabled ? (
							<>
								<TextField
									label={ mpfbsText( 'Tax name' ) }
									name="tax_label"
									value={ settings.tax_label }
									onChange={ ( value ) => set( 'tax_label', value ) }
									hint={ mpfbsText( 'Shown on the price breakdown, for example VAT or GST.' ) }
								/>
								<NumberField
									label={ mpfbsText( 'Tax rate' ) }
									name="tax_rate"
									value={ settings.tax_rate }
									min={ 0 }
									max={ 100 }
									step={ 0.01 }
									suffix="%"
									onChange={ ( value ) => set( 'tax_rate', value ) }
								/>
								<SelectField
									label={ mpfbsText( 'Fares include tax' ) }
									name="tax_mode"
									value={ settings.tax_mode }
									options={ [
										{ value: 'exclusive', label: mpfbsText( 'No — add tax on top' ) },
										{ value: 'inclusive', label: mpfbsText( 'Yes — tax is already in the fare' ) },
									] }
									onChange={ ( value ) => set( 'tax_mode', value ) }
								/>
								<SwitchField
									label={ mpfbsText( 'Tax booking fees too' ) }
									checked={ settings.tax_applies_to_fees }
									onChange={ ( checked ) => set( 'tax_applies_to_fees', checked ) }
								/>
							</>
						) : null }

						<h3 className="mpfbs-subheading">{ mpfbsText( 'Fees' ) }</h3>
						<TextField
							label={ mpfbsText( 'Fee name' ) }
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
							label={ mpfbsText( 'Return journey discount' ) }
							name="return_discount"
							value={ settings.return_discount }
							min={ 0 }
							max={ 100 }
							step={ 0.5 }
							suffix="%"
							hint={ mpfbsText( 'Taken off the whole round trip when both legs are booked together.' ) }
							onChange={ ( value ) => set( 'return_discount', value ) }
						/>
						<NumberField
							label={ mpfbsText( 'Group discount from' ) }
							name="group_discount_from"
							value={ settings.group_discount_from }
							min={ 0 }
							max={ 99 }
							suffix={ mpfbsText( 'passengers' ) }
							hint={ mpfbsText( 'Use 0 to turn the group discount off. Passengers who take no seat do not count.' ) }
							onChange={ ( value ) => set( 'group_discount_from', value ) }
						/>
						<NumberField
							label={ mpfbsText( 'Group discount' ) }
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
			label={ mpfbsText( label ) }
			name={ label }
			value={ mpfbsToMajor( value ) }
			min={ 0 }
			step={ mpfbsMoneyStep() }
			suffix={ mpfbsConfig().currency.code }
			onChange={ ( next ) => onChange( mpfbsToMinor( next ) ) }
		/>
	);
}
