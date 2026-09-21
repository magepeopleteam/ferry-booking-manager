/**
 * Shared loading, empty and error presentations.
 *
 * Every screen uses these three so the dashboard behaves consistently and so
 * failures are always explained rather than silently swallowed.
 */

import type { JSX, ReactNode } from 'react';

import { Icon, type IconName } from './Icon';
import { mpfbsText } from '../lib/i18n';

export interface SkeletonProps {
	width?: string;
	height?: string;
	radius?: string;
	className?: string;
}

/**
 * Renders a shimmering placeholder block.
 */
export function Skeleton( { width = '100%', height = '14px', radius = '6px', className }: SkeletonProps ): JSX.Element {
	return (
		<span
			className={ className ? `mpfbs-skeleton ${ className }` : 'mpfbs-skeleton' }
			style={ { width, height, borderRadius: radius } }
			aria-hidden="true"
		/>
	);
}

export interface LoadingStateProps {
	label?: string;
	rows?: number;
}

/**
 * Renders the standard loading placeholder for a content region.
 */
export function LoadingState( { label, rows = 3 }: LoadingStateProps ): JSX.Element {
	return (
		<div className="mpfbs-state mpfbs-state--loading" role="status" aria-live="polite" aria-busy="true">
			<span className="mpfbs-screen-reader-text">{ label ?? mpfbsText( 'Loading' ) }</span>
			{ Array.from( { length: rows } ).map( ( _, index ) => (
				<div className="mpfbs-skeleton-row" key={ index }>
					<Skeleton height="18px" width={ index === 0 ? '38%' : '100%' } />
					<Skeleton height="12px" width={ index === 0 ? '62%' : '78%' } />
				</div>
			) ) }
		</div>
	);
}

export interface EmptyStateProps {
	icon?: IconName;
	title: string;
	description?: string;
	action?: ReactNode;
}

/**
 * Renders an explanatory empty state.
 */
export function EmptyState( { icon = 'ship', title, description, action }: EmptyStateProps ): JSX.Element {
	return (
		<div className="mpfbs-state mpfbs-state--empty">
			<span className="mpfbs-state__icon">
				<Icon name={ icon } size={ 28 } />
			</span>
			<h2 className="mpfbs-state__title">{ title }</h2>
			{ description ? <p className="mpfbs-state__text">{ description }</p> : null }
			{ action ? <div className="mpfbs-state__action">{ action }</div> : null }
		</div>
	);
}

export interface ErrorStateProps {
	title?: string;
	message: string;
	code?: string;
	onRetry?: () => void;
}

/**
 * Renders a recoverable error with an explicit retry affordance.
 */
export function ErrorState( { title, message, code, onRetry }: ErrorStateProps ): JSX.Element {
	return (
		<div className="mpfbs-state mpfbs-state--error" role="alert">
			<span className="mpfbs-state__icon mpfbs-state__icon--danger">
				<Icon name="alert" size={ 28 } />
			</span>
			<h2 className="mpfbs-state__title">{ title ?? mpfbsText( 'Something went wrong.' ) }</h2>
			<p className="mpfbs-state__text">{ message }</p>
			{ code ? <p className="mpfbs-state__code">{ code }</p> : null }
			{ onRetry ? (
				<div className="mpfbs-state__action">
					<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ onRetry }>
						{ mpfbsText( 'Retry' ) }
					</button>
				</div>
			) : null }
		</div>
	);
}
