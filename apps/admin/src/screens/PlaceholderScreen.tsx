/**
 * Placeholder for a destination whose delivery phase has not been reached.
 *
 * Deliberately explicit: it names the phase that ships the screen rather than
 * pretending the feature exists.
 */

import type { JSX } from 'react';

import { PageHeader } from '../components/PageHeader';
import { EmptyState } from '../components/States';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import type { FbmRoute } from '../lib/routes';

export interface PlaceholderScreenProps {
	route: FbmRoute;
}

/**
 * Renders the "not built yet" screen for a known route.
 */
export function PlaceholderScreen( { route }: PlaceholderScreenProps ): JSX.Element {
	// The badge is an offer, not a label: it marks what this installation does
	// not have yet. Once Pro is installed there is nothing left to offer, so it
	// disappears rather than tagging screens the operator has already paid for.
	const missing = route.pro && ! fbmConfig().proActive;

	return (
		<>
			<PageHeader
				title={ fbmText( route.label ) }
				badge={ missing ? <span className="fbm-badge fbm-badge--pro">PRO</span> : undefined }
			/>
			<EmptyState
				icon={ route.icon }
				title={
					missing
						? fbmText( 'Available in Ferry Booking Manager Pro.' )
						: fbmText( 'Coming in a later phase.' )
				}
				description={ fbmFormat( 'This module is delivered in development phase %s.', route.phase ) }
			/>
		</>
	);
}
