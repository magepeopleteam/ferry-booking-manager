/**
 * Email template editor.
 *
 * The preview is the point of the screen. Operators do not write emails in the
 * abstract — they write them, look at them, and change three words. So the
 * preview renders against a real recent booking wherever the site has one, and
 * updates from the draft rather than from what was last saved.
 *
 * Variables are listed beside the fields with what each one resolves to,
 * because "{{boarding_time}}" is only useful if you can see it becomes 07:00.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { ColorField, TextAreaField, TextField } from '../components/Fields';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmFormat, fbmText } from '../lib/i18n';

interface Variable {
	token: string;
	name: string;
	description: string;
}

interface Template {
	id: string;
	label: string;
	description: string;
	owner: string;
	switch: string;
	sending: boolean;
	customised: boolean;
	enabled: boolean;
	subject: string;
	heading: string;
	body: string;
	cta_label: string;
	cta_url: string;
	variables: Variable[];
}

interface Brand {
	logo_url: string;
	accent: string;
	text: string;
	muted: string;
	background: string;
	panel: string;
	footer: string;
}

interface Preview {
	subject: string;
	html: string;
	text: string;
	source: string;
	booking: string;
}

/**
 * Renders the templates panel.
 */
export function EmailTemplatesPanel(): JSX.Element {
	const toast = useFbmToast();

	const [ templates, setTemplates ] = useState< Template[] >( [] );
	const [ brand, setBrand ] = useState< Brand | null >( null );
	const [ stored, setStored ] = useState< Partial< Brand > >( {} );
	const [ activeId, setActiveId ] = useState( '' );
	const [ draft, setDraft ] = useState< Template | null >( null );
	const [ preview, setPreview ] = useState< Preview | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ resetting, setResetting ] = useState( false );
	const [ testTo, setTestTo ] = useState( '' );
	const [ testing, setTesting ] = useState( false );
	const [ showBrand, setShowBrand ] = useState( false );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );

		try {
			const response = await fbmRequest< { templates: Template[]; brand: Brand; stored: Partial< Brand > } >( 'emails/templates' );
			setTemplates( response.data.templates );
			setBrand( response.data.brand );
			setStored( response.data.stored );

			setActiveId( ( current ) => ( current !== '' ? current : ( response.data.templates[ 0 ]?.id ?? '' ) ) );
		} catch ( caught: unknown ) {
			setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		void load();
	}, [ load ] );

	const active = useMemo( () => templates.find( ( row ) => row.id === activeId ) ?? null, [ templates, activeId ] );

	useEffect( () => {
		setDraft( active ? { ...active } : null );
		setDirty( false );
	}, [ active ] );

	// The preview follows the draft, so the operator sees the wording they are
	// typing rather than the wording they last saved.
	useEffect( () => {
		if ( ! draft ) {
			setPreview( null );
			return undefined;
		}

		let cancelled = false;

		const timer = window.setTimeout( () => {
			fbmRequest< Preview >( `emails/templates/${ draft.id }/preview`, {
				method: 'POST',
				body: {
					subject: draft.subject,
					heading: draft.heading,
					body: draft.body,
					cta_label: draft.cta_label,
					cta_url: draft.cta_url,
				},
			} )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setPreview( response.data );
					}
				} )
				.catch( () => undefined );
		}, 350 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ draft ] );

	const set = useCallback( ( key: keyof Template, value: string | boolean ) => {
		setDraft( ( current ) => ( current ? { ...current, [ key ]: value } : current ) );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! draft ) {
			return;
		}

		setSaving( true );

		try {
			await fbmRequest( `emails/templates/${ draft.id }`, {
				method: 'PUT',
				body: {
					enabled: draft.enabled,
					subject: draft.subject,
					heading: draft.heading,
					body: draft.body,
					cta_label: draft.cta_label,
					cta_url: draft.cta_url,
				},
			} );

			toast.notify( fbmText( 'Template saved.' ), 'success' );
			setDirty( false );
			await load();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ draft, load, toast ] );

	const restore = useCallback( async () => {
		if ( ! draft ) {
			return;
		}

		try {
			await fbmRequest( `emails/templates/${ draft.id }`, { method: 'DELETE' } );
			toast.notify( fbmText( 'Template restored to the wording that ships with the plugin.' ), 'success' );
			setResetting( false );
			await load();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		}
	}, [ draft, load, toast ] );

	const sendTest = useCallback( async () => {
		if ( ! draft ) {
			return;
		}

		setTesting( true );

		try {
			const response = await fbmRequest< { to: string } >( `emails/templates/${ draft.id }/test`, {
				method: 'POST',
				body: { to: testTo },
			} );
			toast.notify( fbmFormat( 'Test message sent to %s.', response.data.to ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setTesting( false );
		}
	}, [ draft, testTo, toast ] );

	const saveBrand = useCallback( async () => {
		if ( ! brand ) {
			return;
		}

		try {
			const response = await fbmRequest< { brand: Brand; stored: Partial< Brand > } >( 'emails/brand', {
				method: 'PUT',
				body: { brand },
			} );
			setBrand( response.data.brand );
			setStored( response.data.stored );
			toast.notify( fbmText( 'Look and feel saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		}
	}, [ brand, toast ] );

	if ( loading ) {
		return <LoadingState rows={ 6 } />;
	}

	if ( error !== '' ) {
		return <ErrorState message={ error } onRetry={ () => void load() } />;
	}

	if ( templates.length === 0 ) {
		return <EmptyState icon="mail" title={ fbmText( 'No templates are available.' ) } />;
	}

	return (
		<div className="fbm-emails">
			<nav className="fbm-emails__list" aria-label={ fbmText( 'Templates' ) }>
				{ templates.map( ( row ) => (
					<button
						type="button"
						key={ row.id }
						className={ `fbm-emails__item${ row.id === activeId ? ' is-selected' : '' }` }
						aria-current={ row.id === activeId }
						onClick={ () => setActiveId( row.id ) }
					>
						<span className="fbm-emails__itemname">{ row.label }</span>
						<span className="fbm-emails__itemmeta">
							{ /* "On" rather than "Sending": a Free-owned message is switched
							     on under Messages, while a Pro one is only sent when
							     something asks for it. Both are honestly "on". */ }
							<span className={ `fbm-pill fbm-pill--${ row.sending ? 'positive' : 'muted' }` }>
								{ row.sending ? fbmText( 'On' ) : fbmText( 'Off' ) }
							</span>
							{ row.customised ? <span className="fbm-pill fbm-pill--muted">{ fbmText( 'Edited' ) }</span> : null }
						</span>
					</button>
				) ) }

				<button
					type="button"
					className={ `fbm-emails__item${ showBrand ? ' is-selected' : '' }` }
					onClick={ () => setShowBrand( ( current ) => ! current ) }
				>
					<span className="fbm-emails__itemname">{ fbmText( 'Look and feel' ) }</span>
					<span className="fbm-emails__itemmeta">
						<span className="fbm-pill fbm-pill--muted">{ fbmText( 'Shared by every message' ) }</span>
					</span>
				</button>
			</nav>

			<div className="fbm-emails__editor">
				{ showBrand && brand ? (
					<div className="fbm-panel">
						<div className="fbm-panel__header">
							<div>
								<h2 className="fbm-panel__title">{ fbmText( 'Look and feel' ) }</h2>
								<p className="fbm-panel__description">
									{ fbmText(
										'One set of colours and one logo for every message, so a rebrand does not leave one email looking like the old company. Leave a field blank to take it from your General settings.'
									) }
								</p>
							</div>
						</div>

						<div className="fbm-settings">
							<TextField
								label={ fbmText( 'Logo URL' ) }
								name="brand_logo"
								value={ brand.logo_url }
								onChange={ ( value ) => setBrand( { ...brand, logo_url: value } ) }
								hint={ stored.logo_url ? '' : fbmText( 'Currently taken from the company logo on the General tab.' ) }
							/>
							<ColorField label={ fbmText( 'Accent' ) } name="brand_accent" value={ brand.accent } onChange={ ( value ) => setBrand( { ...brand, accent: value } ) } />
							<ColorField label={ fbmText( 'Text' ) } name="brand_text" value={ brand.text } onChange={ ( value ) => setBrand( { ...brand, text: value } ) } />
							<ColorField label={ fbmText( 'Quiet text' ) } name="brand_muted" value={ brand.muted } onChange={ ( value ) => setBrand( { ...brand, muted: value } ) } />
							<ColorField label={ fbmText( 'Page background' ) } name="brand_background" value={ brand.background } onChange={ ( value ) => setBrand( { ...brand, background: value } ) } />
							<ColorField label={ fbmText( 'Message background' ) } name="brand_panel" value={ brand.panel } onChange={ ( value ) => setBrand( { ...brand, panel: value } ) } />
							<TextAreaField
								label={ fbmText( 'Footer' ) }
								name="brand_footer"
								value={ brand.footer }
								onChange={ ( value ) => setBrand( { ...brand, footer: value } ) }
								rows={ 3 }
								hint={ fbmText( 'Appears at the bottom of every message. Variables work here too.' ) }
							/>
						</div>

						<div className="fbm-drawer__actions">
							<button type="button" className="fbm-button fbm-button--primary" onClick={ saveBrand }>
								{ fbmText( 'Save look and feel' ) }
							</button>
						</div>
					</div>
				) : null }

				{ ! showBrand && draft ? (
					<>
						<div className="fbm-panel">
							<div className="fbm-panel__header">
								<div>
									<h2 className="fbm-panel__title">{ draft.label }</h2>
									<p className="fbm-panel__description">{ draft.description }</p>
								</div>
							</div>

							{ draft.owner === 'free' ? (
								<div className="fbm-alert fbm-alert--info" role="status">
									{ draft.sending
										? fbmText( 'This message is switched on under Messages. This screen decides what it says.' )
										: fbmText( 'This message is currently switched off under Messages, so nothing is sent whatever you write here.' ) }
								</div>
							) : (
								<label className="fbm-checkbox">
									<input
										type="checkbox"
										checked={ draft.enabled }
										onChange={ ( event ) => set( 'enabled', event.target.checked ) }
									/>
									<span>{ fbmText( 'Send this message' ) }</span>
								</label>
							) }

							<div className="fbm-settings">
								<TextField label={ fbmText( 'Subject' ) } name="tpl_subject" value={ draft.subject } onChange={ ( v ) => set( 'subject', v ) } />
								<TextField label={ fbmText( 'Heading' ) } name="tpl_heading" value={ draft.heading } onChange={ ( v ) => set( 'heading', v ) } />
								<TextAreaField
									label={ fbmText( 'Body' ) }
									name="tpl_body"
									value={ draft.body }
									onChange={ ( v ) => set( 'body', v ) }
									rows={ 12 }
									hint={ fbmText( 'A blank line starts a new paragraph. Basic formatting and links are kept.' ) }
								/>
								<TextField label={ fbmText( 'Button label' ) } name="tpl_cta" value={ draft.cta_label } onChange={ ( v ) => set( 'cta_label', v ) } hint={ fbmText( 'Leave blank for no button.' ) } />
								<TextField label={ fbmText( 'Button link' ) } name="tpl_ctaurl" value={ draft.cta_url } onChange={ ( v ) => set( 'cta_url', v ) } />
							</div>

							<div className="fbm-drawer__actions">
								<button type="button" className="fbm-button fbm-button--primary" onClick={ save } disabled={ saving || ! dirty }>
									{ saving ? fbmText( 'Saving…' ) : fbmText( 'Save template' ) }
								</button>
								<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => setResetting( true ) } disabled={ ! draft.customised }>
									{ fbmText( 'Restore the shipped wording' ) }
								</button>
							</div>

							<h3 className="fbm-subheading">{ fbmText( 'Send yourself a copy' ) }</h3>
							<div className="fbm-fieldadd__row">
								<input
									type="email"
									className="fbm-input"
									value={ testTo }
									placeholder={ fbmText( 'Leave blank to send to yourself' ) }
									aria-label={ fbmText( 'Send the test to' ) }
									onChange={ ( event ) => setTestTo( event.target.value ) }
								/>
								<button type="button" className="fbm-button fbm-button--secondary" onClick={ sendTest } disabled={ testing }>
									{ testing ? fbmText( 'Sending…' ) : fbmText( 'Send test email' ) }
								</button>
							</div>
						</div>

						<div className="fbm-panel">
							<div className="fbm-panel__header">
								<div>
									<h2 className="fbm-panel__title">{ fbmText( 'Variables' ) }</h2>
									<p className="fbm-panel__description">
										{ fbmText( 'Type one of these anywhere in the subject, heading, body or button. Anything the booking cannot supply comes out blank.' ) }
									</p>
								</div>
							</div>

							<div className="fbm-varlist">
								{ draft.variables.map( ( variable ) => (
									<div className="fbm-varlist__row" key={ variable.name }>
										<code className="fbm-varlist__token">{ variable.token }</code>
										<span className="fbm-varlist__desc">{ variable.description }</span>
									</div>
								) ) }
							</div>
						</div>
					</>
				) : null }
			</div>

			{ ! showBrand && preview ? (
				<aside className="fbm-emails__preview">
					<div className="fbm-panel">
						<div className="fbm-panel__header">
							<div>
								<h2 className="fbm-panel__title">{ fbmText( 'Preview' ) }</h2>
								<p className="fbm-panel__description">
									{ preview.source === 'booking'
										? fbmFormat( 'Filled in from booking %s.', preview.booking )
										: fbmText( 'Filled in with example details, because there are no bookings to preview against yet.' ) }
								</p>
							</div>
						</div>

						<p className="fbm-emails__subject">
							<strong>{ fbmText( 'Subject' ) }:</strong> { preview.subject }
						</p>

						{ /* Rendered in a sandboxed frame: the message is the operator's own
						     markup, and dropping it into the dashboard's DOM would let a
						     stray style rule redecorate the admin. */ }
						<iframe
							className="fbm-emails__frame"
							title={ fbmText( 'Email preview' ) }
							sandbox=""
							srcDoc={ preview.html }
						/>
					</div>
				</aside>
			) : null }

			<ConfirmDialog
				open={ resetting }
				title={ fbmText( 'Restore the shipped wording?' ) }
				message={ fbmText( 'Your edits to this template are discarded and it goes back to the wording the plugin ships with.' ) }
				confirmLabel={ fbmText( 'Restore it' ) }
				onConfirm={ restore }
				onCancel={ () => setResetting( false ) }
			/>
		</div>
	);
}
