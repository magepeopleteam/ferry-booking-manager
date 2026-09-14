/**
 * Compact quantity picker for a party.
 *
 * A ferry sells five passenger types and ten vehicle types, and as a stack of
 * twenty labelled number inputs that is a screen and a half of scrolling to say
 * "two adults". One row per type with a minus, a count and a plus fits the lot
 * in a glance, and a row already at zero — which is most of them, most of the
 * time — costs a single line.
 *
 * The rows carry their own subtotal count so staff can see what they have built
 * without adding it up themselves.
 */

import type { JSX } from 'react';

import { Icon } from './Icon';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';

export interface QuantityType {
	id: number;
	name: string;
	/** Zero means no per-type limit. */
	max_per_booking: number;
	/** Shown under the name, e.g. an age band or a length. */
	meta?: string;
	/** True when the type is priced at nothing on purpose. */
	is_free?: boolean;
}

export interface QuantityGridProps {
	title: string;
	types: QuantityType[];
	values: Record< number, number >;
	onChange: ( values: Record< number, number > ) => void;
	/** Shown instead of the rows when there is nothing to sell. */
	empty?: string;
	/**
	 * Unit fare per type id, in minor units, for the crossing chosen. A type
	 * absent from the map has no known fare here and shows nothing — never a
	 * zero, which would read as free.
	 */
	fares?: Record< string, number >;
}

/**
 * Renders one group of quantity rows.
 */
export function QuantityGrid( { title, types, values, onChange, empty, fares }: QuantityGridProps ): JSX.Element | null {
	if ( types.length === 0 ) {
		return empty === undefined ? null : (
			<div className="fbm-qty">
				<p className="fbm-qty__title">{ title }</p>
				<p className="fbm-qty__empty">{ empty }</p>
			</div>
		);
	}

	const total = types.reduce( ( sum, type ) => sum + ( values[ type.id ] ?? 0 ), 0 );

	const set = ( id: number, next: number, ceiling: number ): void => {
		// Both limits use zero to mean "no limit", so an unset one must not
		// become a ceiling of zero.
		const capped = ceiling > 0 ? Math.min( ceiling, next ) : next;

		onChange( { ...values, [ id ]: Math.max( 0, capped ) } );
	};

	return (
		<div className="fbm-qty">
			<p className="fbm-qty__title">
				{ title }
				{ total > 0 ? <span className="fbm-qty__count">{ total }</span> : null }
			</p>

			<ul className="fbm-qty__list">
				{ types.map( ( type ) => {
					const value = values[ type.id ] ?? 0;
					const ceiling = type.max_per_booking;
					const atTop = ceiling > 0 && value >= ceiling;

					return (
						<li className={ `fbm-qty__row${ value > 0 ? ' is-chosen' : '' }` } key={ type.id }>
							<span className="fbm-qty__label">
								<span className="fbm-qty__name">{ type.name }</span>
								{ type.meta ? <span className="fbm-qty__meta">{ type.meta }</span> : null }
							</span>

							<FareTag fare={ fares?.[ String( type.id ) ] } free={ !! type.is_free } />

							<span className="fbm-qty__stepper">
								<button
									type="button"
									className="fbm-qty__button"
									onClick={ () => set( type.id, value - 1, ceiling ) }
									disabled={ value <= 0 }
									aria-label={ fbmFormat( 'One fewer %s', type.name ) }
								>
									<Icon name="minus" size={ 14 } />
								</button>
								<output className="fbm-qty__value" aria-live="polite">
									{ value }
								</output>
								<button
									type="button"
									className="fbm-qty__button"
									onClick={ () => set( type.id, value + 1, ceiling ) }
									disabled={ atTop }
									aria-label={ fbmFormat( 'One more %s', type.name ) }
								>
									<Icon name="plus" size={ 14 } />
								</button>
							</span>
						</li>
					);
				} ) }
			</ul>

			{ total === 0 ? <p className="fbm-qty__empty">{ fbmText( 'Nobody added yet.' ) }</p> : null }
		</div>
	);
}

/**
 * One row's unit fare on the chosen crossing.
 *
 * "Free" only when the operator set the type up that way. A zero from a type
 * the route simply does not price says nothing, because a desk quoting "free"
 * for something the customer will be charged for is worse than a blank.
 */
function FareTag( { fare, free }: { fare: number | undefined; free: boolean } ): JSX.Element | null {
	if ( fare === undefined ) {
		return null;
	}

	if ( fare === 0 ) {
		return free ? <span className="fbm-qty__fare is-free">{ fbmText( 'Free' ) }</span> : null;
	}

	return <span className="fbm-qty__fare">{ fbmFormatMoney( fare ) }</span>;
}
