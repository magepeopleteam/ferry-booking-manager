/**
 * Dashboard header.
 */

import type { JSX } from 'react';

import { Icon } from './Icon';
import { mpfbsConfig } from '../lib/config';
import { mpfbsText } from '../lib/i18n';

export interface HeaderProps {
	sidebarOpen: boolean;
	onToggleSidebar: () => void;
	onOpenSearch: () => void;
	connected: boolean | null;
}

/**
 * Renders the top bar: navigation toggle, search entry point, account and the
 * way out.
 *
 * The dashboard hides the WordPress admin bar and admin menu to take the whole
 * screen, so this header carries the only route back to wp-admin. It is a plain
 * link to a real address, which means it works with a middle click and is
 * reachable by keyboard like any other.
 */
export function Header( { sidebarOpen, onToggleSidebar, onOpenSearch, connected }: HeaderProps ): JSX.Element {
	const config = mpfbsConfig();

	return (
		<header className="mpfbs-header">
			<button
				type="button"
				className="mpfbs-header__toggle"
				onClick={ onToggleSidebar }
				aria-expanded={ sidebarOpen }
				aria-controls="mpfbs-sidebar"
				aria-label={ mpfbsText( 'Toggle navigation' ) }
			>
				<Icon name="menu" size={ 20 } />
			</button>

			<button type="button" className="mpfbs-header__search" onClick={ onOpenSearch }>
				<Icon name="search" size={ 16 } />
				<span>{ mpfbsText( 'Search' ) }</span>
				<kbd className="mpfbs-header__kbd">Ctrl K</kbd>
			</button>

			<div className="mpfbs-header__meta">
				<span
					className={ `mpfbs-status mpfbs-status--${ connected === null ? 'pending' : connected ? 'ok' : 'down' }` }
				>
					<span className="mpfbs-status__dot" aria-hidden="true" />
					{ connected === null
						? mpfbsText( 'Loading' )
						: connected
							? mpfbsText( 'Connected' )
							: mpfbsText( 'Disconnected' ) }
				</span>

				<span className="mpfbs-header__user">
					{ config.user.avatar ? (
						// eslint-disable-next-line @next/next/no-img-element
						<img className="mpfbs-header__avatar" src={ config.user.avatar } alt="" width={ 28 } height={ 28 } />
					) : null }
					<span className="mpfbs-header__username">{ config.user.name }</span>
				</span>

				<a className="mpfbs-header__exit" href={ config.adminUrl } title={ mpfbsText( 'Back to WordPress' ) }>
					<Icon name="back" size={ 16 } />
					<span className="mpfbs-header__exitlabel">{ mpfbsText( 'Back to WordPress' ) }</span>
				</a>
			</div>
		</header>
	);
}
