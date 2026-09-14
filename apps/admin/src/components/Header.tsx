/**
 * Dashboard header.
 */

import type { JSX } from 'react';

import { Icon } from './Icon';
import { fbmConfig } from '../lib/config';
import { fbmText } from '../lib/i18n';

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
	const config = fbmConfig();

	return (
		<header className="fbm-header">
			<button
				type="button"
				className="fbm-header__toggle"
				onClick={ onToggleSidebar }
				aria-expanded={ sidebarOpen }
				aria-controls="fbm-sidebar"
				aria-label={ fbmText( 'Toggle navigation' ) }
			>
				<Icon name="menu" size={ 20 } />
			</button>

			<button type="button" className="fbm-header__search" onClick={ onOpenSearch }>
				<Icon name="search" size={ 16 } />
				<span>{ fbmText( 'Search' ) }</span>
				<kbd className="fbm-header__kbd">Ctrl K</kbd>
			</button>

			<div className="fbm-header__meta">
				<span
					className={ `fbm-status fbm-status--${ connected === null ? 'pending' : connected ? 'ok' : 'down' }` }
				>
					<span className="fbm-status__dot" aria-hidden="true" />
					{ connected === null
						? fbmText( 'Loading' )
						: connected
							? fbmText( 'Connected' )
							: fbmText( 'Disconnected' ) }
				</span>

				<span className="fbm-header__user">
					{ config.user.avatar ? (
						// eslint-disable-next-line @next/next/no-img-element
						<img className="fbm-header__avatar" src={ config.user.avatar } alt="" width={ 28 } height={ 28 } />
					) : null }
					<span className="fbm-header__username">{ config.user.name }</span>
				</span>

				<a className="fbm-header__exit" href={ config.adminUrl } title={ fbmText( 'Back to WordPress' ) }>
					<Icon name="back" size={ 16 } />
					<span className="fbm-header__exitlabel">{ fbmText( 'Back to WordPress' ) }</span>
				</a>
			</div>
		</header>
	);
}
