/**
 * Dashboard screen.
 *
 * What an operator opens the office with: which boats go out today, how full
 * they are, what was sold, and what still needs chasing. Environment details
 * live at the bottom because the PHP version matters on the day the plugin is
 * installed and never again.
 *
 * The figures come from the same repositories and the same availability engine
 * the booking flow uses, so this screen cannot disagree with what a customer
 * is shown.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { Icon, type IconName } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { StatCard } from '../components/StatCard';
import { EmptyState, ErrorState } from '../components/States';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { useFbmHealth } from '../lib/health';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';
import { fbmNavigate } from '../lib/router';

interface Metric {
	label: string;
	value: number | string;
	hint: string;
	money?: boolean;
	tone?: string;
	pro?: boolean;
}

interface Departure {
	id: number;
	departure: string;
	time: string;
	route: string;
	vessel: string;
	status: string;
	booked: number;
	capacity: number;
	load: number;
	vehicles: number;
}

interface RecentBooking {
	id: number;
	reference: string;
	customer: string;
	status: string;
	payment: string;
	total: number;
	departure: string;
}

interface Snapshot {
	date: string;
	today: string;
	metrics: Record< string, Metric >;
	departures: Departure[];
	recent: RecentBooking[];
	capped: boolean;
}

const ICONS: Record< string, IconName > = {
	sailings: 'route',
	passengers: 'users',
	vehicles: 'car',
	revenue: 'tag',
	checked_in: 'check',
	boarded: 'scan',
	pending: 'card',
	cancelled: 'close',
	refunds: 'alert',
};

const ORDER = [ 'sailings', 'passengers', 'vehicles', 'revenue', 'pending', 'checked_in', 'boarded', 'cancelled', 'refunds' ];

/**
 * Renders the dashboard.
 */
export function DashboardScreen(): JSX.Element {
	const [ snapshot, setSnapshot ] = useState< Snapshot | null >( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	const load = useCallback( async () => {
		setError( '' );

		try {
			const response = await fbmRequest< Snapshot >( 'dashboard' );
			setSnapshot( response.data );
		} catch ( caught: unknown ) {
			setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		void load();
	}, [ load ] );

	if ( error !== '' ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Dashboard' ) } />
				<ErrorState message={ error } onRetry={ load } />
			</>
		);
	}

	const proActive = fbmConfig().proActive;

	return (
		<>
			<PageHeader
				title={ fbmText( 'Today' ) }
				description={
					snapshot
						? fbmFormat( 'Operating day %s', snapshot.date )
						: fbmText( 'Your sailings, passengers and takings for today.' )
				}
			/>

			<div className="fbm-stat-grid">
				{ ORDER.map( ( key ) => {
					const metric = snapshot?.metrics[ key ];

					// Check-in and boarding are counted at a Pro check-in desk.
					// Showing them as a hard zero would read as "nobody turned
					// up" rather than "we are not measuring this".
					if ( metric?.pro && ! proActive ) {
						return (
							<StatCard
								key={ key }
								label={ fbmText( metric.label ) }
								value="—"
								hint={ fbmText( 'Needs Ferry Booking Manager Pro' ) }
								icon={ ICONS[ key ] }
							/>
						);
					}

					return (
						<StatCard
							key={ key }
							label={ metric ? fbmText( metric.label ) : '' }
							value={
								metric
									? metric.money
										? fbmFormatMoney( Number( metric.value ) )
										: Number( metric.value ).toLocaleString()
									: undefined
							}
							hint={ metric?.hint ? fbmText( metric.hint ) : undefined }
							icon={ ICONS[ key ] }
							loading={ loading }
							tone={ metric?.tone === 'warning' ? 'warning' : 'default' }
						/>
					);
				} ) }
			</div>

			{ snapshot?.capped ? (
				<div className="fbm-alert fbm-alert--info" role="status">
					{ fbmText( 'Today is busier than these totals can add up exactly. The figures are a floor, not a total.' ) }
				</div>
			) : null }

			<div className="fbm-dash">
				<section className="fbm-panel">
					<div className="fbm-panel__header">
						<div>
							<h2 className="fbm-panel__title">{ fbmText( 'Today’s departures' ) }</h2>
							<p className="fbm-panel__description">{ fbmText( 'How full each crossing is, right now.' ) }</p>
						</div>
						<div className="fbm-panel__actions">
							<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => fbmNavigate( '/sailings' ) }>
								{ fbmText( 'All sailings' ) }
							</button>
						</div>
					</div>

					{ ! loading && snapshot && snapshot.departures.length === 0 ? (
						<EmptyState
							icon="route"
							title={ fbmText( 'Nothing sails today.' ) }
							description={ fbmText( 'Schedule a departure and it will appear here.' ) }
						/>
					) : (
						<ul className="fbm-departures">
							{ ( snapshot?.departures ?? [] ).map( ( row ) => (
								<li className="fbm-departure" key={ row.id }>
									<span className="fbm-departure__time">{ row.time }</span>

									<span className="fbm-departure__detail">
										<span className="fbm-departure__route">{ row.route }</span>
										<span className="fbm-departure__vessel">
											{ row.vessel }
											{ row.vehicles > 0
												? ` · ${ fbmFormat( '%s vehicles', String( row.vehicles ) ) }`
												: '' }
										</span>
									</span>

									<span className="fbm-departure__load">
										{ row.load < 0 ? (
											<span className="fbm-muted">{ fbmFormat( '%s booked', String( row.booked ) ) }</span>
										) : (
											<>
												<span className="fbm-departure__figures">
													{ fbmFormat( '%1$s of %2$s', String( row.booked ), String( row.capacity ) ) }
												</span>
												<span
													className={ `fbm-meter fbm-meter--${ row.load >= 90 ? 'full' : row.load >= 60 ? 'busy' : 'quiet' }` }
													role="img"
													aria-label={ fbmFormat( '%s%% full', String( row.load ) ) }
												>
													<span className="fbm-meter__fill" style={ { inlineSize: `${ Math.min( 100, row.load ) }%` } } />
												</span>
											</>
										) }
									</span>

									{ row.status !== 'scheduled' ? (
										<span className={ `fbm-pill fbm-pill--${ row.status === 'cancelled' ? 'danger' : 'warning' }` }>
											{ fbmText( statusLabel( row.status ) ) }
										</span>
									) : (
										<span />
									) }
								</li>
							) ) }
						</ul>
					) }
				</section>

				<section className="fbm-panel">
					<div className="fbm-panel__header">
						<div>
							<h2 className="fbm-panel__title">{ fbmText( 'Latest bookings' ) }</h2>
						</div>
						<div className="fbm-panel__actions">
							<button type="button" className="fbm-button fbm-button--secondary" onClick={ () => fbmNavigate( '/bookings' ) }>
								{ fbmText( 'All bookings' ) }
							</button>
						</div>
					</div>

					{ ! loading && snapshot && snapshot.recent.length === 0 ? (
						<EmptyState icon="ticket" title={ fbmText( 'No bookings yet.' ) } />
					) : (
						<ul className="fbm-recent">
							{ ( snapshot?.recent ?? [] ).map( ( row ) => (
								<li className="fbm-recent__row" key={ row.id }>
									<span className="fbm-recent__main">
										<code className="fbm-code">{ row.reference }</code>
										<span className="fbm-recent__customer">{ row.customer || '—' }</span>
										<span className="fbm-recent__departure">{ row.departure || '—' }</span>
									</span>
									<span className="fbm-recent__meta">
										<span className="fbm-recent__total">{ fbmFormatMoney( row.total ) }</span>
										<span className={ `fbm-pill fbm-pill--${ paymentTone( row.payment ) }` }>
											{ fbmText( statusLabel( row.payment ) ) }
										</span>
									</span>
								</li>
							) ) }
						</ul>
					) }
				</section>
			</div>

			<SystemStrip />
		</>
	);
}

/**
 * Maps a status slug to its label.
 */
function statusLabel( status: string ): string {
	const labels: Record< string, string > = {
		scheduled: 'Scheduled',
		delayed: 'Delayed',
		departed: 'Departed',
		arrived: 'Arrived',
		cancelled: 'Cancelled',
		unpaid: 'Unpaid',
		partially_paid: 'Partially paid',
		paid: 'Paid',
		refunded: 'Refunded',
	};

	return labels[ status ] ?? status;
}

/**
 * Chooses a tone for a payment status.
 */
function paymentTone( payment: string ): string {
	if ( payment === 'paid' ) {
		return 'positive';
	}

	if ( payment === 'refunded' || payment === 'cancelled' ) {
		return 'muted';
	}

	return 'warning';
}

/**
 * Environment details, kept small and last.
 */
function SystemStrip(): JSX.Element {
	const { health } = useFbmHealth();
	const config = fbmConfig();

	const items = [
		{ label: fbmText( 'Plugin' ), value: config.version },
		{ label: fbmText( 'WordPress' ), value: health?.wp ?? '—' },
		{ label: fbmText( 'PHP' ), value: health?.php ?? '—' },
		{ label: fbmText( 'Timezone' ), value: config.timezone },
		{
			label: fbmText( 'WooCommerce' ),
			value: config.woocommerce ? fbmText( 'Active' ) : fbmText( 'Not installed' ),
		},
		{ label: fbmText( 'Pro' ), value: config.proActive ? fbmText( 'Active' ) : fbmText( 'Not active' ) },
	];

	return (
		<details className="fbm-system">
			<summary className="fbm-system__summary">
				<Icon name="settings" size={ 14 } />
				<span>{ fbmText( 'System information' ) }</span>
			</summary>
			<dl className="fbm-system__grid">
				{ items.map( ( item ) => (
					<div className="fbm-system__item" key={ item.label }>
						<dt>{ item.label }</dt>
						<dd>{ item.value }</dd>
					</div>
				) ) }
			</dl>
		</details>
	);
}
