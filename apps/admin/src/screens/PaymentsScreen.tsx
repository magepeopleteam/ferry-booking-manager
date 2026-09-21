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
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsConfig } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';

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
	const toast = useMpfbsToast();
	const [ settings, setSettings ] = useState< Record< string, unknown > | null >( null );
	const [ gateways, setGateways ] = useState< Record< string, GatewayState > >( {} );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const load = useCallback( async () => {
		setError( '' );

		try {
			const payload = await mpfbsRequest< SettingsPayload >( 'settings' );
			setSettings( payload.data.settings );
			setGateways( payload.data.gateways );
			setDirty( false );
		} catch ( caught: unknown ) {
			setError( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ) );
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
			await mpfbsRequest( 'settings', { method: 'PUT', body: { settings, gateways: gatewayFlags } } );
			toast.notify( mpfbsText( 'Payment settings saved.' ), 'success' );
			setDirty( false );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ gateways, settings, toast ] );

	const enabledIds = useMemo(
		() => Object.entries( gateways ).filter( ( [ , state ] ) => state.enabled ).map( ( [ id ] ) => id ),
		[ gateways ]
	);

	const engine = String( settings?.checkout_engine ?? 'native' );
	const woo = mpfbsConfig().woocommerce;

	if ( error !== '' ) {
		return (
			<>
				<PageHeader title={ mpfbsText( 'Payments' ) } />
				<div className="mpfbs-panel">
					<EmptyState icon="card" title={ error } />
				</div>
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ mpfbsText( 'Payments' ) }
				description={ mpfbsText( 'Where a customer pays, and what they can pay with.' ) }
			/>

			<div className="mpfbs-panel">
				<div className="mpfbs-panel__header">
					<div>
						<h2 className="mpfbs-panel__title">{ mpfbsText( 'Checkout' ) }</h2>
						<p className="mpfbs-panel__description">
							{ mpfbsText(
								'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.'
							) }
						</p>
					</div>
				</div>

				<div className="mpfbs-settings">
					<SelectField
						label={ mpfbsText( 'Checkout engine' ) }
						name="checkout_engine"
						value={ engine }
						options={ [
							{ value: 'native', label: mpfbsText( 'Built-in checkout' ) },
							{
								value: 'woocommerce',
								label: woo
									? mpfbsText( 'WooCommerce' )
									: mpfbsText( 'WooCommerce — not installed' ),
							},
						] }
						onChange={ ( value ) => set( 'checkout_engine', value ) }
						hint={
							! woo && engine === 'woocommerce'
								? mpfbsText( 'WooCommerce is not active, so bookings will fall back to the built-in checkout.' )
								: undefined
						}
					/>

					<NumberField
						label={ mpfbsText( 'Payment deadline' ) }
						name="payment_deadline_minutes"
						value={ Number( settings?.payment_deadline_minutes ?? 0 ) }
						min={ 0 }
						max={ 525600 }
						suffix={ mpfbsText( 'minutes' ) }
						hint={ mpfbsText( 'How long an unpaid booking is held before staff should chase it. Use 0 for no deadline.' ) }
						onChange={ ( value ) => set( 'payment_deadline_minutes', value ) }
					/>
				</div>

				<h3 className="mpfbs-subheading">{ mpfbsText( 'Payment methods' ) }</h3>

				{ Object.keys( gateways ).length === 0 ? (
					<EmptyState icon="card" title={ mpfbsText( 'No payment methods are registered.' ) } />
				) : (
					<div className="mpfbs-fieldgrid">
						{ Object.entries( gateways ).map( ( [ id, state ] ) => (
							<div className="mpfbs-fieldrow" key={ id }>
								<div className="mpfbs-fieldrow__label">
									<span className="mpfbs-fieldrow__name">{ state.label }</span>
									{ state.description ? <span className="mpfbs-fieldrow__hint">{ state.description }</span> : null }
								</div>
								<SwitchField
									label={ mpfbsFormat( 'Offer %s', state.label ) }
									checked={ state.enabled }
									onChange={ ( checked ) => toggleGateway( id, checked ) }
								/>
							</div>
						) ) }
					</div>
				) }

				<div className="mpfbs-settings">
					<SelectField
						label={ mpfbsText( 'Default method' ) }
						name="default_payment_method"
						value={ String( settings?.default_payment_method ?? '' ) }
						placeholder={ mpfbsText( 'First enabled method' ) }
						options={ enabledIds.map( ( id ) => ( { value: id, label: gateways[ id ]!.label } ) ) }
						onChange={ ( value ) => set( 'default_payment_method', value ) }
						hint={ mpfbsText( 'Pre-selected on the booking form.' ) }
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
						? mpfbsText( 'No methods enabled — customers cannot pay.' )
						: mpfbsFormat( '%s payment methods enabled', String( enabledIds.length ) )
				}
			/>
		</>
	);
}
