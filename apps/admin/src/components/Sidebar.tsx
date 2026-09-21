/**
 * Dashboard navigation.
 *
 * Sticky on desktop, collapsible below the tablet breakpoint. Destinations the
 * signed-in user cannot reach are not rendered at all.
 *
 * Grouped into sections because eighteen destinations in one column is a list
 * nobody reads to the bottom. Grouping hides nothing: every destination keeps
 * its own address and its own capability, a section only decides where it is
 * drawn, and the section holding the current screen is always open.
 */

import { useCallback, useState, type JSX } from 'react';

import { Icon } from './Icon';
import { mpfbsCan } from '../lib/config';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { MPFBS_ROUTES, MPFBS_ROUTE_GROUPS, type MpfbsRoute, type MpfbsRouteGroup } from '../lib/routes';
import { useMpfbsSetup } from '../lib/setup';

export interface SidebarProps {
	activeRouteId: string | null;
	open: boolean;
	onNavigate: () => void;
}

const COLLAPSED_KEY = 'mpfbs.sidebar.collapsed.v1';

/**
 * Reads which sections the operator last had shut.
 *
 * A convenience, not state the dashboard depends on: a private window or
 * blocked site data simply means every section starts open.
 */
function readCollapsed(): MpfbsRouteGroup[] {
	try {
		const raw = window.localStorage.getItem( COLLAPSED_KEY );

		return raw ? ( JSON.parse( raw ) as MpfbsRouteGroup[] ) : [];
	} catch {
		return [];
	}
}

/**
 * Renders the persistent left navigation.
 */
export function Sidebar( { activeRouteId, open, onNavigate }: SidebarProps ): JSX.Element {
	const [ collapsed, setCollapsed ] = useState< MpfbsRouteGroup[] >( readCollapsed );
	const { setup } = useMpfbsSetup();

	// A destination the signed-in user cannot reach is not listed.
	const visible: MpfbsRoute[] = MPFBS_ROUTES.filter(
		( route ) => mpfbsCan( route.capability ) && ! route.hidden
	);

	const toggle = useCallback( ( group: MpfbsRouteGroup ) => {
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

	const link = ( route: MpfbsRoute ): JSX.Element => {
		const isActive = route.id === activeRouteId;

		return (
			<li key={ route.id } className="mpfbs-sidebar__item">
				<a
					className={ `mpfbs-sidebar__link${ isActive ? ' is-active' : '' }` }
					href={ `#${ route.path }` }
					aria-current={ isActive ? 'page' : undefined }
					onClick={ onNavigate }
				>
					<Icon name={ route.icon } size={ 18 } />
					<span className="mpfbs-sidebar__label">{ mpfbsText( route.label ) }</span>
				</a>
			</li>
		);
	};

	const start = MPFBS_ROUTES.find( ( route ) => route.id === 'get-started' );
	const showStart = start !== undefined && setup !== null && ! setup.completed;

	const pinned = visible.filter( ( route ) => ! route.group );

	return (
		<nav
			id="mpfbs-sidebar"
			className={ `mpfbs-sidebar${ open ? ' mpfbs-sidebar--open' : '' }` }
			aria-label={ mpfbsText( 'Ferry Manager' ) }
		>
			<div className="mpfbs-sidebar__brand">
				<span className="mpfbs-sidebar__mark" aria-hidden="true">
					<Icon name="ship" size={ 20 } />
				</span>
				<span className="mpfbs-sidebar__name">Ferry Manager</span>
			</div>

			{ showStart && start ? (
				<ul className="mpfbs-sidebar__list">
					<li className="mpfbs-sidebar__item">
						<a
							className={ `mpfbs-sidebar__link mpfbs-sidebar__link--start${ activeRouteId === start.id ? ' is-active' : '' }` }
							href={ `#${ start.path }` }
							aria-current={ activeRouteId === start.id ? 'page' : undefined }
							onClick={ onNavigate }
						>
							<Icon name={ start.icon } size={ 18 } />
							<span className="mpfbs-sidebar__label">{ mpfbsText( start.label ) }</span>
							<span className="mpfbs-sidebar__badge">{ mpfbsFormat( '%1$s of %2$s', String( setup.done ), '3' ) }</span>
						</a>
					</li>
				</ul>
			) : null }

			{ pinned.length > 0 ? <ul className="mpfbs-sidebar__list">{ pinned.map( link ) }</ul> : null }

			{ MPFBS_ROUTE_GROUPS.map( ( group ) => {
				const routes = visible.filter( ( route ) => route.group === group.id );

				// A section whose every destination is gated away by a missing
				// capability is not drawn as an empty heading.
				if ( routes.length === 0 ) {
					return null;
				}

				// Never folded away from underneath the screen being looked at.
				const holdsActive = routes.some( ( route ) => route.id === activeRouteId );
				const shut = collapsed.includes( group.id ) && ! holdsActive;

				return (
					<section className="mpfbs-sidebar__group" key={ group.id }>
						<h2 className="mpfbs-sidebar__grouphead">
							<button
								type="button"
								className="mpfbs-sidebar__grouptoggle"
								aria-expanded={ ! shut }
								aria-controls={ `mpfbs-sidebar-${ group.id }` }
								onClick={ () => toggle( group.id ) }
							>
								<span>{ mpfbsText( group.label ) }</span>
								<Icon name="chevron" size={ 14 } />
							</button>
						</h2>
						<ul className="mpfbs-sidebar__list" id={ `mpfbs-sidebar-${ group.id }` } hidden={ shut }>
							{ routes.map( link ) }
						</ul>
					</section>
				);
			} ) }
		</nav>
	);
}
