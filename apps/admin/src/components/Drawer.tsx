/**
 * Slide-over drawer.
 *
 * Forms open beside the list rather than on a separate page, so staff keep
 * their place in the table. Focus is trapped while open and returned to the
 * control that opened it on close, and Escape always exits.
 */

import { useCallback, useEffect, useRef, type JSX, type ReactNode } from 'react';

import { Icon } from './Icon';
import { fbmText } from '../lib/i18n';

export interface DrawerProps {
	open: boolean;
	title: string;
	description?: string;
	onClose: () => void;
	footer?: ReactNode;
	children: ReactNode;
	width?: 'default' | 'wide' | 'xwide';
}

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Renders an accessible slide-over panel.
 */
export function Drawer( { open, title, description, onClose, footer, children, width = 'default' }: DrawerProps ): JSX.Element | null {
	const panelRef = useRef< HTMLDivElement >( null );
	const returnFocusTo = useRef< HTMLElement | null >( null );

	useEffect( () => {
		if ( ! open ) {
			return;
		}

		returnFocusTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;

		const first = panelRef.current?.querySelector< HTMLElement >( FOCUSABLE );
		( first ?? panelRef.current )?.focus();
	}, [ open ] );

	useEffect( () => {
		if ( open ) {
			return;
		}

		returnFocusTo.current?.focus();
	}, [ open ] );

	/*
	 * Escape is handled on the document rather than on the panel. Focus can
	 * legitimately end up outside the panel — disabling the submit button while
	 * a request is in flight drops focus to the body — and a drawer that can no
	 * longer be dismissed with the keyboard is a trap.
	 */
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		const onEscape = ( event: KeyboardEvent ): void => {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				onClose();
			}
		};

		document.addEventListener( 'keydown', onEscape );

		return () => document.removeEventListener( 'keydown', onEscape );
	}, [ open, onClose ] );

	const onKeyDown = useCallback(
		( event: React.KeyboardEvent< HTMLDivElement > ) => {
			if ( event.key !== 'Tab' ) {
				return;
			}

			const focusable = Array.from( panelRef.current?.querySelectorAll< HTMLElement >( FOCUSABLE ) ?? [] ).filter(
				( element ) => element.offsetParent !== null
			);

			if ( focusable.length === 0 ) {
				return;
			}

			const first = focusable[ 0 ]!;
			const last = focusable[ focusable.length - 1 ]!;

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		},
		[]
	);

	if ( ! open ) {
		return null;
	}

	return (
		<div className="fbm-drawer" onMouseDown={ onClose }>
			<div
				className={ `fbm-drawer__panel fbm-drawer__panel--${ width }` }
				role="dialog"
				aria-modal="true"
				aria-label={ title }
				ref={ panelRef }
				tabIndex={ -1 }
				onKeyDown={ onKeyDown }
				onMouseDown={ ( event ) => event.stopPropagation() }
			>
				<header className="fbm-drawer__header">
					<div>
						<h2 className="fbm-drawer__title">{ title }</h2>
						{ description ? <p className="fbm-drawer__description">{ description }</p> : null }
					</div>
					<button type="button" className="fbm-drawer__close" onClick={ onClose } aria-label={ fbmText( 'Close' ) }>
						<Icon name="close" size={ 18 } />
					</button>
				</header>

				<div className="fbm-drawer__body">{ children }</div>

				{ footer ? <footer className="fbm-drawer__footer">{ footer }</footer> : null }
			</div>
		</div>
	);
}
