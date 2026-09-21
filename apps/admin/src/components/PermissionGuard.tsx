/**
 * Capability gate.
 *
 * The server is always the authority: this only stops the dashboard from
 * offering an action the signed-in user could not complete anyway.
 */

import type { JSX, ReactNode } from 'react';

import { EmptyState } from './States';
import { mpfbsCan } from '../lib/config';
import { mpfbsText } from '../lib/i18n';

export interface PermissionGuardProps {
	capability: string;
	children: ReactNode;
	fallback?: ReactNode;
}

/**
 * Renders children only when the capability is held.
 */
export function PermissionGuard( { capability, children, fallback }: PermissionGuardProps ): JSX.Element {
	if ( mpfbsCan( capability ) ) {
		return <>{ children }</>;
	}

	if ( fallback !== undefined ) {
		return <>{ fallback }</>;
	}

	return (
		<EmptyState
			icon="lock"
			title={ mpfbsText( 'You do not have permission to view this section.' ) }
		/>
	);
}
