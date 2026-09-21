/**
 * Next.js application root.
 */

import type { AppProps } from 'next/app';
import type { JSX } from 'react';

import '../styles/mpfbs-admin.css';

/**
 * Renders the active page.
 */
export default function MpfbsAdminApp( { Component, pageProps }: AppProps ): JSX.Element {
	return <Component { ...pageProps } />;
}
