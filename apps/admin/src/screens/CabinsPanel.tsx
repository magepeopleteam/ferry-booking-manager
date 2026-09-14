/**
 * Cabins.
 *
 * The number an operator gets wrong here is the difference between rooms and
 * beds. A four-cabin vessel with two berths each sells four cabins, not eight —
 * so the two are separate fields with a live sentence underneath spelling out
 * what the vessel will actually sell.
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

interface SoldOption {
	value: string;
	label: string;
	hint: string;
}

interface CabinRow {
	id: string;
	label: string;
	description: string;
	active: boolean;
	quantity: number;
	berths: number;
	price: number;
	sold_as: string;
	vessels: number[];
}

interface Payload {
	cabins: CabinRow[];
	sold: SoldOption[];
}

/**
 * Renders the cabins panel.
 */
export function CabinsPanel(): JSX.Element {
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

		fbmRequest< Payload >( 'cabins' )
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

	const update = useCallback( ( id: string, changes: Partial< CabinRow > ) => {
		setPayload( ( state ) =>
			state
				? { ...state, cabins: state.cabins.map( ( row ) => ( row.id === id ? { ...row, ...changes } : row ) ) }
				: state
		);
		setDirty( true );
	}, [] );

	const add = useCallback( () => {
		setPayload( ( state ) => {
			if ( ! state ) {
				return state;
			}

			const id = `c${ Date.now().toString( 36 ) }`;
			setOpen( id );

			return {
				...state,
				cabins: [
					...state.cabins,
					{
						id,
						label: fbmText( 'New cabin class' ),
						description: '',
						active: false,
						quantity: 0,
						berths: 2,
						price: 0,
						sold_as: state.sold[ 0 ]?.value ?? 'whole',
						vessels: [],
					},
				],
			};
		} );
		setDirty( true );
	}, [] );

	const remove = useCallback( ( id: string ) => {
		setPayload( ( state ) => ( state ? { ...state, cabins: state.cabins.filter( ( r ) => r.id !== id ) } : state ) );
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! payload ) {
			return;
		}

		setSaving( true );

		try {
			const response = await fbmRequest< Payload >( 'cabins', {
				method: 'PUT',
				body: { cabins: payload.cabins },
			} );
			setPayload( response.data );
			setDirty( false );
			toast.notify( fbmText( 'Cabins saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ payload, toast ] );

	if ( ! config.proActive ) {
		return (
			<EmptyState
				icon="ship"
				title={ fbmText( 'Available in Ferry Booking Manager Pro.' ) }
				description={ fbmText( 'Sell cabins and berths with their own stock, so the last family cabin selling out does not close the inside doubles.' ) }
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
						<h2 className="fbm-panel__title">{ fbmText( 'Cabins' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Each class has its own stock, so one selling out never affects another.' ) }
						</p>
					</div>
					<button type="button" className="fbm-button fbm-button--primary" onClick={ add }>
						{ fbmText( 'Add a cabin class' ) }
					</button>
				</div>

				{ payload.cabins.length === 0 ? (
					<EmptyState
						icon="ship"
						title={ fbmText( 'No cabin classes yet' ) }
						description={ fbmText( 'Add one to start selling overnight accommodation.' ) }
					/>
				) : (
					<ul className="fbm-rules">
						{ payload.cabins.map( ( row ) => {
							const sold = payload.sold.find( ( s ) => s.value === row.sold_as );

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
											<span className="fbm-rules__sentence">{ summary( row, sold ) }</span>
										</button>

										<div className="fbm-rules__controls">
											{ ! row.active ? <span className="fbm-pill">{ fbmText( 'Off' ) }</span> : null }
											{ row.active && row.quantity < 1 ? (
												<span className="fbm-pill fbm-pill--warning">{ fbmText( 'None on board' ) }</span>
											) : null }
										</div>
									</div>

									{ open === row.id ? (
										<div className="fbm-rules__body">
											<div className="fbm-settings">
												<TextField
													label={ fbmText( 'Name' ) }
													name={ `cabin_label_${ row.id }` }
													value={ row.label }
													hint={ fbmText( 'What the customer sees when choosing.' ) }
													onChange={ ( value ) => update( row.id, { label: value } ) }
												/>

												<NumberField
													label={ fbmText( 'Rooms of this class on board' ) }
													name={ `cabin_qty_${ row.id }` }
													value={ row.quantity }
													min={ 0 }
													hint={ fbmText( 'How many separate rooms the vessel has.' ) }
													onChange={ ( value ) => update( row.id, { quantity: value } ) }
												/>

												<NumberField
													label={ fbmText( 'Berths in each room' ) }
													name={ `cabin_berths_${ row.id }` }
													value={ row.berths }
													min={ 1 }
													hint={ fbmText( 'How many people sleep in one room.' ) }
													onChange={ ( value ) => update( row.id, { berths: value } ) }
												/>

												<SelectField
													label={ fbmText( 'Sold' ) }
													name={ `cabin_sold_${ row.id }` }
													value={ row.sold_as }
													options={ payload.sold.map( ( s ) => ( { value: s.value, label: s.label } ) ) }
													hint={ sold?.hint }
													onChange={ ( value ) => update( row.id, { sold_as: value } ) }
												/>

												<NumberField
													label={
														row.sold_as === 'berth'
															? fbmText( 'Price per berth' )
															: fbmText( 'Price per room' )
													}
													name={ `cabin_price_${ row.id }` }
													value={ fbmToMajor( row.price ) }
													min={ 0 }
													step={ 0.01 }
													onChange={ ( value ) => update( row.id, { price: fbmToMinor( value ) } ) }
												/>

												<div className="fbm-settings__wide">
													<TextAreaField
														label={ fbmText( 'Description' ) }
														name={ `cabin_desc_${ row.id }` }
														value={ row.description }
														hint={ fbmText( 'One line, shown under the name.' ) }
														onChange={ ( value ) => update( row.id, { description: value } ) }
													/>
												</div>

												<SwitchField
													label={ fbmText( 'On sale' ) }
													checked={ row.active }
													hint={ fbmText( 'Customers only see it once this is on and there is at least one room.' ) }
													onChange={ ( checked ) => update( row.id, { active: checked } ) }
												/>
											</div>

											<p className="fbm-worked">
												<span className="fbm-worked__label">{ fbmText( 'On a full sailing' ) }</span>
												<span className="fbm-worked__text">{ capacity( row ) }</span>
											</p>

											<div className="fbm-rules__actions">
												<button
													type="button"
													className="fbm-button fbm-button--danger"
													onClick={ () => remove( row.id ) }
												>
													{ fbmText( 'Delete this class' ) }
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
 * One-line summary for the collapsed row.
 */
function summary( row: CabinRow, sold?: SoldOption ): string {
	return fbmFormat(
		'%1$s, %2$s — %3$s',
		fbmFormatMoney( row.price ),
		( sold?.label ?? row.sold_as ).toLowerCase(),
		row.sold_as === 'berth'
			? fbmFormat( '%1$s rooms of %2$s berths', String( row.quantity ), String( row.berths ) )
			: fbmFormat( '%s rooms', String( row.quantity ) )
	);
}

/**
 * Spells out what the class will actually sell and earn when full.
 *
 * This is the whole reason the panel exists: rooms and berths are easy to
 * confuse, and the difference is a factor of two or four in revenue.
 */
function capacity( row: CabinRow ): string {
	if ( row.quantity < 1 ) {
		return fbmText( 'Nothing, until you say how many rooms the vessel has.' );
	}

	if ( row.sold_as === 'berth' ) {
		const berths = row.quantity * row.berths;

		return fbmFormat(
			'%1$s berths across %2$s rooms, earning %3$s.',
			String( berths ),
			String( row.quantity ),
			fbmFormatMoney( row.price * berths )
		);
	}

	return fbmFormat(
		'%1$s rooms sleeping up to %2$s people, earning %3$s.',
		String( row.quantity ),
		String( row.quantity * row.berths ),
		fbmFormatMoney( row.price * row.quantity )
	);
}
