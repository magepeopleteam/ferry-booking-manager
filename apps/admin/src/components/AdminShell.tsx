/**
 * Dashboard shell.
 *
 * Owns the persistent chrome — sidebar, header, command palette, toasts — and
 * swaps only the content region as the fragment changes, so WordPress never
 * reloads while staff move around the application.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { AppSkeleton } from './AppSkeleton';
import { CommandPalette } from './CommandPalette';
import { DemoImportDialog } from './DemoImportDialog';
import { Header } from './Header';
import { PermissionGuard } from './PermissionGuard';
import { Sidebar } from './Sidebar';
import { EmptyState, LoadingState } from './States';
import { ToastProvider } from './Toast';
import { mpfbsText } from '../lib/i18n';
import { useMpfbsHealth } from '../lib/health';
import { useMpfbsMounted } from '../lib/mounted';
import { useMpfbsLocation } from '../lib/router';
import { mpfbsMatchRoute, type MpfbsRoute } from '../lib/routes';
import { DashboardScreen } from '../screens/DashboardScreen';
import { ResourceScreen } from '../screens/ResourceScreen';
import { SailingsScreen } from '../screens/SailingsScreen';
import { PassengersScreen, VehiclesScreen } from '../screens/TypeConfigScreen';
import { PricingScreen } from '../screens/PricingScreen';
import { EmailsScreen } from '../screens/EmailsScreen';
import { PaymentsScreen } from '../screens/PaymentsScreen';
import { BookingsScreen } from '../screens/BookingsScreen';
import { NewBookingScreen } from '../screens/NewBookingScreen';
import { SettingsScreen } from '../screens/SettingsScreen';
import { SetupScreen } from '../screens/SetupScreen';
import { GetStartedScreen } from '../screens/GetStartedScreen';
import { MPFBS_PORT_RESOURCE, MPFBS_ROUTE_RESOURCE, MPFBS_VESSEL_RESOURCE } from '../config/resources';

/**
 * Resolves a route to the screen that serves it.
 */
function renderScreen( route: MpfbsRoute, tab: string, sub: string ): JSX.Element {
	switch ( route.id ) {
		case 'dashboard':
			return <DashboardScreen />;

		case 'get-started':
			return <GetStartedScreen key="get-started" />;

		/*
		 * Each resource screen is keyed by its route so React remounts it on a
		 * change of destination. Without that, one ResourceScreen instance is
		 * reused across resources and its list, filters and open drawer carry
		 * over — briefly showing the previous resource's rows under the new
		 * screen's heading.
		 */
		case 'ports':
			return <ResourceScreen key="ports" config={ MPFBS_PORT_RESOURCE } />;

		case 'vessels':
			return <ResourceScreen key="vessels" config={ MPFBS_VESSEL_RESOURCE } />;

		case 'routes':
			return <ResourceScreen key="routes" config={ MPFBS_ROUTE_RESOURCE } />;

		case 'setup':
			return <SetupScreen key="setup" tab={ tab } sub={ sub } />;

		case 'sailings':
			return <SailingsScreen key="sailings" />;

		case 'bookings':
			// A sub-route rather than its own sidebar entry: staff go to
			// Bookings to make one, the same way they go there to find one.
			return tab === 'new' ? (
				<NewBookingScreen key="bookings-new" />
			) : (
				<BookingsScreen key="bookings" />
			);

		case 'settings':
			return <SettingsScreen key="settings" tab={ tab } />;

		case 'passengers':
			return <PassengersScreen key="passengers" tab={ tab } />;

		case 'vehicles':
			return <VehiclesScreen key="vehicles" tab={ tab } />;

		case 'pricing':
			return <PricingScreen key="pricing" tab={ tab } />;

		case 'emails':
			return <EmailsScreen key="emails" />;

		case 'payments':
			return <PaymentsScreen key="payments" />;

		default:
			return <DashboardScreen />;
	}
}

/**
 * Renders the whole dashboard.
 */
export function AdminShell(): JSX.Element {
	const mounted = useMpfbsMounted();
	const location = useMpfbsLocation();
	const [ sidebarOpen, setSidebarOpen ] = useState( false );
	const [ paletteOpen, setPaletteOpen ] = useState( false );
	const { health, error } = useMpfbsHealth();

	useEffect( () => {
		const onKeyDown = ( event: KeyboardEvent ): void => {
			if ( ( event.metaKey || event.ctrlKey ) && event.key.toLowerCase() === 'k' ) {
				/*
				 * WordPress binds Ctrl/Cmd+K to its own command palette on every
				 * admin screen. Capturing the event on the window and stopping it
				 * immediately means only one palette opens on this screen, while
				 * the admin bar's search entry still works everywhere else.
				 */
				event.preventDefault();
				event.stopImmediatePropagation();
				setPaletteOpen( true );
			}
		};

		window.addEventListener( 'keydown', onKeyDown, true );

		return () => {
			window.removeEventListener( 'keydown', onKeyDown, true );
		};
	}, [] );

	const closeSidebar = useCallback( () => setSidebarOpen( false ), [] );
	const closePalette = useCallback( () => setPaletteOpen( false ), [] );

	if ( ! mounted ) {
		return <AppSkeleton />;
	}

	const route = location ? mpfbsMatchRoute( location.path ) : undefined;

	let content: JSX.Element;

	if ( ! location ) {
		content = <LoadingState rows={ 4 } />;
	} else if ( ! route ) {
		content = <EmptyState icon="compass" title={ mpfbsText( 'Page not found.' ) } description={ location.path } />;
	} else {
		content = (
			<PermissionGuard capability={ route.capability }>
					{ renderScreen( route, location.segments[ 1 ] ?? '', location.segments[ 2 ] ?? '' ) }
				</PermissionGuard>
		);
	}

	/*
	 * Everything the dashboard renders lives inside this one root, overlays
	 * included. The stylesheet scopes every rule to it, which is what keeps
	 * wp-admin's own element styles — which are more specific than a bare class
	 * — from reaching the dashboard's controls.
	 */
	return (
		<div className={ `mpfbs-app${ sidebarOpen ? ' mpfbs-app--nav-open' : '' }` }>
			<ToastProvider>
				<a className="mpfbs-skip-link" href="#mpfbs-main">
					{ mpfbsText( 'Skip to dashboard content' ) }
				</a>

				<Sidebar activeRouteId={ route?.id ?? null } open={ sidebarOpen } onNavigate={ closeSidebar } />

				<div className="mpfbs-app__body">
					<Header
						sidebarOpen={ sidebarOpen }
						onToggleSidebar={ () => setSidebarOpen( ( open ) => ! open ) }
						onOpenSearch={ () => setPaletteOpen( true ) }
						connected={ error ? false : health ? true : null }
					/>

					<main className="mpfbs-main" id="mpfbs-main" tabIndex={ -1 }>
						<div className="mpfbs-main__inner">{ content }</div>
					</main>
				</div>

				<button
					type="button"
					className="mpfbs-app__scrim"
					aria-hidden={ ! sidebarOpen }
					tabIndex={ -1 }
					onClick={ closeSidebar }
				/>

				<CommandPalette open={ paletteOpen } onClose={ closePalette } />

				<DemoImportDialog />
			</ToastProvider>
		</div>
	);
}
