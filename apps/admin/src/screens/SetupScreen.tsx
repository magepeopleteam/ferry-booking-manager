/**
 * Fleet and schedule.
 *
 * One destination in place of five. Ports, vessels, routes, sailings and fares
 * were five sidebar entries that had to be visited in a particular order to
 * sell one ticket, and an operator who did not already know that order got a
 * booking form that found nothing and said nothing about why.
 *
 * The tabs hold exactly the screens they replace — the same lists, the same
 * forms, nothing removed — and the wizard on the first tab does the whole
 * sequence in one pass for anyone setting a crossing up rather than editing one.
 */

import { useCallback, useState, type JSX } from 'react';

import { CrossingSetup } from '../components/CrossingSetup';
import { PageHeader } from '../components/PageHeader';
import { Tabs } from '../components/Tabs';
import { fbmCan } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { useFbmReferences } from '../lib/references';
import { fbmNavigate } from '../lib/router';
import { PricingScreen } from './PricingScreen';
import { ResourceScreen } from './ResourceScreen';
import { SailingsScreen } from './SailingsScreen';
import { FBM_PORT_RESOURCE, FBM_ROUTE_RESOURCE, FBM_VESSEL_RESOURCE } from '../config/resources';

export interface SetupScreenProps {
	/** Second path segment: which tab. */
	tab: string;
	/** Third path segment, handed to a tab that has tabs of its own. */
	sub: string;
}

const TABS = [
	{ id: 'start', label: 'Set up', capability: 'fbm_manage_sailings' },
	{ id: 'ports', label: 'Ports', capability: 'fbm_manage_ports' },
	{ id: 'vessels', label: 'Vessels', capability: 'fbm_manage_vessels' },
	{ id: 'routes', label: 'Routes', capability: 'fbm_manage_routes' },
	{ id: 'sailings', label: 'Sailings', capability: 'fbm_manage_sailings' },
	{ id: 'pricing', label: 'Pricing', capability: 'fbm_manage_pricing' },
];

/**
 * Renders the fleet and schedule workspace.
 */
export function SetupScreen( { tab, sub }: SetupScreenProps ): JSX.Element {
	const { references, reload } = useFbmReferences();
	const [ wizardOpen, setWizardOpen ] = useState( false );

	// A tab behind a capability the signed-in user does not hold is not drawn,
	// the same rule the sidebar follows.
	const allowed = TABS.filter( ( entry ) => fbmCan( entry.capability ) );
	const active = allowed.some( ( entry ) => entry.id === tab ) ? tab : ( allowed[ 0 ]?.id ?? 'start' );

	const select = useCallback( ( id: string ) => {
		fbmNavigate( id === 'start' ? '/setup' : `/setup/${ id }` );
	}, [] );

	const counts = {
		ports: references.ports.length,
		vessels: references.vessels.length,
		routes: references.routes.length,
	};
	const empty = counts.ports === 0 || counts.vessels === 0 || counts.routes === 0;

	return (
		<>
			<PageHeader
				title={ fbmText( 'Fleet and schedule' ) }
				description={ fbmText( 'Everything a crossing needs before it can be sold, in one place.' ) }
				actions={
					<button type="button" className="fbm-button fbm-button--primary" onClick={ () => setWizardOpen( true ) }>
						{ fbmText( 'Set up a crossing' ) }
					</button>
				}
			/>

			<Tabs
				label="Fleet and schedule"
				active={ active }
				onSelect={ select }
				tabs={ allowed.map( ( entry ) => ( { id: entry.id, label: entry.label } ) ) }
			/>

			<div id={ `fbm-tabpanel-${ active }` } role="tabpanel" aria-labelledby={ `fbm-tab-${ active }` }>
				{ active === 'start' ? (
					<div className="fbm-panel fbm-setup">
						<h2 className="fbm-panel__title">
							{ empty ? fbmText( 'Nothing is on sale yet' ) : fbmText( 'Add another crossing' ) }
						</h2>
						<p className="fbm-panel__description">
							{ empty
								? fbmText(
										'A crossing needs five things, and it needs them in order: two ports, a vessel, a route joining them, a timetable, and a fare. Set them up together and the booking form has something to find.'
								  )
								: fbmText(
										'The wizard asks for the ports, the vessel, the route, the timetable and the fares once, then writes them together. The tabs above edit any of it afterwards.'
								  ) }
						</p>

						<ol className="fbm-setup__list">
							<li className={ counts.ports > 0 ? 'is-done' : '' }>
								{ counts.ports > 0
									? fbmFormat( '%s ports', String( counts.ports ) )
									: fbmText( 'No ports yet' ) }
							</li>
							<li className={ counts.vessels > 0 ? 'is-done' : '' }>
								{ counts.vessels > 0
									? fbmFormat( '%s vessels', String( counts.vessels ) )
									: fbmText( 'No vessels yet' ) }
							</li>
							<li className={ counts.routes > 0 ? 'is-done' : '' }>
								{ counts.routes > 0
									? fbmFormat( '%s routes', String( counts.routes ) )
									: fbmText( 'No routes yet' ) }
							</li>
						</ol>

						<button type="button" className="fbm-button fbm-button--primary" onClick={ () => setWizardOpen( true ) }>
							{ fbmText( 'Set up a crossing' ) }
						</button>
					</div>
				) : null }

				{ active === 'ports' ? <ResourceScreen key="setup-ports" config={ FBM_PORT_RESOURCE } /> : null }
				{ active === 'vessels' ? <ResourceScreen key="setup-vessels" config={ FBM_VESSEL_RESOURCE } /> : null }
				{ active === 'routes' ? <ResourceScreen key="setup-routes" config={ FBM_ROUTE_RESOURCE } /> : null }
				{ active === 'sailings' ? <SailingsScreen key="setup-sailings" /> : null }
				{ active === 'pricing' ? <PricingScreen key="setup-pricing" tab={ sub } /> : null }
			</div>

			<CrossingSetup open={ wizardOpen } onClose={ () => setWizardOpen( false ) } onCreated={ reload } />
		</>
	);
}
