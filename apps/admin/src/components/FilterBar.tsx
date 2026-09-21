/**
 * Listing toolbar: search, filters and the primary action.
 */

import type { JSX, ReactNode } from 'react';
import { Listbox } from './Listbox';

import { Icon } from './Icon';
import { mpfbsText } from '../lib/i18n';

export interface FilterOption {
	value: string;
	label: string;
}

export interface FilterBarProps {
	search: string;
	onSearch: ( value: string ) => void;
	searchPlaceholder?: string;
	filters?: ReactNode;
	statusOptions?: FilterOption[];
	status?: string;
	onStatus?: ( value: string ) => void;
	actions?: ReactNode;
}

/**
 * Renders the controls above a listing.
 */
export function FilterBar( {
	search,
	onSearch,
	searchPlaceholder,
	filters,
	statusOptions,
	status = '',
	onStatus,
	actions,
}: FilterBarProps ): JSX.Element {
	return (
		<div className="mpfbs-filter-bar">
			<div className="mpfbs-filter-bar__search">
				<Icon name="search" size={ 16 } />
				<input
					type="search"
					className="mpfbs-input mpfbs-input--bare"
					value={ search }
					placeholder={ searchPlaceholder ?? mpfbsText( 'Search' ) }
					aria-label={ searchPlaceholder ?? mpfbsText( 'Search' ) }
					onChange={ ( event ) => onSearch( event.target.value ) }
				/>
			</div>

			{ statusOptions && onStatus ? (
				<div className="mpfbs-filter-bar__filter">
					<Listbox
						value={ status }
						options={ statusOptions }
						placeholder={ mpfbsText( 'All statuses' ) }
						ariaLabel={ mpfbsText( 'Status' ) }
						onChange={ onStatus }
					/>
				</div>
			) : null }

			{ filters }

			{ actions ? <div className="mpfbs-filter-bar__actions">{ actions }</div> : null }
		</div>
	);
}
