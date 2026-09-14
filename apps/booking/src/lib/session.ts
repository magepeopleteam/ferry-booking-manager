/**
 * Booking session.
 *
 * A customer who reloads the page mid-booking, or follows a link to a payment
 * page and comes back, should not start again. The session holds what they have
 * chosen so far — never anything sensitive, and never a price: the total is
 * always re-fetched from the server, because a figure cached in a browser is
 * exactly the figure an attacker would edit.
 */

const KEY = 'fbm.booking.session.v1';

export interface SessionState {
	origin: number;
	destination: number;
	date: string;
	returnDate: string;
	passengers: Record< number, number >;
	vehicles: Record< number, number >;
	outboundId: number;
	inboundId: number;
	step: string;
	reference: string;
}

const EMPTY: SessionState = {
	origin: 0,
	destination: 0,
	date: '',
	returnDate: '',
	passengers: {},
	vehicles: {},
	outboundId: 0,
	inboundId: 0,
	step: 'search',
	reference: '',
};

/**
 * Reads the stored session, falling back to an empty one.
 */
export function readSession(): SessionState {
	try {
		const raw = window.sessionStorage.getItem( KEY );

		if ( ! raw ) {
			return { ...EMPTY };
		}

		const parsed = JSON.parse( raw ) as Partial< SessionState >;

		return { ...EMPTY, ...parsed };
	} catch {
		// A private window, disabled storage, or corrupt JSON. Starting fresh
		// is always correct here, and never worth an error message.
		return { ...EMPTY };
	}
}

/**
 * Stores the session.
 */
export function writeSession( state: Partial< SessionState > ): void {
	try {
		window.sessionStorage.setItem( KEY, JSON.stringify( { ...readSession(), ...state } ) );
	} catch {
		// Nothing to do: the flow works without persistence, just less kindly.
	}
}

/**
 * Clears the session once a booking is complete.
 */
export function clearSession(): void {
	try {
		window.sessionStorage.removeItem( KEY );
	} catch {
		// Ignored for the same reason as above.
	}
}
