/**
 * Get started.
 *
 * What an install that starts from nothing sees until it can sell a ticket:
 * who you are, your first crossing, and going live — in that order, each step
 * opening only once the one before it is done.
 *
 * Progress is read from the server, which reads it from the data, so a step
 * finished on another screen shows as finished here and a reload never loses
 * anything. The dashboard stays locked to this screen until the last step,
 * because every other screen would only be a list of things that do not
 * exist yet.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { CrossingSetup } from '../components/CrossingSetup';
import { TextField } from '../components/Fields';
import { FormSteps } from '../components/FormSteps';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmCan } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmInvalidateReferences } from '../lib/references';
import { fbmNavigate } from '../lib/router';
import { fbmFetchSetup, fbmSetSetupStatus, useFbmSetup, type FbmSetupStatus } from '../lib/setup';

const STEPS = [
	{ id: 'business', title: 'Your business' },
	{ id: 'crossing', title: 'Your first crossing' },
	{ id: 'live', title: 'Go live' },
];

interface PaymentMethod {
	id: string;
	label: string;
}

/**
 * The first step not yet finished.
 */
function firstOpenStep( setup: FbmSetupStatus ): number {
	if ( ! setup.business ) {
		return 0;
	}

	return setup.crossing.ready ? 2 : 1;
}

/**
 * Renders the first-run setup.
 */
export function GetStartedScreen(): JSX.Element {
	const { setup } = useFbmSetup();
	const [ view, setView ] = useState< number | null >( null );

	if ( ! fbmCan( 'fbm_manage_settings' ) ) {
		return (
			<EmptyState
				icon="lock"
				title={ fbmText( 'Setup is not finished yet' ) }
				description={ fbmText( 'An administrator needs to finish setting up the ferry operation before this part of the dashboard opens.' ) }
			/>
		);
	}

	if ( ! setup ) {
		return <LoadingState rows={ 4 } />;
	}

	if ( setup.completed ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Get started' ) } />
				<EmptyState
					icon="check"
					title={ fbmText( 'Setup is finished' ) }
					description={ fbmText( 'Your first crossing is on sale. Everything can be changed later on its own screen.' ) }
					action={
						<button type="button" className="fbm-button fbm-button--primary" onClick={ () => fbmNavigate( '/dashboard' ) }>
							{ fbmText( 'Go to the dashboard' ) }
						</button>
					}
				/>
			</>
		);
	}

	const open = firstOpenStep( setup );
	const shown = view !== null && view <= open ? view : open;

	return (
		<>
			<PageHeader
				title={ fbmText( 'Get started' ) }
				description={ fbmText( 'Three steps, in order, and your first crossing is on sale. The rest of the dashboard opens when they are done.' ) }
			/>

			<FormSteps
				steps={ STEPS }
				current={ shown }
				reachable={ ( index ) => index <= open }
				onSelect={ ( index ) => setView( index ) }
			/>

			{ shown === 0 ? <BusinessStep setup={ setup } onSaved={ () => setView( null ) } /> : null }
			{ shown === 1 ? <CrossingStep setup={ setup } onNext={ () => setView( null ) } /> : null }
			{ shown === 2 ? <LiveStep setup={ setup } /> : null }
		</>
	);
}

/**
 * Step one: who the operator is.
 */
function BusinessStep( { setup, onSaved }: { setup: FbmSetupStatus; onSaved: () => void } ): JSX.Element {
	const details = setup.details ?? { company_name: '', support_email: '', support_phone: '', currency: '', currency_symbol: '' };

	const [ values, setValues ] = useState( details );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ formError, setFormError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const set = ( key: keyof typeof details ) => ( value: string ): void => setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = useCallback( async () => {
		setBusy( true );
		setErrors( {} );
		setFormError( '' );

		try {
			const response = await fbmRequest< FbmSetupStatus >( 'setup/business', { method: 'POST', body: values } );

			fbmSetSetupStatus( response.data );
			onSaved();
		} catch ( caught: unknown ) {
			if ( caught instanceof FbmApiError ) {
				setErrors( caught.details.fields && typeof caught.details.fields === 'object' ? ( caught.details.fields as Record< string, string > ) : {} );
				setFormError( caught.message );
			} else {
				setFormError( fbmText( 'Something went wrong.' ) );
			}
		} finally {
			setBusy( false );
		}
	}, [ onSaved, values ] );

	return (
		<section className="fbm-panel fbm-wizard">
			<form
				className="fbm-form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					void save();
				} }
			>
				<p className="fbm-wizard__note">
					{ fbmText( 'How your business appears on tickets, confirmations and emails. Check what is filled in and correct anything that is wrong.' ) }
				</p>

				{ formError ? (
					<div className="fbm-alert fbm-alert--error" role="alert">
						{ formError }
					</div>
				) : null }

				<TextField
					label={ fbmText( 'Company name' ) }
					name="company_name"
					value={ values.company_name }
					onChange={ set( 'company_name' ) }
					error={ errors.company_name }
					required
				/>
				<TextField
					label={ fbmText( 'Support email' ) }
					name="support_email"
					type="email"
					value={ values.support_email }
					onChange={ set( 'support_email' ) }
					error={ errors.support_email }
					hint={ fbmText( 'Where customers are told to write if something goes wrong.' ) }
					required
				/>
				<TextField
					label={ fbmText( 'Support phone' ) }
					name="support_phone"
					type="tel"
					value={ values.support_phone }
					onChange={ set( 'support_phone' ) }
				/>

				{ setup.woocommerce ? (
					<div className="fbm-alert fbm-alert--info" role="status">
						{ fbmFormat( 'WooCommerce is active, so prices use its currency (%s). Change it in WooCommerce → Settings.', setup.currency ) }
					</div>
				) : (
					<>
						<TextField
							label={ fbmText( 'Currency code' ) }
							name="currency"
							value={ values.currency }
							placeholder="EUR"
							onChange={ set( 'currency' ) }
							error={ errors.currency }
							hint={ fbmText( 'Three-letter ISO code, such as EUR or GBP.' ) }
						/>
						<TextField
							label={ fbmText( 'Currency symbol' ) }
							name="currency_symbol"
							value={ values.currency_symbol }
							placeholder="€"
							onChange={ set( 'currency_symbol' ) }
							hint={ fbmText( 'Leave empty to use the usual symbol for the code above.' ) }
						/>
					</>
				) }

				<div className="fbm-wizard__footer">
					<div className="fbm-wizard__footer-end">
						<button type="submit" className="fbm-button fbm-button--primary" disabled={ busy }>
							{ busy ? fbmText( 'Saving…' ) : fbmText( 'Save and continue' ) }
						</button>
					</div>
				</div>
			</form>
		</section>
	);
}

/**
 * Step two: one crossing that can be sold.
 */
function CrossingStep( { setup, onNext }: { setup: FbmSetupStatus; onNext: () => void } ): JSX.Element {
	const { crossing } = setup;

	const checks = [
		{ done: crossing.ports >= 2, yes: fbmText( 'Two ports' ), no: fbmText( 'Needs two ports' ) },
		{ done: crossing.vessels >= 1, yes: fbmText( 'A vessel' ), no: fbmText( 'Needs a vessel' ) },
		{ done: crossing.routes >= 1, yes: fbmText( 'A route' ), no: fbmText( 'Needs a route' ) },
		{ done: crossing.sailings >= 1, yes: fbmText( 'Upcoming sailings' ), no: fbmText( 'No future sailings yet' ) },
		{ done: crossing.fare, yes: fbmText( 'A fare' ), no: fbmText( 'No fare yet: tickets would be free' ) },
	];

	const started = crossing.ports > 0 || crossing.vessels > 0 || crossing.routes > 0;

	const created = useCallback( () => {
		fbmInvalidateReferences();
		void fbmFetchSetup( true ).catch( () => undefined );
	}, [] );

	return (
		<>
			<ul className="fbm-setup__list" aria-label={ fbmText( 'What a crossing needs' ) }>
				{ checks.map( ( check ) => (
					<li key={ check.yes } className={ check.done ? 'is-done' : '' }>
						{ check.done ? check.yes : check.no }
					</li>
				) ) }
			</ul>

			{ crossing.ready ? (
				<section className="fbm-panel fbm-wizard">
					<p className="fbm-wizard__note">{ fbmText( 'Your first crossing is ready to sell. More can be added later from Fleet and schedule.' ) }</p>
					<div className="fbm-wizard__footer">
						<div className="fbm-wizard__footer-end">
							<button type="button" className="fbm-button fbm-button--primary" onClick={ onNext }>
								{ fbmText( 'Continue' ) }
							</button>
						</div>
					</div>
				</section>
			) : (
				<>
					{ started ? (
						<div className="fbm-alert fbm-alert--info" role="status">
							{ fbmText( 'Part of a crossing already exists. Pick those ports and that vessel in the wizard below, or finish it on the Fleet and schedule tabs.' ) }{ ' ' }
							<button type="button" className="fbm-start__link" onClick={ () => fbmNavigate( '/setup/routes' ) }>
								{ fbmText( 'Open Fleet and schedule' ) }
							</button>
						</div>
					) : null }

					<CrossingSetup inline open onClose={ () => undefined } onCreated={ created } />
				</>
			) }
		</>
	);
}

/**
 * Step three: check the shop window and open it.
 */
function LiveStep( { setup }: { setup: FbmSetupStatus } ): JSX.Element {
	const toast = useFbmToast();
	const [ methods, setMethods ] = useState< PaymentMethod[] | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ formError, setFormError ] = useState( '' );

	useEffect( () => {
		let active = true;

		fbmRequest< { methods: PaymentMethod[] } >( 'payment-methods' )
			.then( ( response ) => {
				if ( active ) {
					setMethods( Array.isArray( response.data.methods ) ? response.data.methods : [] );
				}
			} )
			.catch( () => {
				if ( active ) {
					setMethods( [] );
				}
			} );

		return () => {
			active = false;
		};
	}, [] );

	const finish = useCallback( async () => {
		setBusy( true );
		setFormError( '' );

		try {
			const response = await fbmRequest< FbmSetupStatus >( 'setup/complete', { method: 'POST' } );

			fbmSetSetupStatus( response.data );
			toast.notify( fbmText( 'Setup is finished. Your crossing is on sale.' ), 'success' );
			fbmNavigate( '/dashboard' );
		} catch ( caught: unknown ) {
			setFormError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
			setBusy( false );
		}
	}, [ toast ] );

	const noMethods = methods !== null && methods.length === 0;

	return (
		<section className="fbm-panel fbm-wizard">
			<p className="fbm-wizard__note">
				{ fbmText( 'The last check before customers can book. Nothing here is final: payments and pages can be changed at any time.' ) }
			</p>

			{ formError ? (
				<div className="fbm-alert fbm-alert--error" role="alert">
					{ formError }
				</div>
			) : null }

			<ul className="fbm-start__checks">
				<li className={ noMethods ? 'is-warning' : '' }>
					<Icon name={ noMethods ? 'alert' : 'card' } size={ 18 } />
					<div>
						<strong>{ fbmText( 'How customers pay' ) }</strong>
						<p>
							{ methods === null
								? fbmText( 'Checking…' )
								: noMethods
									? fbmText( 'No payment method is switched on, so customers could not finish a booking.' )
									: methods.map( ( method ) => method.label ).join( ', ' ) }
						</p>
						<button type="button" className="fbm-start__link" onClick={ () => fbmNavigate( '/payments' ) }>
							{ fbmText( 'Change payment methods' ) }
						</button>
					</div>
				</li>

				<li className={ setup.booking_url === '' ? 'is-warning' : '' }>
					<Icon name={ setup.booking_url === '' ? 'alert' : 'ticket' } size={ 18 } />
					<div>
						<strong>{ fbmText( 'Where customers book' ) }</strong>
						<p>
							{ setup.booking_url === ''
								? fbmText( 'There is no booking page. Create one in Settings → Pages.' )
								: setup.booking_url }
						</p>
						{ setup.booking_url !== '' ? (
							<a className="fbm-start__link" href={ setup.booking_url } target="_blank" rel="noopener noreferrer">
								{ fbmText( 'Open the booking page' ) }
							</a>
						) : (
							<button type="button" className="fbm-start__link" onClick={ () => fbmNavigate( '/settings' ) }>
								{ fbmText( 'Open Settings' ) }
							</button>
						) }
					</div>
				</li>
			</ul>

			<div className="fbm-wizard__footer">
				<div className="fbm-wizard__footer-end">
					<button type="button" className="fbm-button fbm-button--primary" onClick={ () => void finish() } disabled={ busy }>
						{ busy ? fbmText( 'Finishing…' ) : fbmText( 'Finish setup' ) }
					</button>
				</div>
			</div>
		</section>
	);
}
