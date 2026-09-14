/**
 * The signed-in customer's own bookings.
 *
 * A history is a list, not a stack of posters. Somebody opening this page is
 * looking for one crossing among many — the one next week, or the one they need
 * a receipt for — so the page is built around finding it: a search box that
 * filters as you type, upcoming and past kept apart, and pages rather than an
 * endless scroll.
 *
 * Each row carries what identifies a booking (reference, route, when) and what
 * a customer acts on (what is left to pay, the tickets). Everything else is a
 * detail they can open.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type JSX } from 'preact/compat';

import { ApiError, requestWithMeta } from '../lib/api';
import { config } from '../lib/config';
import { date as formatDate, time } from '../lib/datetime';
import { t, tf } from '../lib/i18n';
import { money } from '../lib/money';
import type { CustomerBooking } from '../lib/types';
import { BookingCard } from './BookingCard';
import { Badge, Empty, ErrorState, Skeleton } from './ui';

type When = 'upcoming' | 'past' | 'all';

interface HistoryPayload {
	items: CustomerBooking[];
	counts: { all: number; upcoming: number; past: number };
	/** Every status the customer's history contains, not just this page. */
	statuses: Record< string, string >;
	capped: boolean;
}

interface HistoryMeta {
	page?: number;
	per_page?: number;
	total?: number;
	total_pages?: number;
}

export interface MyBookingsProps {
	/** Managed page URLs, published with the mount point. */
	pages?: Record< string, string >;
}

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

/**
 * Renders the customer's booking history.
 */
export function MyBookings( { pages = {} }: MyBookingsProps ): JSX.Element {
	const cfg = config();
	const lookupUrl = pages.lookup ?? '';

	const [ when, setWhen ] = useState< When >( 'upcoming' );
	const [ search, setSearch ] = useState( '' );
	const [ typed, setTyped ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ perPage, setPerPage ] = useState( 20 );
	const [ page, setPage ] = useState( 1 );

	const [ data, setData ] = useState< HistoryPayload | null >( null );
	const [ meta, setMeta ] = useState< HistoryMeta >( {} );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ attempt, setAttempt ] = useState( 0 );
	const [ openRef, setOpenRef ] = useState( '' );

	// Typing filters, but not on every keystroke: a search that fires per letter
	// makes a slow connection feel broken and asks the server the same question
	// five times.
	const timer = useRef< number | undefined >( undefined );

	useEffect( () => {
		window.clearTimeout( timer.current );

		timer.current = window.setTimeout( () => {
			setSearch( typed.trim() );
			setPage( 1 );
		}, 300 );

		return () => window.clearTimeout( timer.current );
	}, [ typed ] );

	useEffect( () => {
		if ( ! cfg.loggedIn ) {
			setLoading( false );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );
		setError( '' );

		requestWithMeta< HistoryPayload >( 'bookings/mine', {
			query: { when, search, status, page, per_page: perPage },
		} )
			.then( ( payload ) => {
				if ( cancelled ) {
					return;
				}

				setData( payload.data );
				setMeta( payload.meta );
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setError( caught instanceof ApiError ? caught.message : t( 'Something went wrong.' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ cfg.loggedIn, when, search, status, page, perPage, attempt ] );

	const statuses = useMemo( () => Object.entries( data?.statuses ?? {} ), [ data ] );

	const choose = useCallback( ( next: When ) => {
		setWhen( next );
		setPage( 1 );
	}, [] );

	if ( ! cfg.loggedIn ) {
		return (
			<div className="fbmb-panel">
				<Empty
					title={ t( 'Please sign in to see your bookings' ) }
					description={ t( 'Booked as a guest? Use the reference from your confirmation email to find it instead.' ) }
				/>
				{ lookupUrl !== '' ? (
					<p className="fbmb-panel__action">
						<a className="fbmb-button fbmb-button--secondary" href={ lookupUrl }>
							{ t( 'Find my booking' ) }
						</a>
					</p>
				) : null }
			</div>
		);
	}

	if ( error !== '' ) {
		return <ErrorState message={ error } onRetry={ () => setAttempt( ( n ) => n + 1 ) } />;
	}

	const counts = data?.counts ?? { all: 0, upcoming: 0, past: 0 };
	const total = Number( meta.total ?? 0 );
	const pageCount = Number( meta.total_pages ?? 0 );
	const items = data?.items ?? [];
	const filtered = search !== '' || status !== '';

	// Nothing at all, ever — a different message from "nothing matched".
	if ( ! loading && counts.all === 0 && ! filtered ) {
		return (
			<Empty
				title={ t( 'You have no bookings yet' ) }
				description={ t( 'Crossings you book will appear here.' ) }
			/>
		);
	}

	const first = total === 0 ? 0 : ( Number( meta.page ?? 1 ) - 1 ) * Number( meta.per_page ?? perPage ) + 1;
	const last = Math.min( total, first + items.length - 1 );

	return (
		<div className="fbmb-history">
			<div className="fbmb-history__toolbar">
				<div className="fbmb-segmented" role="group" aria-label={ t( 'Which bookings' ) }>
					{ ( [
						[ 'upcoming', t( 'Upcoming' ), counts.upcoming ],
						[ 'past', t( 'Past' ), counts.past ],
						[ 'all', t( 'All' ), counts.all ],
					] as Array< [ When, string, number ] > ).map( ( [ id, label, count ] ) => (
						<button
							type="button"
							key={ id }
							className={ `fbmb-segmented__option${ when === id ? ' is-selected' : '' }` }
							aria-pressed={ when === id }
							onClick={ () => choose( id ) }
						>
							{ label }
							<span className="fbmb-segmented__count">{ count }</span>
						</button>
					) ) }
				</div>

				<div className="fbmb-history__filters">
					<label className="fbmb-history__search">
						<span className="fbmb-visually-hidden">{ t( 'Search your bookings' ) }</span>
						<input
							type="search"
							className="fbmb-input"
							value={ typed }
							placeholder={ t( 'Search by reference, route or port' ) }
							onInput={ ( event ) => setTyped( ( event.target as HTMLInputElement ).value ) }
						/>
					</label>

					{ statuses.length > 1 || status !== '' ? (
						<label className="fbmb-history__status">
							<span className="fbmb-visually-hidden">{ t( 'Filter by status' ) }</span>
							<select
								className="fbmb-select"
								value={ status }
								onChange={ ( event ) => {
									setStatus( ( event.target as HTMLSelectElement ).value );
									setPage( 1 );
								} }
							>
								<option value="">{ t( 'Any status' ) }</option>
								{ statuses.map( ( [ value, label ] ) => (
									<option value={ value } key={ value }>
										{ label }
									</option>
								) ) }
							</select>
						</label>
					) : null }
				</div>
			</div>

			{ data?.capped ? (
				<p className="fbmb-history__note" role="status">
					{ t( 'Only your most recent bookings are shown. Older crossings are not listed here — ask us if you need one.' ) }
				</p>
			) : null }

			{ loading ? <Skeleton rows={ 4 } /> : null }

			{ /* An empty tab and an empty search are different situations, and
			     "nothing matched" on a tab nobody has filtered reads as though
			     something is being hidden. */ }
			{ ! loading && items.length === 0 ? (
				filtered ? (
					<Empty
						title={ t( 'Nothing matched' ) }
						description={ t( 'Try a different reference, route or port, or clear the filters.' ) }
					/>
				) : (
					<Empty
						title={ when === 'past' ? t( 'No past crossings' ) : t( 'No upcoming crossings' ) }
						description={
							when === 'past'
								? t( 'Crossings you have already travelled on will appear here.' )
								: t( 'Book a crossing and it will appear here.' )
						}
					/>
				)
			) : null }

			{ ! loading && items.length > 0 ? (
				<>
					<p className="fbmb-history__summary" role="status">
						{ tf( 'Showing %1$s–%2$s of %3$s', String( first ), String( last ), String( total ) ) }
					</p>

					<ul className="fbmb-list">
						{ items.map( ( booking ) => (
							<HistoryRow
								booking={ booking }
								key={ booking.reference }
								open={ openRef === booking.reference }
								onToggle={ () =>
									setOpenRef( ( current ) => ( current === booking.reference ? '' : booking.reference ) )
								}
							/>
						) ) }
					</ul>

					{ pageCount > 1 ? (
						<nav className="fbmb-pager" aria-label={ t( 'Pages' ) }>
							<button
								type="button"
								className="fbmb-button fbmb-button--secondary"
								disabled={ page <= 1 }
								onClick={ () => setPage( ( n ) => Math.max( 1, n - 1 ) ) }
							>
								{ t( 'Previous' ) }
							</button>

							<span className="fbmb-pager__status">
								{ tf( 'Page %1$s of %2$s', String( meta.page ?? page ), String( pageCount ) ) }
							</span>

							<button
								type="button"
								className="fbmb-button fbmb-button--secondary"
								disabled={ page >= pageCount }
								onClick={ () => setPage( ( n ) => Math.min( pageCount, n + 1 ) ) }
							>
								{ t( 'Next' ) }
							</button>

							<label className="fbmb-pager__size">
								<span className="fbmb-visually-hidden">{ t( 'Bookings per page' ) }</span>
								<select
									className="fbmb-select"
									value={ String( perPage ) }
									onChange={ ( event ) => {
										setPerPage( Number( ( event.target as HTMLSelectElement ).value ) );
										setPage( 1 );
									} }
								>
									{ [ 20, 50, 100 ].map( ( size ) => (
										<option value={ String( size ) } key={ size }>
											{ tf( '%s per page', String( size ) ) }
										</option>
									) ) }
								</select>
							</label>
						</nav>
					) : null }
				</>
			) : null }
		</div>
	);
}

/**
 * Renders one booking as a row, expandable to the full card.
 */
function HistoryRow( {
	booking,
	open,
	onToggle,
}: {
	booking: CustomerBooking;
	open: boolean;
	onToggle: () => void;
} ): JSX.Element {
	const cfg = config();
	const leg = booking.legs[ 0 ];
	const ticket = booking.tickets?.[ 0 ];
	const panelId = `fbmb-detail-${ booking.reference }`;

	// English needs both forms, and a translator needs to see both to give the
	// right ones for their language.
	const party = [
		booking.passenger_count === 1
			? tf( '%s passenger', String( booking.passenger_count ) )
			: tf( '%s passengers', String( booking.passenger_count ) ),
		cfg.vehiclesEnabled && booking.vehicle_count > 0
			? booking.vehicle_count === 1
				? tf( '%s vehicle', String( booking.vehicle_count ) )
				: tf( '%s vehicles', String( booking.vehicle_count ) )
			: '',
	]
		.filter( Boolean )
		.join( ' · ' );

	return (
		<li className={ `fbmb-list__item${ open ? ' is-open' : '' }` }>
			<div className="fbmb-list__row">
				<div className="fbmb-list__ref">
					<span className="fbmb-list__reference">{ booking.reference }</span>
					<Badge tone={ tone( booking.status ) }>{ booking.status_label }</Badge>
				</div>

				<div className="fbmb-list__journey">
					{ leg ? (
						<>
							<span className="fbmb-list__route">
								{ leg.origin !== '' && leg.destination !== ''
									? tf( '%1$s to %2$s', leg.origin, leg.destination )
									: leg.route }
							</span>
							<span className="fbmb-list__when">
								{ `${ formatDate( leg.departure, true ) } · ${ time( leg.departure ) }` }
								{ booking.legs.length > 1 ? ` · ${ t( 'return' ) }` : '' }
							</span>
						</>
					) : (
						<span className="fbmb-list__when">{ t( 'Crossing details are no longer available.' ) }</span>
					) }
				</div>

				<div className="fbmb-list__party">{ party }</div>

				<div className="fbmb-list__money">
					<span className="fbmb-list__total">{ money( booking.total ) }</span>
					{ booking.balance > 0 ? (
						<span className="fbmb-list__balance">{ tf( '%s to pay', money( booking.balance ) ) }</span>
					) : null }
				</div>

				<div className="fbmb-list__actions">
					{ ticket ? (
						<a className="fbmb-button fbmb-button--secondary" href={ ticket.url }>
							{ t( 'Ticket' ) }
						</a>
					) : null }
					<button
						type="button"
						className="fbmb-button fbmb-button--ghost"
						aria-expanded={ open }
						aria-controls={ panelId }
						onClick={ onToggle }
					>
						{ open ? t( 'Hide details' ) : t( 'Details' ) }
					</button>
				</div>
			</div>

			<div className="fbmb-list__detail" id={ panelId } hidden={ ! open }>
				<BookingCard booking={ booking } detailed />
			</div>
		</li>
	);
}
