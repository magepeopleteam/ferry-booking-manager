/**
 * Get started.
 *
 * What an install that starts from nothing sees until it can sell a ticket:
 * who you are, your first crossing, and going live — in that order, each step
 * opening only once the one before it is done.
 *
 * Progress is read from the server, which reads it from the data, so a step
 * finished on another screen shows as finished here and a reload never loses
 * anything. It stays in the sidebar until the last step is done, as a guide
 * to what the ferry operation still needs before it can take bookings.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { CrossingSetup } from '../components/CrossingSetup';
import { TextField } from '../components/Fields';
import { FormSteps } from '../components/FormSteps';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, LoadingState } from '../components/States';
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsCan } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsInvalidateReferences } from '../lib/references';
import { mpfbsNavigate } from '../lib/router';
import { mpfbsFetchSetup, mpfbsSetSetupStatus, useMpfbsSetup, type MpfbsSetupStatus } from '../lib/setup';

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
function firstOpenStep( setup: MpfbsSetupStatus ): number {
	if ( ! setup.business ) {
		return 0;
	}

	return setup.crossing.ready ? 2 : 1;
}

/**
 * Renders the first-run setup.
 */
export function GetStartedScreen(): JSX.Element {
	const { setup } = useMpfbsSetup();
	const [ view, setView ] = useState< number | null >( null );

	if ( ! mpfbsCan( 'mpfbs_manage_settings' ) ) {
		return (
			<EmptyState
				icon="settings"
				title={ mpfbsText( 'Setup is not finished yet' ) }
				description={ mpfbsText( 'An administrator is still setting up the ferry operation.' ) }
			/>
		);
	}

	if ( ! setup ) {
		return <LoadingState rows={ 4 } />;
	}

	if ( setup.completed ) {
		return (
			<>
				<PageHeader title={ mpfbsText( 'Get started' ) } />
				<EmptyState
					icon="check"
					title={ mpfbsText( 'Setup is finished' ) }
					description={ mpfbsText( 'Your first crossing is on sale. Everything can be changed later on its own screen.' ) }
					action={
						<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => mpfbsNavigate( '/dashboard' ) }>
							{ mpfbsText( 'Go to the dashboard' ) }
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
				title={ mpfbsText( 'Get started' ) }
				description={ mpfbsText( 'Three steps, in order, and your first crossing is on sale. The rest of the dashboard opens when they are done.' ) }
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
function BusinessStep( { setup, onSaved }: { setup: MpfbsSetupStatus; onSaved: () => void } ): JSX.Element {
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
			const response = await mpfbsRequest< MpfbsSetupStatus >( 'setup/business', { method: 'POST', body: values } );

			mpfbsSetSetupStatus( response.data );
			onSaved();
		} catch ( caught: unknown ) {
			if ( caught instanceof MpfbsApiError ) {
				setErrors( caught.details.fields && typeof caught.details.fields === 'object' ? ( caught.details.fields as Record< string, string > ) : {} );
				setFormError( caught.message );
			} else {
				setFormError( mpfbsText( 'Something went wrong.' ) );
			}
		} finally {
			setBusy( false );
		}
	}, [ onSaved, values ] );

	return (
		<section className="mpfbs-panel mpfbs-wizard">
			<form
				className="mpfbs-form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					void save();
				} }
			>
				<p className="mpfbs-wizard__note">
					{ mpfbsText( 'How your business appears on confirmations and emails. Check what is filled in and correct anything that is wrong.' ) }
				</p>

				{ formError ? (
					<div className="mpfbs-alert mpfbs-alert--error" role="alert">
						{ formError }
					</div>
				) : null }

				<TextField
					label={ mpfbsText( 'Company name' ) }
					name="company_name"
					value={ values.company_name }
					onChange={ set( 'company_name' ) }
					error={ errors.company_name }
					required
				/>
				<TextField
					label={ mpfbsText( 'Support email' ) }
					name="support_email"
					type="email"
					value={ values.support_email }
					onChange={ set( 'support_email' ) }
					error={ errors.support_email }
					hint={ mpfbsText( 'Where customers are told to write if something goes wrong.' ) }
					required
				/>
				<TextField
					label={ mpfbsText( 'Support phone' ) }
					name="support_phone"
					type="tel"
					value={ values.support_phone }
					onChange={ set( 'support_phone' ) }
				/>

				{ setup.woocommerce ? (
					<div className="mpfbs-alert mpfbs-alert--info" role="status">
						{ mpfbsFormat( 'WooCommerce is active, so prices use its currency (%s). Change it in WooCommerce → Settings.', setup.currency ) }
					</div>
				) : (
					<>
						<TextField
							label={ mpfbsText( 'Currency code' ) }
							name="currency"
							value={ values.currency }
							placeholder="EUR"
							onChange={ set( 'currency' ) }
							error={ errors.currency }
							hint={ mpfbsText( 'Three-letter ISO code, such as EUR or GBP.' ) }
						/>
						<TextField
							label={ mpfbsText( 'Currency symbol' ) }
							name="currency_symbol"
							value={ values.currency_symbol }
							placeholder="€"
							onChange={ set( 'currency_symbol' ) }
							hint={ mpfbsText( 'Leave empty to use the usual symbol for the code above.' ) }
						/>
					</>
				) }

				<div className="mpfbs-wizard__footer">
					<div className="mpfbs-wizard__footer-end">
						<button type="submit" className="mpfbs-button mpfbs-button--primary" disabled={ busy }>
							{ busy ? mpfbsText( 'Saving…' ) : mpfbsText( 'Save and continue' ) }
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
function CrossingStep( { setup, onNext }: { setup: MpfbsSetupStatus; onNext: () => void } ): JSX.Element {
	const { crossing } = setup;

	const checks = [
		{ done: crossing.ports >= 2, yes: mpfbsText( 'Two ports' ), no: mpfbsText( 'Needs two ports' ) },
		{ done: crossing.vessels >= 1, yes: mpfbsText( 'A vessel' ), no: mpfbsText( 'Needs a vessel' ) },
		{ done: crossing.routes >= 1, yes: mpfbsText( 'A route' ), no: mpfbsText( 'Needs a route' ) },
		{ done: crossing.sailings >= 1, yes: mpfbsText( 'Upcoming sailings' ), no: mpfbsText( 'No future sailings yet' ) },
		{ done: crossing.fare, yes: mpfbsText( 'A fare' ), no: mpfbsText( 'No fare yet: tickets would be free' ) },
	];

	const started = crossing.ports > 0 || crossing.vessels > 0 || crossing.routes > 0;

	const created = useCallback( () => {
		mpfbsInvalidateReferences();
		void mpfbsFetchSetup( true ).catch( () => undefined );
	}, [] );

	return (
		<>
			<ul className="mpfbs-setup__list" aria-label={ mpfbsText( 'What a crossing needs' ) }>
				{ checks.map( ( check ) => (
					<li key={ check.yes } className={ check.done ? 'is-done' : '' }>
						{ check.done ? check.yes : check.no }
					</li>
				) ) }
			</ul>

			{ crossing.ready ? (
				<section className="mpfbs-panel mpfbs-wizard">
					<p className="mpfbs-wizard__note">{ mpfbsText( 'Your first crossing is ready to sell. More can be added later from Fleet and schedule.' ) }</p>
					<div className="mpfbs-wizard__footer">
						<div className="mpfbs-wizard__footer-end">
							<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ onNext }>
								{ mpfbsText( 'Continue' ) }
							</button>
						</div>
					</div>
				</section>
			) : (
				<>
					{ started ? (
						<div className="mpfbs-alert mpfbs-alert--info" role="status">
							{ mpfbsText( 'Part of a crossing already exists. Pick those ports and that vessel in the wizard below, or finish it on the Fleet and schedule tabs.' ) }{ ' ' }
							<button type="button" className="mpfbs-start__link" onClick={ () => mpfbsNavigate( '/setup/routes' ) }>
								{ mpfbsText( 'Open Fleet and schedule' ) }
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
function LiveStep( { setup }: { setup: MpfbsSetupStatus } ): JSX.Element {
	const toast = useMpfbsToast();
	const [ methods, setMethods ] = useState< PaymentMethod[] | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ formError, setFormError ] = useState( '' );

	useEffect( () => {
		let active = true;

		mpfbsRequest< { methods: PaymentMethod[] } >( 'payment-methods' )
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
			const response = await mpfbsRequest< MpfbsSetupStatus >( 'setup/complete', { method: 'POST' } );

			mpfbsSetSetupStatus( response.data );
			toast.notify( mpfbsText( 'Setup is finished. Your crossing is on sale.' ), 'success' );
			mpfbsNavigate( '/dashboard' );
		} catch ( caught: unknown ) {
			setFormError( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ) );
			setBusy( false );
		}
	}, [ toast ] );

	const noMethods = methods !== null && methods.length === 0;

	return (
		<section className="mpfbs-panel mpfbs-wizard">
			<p className="mpfbs-wizard__note">
				{ mpfbsText( 'The last check before customers can book. Nothing here is final: payments and pages can be changed at any time.' ) }
			</p>

			{ formError ? (
				<div className="mpfbs-alert mpfbs-alert--error" role="alert">
					{ formError }
				</div>
			) : null }

			<ul className="mpfbs-start__checks">
				<li className={ noMethods ? 'is-warning' : '' }>
					<Icon name={ noMethods ? 'alert' : 'card' } size={ 18 } />
					<div>
						<strong>{ mpfbsText( 'How customers pay' ) }</strong>
						<p>
							{ methods === null
								? mpfbsText( 'Checking…' )
								: noMethods
									? mpfbsText( 'No payment method is switched on, so customers could not finish a booking.' )
									: methods.map( ( method ) => method.label ).join( ', ' ) }
						</p>
						<button type="button" className="mpfbs-start__link" onClick={ () => mpfbsNavigate( '/payments' ) }>
							{ mpfbsText( 'Change payment methods' ) }
						</button>
					</div>
				</li>

				<li className={ setup.booking_url === '' ? 'is-warning' : '' }>
					<Icon name={ setup.booking_url === '' ? 'alert' : 'ticket' } size={ 18 } />
					<div>
						<strong>{ mpfbsText( 'Where customers book' ) }</strong>
						<p>
							{ setup.booking_url === ''
								? mpfbsText( 'There is no booking page. Create one in Settings → Pages.' )
								: setup.booking_url }
						</p>
						{ setup.booking_url !== '' ? (
							<a className="mpfbs-start__link" href={ setup.booking_url } target="_blank" rel="noopener noreferrer">
								{ mpfbsText( 'Open the booking page' ) }
							</a>
						) : (
							<button type="button" className="mpfbs-start__link" onClick={ () => mpfbsNavigate( '/settings' ) }>
								{ mpfbsText( 'Open Settings' ) }
							</button>
						) }
					</div>
				</li>
			</ul>

			<div className="mpfbs-wizard__footer">
				<div className="mpfbs-wizard__footer-end">
					<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => void finish() } disabled={ busy }>
						{ busy ? mpfbsText( 'Finishing…' ) : mpfbsText( 'Finish setup' ) }
					</button>
				</div>
			</div>
		</section>
	);
}
