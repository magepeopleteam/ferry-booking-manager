/**
 * Tab strip.
 *
 * Used where one destination owns two related but separate jobs — the passenger
 * types you sell, and the details you collect about the people travelling on
 * them. Selection lives in the URL hash so a tab survives a reload and can be
 * linked to, which a local useState would not give us.
 */

import type { JSX } from 'react';

import { fbmText } from '../lib/i18n';

export interface FbmTab {
	id: string;
	label: string;
}

export interface TabsProps {
	tabs: FbmTab[];
	active: string;
	onSelect: ( id: string ) => void;
	label: string;
}

/**
 * Renders a horizontal tab list.
 */
export function Tabs( { tabs, active, onSelect, label }: TabsProps ): JSX.Element {
	return (
		<div className="fbm-tabs" role="tablist" aria-label={ fbmText( label ) }>
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.id }
					type="button"
					role="tab"
					id={ `fbm-tab-${ tab.id }` }
					aria-selected={ active === tab.id }
					aria-controls={ `fbm-tabpanel-${ tab.id }` }
					className={ `fbm-tabs__tab${ active === tab.id ? ' is-active' : '' }` }
					onClick={ () => onSelect( tab.id ) }
				>
					{ fbmText( tab.label ) }
				</button>
			) ) }
		</div>
	);
}
