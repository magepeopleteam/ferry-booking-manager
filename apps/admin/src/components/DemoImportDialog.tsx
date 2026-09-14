/**
 * First-run offer to install a working ferry operation.
 *
 * An empty install is a bad first impression and a bad test: nothing to search,
 * no price to quote, no departure to book. This offers a real operation to click
 * around in, and only on an install that has nothing of its own — once there is
 * a single port or route the operator has started work, and an offer to add ten
 * more is an interruption.
 *
 * The import is driven one step at a time. Ten days of schedule is several
 * hundred records, and a host that stops the request at thirty seconds would
 * leave a half-built catalogue behind with no way to tell what had been done.
 */

import { useCallback, useEffect, useRef, useState, type JSX } from 'react';

import { Icon } from './Icon';
import { fbmRequest, FbmApiError } from '../lib/api';
import { fbmCan } from '../lib/config';
import { fbmText } from '../lib/i18n';

interface DemoStatus {
	title: string;
	description: string;
	includes: string[];
	installed: boolean;
	counts: Record< string, number >;
	steps: number;
	empty: boolean;
	dismissed: boolean;
}

interface StepResult {
	step: number;
	steps: number;
	label: string;
	done: boolean;
}

/**
 * Renders the first-run demo offer.
 */
export function DemoImportDialog(): JSX.Element | null {
	const [ status, setStatus ] = useState< DemoStatus | null >( null );
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ label, setLabel ] = useState( '' );
	const [ done, setDone ] = useState( 0 );
	const [ failed, setFailed ] = useState( '' );
	const dialogRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		if ( ! fbmCan( 'fbm_manage_settings' ) ) {
			return;
		}

		let cancelled = false;

		fbmRequest< DemoStatus >( 'demo' )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setStatus( response.data );
				setOpen( response.data.empty && ! response.data.dismissed );
			} )
			.catch( () => {
				/* The offer is a courtesy. If it cannot be described, say nothing. */
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	// Focus moves into the dialog so a keyboard user is not left behind it.
	useEffect( () => {
		if ( open ) {
			dialogRef.current?.focus();
		}
	}, [ open ] );

	const close = useCallback( async () => {
		setOpen( false );

		try {
			await fbmRequest( 'demo/dismiss', { method: 'POST' } );
		} catch {
			/* Dismissal is a preference, not a transaction. */
		}
	}, [] );

	const install = useCallback( async () => {
		if ( ! status ) {
			return;
		}

		setBusy( true );
		setFailed( '' );

		try {
			for ( let step = 0; step < status.steps; step += 1 ) {
				// Sequential on purpose: each step depends on what the last one
				// created, and a parallel burst would race to build the same
				// routes twice.
				// eslint-disable-next-line no-await-in-loop
				const response = await fbmRequest< StepResult >( 'demo', {
					method: 'POST',
					body: { step },
				} );

				setLabel( response.data.label );
				setDone( step + 1 );
			}

			const refreshed = await fbmRequest< DemoStatus >( 'demo' );
			setStatus( refreshed.data );
			setOpen( false );

			// The screens behind the dialog were rendered against an empty
			// catalogue, so they are showing "nothing yet" for data that now
			// exists. Reloading is the honest way to show what was installed.
			window.location.reload();
		} catch ( caught: unknown ) {
			setFailed(
				caught instanceof FbmApiError ? caught.message : fbmText( 'The demo could not be installed.' )
			);
			setBusy( false );
		}
	}, [ status ] );

	if ( ! open || ! status ) {
		return null;
	}

	const percent = status.steps > 0 ? Math.round( ( done / status.steps ) * 100 ) : 0;

	return (
		<div className="fbm-demo__backdrop" role="presentation">
			<div
				className="fbm-demo"
				role="dialog"
				aria-modal="true"
				aria-labelledby="fbm-demo-title"
				aria-describedby="fbm-demo-description"
				tabIndex={ -1 }
				ref={ dialogRef }
			>
				<div className="fbm-demo__head">
					<span className="fbm-demo__mark" aria-hidden="true">
						<Icon name="ship" size={ 22 } />
					</span>
					<div>
						<h2 className="fbm-demo__title" id="fbm-demo-title">
							{ fbmText( 'Start with a working ferry operation' ) }
						</h2>
						<p className="fbm-demo__lede" id="fbm-demo-description">
							{ status.description }
						</p>
					</div>
				</div>

				<p className="fbm-demo__name">{ status.title }</p>

				<ul className="fbm-demo__list">
					{ status.includes.map( ( item ) => (
						<li key={ item }>
							<Icon name="check" size={ 15 } />
							<span>{ item }</span>
						</li>
					) ) }
				</ul>

				{ busy ? (
					<div className="fbm-demo__progress" role="status" aria-live="polite">
						<div className="fbm-demo__bar">
							<span className="fbm-demo__fill" style={ { inlineSize: `${ percent }%` } } />
						</div>
						<p className="fbm-demo__step">
							<span>{ label !== '' ? label : fbmText( 'Starting…' ) }</span>
							{ /* Numbers need no translation, and a two-placeholder
							     sentence would only give translators a harder
							     string to get right. */ }
							<span className="fbm-demo__count">{ `${ done } / ${ status.steps }` }</span>
						</p>
					</div>
				) : null }

				{ failed !== '' ? <p className="fbm-demo__error">{ failed }</p> : null }

				<p className="fbm-demo__note">
					{ fbmText(
						'Everything it adds is marked as demo content and can be removed again from Settings → Advanced, without touching anything you have created yourself.'
					) }
				</p>

				<div className="fbm-demo__actions">
					<button type="button" className="fbm-button" onClick={ close } disabled={ busy }>
						{ fbmText( 'Start from scratch' ) }
					</button>
					<button
						type="button"
						className="fbm-button fbm-button--primary"
						onClick={ install }
						disabled={ busy }
					>
						{ busy ? fbmText( 'Installing…' ) : fbmText( 'Install the demo' ) }
					</button>
				</div>
			</div>
		</div>
	);
}
