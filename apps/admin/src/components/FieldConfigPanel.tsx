/**
 * Capture-field configuration panel.
 *
 * Lets an operator decide which details a booking collects about each traveller
 * or vehicle. Every field has three states rather than a checkbox, because
 * "collected but optional" and "not collected at all" are genuinely different
 * things on a manifest, and a two-state control forces operators to pick the
 * wrong one.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { Listbox } from './Listbox';

import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmFormat, fbmText } from '../lib/i18n';
import { useFbmToast } from '../components/Toast';
import { Icon } from './Icon';
import { fbmFieldSummary, SaveBar } from './SaveBar';

export interface FbmConfigurableField {
	key: string;
	label: string;
	type: string;
	mode: string;
	locked: boolean;
	custom: boolean;
	hint: string;
}

interface FieldConfigResponse {
	group: string;
	modes: string[];
	fields: FbmConfigurableField[];
}

export interface FieldConfigPanelProps {
	group: 'passenger' | 'vehicle';
	title: string;
	description: string;
}

const MODE_LABELS: Record< string, string > = {
	off: 'Not collected',
	optional: 'Optional',
	required: 'Required',
};

const CUSTOM_TYPES = [ 'text', 'textarea', 'number', 'date', 'email', 'tel', 'switch' ];

/**
 * Renders the field matrix for one group.
 */
export function FieldConfigPanel( { group, title, description }: FieldConfigPanelProps ): JSX.Element {
	const toast = useFbmToast();
	const [ fields, setFields ] = useState< FbmConfigurableField[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ dirty, setDirty ] = useState( false );
	const [ newLabel, setNewLabel ] = useState( '' );
	const [ newType, setNewType ] = useState( 'text' );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );

		try {
			const response = await fbmRequest< FieldConfigResponse >( `field-config/${ group }` );
			setFields( response.data.fields );
			setDirty( false );
		} catch ( caught: unknown ) {
			setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		} finally {
			setLoading( false );
		}
	}, [ group ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	const setMode = useCallback( ( key: string, mode: string ) => {
		setFields( ( current ) => current.map( ( field ) => ( field.key === key ? { ...field, mode } : field ) ) );
		setDirty( true );
	}, [] );

	const removeCustom = useCallback( ( key: string ) => {
		setFields( ( current ) => current.filter( ( field ) => field.key !== key ) );
		setDirty( true );
	}, [] );

	const addCustom = useCallback( () => {
		const label = newLabel.trim();

		if ( label === '' ) {
			return;
		}

		setFields( ( current ) => [
			...current,
			{ key: '', label, type: newType, mode: 'optional', locked: false, custom: true, hint: '' },
		] );
		setNewLabel( '' );
		setNewType( 'text' );
		setDirty( true );
	}, [ newLabel, newType ] );

	const save = useCallback( async () => {
		setSaving( true );

		const modes: Record< string, string > = {};
		const custom: Array< { key: string; label: string; type: string; mode: string } > = [];

		fields.forEach( ( field ) => {
			if ( field.custom ) {
				custom.push( { key: field.key, label: field.label, type: field.type, mode: field.mode } );
			} else if ( ! field.locked ) {
				modes[ field.key ] = field.mode;
			}
		} );

		try {
			const response = await fbmRequest< FieldConfigResponse >( `field-config/${ group }`, {
				method: 'PUT',
				body: { modes, custom },
			} );

			setFields( response.data.fields );
			setDirty( false );
			toast.notify( fbmText( 'Booking form saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ fields, group, toast ] );

	const summary = useMemo(
		() =>
			fbmFieldSummary(
				fields.filter( ( field ) => field.mode === 'required' ).length,
				fields.filter( ( field ) => field.mode === 'optional' ).length
			),
		[ fields ]
	);

	return (
		<>
		<div className="fbm-panel">
			<div className="fbm-panel__header">
				<div>
					<h2 className="fbm-panel__title">{ fbmText( title ) }</h2>
					<p className="fbm-panel__description">{ fbmText( description ) }</p>
				</div>
			</div>

			{ error ? (
				<div className="fbm-alert fbm-alert--error" role="alert">
					{ error }
				</div>
			) : null }

			{ loading ? (
				<div className="fbm-fieldgrid" aria-hidden="true">
					{ [ 0, 1, 2, 3, 4, 5 ].map( ( index ) => (
						<div key={ index } className="fbm-fieldrow fbm-fieldrow--skeleton">
							<span className="fbm-skeleton fbm-skeleton--text" />
						</div>
					) ) }
				</div>
			) : (
				<div className="fbm-fieldgrid">
					{ fields.map( ( field ) => (
						<div className="fbm-fieldrow" key={ field.key || field.label }>
							<div className="fbm-fieldrow__label">
								<span className="fbm-fieldrow__name">{ field.label }</span>
								{ field.custom ? <span className="fbm-pill fbm-pill--muted">{ fbmText( 'Custom' ) }</span> : null }
								{ field.hint ? <span className="fbm-fieldrow__hint">{ field.hint }</span> : null }
							</div>

							<div className="fbm-segmented" role="group" aria-label={ field.label }>
								{ Object.keys( MODE_LABELS ).map( ( mode ) => (
									<button
										key={ mode }
										type="button"
										className={ `fbm-segmented__option${ field.mode === mode ? ' is-selected' : '' }` }
										aria-pressed={ field.mode === mode }
										disabled={ field.locked }
										onClick={ () => setMode( field.key, mode ) }
									>
										{ fbmText( MODE_LABELS[ mode ] ?? mode ) }
									</button>
								) ) }
							</div>

							{ field.custom ? (
								<button
									type="button"
									className="fbm-iconbutton fbm-iconbutton--danger"
									onClick={ () => removeCustom( field.key ) }
									aria-label={ fbmFormat( 'Remove %s', field.label ) }
								>
									<Icon name="trash" />
								</button>
							) : (
								<span className="fbm-fieldrow__spacer" aria-hidden="true" />
							) }
						</div>
					) ) }
				</div>
			) }

			<div className="fbm-fieldadd">
				<label className="fbm-fieldadd__label" htmlFor={ `fbm-custom-${ group }` }>
					{ fbmText( 'Add a custom field' ) }
				</label>
				<div className="fbm-fieldadd__row">
					<input
						id={ `fbm-custom-${ group }` }
						type="text"
						className="fbm-input"
						value={ newLabel }
						placeholder={ fbmText( 'Field label' ) }
						onChange={ ( event ) => setNewLabel( event.target.value ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
								addCustom();
							}
						} }
					/>
					<Listbox
						value={ newType }
						options={ CUSTOM_TYPES.map( ( type ) => ( { value: type, label: fbmText( type ) } ) ) }
						ariaLabel={ fbmText( 'Field type' ) }
						onChange={ setNewType }
					/>
					<button type="button" className="fbm-button fbm-button--secondary" onClick={ addCustom } disabled={ newLabel.trim() === '' }>
						{ fbmText( 'Add field' ) }
					</button>
				</div>
			</div>
		</div>

		<SaveBar dirty={ dirty } saving={ saving } onSave={ save } onReset={ load } summary={ summary } />
		</>
	);
}
