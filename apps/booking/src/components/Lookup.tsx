/**
 * Guest booking lookup.
 *
 * A reference alone is not enough to see a booking — it would make the page a
 * way to read other people's crossings by trying references. The email address
 * on the booking has to match, and the server answers "no match" identically
 * whether the reference is wrong or the email is.
 */

import { useState, type JSX } from 'preact/compat';

import { ApiError, request } from '../lib/api';
import { t } from '../lib/i18n';
import type { CustomerBooking } from '../lib/types';
import { BookingCard } from './BookingCard';
import { Button, Field, Input } from './ui';

export interface LookupProps {
	/** Pre-filled reference, used by the confirmation page. */
	reference?: string;
}

/**
 * Renders the lookup form and its result.
 */
export function Lookup( { reference = '' }: LookupProps ): JSX.Element {
	const [ ref, setRef ] = useState( reference );
	const [ email, setEmail ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ booking, setBooking ] = useState< CustomerBooking | null >( null );

	const submit = async ( event: Event ) => {
		event.preventDefault();
		setError( '' );

		if ( ref.trim() === '' || email.trim() === '' ) {
			setError( t( 'Enter your booking reference and the email address you booked with.' ) );
			return;
		}

		setBusy( true );

		try {
			const found = await request< CustomerBooking >( 'bookings/lookup', {
				method: 'POST',
				body: { reference: ref.trim(), email: email.trim() },
			} );
			setBooking( found );
		} catch ( caught: unknown ) {
			setBooking( null );
			setError( caught instanceof ApiError ? caught.message : t( 'Something went wrong.' ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="fbmb-lookup">
			<form className="fbmb-lookup__form" onSubmit={ submit } noValidate>
				<h2 className="fbmb-lookup__title">{ t( 'Find my booking' ) }</h2>
				<p className="fbmb-lookup__note">
					{ t( 'Your reference is in the confirmation email we sent when you booked.' ) }
				</p>

				<div className="fbmb-lookup__fields">
					<Field label={ t( 'Booking reference' ) } htmlFor="fbm-lookup-reference" required>
						<Input
							id="fbm-lookup-reference"
							value={ ref }
							autoComplete="off"
							onChange={ setRef }
						/>
					</Field>

					<Field label={ t( 'Email' ) } htmlFor="fbm-lookup-email" required>
						<Input
							id="fbm-lookup-email"
							type="email"
							value={ email }
							autoComplete="email"
							onChange={ setEmail }
						/>
					</Field>

					<Button type="submit" disabled={ busy }>
						{ busy ? t( 'Searching…' ) : t( 'Find booking' ) }
					</Button>
				</div>

				{ error !== '' ? (
					<p className="fbmb-lookup__error" role="alert">
						{ error }
					</p>
				) : null }
			</form>

			{ booking ? <BookingCard booking={ booking } detailed /> : null }
		</div>
	);
}
