/**
 * A listbox the dashboard positions itself.
 *
 * Chrome places a native `<select>` popup so the *selected* row sits over the
 * control, which on a list of two hundred sailings means the panel opens
 * upward across the page — over the heading, the filters and the stats. A page
 * gets no say in it. Staff read it as the screen having broken.
 *
 * So the panel is ours: it opens directly under the control it belongs to, and
 * flips above only when there is genuinely no room below.
 *
 * It is also rendered outside the card it belongs to. Panels, tables and the
 * drawer all clip their own overflow, and an absolutely-positioned list inside
 * one is cut off at the card's edge — a two-hundred-sailing dropdown showed as
 * a single visible row with a scrollbar. So the list is fixed-positioned and
 * portalled up to the application root, which clips nothing, and follows its
 * control on scroll and resize. It stays inside that root rather than going to
 * the body because every style in this dashboard is scoped to it.
 *
 * The trade-off is that everything the native control gave for free has to be
 * re-implemented — the roving-focus keyboard model, type-ahead, and the
 * `aria-activedescendant` wiring screen readers rely on. That is done once,
 * here, and every dropdown in the dashboard uses it.
 */

import { useCallback, useEffect, useId, useMemo, useRef, useState, type JSX } from 'react';
import { createPortal } from 'react-dom';

import { Icon } from './Icon';
import { fbmText } from '../lib/i18n';

export interface ListboxOption {
	value: string | number;
	label: string;
	disabled?: boolean;
}

export interface ListboxProps {
	id?: string;
	name?: string;
	value: string | number;
	options: ListboxOption[];
	onChange: ( value: string ) => void;
	/** First row, offered as "no choice yet". */
	placeholder?: string;
	disabled?: boolean;
	/** Shorter control, for a filter bar or a pager. */
	compact?: boolean;
	/** Accessible label where the control has no visible one. */
	ariaLabel?: string;
	describedBy?: string;
	invalid?: boolean;
}

/**
 * Renders the dropdown.
 */
export function Listbox( {
	id,
	name,
	value,
	options,
	onChange,
	placeholder,
	disabled,
	compact,
	ariaLabel,
	describedBy,
	invalid,
}: ListboxProps ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ anchor, setAnchor ] = useState< {
		left: number;
		width: number;
		top?: number;
		bottom?: number;
		maxHeight: number;
	} | null >( null );
	const rootRef = useRef< HTMLDivElement | null >( null );
	const buttonRef = useRef< HTMLButtonElement | null >( null );
	const listRef = useRef< HTMLDivElement | null >( null );
	const typed = useRef( { text: '', at: 0 } );

	const rows = useMemo(
		() => ( placeholder !== undefined ? [ { value: '', label: placeholder }, ...options ] : options ),
		[ options, placeholder ]
	);

	const selected = rows.findIndex( ( row ) => String( row.value ) === String( value ) );

	/*
	 * Unique per instance, not derived from the field name. A filter bar and a
	 * pager pass neither an id nor a name, so every one of them produced the
	 * same element id — which made `aria-controls` point at somebody else's
	 * list, and any code looking the panel up by id find the wrong one.
	 */
	const listId = `${ useId() }-listbox`;

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

	/*
	 * Works out where the panel goes from the room actually available, and caps
	 * its height to that room. Choosing a side without checking whether the
	 * panel fits there is how a list ends up running off the top of the window.
	 */
	const measure = useCallback( (): void => {
		const box = buttonRef.current?.getBoundingClientRect();

		if ( ! box ) {
			return;
		}

		const gap = 4;
		const margin = 8;
		const tallest = 272;
		const below = window.innerHeight - box.bottom - gap - margin;
		const above = box.top - gap - margin;

		// Below unless above is both roomier and actually needed.
		const flip = below < Math.min( tallest, above ) && above > below;

		/*
		 * Capped to the room on the chosen side, with no floor. A minimum
		 * height would be a promise the window cannot keep: forcing 120px into
		 * 88px of space is exactly how the panel ended up running off the top
		 * edge. A short scrollable list in view beats a tall one half off it.
		 */
		const room = Math.max( 0, Math.min( tallest, flip ? above : below ) );

		setAnchor(
			flip
				? { left: box.left, width: box.width, bottom: window.innerHeight - box.top + gap, maxHeight: room }
				: { left: box.left, width: box.width, top: box.bottom + gap, maxHeight: room }
		);
	}, [] );

	const reveal = (): void => {
		measure();
		setActive( selected > 0 ? selected : step( placeholder !== undefined && rows.length > 1 ? 0 : -1, 1 ) );
		setOpen( true );
	};

	/*
	 * A fixed panel does not move with the page, so it is re-anchored whenever
	 * anything scrolls — captured, because the scroll usually happens on an
	 * inner container rather than the window.
	 */
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		const reanchor = (): void => measure();

		window.addEventListener( 'scroll', reanchor, true );
		window.addEventListener( 'resize', reanchor );

		return () => {
			window.removeEventListener( 'scroll', reanchor, true );
			window.removeEventListener( 'resize', reanchor );
		};
	}, [ measure, open ] );

	// Bound on click rather than mousedown, so the click that opened the panel
	// cannot immediately close it again.
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		const onDocumentClick = ( event: MouseEvent ): void => {
			const target = event.target as Node | null;

			// The panel lives outside this component's own subtree, so it has
			// to be asked separately or every click on an option would read as
			// a click elsewhere and shut the list.
			if ( target && ! rootRef.current?.contains( target ) && ! listRef.current?.contains( target ) ) {
				setOpen( false );
			}
		};

		document.addEventListener( 'click', onDocumentClick );

		return () => document.removeEventListener( 'click', onDocumentClick );
	}, [ open ] );

	// Keeps the active row in view when the keyboard is doing the moving.
	useEffect( () => {
		if ( ! open || active < 0 ) {
			return;
		}

		listRef.current?.children[ active ]?.scrollIntoView( { block: 'nearest' } );
	}, [ active, open ] );

	const onKeyDown = useCallback(
		( event: React.KeyboardEvent< HTMLElement > ): void => {
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
					// Stopped so the panel closes without also closing the
					// drawer or dialog it is sitting inside.
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
					setActive( step( -1, 1 ) );

					return;

				case 'End':
					event.preventDefault();
					setActive( step( rows.length, -1 ) );

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

			// Type-ahead: the one affordance of a native select people miss
			// most, and the only way to reach the 19:00 sailing in a list of
			// two hundred without scrolling.
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
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ active, open, rows, selected ]
	);

	const label = selected >= 0 ? rows[ selected ]?.label ?? '' : placeholder ?? '';

	return (
		<div className={ `fbm-listbox${ open ? ' is-open' : '' }${ compact ? ' fbm-listbox--compact' : '' }` } ref={ rootRef }>
			<button
				type="button"
				id={ id }
				ref={ buttonRef }
				className="fbm-input fbm-listbox__control"
				disabled={ disabled }
				role="combobox"
				aria-haspopup="listbox"
				aria-expanded={ open }
				aria-controls={ listId }
				aria-label={ ariaLabel }
				// Kept addressable by field name now the element id is generated.
				data-fbm-listbox={ name }
				aria-describedby={ describedBy }
				aria-invalid={ invalid || undefined }
				aria-activedescendant={ open && active >= 0 ? `${ listId }-${ active }` : undefined }
				onClick={ () => ( open ? setOpen( false ) : reveal() ) }
				onKeyDown={ onKeyDown }
			>
				<span className={ `fbm-listbox__value${ selected > 0 || placeholder === undefined ? '' : ' is-placeholder' }` }>
					{ label }
				</span>
				<Icon name="chevron" size={ 14 } />
			</button>

			{ open && anchor
				? createPortal(
						<div
							className="fbm-listbox__panel"
							id={ listId }
							role="listbox"
							ref={ listRef }
							tabIndex={ -1 }
							style={ {
								left: `${ anchor.left }px`,
								minWidth: `${ anchor.width }px`,
								maxHeight: `${ anchor.maxHeight }px`,
								...( anchor.top !== undefined ? { top: `${ anchor.top }px` } : {} ),
								...( anchor.bottom !== undefined ? { bottom: `${ anchor.bottom }px` } : {} ),
							} }
						>
					{ rows.map( ( row, index ) => (
						<div
							key={ `${ row.value }-${ index }` }
							id={ `${ listId }-${ index }` }
							role="option"
							aria-selected={ index === selected }
							aria-disabled={ row.disabled ? true : undefined }
							className={ [
								'fbm-listbox__option',
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

							{ rows.length === 0 ? (
								<p className="fbm-listbox__empty">{ fbmText( 'Nothing to choose from.' ) }</p>
							) : null }
						</div>,
						buttonRef.current?.closest( '.fbm-app' ) ?? document.body
				  )
				: null }
		</div>
	);
}
