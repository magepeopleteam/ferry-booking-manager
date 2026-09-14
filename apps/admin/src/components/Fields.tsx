/**
 * Form controls.
 *
 * Every control is label-associated, reports its own error through
 * aria-describedby, and marks itself invalid so screen readers announce the
 * failure rather than leaving the message as decoration.
 */

import { useId, type JSX, type ReactNode } from 'react';
import { Listbox } from './Listbox';

import { fbmText } from '../lib/i18n';

export interface FieldShellProps {
	label: string;
	/** Field name, exposed as data-fbm-field so validation can move focus here. */
	name?: string;
	error?: string;
	hint?: string;
	required?: boolean;
	children: ( props: { id: string; describedBy?: string; invalid: boolean } ) => ReactNode;
}

/**
 * Wraps a control with its label, hint and error message.
 */
export function FieldShell( { label, name, error, hint, required, children }: FieldShellProps ): JSX.Element {
	const id = useId();
	const errorId = `${ id }-error`;
	const hintId = `${ id }-hint`;
	const describedBy = [ error ? errorId : null, hint ? hintId : null ].filter( Boolean ).join( ' ' ) || undefined;

	return (
		<div className={ `fbm-field${ error ? ' fbm-field--invalid' : '' }` } data-fbm-field={ name }>
			<label className="fbm-field__label" htmlFor={ id }>
				{ label }
				{ required ? (
					<span className="fbm-field__required" aria-hidden="true">
						*
					</span>
				) : null }
			</label>

			{ children( { id, describedBy, invalid: Boolean( error ) } ) }

			{ hint ? (
				<p className="fbm-field__hint" id={ hintId }>
					{ hint }
				</p>
			) : null }
			{ error ? (
				<p className="fbm-field__error" id={ errorId }>
					{ error }
				</p>
			) : null }
		</div>
	);
}

export interface TextFieldProps {
	label: string;
	name?: string;
	value: string;
	onChange: ( value: string ) => void;
	type?: 'text' | 'email' | 'tel' | 'url' | 'date' | 'time' | 'datetime-local';
	placeholder?: string;
	error?: string;
	hint?: string;
	required?: boolean;
	disabled?: boolean;
}

/**
 * Single-line text input.
 */
export function TextField( { label, name, value, onChange, type = 'text', placeholder, error, hint, required, disabled }: TextFieldProps ): JSX.Element {
	return (
		<FieldShell label={ label } name={ name } error={ error } hint={ hint } required={ required }>
			{ ( { id, describedBy, invalid } ) => (
				<input
					id={ id }
					name={ name }
					className="fbm-input"
					type={ type }
					value={ value }
					placeholder={ placeholder }
					disabled={ disabled }
					aria-describedby={ describedBy }
					aria-invalid={ invalid || undefined }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
			) }
		</FieldShell>
	);
}

export interface NumberFieldProps {
	label: string;
	name?: string;
	value: number;
	onChange: ( value: number ) => void;
	min?: number;
	max?: number;
	step?: number;
	suffix?: string;
	error?: string;
	hint?: string;
	required?: boolean;
}

/**
 * Numeric input with an optional unit suffix.
 */
export function NumberField( { label, name, value, onChange, min, max, step = 1, suffix, error, hint, required }: NumberFieldProps ): JSX.Element {
	return (
		<FieldShell label={ label } name={ name } error={ error } hint={ hint } required={ required }>
			{ ( { id, describedBy, invalid } ) => (
				<span className="fbm-input-group">
					<input
						id={ id }
						name={ name }
						className="fbm-input"
						type="number"
						value={ Number.isFinite( value ) ? value : '' }
						min={ min }
						max={ max }
						step={ step }
						aria-describedby={ describedBy }
						aria-invalid={ invalid || undefined }
						onChange={ ( event ) => onChange( event.target.value === '' ? 0 : Number( event.target.value ) ) }
					/>
					{ suffix ? <span className="fbm-input-group__suffix">{ suffix }</span> : null }
				</span>
			) }
		</FieldShell>
	);
}

export interface TextAreaFieldProps {
	label: string;
	name?: string;
	value: string;
	onChange: ( value: string ) => void;
	rows?: number;
	error?: string;
	hint?: string;
}

/**
 * Multi-line text input.
 */
export function TextAreaField( { label, name, value, onChange, rows = 3, error, hint }: TextAreaFieldProps ): JSX.Element {
	return (
		<FieldShell label={ label } name={ name } error={ error } hint={ hint }>
			{ ( { id, describedBy, invalid } ) => (
				<textarea
					id={ id }
					name={ name }
					className="fbm-input fbm-input--textarea"
					rows={ rows }
					value={ value }
					aria-describedby={ describedBy }
					aria-invalid={ invalid || undefined }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
			) }
		</FieldShell>
	);
}

export interface SelectOption {
	value: string | number;
	label: string;
	disabled?: boolean;
}

export interface SelectFieldProps {
	/** Renders the control read-only, for a viewer without edit rights. */
	disabled?: boolean;
	label: string;
	name?: string;
	value: string | number;
	options: SelectOption[];
	onChange: ( value: string ) => void;
	placeholder?: string;
	error?: string;
	hint?: string;
	required?: boolean;
}

/**
 * Single-choice select.
 */
export function SelectField( { label, name, value, options, onChange, placeholder, error, hint, required, disabled }: SelectFieldProps ): JSX.Element {
	return (
		<FieldShell label={ label } name={ name } error={ error } hint={ hint } required={ required }>
			{ ( { id, describedBy, invalid } ) => (
				<Listbox
					id={ id }
					name={ name }
					value={ value }
					options={ options }
					onChange={ onChange }
					placeholder={ placeholder }
					disabled={ disabled }
					describedBy={ describedBy }
					invalid={ invalid }
				/>
			) }
		</FieldShell>
	);
}

export interface ColorFieldProps {
	label: string;
	name?: string;
	value: string;
	onChange: ( value: string ) => void;
	hint?: string;
}

/**
 * Colour picker paired with the hex value.
 *
 * The swatch alone is not enough: an operator matching a brand has the hex code
 * written down somewhere and wants to paste it, and a native colour input
 * cannot be pasted into.
 */
export function ColorField( { label, name, value, onChange, hint }: ColorFieldProps ): JSX.Element {
	const safe = /^#[0-9a-fA-F]{6}$/.test( value ) ? value : '#000000';

	return (
		<FieldShell label={ label } name={ name } hint={ hint }>
			{ ( { id, describedBy } ) => (
				<span className="fbm-colorfield">
					<input
						id={ id }
						name={ name }
						type="color"
						className="fbm-colorfield__swatch"
						value={ safe }
						aria-describedby={ describedBy }
						onChange={ ( event ) => onChange( event.currentTarget.value ) }
					/>
					<input
						type="text"
						className="fbm-input fbm-colorfield__hex"
						value={ value }
						spellCheck={ false }
						aria-label={ `${ label } (hex)` }
						onChange={ ( event ) => onChange( event.currentTarget.value ) }
					/>
				</span>
			) }
		</FieldShell>
	);
}

export interface SwitchFieldProps {
	label: string;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	hint?: string;
}

/**
 * Boolean toggle.
 */
export function SwitchField( { label, checked, onChange, hint }: SwitchFieldProps ): JSX.Element {
	const id = useId();
	const hintId = `${ id }-hint`;

	return (
		<div className="fbm-field fbm-field--switch">
			<label className="fbm-switch" htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					className="fbm-switch__input"
					checked={ checked }
					aria-describedby={ hint ? hintId : undefined }
					onChange={ ( event ) => onChange( event.target.checked ) }
				/>
				<span className="fbm-switch__track" aria-hidden="true">
					<span className="fbm-switch__thumb" />
				</span>
				<span className="fbm-switch__label">{ label }</span>
			</label>
			{ hint ? (
				<p className="fbm-field__hint" id={ hintId }>
					{ hint }
				</p>
			) : null }
		</div>
	);
}

export interface TagsFieldProps {
	label: string;
	values: string[];
	onChange: ( values: string[] ) => void;
	placeholder?: string;
	hint?: string;
}

/**
 * Free-text tag list, committed with Enter or comma.
 */
export function TagsField( { label, values, onChange, placeholder, hint }: TagsFieldProps ): JSX.Element {
	const id = useId();

	const commit = ( raw: string ): void => {
		const value = raw.trim().replace( /,$/, '' ).trim();

		if ( value !== '' && ! values.includes( value ) ) {
			onChange( [ ...values, value ] );
		}
	};

	return (
		<div className="fbm-field">
			<label className="fbm-field__label" htmlFor={ id }>
				{ label }
			</label>

			{ values.length > 0 ? (
				<ul className="fbm-tags">
					{ values.map( ( value ) => (
						<li className="fbm-tag" key={ value }>
							{ value }
							<button
								type="button"
								className="fbm-tag__remove"
								onClick={ () => onChange( values.filter( ( item ) => item !== value ) ) }
								aria-label={ `${ fbmText( 'Remove' ) } ${ value }` }
							>
								×
							</button>
						</li>
					) ) }
				</ul>
			) : null }

			<input
				id={ id }
				className="fbm-input"
				type="text"
				placeholder={ placeholder }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' || event.key === ',' ) {
						event.preventDefault();
						commit( event.currentTarget.value );
						event.currentTarget.value = '';
					}
				} }
				onBlur={ ( event ) => {
					commit( event.currentTarget.value );
					event.currentTarget.value = '';
				} }
			/>
			{ hint ? <p className="fbm-field__hint">{ hint }</p> : null }
		</div>
	);
}

export interface MultiSelectFieldProps {
	label: string;
	values: number[];
	options: SelectOption[];
	onChange: ( values: number[] ) => void;
	hint?: string;
}

/**
 * Ordered multi-select built from add/remove rather than ctrl-click, which is
 * unusable on touch devices.
 */
export function MultiSelectField( { label, values, options, onChange, hint }: MultiSelectFieldProps ): JSX.Element {
	const id = useId();
	const available = options.filter( ( option ) => ! values.includes( Number( option.value ) ) );

	return (
		<div className="fbm-field">
			<label className="fbm-field__label" htmlFor={ id }>
				{ label }
			</label>

			{ values.length > 0 ? (
				<ol className="fbm-tags">
					{ values.map( ( value, index ) => {
						const option = options.find( ( item ) => Number( item.value ) === value );

						return (
							<li className="fbm-tag" key={ value }>
								<span className="fbm-tag__index">{ index + 1 }</span>
								{ option?.label ?? String( value ) }
								<button
									type="button"
									className="fbm-tag__remove"
									onClick={ () => onChange( values.filter( ( item ) => item !== value ) ) }
									aria-label={ `${ fbmText( 'Remove' ) } ${ option?.label ?? value }` }
								>
									×
								</button>
							</li>
						);
					} ) }
				</ol>
			) : null }

			<Listbox
				id={ id }
				value=""
				options={ available }
				placeholder={ fbmText( 'Add…' ) }
				onChange={ ( picked ) => {
					const value = Number( picked );

					if ( value > 0 ) {
						onChange( [ ...values, value ] );
					}
				} }
			/>
			{ hint ? <p className="fbm-field__hint">{ hint }</p> : null }
		</div>
	);
}

export interface WeekdayFieldProps {
	label: string;
	values: number[];
	onChange: ( values: number[] ) => void;
	startOfWeek?: number;
	error?: string;
	hint?: string;
}

const WEEKDAY_LABELS = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

/**
 * Day-of-week selector.
 *
 * Rendered as real checkboxes so it is operable and announced correctly with a
 * keyboard, and ordered from the site's configured first day of the week.
 */
export function WeekdayField( { label, values, onChange, startOfWeek = 1, error, hint }: WeekdayFieldProps ): JSX.Element {
	const order = Array.from( { length: 7 }, ( _, index ) => ( index + startOfWeek ) % 7 );

	return (
		<fieldset className={ `fbm-field fbm-fieldset${ error ? ' fbm-field--invalid' : '' }` } data-fbm-field="weekdays">
			<legend className="fbm-field__label">{ label }</legend>
			<div className="fbm-weekdays">
				{ order.map( ( day ) => {
					const checked = values.includes( day );

					return (
						<label className={ `fbm-weekday${ checked ? ' is-on' : '' }` } key={ day }>
							<input
								type="checkbox"
								className="fbm-weekday__input"
								checked={ checked }
								onChange={ () =>
									onChange( checked ? values.filter( ( value ) => value !== day ) : [ ...values, day ] )
								}
							/>
							<span aria-hidden="true">{ fbmText( WEEKDAY_LABELS[ day ] as string ).slice( 0, 2 ) }</span>
							<span className="fbm-screen-reader-text">{ fbmText( WEEKDAY_LABELS[ day ] as string ) }</span>
						</label>
					);
				} ) }
			</div>
			{ hint ? <p className="fbm-field__hint">{ hint }</p> : null }
			{ error ? <p className="fbm-field__error">{ error }</p> : null }
		</fieldset>
	);
}

export interface TimeListFieldProps {
	label: string;
	values: string[];
	onChange: ( values: string[] ) => void;
	error?: string;
	hint?: string;
}

/**
 * Departure-time list.
 *
 * Uses a native time input to add entries, so the value is always a valid
 * HH:MM and the platform supplies its own locale-appropriate picker.
 */
export function TimeListField( { label, values, onChange, error, hint }: TimeListFieldProps ): JSX.Element {
	const id = useId();
	const sorted = [ ...values ].sort();

	return (
		<div className={ `fbm-field${ error ? ' fbm-field--invalid' : '' }` } data-fbm-field="times">
			<label className="fbm-field__label" htmlFor={ id }>
				{ label }
			</label>

			{ sorted.length > 0 ? (
				<ul className="fbm-tags">
					{ sorted.map( ( time ) => (
						<li className="fbm-tag" key={ time }>
							{ time }
							<button
								type="button"
								className="fbm-tag__remove"
								onClick={ () => onChange( values.filter( ( value ) => value !== time ) ) }
								aria-label={ `${ fbmText( 'Remove' ) } ${ time }` }
							>
								×
							</button>
						</li>
					) ) }
				</ul>
			) : null }

			<input
				id={ id }
				className="fbm-input"
				type="time"
				onChange={ ( event ) => {
					const value = event.target.value;

					if ( value && ! values.includes( value ) ) {
						onChange( [ ...values, value ] );
					}

					event.target.value = '';
				} }
			/>
			{ hint ? <p className="fbm-field__hint">{ hint }</p> : null }
			{ error ? <p className="fbm-field__error">{ error }</p> : null }
		</div>
	);
}
