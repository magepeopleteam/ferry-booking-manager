/**
 * Step indicator.
 *
 * Shows how far through the booking a customer is, because the single biggest
 * cause of abandonment is not knowing how much is left.
 */

import type { JSX } from 'preact';

export interface Step {
	id: string;
	label: string;
}

export function Steps( { steps, current }: { steps: Step[]; current: string } ): JSX.Element {
	const index = Math.max( 0, steps.findIndex( ( step ) => step.id === current ) );

	return (
		<ol className="fbmb-steps" aria-label="progress">
			{ steps.map( ( step, position ) => {
				const state = position < index ? 'done' : position === index ? 'current' : 'todo';

				return (
					<li key={ step.id } className={ `fbmb-steps__item is-${ state }` } aria-current={ state === 'current' ? 'step' : undefined }>
						<span className="fbmb-steps__marker" aria-hidden="true">
							{ state === 'done' ? (
								<svg viewBox="0 0 20 20">
									<path d="m5 10.5 3.5 3.5L15 7" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
								</svg>
							) : (
								position + 1
							) }
						</span>
						<span className="fbmb-steps__label">{ step.label }</span>
					</li>
				);
			} ) }
		</ol>
	);
}
