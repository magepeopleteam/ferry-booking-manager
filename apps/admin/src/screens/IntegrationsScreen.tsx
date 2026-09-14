/**
 * Integration panels.
 *
 * Webhooks and the import/export tools, rendered inside Settings rather than
 * as a destination of their own. They are configuration an operator sets up
 * once, and a second top-level "Integrations" beside the Integrations settings
 * tab only made it ambiguous which one was the real thing.
 */

import { useCallback, useEffect, useRef, useState, type JSX } from 'react';

import { SelectField, SwitchField, TextField } from '../components/Fields';
import { SaveBar } from '../components/SaveBar';
import { EmptyState, ErrorState, LoadingState } from '../components/States';
import { useFbmToast } from '../components/Toast';
import { FbmApiError, fbmRequest, fbmRestUrl } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmFormat, fbmText } from '../lib/i18n';

interface EventOption {
	value: string;
	label: string;
}

interface Endpoint {
	id: string;
	url: string;
	active: boolean;
	events: string[];
}

interface Delivery {
	at: number;
	url: string;
	event: string;
	status: number;
	ok: boolean;
	error: string;
}

interface WebhookPayload {
	endpoints: Endpoint[];
	events: EventOption[];
	secret: string;
	deliveries: Delivery[];
}

interface Dataset {
	key: string;
	label: string;
	columns: string[];
	count: number;
}

interface ImportResult {
	dataset: string;
	rows: number;
	creating: number;
	updating: number;
	problems: string[];
	applied: boolean;
	written: number;
}

/**
 * Renders the integrations destination.
 */
export function WebhooksPanel(): JSX.Element {
	const toast = useFbmToast();
	const [ payload, setPayload ] = useState< WebhookPayload | null >( null );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	const load = useCallback( () => {
		let cancelled = false;

		fbmRequest< WebhookPayload >( 'webhooks' )
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

	useEffect( () => load(), [ load ] );

	const update = useCallback( ( id: string, changes: Partial< Endpoint > ) => {
		setPayload( ( state ) =>
			state
				? { ...state, endpoints: state.endpoints.map( ( e ) => ( e.id === id ? { ...e, ...changes } : e ) ) }
				: state
		);
		setDirty( true );
	}, [] );

	const add = useCallback( () => {
		setPayload( ( state ) =>
			state
				? {
						...state,
						endpoints: [
							...state.endpoints,
							{ id: `e${ Date.now().toString( 36 ) }`, url: '', active: false, events: [] },
						],
				  }
				: state
		);
		setDirty( true );
	}, [] );

	const remove = useCallback( ( id: string ) => {
		setPayload( ( state ) =>
			state ? { ...state, endpoints: state.endpoints.filter( ( e ) => e.id !== id ) } : state
		);
		setDirty( true );
	}, [] );

	const save = useCallback( async () => {
		if ( ! payload ) {
			return;
		}

		setSaving( true );

		try {
			const response = await fbmRequest< WebhookPayload >( 'webhooks', {
				method: 'PUT',
				body: { endpoints: payload.endpoints },
			} );
			setPayload( response.data );
			setDirty( false );
			toast.notify( fbmText( 'Webhooks saved.' ), 'success' );
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
		} finally {
			setSaving( false );
		}
	}, [ payload, toast ] );

	if ( error !== '' ) {
		return <ErrorState message={ error } onRetry={ load } />;
	}

	if ( ! payload ) {
		return <LoadingState rows={ 3 } />;
	}

	return (
		<>
			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Where events are sent' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Each event is posted as JSON shortly after it happens, never during the request that caused it — so a slow endpoint can never delay a customer.' ) }
						</p>
					</div>
					<button type="button" className="fbm-button fbm-button--primary" onClick={ add }>
						{ fbmText( 'Add an endpoint' ) }
					</button>
				</div>

				{ payload.endpoints.length === 0 ? (
					<EmptyState
						icon="settings"
						title={ fbmText( 'No endpoints yet' ) }
						description={ fbmText( 'Nothing is sent anywhere until you add one.' ) }
					/>
				) : (
					<ul className="fbm-rules">
						{ payload.endpoints.map( ( endpoint ) => (
							<li className={ `fbm-rules__item${ endpoint.active ? '' : ' is-off' }` } key={ endpoint.id }>
								<div className="fbm-rules__body">
									<div className="fbm-settings">
										<div className="fbm-settings__wide">
											<TextField
												label={ fbmText( 'Address' ) }
												name={ `hook_url_${ endpoint.id }` }
												type="url"
												value={ endpoint.url }
												placeholder="https://example.com/ferry-hook"
												hint={ fbmText( 'Must start with http:// or https://. Anything else is discarded when you save.' ) }
												onChange={ ( value ) => update( endpoint.id, { url: value } ) }
											/>
										</div>

										<SwitchField
											label={ fbmText( 'Sending' ) }
											checked={ endpoint.active }
											hint={ fbmText( 'Turn off to stop sending without losing the setup.' ) }
											onChange={ ( checked ) => update( endpoint.id, { active: checked } ) }
										/>

										<div className="fbm-settings__wide">
											<fieldset className="fbm-choices">
												<legend className="fbm-choices__legend">{ fbmText( 'Send these events' ) }</legend>
												<div className="fbm-choices__options">
													{ payload.events.map( ( event ) => {
														const on = endpoint.events.includes( event.value );

														return (
															<label className={ `fbm-choice${ on ? ' is-on' : '' }` } key={ event.value }>
																<input
																	type="checkbox"
																	checked={ on }
																	onChange={ () =>
																		update( endpoint.id, {
																			events: on
																				? endpoint.events.filter( ( e ) => e !== event.value )
																				: [ ...endpoint.events, event.value ],
																		} )
																	}
																/>
																<span>{ event.label }</span>
															</label>
														);
													} ) }
												</div>
											</fieldset>
										</div>
									</div>

									<div className="fbm-rules__actions">
										<button
											type="button"
											className="fbm-button fbm-button--danger"
											onClick={ () => remove( endpoint.id ) }
										>
											{ fbmText( 'Remove this endpoint' ) }
										</button>
									</div>
								</div>
							</li>
						) ) }
					</ul>
				)}
			</div>

			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Verifying a call came from here' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Every request carries an X-FBM-Signature header: an HMAC-SHA256 of the exact body, using this secret. Recompute it at your end and compare — if it matches, the call is genuine and nothing was altered on the way.' ) }
						</p>
					</div>
				</div>
				<div className="fbm-settings">
					<div className="fbm-settings__wide">
						<Secret value={ payload.secret } />
					</div>
				</div>
			</div>

			{ payload.deliveries.length > 0 ? (
				<div className="fbm-panel">
					<div className="fbm-panel__header">
						<h2 className="fbm-panel__title">{ fbmText( 'Recent deliveries' ) }</h2>
					</div>
					<ul className="fbm-timeline">
						{ payload.deliveries.slice( 0, 12 ).map( ( delivery, index ) => (
							<li
								className={ `fbm-timeline__item is-${ delivery.ok ? 'change' : 'refund' }` }
								key={ `${ delivery.at }-${ index }` }
							>
								<p className="fbm-timeline__what">
									{ delivery.ok
										? fbmFormat( '%1$s accepted (%2$s)', delivery.event, String( delivery.status ) )
										: fbmFormat( '%1$s was not accepted (%2$s)', delivery.event, String( delivery.status ) ) }
								</p>
								<p className="fbm-timeline__why">{ delivery.url }</p>
								{ delivery.error !== '' ? <p className="fbm-timeline__why">{ delivery.error }</p> : null }
							</li>
						) ) }
					</ul>
				</div>
			) : null }

			<SaveBar dirty={ dirty } saving={ saving } onSave={ save } />
		</>
	);
}

/**
 * Shows the signing secret, hidden until asked for.
 *
 * Hidden by default because this screen gets opened in front of other people,
 * and a credential on display is a credential in a screenshot.
 */
function Secret( { value }: { value: string } ): JSX.Element {
	const [ shown, setShown ] = useState( false );
	const field = useRef< HTMLInputElement | null >( null );

	return (
		<div className="fbm-secret">
			<input
				ref={ field }
				className="fbm-input fbm-secret__value"
				type={ shown ? 'text' : 'password' }
				value={ value }
				readOnly
				aria-label={ fbmText( 'Webhook signing secret' ) }
				onFocus={ () => field.current?.select() }
			/>
			<button type="button" className="fbm-button" onClick={ () => setShown( ! shown ) }>
				{ shown ? fbmText( 'Hide' ) : fbmText( 'Show' ) }
			</button>
		</div>
	);
}

/**
 * Moves the timetable in and out as CSV.
 */
export function TransferPanel(): JSX.Element {
	const config = fbmConfig();
	const toast = useFbmToast();

	const [ datasets, setDatasets ] = useState< Dataset[] | null >( null );
	const [ chosen, setChosen ] = useState( '' );
	const [ csv, setCsv ] = useState( '' );
	const [ result, setResult ] = useState< ImportResult | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		fbmRequest< { datasets: Dataset[] } >( 'transfer' )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setDatasets( response.data.datasets );
				setChosen( ( current ) => ( current !== '' ? current : response.data.datasets[ 0 ]?.key ?? '' ) );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setDatasets( [] );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	const run = useCallback(
		async ( apply: boolean ) => {
			setBusy( true );

			try {
				const response = await fbmRequest< ImportResult >( 'transfer/import', {
					method: 'POST',
					body: { dataset: chosen, csv, apply },
				} );
				setResult( response.data );

				if ( apply && response.data.applied ) {
					toast.notify(
						fbmFormat( '%s rows imported.', String( response.data.written ) ),
						'success'
					);
				}
			} catch ( caught: unknown ) {
				setResult( null );
				toast.notify( caught instanceof FbmApiError ? caught.message : fbmText( 'Something went wrong.' ), 'error' );
			} finally {
				setBusy( false );
			}
		},
		[ chosen, csv, toast ]
	);

	if ( ! datasets ) {
		return <LoadingState rows={ 3 } />;
	}

	const set = datasets.find( ( d ) => d.key === chosen );
	const exportUrl = fbmRestUrl( 'transfer/export', { dataset: chosen, _wpnonce: config.restNonce } );

	return (
		<>
			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Export' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Take a copy before you change anything, or edit a season of sailings in a spreadsheet and bring it back.' ) }
						</p>
					</div>
				</div>

				<div className="fbm-settings">
					<SelectField
						label={ fbmText( 'What to move' ) }
						name="transfer_dataset"
						value={ chosen }
						options={ datasets.map( ( d ) => ( {
							value: d.key,
							label: `${ d.label } (${ d.count })`,
						} ) ) }
						onChange={ ( value ) => {
							setChosen( value );
							setResult( null );
						} }
					/>

					<div className="fbm-settings__wide">
						<a className="fbm-button fbm-button--primary" href={ exportUrl }>
							{ fbmText( 'Download CSV' ) }
						</a>
					</div>

					{ set ? (
						<p className="fbm-field__hint fbm-settings__wide">
							{ fbmFormat( 'Columns: %s', set.columns.join( ', ' ) ) }
						</p>
					) : null }
				</div>
			</div>

			<div className="fbm-panel">
				<div className="fbm-panel__header">
					<div>
						<h2 className="fbm-panel__title">{ fbmText( 'Import' ) }</h2>
						<p className="fbm-panel__description">
							{ fbmText( 'Rows with an id update that record; rows without one create a new record. Check it first — nothing is written until you say so, and a single bad row stops the whole file rather than leaving half of it applied.' ) }
						</p>
					</div>
				</div>

				<div className="fbm-settings">
					<div className="fbm-settings__wide">
						<label className="fbm-field__label" htmlFor="fbm-transfer-file">
							{ fbmText( 'Choose a CSV file' ) }
						</label>
						<input
							id="fbm-transfer-file"
							className="fbm-input"
							type="file"
							accept=".csv,text/csv"
							onChange={ ( event ) => {
								const file = event.currentTarget.files?.[ 0 ];

								if ( ! file ) {
									return;
								}

								const reader = new FileReader();
								reader.onload = () => {
									setCsv( String( reader.result ?? '' ) );
									setResult( null );
								};
								reader.readAsText( file );
							} }
						/>
					</div>

					<div className="fbm-settings__wide">
						<button
							type="button"
							className="fbm-button"
							disabled={ busy || csv === '' }
							onClick={ () => void run( false ) }
						>
							{ fbmText( 'Check the file' ) }
						</button>
					</div>
				</div>

				{ result ? (
					<div className="fbm-import">
						<dl className="fbm-record__grid">
							<Row label={ fbmText( 'Rows in the file' ) } value={ String( result.rows ) } />
							<Row label={ fbmText( 'Would create' ) } value={ String( result.creating ) } />
							<Row label={ fbmText( 'Would update' ) } value={ String( result.updating ) } />
						</dl>

						{ result.problems.length > 0 ? (
							<div className="fbm-alert fbm-alert--error" role="alert">
								<p>{ fbmText( 'Nothing was imported. Fix these and try again:' ) }</p>
								<ul className="fbm-import__problems">
									{ result.problems.slice( 0, 12 ).map( ( problem ) => (
										<li key={ problem }>{ problem }</li>
									) ) }
								</ul>
							</div>
						) : (
							<>
								{ result.applied ? (
									<p className="fbm-import__done">
										{ fbmFormat( '%s rows imported.', String( result.written ) ) }
									</p>
								) : (
									<button
										type="button"
										className="fbm-button fbm-button--primary"
										disabled={ busy }
										onClick={ () => void run( true ) }
									>
										{ fbmFormat( 'Import %s rows', String( result.rows ) ) }
									</button>
								) }
							</>
						) }
					</div>
				) : null }
			</div>
		</>
	);
}

/**
 * Renders one label and value.
 */
function Row( { label, value }: { label: string; value: string } ): JSX.Element {
	return (
		<div className="fbm-record__row">
			<dt className="fbm-record__label">{ label }</dt>
			<dd className="fbm-record__value">{ value }</dd>
		</div>
	);
}
