/**
 * Server pagination control.
 */

import type { JSX } from 'react';
import { Listbox } from './Listbox';

import { mpfbsFormat, mpfbsText } from '../lib/i18n';

export interface PaginationProps {
	page: number;
	perPage: number;
	total: number;
	totalPages: number;
	onPage: ( page: number ) => void;
	onPerPage: ( perPage: number ) => void;
}

const PER_PAGE_OPTIONS = [ 20, 50, 100 ];

/**
 * Renders page controls and the row-count selector.
 */
export function Pagination( { page, perPage, total, totalPages, onPage, onPerPage }: PaginationProps ): JSX.Element | null {
	if ( total === 0 ) {
		return null;
	}

	const first = ( page - 1 ) * perPage + 1;
	const last = Math.min( total, page * perPage );

	return (
		<nav className="mpfbs-pagination" aria-label={ mpfbsText( 'Pagination' ) }>
			<span className="mpfbs-pagination__summary">
				{ mpfbsFormat( 'Showing %1$s to %2$s of %3$s', first, last, total ) }
			</span>

			<div className="mpfbs-pagination__per-page">
				<Listbox
					compact
					value={ perPage }
					options={ PER_PAGE_OPTIONS.map( ( option ) => ( { value: option, label: String( option ) } ) ) }
					ariaLabel={ mpfbsText( 'Rows per page' ) }
					onChange={ ( value ) => onPerPage( Number( value ) ) }
				/>
			</div>

			<div className="mpfbs-pagination__buttons">
				<button
					type="button"
					className="mpfbs-button mpfbs-button--secondary mpfbs-button--compact"
					onClick={ () => onPage( page - 1 ) }
					disabled={ page <= 1 }
				>
					{ mpfbsText( 'Previous' ) }
				</button>
				<span className="mpfbs-pagination__page">{ mpfbsFormat( 'Page %1$s of %2$s', page, Math.max( 1, totalPages ) ) }</span>
				<button
					type="button"
					className="mpfbs-button mpfbs-button--secondary mpfbs-button--compact"
					onClick={ () => onPage( page + 1 ) }
					disabled={ page >= totalPages }
				>
					{ mpfbsText( 'Next' ) }
				</button>
			</div>
		</nav>
	);
}
