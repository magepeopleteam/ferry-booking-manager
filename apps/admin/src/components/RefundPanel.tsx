/**
 * Refund a booking.
 *
 * Refunds get argued about afterwards, so the sum is shown worked out line by
 * line before anything is committed — what was paid, what is being cancelled,
 * what the penalty comes to, and what the customer actually gets back. The
 * figure is recalculated by the server, not here, so what is on screen is what
 * will be recorded.
 *
 * Nothing here moves money. It records what was agreed and what is owed; the
 * money goes back the way it came.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { NumberField, SelectField, SwitchField, TextField } from './Fields';
import { useFbmToast } from './Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmText } from '../lib/i18n';
import { fbmFormatMoney, fbmToMinor } from '../lib/money';

interface RefundResult {
	paid: number;
	already_refunded: number;
	eligible: number;
	removed: number;
	kept: number;
	penalty: number;
	penalty_label: string;
	refund: number;
	remaining: number;
	full: boolean;
	issued: boolean;
}

export interface RefundPanelProps {
	bookingId: number;
	/** Called after a refund is recorded, so the list can reload. */
	onIssued: () => void;
}

const MODES = [
	{ value: 'policy', label: 'Use the cancellation policy' },
	{ value: 'none', label: 'No penalty' },
	{ value: 'percent', label: 'A percentage of what is cancelled' },
	{ value: 'fixed', label: 'A fixed fee' },
];

/**
 * Renders the refund panel.
 */
export function RefundPanel( { bookingId, onIssued }: RefundPanelProps ): JSX.Element {
	const toast = useFbmToast();

	const [ mode, setMode ] = useState( 'policy' );
	const [ penalty, setPenalty ] = useState( 0 );
	const [ removed, setRemoved ] = useState( 0 );
	const [ partial, setPartial ] = useState( false );
	const [ reason, setReason ] = useState( '' );
	const [ result, setResult ] = useState< RefundResult | null >( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const body = useCallback(
		() => ( {
			penalty_mode: mode,
			penalty: mode === 'fixed' ? fbmToMinor( penalty ) : penalty,
			...( partial ? { removed: fbmToMinor( removed ) } : {} ),
			reason,
		} ),
		[ mode, penalty, partial, removed, reason ]
	);

	// The sum is re-worked whenever an input changes, so the operator is always
	// looking at the current answer rather than a stale one.
	useEffect( () => {
		let cancelled = false;
		setError( '' );

		fbmRequest< RefundResult >( `bookings/${ bookingId }/refund/preview`, { method: 'POST', body: body() } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setResult( response.data );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setResult( null );
					setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ bookingId, body ] );

	const issue = useCallback( async () => {
		setBusy( true );

		try {
			const response = await fbmRequest< RefundResult >( `bookings/${ bookingId }/refund/issue`, {
				method: 'POST',
				body: body(),
			} );
			toast.notify(
				`${ fbmText( 'Refund recorded:' ) } ${ fbmFormatMoney( response.data.refund ) }`,
				'success'
			);
			onIssued();
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setBusy( false );
		}
	}, [ bookingId, body, onIssued, toast ] );

	return (
		<div className="fbm-refund">
			<div className="fbm-settings">
				<SelectField
					label={ fbmText( 'Penalty' ) }
					name="refund_mode"
					value={ mode }
					options={ MODES.map( ( m ) => ( { value: m.value, label: fbmText( m.label ) } ) ) }
					onChange={ setMode }
				/>

				{ mode === 'percent' ? (
					<NumberField
						label={ fbmText( 'Penalty' ) }
						name="refund_penalty_percent"
						value={ penalty }
						min={ 0 }
						max={ 100 }
						suffix="%"
						onChange={ setPenalty }
					/>
				) : null }

				{ mode === 'fixed' ? (
					<NumberField
						label={ fbmText( 'Fee' ) }
						name="refund_penalty_fixed"
						value={ penalty }
						min={ 0 }
						step={ 0.01 }
						onChange={ setPenalty }
					/>
				) : null }

				<SwitchField
					label={ fbmText( 'Only part of the booking' ) }
					checked={ partial }
					hint={ fbmText( 'Leave off to cancel the whole booking. Turn it on to refund one cabin, one passenger or one extra.' ) }
					onChange={ ( checked ) => setPartial( checked ) }
				/>

				{ partial ? (
					<NumberField
						label={ fbmText( 'Value being cancelled' ) }
						name="refund_removed"
						value={ removed }
						min={ 0 }
						step={ 0.01 }
						hint={ fbmText( 'What the cancelled part was worth. The penalty applies to this, not to the whole booking.' ) }
						onChange={ setRemoved }
					/>
				) : null }

				<div className="fbm-settings__wide">
					<TextField
						label={ fbmText( 'Reason' ) }
						name="refund_reason"
						value={ reason }
						placeholder={ fbmText( 'Kept with the refund record' ) }
						onChange={ setReason }
					/>
				</div>
			</div>

			{ error !== '' ? (
				<p className="fbm-refund__error" role="alert">
					{ error }
				</p>
			) : null }

			{ result ? (
				<>
					<dl className="fbm-record__grid fbm-refund__sum">
						<Row label={ fbmText( 'Paid' ) } value={ fbmFormatMoney( result.paid ) } />
						{ result.already_refunded > 0 ? (
							<Row
								label={ fbmText( 'Already refunded' ) }
								value={ `−${ fbmFormatMoney( result.already_refunded ) }` }
							/>
						) : null }
						{ result.kept > 0 ? (
							<Row label={ fbmText( 'Not being cancelled' ) } value={ `−${ fbmFormatMoney( result.kept ) }` } />
						) : null }
						{ result.penalty > 0 ? (
							<Row
								label={ result.penalty_label !== '' ? result.penalty_label : fbmText( 'Penalty' ) }
								value={ `−${ fbmFormatMoney( result.penalty ) }` }
							/>
						) : null }
						<div className="fbm-record__row is-total">
							<dt className="fbm-record__label">{ fbmText( 'Customer gets back' ) }</dt>
							<dd className="fbm-record__value">{ fbmFormatMoney( result.refund ) }</dd>
						</div>
					</dl>

					<p className="fbm-refund__note">
						{ result.full
							? fbmText( 'This refunds everything paid, so the booking is cancelled and its places go back on sale.' )
							: fbmText( 'The booking stays on the crossing. Its places are not released.' ) }
					</p>

					<button
						type="button"
						className="fbm-button fbm-button--danger"
						disabled={ busy || result.refund < 1 }
						onClick={ issue }
					>
						{ busy
							? fbmText( 'Recording…' )
							: `${ fbmText( 'Record a refund of' ) } ${ fbmFormatMoney( result.refund ) }` }
					</button>
				</>
			) : null }
		</div>
	);
}

/**
 * Renders one line of the worked sum.
 */
function Row( { label, value }: { label: string; value: string } ): JSX.Element {
	return (
		<div className="fbm-record__row">
			<dt className="fbm-record__label">{ label }</dt>
			<dd className="fbm-record__value">{ value }</dd>
		</div>
	);
}
