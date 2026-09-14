/**
 * KPI card.
 */

import type { JSX } from 'react';

import { Icon, type IconName } from './Icon';
import { Skeleton } from './States';

export interface StatCardProps {
	label: string;
	value?: string | number;
	hint?: string;
	icon?: IconName;
	loading?: boolean;
	tone?: 'default' | 'positive' | 'warning' | 'danger';
}

/**
 * Renders a single dashboard metric.
 */
export function StatCard( { label, value, hint, icon, loading = false, tone = 'default' }: StatCardProps ): JSX.Element {
	return (
		<div className={ `fbm-stat fbm-stat--${ tone }` }>
			<div className="fbm-stat__head">
				<span className="fbm-stat__label">{ label }</span>
				{ icon ? (
					<span className="fbm-stat__icon">
						<Icon name={ icon } size={ 16 } />
					</span>
				) : null }
			</div>
			<div className="fbm-stat__value">
				{ loading ? <Skeleton width="60%" height="24px" /> : ( value ?? '—' ) }
			</div>
			{ hint ? <p className="fbm-stat__hint">{ hint }</p> : null }
		</div>
	);
}
