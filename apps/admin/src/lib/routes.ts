/**
 * Dashboard route registry.
 *
 * One declaration per sidebar destination: the capability that gates it and
 * the icon. The sidebar, the router and the permission guard all read from this list, so a
 * route can never appear in navigation without a capability behind it.
 */

import type { IconName } from '../components/Icon';

export interface MpfbsRoute {
	id: string;
	path: string;
	label: string;
	icon: IconName;
	capability: string;
	/** Sidebar section this destination belongs to. Omit to pin it above them all. */
	group?: MpfbsRouteGroup;
	/**
	 * Keeps the destination routable but out of the sidebar.
	 *
	 * Used where a screen has been folded into a workspace: the address still
	 * resolves, so a bookmark, an internal link and the command palette all
	 * keep working, and only the menu entry goes away.
	 */
	hidden?: boolean;
}

export type MpfbsRouteGroup = 'operations' | 'catalogue' | 'money' | 'system';

/**
 * The sidebar's sections, in the order they are shown.
 *
 * Eighteen destinations in one flat column is a list nobody reads — it is
 * scanned from the top until something looks close enough. Four sections put
 * every destination under a heading that says what kind of job it does: what is
 * happening today, what you sell, what it earns, and how the plugin is set up.
 *
 * Nothing is hidden by grouping. Every destination still has its own address
 * and its own capability; a section only decides where it is drawn.
 */
export const MPFBS_ROUTE_GROUPS: Array< { id: MpfbsRouteGroup; label: string } > = [
	{ id: 'operations', label: 'Operations' },
	{ id: 'catalogue', label: 'What you sell' },
	{ id: 'money', label: 'Money' },
	{ id: 'system', label: 'System' },
];

export const MPFBS_ROUTES: MpfbsRoute[] = [
	/*
	 * First-run setup. Kept out of the sidebar's own list: the sidebar draws
	 * it separately, with its progress, and only until setup is finished.
	 */
	{ id: 'get-started', path: '/get-started', label: 'Get started', icon: 'check', capability: 'mpfbs_access_dashboard', hidden: true },
	{ id: 'dashboard', path: '/dashboard', label: 'Dashboard', icon: 'dashboard', capability: 'mpfbs_access_dashboard' },
	{ id: 'bookings', path: '/bookings', label: 'Bookings', icon: 'ticket', group: 'operations', capability: 'mpfbs_manage_bookings' },
	{ id: 'setup', path: '/setup', label: 'Fleet & schedule', icon: 'ship', group: 'operations', capability: 'mpfbs_manage_sailings' },

	/*
	 * Folded into Fleet & schedule as tabs. Left routable on purpose — the
	 * dashboard links to /sailings, operators bookmark these addresses, and the
	 * command palette still finds them by name.
	 */
	{ id: 'sailings', path: '/sailings', label: 'Sailings', icon: 'route', group: 'operations', capability: 'mpfbs_manage_sailings', hidden: true },
	{ id: 'routes', path: '/routes', label: 'Routes', icon: 'compass', group: 'catalogue', hidden: true, capability: 'mpfbs_manage_routes' },
	{ id: 'ports', path: '/ports', label: 'Ports', icon: 'anchor', group: 'catalogue', hidden: true, capability: 'mpfbs_manage_ports' },
	{ id: 'vessels', path: '/vessels', label: 'Vessels', icon: 'ship', group: 'catalogue', hidden: true, capability: 'mpfbs_manage_vessels' },
	{ id: 'pricing', path: '/pricing', label: 'Pricing', icon: 'tag', group: 'money', hidden: true, capability: 'mpfbs_manage_pricing' },
	{ id: 'passengers', path: '/passengers', label: 'Passengers', icon: 'users', group: 'catalogue', capability: 'mpfbs_manage_settings' },
	{ id: 'vehicles', path: '/vehicles', label: 'Vehicles', icon: 'car', group: 'catalogue', capability: 'mpfbs_manage_settings' },
	{ id: 'emails', path: '/emails', label: 'Emails', icon: 'mail', group: 'system', capability: 'mpfbs_manage_settings' },
	{ id: 'payments', path: '/payments', label: 'Payments', icon: 'card', group: 'money', capability: 'mpfbs_manage_settings' },
	{ id: 'settings', path: '/settings', label: 'Settings', icon: 'settings', group: 'system', capability: 'mpfbs_manage_settings' },
];

/**
 * Resolves the route that owns a path, matching the first segment.
 */
export function mpfbsMatchRoute( path: string ): MpfbsRoute | undefined {
	const first = path.split( '/' ).filter( Boolean )[ 0 ];

	if ( ! first ) {
		return undefined;
	}

	return MPFBS_ROUTES.find( ( route ) => route.path === `/${ first }` );
}
