/**
 * Search results.
 *
 * Sold-out and unbookable crossings are listed and marked rather than hidden: a
 * customer who cannot see the 08:00 sailing assumes the site is broken, while
 * one who sees it marked full picks another time.
 */

import { useMemo, useState, type JSX } from 'preact/compat';

import { config } from '../lib/config';
import { date as formatDate, duration as formatDuration, time } from '../lib/datetime';
import { t, tf } from '../lib/i18n';
import { money } from '../lib/money';
import type { Bucket, SailingResult } from '../lib/types';
import { Badge, Button, Empty, Select } from './ui';

export type SortKey = 'earliest' | 'latest' | 'cheapest' | 'shortest';

export interface ResultsProps {
	title: string;
	subtitle: string;
	results: SailingResult[];
	selectedId: number;
	onSelect: ( sailing: SailingResult ) => void;
}

/**
 * Describes how much of a measure is left, in words a customer understands.
 */
function remainingLabel( bucket: Bucket | undefined ): { text: string; tone: 'positive' | 'warning' | 'danger' } {
	if ( ! bucket || bucket.unlimited ) {
		return { text: t( 'Available' ), tone: 'positive' };
	}

	const left = Number( bucket.remaining );

	if ( left <= 0 ) {
		return { text: t( 'Sold out' ), tone: 'danger' };
	}

	const cfg = config();

	if ( ! cfg.showRemainingSeats ) {
		return { text: t( 'Available' ), tone: 'positive' };
	}

	/*
	 * A precise count is only worth showing while it is small: "312 seats left"
	 * is noise, "3 seats left" is the reason someone books now. What counts as
	 * small depends on the vessel, so the operator sets both a proportion and an
	 * absolute number, and whichever is reached first wins. A proportion alone
	 * would treat a 900-berth ferry and a 12-seat launch alike; a number alone
	 * would never fire on the ferry until it was nearly empty of places.
	 */
	const capacity = Number( bucket.capacity );
	const proportion = cfg.lowAvailabilityAt > 0 && capacity > 0
		&& ( capacity - left ) / capacity >= cfg.lowAvailabilityAt / 100;
	const handful = cfg.lowAvailabilityLeft > 0 && left <= cfg.lowAvailabilityLeft;

	if ( proportion || handful ) {
		return { text: tf( '%s left', String( left ) ), tone: 'warning' };
	}

	return { text: t( 'Available' ), tone: 'positive' };
}

/**
 * Renders one leg's results.
 */
export function Results( { title, subtitle, results, selectedId, onSelect }: ResultsProps ): JSX.Element {
	const [ sort, setSort ] = useState< SortKey >( 'earliest' );
	const [ vesselFilter, setVesselFilter ] = useState( '' );
	const [ hideSoldOut, setHideSoldOut ] = useState( false );

	const vessels = useMemo( () => {
		const seen = new Map< string, string >();

		results.forEach( ( row ) => {
			if ( row.vessel.name ) {
				seen.set( String( row.vessel.id ), row.vessel.name );
			}
		} );

		return Array.from( seen.entries() ).map( ( [ value, label ] ) => ( { value, label } ) );
	}, [ results ] );

	const visible = useMemo( () => {
		const rows = results.filter( ( row ) => {
			if ( vesselFilter !== '' && String( row.vessel.id ) !== vesselFilter ) {
				return false;
			}

			if ( hideSoldOut && ( row.availability.sold_out || row.fits === false ) ) {
				return false;
			}

			return true;
		} );

		const sorted = [ ...rows ];

		sorted.sort( ( a, b ) => {
			switch ( sort ) {
				case 'latest':
					return b.departure_ts - a.departure_ts;

				case 'shortest':
					return a.duration - b.duration;

				case 'cheapest': {
					// A sailing with no price could not be quoted for this
					// party at all, so it belongs at the end rather than at the
					// top as a free crossing.
					const priceA = a.price ? a.price.total : Number.MAX_SAFE_INTEGER;
					const priceB = b.price ? b.price.total : Number.MAX_SAFE_INTEGER;

					return priceA - priceB || a.departure_ts - b.departure_ts;
				}

				default:
					return a.departure_ts - b.departure_ts;
			}
		} );

		return sorted;
	}, [ results, sort, vesselFilter, hideSoldOut ] );

	if ( results.length === 0 ) {
		return (
			<section className="mpfbsb-results">
				<header className="mpfbsb-results__header">
					<div>
						<h3 className="mpfbsb-results__title">{ title }</h3>
						<p className="mpfbsb-results__subtitle">{ subtitle }</p>
					</div>
				</header>
				<Empty title={ t( 'No sailings found.' ) } description={ t( 'Try a different date, or another crossing.' ) } />
			</section>
		);
	}

	return (
		<section className="mpfbsb-results">
			<header className="mpfbsb-results__header">
				<div>
					<h3 className="mpfbsb-results__title">{ title }</h3>
					<p className="mpfbsb-results__subtitle">{ subtitle }</p>
				</div>

				<div className="mpfbsb-results__controls">
					{ vessels.length > 1 ? (
						<Select
							value={ vesselFilter }
							placeholder={ t( 'All vessels' ) }
							options={ vessels }
							onChange={ setVesselFilter }
						/>
					) : null }

					<Select
						value={ sort }
						options={ [
							{ value: 'earliest', label: t( 'Earliest first' ) },
							{ value: 'latest', label: t( 'Latest first' ) },
							{ value: 'cheapest', label: t( 'Lowest price' ) },
							{ value: 'shortest', label: t( 'Shortest crossing' ) },
						] }
						onChange={ ( value ) => setSort( value as SortKey ) }
					/>

					<label className="mpfbsb-checkbox">
						<input
							type="checkbox"
							checked={ hideSoldOut }
							onChange={ ( event ) => setHideSoldOut( ( event.target as HTMLInputElement ).checked ) }
						/>
						<span>{ t( 'Hide unavailable' ) }</span>
					</label>
				</div>
			</header>

			<ul className="mpfbsb-results__list">
				{ visible.map( ( row ) => {
					const seats = remainingLabel( row.availability.passengers );
					const unavailable = row.availability.sold_out || row.fits === false;
					const selected = row.sailing_id === selectedId;

					return (
						<li key={ row.sailing_id }>
							<article className={ `mpfbsb-sailing${ selected ? ' is-selected' : '' }${ unavailable ? ' is-unavailable' : '' }` }>
								<div className="mpfbsb-sailing__times">
									<span className="mpfbsb-sailing__time">{ time( row.departure ) }</span>
									<span className="mpfbsb-sailing__line" aria-hidden="true">
										<span className="mpfbsb-sailing__dot" />
										<span className="mpfbsb-sailing__dash" />
										<span className="mpfbsb-sailing__dot" />
									</span>
									<span className="mpfbsb-sailing__time">{ time( row.arrival ) }</span>
								</div>

								<div className="mpfbsb-sailing__detail">
									<p className="mpfbsb-sailing__route">{ row.route_name }</p>
									<p className="mpfbsb-sailing__meta">
										<span>{ formatDate( row.departure, true ) }</span>
										<span aria-hidden="true">·</span>
										<span>{ formatDuration( row.duration ) }</span>
										{ row.vessel.name ? (
											<>
												<span aria-hidden="true">·</span>
												<span>{ row.vessel.name }</span>
											</>
										) : null }
									</p>
									<p className="mpfbsb-sailing__badges">
										<Badge tone={ seats.tone }>{ seats.text }</Badge>
										{ row.takes_vehicles ? (
											<Badge tone={ Number( row.availability.vehicles.remaining ) === 0 ? 'danger' : 'neutral' }>
												{ Number( row.availability.vehicles.remaining ) === 0
													? t( 'No vehicle space' )
													: t( 'Vehicles carried' ) }
											</Badge>
										) : (
											<Badge tone="neutral">{ t( 'Foot passengers only' ) }</Badge>
										) }
									</p>
								</div>

								<div className="mpfbsb-sailing__action">
									{ row.price ? (
										<p className="mpfbsb-sailing__price">
											<span className="mpfbsb-sailing__amount">{ money( row.price.total ) }</span>
											<span className="mpfbsb-sailing__pricemeta">{ t( 'total' ) }</span>
										</p>
									) : (
										<p className="mpfbsb-sailing__price">
											<span className="mpfbsb-sailing__pricemeta">{ t( 'Add a passenger to see fares.' ) }</span>
										</p>
									) }

									{ unavailable ? (
										<p className="mpfbsb-sailing__unavailable">{ row.unavailable ?? t( 'Sold out' ) }</p>
									) : (
										<Button variant={ selected ? 'secondary' : 'primary' } onClick={ () => onSelect( row ) }>
											{ selected ? t( 'Selected' ) : t( 'Choose' ) }
										</Button>
									) }
								</div>
							</article>
						</li>
					);
				} ) }
			</ul>

			{ visible.length === 0 ? (
				<Empty title={ t( 'Nothing matches those filters.' ) } description={ t( 'Clear a filter to see more crossings.' ) } />
			) : null }
		</section>
	);
}
