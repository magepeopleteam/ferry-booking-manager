/**
 * Dashboard navigation.
 *
 * Sticky on desktop, collapsible below the tablet breakpoint. Destinations the
 * signed-in user cannot reach are not rendered at all, and neither are the Pro
 * screens when Pro is not installed.
 *
 * Grouped into sections because eighteen destinations in one column is a list
 * nobody reads to the bottom. Grouping hides nothing: every destination keeps
 * its own address and its own capability, a section only decides where it is
 * drawn, and the section holding the current screen is always open.
 */

import { useCallback, useState, type JSX } from 'react';

import { Icon } from './Icon';
import { fbmCan, fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { FBM_ROUTES, FBM_ROUTE_GROUPS, type FbmRoute, type FbmRouteGroup } from '../lib/routes';
import { fbmSetupBlocks, useFbmSetup } from '../lib/setup';

export interface SidebarProps {
	activeRouteId: string | null;
	open: boolean;
	onNavigate: () => void;
}

const COLLAPSED_KEY = 'fbm.sidebar.collapsed.v1';

/**
 * Reads which sections the operator last had shut.
 *
 * A convenience, not state the dashboard depends on: a private window or
 * blocked site data simply means every section starts open.
 */
function readCollapsed(): FbmRouteGroup[] {
	try {
		const raw = window.localStorage.getItem( COLLAPSED_KEY );

		return raw ? ( JSON.parse( raw ) as FbmRouteGroup[] ) : [];
	} catch {
		return [];
	}
}

/**
 * Renders the persistent left navigation.
 */
export function Sidebar( { activeRouteId, open, onNavigate }: SidebarProps ): JSX.Element {
	const [ collapsed, setCollapsed ] = useState< FbmRouteGroup[] >( readCollapsed );
	const { setup } = useFbmSetup();
	const canManage = fbmCan( 'fbm_manage_settings' );

	/*
	 * A destination the installation cannot reach is not listed. Without Pro
	 * installed its screens do not exist, so advertising them here would leave
	 * an operator clicking through navigation into a page that only says no.
	 */
	const proActive = fbmConfig().proActive;
	const visible: FbmRoute[] = FBM_ROUTES.filter(
		( route ) => fbmCan( route.capability ) && ( proActive || ! route.pro ) && ! route.hidden
	);

	const toggle = useCallback( ( group: FbmRouteGroup ) => {
		setCollapsed( ( current ) => {
			const next = current.includes( group ) ? current.filter( ( id ) => id !== group ) : [ ...current, group ];

			try {
				window.localStorage.setItem( COLLAPSED_KEY, JSON.stringify( next ) );
			} catch {
				// Nothing to do: the sections still fold, just not next time.
			}

			return next;
		} );
	}, [] );

	const link = ( route: FbmRoute ): JSX.Element => {
		const isActive = route.id === activeRouteId;
		// Still a link: following it lands on the setup steps, which is the
		// answer to "why can't I open this".
		const locked = fbmSetupBlocks( setup, route.id, canManage );

		return (
			<li key={ route.id } className="fbm-sidebar__item">
				<a
					className={ `fbm-sidebar__link${ isActive ? ' is-active' : '' }${ locked ? ' is-locked' : '' }` }
					href={ `#${ route.path }` }
					aria-current={ isActive ? 'page' : undefined }
					onClick={ onNavigate }
				>
					<Icon name={ route.icon } size={ 18 } />
					<span className="fbm-sidebar__label">{ fbmText( route.label ) }</span>
					{ locked ? (
						<span className="fbm-sidebar__lock" title={ fbmText( 'Opens when setup is finished' ) }>
							<Icon name="lock" size={ 14 } />
						</span>
					) : null }
				</a>
			</li>
		);
	};

	const start = FBM_ROUTES.find( ( route ) => route.id === 'get-started' );
	const showStart = start !== undefined && setup !== null && ! setup.completed;

	const pinned = visible.filter( ( route ) => ! route.group );

	return (
		<nav
			id="fbm-sidebar"
			className={ `fbm-sidebar${ open ? ' fbm-sidebar--open' : '' }` }
			aria-label={ fbmText( 'Ferry Manager' ) }
		>
			<div className="fbm-sidebar__brand">
				<span className="fbm-sidebar__mark" aria-hidden="true">
					<Icon name="ship" size={ 20 } />
				</span>
				<span className="fbm-sidebar__name">Ferry Manager</span>
			</div>

			{ showStart && start ? (
				<ul className="fbm-sidebar__list">
					<li className="fbm-sidebar__item">
						<a
							className={ `fbm-sidebar__link fbm-sidebar__link--start${ activeRouteId === start.id ? ' is-active' : '' }` }
							href={ `#${ start.path }` }
							aria-current={ activeRouteId === start.id ? 'page' : undefined }
							onClick={ onNavigate }
						>
							<Icon name={ start.icon } size={ 18 } />
							<span className="fbm-sidebar__label">{ fbmText( start.label ) }</span>
							<span className="fbm-sidebar__badge">{ fbmFormat( '%1$s of %2$s', String( setup.done ), '3' ) }</span>
						</a>
					</li>
				</ul>
			) : null }

			{ pinned.length > 0 ? <ul className="fbm-sidebar__list">{ pinned.map( link ) }</ul> : null }

			{ FBM_ROUTE_GROUPS.map( ( group ) => {
				const routes = visible.filter( ( route ) => route.group === group.id );

				// A section whose every destination is gated away — Pro screens
				// on a Free install, or a role without the capability — is not
				// drawn as an empty heading.
				if ( routes.length === 0 ) {
					return null;
				}

				// Never folded away from underneath the screen being looked at.
				const holdsActive = routes.some( ( route ) => route.id === activeRouteId );
				const shut = collapsed.includes( group.id ) && ! holdsActive;

				return (
					<section className="fbm-sidebar__group" key={ group.id }>
						<h2 className="fbm-sidebar__grouphead">
							<button
								type="button"
								className="fbm-sidebar__grouptoggle"
								aria-expanded={ ! shut }
								aria-controls={ `fbm-sidebar-${ group.id }` }
								onClick={ () => toggle( group.id ) }
							>
								<span>{ fbmText( group.label ) }</span>
								<Icon name="chevron" size={ 14 } />
							</button>
						</h2>
						<ul className="fbm-sidebar__list" id={ `fbm-sidebar-${ group.id }` } hidden={ shut }>
							{ routes.map( link ) }
						</ul>
					</section>
				);
			} ) }
		</nav>
	);
}
