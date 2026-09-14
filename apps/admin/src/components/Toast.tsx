/**
 * Toast notifications.
 *
 * Announcements are polite by default and assertive for failures, so screen
 * reader users hear the same feedback sighted users see.
 */

import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
	type JSX,
	type ReactNode,
} from 'react';

import { Icon } from './Icon';
import { fbmText } from '../lib/i18n';

export type ToastTone = 'success' | 'error' | 'info';

export interface Toast {
	id: number;
	tone: ToastTone;
	message: string;
}

export interface ToastApi {
	notify: ( message: string, tone?: ToastTone ) => void;
	dismiss: ( id: number ) => void;
}

const ToastContext = createContext< ToastApi | null >( null );

const DISPLAY_MS = 6000;

export interface ToastProviderProps {
	children: ReactNode;
}

/**
 * Provides the toast API to the dashboard.
 */
export function ToastProvider( { children }: ToastProviderProps ): JSX.Element {
	const [ toasts, setToasts ] = useState< Toast[] >( [] );
	const nextId = useRef( 1 );
	const timers = useRef< Map< number, ReturnType< typeof setTimeout > > >( new Map() );

	const dismiss = useCallback( ( id: number ) => {
		setToasts( ( current ) => current.filter( ( toast ) => toast.id !== id ) );

		const timer = timers.current.get( id );

		if ( timer ) {
			clearTimeout( timer );
			timers.current.delete( id );
		}
	}, [] );

	const notify = useCallback(
		( message: string, tone: ToastTone = 'info' ) => {
			const id = nextId.current;
			nextId.current += 1;

			setToasts( ( current ) => [ ...current, { id, tone, message } ] );

			timers.current.set(
				id,
				setTimeout( () => {
					dismiss( id );
				}, DISPLAY_MS )
			);
		},
		[ dismiss ]
	);

	useEffect( () => {
		const pending = timers.current;

		return () => {
			pending.forEach( ( timer ) => clearTimeout( timer ) );
			pending.clear();
		};
	}, [] );

	const api = useMemo< ToastApi >( () => ( { notify, dismiss } ), [ notify, dismiss ] );

	return (
		<ToastContext.Provider value={ api }>
			{ children }
			<div className="fbm-toasts">
				{ toasts.map( ( toast ) => (
					<div
						key={ toast.id }
						className={ `fbm-toast fbm-toast--${ toast.tone }` }
						role={ toast.tone === 'error' ? 'alert' : 'status' }
						aria-live={ toast.tone === 'error' ? 'assertive' : 'polite' }
					>
						<Icon name={ toast.tone === 'error' ? 'alert' : 'check' } size={ 16 } />
						<span className="fbm-toast__message">{ toast.message }</span>
						<button
							type="button"
							className="fbm-toast__close"
							onClick={ () => dismiss( toast.id ) }
							aria-label={ fbmText( 'Close' ) }
						>
							×
						</button>
					</div>
				) ) }
			</div>
		</ToastContext.Provider>
	);
}

/**
 * Returns the toast API.
 */
export function useFbmToast(): ToastApi {
	const context = useContext( ToastContext );

	if ( ! context ) {
		throw new Error( 'useFbmToast must be used inside <ToastProvider>.' );
	}

	return context;
}
