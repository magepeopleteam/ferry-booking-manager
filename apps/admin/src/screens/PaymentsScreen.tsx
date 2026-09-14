/**
 * Payments screen.
 *
 * Which checkout takes the money, and which methods a customer is offered.
 * Kept apart from Settings because it is the screen an operator opens when
 * money is not arriving, and hunting for it inside a tab of a tab is the last
 * thing they need at that moment.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { NumberField, SelectField, SwitchField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { SaveBar } from '../components/SaveBar';
import { EmptyState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';

interface GatewayState {
	label: string;
	description: string;
	enabled: boolean;
}

interface SettingsPayload {
	settings: Record< string, unknown >;
	gateways: Record< string, GatewayState >;
}

/**
 * Renders the payments destination.
 */
export function PaymentsScreen(): JSX.Element {
	const toast = useFbmToast();
	const [ settings, setSettings ] = useState< Record< string, unknown > | null >( null );
	const [ gateways, setGateways ] = useState< Record< string, GatewayState > >( {} );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const load = useCallback( async () => {
		setError( '' );

		try {
			const payload = await fbmRequest< SettingsPayload >( 'settings' );
			setSettings( payload.data.settings );
			setGateways( payload.data.gateways );
			setDirty( false );
		} catch ( caught: unknown ) {
			setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		}
	}, [] );

	useEffect( () => {
		void load();
	}, [ load ] );

	const set = useCallback( ( key: string, value: unknown ) => {
		setSettings( ( current ) => ( current ? { ...current, [ key ]: value } : current ) );
		setDirty( true );
	}, [] );

	const toggleGateway = useCallback( ( id: string, enabled: boolean ) => {
		setGateways( ( current ) => ( { ...current, [ id ]: { ...current[ id ]!, enabled } } ) );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! settings ) {
			return;
		}

		setSaving( true );

		const gatewayFlags: Record< string, boolean > = {};
		Object.entries( gateways ).forEach( ( [ id, state ] ) => {
			gatewayFlags[ id ] = state.enabled;
		} );

		try {
			await fbmRequest( 'settings', { method: 'PUT', body: { settings, gateways: gatewayFlags } } );
			toast.notify( fbmText( 'Payment settings saved.' ), 'success' );
			setDirty( false );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ gateways, settings, toast ] );

	const enabledIds = useMemo(
		() => Object.entries( gateways ).filter( ( [ , state ] ) => state.enabled ).map( ( [ id ] ) => id ),
		[ gateways ]
	);

	const engine = String( settings?.checkout_engine ?? 'native' );
	const woo = fbmConfig().woocommerce;

	if ( error !== '' ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Payments' ) } />
				<div className="fbm-panel">
					<EmptyState icon="card" title={ error } />
				</div>
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ fbmText( 'Payments' ) }
				description={ fbmText( 'Where a customer pays, and what they can pay with.' ) }
			/>

			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Checkout' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText(
								'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.'
							) }
						</p>
					</div>
				</div>

				<div className="fbm-settings">
					<SelectField
						label={ fbmText( 'Checkout engine' ) }
						name="checkout_engine"
						value={ engine }
						options={ [
							{ value: 'native', label: fbmText( 'Built-in checkout' ) },
							{
								value: 'woocommerce',
								label: woo
									? fbmText( 'WooCommerce' )
									: fbmText( 'WooCommerce — not installed' ),
							},
						] }
						onChange={ ( value ) => set( 'checkout_engine', value ) }
						hint={
							! woo && engine === 'woocommerce'
								? fbmText( 'WooCommerce is not active, so bookings will fall back to the built-in checkout.' )
								: undefined
						}
					/>

					<NumberField
						label={ fbmText( 'Payment deadline' ) }
						name="payment_deadline_minutes"
						value={ Number( settings?.payment_deadline_minutes ?? 0 ) }
						min={ 0 }
						max={ 525600 }
						suffix={ fbmText( 'minutes' ) }
						hint={ fbmText( 'How long an unpaid booking is held before staff should chase it. Use 0 for no deadline.' ) }
						onChange={ ( value ) => set( 'payment_deadline_minutes', value ) }
					/>
				</div>

				<h3 className="fbm-subheading">{ fbmText( 'Payment methods' ) }</h3>

				{ Object.keys( gateways ).length === 0 ? (
					<EmptyState icon="card" title={ fbmText( 'No payment methods are registered.' ) } />
				) : (
					<div className="fbm-fieldgrid">
						{ Object.entries( gateways ).map( ( [ id, state ] ) => (
							<div className="fbm-fieldrow" key={ id }>
								<div className="fbm-fieldrow__label">
									<span className="fbm-fieldrow__name">{ state.label }</span>
									{ state.description ? <span className="fbm-fieldrow__hint">{ state.description }</span> : null }
								</div>
								<SwitchField
									label={ fbmFormat( 'Offer %s', state.label ) }
									checked={ state.enabled }
									onChange={ ( checked ) => toggleGateway( id, checked ) }
								/>
							</div>
						) ) }
					</div>
				) }

				<div className="fbm-settings">
					<SelectField
						label={ fbmText( 'Default method' ) }
						name="default_payment_method"
						value={ String( settings?.default_payment_method ?? '' ) }
						placeholder={ fbmText( 'First enabled method' ) }
						options={ enabledIds.map( ( id ) => ( { value: id, label: gateways[ id ]!.label } ) ) }
						onChange={ ( value ) => set( 'default_payment_method', value ) }
						hint={ fbmText( 'Pre-selected on the booking form.' ) }
					/>
				</div>
			</div>

			<SaveBar
				dirty={ dirty }
				saving={ saving }
				onSave={ save }
				onReset={ load }
				summary={
					enabledIds.length === 0
						? fbmText( 'No methods enabled — customers cannot pay.' )
						: fbmFormat( '%s payment methods enabled', String( enabledIds.length ) )
				}
			/>
		</>
	);
}
