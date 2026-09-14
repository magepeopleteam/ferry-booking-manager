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
import { Tabs } from '../components/Tabs';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmNavigate } from '../lib/router';
import { AutomationsPanel } from './AutomationsPanel';
import { EmailTemplatesPanel } from './EmailTemplatesPanel';

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
export function EmailsScreen( { tab = '' }: { tab?: string } ): JSX.Element {
	const config = fbmConfig();
	const toast = useFbmToast();
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
			const payload = await fbmRequest< SettingsPayload >( 'settings' );
			setSettings( payload.data.settings );
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

	const save = useCallback( async () => {
		if ( ! settings ) {
			return;
		}

		setSaving( true );

		try {
			await fbmRequest( 'settings', { method: 'PUT', body: { settings } } );
			toast.notify( fbmText( 'Email settings saved.' ), 'success' );
			setDirty( false );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ settings, toast ] );

	const sendTest = useCallback( async () => {
		setTesting( true );
		setTestResult( null );

		try {
			const response = await fbmRequest< { sent: boolean; to: string } >( 'settings/test-email', {
				method: 'POST',
				body: { to: testTo },
			} );

			setTestResult( { ok: true, message: fbmFormat( 'Test message sent to %s.', response.data.to ) } );
		} catch ( caught: unknown ) {
			setTestResult( {
				ok: false,
				message: caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ),
			} );
		} finally {
			setTesting( false );
		}
	}, [ testTo ] );

	if ( error !== '' ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Emails' ) } />
				<div className="fbm-panel">
					<EmptyState icon="mail" title={ error } />
				</div>
			</>
		);
	}

	const enabled = MESSAGES.filter( ( message ) => Boolean( settings?.[ message.key ] ) ).length;

	// The builder is a Pro screen, so the tabs only appear when there is more
	// than one thing to choose between.
	const panels = config.proActive
		? [
				{ id: '', label: fbmText( 'Messages' ) },
				{ id: 'templates', label: fbmText( 'Templates' ) },
				{ id: 'automations', label: fbmText( 'Automations' ) },
		  ]
		: [];

	const active = panels.some( ( panel ) => panel.id === tab ) ? tab : '';

	return (
		<>
			<PageHeader
				title={ fbmText( 'Emails' ) }
				description={ fbmText( 'What the plugin sends, how it looks, and who it comes from.' ) }
			/>

			{ panels.length > 0 ? (
				<Tabs
					label={ fbmText( 'Emails' ) }
					active={ active }
					onSelect={ ( id ) => fbmNavigate( id === '' ? '/emails' : `/emails/${ id }` ) }
					tabs={ panels }
				/>
			) : null }

			{ active === 'templates' ? <EmailTemplatesPanel /> : null }
			{ active === 'automations' ? <AutomationsPanel /> : null }

			{ active === '' ? (
			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Messages' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Switching a message off does not stop the booking — it only stops the notification.' ) }
						</p>
					</div>
				</div>

				<div className="fbm-fieldgrid">
					{ MESSAGES.map( ( message ) => (
						<div className="fbm-fieldrow" key={ message.key }>
							<div className="fbm-fieldrow__label">
								<span className="fbm-fieldrow__name">{ fbmText( message.label ) }</span>
								<span className="fbm-pill fbm-pill--muted">{ fbmText( message.audience ) }</span>
								<span className="fbm-fieldrow__hint">{ fbmText( message.description ) }</span>
							</div>
							<SwitchField
								label={ fbmText( message.label ) }
								checked={ Boolean( settings?.[ message.key ] ) }
								onChange={ ( checked ) => set( message.key, checked ) }
							/>
						</div>
					) ) }
				</div>

				<h3 className="fbm-subheading">{ fbmText( 'Sender' ) }</h3>

				<div className="fbm-settings">
					<TextField
						label={ fbmText( 'From name' ) }
						name="email_from_name"
						value={ String( settings?.email_from_name ?? '' ) }
						onChange={ ( value ) => set( 'email_from_name', value ) }
						hint={ fbmText( 'Leave blank to use the site name.' ) }
					/>
					<TextField
						label={ fbmText( 'From address' ) }
						name="email_from_address"
						type="email"
						value={ String( settings?.email_from_address ?? '' ) }
						onChange={ ( value ) => set( 'email_from_address', value ) }
						hint={ fbmText( 'Use an address on your own domain, or messages will be treated as spam.' ) }
					/>
					<TextField
						label={ fbmText( 'Staff notification address' ) }
						name="admin_notification_email"
						type="email"
						value={ String( settings?.admin_notification_email ?? '' ) }
						onChange={ ( value ) => set( 'admin_notification_email', value ) }
						hint={ fbmText( 'Where new booking alerts go. Leave blank to use the site administrator.' ) }
					/>
					<TextAreaField
						label={ fbmText( 'Footer text' ) }
						name="email_footer_text"
						value={ String( settings?.email_footer_text ?? '' ) }
						onChange={ ( value ) => set( 'email_footer_text', value ) }
						rows={ 3 }
						hint={ fbmText( 'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.' ) }
					/>
				</div>

				<h3 className="fbm-subheading">{ fbmText( 'Check delivery' ) }</h3>

				<div className="fbm-settings">
					<p className="fbm-panel__description">
						{ fbmText(
							'Sends one message through WordPress using the sender above. If it does not arrive, the problem is mail delivery on this site rather than the plugin.'
						) }
					</p>

					<div className="fbm-fieldadd__row">
						<input
							type="email"
							className="fbm-input"
							value={ testTo }
							placeholder={ fbmText( 'you@example.com' ) }
							aria-label={ fbmText( 'Send the test to' ) }
							onChange={ ( event ) => setTestTo( event.target.value ) }
						/>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ sendTest } disabled={ testing }>
							{ testing ? fbmText( 'Sending…' ) : fbmText( 'Send test email' ) }
						</button>
					</div>

					{ testResult ? (
						<div className={ `fbm-alert fbm-alert--${ testResult.ok ? 'success' : 'error' }` } role="status">
							{ testResult.message }
						</div>
					) : null }
				</div>
			</div>
			) : null }

			{ active === '' ? (
				<SaveBar
					dirty={ dirty }
					saving={ saving }
					onSave={ save }
					onReset={ load }
					summary={ fbmFormat( '%1$s of %2$s messages enabled', String( enabled ), String( MESSAGES.length ) ) }
				/>
			) : null }
		</>
	);
}
