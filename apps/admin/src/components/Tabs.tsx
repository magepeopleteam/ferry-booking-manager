/**
 * Tab strip.
 *
 * Used where one destination owns two related but separate jobs — the passenger
 * types you sell, and the details you collect about the people travelling on
 * them. Selection lives in the URL hash so a tab survives a reload and can be
 * linked to, which a local useState would not give us.
 */

import type { JSX } from 'react';

import { mpfbsText } from '../lib/i18n';

export interface MpfbsTab {
	id: string;
	label: string;
}

export interface TabsProps {
	tabs: MpfbsTab[];
	active: string;
	onSelect: ( id: string ) => void;
	label: string;
}

/**
 * Renders a horizontal tab list.
 */
export function Tabs( { tabs, active, onSelect, label }: TabsProps ): JSX.Element {
	return (
		<div className="mpfbs-tabs" role="tablist" aria-label={ mpfbsText( label ) }>
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.id }
					type="button"
					role="tab"
					id={ `mpfbs-tab-${ tab.id }` }
					aria-selected={ active === tab.id }
					aria-controls={ `mpfbs-tabpanel-${ tab.id }` }
					className={ `mpfbs-tabs__tab${ active === tab.id ? ' is-active' : '' }` }
					onClick={ () => onSelect( tab.id ) }
				>
					{ mpfbsText( tab.label ) }
				</button>
			) ) }
		</div>
	);
}
