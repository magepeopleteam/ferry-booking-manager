/**
 * Dynamic pricing rules.
 *
 * A rule is a sentence — "when a sailing is over 80% full, add 15%" — and the
 * screen is built so it reads as one. Each rule shows that sentence in its
 * header, so an operator scanning a list of thirty can tell what they do without
 * opening any of them; the form underneath is only for the one being edited.
 *
 * Order matters and is visible: rules are applied top to bottom, each seeing the
 * fare the one above it left behind, and the list can be reordered directly.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { NumberField, SelectField, SwitchField, TextField } from '../components/Fields';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { SaveBar } from '../components/SaveBar';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney, fbmToMinor, fbmToMajor } from '../lib/money';

interface ActionOption {
	value: string;
	label: string;
	unit: string;
	/** Sentence fragment with one placeholder for the amount. */
	verb: string;
}

interface ConditionOption {
	value: number | string;
	label: string;
}

interface ConditionDefinition {
	key: string;
	label: string;
	type: string;
	/** Sentence fragment with one placeholder for the value. */
	summary?: string;
	options?: ConditionOption[];
}

interface Rule {
	id: string;
	label: string;
	active: boolean;
	priority: number;
	stop: boolean;
	action: string;
	amount: number;
	conditions: Record< string, unknown >;
}

interface Payload {
	rules: Rule[];
	actions: ActionOption[];
	conditions: ConditionDefinition[];
}

/**
 * Renders the pricing rules panel.
 */
export function PricingRulesPanel(): JSX.Element {
	const config = fbmConfig();
	const toast = useFbmToast();

	const [ payload, setPayload ] = useState< Payload | null >( null );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ open, setOpen ] = useState( '' );

	const load = useCallback( () => {
		let cancelled = false;
		setError( '' );

		fbmRequest< Payload >( 'pricing-rules' )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setPayload( response.data );
					setDirty( false );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! config.proActive ) {
			return undefined;
		}

		return load();
	}, [ config.proActive, load ] );

	const update = useCallback( ( id: string, changes: Partial< Rule > ) => {
		setPayload( ( state ) =>
			state
				? { ...state, rules: state.rules.map( ( rule ) => ( rule.id === id ? { ...rule, ...changes } : rule ) ) }
				: state
		);
		setDirty( true );
	}, [] );

	const setCondition = useCallback( ( id: string, key: string, value: unknown ) => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			return {
				...state,
				rules: state.rules.map( ( rule ) => {
					if ( rule.id !== id ) {
						return rule;
					}

					const conditions = { ...rule.conditions };

					// An empty condition is removed rather than stored blank:
					// "no value" and "any value" have to be the same thing, or a
					// half-filled rule would silently stop matching.
					if ( value === '' || value === 0 || ( Array.isArray( value ) && value.length === 0 ) ) {
						delete conditions[ key ];
					} else {
						conditions[ key ] = value;
					}

					return { ...rule, conditions };
				} ),
			};
		} );
		setDirty( true );
	}, [] );

	const add = useCallback( () => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			const id = `r${ Date.now().toString( 36 ) }`;
			const last = state.rules[ state.rules.length - 1 ];

			const rule: Rule = {
				id,
				label: fbmText( 'New rule' ),
				active: false,
				// Ten clear of the last, so a new rule lands at the end and
				// there is room to slot others in between later.
				priority: last ? last.priority + 10 : 10,
				stop: false,
				action: state.actions[ 0 ]?.value ?? 'decrease_percent',
				amount: 0,
				conditions: {},
			};

			setOpen( id );

			return { ...state, rules: [ ...state.rules, rule ] };
		} );
		setDirty( true );
	}, [] );

	const remove = useCallback( ( id: string ) => {
		setPayload( ( state ) => ( state ? { ...state, rules: state.rules.filter( ( r ) => r.id !== id ) } : state ) );
		setDirty( true );
	}, [] );

	const move = useCallback( ( id: string, direction: -1 | 1 ) => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			const index = state.rules.findIndex( ( rule ) => rule.id === id );
			const target = index + direction;

			if ( index < 0 || target < 0 || target >= state.rules.length ) {
				return state;
			}

			const rules = [ ...state.rules ];
			const moved = rules[ index ];
			const other = rules[ target ];

			if ( ! moved || ! other ) {
				return state;
			}

			// Swap the priorities as well as the positions, so the order shown
			// is the order applied.
			rules[ index ] = { ...other, priority: moved.priority };
			rules[ target ] = { ...moved, priority: other.priority };

			return { ...state, rules };
		} );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! payload ) {
			return;
		}

		setSaving( true );

		try {
			const response = await fbmRequest< Payload >( 'pricing-rules', {
				method: 'PUT',
				body: { rules: payload.rules },
			} );
			setPayload( response.data );
			setDirty( false );
			toast.notify( fbmText( 'Pricing rules saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ payload, toast ] );

	if ( ! config.proActive ) {
		return (
			<EmptyState
				icon="tag"
				title={ fbmText( 'Available in Ferry Booking Manager Pro.' ) }
				description={ fbmText( 'Change fares by season, day of the week, departure time, how far ahead someone books, or how full the sailing already is.' ) }
			/>
		);
	}

	if ( error !== '' ) {
		return <ErrorState message={ error } onRetry={ load } />;
	}

	if ( ! payload ) {
		return <LoadingState rows={ 4 } />;
	}

	return (
		<>
			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Price rules' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Applied in order, top to bottom. Each rule works on the fare the one above it left behind.' ) }
						</p>
					</div>
					<button type="button" className="fbm-button fbm-button--primary" onClick={ add }>
						{ fbmText( 'Add a rule' ) }
					</button>
				</div>

				{ payload.rules.length === 0 ? (
					<EmptyState
						icon="tag"
						title={ fbmText( 'No price rules yet' ) }
						description={ fbmText( 'Fares stay exactly as set on the Fares tab until you add one.' ) }
					/>
				) : (
					<ul className="fbm-rules">
						{ payload.rules.map( ( rule, index ) => (
							<li className={ `fbm-rules__item${ rule.active ? '' : ' is-off' }` } key={ rule.id }>
								<div className="fbm-rules__head">
									<button
										type="button"
										className="fbm-rules__toggle"
										aria-expanded={ open === rule.id }
										onClick={ () => setOpen( open === rule.id ? '' : rule.id ) }
									>
										<span className="fbm-rules__order">{ index + 1 }</span>
										<span className="fbm-rules__name">{ rule.label }</span>
										<span className="fbm-rules__sentence">
											{ describe( rule, payload.actions, payload.conditions ) }
										</span>
									</button>

									<div className="fbm-rules__controls">
										{ ! rule.active ? (
											<span className="fbm-pill">{ fbmText( 'Off' ) }</span>
										) : null }
										<button
											type="button"
											className="fbm-iconbutton"
											aria-label={ fbmText( 'Move up' ) }
											disabled={ index === 0 }
											onClick={ () => move( rule.id, -1 ) }
										>
											↑
										</button>
										<button
											type="button"
											className="fbm-iconbutton"
											aria-label={ fbmText( 'Move down' ) }
											disabled={ index === payload.rules.length - 1 }
											onClick={ () => move( rule.id, 1 ) }
										>
											↓
										</button>
									</div>
								</div>

								{ open === rule.id ? (
									<div className="fbm-rules__body">
										<div className="fbm-settings">
											<TextField
												label={ fbmText( 'Name' ) }
												name={ `rule_label_${ rule.id }` }
												value={ rule.label }
												hint={ fbmText( 'Shown to the customer on the price breakdown, so name it the way you would explain it.' ) }
												onChange={ ( value ) => update( rule.id, { label: value } ) }
											/>

											<SelectField
												label={ fbmText( 'What it does' ) }
												name={ `rule_action_${ rule.id }` }
												value={ rule.action }
												options={ payload.actions.map( ( a ) => ( { value: a.value, label: a.label } ) ) }
												onChange={ ( value ) => update( rule.id, { action: value } ) }
											/>

											<AmountField rule={ rule } actions={ payload.actions } onChange={ update } />

											<SwitchField
												label={ fbmText( 'Rule is on' ) }
												checked={ rule.active }
												hint={ fbmText( 'Leave a rule off while you build it. Nothing is applied until you turn it on.' ) }
												onChange={ ( checked ) => update( rule.id, { active: checked } ) }
											/>

											<SwitchField
												label={ fbmText( 'Stop after this rule' ) }
												checked={ rule.stop }
												hint={ fbmText( 'Any rule below this one is skipped when this one applies.' ) }
												onChange={ ( checked ) => update( rule.id, { stop: checked } ) }
											/>
										</div>

										<h3 className="fbm-subheading">{ fbmText( 'When it applies' ) }</h3>
										<p className="fbm-rules__note">
											{ fbmText( 'Leave everything blank to apply the rule to every crossing. Anything you fill in narrows it.' ) }
										</p>

										<div className="fbm-settings">
											{ payload.conditions.map( ( condition ) => (
												<ConditionField
													key={ condition.key }
													rule={ rule }
													condition={ condition }
													onChange={ setCondition }
												/>
											) ) }
										</div>

										<div className="fbm-rules__actions">
											<button
												type="button"
												className="fbm-button fbm-button--danger"
												onClick={ () => remove( rule.id ) }
											>
												{ fbmText( 'Delete this rule' ) }
											</button>
										</div>
									</div>
								) : null }
							</li>
						) ) }
					</ul>
				) }
			</div>

			<SaveBar dirty={ dirty } saving={ saving } onSave={ save } />
		</>
	);
}

/**
 * Renders the amount, in the unit the chosen action uses.
 */
function AmountField( {
	rule,
	actions,
	onChange,
}: {
	rule: Rule;
	actions: ActionOption[];
	onChange: ( id: string, changes: Partial< Rule > ) => void;
} ): JSX.Element {
	const unit = actions.find( ( a ) => a.value === rule.action )?.unit ?? '%';
	const isMoney = unit === 'money';

	return (
		<NumberField
			label={ fbmText( 'Amount' ) }
			name={ `rule_amount_${ rule.id }` }
			value={ isMoney ? fbmToMajor( rule.amount ) : rule.amount }
			min={ 0 }
			step={ 0.01 }
			suffix={ isMoney ? undefined : '%' }
			onChange={ ( value ) => onChange( rule.id, { amount: isMoney ? fbmToMinor( value ) : value } ) }
		/>
	);
}

/**
 * Renders one condition in the control its type calls for.
 */
function ConditionField( {
	rule,
	condition,
	onChange,
}: {
	rule: Rule;
	condition: ConditionDefinition;
	onChange: ( id: string, key: string, value: unknown ) => void;
} ): JSX.Element {
	const raw = rule.conditions[ condition.key ];

	if ( condition.type === 'weekdays' || condition.type === 'routes' ) {
		const selected = Array.isArray( raw ) ? raw.map( String ) : [];

		return (
			<div className="fbm-settings__wide">
				<fieldset className="fbm-choices">
					<legend className="fbm-choices__legend">{ condition.label }</legend>
					<div className="fbm-choices__options">
						{ ( condition.options ?? [] ).map( ( option ) => {
							const value = String( option.value );
							const checked = selected.includes( value );

							return (
								<label className={ `fbm-choice${ checked ? ' is-on' : '' }` } key={ value }>
									<input
										type="checkbox"
										checked={ checked }
										onChange={ () =>
											onChange(
												rule.id,
												condition.key,
												checked
													? selected.filter( ( v ) => v !== value ).map( Number )
													: [ ...selected, value ].map( Number )
											)
										}
									/>
									<span>{ option.label }</span>
								</label>
							);
						} ) }
					</div>
				</fieldset>
			</div>
		);
	}

	if ( condition.type === 'number' || condition.type === 'percent' ) {
		return (
			<NumberField
				label={ condition.label }
				name={ `${ rule.id }_${ condition.key }` }
				value={ typeof raw === 'number' ? raw : 0 }
				min={ 0 }
				max={ condition.type === 'percent' ? 100 : undefined }
				suffix={ condition.type === 'percent' ? '%' : undefined }
				onChange={ ( value ) => onChange( rule.id, condition.key, value ) }
			/>
		);
	}

	return (
		<TextField
			label={ condition.label }
			name={ `${ rule.id }_${ condition.key }` }
			type={ condition.type === 'time' ? 'time' : 'date' }
			value={ typeof raw === 'string' ? raw : '' }
			onChange={ ( value ) => onChange( rule.id, condition.key, value ) }
		/>
	);
}

/**
 * Substitutes the value into a sentence fragment.
 *
 * A literal percent sign is doubled in these templates, because they are printf
 * formats on the server side too. Unescaping it here is what stops "80%% full"
 * reaching the screen.
 */
function fill( template: string, value: string ): string {
	return template.replace( '%s', value ).replace( /%%/g, '%' );
}

/**
 * Describes a rule in one sentence, for the collapsed list.
 *
 * Assembled from fragments the server supplies rather than glued together here,
 * so the sentence can be translated as a sentence. A German operator should not
 * be reading English word order with German words in it.
 */
function describe( rule: Rule, actions: ActionOption[], conditions: ConditionDefinition[] ): string {
	const action = actions.find( ( a ) => a.value === rule.action );
	const amount = action?.unit === 'money' ? fbmFormatMoney( rule.amount ) : `${ rule.amount }%`;
	const what = action?.verb ? fill( action.verb, amount ) : `${ action?.label ?? rule.action } ${ amount }`;

	const clauses: string[] = [];

	conditions.forEach( ( condition ) => {
		const value = rule.conditions[ condition.key ];

		if ( value === undefined || value === '' || ( Array.isArray( value ) && value.length === 0 ) ) {
			return;
		}

		const shown = Array.isArray( value )
			? value
					.map( ( item ) => condition.options?.find( ( o ) => String( o.value ) === String( item ) )?.label ?? String( item ) )
					.join( ', ' )
			: String( value );

		clauses.push( condition.summary ? fill( condition.summary, shown ) : `${ condition.label }: ${ shown }` );
	} );

	if ( clauses.length === 0 ) {
		return fbmFormat( '%s, on every crossing', what );
	}

	return fbmFormat( '%1$s, when %2$s', what, clauses.join( ' · ' ) );
}
