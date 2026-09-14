/**
 * Next.js application root.
 */

import type { AppProps } from 'next/app';
import type { JSX } from 'react';

import '../styles/fbm-admin.css';

/**
 * Renders the active page.
 */
export default function FbmAdminApp( { Component, pageProps }: AppProps ): JSX.Element {
	return <Component { ...pageProps } />;
}
