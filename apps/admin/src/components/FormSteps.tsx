/**
 * Step indicator for a multi-step form.
 *
 * Says where you are, how far there is to go, and what each step is going to
 * ask — a form that only says "Step 2 of 4" makes somebody guess whether the
 * thing they want is behind Next or behind Back.
 *
 * Steps already visited are clickable, and every step is clickable while
 * editing an existing record: correcting one field on a saved vehicle type
 * should not mean walking through four screens to reach it.
 */

import type { JSX } from 'react';

import { Icon } from './Icon';
import { fbmFormat, fbmText } from '../lib/i18n';

export interface FormStepsProps {
	steps: Array< { id: string; title: string } >;
	/** Index of the step on screen. */
	current: number;
	/** Steps the reader may jump straight to. */
	reachable: ( index: number ) => boolean;
	/** Steps holding a field the server rejected. */
	invalid?: ( index: number ) => boolean;
	onSelect: ( index: number ) => void;
}

/**
 * Renders the step rail.
 */
export function FormSteps( { steps, current, reachable, invalid, onSelect }: FormStepsProps ): JSX.Element {
	return (
		<ol className="fbm-steps" aria-label={ fbmText( 'Form steps' ) }>
			{ steps.map( ( step, index ) => {
				const done = index < current;
				const here = index === current;
				const broken = invalid ? invalid( index ) : false;
				const open = reachable( index );

				return (
					<li
						className={ [
							'fbm-steps__item',
							here ? 'is-current' : '',
							done ? 'is-done' : '',
							broken ? 'is-invalid' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						key={ step.id }
					>
						<button
							type="button"
							className="fbm-steps__button"
							onClick={ () => onSelect( index ) }
							disabled={ ! open }
							aria-current={ here ? 'step' : undefined }
						>
							<span className="fbm-steps__marker" aria-hidden="true">
								{ broken ? (
									<Icon name="alert" />
								) : done ? (
									<Icon name="check" />
								) : (
									index + 1
								) }
							</span>
							<span className="fbm-steps__label">
								<span className="fbm-steps__count">
									{ fbmFormat( 'Step %1$s of %2$s', String( index + 1 ), String( steps.length ) ) }
								</span>
								<span className="fbm-steps__title">{ fbmText( step.title ) }</span>
							</span>
						</button>
					</li>
				);
			} ) }
		</ol>
	);
}
