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
import { fbmCan, fbmConfig } from '../lib/config';
import { fbmText } from '../lib/i18n';
import { FBM_ROUTES } from '../lib/routes';
import { fbmNavigate } from '../lib/router';

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

		const proActive = fbmConfig().proActive;

		return FBM_ROUTES.filter(
			( route ) => fbmCan( route.capability ) && ( proActive || ! route.pro )
		).filter( ( route ) => {
			if ( ! needle ) {
				return true;
			}

			return fbmText( route.label ).toLowerCase().includes( needle ) || route.path.includes( needle );
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

			fbmNavigate( route.path );
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
		<div className="fbm-palette" onMouseDown={ onClose }>
			<div
				className="fbm-palette__dialog"
				role="dialog"
				aria-modal="true"
				aria-label={ fbmText( 'Search' ) }
				ref={ dialogRef }
				onKeyDown={ onKeyDown }
				onMouseDown={ ( event ) => event.stopPropagation() }
			>
				<div className="fbm-palette__field">
					<Icon name="search" size={ 18 } />
					<input
						ref={ inputRef }
						type="search"
						className="fbm-palette__input"
						value={ query }
						placeholder={ fbmText( 'Search' ) }
						aria-label={ fbmText( 'Search' ) }
						aria-controls="fbm-palette-results"
						aria-activedescendant={ results[ activeIndex ] ? `fbm-palette-option-${ results[ activeIndex ]!.id }` : undefined }
						onChange={ ( event ) => {
							setQuery( event.target.value );
							setActiveIndex( 0 );
						} }
					/>
				</div>

				<ul className="fbm-palette__results" id="fbm-palette-results" role="listbox">
					{ results.map( ( route, index ) => (
						<li
							key={ route.id }
							id={ `fbm-palette-option-${ route.id }` }
							role="option"
							aria-selected={ index === activeIndex }
							className={ `fbm-palette__result${ index === activeIndex ? ' is-active' : '' }` }
							onMouseEnter={ () => setActiveIndex( index ) }
							onClick={ () => choose( index ) }
						>
							<Icon name={ route.icon } size={ 16 } />
							<span>{ fbmText( route.label ) }</span>
						</li>
					) ) }
					{ results.length === 0 ? (
						<li className="fbm-palette__empty">{ fbmText( 'Page not found.' ) }</li>
					) : null }
				</ul>
			</div>
		</div>
	);
}
