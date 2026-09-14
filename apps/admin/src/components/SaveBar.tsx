/**
 * Sticky save bar.
 *
 * Configuration lists run past a screen, and the action that commits them has
 * to stay reachable from the bottom of the list — which is exactly where an
 * operator is standing after adding the last row.
 *
 * It lives outside the panel rather than inside it: a panel clips its overflow,
 * which makes it the containing block for any sticky descendant, so a sticky
 * header in there does not stick to the viewport at all — it just sits at an
 * offset from the panel's own top edge, over the content underneath.
 */

import type { JSX } from 'react';

import { fbmFormat, fbmText } from '../lib/i18n';

export interface SaveBarProps {
	dirty: boolean;
	saving: boolean;
	onSave: () => void;
	onReset?: () => void;
	/** Short summary of the current state, e.g. "3 required, 6 optional". */
	summary?: string;
}

/**
 * Renders the pinned save bar.
 */
export function SaveBar( { dirty, saving, onSave, onReset, summary }: SaveBarProps ): JSX.Element {
	return (
		<div className={ `fbm-savebar${ dirty ? ' is-dirty' : '' }` }>
			<span className="fbm-savebar__status" role="status">
				{ dirty ? fbmText( 'Unsaved changes' ) : summary ?? '' }
			</span>

			<div className="fbm-savebar__actions">
				{ onReset && dirty ? (
					<button type="button" className="fbm-button fbm-button--secondary" onClick={ onReset } disabled={ saving }>
						{ fbmText( 'Discard' ) }
					</button>
				) : null }

				<button type="button" className="fbm-button fbm-button--primary" onClick={ onSave } disabled={ saving || ! dirty }>
					{ saving ? fbmText( 'Saving…' ) : fbmText( 'Save changes' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * Builds the idle summary for a field matrix.
 */
export function fbmFieldSummary( required: number, optional: number ): string {
	return fbmFormat( '%1$s required, %2$s optional', String( required ), String( optional ) );
}
