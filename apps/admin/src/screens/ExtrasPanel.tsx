/**
 * Extras.
 *
 * The thing an operator gets wrong here is the charging basis: whether "meal"
 * means one meal or one each. So the basis is a set of plain sentences rather
 * than a dropdown of jargon, and every extra shows a worked example — "a party
 * of 3 with 1 vehicle pays £36.00" — recalculated as the price is typed, so the
 * answer is visible before a customer finds it.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

import { NumberField, SelectField, SwitchField, TextAreaField, TextField } from '../components/Fields';
import { SaveBar } from '../components/SaveBar';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney, fbmToMajor, fbmToMinor } from '../lib/money';

interface Basis {
	value: string;
	label: string;
	hint: string;
}

interface ExtraRow {
	id: string;
	label: string;
	description: string;
	active: boolean;
	price: number;
	basis: string;
	per_direction: boolean;
	max_quantity: number;
	routes: number[];
}

interface Payload {
	extras: ExtraRow[];
	bases: Basis[];
}

/** The worked example is priced against a plausible party, not an empty one. */
const EXAMPLE_PASSENGERS = 3;
const EXAMPLE_VEHICLES = 1;
const EXAMPLE_UNITS = 2;

/**
 * Renders the extras panel.
 */
export function ExtrasPanel(): JSX.Element {
	const config = fbmConfig();
	const toast = useFbmToast();

	const [ payload, setPayload ] = useState< Payload | null >( null );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ open, setOpen ] = useState( '' );

	const load = useCallback( () => {
		let cancelled = false;
		setError( '' );

		fbmRequest< Payload >( 'extras' )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setPayload( response.data );
					setDirty( false );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( ! cancelled ) {
					setError( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! config.proActive ) {
			return undefined;
		}

		return load();
	}, [ config.proActive, load ] );

	const update = useCallback( ( id: string, changes: Partial< ExtraRow > ) => {
		setPayload( ( state ) =>
			state
				? { ...state, extras: state.extras.map( ( row ) => ( row.id === id ? { ...row, ...changes } : row ) ) }
				: state
		);
		setDirty( true );
	}, [] );

	const add = useCallback( () => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			const id = `x${ Date.now().toString( 36 ) }`;
			setOpen( id );

			return {
				...state,
				extras: [
					...state.extras,
					{
						id,
						label: fbmText( 'New extra' ),
						description: '',
						// Off until it has been priced. An extra that appears on
						// the booking form at zero is worse than one that is not
						// there yet.
						active: false,
						price: 0,
						basis: state.bases[ 0 ]?.value ?? 'booking',
						per_direction: false,
						max_quantity: 0,
						routes: [],
					},
				],
			};
		} );
		setDirty( true );
	}, [] );

	const remove = useCallback( ( id: string ) => {
		setPayload( ( state ) => ( state ? { ...state, extras: state.extras.filter( ( r ) => r.id !== id ) } : state ) );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! payload ) {
			return;
		}

		setSaving( true );

		try {
			const response = await fbmRequest< Payload >( 'extras', {
				method: 'PUT',
				body: { extras: payload.extras },
			} );
			setPayload( response.data );
			setDirty( false );
			toast.notify( fbmText( 'Extras saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ payload, toast ] );

	if ( ! config.proActive ) {
		return (
			<EmptyState
				icon="tag"
				title={ fbmText( 'Available in MagePeople Ferry Booking System Pro.' ) }
				description={ fbmText( 'Sell meals, pets, bicycles, priority boarding and anything else you carry, charged per booking, per passenger or per vehicle.' ) }
			/>
		);
	}

	if ( error !== '' ) {
		return <ErrorState message={ error } onRetry={ load } />;
	}

	if ( ! payload ) {
		return <LoadingState rows={ 4 } />;
	}

	return (
		<>
			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Extras' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'What a customer can add to a crossing. Only the ones switched on appear on the booking form.' ) }
						</p>
					</div>
					<button type="button" className="fbm-button fbm-button--primary" onClick={ add }>
						{ fbmText( 'Add an extra' ) }
					</button>
				</div>

				{ payload.extras.length === 0 ? (
					<EmptyState
						icon="tag"
						title={ fbmText( 'No extras yet' ) }
						description={ fbmText( 'Add one to start selling meals, pets or bicycles alongside a crossing.' ) }
					/>
				) : (
					<ul className="fbm-rules">
						{ payload.extras.map( ( row ) => {
							const basis = payload.bases.find( ( b ) => b.value === row.basis );

							return (
								<li className={ `fbm-rules__item${ row.active ? '' : ' is-off' }` } key={ row.id }>
									<div className="fbm-rules__head">
										<button
											type="button"
											className="fbm-rules__toggle"
											aria-expanded={ open === row.id }
											onClick={ () => setOpen( open === row.id ? '' : row.id ) }
										>
											<span className="fbm-rules__order">{ row.active ? '●' : '○' }</span>
											<span className="fbm-rules__name">{ row.label }</span>
											<span className="fbm-rules__sentence">
												{ fbmFormat(
													'%1$s, %2$s',
													fbmFormatMoney( row.price ),
													( basis?.label ?? row.basis ).toLowerCase()
												) }
											</span>
										</button>

										<div className="fbm-rules__controls">
											{ ! row.active ? <span className="fbm-pill">{ fbmText( 'Off' ) }</span> : null }
										</div>
									</div>

									{ open === row.id ? (
										<div className="fbm-rules__body">
											<div className="fbm-settings">
												<TextField
													label={ fbmText( 'Name' ) }
													name={ `extra_label_${ row.id }` }
													value={ row.label }
													hint={ fbmText( 'What the customer sees on the booking form.' ) }
													onChange={ ( value ) => update( row.id, { label: value } ) }
												/>

												<NumberField
													label={ fbmText( 'Price' ) }
													name={ `extra_price_${ row.id }` }
													value={ fbmToMajor( row.price ) }
													min={ 0 }
													step={ 0.01 }
													onChange={ ( value ) => update( row.id, { price: fbmToMinor( value ) } ) }
												/>

												<SelectField
													label={ fbmText( 'Charged' ) }
													name={ `extra_basis_${ row.id }` }
													value={ row.basis }
													options={ payload.bases.map( ( b ) => ( { value: b.value, label: b.label } ) ) }
													hint={ basis?.hint }
													onChange={ ( value ) => update( row.id, { basis: value } ) }
												/>

												<div className="fbm-settings__wide">
													<TextAreaField
														label={ fbmText( 'Description' ) }
														name={ `extra_desc_${ row.id }` }
														value={ row.description }
														hint={ fbmText( 'One line, shown under the name.' ) }
														onChange={ ( value ) => update( row.id, { description: value } ) }
													/>
												</div>

												{ row.basis === 'unit' ? (
													<NumberField
														label={ fbmText( 'Most one booking may add' ) }
														name={ `extra_max_${ row.id }` }
														value={ row.max_quantity }
														min={ 0 }
														hint={ fbmText( 'Zero means no limit.' ) }
														onChange={ ( value ) => update( row.id, { max_quantity: value } ) }
													/>
												) : null }

												<SwitchField
													label={ fbmText( 'Charge on each leg of a return' ) }
													checked={ row.per_direction }
													hint={ fbmText( 'On for something consumed on both crossings, like a meal. Off for something bought once, like insurance.' ) }
													onChange={ ( checked ) => update( row.id, { per_direction: checked } ) }
												/>

												<SwitchField
													label={ fbmText( 'On sale' ) }
													checked={ row.active }
													hint={ fbmText( 'Customers only see it once this is on.' ) }
													onChange={ ( checked ) => update( row.id, { active: checked } ) }
												/>
											</div>

											<p className="fbm-worked">
												<span className="fbm-worked__label">{ fbmText( 'For example' ) }</span>
												<span className="fbm-worked__text">{ example( row ) }</span>
											</p>

											<div className="fbm-rules__actions">
												<button
													type="button"
													className="fbm-button fbm-button--danger"
													onClick={ () => remove( row.id ) }
												>
													{ fbmText( 'Delete this extra' ) }
												</button>
											</div>
										</div>
									) : null }
								</li>
							);
						} ) }
					</ul>
				) }
			</div>

			<SaveBar dirty={ dirty } saving={ saving } onSave={ save } />
		</>
	);
}

/**
 * Describes what a plausible party would actually be charged.
 *
 * The whole point of the panel: an operator setting "meal, £12" needs to see
 * that a family of three is charged £36 before a family of three does.
 */
function example( row: ExtraRow ): string {
	const legs = row.per_direction ? 2 : 1;

	let units = 1;
	let who = '';

	if ( row.basis === 'passenger' ) {
		units = EXAMPLE_PASSENGERS;
		who = fbmFormat( '%s passengers', String( EXAMPLE_PASSENGERS ) );
	} else if ( row.basis === 'vehicle' ) {
		units = EXAMPLE_VEHICLES;
		who = fbmFormat( '%s vehicle', String( EXAMPLE_VEHICLES ) );
	} else if ( row.basis === 'unit' ) {
		units = row.max_quantity > 0 ? Math.min( EXAMPLE_UNITS, row.max_quantity ) : EXAMPLE_UNITS;
		who = fbmFormat( '%s added', String( units ) );
	}

	const total = row.price * units * legs;
	const perLeg = fbmFormatMoney( row.price * units );

	// A per-booking extra has nothing to count, so it gets its own sentence
	// rather than "a booking with a booking".
	if ( who === '' ) {
		return legs === 2
			? fbmFormat( 'A return booking pays %1$s — %2$s each way.', fbmFormatMoney( total ), perLeg )
			: fbmFormat( 'A booking pays %s.', fbmFormatMoney( total ) );
	}

	if ( legs === 2 ) {
		return fbmFormat(
			'A return booking with %1$s pays %2$s — %3$s each way.',
			who,
			fbmFormatMoney( total ),
			perLeg
		);
	}

	return fbmFormat( 'A booking with %1$s pays %2$s.', who, fbmFormatMoney( total ) );
}
