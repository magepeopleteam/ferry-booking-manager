/**
 * Confirmation dialog.
 *
 * Used before anything destructive. The confirm button carries the specific
 * action word rather than "OK", so the consequence is legible at the moment of
 * the click.
 */

import { useEffect, useRef, type JSX } from 'react';

import { fbmText } from '../lib/i18n';

export interface ConfirmDialogProps {
	open: boolean;
	title: string;
	message: string;
	confirmLabel: string;
	tone?: 'danger' | 'default';
	busy?: boolean;
	onConfirm: () => void;
	onCancel: () => void;
}

/**
 * Renders a modal confirmation.
 */
export function ConfirmDialog( {
	open,
	title,
	message,
	confirmLabel,
	tone = 'danger',
	busy = false,
	onConfirm,
	onCancel,
}: ConfirmDialogProps ): JSX.Element | null {
	const confirmRef = useRef< HTMLButtonElement >( null );

	useEffect( () => {
		if ( open ) {
			confirmRef.current?.focus();
		}
	}, [ open ] );

	if ( ! open ) {
		return null;
	}

	return (
		<div className="fbm-modal" onMouseDown={ onCancel }>
			<div
				className="fbm-modal__dialog"
				role="alertdialog"
				aria-modal="true"
				aria-label={ title }
				onMouseDown={ ( event ) => event.stopPropagation() }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Escape' ) {
						event.preventDefault();
						onCancel();
					}
				} }
			>
				<h2 className="fbm-modal__title">{ title }</h2>
				<p className="fbm-modal__text">{ message }</p>
				<div className="fbm-modal__actions">
					<button type="button" className="fbm-button fbm-button--secondary" onClick={ onCancel } disabled={ busy }>
						{ fbmText( 'Cancel' ) }
					</button>
					<button
						type="button"
						ref={ confirmRef }
						className={ `fbm-button ${ tone === 'danger' ? 'fbm-button--danger' : 'fbm-button--primary' }` }
						onClick={ onConfirm }
						disabled={ busy }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}
