/**
 * Command palette.
 *
 * Opens with Ctrl/Cmd+K, filters the destinations the signed-in user can reach
 * and navigates with the keyboard alone.
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type JSX,
	type KeyboardEvent,
} from 'react';

import { Icon } from './Icon';
import { mpfbsCan } from '../lib/config';
import { mpfbsText } from '../lib/i18n';
import { MPFBS_ROUTES } from '../lib/routes';
import { mpfbsNavigate } from '../lib/router';

export interface CommandPaletteProps {
	open: boolean;
	onClose: () => void;
}

/**
 * Renders the searchable command dialog.
 */
export function CommandPalette( { open, onClose }: CommandPaletteProps ): JSX.Element | null {
	const [ query, setQuery ] = useState( '' );
	const [ activeIndex, setActiveIndex ] = useState( 0 );
	const inputRef = useRef< HTMLInputElement >( null );
	const dialogRef = useRef< HTMLDivElement >( null );
	const returnFocusTo = useRef< HTMLElement | null >( null );

	const results = useMemo( () => {
		const needle = query.trim().toLowerCase();

		return MPFBS_ROUTES.filter( ( route ) => mpfbsCan( route.capability ) ).filter( ( route ) => {
			if ( ! needle ) {
				return true;
			}

			return mpfbsText( route.label ).toLowerCase().includes( needle ) || route.path.includes( needle );
		} );
	}, [ query ] );

	useEffect( () => {
		if ( ! open ) {
			return;
		}

		returnFocusTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		setQuery( '' );
		setActiveIndex( 0 );
		inputRef.current?.focus();
	}, [ open ] );

	useEffect( () => {
		if ( open ) {
			return;
		}

		returnFocusTo.current?.focus();
	}, [ open ] );

	const choose = useCallback(
		( index: number ) => {
			const route = results[ index ];

			if ( ! route ) {
				return;
			}

			mpfbsNavigate( route.path );
			onClose();
		},
		[ results, onClose ]
	);

	const onKeyDown = useCallback(
		( event: KeyboardEvent< HTMLDivElement > ) => {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				onClose();

				return;
			}

			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				setActiveIndex( ( current ) => ( results.length ? ( current + 1 ) % results.length : 0 ) );

				return;
			}

			if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				setActiveIndex( ( current ) =>
					results.length ? ( current - 1 + results.length ) % results.length : 0
				);

				return;
			}

			if ( event.key === 'Enter' ) {
				event.preventDefault();
				choose( activeIndex );

				return;
			}

			if ( event.key === 'Tab' ) {
				// Single focusable control: keep focus inside the dialog.
				event.preventDefault();
				inputRef.current?.focus();
			}
		},
		[ results.length, activeIndex, choose, onClose ]
	);

	if ( ! open ) {
		return null;
	}

	return (
		<div className="mpfbs-palette" onMouseDown={ onClose }>
			<div
				className="mpfbs-palette__dialog"
				role="dialog"
				aria-modal="true"
				aria-label={ mpfbsText( 'Search' ) }
				ref={ dialogRef }
				onKeyDown={ onKeyDown }
				onMouseDown={ ( event ) => event.stopPropagation() }
			>
				<div className="mpfbs-palette__field">
					<Icon name="search" size={ 18 } />
					<input
						ref={ inputRef }
						type="search"
						className="mpfbs-palette__input"
						value={ query }
						placeholder={ mpfbsText( 'Search' ) }
						aria-label={ mpfbsText( 'Search' ) }
						aria-controls="mpfbs-palette-results"
						aria-activedescendant={ results[ activeIndex ] ? `mpfbs-palette-option-${ results[ activeIndex ]!.id }` : undefined }
						onChange={ ( event ) => {
							setQuery( event.target.value );
							setActiveIndex( 0 );
						} }
					/>
				</div>

				<ul className="mpfbs-palette__results" id="mpfbs-palette-results" role="listbox">
					{ results.map( ( route, index ) => (
						<li
							key={ route.id }
							id={ `mpfbs-palette-option-${ route.id }` }
							role="option"
							aria-selected={ index === activeIndex }
							className={ `mpfbs-palette__result${ index === activeIndex ? ' is-active' : '' }` }
							onMouseEnter={ () => setActiveIndex( index ) }
							onClick={ () => choose( index ) }
						>
							<Icon name={ route.icon } size={ 16 } />
							<span>{ mpfbsText( route.label ) }</span>
						</li>
					) ) }
					{ results.length === 0 ? (
						<li className="mpfbs-palette__empty">{ mpfbsText( 'Page not found.' ) }</li>
					) : null }
				</ul>
			</div>
		</div>
	);
}
