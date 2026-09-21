/**
 * Screen header.
 */

import type { JSX, ReactNode } from 'react';

export interface PageHeaderProps {
	title: string;
	description?: string;
	badge?: ReactNode;
	actions?: ReactNode;
}

/**
 * Renders the title block at the top of a screen.
 */
export function PageHeader( { title, description, badge, actions }: PageHeaderProps ): JSX.Element {
	return (
		<header className="mpfbs-page-header">
			<div className="mpfbs-page-header__text">
				<h1 className="mpfbs-page-header__title">
					{ title }
					{ badge ? <span className="mpfbs-page-header__badge">{ badge }</span> : null }
				</h1>
				{ description ? <p className="mpfbs-page-header__description">{ description }</p> : null }
			</div>
			{ actions ? <div className="mpfbs-page-header__actions">{ actions }</div> : null }
		</header>
	);
}
