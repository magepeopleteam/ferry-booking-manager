/**
 * Booking design system.
 *
 * A small, deliberately plain set of components. They inherit the theme's font
 * and use their own colour tokens, so the booking form looks like part of the
 * site it is embedded in rather than an iframe from another decade — while
 * still being immune to a theme that styles every `input` on the page.
 */

import type { ComponentChildren, JSX } from 'preact';
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';

import { t } from '../lib/i18n';

export function Button( {
	children,
	onClick,
	variant = 'primary',
	type = 'button',
	disabled,
	block,
	size,
	ariaLabel,
}: {
	children: ComponentChildren;
	onClick?: () => void;
	variant?: 'primary' | 'secondary' | 'ghost';
	type?: 'button' | 'submit';
	disabled?: boolean;
	block?: boolean;
	size?: 'sm' | 'md' | 'lg';
	ariaLabel?: string;
} ): JSX.Element {
	return (
		<button
			type={ type }
			className={ [
				'fbmb-button',
				`fbmb-button--${ variant }`,
				size ? `fbmb-button--${ size }` : '',
				block ? 'fbmb-button--block' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			onClick={ onClick }
			disabled={ disabled }
			aria-label={ ariaLabel }
		>
			{ children }
		</button>
	);
}

export function Field( {
	label,
	htmlFor,
	error,
	hint,
	required,
	children,
}: {
	label: string;
	htmlFor?: string;
	error?: string;
	hint?: string;
	required?: boolean;
	children: ComponentChildren;
} ): JSX.Element {
	return (
		<div className={ `fbmb-field${ error ? ' is-invalid' : '' }` }>
			<label className="fbmb-field__label" htmlFor={ htmlFor }>
				{ label }
				{ required ? (
					<span className="fbmb-field__required" aria-hidden="true">
						*
					</span>
				) : null }
			</label>
			{ children }
			{ error ? (
				<p className="fbmb-field__error" role="alert">
					{ error }
				</p>
			) : hint ? (
				<p className="fbmb-field__hint">{ hint }</p>
			) : null }
		</div>
	);
}

/*
 * A listbox rather than a native `<select>`.
 *
 * The native control was the obvious choice and it had to go. Chrome places a
 * `<select>` popup so that the *selected* row sits over the control, which on a
 * form whose first row is a placeholder means the list opens upwards, covering
 * the field's own label and whatever sits above it — and the browser gives a
 * page no say in that. Customers read it as the form having broken. Owning the
 * panel means it opens where a dropdown is expected: directly under the control
 * it belongs to, flipping above only when there is genuinely no room below.
 *
 * The trade-off is that the OS picker on a phone is lost, so everything it gave
 * for free has to be re-implemented here: the roving-focus keyboard model,
 * type-ahead, and the `aria-activedescendant` wiring screen readers rely on.
 */
export function Select( {
	id,
	value,
	options,
	onChange,
	placeholder,
	disabled,
}: {
	id?: string;
	value: string | number;
	options: Array< { value: string | number; label: string; disabled?: boolean } >;
	onChange: ( value: string ) => void;
	placeholder?: string;
	disabled?: boolean;
} ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ flip, setFlip ] = useState( false );
	const rootRef = useRef< HTMLDivElement | null >( null );
	const buttonRef = useRef< HTMLButtonElement | null >( null );
	const listRef = useRef< HTMLDivElement | null >( null );
	const typed = useRef( { text: '', at: 0 } );

	// The placeholder is a row of the list rather than a separate control, so
	// "none of them" stays reachable by keyboard and clearable by mouse.
	const rows = useMemo(
		() => ( placeholder ? [ { value: '', label: placeholder, disabled: false }, ...options ] : options ),
		[ options, placeholder ]
	);

	const selected = rows.findIndex( ( row ) => String( row.value ) === String( value ) );
	const listId = `${ id ?? 'fbmb-select' }-listbox`;

	const commit = ( index: number ): void => {
		const row = rows[ index ];

		if ( ! row || row.disabled ) {
			return;
		}

		onChange( String( row.value ) );
		setOpen( false );
		buttonRef.current?.focus();
	};

	const step = ( from: number, delta: number ): number => {
		let next = from;

		for ( let i = 0; i < rows.length; i++ ) {
			next += delta;

			if ( next < 0 || next >= rows.length ) {
				return from;
			}

			if ( ! rows[ next ]?.disabled ) {
				return next;
			}
		}

		return from;
	};

	const edge = ( delta: number ): number => {
		const from = delta > 0 ? -1 : rows.length;

		return step( from, delta );
	};

	/*
	 * There is no room below on a form near the bottom of the window, and a
	 * panel that runs off the fold is no better than the one this replaced.
	 * The side is decided once per opening: re-deciding it while the customer
	 * moves through the list would make it jump under the pointer.
	 */
	const reveal = (): void => {
		const box = buttonRef.current?.getBoundingClientRect();

		setFlip( !! box && window.innerHeight - box.bottom < 260 && box.top > window.innerHeight - box.bottom );

		// With nothing chosen the highlight starts on the first real option
		// rather than the placeholder: it is the row a customer opened the list
		// to reach, and Enter should land on a port, not on "none of them".
		setActive( selected > 0 ? selected : step( placeholder && rows.length > 1 ? 0 : -1, 1 ) );
		setOpen( true );
	};

	// A click anywhere else closes the panel. Bound on click rather than
	// mousedown so the very click that opened it cannot close it again.
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		const onDocumentClick = ( event: MouseEvent ): void => {
			const target = event.target as Node | null;

			if ( target && ! rootRef.current?.contains( target ) ) {
				setOpen( false );
			}
		};

		document.addEventListener( 'click', onDocumentClick );

		return () => document.removeEventListener( 'click', onDocumentClick );
	}, [ open ] );

	// Keeps the active row in view when the keyboard, not the pointer, is
	// moving through a list longer than the panel.
	useEffect( () => {
		if ( ! open || active < 0 ) {
			return;
		}

		listRef.current?.children[ active ]?.scrollIntoView( { block: 'nearest' } );
	}, [ active, open ] );

	const onKeyDown = ( event: JSX.TargetedKeyboardEvent< HTMLElement > ): void => {
		const key = event.key;

		if ( ! open ) {
			if ( key === 'ArrowDown' || key === 'ArrowUp' || key === 'Enter' || key === ' ' ) {
				event.preventDefault();
				reveal();
			}

			return;
		}

		switch ( key ) {
			case 'Escape':
				event.preventDefault();
				// Stopped so the panel closes without also closing whatever
				// popover or dialog the form happens to be sitting inside.
				event.stopPropagation();
				setOpen( false );
				buttonRef.current?.focus();

				return;

			case 'ArrowDown':
				event.preventDefault();
				setActive( ( current ) => step( current, 1 ) );

				return;

			case 'ArrowUp':
				event.preventDefault();
				setActive( ( current ) => step( current, -1 ) );

				return;

			case 'Home':
				event.preventDefault();
				setActive( edge( 1 ) );

				return;

			case 'End':
				event.preventDefault();
				setActive( edge( -1 ) );

				return;

			case 'Enter':
			case ' ':
				event.preventDefault();
				commit( active );

				return;

			case 'Tab':
				setOpen( false );

				return;

			default:
				break;
		}

		// Type-ahead: the one affordance of a native select people miss most.
		// Consecutive keystrokes within the pause build up a prefix, so "st"
		// reaches St. George rather than stopping at the first S.
		if ( key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey ) {
			return;
		}

		const now = Date.now();

		typed.current = {
			text: ( now - typed.current.at < 700 ? typed.current.text : '' ) + key.toLowerCase(),
			at: now,
		};

		const match = rows.findIndex(
			( row ) => ! row.disabled && row.label.toLowerCase().startsWith( typed.current.text )
		);

		if ( match >= 0 ) {
			setActive( match );
		}
	};

	const label = selected >= 0 ? rows[ selected ]?.label ?? '' : placeholder ?? '';

	return (
		<div className={ `fbmb-select${ open ? ' is-open' : '' }` } ref={ rootRef }>
			<button
				type="button"
				id={ id }
				ref={ buttonRef }
				className="fbmb-select__control"
				disabled={ disabled }
				role="combobox"
				aria-haspopup="listbox"
				aria-expanded={ open }
				aria-controls={ listId }
				aria-activedescendant={ open && active >= 0 ? `${ listId }-${ active }` : undefined }
				onClick={ () => ( open ? setOpen( false ) : reveal() ) }
				onKeyDown={ onKeyDown }
			>
				<span className={ `fbmb-select__value${ selected > 0 || ! placeholder ? '' : ' is-placeholder' }` }>
					{ label }
				</span>
			</button>
			<svg className="fbmb-select__arrow" viewBox="0 0 20 20" aria-hidden="true">
				<path d="M5 8l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
			</svg>

			{ open ? (
				<div
					className={ `fbmb-select__panel${ flip ? ' is-above' : '' }` }
					id={ listId }
					role="listbox"
					ref={ listRef }
					tabIndex={ -1 }
				>
					{ rows.map( ( row, index ) => (
						<div
							key={ row.value }
							id={ `${ listId }-${ index }` }
							role="option"
							aria-selected={ index === selected }
							aria-disabled={ row.disabled ? true : undefined }
							className={ [
								'fbmb-select__option',
								index === active ? 'is-active' : '',
								index === selected ? 'is-selected' : '',
								row.disabled ? 'is-disabled' : '',
							]
								.filter( Boolean )
								.join( ' ' ) }
							onClick={ () => commit( index ) }
							onMouseEnter={ () => ( row.disabled ? undefined : setActive( index ) ) }
						>
							{ row.label }
						</div>
					) ) }
				</div>
			) : null }
		</div>
	);
}

export function Input( {
	id,
	value,
	onChange,
	type = 'text',
	placeholder,
	min,
	max,
	step,
	inputMode,
	autoComplete,
	required,
}: {
	id?: string;
	value: string;
	onChange: ( value: string ) => void;
	type?: string;
	placeholder?: string;
	min?: string | number;
	max?: string | number;
	step?: number;
	inputMode?: string;
	autoComplete?: string;
	required?: boolean;
} ): JSX.Element {
	return (
		<input
			id={ id }
			className="fbmb-input"
			type={ type }
			value={ value }
			placeholder={ placeholder }
			min={ min }
			max={ max }
			step={ step }
			inputMode={ inputMode as never }
			autoComplete={ autoComplete }
			required={ required }
			onInput={ ( event ) => onChange( ( event.target as HTMLInputElement ).value ) }
		/>
	);
}

export function Stepper( {
	value,
	min = 0,
	// No ceiling unless the caller sets one. A number here would be an
	// invisible limit that nothing in the product had asked for.
	max = Infinity,
	onChange,
	label,
}: {
	value: number;
	min?: number;
	max?: number;
	onChange: ( value: number ) => void;
	label: string;
} ): JSX.Element {
	const clamp = ( next: number ): number => Math.max( min, Math.min( max, next ) );

	return (
		<div className="fbmb-stepper" role="group" aria-label={ label }>
			<button
				type="button"
				className="fbmb-stepper__button"
				onClick={ () => onChange( clamp( value - 1 ) ) }
				disabled={ value <= min }
				aria-label={ `${ label } −` }
			>
				<svg viewBox="0 0 20 20" aria-hidden="true">
					<path d="M5 10h10" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
				</svg>
			</button>
			<span
				className="fbmb-stepper__value"
				role="status"
				aria-live="polite"
				aria-valuenow={ value }
				aria-valuemin={ min }
				aria-valuemax={ Number.isFinite( max ) ? max : undefined }
			>
				{ value }
			</span>
			<button
				type="button"
				className="fbmb-stepper__button"
				onClick={ () => onChange( clamp( value + 1 ) ) }
				disabled={ value >= max }
				aria-label={ `${ label } +` }
			>
				<svg viewBox="0 0 20 20" aria-hidden="true">
					<path d="M10 5v10M5 10h10" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
				</svg>
			</button>
		</div>
	);
}

export function Badge( {
	children,
	tone = 'neutral',
}: {
	children: ComponentChildren;
	tone?: 'neutral' | 'positive' | 'warning' | 'danger' | 'accent';
} ): JSX.Element {
	return <span className={ `fbmb-badge fbmb-badge--${ tone }` }>{ children }</span>;
}

export function Alert( {
	children,
	tone = 'error',
}: {
	children: ComponentChildren;
	tone?: 'error' | 'info' | 'success' | 'warning';
} ): JSX.Element {
	return (
		<div className={ `fbmb-alert fbmb-alert--${ tone }` } role={ tone === 'error' ? 'alert' : 'status' }>
			{ children }
		</div>
	);
}

export function Card( { children, onClick, selected }: { children: ComponentChildren; onClick?: () => void; selected?: boolean } ): JSX.Element {
	return (
		<div className={ `fbmb-card${ selected ? ' is-selected' : '' }${ onClick ? ' is-clickable' : '' }` } onClick={ onClick }>
			{ children }
		</div>
	);
}

export function Skeleton( { rows = 3 }: { rows?: number } ): JSX.Element {
	return (
		<div className="fbmb-skeletons" aria-hidden="true">
			{ Array.from( { length: rows } ).map( ( _, index ) => (
				<div key={ index } className="fbmb-skeleton" />
			) ) }
		</div>
	);
}

export function Empty( { title, description }: { title: string; description?: string } ): JSX.Element {
	return (
		<div className="fbmb-empty">
			<svg viewBox="0 0 48 48" aria-hidden="true" className="fbmb-empty__icon">
				<path
					d="M6 30h36l-4 10H10L6 30Zm6-12h24l3 10H9l3-10Zm6-10h12l2 8H16l2-8Z"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinejoin="round"
				/>
			</svg>
			<p className="fbmb-empty__title">{ title }</p>
			{ description ? <p className="fbmb-empty__description">{ description }</p> : null }
		</div>
	);
}

export function ErrorState( { message, onRetry }: { message: string; onRetry?: () => void } ): JSX.Element {
	return (
		<div className="fbmb-empty">
			<p className="fbmb-empty__title">{ message }</p>
			{ onRetry ? (
				<Button variant="secondary" onClick={ onRetry }>
					{ t( 'Try again' ) }
				</Button>
			) : null }
		</div>
	);
}
