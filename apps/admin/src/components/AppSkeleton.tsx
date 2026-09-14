/**
 * Static application skeleton.
 *
 * This is what `next build` writes into the exported document and what
 * WordPress prints before a single byte of JavaScript has run, so operators see
 * the dashboard layout immediately instead of an empty admin page.
 *
 * It deliberately contains no translated text and reads no runtime
 * configuration: identical markup on the server and on the first client render
 * is what allows React to hydrate the real interface in place.
 */

import type { JSX } from 'react';

import { Skeleton } from './States';

const NAV_ITEMS = 9;
const STAT_CARDS = 6;

/**
 * Renders the structural placeholder for the whole dashboard.
 */
export function AppSkeleton(): JSX.Element {
	return (
		<div className="fbm-app fbm-app--booting" role="status" aria-label="Loading the Ferry Manager dashboard">
			<div className="fbm-sidebar" aria-hidden="true">
				<div className="fbm-sidebar__brand">
					<Skeleton width="32px" height="32px" radius="6px" />
					<Skeleton width="120px" height="14px" />
				</div>
				<div className="fbm-sidebar__list">
					{ Array.from( { length: NAV_ITEMS } ).map( ( _, index ) => (
						<div className="fbm-sidebar__item" key={ index }>
							<Skeleton width="82%" height="16px" />
						</div>
					) ) }
				</div>
			</div>

			<div className="fbm-app__body">
				<div className="fbm-header" aria-hidden="true">
					<Skeleton width="280px" height="32px" radius="10px" />
					<span className="fbm-header__meta">
						<Skeleton width="96px" height="20px" radius="999px" />
						<Skeleton width="28px" height="28px" radius="50%" />
					</span>
				</div>

				<div className="fbm-main">
					<div className="fbm-main__inner" aria-hidden="true">
						<Skeleton width="220px" height="26px" />
						<div className="fbm-stat-grid">
							{ Array.from( { length: STAT_CARDS } ).map( ( _, index ) => (
								<div className="fbm-stat" key={ index }>
									<Skeleton width="55%" height="11px" />
									<div className="fbm-stat__value">
										<Skeleton width="70%" height="24px" />
									</div>
								</div>
							) ) }
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}
