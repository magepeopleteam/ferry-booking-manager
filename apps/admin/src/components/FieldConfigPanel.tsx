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

import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { useMpfbsToast } from '../components/Toast';
import { Icon } from './Icon';
import { mpfbsFieldSummary, SaveBar } from './SaveBar';

export interface MpfbsConfigurableField {
	key: string;
	label: string;
	type: string;
	mode: string;
	always_on: boolean;
	custom: boolean;
	hint: string;
}

interface FieldConfigResponse {
	group: string;
	modes: string[];
	fields: MpfbsConfigurableField[];
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
	const toast = useMpfbsToast();
	const [ fields, setFields ] = useState< MpfbsConfigurableField[] >( [] );
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
			const response = await mpfbsRequest< FieldConfigResponse >( `field-config/${ group }` );
			setFields( response.data.fields );
			setDirty( false );
		} catch ( caught: unknown ) {
			setError( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ) );
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
			{ key: '', label, type: newType, mode: 'optional', always_on: false, custom: true, hint: '' },
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
			} else if ( ! field.always_on ) {
				modes[ field.key ] = field.mode;
			}
		} );

		try {
			const response = await mpfbsRequest< FieldConfigResponse >( `field-config/${ group }`, {
				method: 'PUT',
				body: { modes, custom },
			} );

			setFields( response.data.fields );
			setDirty( false );
			toast.notify( mpfbsText( 'Booking form saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ fields, group, toast ] );

	const summary = useMemo(
		() =>
			mpfbsFieldSummary(
				fields.filter( ( field ) => field.mode === 'required' ).length,
				fields.filter( ( field ) => field.mode === 'optional' ).length
			),
		[ fields ]
	);

	return (
		<>
		<div className="mpfbs-panel">
			<div className="mpfbs-panel__header">
				<div>
					<h2 className="mpfbs-panel__title">{ mpfbsText( title ) }</h2>
					<p className="mpfbs-panel__description">{ mpfbsText( description ) }</p>
				</div>
			</div>

			{ error ? (
				<div className="mpfbs-alert mpfbs-alert--error" role="alert">
					{ error }
				</div>
			) : null }

			{ loading ? (
				<div className="mpfbs-fieldgrid" aria-hidden="true">
					{ [ 0, 1, 2, 3, 4, 5 ].map( ( index ) => (
						<div key={ index } className="mpfbs-fieldrow mpfbs-fieldrow--skeleton">
							<span className="mpfbs-skeleton mpfbs-skeleton--text" />
						</div>
					) ) }
				</div>
			) : (
				<div className="mpfbs-fieldgrid">
					{ fields.map( ( field ) => (
						<div className="mpfbs-fieldrow" key={ field.key || field.label }>
							<div className="mpfbs-fieldrow__label">
								<span className="mpfbs-fieldrow__name">{ field.label }</span>
								{ field.custom ? <span className="mpfbs-pill mpfbs-pill--muted">{ mpfbsText( 'Custom' ) }</span> : null }
								{ field.hint ? <span className="mpfbs-fieldrow__hint">{ field.hint }</span> : null }
							</div>

							<div className="mpfbs-segmented" role="group" aria-label={ field.label }>
								{ Object.keys( MODE_LABELS ).map( ( mode ) => (
									<button
										key={ mode }
										type="button"
										className={ `mpfbs-segmented__option${ field.mode === mode ? ' is-selected' : '' }` }
										aria-pressed={ field.mode === mode }
										disabled={ field.always_on }
										onClick={ () => setMode( field.key, mode ) }
									>
										{ mpfbsText( MODE_LABELS[ mode ] ?? mode ) }
									</button>
								) ) }
							</div>

							{ field.custom ? (
								<button
									type="button"
									className="mpfbs-iconbutton mpfbs-iconbutton--danger"
									onClick={ () => removeCustom( field.key ) }
									aria-label={ mpfbsFormat( 'Remove %s', field.label ) }
								>
									<Icon name="trash" />
								</button>
							) : (
								<span className="mpfbs-fieldrow__spacer" aria-hidden="true" />
							) }
						</div>
					) ) }
				</div>
			) }

			<div className="mpfbs-fieldadd">
				<label className="mpfbs-fieldadd__label" htmlFor={ `mpfbs-custom-${ group }` }>
					{ mpfbsText( 'Add a custom field' ) }
				</label>
				<div className="mpfbs-fieldadd__row">
					<input
						id={ `mpfbs-custom-${ group }` }
						type="text"
						className="mpfbs-input"
						value={ newLabel }
						placeholder={ mpfbsText( 'Field label' ) }
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
						options={ CUSTOM_TYPES.map( ( type ) => ( { value: type, label: mpfbsText( type ) } ) ) }
						ariaLabel={ mpfbsText( 'Field type' ) }
						onChange={ setNewType }
					/>
					<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ addCustom } disabled={ newLabel.trim() === '' }>
						{ mpfbsText( 'Add field' ) }
					</button>
				</div>
			</div>
		</div>

		<SaveBar dirty={ dirty } saving={ saving } onSave={ save } onReset={ load } summary={ summary } />
		</>
	);
}
