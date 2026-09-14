/**
 * Settings screen.
 *
 * The screen renders no layout of its own. The server describes the tabs, their
 * sections and every field in them, and this reads that description. Two
 * reasons: a settings screen that hand-writes its own controls needs a matching
 * sanitiser written by hand on the server, and the two lists drift — a renamed
 * key, a default written twice, and the screen quietly stops describing what
 * the plugin actually does. And a described screen can be extended by the Pro
 * plugin, which adds PDF, QR and webhook settings to a dashboard bundle that
 * was compiled before Pro was installed.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';

import { FieldConfigPanel } from '../components/FieldConfigPanel';
import { TransferPanel, WebhooksPanel } from './IntegrationsScreen';
import {
	ColorField,
	NumberField,
	SelectField,
	SwitchField,
	TextAreaField,
	TextField,
} from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { SaveBar } from '../components/SaveBar';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmText } from '../lib/i18n';
import { fbmNavigate } from '../lib/router';

interface FieldDescriptor {
	key: string;
	type: string;
	label: string;
	help?: string;
	placeholder?: string;
	unit?: string;
	options?: Array< { value: string; label: string } >;
	min?: number;
	max?: number;
	step?: number;
	wide?: boolean;
}

interface SectionDescriptor {
	title: string;
	description: string;
	custom: string;
	store: string;
	/** Where the setting is actually edited, when this section is a signpost. */
	elsewhere: { path: string; label: string } | null;
	fields: FieldDescriptor[];
}

interface TabDescriptor {
	id: string;
	label: string;
	description: string;
	/** Navigation group slug. */
	group: string;
	/** Translated heading the group is listed under. */
	groupLabel: string;
	sections: SectionDescriptor[];
}

interface TabGroup {
	key: string;
	label: string;
	tabs: TabDescriptor[];
}

interface GatewayState {
	label: string;
	description: string;
	enabled: boolean;
}

interface ManagedPage {
	key: string;
	title: string;
	exists: boolean;
	url: string;
	edit_url: string;
}

interface RoleRow {
	slug: string;
	label: string;
	users: number;
	capabilities: string[];
}

interface RoleMatrix {
	roles: RoleRow[];
	capabilities: Array< { key: string; label: string } >;
}

type Values = Record< string, unknown >;

interface SettingsPayload {
	settings: Values;
	pricing: Values;
	panels: TabDescriptor[];
	gateways: Record< string, GatewayState >;
	pages: ManagedPage[];
	roles: RoleMatrix;
}

/**
 * Buckets the described tabs into their navigation groups.
 *
 * Groups appear in the order the server first mentions them, so a tab added by
 * Pro or a third party lands under its heading without the dashboard holding a
 * list of group names of its own.
 */
function groupTabs( panels: TabDescriptor[] ): TabGroup[] {
	const groups: TabGroup[] = [];

	panels.forEach( ( panel ) => {
		const key = panel.group || 'more';
		const found = groups.find( ( group ) => group.key === key );

		if ( found ) {
			found.tabs.push( panel );

			return;
		}

		groups.push( { key, label: panel.groupLabel || key, tabs: [ panel ] } );
	} );

	return groups;
}

/**
 * Renders the settings destination.
 */
export function SettingsScreen( { tab }: { tab: string } ): JSX.Element {
	const toast = useFbmToast();
	const [ payload, setPayload ] = useState< SettingsPayload | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		fbmRequest< SettingsPayload >( 'settings' )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setPayload( response.data );
					setDirty( false );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setFailed( caught instanceof FbmApiError ? caught.message : fbmText( 'Settings could not be loaded.' ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	const panels = payload?.panels ?? [];

	// Before the description arrives there is no list of valid tabs, so the
	// requested one is kept as-is rather than being corrected to General — that
	// would fight the URL for the moment the request is in flight.
	const active = useMemo( () => {
		if ( panels.length === 0 ) {
			return tab || 'general';
		}

		return panels.some( ( panel ) => panel.id === tab ) ? tab : ( panels[ 0 ]?.id ?? 'general' );
	}, [ panels, tab ] );

	const current = panels.find( ( panel ) => panel.id === active ) ?? null;
	const groups = useMemo( () => groupTabs( panels ), [ panels ] );

	const set = useCallback( ( store: string, key: string, value: unknown ) => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			if ( store === 'pricing' ) {
				return { ...state, pricing: { ...state.pricing, [ key ]: value } };
			}

			return { ...state, settings: { ...state.settings, [ key ]: value } };
		} );
		setDirty( true );
	}, [] );

	const toggleGateway = useCallback( ( id: string, enabled: boolean ) => {
		setPayload( ( state ) => {
			if ( ! state || ! state.gateways[ id ] ) {
				return state;
			}

			return { ...state, gateways: { ...state.gateways, [ id ]: { ...state.gateways[ id ], enabled } } };
		} );
		setDirty( true );
	}, [] );

	const toggleCapability = useCallback( ( role: string, capability: string, granted: boolean ) => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			return {
				...state,
				roles: {
					...state.roles,
					roles: state.roles.roles.map( ( row ) => {
						if ( row.slug !== role ) {
							return row;
						}

						const held = row.capabilities.filter( ( held2 ) => held2 !== capability );

						return { ...row, capabilities: granted ? [ ...held, capability ] : held };
					} ),
				},
			};
		} );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! payload ) {
			return;
		}

		setSaving( true );

		try {
			// The response carries the stored state, not an acknowledgement: the
			// server clamps numbers into range and drops what it does not
			// recognise, and the operator should end up looking at what was
			// actually saved.
			const response = await fbmRequest< SettingsPayload >( 'settings', {
				method: 'PUT',
				body: {
					settings: payload.settings,
					pricing: payload.pricing,
					gateways: gatewayStates( payload.gateways ),
					roles: roleStates( payload.roles ),
				},
			} );

			setPayload( response.data );
			toast.notify( fbmText( 'Settings saved.' ), 'success' );
			setDirty( false );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ payload, toast ] );

	if ( failed !== '' ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Settings' ) } />
				<div className="fbm-panel">
					<p className="fbm-empty__body">{ failed }</p>
				</div>
			</>
		);
	}

	if ( ! payload || ! current ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Settings' ) } />
				<div className="fbm-panel">
					<div className="fbm-settings" aria-hidden="true">
						<span className="fbm-skeleton fbm-skeleton--text" />
						<span className="fbm-skeleton fbm-skeleton--text" />
						<span className="fbm-skeleton fbm-skeleton--text" />
					</div>
				</div>
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ fbmText( 'Settings' ) }
				description={ fbmText( 'How the ferry operation behaves, sells and communicates.' ) }
			/>

			<div className="fbm-settings__layout">
				<SettingsNav groups={ groups } active={ active } />

				<div
					className="fbm-settings__panels"
					role="region"
					aria-labelledby={ `fbm-settings-link-${ active }` }
				>
					{ current.description !== '' ? <p className="fbm-settings__lede">{ current.description }</p> : null }

					{ current.sections.map( ( section, index ) => (
						<Section
							key={ `${ current.id }-${ index }` }
							section={ section }
							payload={ payload }
							onChange={ set }
							onToggleGateway={ toggleGateway }
							onToggleCapability={ toggleCapability }
						/>
					) ) }

					<SaveBar dirty={ dirty } saving={ saving } onSave={ save } />
				</div>
			</div>
		</>
	);
}

/**
 * Renders the settings navigation column.
 *
 * A column rather than a strip: there are fifteen tabs before Pro adds any, and
 * a strip that wide either scrolls sideways or truncates, both of which hide
 * settings. Grouped headings also say what a tab is for before it is opened.
 * These are links, not buttons, because each one is a real address — the hash
 * router reads it, and an operator can bookmark or middle-click it.
 */
function SettingsNav( { groups, active }: { groups: TabGroup[]; active: string } ): JSX.Element {
	return (
		<nav className="fbm-settings__nav" aria-label={ fbmText( 'Settings' ) }>
			{ groups.map( ( group ) => (
				<div className="fbm-settings__navgroup" key={ group.key }>
					<h2 className="fbm-settings__navtitle" id={ `fbm-settings-group-${ group.key }` }>
						{ group.label }
					</h2>
					<ul className="fbm-settings__navlist" aria-labelledby={ `fbm-settings-group-${ group.key }` }>
						{ group.tabs.map( ( panel ) => (
							<li key={ panel.id }>
								<a
									id={ `fbm-settings-link-${ panel.id }` }
									className={ `fbm-settings__navlink${ panel.id === active ? ' is-active' : '' }` }
									href={ `#${ panel.id === 'general' ? '/settings' : `/settings/${ panel.id }` }` }
									aria-current={ panel.id === active ? 'page' : undefined }
								>
									{ panel.label }
								</a>
							</li>
						) ) }
					</ul>
				</div>
			) ) }
		</nav>
	);
}

interface SectionProps {
	section: SectionDescriptor;
	payload: SettingsPayload;
	onChange: ( store: string, key: string, value: unknown ) => void;
	onToggleGateway: ( id: string, enabled: boolean ) => void;
	onToggleCapability: ( role: string, capability: string, granted: boolean ) => void;
}

/**
 * Renders one section of a tab.
 */
function Section( { section, payload, onChange, onToggleGateway, onToggleCapability }: SectionProps ): JSX.Element | null {
	// A purpose-built control replaces the field list entirely. The field config
	// panels load and save themselves, so they sit outside the save bar.
	if ( section.custom === 'passenger-fields' ) {
		return <FieldConfigPanel group="passenger" title={ section.title } description={ section.description } />;
	}

	if ( section.custom === 'vehicle-fields' ) {
		return <FieldConfigPanel group="vehicle" title={ section.title } description={ section.description } />;
	}

	if ( section.custom === 'webhooks' ) {
		return <WebhooksPanel />;
	}

	if ( section.custom === 'transfer' ) {
		return <TransferPanel />;
	}

	return (
		<div className="fbm-panel">
			<div className="fbm-panel__header">
				<div>
					<h2 className="fbm-panel__title">{ section.title }</h2>
					{ section.description !== '' ? (
						<p className="fbm-panel__description">{ section.description }</p>
					) : null }
				</div>
			</div>

			{ section.custom === 'gateways' ? <Gateways gateways={ payload.gateways } onToggle={ onToggleGateway } /> : null }
			{ section.custom === 'pages' ? <Pages pages={ payload.pages } /> : null }
			{ section.custom === 'email-test' ? <TestEmail /> : null }
			{ section.custom === 'roles' ? <RolesMatrix matrix={ payload.roles } onToggle={ onToggleCapability } /> : null }

			{ /* A signpost, not a second copy of the controls. The setting has
			     one home, and this says where it is. */ }
			{ section.elsewhere && section.elsewhere.path !== '' ? (
				<div className="fbm-settings__elsewhere">
					<button
						type="button"
						className="fbm-button fbm-button--secondary"
						onClick={ () => fbmNavigate( section.elsewhere?.path ?? '' ) }
					>
						{ section.elsewhere.label }
					</button>
				</div>
			) : null }

			{ section.fields.length > 0 ? (
				<div className="fbm-settings">
					{ section.fields.map( ( field ) => (
						<Control
							key={ field.key }
							field={ field }
							store={ section.store }
							values={ section.store === 'pricing' ? payload.pricing : payload.settings }
							onChange={ onChange }
						/>
					) ) }
				</div>
			) : null }
		</div>
	);
}

interface ControlProps {
	field: FieldDescriptor;
	store: string;
	values: Values;
	onChange: ( store: string, key: string, value: unknown ) => void;
}

/**
 * Renders the control a field description asks for.
 */
function Control( { field, store, values, onChange }: ControlProps ): JSX.Element {
	const raw = values[ field.key ];
	const change = ( value: unknown ) => onChange( store, field.key, value );
	const wrapper = field.wide ? 'fbm-settings__wide' : undefined;

	if ( field.type === 'switch' ) {
		return (
			<div className={ wrapper }>
				<SwitchField label={ field.label } checked={ booleanOf( raw ) } hint={ field.help } onChange={ change } />
			</div>
		);
	}

	if ( field.type === 'select' ) {
		return (
			<div className={ wrapper }>
				<SelectField
					label={ field.label }
					name={ field.key }
					value={ stringOf( raw ) }
					options={ field.options ?? [] }
					hint={ field.help }
					onChange={ change }
				/>
			</div>
		);
	}

	if ( field.type === 'number' || field.type === 'percent' ) {
		return (
			<div className={ wrapper }>
				<NumberField
					label={ field.label }
					name={ field.key }
					value={ numberOf( raw ) }
					min={ field.min }
					max={ field.max }
					step={ field.step }
					suffix={ field.unit }
					hint={ field.help }
					onChange={ change }
				/>
			</div>
		);
	}

	if ( field.type === 'color' ) {
		return (
			<div className={ wrapper }>
				<ColorField label={ field.label } name={ field.key } value={ stringOf( raw ) } hint={ field.help } onChange={ change } />
			</div>
		);
	}

	if ( field.type === 'textarea' ) {
		return (
			<div className={ wrapper }>
				<TextAreaField label={ field.label } name={ field.key } value={ stringOf( raw ) } hint={ field.help } onChange={ change } />
			</div>
		);
	}

	return (
		<div className={ wrapper }>
			<TextField
				label={ field.label }
				name={ field.key }
				type={ inputType( field.type ) }
				value={ stringOf( raw ) }
				placeholder={ field.placeholder }
				hint={ field.help }
				onChange={ change }
			/>
		</div>
	);
}

/**
 * Renders the native checkout payment methods.
 */
function Gateways( { gateways, onToggle }: { gateways: Record< string, GatewayState >; onToggle: ( id: string, enabled: boolean ) => void } ): JSX.Element {
	const entries = Object.entries( gateways );

	if ( entries.length === 0 ) {
		return <p className="fbm-field__hint">{ fbmText( 'No payment methods are available.' ) }</p>;
	}

	return (
		<div className="fbm-settings">
			{ entries.map( ( [ id, gateway ] ) => (
				<SwitchField
					key={ id }
					label={ gateway.label }
					checked={ gateway.enabled }
					hint={ gateway.description }
					onChange={ ( enabled ) => onToggle( id, enabled ) }
				/>
			) ) }
		</div>
	);
}

/**
 * Lists the pages the plugin keeps.
 */
function Pages( { pages }: { pages: ManagedPage[] } ): JSX.Element {
	if ( pages.length === 0 ) {
		return <p className="fbm-field__hint">{ fbmText( 'No managed pages found.' ) }</p>;
	}

	return (
		<ul className="fbm-pagelist">
			{ pages.map( ( page ) => (
				<li className="fbm-pagelist__row" key={ page.key }>
					<span className="fbm-pagelist__title">{ page.title }</span>
					{ page.exists && page.url !== '' ? (
						<a href={ page.url } target="_blank" rel="noreferrer" className="fbm-link">
							{ fbmText( 'View' ) }
						</a>
					) : (
						<span className="fbm-pagelist__missing">{ fbmText( 'Missing' ) }</span>
					) }
					{ page.exists && page.edit_url !== '' ? (
						<a href={ page.edit_url } className="fbm-link">
							{ fbmText( 'Edit' ) }
						</a>
					) : null }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Sends a test message so an operator can prove delivery works.
 */
function TestEmail(): JSX.Element {
	const toast = useFbmToast();
	const [ to, setTo ] = useState( '' );
	const [ sending, setSending ] = useState( false );

	const send = useCallback( async () => {
		setSending( true );

		try {
			const response = await fbmRequest< { to: string } >( 'settings/test-email', {
				method: 'POST',
				body: { to },
			} );
			toast.notify(
				`${ fbmText( 'Test message sent to' ) } ${ response.data.to }`,
				'success'
			);
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSending( false );
		}
	}, [ to, toast ] );

	return (
		<div className="fbm-settings">
			<TextField
				label={ fbmText( 'Send a test message to' ) }
				name="test_email_to"
				type="email"
				value={ to }
				placeholder={ fbmText( 'Leave empty to use the admin address' ) }
				onChange={ setTo }
			/>
			<div className="fbm-settings__wide">
				<button type="button" className="fbm-button" onClick={ send } disabled={ sending }>
					{ sending ? fbmText( 'Sending…' ) : fbmText( 'Send test message' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * Renders the staff permission matrix.
 */
function RolesMatrix( { matrix, onToggle }: { matrix: RoleMatrix; onToggle: ( role: string, capability: string, granted: boolean ) => void } ): JSX.Element {
	if ( matrix.roles.length === 0 ) {
		return <p className="fbm-field__hint">{ fbmText( 'No ferry roles are installed.' ) }</p>;
	}

	return (
		<div className="fbm-matrix__scroll">
			<table className="fbm-matrix">
				<thead>
					<tr>
						<th scope="col">{ fbmText( 'Permission' ) }</th>
						{ matrix.roles.map( ( role ) => (
							<th scope="col" key={ role.slug }>
								<span className="fbm-matrix__role">{ role.label }</span>
								<span className="fbm-matrix__count">
									{ role.users === 1
										? `1 ${ fbmText( 'person' ) }`
										: `${ role.users } ${ fbmText( 'people' ) }` }
								</span>
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ matrix.capabilities.map( ( capability ) => (
						<tr key={ capability.key }>
							<th scope="row">{ capability.label }</th>
							{ matrix.roles.map( ( role ) => {
								const granted = role.capabilities.includes( capability.key );

								return (
									<td key={ `${ role.slug }-${ capability.key }` }>
										<label className="fbm-matrix__cell">
											<input
												type="checkbox"
												checked={ granted }
												onChange={ ( event ) =>
													onToggle( role.slug, capability.key, event.currentTarget.checked )
												}
											/>
											<span className="fbm-visually-hidden">
												{ `${ capability.label } — ${ role.label }` }
											</span>
										</label>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Reduces gateway records to the enabled map the API expects.
 */
function gatewayStates( gateways: Record< string, GatewayState > ): Record< string, boolean > {
	const states: Record< string, boolean > = {};

	Object.entries( gateways ).forEach( ( [ id, gateway ] ) => {
		states[ id ] = gateway.enabled;
	} );

	return states;
}

/**
 * Turns the permission matrix into the role => capability => granted map the
 * API expects. Sending the full matrix is safe: the server stores only what
 * differs from the shipped role, so an untouched matrix stores nothing.
 */
function roleStates( matrix: RoleMatrix ): Record< string, Record< string, boolean > > {
	const states: Record< string, Record< string, boolean > > = {};

	matrix.roles.forEach( ( role ) => {
		const granted: Record< string, boolean > = {};

		matrix.capabilities.forEach( ( capability ) => {
			granted[ capability.key ] = role.capabilities.includes( capability.key );
		} );

		states[ role.slug ] = granted;
	} );

	return states;
}

/**
 * Maps a described field type onto a text input type.
 */
function inputType( type: string ): 'text' | 'email' | 'tel' | 'url' {
	if ( type === 'email' || type === 'tel' || type === 'url' ) {
		return type;
	}

	return 'text';
}

function stringOf( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}

	return typeof value === 'number' && Number.isFinite( value ) ? String( value ) : '';
}

function numberOf( value: unknown ): number {
	if ( typeof value === 'number' && Number.isFinite( value ) ) {
		return value;
	}

	const parsed = typeof value === 'string' ? Number( value ) : Number.NaN;

	return Number.isFinite( parsed ) ? parsed : 0;
}

function booleanOf( value: unknown ): boolean {
	return value === true || value === 1 || value === '1';
}
