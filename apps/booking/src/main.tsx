/**
 * Booking application entry point.
 *
 * Mounts one instance per component on the page, so a homepage can carry a
 * search form and a booking page the full flow without either knowing about the
 * other. Nothing is mounted if the page contains no ferry component, and the
 * bundle is not even enqueued in that case.
 */

import { render } from 'preact';

import { App } from './components/App';
import './styles.css';

interface MountConfig {
	component: string;
	attributes: Record< string, unknown >;
}

/**
 * Reads a mount point's configuration.
 */
function readConfig( element: HTMLElement ): MountConfig | null {
	const raw = element.getAttribute( 'data-mpfbs-config' );

	if ( ! raw ) {
		return null;
	}

	try {
		const parsed = JSON.parse( raw ) as Partial< MountConfig >;

		if ( ! parsed.component ) {
			return null;
		}

		return {
			component: String( parsed.component ),
			attributes: ( parsed.attributes ?? {} ) as Record< string, unknown >,
		};
	} catch {
		return null;
	}
}

/**
 * Mounts every component on the page.
 */
function boot(): void {
	const mounts = document.querySelectorAll< HTMLElement >( '[data-mpfbs-component]' );

	mounts.forEach( ( element ) => {
		if ( element.dataset.mpfbsMounted === '1' ) {
			return;
		}

		const config = readConfig( element );

		if ( ! config ) {
			return;
		}

		element.dataset.mpfbsMounted = '1';
		element.innerHTML = '';

		render( <App component={ config.component } attributes={ config.attributes } />, element );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot, { once: true } );
} else {
	boot();
}

/*
 * Block editor previews and themes that load content over AJAX both insert
 * mount points after the initial boot, so late arrivals are picked up too.
 */
if ( typeof MutationObserver !== 'undefined' ) {
	new MutationObserver( () => boot() ).observe( document.body, { childList: true, subtree: true } );
}
