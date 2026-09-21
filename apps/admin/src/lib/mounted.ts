/**
 * Mount detection.
 *
 * The dashboard is exported to static HTML at build time, where the WordPress
 * runtime configuration — capabilities, translations, the signed-in user — does
 * not exist. Anything derived from that configuration must therefore render
 * identically on the server and on the first client render, then update once
 * mounted, otherwise React discards the pre-rendered markup as a mismatch.
 */

import { useEffect, useState } from 'react';

/**
 * Returns false during the export and the first client render, true afterwards.
 */
export function useMpfbsMounted(): boolean {
	const [ mounted, setMounted ] = useState( false );

	useEffect( () => {
		setMounted( true );
	}, [] );

	return mounted;
}
