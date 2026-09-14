/**
 * A booking, as its owner sees it.
 *
 * The same card serves the confirmation page, the booking history and the guest
 * lookup, because they are all answering the same question and a customer who
 * checks their booking in two places should not have to read two layouts.
 */

import type { JSX } from 'preact';

import { config } from '../lib/config';
import { date as formatDate, time } from '../lib/datetime';
import { t, tf } from '../lib/i18n';
import { money } from '../lib/money';
import type { CustomerBooking } from '../lib/types';
import { Badge } from './ui';

/**
 * Maps a booking status onto the tone its badge should carry.
 */
function tone( status: string ): 'positive' | 'warning' | 'danger' | 'neutral' {
	if ( status === 'confirmed' || status === 'completed' ) {
		return 'positive';
	}

	if ( status === 'cancelled' || status === 'failed' || status === 'refunded' ) {
		return 'danger';
	}

	if ( status === 'pending' || status === 'on_hold' ) {
		return 'warning';
	}

	return 'neutral';
}

export interface BookingCardProps {
	booking: CustomerBooking;
	/** Shows the balance and payment method. Off in a history list. */
	detailed?: boolean;
}

/**
 * Renders one booking.
 */
export function BookingCard( { booking, detailed = false }: BookingCardProps ): JSX.Element {
	const cfg = config();

	return (
		<article className="fbmb-record">
			<header className="fbmb-record__head">
				<div>
					<p className="fbmb-record__reference">{ booking.reference }</p>
					<p className="fbmb-record__name">{ booking.customer_name }</p>
				</div>
				<div className="fbmb-record__badges">
					<Badge tone={ tone( booking.status ) }>{ booking.status_label }</Badge>
					{ booking.balance > 0 ? <Badge tone="warning">{ booking.payment_label }</Badge> : null }
				</div>
			</header>

			<ol className="fbmb-record__legs">
				{ booking.legs.map( ( leg, index ) => (
					<li className="fbmb-record__leg" key={ `${ booking.reference }-${ index }` }>
						<span className="fbmb-record__leg-label">{ leg.label }</span>
						<span className="fbmb-record__route">
							{ leg.origin !== '' && leg.destination !== ''
								? tf( '%1$s to %2$s', leg.origin, leg.destination )
								: leg.route }
						</span>
						<span className="fbmb-record__when">
							{ `${ formatDate( leg.departure, true ) } · ${ time( leg.departure ) }` }
							{ leg.arrival !== '' ? ` – ${ time( leg.arrival ) }` : '' }
						</span>
						{ leg.vessel !== '' ? <span className="fbmb-record__vessel">{ leg.vessel }</span> : null }
					</li>
				) ) }
				{ booking.legs.length === 0 ? (
					<li className="fbmb-record__leg">
						<span className="fbmb-record__when">{ t( 'Crossing details are no longer available.' ) }</span>
					</li>
				) : null }
			</ol>

			<dl className="fbmb-record__facts">
				<div>
					<dt>{ t( 'Passengers' ) }</dt>
					<dd>{ booking.passenger_count }</dd>
				</div>
				{ cfg.vehiclesEnabled && booking.vehicle_count > 0 ? (
					<div>
						<dt>{ t( 'Vehicles' ) }</dt>
						<dd>{ booking.vehicle_count }</dd>
					</div>
				) : null }
				<div>
					<dt>{ t( 'Total' ) }</dt>
					<dd>{ money( booking.total ) }</dd>
				</div>
				{ detailed && booking.balance > 0 ? (
					<div>
						<dt>{ t( 'Still to pay' ) }</dt>
						<dd>{ money( booking.balance ) }</dd>
					</div>
				) : null }
			</dl>

			{ booking.tickets && booking.tickets.length > 0 ? (
				<p className="fbmb-record__actions">
					{ booking.tickets.map( ( ticket ) => (
						<a className="fbmb-button fbmb-button--secondary" href={ ticket.url } key={ ticket.url }>
							{ ticket.label }
						</a>
					) ) }
				</p>
			) : null }
		</article>
	);
}
