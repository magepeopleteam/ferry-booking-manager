/**
 * Automations.
 *
 * Each rule reads as one sentence — when this happens, do that — because that
 * is how an operator describes the thing they want, and a form that matches the
 * sentence needs no explanation. Fields that do not apply to the chosen action
 * are not shown at all rather than disabled: an empty webhook box beside an
 * email rule invites somebody to fill it in.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { NumberField, SelectField, TextAreaField, TextField } from '../components/Fields';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmFormat, fbmText } from '../lib/i18n';

interface Rule {
	key: string;
	name: string;
	enabled: boolean;
	trigger: string;
	hours_before: number;
	action: string;
	template: string;
	url: string;
	message: string;
	problem: string;
	summary: string;
}

interface Option {
	id: string;
	label: string;
	description: string;
	timed?: boolean;
	template?: string;
	needs?: string;
}

interface Payload {
	rules: Rule[];
	triggers: Option[];
	actions: Option[];
	templates: Array< { id: string; label: string } >;
	next_run: number;
}

const BLANK: Rule = {
	key: '',
	name: '',
	enabled: true,
	trigger: 'booking_created',
	hours_before: 24,
	action: 'send_email',
	template: '',
	url: '',
	message: '',
	problem: '',
	summary: '',
};

/**
 * Renders the automations panel.
 */
export function AutomationsPanel(): JSX.Element {
	const toast = useFbmToast();

	const [ payload, setPayload ] = useState< Payload | null >( null );
	const [ rules, setRules ] = useState< Rule[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ removing, setRemoving ] = useState( -1 );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );

		try {
			const response = await fbmRequest< Payload >( 'automations' );
			setPayload( response.data );
			setRules( response.data.rules );
			setDirty( false );
		} catch ( caught: unknown ) {
			setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		void load();
	}, [ load ] );

	const update = useCallback( ( index: number, patch: Partial< Rule > ) => {
		setRules( ( current ) => current.map( ( row, position ) => ( position === index ? { ...row, ...patch } : row ) ) );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		setSaving( true );

		try {
			const response = await fbmRequest< Payload >( 'automations', { method: 'PUT', body: { rules } } );
			setPayload( response.data );
			setRules( response.data.rules );
			setDirty( false );
			toast.notify( fbmText( 'Automations saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ rules, toast ] );

	if ( loading ) {
		return <LoadingState rows={ 4 } />;
	}

	if ( error !== '' ) {
		return <ErrorState message={ error } onRetry={ () => void load() } />;
	}

	const triggers = payload?.triggers ?? [];
	const actions = payload?.actions ?? [];
	const templates = payload?.templates ?? [];

	return (
		<>
			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Automations' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText(
								'Rules that run on their own. Each one watches for something happening and does one thing about it.'
							) }
						</p>
					</div>
					<button
						type="button"
						className="fbm-button fbm-button--secondary"
						onClick={ () => {
							setRules( ( current ) => [ ...current, { ...BLANK } ] );
							setDirty( true );
						} }
					>
						{ fbmText( 'Add a rule' ) }
					</button>
				</div>

				<p className="fbm-panel__description">
					{ payload && payload.next_run > 0
						? fbmFormat(
								'Timed rules are checked every hour. The next check is at %s.',
								new Date( payload.next_run * 1000 ).toLocaleString()
						  )
						: fbmText(
								'Timed rules are checked every hour by WordPress’s scheduler. On a quiet site the scheduler only runs when somebody visits, so a reminder can be late.'
						  ) }
				</p>
			</div>

			{ rules.length === 0 ? (
				<EmptyState
					icon="settings"
					title={ fbmText( 'No rules yet.' ) }
					description={ fbmText( 'A common first rule: send the departure reminder 24 hours before a crossing.' ) }
				/>
			) : null }

			{ rules.map( ( rule, index ) => {
				const trigger = triggers.find( ( row ) => row.id === rule.trigger );
				const action = actions.find( ( row ) => row.id === rule.action );

				return (
					<div className="fbm-panel fbm-rule" key={ rule.key !== '' ? rule.key : `new-${ index }` }>
						<div className="fbm-panel__header">
							<div>
								<h3 className="fbm-panel__title">
									{ rule.name !== '' ? rule.name : fbmText( 'New rule' ) }
									{ ! rule.enabled ? <span className="fbm-pill fbm-pill--muted">{ fbmText( 'Paused' ) }</span> : null }
								</h3>
								{ rule.summary !== '' ? <p className="fbm-panel__description">{ rule.summary }</p> : null }
							</div>
							<button type="button" className="fbm-button fbm-button--ghost" onClick={ () => setRemoving( index ) }>
								{ fbmText( 'Remove' ) }
							</button>
						</div>

						{ rule.problem !== '' ? (
							<div className="fbm-alert fbm-alert--warning" role="status">
								{ rule.problem }
							</div>
						) : null }

						<div className="fbm-settings">
							<TextField
								label={ fbmText( 'Name' ) }
								name={ `rule_name_${ index }` }
								value={ rule.name }
								onChange={ ( value ) => update( index, { name: value } ) }
								hint={ fbmText( 'Just for you — how this rule appears in the list.' ) }
							/>

							<SelectField
								label={ fbmText( 'When' ) }
								name={ `rule_trigger_${ index }` }
								value={ rule.trigger }
								options={ triggers.map( ( row ) => ( { value: row.id, label: row.label } ) ) }
								onChange={ ( value ) => {
									const chosen = triggers.find( ( row ) => row.id === value );
									update( index, {
										trigger: value,
										// Suggest the matching template, but only when the
										// operator has not already chosen one.
										template: rule.template !== '' ? rule.template : ( chosen?.template ?? '' ),
									} );
								} }
								hint={ trigger?.description ?? '' }
							/>

							{ trigger?.timed ? (
								<NumberField
									label={ fbmText( 'How long before departure' ) }
									name={ `rule_hours_${ index }` }
									value={ rule.hours_before }
									min={ 1 }
									max={ 672 }
									suffix={ fbmText( 'hours' ) }
									onChange={ ( value ) => update( index, { hours_before: value } ) }
									hint={ fbmText( 'Each booking is only ever acted on once by this rule.' ) }
								/>
							) : null }

							<SelectField
								label={ fbmText( 'Then' ) }
								name={ `rule_action_${ index }` }
								value={ rule.action }
								options={ actions.map( ( row ) => ( { value: row.id, label: row.label } ) ) }
								onChange={ ( value ) => update( index, { action: value } ) }
								hint={ action?.description ?? '' }
							/>

							{ action?.needs === 'template' ? (
								<SelectField
									label={ fbmText( 'Which email' ) }
									name={ `rule_template_${ index }` }
									value={ rule.template }
									options={ templates.map( ( row ) => ( { value: row.id, label: row.label } ) ) }
									onChange={ ( value ) => update( index, { template: value } ) }
								/>
							) : null }

							{ action?.needs === 'url' ? (
								<TextField
									label={ fbmText( 'URL to call' ) }
									name={ `rule_url_${ index }` }
									type="url"
									value={ rule.url }
									onChange={ ( value ) => update( index, { url: value } ) }
									hint={ fbmText( 'Signed with the same secret as your webhooks, and carrying no passenger details.' ) }
								/>
							) : null }

							{ action?.needs === 'message' ? (
								<TextAreaField
									label={ fbmText( 'Message' ) }
									name={ `rule_message_${ index }` }
									value={ rule.message }
									onChange={ ( value ) => update( index, { message: value } ) }
									rows={ 3 }
									hint={ fbmText( 'Variables work here: {{route}}, {{departure_time}}, {{booking_number}}.' ) }
								/>
							) : null }

							<label className="fbm-checkbox">
								<input
									type="checkbox"
									checked={ rule.enabled }
									onChange={ ( event ) => update( index, { enabled: event.target.checked } ) }
								/>
								<span>{ fbmText( 'This rule is running' ) }</span>
							</label>
						</div>
					</div>
				);
			} ) }

			<div className="fbm-drawer__actions">
				<button type="button" className="fbm-button fbm-button--primary" onClick={ save } disabled={ saving || ! dirty }>
					{ saving ? fbmText( 'Saving…' ) : fbmText( 'Save automations' ) }
				</button>
				<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => void load() } disabled={ ! dirty }>
					{ fbmText( 'Discard changes' ) }
				</button>
			</div>

			<ConfirmDialog
				open={ removing >= 0 }
				title={ fbmText( 'Remove this rule?' ) }
				message={ fbmText( 'The rule stops running. Anything it has already done stays as it is.' ) }
				confirmLabel={ fbmText( 'Remove it' ) }
				onConfirm={ () => {
					setRules( ( current ) => current.filter( ( _row, index ) => index !== removing ) );
					setDirty( true );
					setRemoving( -1 );
				} }
				onCancel={ () => setRemoving( -1 ) }
			/>
		</>
	);
}
