/**
 * MagePeople Ferry Booking System - admin runtime bootstrap.
 *
 * Loaded before the exported dashboard chunks. It guarantees the configuration
 * object exists, records boot failures where a human can see them, and exposes
 * the small surface the dashboard reads at startup.
 *
 * @package FerryBookingManager
 */
( function ( window, document ) {
	'use strict';

	var config = window.fbmAdmin || {};

	config.version = config.version || '0.0.0';
	config.capabilities = config.capabilities || {};
	config.i18n = config.i18n || {};
	config.bootedAt = Date.now();

	window.fbmAdmin = config;

	/*
	 * Tell the bundler runtime where the dashboard chunks actually live.
	 *
	 * The exported bundle is built without knowing the site's directory layout,
	 * so its runtime falls back to resolving sibling chunks against "/_next/" —
	 * the WordPress root. Pointing it at the plugin's export directory is what
	 * lets the pre-registered chunks be recognised and the application entry run.
	 * The empty suffix stops it deriving a cache-busting query from its own tag.
	 */
	if ( config.chunkBase ) {
		window.TURBOPACK_CHUNK_BASE_PATH = config.chunkBase;
	}

	window.TURBOPACK_ASSET_SUFFIX = '';

	/**
	 * Renders a last-resort failure message inside the dashboard root.
	 *
	 * @param {string} message Human readable message.
	 * @return {void}
	 */
	function fbmRenderBootFailure( message ) {
		var root = document.getElementById( '__next' );

		if ( ! root || root.getAttribute( 'data-fbm-failed' ) === '1' ) {
			return;
		}

		root.setAttribute( 'data-fbm-failed', '1' );
		root.innerHTML =
			'<div class="fbm-boot-error" role="alert">' +
			'<h2 class="fbm-boot-error__title"></h2>' +
			'<p class="fbm-boot-error__text"></p>' +
			'</div>';

		root.querySelector( '.fbm-boot-error__title' ).textContent =
			config.i18n[ 'Something went wrong.' ] || 'Something went wrong.';
		root.querySelector( '.fbm-boot-error__text' ).textContent = message;
	}

	window.fbmAdminBootFailure = fbmRenderBootFailure;

	window.addEventListener( 'error', function ( event ) {
		if ( ! event || ! event.filename || event.filename.indexOf( '/ferry-booking-manager/' ) === -1 ) {
			return;
		}

		fbmRenderBootFailure( event.message || 'Script error.' );
	} );
}( window, document ) );
