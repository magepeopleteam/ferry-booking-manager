/**
 * Server-paginated data table.
 *
 * Never receives more rows than the page it displays, and renders a skeleton of
 * the same shape while loading so the layout does not jump when data arrives.
 */

import type { JSX, ReactNode } from 'react';

import { Icon } from './Icon';
import { Skeleton } from './States';
import { fbmText } from '../lib/i18n';

export interface Column< T > {
	key: string;
	label: string;
	/** Field name to sort by on the server; omit to make the column unsortable. */
	sortBy?: string;
	align?: 'start' | 'end';
	width?: string;
	render: ( item: T ) => ReactNode;
}

export interface RowAction< T > {
	key: string;
	label: string;
	tone?: 'default' | 'danger';
	onSelect: ( item: T ) => void;
	isVisible?: ( item: T ) => boolean;
}

export interface DataTableProps< T > {
	columns: Array< Column< T > >;
	items: T[];
	rowKey: ( item: T ) => string | number;
	loading?: boolean;
	refreshing?: boolean;
	emptyState?: ReactNode;
	actions?: Array< RowAction< T > >;
	orderby?: string;
	order?: 'asc' | 'desc';
	onSort?: ( field: string ) => void;
	onRowClick?: ( item: T ) => void;
}

/**
 * Renders one page of records.
 */
export function DataTable< T >( {
	columns,
	items,
	rowKey,
	loading = false,
	refreshing = false,
	emptyState,
	actions = [],
	orderby,
	order = 'asc',
	onSort,
	onRowClick,
}: DataTableProps< T > ): JSX.Element {
	const columnCount = columns.length + ( actions.length > 0 ? 1 : 0 );

	if ( ! loading && items.length === 0 ) {
		return <>{ emptyState }</>;
	}

	return (
		<div className={ `fbm-table-wrap${ refreshing ? ' is-refreshing' : '' }` }>
			<table className="fbm-table" aria-busy={ loading || refreshing }>
				<thead>
					<tr>
						{ columns.map( ( column ) => {
							const sortable = Boolean( column.sortBy && onSort );
							const active = Boolean( column.sortBy && column.sortBy === orderby );

							return (
								<th
									key={ column.key }
									scope="col"
									style={ column.width ? { width: column.width } : undefined }
									className={ column.align === 'end' ? 'is-end' : undefined }
									aria-sort={ active ? ( order === 'asc' ? 'ascending' : 'descending' ) : undefined }
								>
									{ sortable ? (
										<button
											type="button"
											className={ `fbm-table__sort${ active ? ' is-active' : '' }` }
											onClick={ () => onSort?.( column.sortBy as string ) }
										>
											{ column.label }
											<span className="fbm-table__sort-icon" aria-hidden="true">
												{ active ? ( order === 'asc' ? '↑' : '↓' ) : '↕' }
											</span>
										</button>
									) : (
										column.label
									) }
								</th>
							);
						} ) }
						{ actions.length > 0 ? (
							<th scope="col" className="is-end">
								<span className="fbm-screen-reader-text">{ fbmText( 'Actions' ) }</span>
							</th>
						) : null }
					</tr>
				</thead>

				<tbody>
					{ loading
						? Array.from( { length: 5 } ).map( ( _, rowIndex ) => (
								<tr key={ `skeleton-${ rowIndex }` } className="fbm-table__placeholder" aria-hidden="true">
									{ Array.from( { length: columnCount } ).map( ( __, cellIndex ) => (
										<td key={ cellIndex }>
											<Skeleton height="14px" width={ cellIndex === 0 ? '70%' : '45%' } />
										</td>
									) ) }
								</tr>
						  ) )
						: items.map( ( item ) => (
								<tr
									key={ rowKey( item ) }
									className={ onRowClick ? 'is-clickable' : undefined }
									onClick={ onRowClick ? () => onRowClick( item ) : undefined }
								>
									{ columns.map( ( column ) => (
										<td key={ column.key } className={ column.align === 'end' ? 'is-end' : undefined }>
											{ column.render( item ) }
										</td>
									) ) }

									{ actions.length > 0 ? (
										<td className="is-end">
											<div className="fbm-table__actions">
												{ actions
													.filter( ( action ) => ! action.isVisible || action.isVisible( item ) )
													.map( ( action ) => (
														<button
															key={ action.key }
															type="button"
															className={ `fbm-table__action${
																action.tone === 'danger' ? ' is-danger' : ''
															}` }
															onClick={ ( event ) => {
																event.stopPropagation();
																action.onSelect( item );
															} }
														>
															{ action.label }
														</button>
													) ) }
											</div>
										</td>
									) : null }
								</tr>
						  ) ) }
				</tbody>
			</table>

			{ refreshing ? (
				<span className="fbm-table__refresh" role="status">
					<Icon name="clock" size={ 14 } />
					<span className="fbm-screen-reader-text">{ fbmText( 'Loading' ) }</span>
				</span>
			) : null }
		</div>
	);
}
