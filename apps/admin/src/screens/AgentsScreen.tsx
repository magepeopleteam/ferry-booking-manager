/**
 * Agencies.
 *
 * Two things an operator needs to see about a travel agency: what it owes and
 * what it has sold. Both are on the card, because a credit limit means nothing
 * without the balance next to it, and a commission rate means nothing without
 * the bookings it has been earned on.
 *
 * The wallet is presented as a statement — every movement showing the balance
 * before and after — rather than as a single editable number. An account whose
 * balance can simply be typed over is one nobody can reconcile.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { Drawer } from '../components/Drawer';
import { NumberField, SelectField, TextAreaField, TextField } from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { Tabs } from '../components/Tabs';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';

interface Agent {
	user_id: number;
	name: string;
	email: string;
	company: string;
	phone: string;
	status: string;
	status_label: string;
	commission_type: string;
	commission_rate: number;
	commission_label: string;
	credit_limit: number;
	credit_display: string;
	balance: number;
	balance_display: string;
	spendable: number;
	spendable_display: string;
	notes: string;
}

interface WalletRow {
	id: number;
	kind: string;
	kind_label: string;
	amount_display: string;
	previous_display: string;
	resulting_display: string;
	reference: string;
	actor: string;
	recorded: string;
	note: string;
}

interface Wallet {
	balance: number;
	balance_display: string;
	transactions: WalletRow[];
	page: number;
	total: number;
	total_pages: number;
}

interface AgentDetail extends Agent {
	commission: {
		bookings: number;
		total_bookings: number;
		sold_display: string;
		earned_display: string;
		reversed_display: string;
		window: number;
	};
	wallet: Wallet;
	types: Record< string, string >;
	statuses: Record< string, string >;
	kinds: Record< string, string >;
}

interface ListMeta {
	total?: number;
	commission_types?: Record< string, string >;
	statuses?: Record< string, string >;
}

/**
 * Renders the agencies screen.
 */
export function AgentsScreen(): JSX.Element {
	const config = fbmConfig();

	const [ agents, setAgents ] = useState< Agent[] >( [] );
	const [ meta, setMeta ] = useState< ListMeta >( {} );
	const [ search, setSearch ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ openId, setOpenId ] = useState( 0 );

	const load = useCallback( () => {
		if ( ! config.proActive ) {
			setLoading( false );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );
		setError( '' );

		fbmRequest< Agent[] >( 'agents', { query: { search, status, per_page: 50 } } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setAgents( response.data );
					setMeta( response.meta as ListMeta );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
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
	}, [ config.proActive, search, status ] );

	useEffect( () => load(), [ load ] );

	if ( ! config.proActive ) {
		return (
			<>
				<PageHeader title={ fbmText( 'Agents' ) } badge={ <span className="fbm-badge fbm-badge--pro">PRO</span> } />
				<EmptyState
					icon="briefcase"
					title={ fbmText( 'Available in MagePeople Ferry Booking System Pro.' ) }
					description={ fbmText(
						'Travel agencies with their own logins, commission terms, a prepaid account and a credit limit — each seeing only their own bookings.'
					) }
				/>
			</>
		);
	}

	const statusOptions = [
		{ value: '', label: fbmText( 'Any status' ) },
		...Object.entries( meta.statuses ?? {} ).map( ( [ value, label ] ) => ( { value, label } ) ),
	];

	return (
		<>
			<PageHeader
				title={ fbmText( 'Agents' ) }
				description={ fbmText( 'Agencies selling on your behalf.' ) }
			/>

			<div className="fbm-panel">
				<p className="fbm-panel__description">
					{ fbmText(
						'An agency is a WordPress user holding the Ferry Agent role. Create one under Users, then set its commercial terms here.'
					) }
				</p>

				<div className="fbm-settings">
					<TextField
						label={ fbmText( 'Search' ) }
						name="agent_search"
						value={ search }
						onChange={ setSearch }
						placeholder={ fbmText( 'Name, company or email' ) }
					/>
					<SelectField
						label={ fbmText( 'Status' ) }
						name="agent_status"
						value={ status }
						options={ statusOptions }
						onChange={ setStatus }
					/>
				</div>
			</div>

			{ error !== '' ? <ErrorState message={ error } onRetry={ load } /> : null }
			{ loading ? <LoadingState rows={ 4 } /> : null }

			{ ! loading && agents.length === 0 && error === '' ? (
				<EmptyState
					icon="briefcase"
					title={ fbmText( 'No agencies yet.' ) }
					description={ fbmText( 'Give a user the Ferry Agent role and they will appear here.' ) }
				/>
			) : null }

			{ ! loading && agents.length > 0 ? (
				<div className="fbm-agentgrid">
					{ agents.map( ( agent ) => (
						<button type="button" className="fbm-agentcard" key={ agent.user_id } onClick={ () => setOpenId( agent.user_id ) }>
							<span className="fbm-agentcard__head">
								<span className="fbm-agentcard__name">{ agent.company !== '' ? agent.company : agent.name }</span>
								<span className={ `fbm-pill fbm-pill--${ agent.status === 'active' ? 'positive' : 'warning' }` }>
									{ agent.status_label }
								</span>
							</span>
							<span className="fbm-agentcard__sub">{ agent.email }</span>
							<span className="fbm-agentcard__figures">
								<span>
									<span className="fbm-agentcard__label">{ fbmText( 'Balance' ) }</span>
									<span className={ `fbm-agentcard__value${ agent.balance < 0 ? ' is-negative' : '' }` }>
										{ agent.balance_display }
									</span>
								</span>
								<span>
									<span className="fbm-agentcard__label">{ fbmText( 'Can spend' ) }</span>
									<span className="fbm-agentcard__value">{ agent.spendable_display }</span>
								</span>
								<span>
									<span className="fbm-agentcard__label">{ fbmText( 'Commission' ) }</span>
									<span className="fbm-agentcard__value">{ agent.commission_label }</span>
								</span>
							</span>
						</button>
					) ) }
				</div>
			) : null }

			<AgentDrawer agentId={ openId } onClose={ () => setOpenId( 0 ) } onSaved={ load } />
		</>
	);
}

/**
 * Renders the drawer for one agency.
 */
function AgentDrawer( { agentId, onClose, onSaved }: { agentId: number; onClose: () => void; onSaved: () => void } ): JSX.Element | null {
	const toast = useFbmToast();
	const [ detail, setDetail ] = useState< AgentDetail | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ tab, setTab ] = useState( 'terms' );
	const [ form, setForm ] = useState< Record< string, string > >( {} );
	const [ saving, setSaving ] = useState( false );

	const [ moveKind, setMoveKind ] = useState( 'credit' );
	const [ moveAmount, setMoveAmount ] = useState( '' );
	const [ moveReference, setMoveReference ] = useState( '' );
	const [ moveNote, setMoveNote ] = useState( '' );
	const [ moving, setMoving ] = useState( false );

	const load = useCallback( () => {
		if ( agentId === 0 ) {
			setDetail( null );
			return undefined;
		}

		let cancelled = false;
		setLoading( true );

		fbmRequest< AgentDetail >( `agents/${ agentId }` )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setDetail( response.data );
				setForm( {
					company: response.data.company,
					phone: response.data.phone,
					status: response.data.status,
					commission_type: response.data.commission_type,
					// A percentage is a plain number; a fixed amount is money, so
					// it is shown in major units the way it was typed.
					commission_rate:
						response.data.commission_type === 'percent'
							? String( response.data.commission_rate )
							: ( response.data.commission_rate / 100 ).toFixed( 2 ),
					credit_limit: ( response.data.credit_limit / 100 ).toFixed( 2 ),
					notes: response.data.notes,
				} );
				setTab( 'terms' );
			} )
			.catch( () => undefined )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ agentId ] );

	useEffect( () => load(), [ load ] );

	const set = useCallback( ( key: string, value: string ) => {
		setForm( ( current ) => ( { ...current, [ key ]: value } ) );
	}, [] );

	const save = useCallback( async () => {
		setSaving( true );

		try {
			const response = await fbmRequest< AgentDetail >( `agents/${ agentId }`, { method: 'PUT', body: form } );
			setDetail( response.data );
			toast.notify( fbmText( 'Agency saved.' ), 'success' );
			onSaved();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ agentId, form, onSaved, toast ] );

	const move = useCallback( async () => {
		setMoving( true );

		try {
			const response = await fbmRequest< Wallet >( `agents/${ agentId }/wallet`, {
				method: 'POST',
				body: { kind: moveKind, amount: moveAmount, reference: moveReference, note: moveNote },
			} );

			setDetail( ( current ) => ( current ? { ...current, wallet: response.data, balance: response.data.balance, balance_display: response.data.balance_display } : current ) );
			setMoveAmount( '' );
			setMoveReference( '' );
			setMoveNote( '' );
			toast.notify( fbmFormat( 'Recorded. The account now holds %s.', response.data.balance_display ), 'success' );
			onSaved();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setMoving( false );
		}
	}, [ agentId, moveKind, moveAmount, moveReference, moveNote, onSaved, toast ] );

	if ( agentId === 0 ) {
		return null;
	}

	const isPercent = ( form.commission_type ?? 'percent' ) === 'percent';

	return (
		<Drawer
			open={ true }
			title={ detail ? ( detail.company !== '' ? detail.company : detail.name ) : fbmText( 'Agency' ) }
			description={ detail?.email }
			onClose={ onClose }
			width="wide"
			footer={
				tab === 'terms' && detail ? (
					<>
						<button type="button" className="fbm-button fbm-button--secondary" onClick={ onClose }>
							{ fbmText( 'Close' ) }
						</button>
						<button type="button" className="fbm-button fbm-button--primary" onClick={ save } disabled={ saving }>
							{ saving ? fbmText( 'Saving…' ) : fbmText( 'Save agency' ) }
						</button>
					</>
				) : null
			}
		>
			{ loading ? <LoadingState rows={ 4 } /> : null }

			{ detail ? (
				<>
					<div className="fbm-stat-grid">
						<Figure label={ fbmText( 'Balance' ) } value={ detail.wallet.balance_display } negative={ detail.wallet.balance < 0 } />
						<Figure label={ fbmText( 'Credit limit' ) } value={ detail.credit_display } />
						<Figure label={ fbmText( 'Can still spend' ) } value={ detail.spendable_display } />
						<Figure label={ fbmText( 'Commission earned' ) } value={ detail.commission.earned_display } />
					</div>

					<Tabs
						label={ fbmText( 'Agency' ) }
						active={ tab }
						onSelect={ setTab }
						tabs={ [
							{ id: 'terms', label: fbmText( 'Terms' ) },
							{ id: 'wallet', label: fbmText( 'Account' ) },
							{ id: 'trading', label: fbmText( 'Trading' ) },
						] }
					/>

					{ tab === 'terms' ? (
						<div className="fbm-settings">
							<TextField label={ fbmText( 'Trading name' ) } name="company" value={ form.company ?? '' } onChange={ ( v ) => set( 'company', v ) } />
							<TextField label={ fbmText( 'Telephone' ) } name="phone" value={ form.phone ?? '' } onChange={ ( v ) => set( 'phone', v ) } />
							<SelectField
								label={ fbmText( 'Status' ) }
								name="status"
								value={ form.status ?? 'active' }
								options={ Object.entries( detail.statuses ).map( ( [ value, label ] ) => ( { value, label } ) ) }
								onChange={ ( v ) => set( 'status', v ) }
								hint={ fbmText( 'A suspended agency keeps its bookings and its balance but cannot make new ones.' ) }
							/>
							<SelectField
								label={ fbmText( 'How commission is worked out' ) }
								name="commission_type"
								value={ form.commission_type ?? 'percent' }
								options={ Object.entries( detail.types ).map( ( [ value, label ] ) => ( { value, label } ) ) }
								onChange={ ( v ) => set( 'commission_type', v ) }
							/>
							<NumberField
								label={ isPercent ? fbmText( 'Commission rate' ) : fbmText( 'Commission amount' ) }
								name="commission_rate"
								value={ Number( form.commission_rate ?? 0 ) }
								onChange={ ( v ) => set( 'commission_rate', String( v ) ) }
								min={ 0 }
								step={ 0.01 }
								suffix={ isPercent ? '%' : '' }
								hint={ fbmText( 'Commission is earned when a booking is paid, and taken back if it is cancelled.' ) }
							/>
							<NumberField
								label={ fbmText( 'Credit limit' ) }
								name="credit_limit"
								value={ Number( form.credit_limit ?? 0 ) }
								onChange={ ( v ) => set( 'credit_limit', String( v ) ) }
								min={ 0 }
								step={ 0.01 }
								hint={ fbmText( 'How far the account may go below zero. Leave at nothing to require payment up front.' ) }
							/>
							<TextAreaField label={ fbmText( 'Internal notes' ) } name="notes" value={ form.notes ?? '' } onChange={ ( v ) => set( 'notes', v ) } rows={ 3 } />
						</div>
					) : null }

					{ tab === 'wallet' ? (
						<>
							<h3 className="fbm-subheading">{ fbmText( 'Record a movement' ) }</h3>
							<p className="fbm-panel__description">
								{ fbmText(
									'Movements cannot be edited or deleted. To correct a mistake, record an adjustment — the error and the correction both stay on the statement.'
								) }
							</p>

							<div className="fbm-settings">
								<SelectField
									label={ fbmText( 'Kind' ) }
									name="wallet_kind"
									value={ moveKind }
									options={ [ 'credit', 'debit', 'refund', 'adjustment' ].map( ( value ) => ( {
										value,
										label: detail.kinds[ value ] ?? value,
									} ) ) }
									onChange={ setMoveKind }
									hint={
										moveKind === 'adjustment'
											? fbmText( 'An adjustment keeps its sign: enter a negative amount to take money off.' )
											: ''
									}
								/>
								<TextField label={ fbmText( 'Amount' ) } name="wallet_amount" value={ moveAmount } onChange={ setMoveAmount } placeholder="0.00" />
								<TextField label={ fbmText( 'Reference' ) } name="wallet_reference" value={ moveReference } onChange={ setMoveReference } placeholder={ fbmText( 'Bank transfer, invoice number…' ) } />
								<TextAreaField label={ fbmText( 'Note' ) } name="wallet_note" value={ moveNote } onChange={ setMoveNote } rows={ 2 } />
							</div>

							<div className="fbm-drawer__actions">
								<button type="button" className="fbm-button fbm-button--primary" onClick={ move } disabled={ moving || moveAmount === '' }>
									{ moving ? fbmText( 'Recording…' ) : fbmText( 'Record it' ) }
								</button>
							</div>

							<h3 className="fbm-subheading">{ fbmText( 'Statement' ) }</h3>

							{ detail.wallet.transactions.length === 0 ? (
								<EmptyState icon="card" title={ fbmText( 'Nothing has moved on this account yet.' ) } />
							) : (
								<div className="fbm-table__scroll">
									<table className="fbm-table">
										<thead>
											<tr>
												<th scope="col">{ fbmText( 'When' ) }</th>
												<th scope="col">{ fbmText( 'Kind' ) }</th>
												<th scope="col">{ fbmText( 'Movement' ) }</th>
												<th scope="col">{ fbmText( 'Before' ) }</th>
												<th scope="col">{ fbmText( 'After' ) }</th>
												<th scope="col">{ fbmText( 'Reference' ) }</th>
												<th scope="col">{ fbmText( 'By' ) }</th>
											</tr>
										</thead>
										<tbody>
											{ detail.wallet.transactions.map( ( row ) => (
												<tr key={ row.id }>
													<td>{ row.recorded }</td>
													<td>{ row.kind_label }</td>
													<td>{ row.amount_display }</td>
													<td>{ row.previous_display }</td>
													<td>{ row.resulting_display }</td>
													<td>{ row.reference }</td>
													<td>{ row.actor }</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							) }
						</>
					) : null }

					{ tab === 'trading' ? (
						<>
							<div className="fbm-stat-grid">
								<Figure label={ fbmText( 'Bookings' ) } value={ String( detail.commission.total_bookings ) } />
								<Figure label={ fbmText( 'Sold' ) } value={ detail.commission.sold_display } />
								<Figure label={ fbmText( 'Commission earned' ) } value={ detail.commission.earned_display } />
								<Figure label={ fbmText( 'Commission reversed' ) } value={ detail.commission.reversed_display } />
							</div>

							<p className="fbm-panel__description">
								{ fbmFormat(
									'Totalled over this agency’s %s most recent bookings.',
									String( detail.commission.window )
								) }
							</p>
						</>
					) : null }
				</>
			) : null }
		</Drawer>
	);
}

/**
 * Renders one figure.
 */
function Figure( { label, value, negative }: { label: string; value: string; negative?: boolean } ): JSX.Element {
	return (
		<div className="fbm-stat">
			<span className="fbm-stat__label">{ label }</span>
			<span className={ `fbm-stat__value${ negative ? ' is-negative' : '' }` }>{ value }</span>
		</div>
	);
}
