/**
 * Emails screen.
 *
 * Which messages go out, who they come from, and — the part that actually
 * matters — a way to prove delivery works before a customer is the one who
 * discovers it does not. Booking confirmations are lost to spam filters and
 * unconfigured SMTP far more often than to bugs, and an operator has no way to
 * tell those apart from the outside.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { SwitchField, TextAreaField, TextField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { SaveBar } from '../components/SaveBar';
import { EmptyState } from '../components/States';
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';

interface SettingsPayload {
	settings: Record< string, unknown >;
}

interface MessageDefinition {
	key: string;
	label: string;
	description: string;
	audience: string;
}

const MESSAGES: MessageDefinition[] = [
	{
		key: 'send_booking_received',
		label: 'Booking received',
		description: 'Sent as soon as a booking is made, before payment clears. Confirms we have their reference.',
		audience: 'Customer',
	},
	{
		key: 'send_booking_confirmed',
		label: 'Booking confirmed',
		description: 'Sent when payment is complete. This is the message a passenger brings to check-in.',
		audience: 'Customer',
	},
	{
		key: 'send_booking_cancelled',
		label: 'Booking cancelled',
		description: 'Sent when a booking is cancelled, by staff or by the customer.',
		audience: 'Customer',
	},
	{
		key: 'notify_admin_on_booking',
		label: 'New booking alert',
		description: 'Tells your team a crossing has been sold.',
		audience: 'Staff',
	},
];

/**
 * Renders the emails destination.
 */
export function EmailsScreen(): JSX.Element {
	const toast = useMpfbsToast();
	const [ settings, setSettings ] = useState< Record< string, unknown > | null >( null );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ testTo, setTestTo ] = useState( '' );
	const [ testing, setTesting ] = useState( false );
	const [ testResult, setTestResult ] = useState< { ok: boolean; message: string } | null >( null );

	const load = useCallback( async () => {
		setError( '' );

		try {
			const payload = await mpfbsRequest< SettingsPayload >( 'settings' );
			setSettings( payload.data.settings );
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

	const save = useCallback( async () => {
		if ( ! settings ) {
			return;
		}

		setSaving( true );

		try {
			await mpfbsRequest( 'settings', { method: 'PUT', body: { settings } } );
			toast.notify( mpfbsText( 'Email settings saved.' ), 'success' );
			setDirty( false );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ settings, toast ] );

	const sendTest = useCallback( async () => {
		setTesting( true );
		setTestResult( null );

		try {
			const response = await mpfbsRequest< { sent: boolean; to: string } >( 'settings/test-email', {
				method: 'POST',
				body: { to: testTo },
			} );

			setTestResult( { ok: true, message: mpfbsFormat( 'Test message sent to %s.', response.data.to ) } );
		} catch ( caught: unknown ) {
			setTestResult( {
				ok: false,
				message: caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ),
			} );
		} finally {
			setTesting( false );
		}
	}, [ testTo ] );

	if ( error !== '' ) {
		return (
			<>
				<PageHeader title={ mpfbsText( 'Emails' ) } />
				<div className="mpfbs-panel">
					<EmptyState icon="mail" title={ error } />
				</div>
			</>
		);
	}

	const enabled = MESSAGES.filter( ( message ) => Boolean( settings?.[ message.key ] ) ).length;

	return (
		<>
			<PageHeader
				title={ mpfbsText( 'Emails' ) }
				description={ mpfbsText( 'What the plugin sends, how it looks, and who it comes from.' ) }
			/>

			<div className="mpfbs-panel">
				<div className="mpfbs-panel__header">
					<div>
						<h2 className="mpfbs-panel__title">{ mpfbsText( 'Messages' ) }</h2>
						<p className="mpfbs-panel__description">
							{ mpfbsText( 'Switching a message off does not stop the booking — it only stops the notification.' ) }
						</p>
					</div>
				</div>

				<div className="mpfbs-fieldgrid">
					{ MESSAGES.map( ( message ) => (
						<div className="mpfbs-fieldrow" key={ message.key }>
							<div className="mpfbs-fieldrow__label">
								<span className="mpfbs-fieldrow__name">{ mpfbsText( message.label ) }</span>
								<span className="mpfbs-pill mpfbs-pill--muted">{ mpfbsText( message.audience ) }</span>
								<span className="mpfbs-fieldrow__hint">{ mpfbsText( message.description ) }</span>
							</div>
							<SwitchField
								label={ mpfbsText( message.label ) }
								checked={ Boolean( settings?.[ message.key ] ) }
								onChange={ ( checked ) => set( message.key, checked ) }
							/>
						</div>
					) ) }
				</div>

				<h3 className="mpfbs-subheading">{ mpfbsText( 'Sender' ) }</h3>

				<div className="mpfbs-settings">
					<TextField
						label={ mpfbsText( 'From name' ) }
						name="email_from_name"
						value={ String( settings?.email_from_name ?? '' ) }
						onChange={ ( value ) => set( 'email_from_name', value ) }
						hint={ mpfbsText( 'Leave blank to use the site name.' ) }
					/>
					<TextField
						label={ mpfbsText( 'From address' ) }
						name="email_from_address"
						type="email"
						value={ String( settings?.email_from_address ?? '' ) }
						onChange={ ( value ) => set( 'email_from_address', value ) }
						hint={ mpfbsText( 'Use an address on your own domain, or messages will be treated as spam.' ) }
					/>
					<TextField
						label={ mpfbsText( 'Staff notification address' ) }
						name="admin_notification_email"
						type="email"
						value={ String( settings?.admin_notification_email ?? '' ) }
						onChange={ ( value ) => set( 'admin_notification_email', value ) }
						hint={ mpfbsText( 'Where new booking alerts go. Leave blank to use the site administrator.' ) }
					/>
					<TextAreaField
						label={ mpfbsText( 'Footer text' ) }
						name="email_footer_text"
						value={ String( settings?.email_footer_text ?? '' ) }
						onChange={ ( value ) => set( 'email_footer_text', value ) }
						rows={ 3 }
						hint={ mpfbsText( 'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.' ) }
					/>
				</div>

				<h3 className="mpfbs-subheading">{ mpfbsText( 'Check delivery' ) }</h3>

				<div className="mpfbs-settings">
					<p className="mpfbs-panel__description">
						{ mpfbsText(
							'Sends one message through WordPress using the sender above. If it does not arrive, the problem is mail delivery on this site rather than the plugin.'
						) }
					</p>

					<div className="mpfbs-fieldadd__row">
						<input
							type="email"
							className="mpfbs-input"
							value={ testTo }
							placeholder={ mpfbsText( 'you@example.com' ) }
							aria-label={ mpfbsText( 'Send the test to' ) }
							onChange={ ( event ) => setTestTo( event.target.value ) }
						/>
						<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ sendTest } disabled={ testing }>
							{ testing ? mpfbsText( 'Sending…' ) : mpfbsText( 'Send test email' ) }
						</button>
					</div>

					{ testResult ? (
						<div className={ `mpfbs-alert mpfbs-alert--${ testResult.ok ? 'success' : 'error' }` } role="status">
							{ testResult.message }
						</div>
					) : null }
				</div>
			</div>

			<SaveBar
				dirty={ dirty }
				saving={ saving }
				onSave={ save }
				onReset={ load }
				summary={ mpfbsFormat( '%1$s of %2$s messages enabled', String( enabled ), String( MESSAGES.length ) ) }
			/>
		</>
	);
}
