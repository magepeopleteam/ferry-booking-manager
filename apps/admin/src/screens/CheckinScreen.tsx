/**
 * Check-in.
 *
 * The screen a crew member holds at the ramp, so it is built for one hand, poor
 * light and a queue: one very large verdict, one very large button, and nothing
 * else competing for attention. The result colour is the message — green means
 * board them, red means stop — and the text underneath is for the cases where
 * the crew member has to explain something to a passenger.
 *
 * Camera scanning uses the browser's own barcode detector where there is one.
 * Where there is not, the reference can be typed: a scanner that refuses to work
 * on the duty officer's phone is worse than one that asks for six characters.
 */

import { useCallback, useEffect, useRef, useState, type JSX } from 'react';

import { PageHeader } from '../components/PageHeader';
import { SelectField, TextField } from '../components/Fields';
import { EmptyState } from '../components/States';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmConfig } from '../lib/config';
import { fbmText } from '../lib/i18n';

interface Ticket {
	token_kind: string;
	index: number;
	state: string;
	reference: string;
	name: string;
	passengers: number;
	vehicles: number;
	route: string;
	origin: string;
	destination: string;
	vessel: string;
	departure: string;
	checked_at: number;
	checked_by: string;
}

interface SailingOption {
	id: number;
	label: string;
}

type Verdict =
	| { tone: 'valid'; ticket: Ticket }
	| { tone: 'done'; ticket: Ticket; message: string }
	| { tone: 'refused'; message: string }
	| null;

/** A browser that can read a QR code from a video frame. */
interface DetectedBarcode {
	rawValue: string;
}

interface BarcodeDetectorLike {
	detect: ( source: CanvasImageSource ) => Promise< DetectedBarcode[] >;
}

/**
 * Returns a barcode detector when the browser has one.
 */
function detector(): BarcodeDetectorLike | null {
	const global = window as unknown as {
		BarcodeDetector?: new ( options: { formats: string[] } ) => BarcodeDetectorLike;
	};

	if ( ! global.BarcodeDetector ) {
		return null;
	}

	try {
		return new global.BarcodeDetector( { formats: [ 'qr_code' ] } );
	} catch {
		return null;
	}
}

/**
 * Renders the check-in screen.
 */
export function CheckinScreen(): JSX.Element {
	const config = fbmConfig();

	const [ sailings, setSailings ] = useState< SailingOption[] >( [] );
	const [ sailingId, setSailingId ] = useState( 0 );
	const [ manual, setManual ] = useState( '' );
	const [ verdict, setVerdict ] = useState< Verdict >( null );
	const [ busy, setBusy ] = useState( false );
	const [ scanning, setScanning ] = useState( false );
	const [ cameraError, setCameraError ] = useState( '' );

	const videoRef = useRef< HTMLVideoElement | null >( null );
	const streamRef = useRef< MediaStream | null >( null );
	const lastScan = useRef( { token: '', at: 0 } );

	const canScan = detector() !== null;

	// ---- today's sailings -------------------------------------------------

	useEffect( () => {
		if ( ! config.proActive ) {
			return undefined;
		}

		let cancelled = false;

		fbmRequest< { departures?: Array< { id: number; time: string; route: string; vessel: string } > } >(
			'dashboard'
		)
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				const rows = response.data.departures ?? [];
				setSailings(
					rows.map( ( row ) => ( {
						id: row.id,
						label: `${ row.time } · ${ row.route }${ row.vessel ? ` · ${ row.vessel }` : '' }`,
					} ) )
				);
			} )
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [ config.proActive ] );

	// ---- scanning ---------------------------------------------------------

	const submit = useCallback(
		async ( token: string, action: '' | 'check_in' | 'board' ) => {
			if ( token === '' ) {
				return;
			}

			setBusy( true );

			try {
				const response = await fbmRequest< Ticket >(
					action === '' ? 'checkin/inspect' : 'checkin/accept',
					{
						method: 'POST',
						body: action === '' ? { token, sailing_id: sailingId } : { token, action, sailing_id: sailingId },
					}
				);

				setVerdict(
					action === 'board' || action === 'check_in'
						? {
								tone: 'done',
								ticket: response.data,
								message:
									action === 'board'
										? fbmText( 'Boarded' )
										: fbmText( 'Checked in' ),
						  }
						: { tone: 'valid', ticket: response.data }
				);
			} catch ( caught: unknown ) {
				if ( caught instanceof FbmApiError ) {
					const already = caught.details.ticket as Ticket | undefined;

					setVerdict(
						already
							? { tone: 'done', ticket: already, message: caught.message }
							: { tone: 'refused', message: caught.message }
					);
				} else {
					setVerdict( { tone: 'refused', message: fbmText( 'Something went wrong.' ) } );
				}
			} finally {
				setBusy( false );
			}
		},
		[ sailingId ]
	);

	const stopCamera = useCallback( () => {
		streamRef.current?.getTracks().forEach( ( track ) => track.stop() );
		streamRef.current = null;
		setScanning( false );
	}, [] );

	useEffect( () => {
		if ( ! scanning ) {
			return undefined;
		}

		let cancelled = false;
		let frame = 0;
		const reader = detector();

		const tick = async () => {
			const video = videoRef.current;

			if ( cancelled || ! reader || ! video || video.readyState < 2 ) {
				frame = window.requestAnimationFrame( () => void tick() );
				return;
			}

			try {
				const codes = await reader.detect( video );
				const found = codes[ 0 ]?.rawValue ?? '';

				// The same code stays in front of the lens for several seconds
				// after it is read. Without this the crew would check a
				// passenger in and immediately be told they are already in.
				const fresh = found !== '' && ( found !== lastScan.current.token || Date.now() - lastScan.current.at > 4000 );

				if ( fresh ) {
					lastScan.current = { token: found, at: Date.now() };
					await submit( found, '' );
				}
			} catch {
				// A frame that could not be read is not an error worth showing;
				// the next one is a few milliseconds away.
			}

			if ( ! cancelled ) {
				frame = window.requestAnimationFrame( () => void tick() );
			}
		};

		navigator.mediaDevices
			?.getUserMedia( { video: { facingMode: 'environment' } } )
			.then( ( stream ) => {
				if ( cancelled ) {
					stream.getTracks().forEach( ( track ) => track.stop() );
					return;
				}

				streamRef.current = stream;

				if ( videoRef.current ) {
					videoRef.current.srcObject = stream;
					void videoRef.current.play();
				}

				void tick();
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setCameraError( fbmText( 'The camera could not be opened. Type the reference instead.' ) );
					setScanning( false );
				}
			} );

		return () => {
			cancelled = true;
			window.cancelAnimationFrame( frame );
			streamRef.current?.getTracks().forEach( ( track ) => track.stop() );
			streamRef.current = null;
		};
	}, [ scanning, submit ] );

	useEffect( () => stopCamera, [ stopCamera ] );

	if ( ! config.proActive ) {
		return (
			<>
				<PageHeader
					title={ fbmText( 'Check-In' ) }
					badge={ <span className="fbm-badge fbm-badge--pro">PRO</span> }
				/>
				<EmptyState
					icon="scan"
					title={ fbmText( 'Available in Ferry Booking Manager Pro.' ) }
					description={ fbmText( 'Scan tickets from a phone camera, check passengers in and board them, with duplicate-scan protection and a history you can audit.' ) }
				/>
			</>
		);
	}

	const ticket = verdict && 'ticket' in verdict ? verdict.ticket : null;

	return (
		<>
			<PageHeader
				title={ fbmText( 'Check-In' ) }
				description={ fbmText( 'Scan a ticket, or type its reference.' ) }
			/>

			<div className="fbm-checkin">
				<div className="fbm-panel fbm-checkin__controls">
					<div className="fbm-settings">
						<SelectField
							label={ fbmText( 'Sailing' ) }
							name="checkin_sailing"
							value={ String( sailingId ) }
							options={ [
								{ value: '0', label: fbmText( 'Any sailing today' ) },
								...sailings.map( ( row ) => ( { value: String( row.id ), label: row.label } ) ),
							] }
							hint={ fbmText( 'Choosing one stops a ticket for a later crossing being boarded onto this vessel.' ) }
							onChange={ ( value ) => setSailingId( Number( value ) ) }
						/>

						<div className="fbm-settings__wide">
							{ canScan ? (
								<button
									type="button"
									className={ `fbm-button ${ scanning ? '' : 'fbm-button--primary' }` }
									onClick={ () => ( scanning ? stopCamera() : setScanning( true ) ) }
								>
									{ scanning ? fbmText( 'Stop the camera' ) : fbmText( 'Scan with the camera' ) }
								</button>
							) : (
								<p className="fbm-field__hint">
									{ fbmText( 'This browser cannot use the camera for scanning. Type the reference below.' ) }
								</p>
							) }
							{ cameraError !== '' ? <p className="fbm-field__error">{ cameraError }</p> : null }
						</div>

						<TextField
							label={ fbmText( 'Ticket reference' ) }
							name="checkin_manual"
							value={ manual }
							placeholder={ fbmText( 'Paste or type a ticket code' ) }
							onChange={ setManual }
						/>
						<div className="fbm-settings__wide">
							<button
								type="button"
								className="fbm-button"
								disabled={ busy || manual.trim() === '' }
								onClick={ () => void submit( manual.trim(), '' ) }
							>
								{ fbmText( 'Look it up' ) }
							</button>
						</div>
					</div>

					{ scanning ? (
						<div className="fbm-checkin__camera">
							{ /* eslint-disable-next-line jsx-a11y/media-has-caption -- A live camera preview carries no audio to caption. */ }
							<video ref={ videoRef } playsInline muted className="fbm-checkin__video" />
							<p className="fbm-checkin__hint">{ fbmText( 'Hold the code steady in the frame.' ) }</p>
						</div>
					) : null }
				</div>

				<div className="fbm-panel fbm-checkin__result">
					{ verdict === null ? (
						<p className="fbm-checkin__idle">{ fbmText( 'Waiting for a ticket.' ) }</p>
					) : (
						<div
							className={ `fbm-verdict fbm-verdict--${ verdict.tone }` }
							role="status"
							aria-live="assertive"
						>
							<p className="fbm-verdict__headline">
								{ verdict.tone === 'valid'
									? fbmText( 'Valid' )
									: verdict.tone === 'done'
										? verdict.message
										: fbmText( 'Do not board' ) }
							</p>

							{ verdict.tone === 'refused' ? (
								<p className="fbm-verdict__reason">{ verdict.message }</p>
							) : null }

							{ ticket ? (
								<dl className="fbm-verdict__facts">
									<div>
										<dt>{ fbmText( 'Passenger' ) }</dt>
										<dd>{ ticket.name }</dd>
									</div>
									<div>
										<dt>{ fbmText( 'Booking' ) }</dt>
										<dd>{ ticket.reference }</dd>
									</div>
									<div>
										<dt>{ fbmText( 'Crossing' ) }</dt>
										<dd>
											{ ticket.origin !== '' && ticket.destination !== ''
												? `${ ticket.origin } → ${ ticket.destination }`
												: ticket.route }
										</dd>
									</div>
									<div>
										<dt>{ fbmText( 'Departure' ) }</dt>
										<dd>{ ticket.departure }</dd>
									</div>
									{ ticket.vessel !== '' ? (
										<div>
											<dt>{ fbmText( 'Vessel' ) }</dt>
											<dd>{ ticket.vessel }</dd>
										</div>
									) : null }
									{ ticket.checked_by !== '' ? (
										<div>
											<dt>{ fbmText( 'Scanned by' ) }</dt>
											<dd>{ ticket.checked_by }</dd>
										</div>
									) : null }
								</dl>
							) : null }

							{ ticket ? (
								<div className="fbm-verdict__actions">
									{ ticket.state === 'valid' ? (
										<button
											type="button"
											className="fbm-button fbm-button--primary fbm-button--large"
											disabled={ busy }
											onClick={ () => void submit( lastToken( ticket, manual ), 'check_in' ) }
										>
											{ fbmText( 'Check in' ) }
										</button>
									) : null }

									{ ticket.state === 'checked_in' ? (
										<button
											type="button"
											className="fbm-button fbm-button--primary fbm-button--large"
											disabled={ busy }
											onClick={ () => void submit( lastToken( ticket, manual ), 'board' ) }
										>
											{ fbmText( 'Mark boarded' ) }
										</button>
									) : null }

									<button
										type="button"
										className="fbm-button"
										onClick={ () => {
											setVerdict( null );
											setManual( '' );
											lastScan.current = { token: '', at: 0 };
										} }
									>
										{ fbmText( 'Next passenger' ) }
									</button>
								</div>
							) : null }
						</div>
					) }
				</div>
			</div>
		</>
	);

	/**
	 * Returns the token the verdict on screen came from.
	 *
	 * The API answers with the ticket rather than echoing the token, so the one
	 * that produced this verdict is either the last thing scanned or whatever
	 * was typed in.
	 */
	function lastToken( _ticket: Ticket, typed: string ): string {
		return lastScan.current.token !== '' ? lastScan.current.token : typed.trim();
	}
}
