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
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsConfig } from '../lib/config';
import { useMpfbsHealth } from '../lib/health';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsFormatMoney } from '../lib/money';
import { mpfbsNavigate } from '../lib/router';

interface Metric {
	label: string;
	value: number | string;
	hint: string;
	money?: boolean;
	tone?: string;
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
	pending: 'card',
	cancelled: 'close',
	refunds: 'alert',
};

const ORDER = [ 'sailings', 'passengers', 'vehicles', 'revenue', 'pending', 'cancelled', 'refunds' ];

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
			const response = await mpfbsRequest< Snapshot >( 'dashboard' );
			setSnapshot( response.data );
		} catch ( caught: unknown ) {
			setError( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ) );
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
				<PageHeader title={ mpfbsText( 'Dashboard' ) } />
				<ErrorState message={ error } onRetry={ load } />
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ mpfbsText( 'Today' ) }
				description={
					snapshot
						? mpfbsFormat( 'Operating day %s', snapshot.date )
						: mpfbsText( 'Your sailings, passengers and takings for today.' )
				}
			/>

			<div className="mpfbs-stat-grid">
				{ ORDER.map( ( key ) => {
					const metric = snapshot?.metrics[ key ];

					return (
						<StatCard
							key={ key }
							label={ metric ? mpfbsText( metric.label ) : '' }
							value={
								metric
									? metric.money
										? mpfbsFormatMoney( Number( metric.value ) )
										: Number( metric.value ).toLocaleString()
									: undefined
							}
							hint={ metric?.hint ? mpfbsText( metric.hint ) : undefined }
							icon={ ICONS[ key ] }
							loading={ loading }
							tone={ metric?.tone === 'warning' ? 'warning' : 'default' }
						/>
					);
				} ) }
			</div>

			{ snapshot?.capped ? (
				<div className="mpfbs-alert mpfbs-alert--info" role="status">
					{ mpfbsText( 'Today is busier than these totals can add up exactly. The figures are a floor, not a total.' ) }
				</div>
			) : null }

			<div className="mpfbs-dash">
				<section className="mpfbs-panel">
					<div className="mpfbs-panel__header">
						<div>
							<h2 className="mpfbs-panel__title">{ mpfbsText( 'Today’s departures' ) }</h2>
							<p className="mpfbs-panel__description">{ mpfbsText( 'How full each crossing is, right now.' ) }</p>
						</div>
						<div className="mpfbs-panel__actions">
							<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => mpfbsNavigate( '/sailings' ) }>
								{ mpfbsText( 'All sailings' ) }
							</button>
						</div>
					</div>

					{ ! loading && snapshot && snapshot.departures.length === 0 ? (
						<EmptyState
							icon="route"
							title={ mpfbsText( 'Nothing sails today.' ) }
							description={ mpfbsText( 'Schedule a departure and it will appear here.' ) }
						/>
					) : (
						<ul className="mpfbs-departures">
							{ ( snapshot?.departures ?? [] ).map( ( row ) => (
								<li className="mpfbs-departure" key={ row.id }>
									<span className="mpfbs-departure__time">{ row.time }</span>

									<span className="mpfbs-departure__detail">
										<span className="mpfbs-departure__route">{ row.route }</span>
										<span className="mpfbs-departure__vessel">
											{ row.vessel }
											{ row.vehicles > 0
												? ` · ${ mpfbsFormat( '%s vehicles', String( row.vehicles ) ) }`
												: '' }
										</span>
									</span>

									<span className="mpfbs-departure__load">
										{ row.load < 0 ? (
											<span className="mpfbs-muted">{ mpfbsFormat( '%s booked', String( row.booked ) ) }</span>
										) : (
											<>
												<span className="mpfbs-departure__figures">
													{ mpfbsFormat( '%1$s of %2$s', String( row.booked ), String( row.capacity ) ) }
												</span>
												<span
													className={ `mpfbs-meter mpfbs-meter--${ row.load >= 90 ? 'full' : row.load >= 60 ? 'busy' : 'quiet' }` }
													role="img"
													aria-label={ mpfbsFormat( '%s%% full', String( row.load ) ) }
												>
													<span className="mpfbs-meter__fill" style={ { inlineSize: `${ Math.min( 100, row.load ) }%` } } />
												</span>
											</>
										) }
									</span>

									{ row.status !== 'scheduled' ? (
										<span className={ `mpfbs-pill mpfbs-pill--${ row.status === 'cancelled' ? 'danger' : 'warning' }` }>
											{ mpfbsText( statusLabel( row.status ) ) }
										</span>
									) : (
										<span />
									) }
								</li>
							) ) }
						</ul>
					) }
				</section>

				<section className="mpfbs-panel">
					<div className="mpfbs-panel__header">
						<div>
							<h2 className="mpfbs-panel__title">{ mpfbsText( 'Latest bookings' ) }</h2>
						</div>
						<div className="mpfbs-panel__actions">
							<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ () => mpfbsNavigate( '/bookings' ) }>
								{ mpfbsText( 'All bookings' ) }
							</button>
						</div>
					</div>

					{ ! loading && snapshot && snapshot.recent.length === 0 ? (
						<EmptyState icon="ticket" title={ mpfbsText( 'No bookings yet.' ) } />
					) : (
						<ul className="mpfbs-recent">
							{ ( snapshot?.recent ?? [] ).map( ( row ) => (
								<li className="mpfbs-recent__row" key={ row.id }>
									<span className="mpfbs-recent__main">
										<code className="mpfbs-code">{ row.reference }</code>
										<span className="mpfbs-recent__customer">{ row.customer || '—' }</span>
										<span className="mpfbs-recent__departure">{ row.departure || '—' }</span>
									</span>
									<span className="mpfbs-recent__meta">
										<span className="mpfbs-recent__total">{ mpfbsFormatMoney( row.total ) }</span>
										<span className={ `mpfbs-pill mpfbs-pill--${ paymentTone( row.payment ) }` }>
											{ mpfbsText( statusLabel( row.payment ) ) }
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
	const { health } = useMpfbsHealth();
	const config = mpfbsConfig();

	const items = [
		{ label: mpfbsText( 'Plugin' ), value: config.version },
		{ label: mpfbsText( 'WordPress' ), value: health?.wp ?? '—' },
		{ label: mpfbsText( 'PHP' ), value: health?.php ?? '—' },
		{ label: mpfbsText( 'Timezone' ), value: config.timezone },
		{
			label: mpfbsText( 'WooCommerce' ),
			value: config.woocommerce ? mpfbsText( 'Active' ) : mpfbsText( 'Not installed' ),
		},
	];

	return (
		<details className="mpfbs-system">
			<summary className="mpfbs-system__summary">
				<Icon name="settings" size={ 14 } />
				<span>{ mpfbsText( 'System information' ) }</span>
			</summary>
			<dl className="mpfbs-system__grid">
				{ items.map( ( item ) => (
					<div className="mpfbs-system__item" key={ item.label }>
						<dt>{ item.label }</dt>
						<dd>{ item.value }</dd>
					</div>
				) ) }
			</dl>
		</details>
	);
}
