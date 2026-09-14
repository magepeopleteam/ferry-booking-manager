/**
 * Dashboard route registry.
 *
 * One declaration per sidebar destination: the capability that gates it, the
 * icon, and whether the screen is delivered by Ferry Booking Manager Pro. The
 * sidebar, the router and the permission guard all read from this list, so a
 * route can never appear in navigation without a capability behind it.
 */

import type { IconName } from '../components/Icon';

export interface FbmRoute {
	id: string;
	path: string;
	label: string;
	icon: IconName;
	capability: string;
	/** Sidebar section this destination belongs to. Omit to pin it above them all. */
	group?: FbmRouteGroup;
	/**
	 * Keeps the destination routable but out of the sidebar.
	 *
	 * Used where a screen has been folded into a workspace: the address still
	 * resolves, so a bookmark, an internal link and the command palette all
	 * keep working, and only the menu entry goes away.
	 */
	hidden?: boolean;
	/** True when the screen is provided by the Pro add-on. */
	pro?: boolean;
	/** Development phase that delivers the screen. Used for honest placeholders. */
	phase: number;
}

export type FbmRouteGroup = 'operations' | 'catalogue' | 'money' | 'system';

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
export const FBM_ROUTE_GROUPS: Array< { id: FbmRouteGroup; label: string } > = [
	{ id: 'operations', label: 'Operations' },
	{ id: 'catalogue', label: 'What you sell' },
	{ id: 'money', label: 'Money' },
	{ id: 'system', label: 'System' },
];

export const FBM_ROUTES: FbmRoute[] = [
	/*
	 * First-run setup. Kept out of the sidebar's own list: the sidebar draws
	 * it separately, with its progress, and only until setup is finished.
	 */
	{ id: 'get-started', path: '/get-started', label: 'Get started', icon: 'check', capability: 'fbm_access_dashboard', hidden: true, phase: 1 },
	{ id: 'dashboard', path: '/dashboard', label: 'Dashboard', icon: 'dashboard', capability: 'fbm_access_dashboard', phase: 1 },
	{ id: 'bookings', path: '/bookings', label: 'Bookings', icon: 'ticket', group: 'operations', capability: 'fbm_manage_bookings', phase: 10 },
	{ id: 'calendar', path: '/calendar', label: 'Calendar', icon: 'calendar', group: 'operations', capability: 'fbm_manage_sailings', pro: true, phase: 27 },
	{ id: 'setup', path: '/setup', label: 'Fleet & schedule', icon: 'ship', group: 'operations', capability: 'fbm_manage_sailings', phase: 3 },

	/*
	 * Folded into Fleet & schedule as tabs. Left routable on purpose — the
	 * dashboard links to /sailings, operators bookmark these addresses, and the
	 * command palette still finds them by name.
	 */
	{ id: 'sailings', path: '/sailings', label: 'Sailings', icon: 'route', group: 'operations', capability: 'fbm_manage_sailings', hidden: true, phase: 4 },
	{ id: 'routes', path: '/routes', label: 'Routes', icon: 'compass', group: 'catalogue', hidden: true, capability: 'fbm_manage_routes', phase: 3 },
	{ id: 'ports', path: '/ports', label: 'Ports', icon: 'anchor', group: 'catalogue', hidden: true, capability: 'fbm_manage_ports', phase: 3 },
	{ id: 'vessels', path: '/vessels', label: 'Vessels', icon: 'ship', group: 'catalogue', hidden: true, capability: 'fbm_manage_vessels', phase: 3 },
	{ id: 'pricing', path: '/pricing', label: 'Pricing', icon: 'tag', group: 'money', hidden: true, capability: 'fbm_manage_pricing', phase: 7 },
	{ id: 'passengers', path: '/passengers', label: 'Passengers', icon: 'users', group: 'catalogue', capability: 'fbm_manage_settings', phase: 5 },
	{ id: 'vehicles', path: '/vehicles', label: 'Vehicles', icon: 'car', group: 'catalogue', capability: 'fbm_manage_settings', phase: 5 },
	{ id: 'pos', path: '/pos', label: 'Counter', icon: 'card', group: 'money', capability: 'fbm_use_pos', pro: true, phase: 29 },
	{ id: 'checkin', path: '/checkin', label: 'Check-In', icon: 'scan', group: 'operations', capability: 'fbm_checkin', pro: true, phase: 20 },
	{ id: 'manifests', path: '/manifests', label: 'Manifests', icon: 'list', group: 'operations', capability: 'fbm_view_reports', pro: true, phase: 21 },
	{ id: 'agents', path: '/agents', label: 'Agents', icon: 'briefcase', group: 'money', capability: 'fbm_manage_agents', pro: true, phase: 28 },
	{ id: 'reports', path: '/reports', label: 'Reports', icon: 'chart', group: 'money', capability: 'fbm_view_reports', pro: true, phase: 30 },
	{ id: 'emails', path: '/emails', label: 'Emails', icon: 'mail', group: 'system', capability: 'fbm_manage_settings', phase: 14 },
	{ id: 'payments', path: '/payments', label: 'Payments', icon: 'card', group: 'money', capability: 'fbm_manage_settings', phase: 12 },
	{ id: 'settings', path: '/settings', label: 'Settings', icon: 'settings', group: 'system', capability: 'fbm_manage_settings', phase: 16 },
];

/**
 * Resolves the route that owns a path, matching the first segment.
 */
export function fbmMatchRoute( path: string ): FbmRoute | undefined {
	const first = path.split( '/' ).filter( Boolean )[ 0 ];

	if ( ! first ) {
		return undefined;
	}

	return FBM_ROUTES.find( ( route ) => route.path === `/${ first }` );
}
