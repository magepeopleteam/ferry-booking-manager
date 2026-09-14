/**
 * The single exported page.
 *
 * Everything below this point is client-side routed inside the fragment, which
 * is what lets one WordPress admin screen host the whole dashboard.
 */

import Head from 'next/head';
import type { JSX } from 'react';

import { AdminShell } from '../components/AdminShell';

/**
 * Renders the dashboard entry point.
 */
export default function FbmDashboardPage(): JSX.Element {
	return (
		<>
			<Head>
				<title>Ferry Manager</title>
			</Head>
			<AdminShell />
		</>
	);
}
