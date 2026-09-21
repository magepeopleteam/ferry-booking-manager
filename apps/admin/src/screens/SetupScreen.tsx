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
import { mpfbsCan } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { useMpfbsReferences } from '../lib/references';
import { mpfbsNavigate } from '../lib/router';
import { PricingScreen } from './PricingScreen';
import { ResourceScreen } from './ResourceScreen';
import { SailingsScreen } from './SailingsScreen';
import { MPFBS_PORT_RESOURCE, MPFBS_ROUTE_RESOURCE, MPFBS_VESSEL_RESOURCE } from '../config/resources';

export interface SetupScreenProps {
	/** Second path segment: which tab. */
	tab: string;
	/** Third path segment, handed to a tab that has tabs of its own. */
	sub: string;
}

const TABS = [
	{ id: 'start', label: 'Set up', capability: 'mpfbs_manage_sailings' },
	{ id: 'ports', label: 'Ports', capability: 'mpfbs_manage_ports' },
	{ id: 'vessels', label: 'Vessels', capability: 'mpfbs_manage_vessels' },
	{ id: 'routes', label: 'Routes', capability: 'mpfbs_manage_routes' },
	{ id: 'sailings', label: 'Sailings', capability: 'mpfbs_manage_sailings' },
	{ id: 'pricing', label: 'Pricing', capability: 'mpfbs_manage_pricing' },
];

/**
 * Renders the fleet and schedule workspace.
 */
export function SetupScreen( { tab, sub }: SetupScreenProps ): JSX.Element {
	const { references, reload } = useMpfbsReferences();
	const [ wizardOpen, setWizardOpen ] = useState( false );

	// A tab behind a capability the signed-in user does not hold is not drawn,
	// the same rule the sidebar follows.
	const allowed = TABS.filter( ( entry ) => mpfbsCan( entry.capability ) );
	const active = allowed.some( ( entry ) => entry.id === tab ) ? tab : ( allowed[ 0 ]?.id ?? 'start' );

	const select = useCallback( ( id: string ) => {
		mpfbsNavigate( id === 'start' ? '/setup' : `/setup/${ id }` );
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
				title={ mpfbsText( 'Fleet and schedule' ) }
				description={ mpfbsText( 'Everything a crossing needs before it can be sold, in one place.' ) }
				actions={
					<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => setWizardOpen( true ) }>
						{ mpfbsText( 'Set up a crossing' ) }
					</button>
				}
			/>

			<Tabs
				label="Fleet and schedule"
				active={ active }
				onSelect={ select }
				tabs={ allowed.map( ( entry ) => ( { id: entry.id, label: entry.label } ) ) }
			/>

			<div id={ `mpfbs-tabpanel-${ active }` } role="tabpanel" aria-labelledby={ `mpfbs-tab-${ active }` }>
				{ active === 'start' ? (
					<div className="mpfbs-panel mpfbs-setup">
						<h2 className="mpfbs-panel__title">
							{ empty ? mpfbsText( 'Nothing is on sale yet' ) : mpfbsText( 'Add another crossing' ) }
						</h2>
						<p className="mpfbs-panel__description">
							{ empty
								? mpfbsText(
										'A crossing needs five things, and it needs them in order: two ports, a vessel, a route joining them, a timetable, and a fare. Set them up together and the booking form has something to find.'
								  )
								: mpfbsText(
										'The wizard asks for the ports, the vessel, the route, the timetable and the fares once, then writes them together. The tabs above edit any of it afterwards.'
								  ) }
						</p>

						<ol className="mpfbs-setup__list">
							<li className={ counts.ports > 0 ? 'is-done' : '' }>
								{ counts.ports > 0
									? mpfbsFormat( '%s ports', String( counts.ports ) )
									: mpfbsText( 'No ports yet' ) }
							</li>
							<li className={ counts.vessels > 0 ? 'is-done' : '' }>
								{ counts.vessels > 0
									? mpfbsFormat( '%s vessels', String( counts.vessels ) )
									: mpfbsText( 'No vessels yet' ) }
							</li>
							<li className={ counts.routes > 0 ? 'is-done' : '' }>
								{ counts.routes > 0
									? mpfbsFormat( '%s routes', String( counts.routes ) )
									: mpfbsText( 'No routes yet' ) }
							</li>
						</ol>

						<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ () => setWizardOpen( true ) }>
							{ mpfbsText( 'Set up a crossing' ) }
						</button>
					</div>
				) : null }

				{ active === 'ports' ? <ResourceScreen key="setup-ports" config={ MPFBS_PORT_RESOURCE } /> : null }
				{ active === 'vessels' ? <ResourceScreen key="setup-vessels" config={ MPFBS_VESSEL_RESOURCE } /> : null }
				{ active === 'routes' ? <ResourceScreen key="setup-routes" config={ MPFBS_ROUTE_RESOURCE } /> : null }
				{ active === 'sailings' ? <SailingsScreen key="setup-sailings" /> : null }
				{ active === 'pricing' ? <PricingScreen key="setup-pricing" tab={ sub } /> : null }
			</div>

			<CrossingSetup open={ wizardOpen } onClose={ () => setWizardOpen( false ) } onCreated={ reload } />
		</>
	);
}
