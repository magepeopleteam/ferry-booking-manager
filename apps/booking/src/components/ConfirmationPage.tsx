/**
 * Booking confirmation page.
 *
 * Reached in two ways: straight after booking, when the reference is still in
 * the session, and later from a bookmark or a link in the confirmation email.
 * The reference alone never reveals a booking — the customer confirms the email
 * address it was made with, the same rule the guest lookup uses.
 */

import { useEffect, useState, type JSX } from 'preact/compat';

import { t } from '../lib/i18n';
import { readSession } from '../lib/session';
import { Lookup } from './Lookup';

export interface ConfirmationPageProps {
	/** Managed page URLs, published with the mount point. */
	pages?: Record< string, string >;
}

/**
 * Reads a booking reference from the query string.
 *
 * The confirmation email links here with the reference in the URL, so a
 * customer only has to supply the email address.
 */
function referenceFromUrl(): string {
	try {
		const value = new URLSearchParams( window.location.search ).get( 'reference' );

		return value ? value.trim() : '';
	} catch {
		return '';
	}
}

/**
 * Renders the confirmation page.
 */
export function ConfirmationPage( { pages = {} }: ConfirmationPageProps ): JSX.Element {
	const [ reference, setReference ] = useState( '' );

	useEffect( () => {
		const fromUrl = referenceFromUrl();

		setReference( fromUrl !== '' ? fromUrl : readSession().reference );
	}, [] );

	return (
		<div className="fbmb-confirmation">
			<header className="fbmb-confirmation__head">
				<h2 className="fbmb-confirmation__title">{ t( 'Your booking' ) }</h2>
				<p className="fbmb-confirmation__note">
					{ t( 'Confirm the email address you booked with to see your crossing.' ) }
				</p>
			</header>

			<Lookup reference={ reference } />

			{ pages.myBookings ? (
				<p className="fbmb-confirmation__action">
					<a className="fbmb-link" href={ pages.myBookings }>
						{ t( 'See all my bookings' ) }
					</a>
				</p>
			) : null }
		</div>
	);
}
