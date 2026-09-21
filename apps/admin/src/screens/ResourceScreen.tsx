/**
 * Generic resource screen.
 *
 * Listing, search, filtering, pagination, create, edit and delete for any
 * schema-backed entity. Each resource supplies its columns and form fields; the
 * behaviour around them — optimistic-free writes, server-side field errors,
 * confirmation before destructive actions — is identical everywhere, which is
 * what makes the dashboard predictable.
 */

import { useCallback, useMemo, useRef, useState, type JSX } from 'react';

import { ConfirmDialog } from '../components/ConfirmDialog';
import { DataTable } from '../components/DataTable';
import { Drawer } from '../components/Drawer';
import { FilterBar } from '../components/FilterBar';
import { FormSteps } from '../components/FormSteps';
import { RouteFaresField, type FareOverrides } from '../components/RouteFaresField';
import {
	MultiSelectField,
	NumberField,
	SelectField,
	SwitchField,
	TagsField,
	TextAreaField,
	TextField,
} from '../components/Fields';
import { PageHeader } from '../components/PageHeader';
import { Pagination } from '../components/Pagination';
import { EmptyState, ErrorState } from '../components/States';
import { useMpfbsToast } from '../components/Toast';
import { mpfbsRequest, MpfbsApiError } from '../lib/api';
import { mpfbsFormat, mpfbsText } from '../lib/i18n';
import { mpfbsMoneyStep, mpfbsToMajor, mpfbsToMinor } from '../lib/money';
import { mpfbsInvalidateReferences, useMpfbsReferences } from '../lib/references';
import { useMpfbsCollection } from '../lib/useCollection';
import type { ResourceConfig, ResourceField, ResourceRecord } from '../config/resources';

export interface ResourceScreenProps {
	config: ResourceConfig;
}

/**
 * Renders a complete CRUD screen for one resource.
 */
export function ResourceScreen( { config }: ResourceScreenProps ): JSX.Element {
	const collection = useMpfbsCollection< ResourceRecord >( config.endpoint, config.initialQuery );
	const { references } = useMpfbsReferences();
	const toast = useMpfbsToast();

	const [ editing, setEditing ] = useState< ResourceRecord | null >( null );
	const [ drawerOpen, setDrawerOpen ] = useState( false );
	const [ values, setValues ] = useState< Record< string, unknown > >( {} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ formError, setFormError ] = useState< string >( '' );
	const [ saving, setSaving ] = useState( false );
	const [ deleting, setDeleting ] = useState< ResourceRecord | null >( null );
	const [ deleteBusy, setDeleteBusy ] = useState( false );
	const [ step, setStep ] = useState( 0 );
	const [ visited, setVisited ] = useState( 0 );
	const [ routeFares, setRouteFares ] = useState< FareOverrides >( {} );
	const alertRef = useRef< HTMLDivElement >( null );

	const steps = config.steps ?? [];
	const stepped = steps.length > 1;
	const lastStep = step >= steps.length - 1;

	/*
	 * Disabling the submit button while the request is in flight drops focus to
	 * the document body, so it has to be placed deliberately once the server
	 * answers: on the first field the server rejected, or on the summary when
	 * the failure is not attributable to one field.
	 */
	const focusFirstProblem = useCallback( ( fields: Record< string, string > ) => {
		window.requestAnimationFrame( () => {
			const first = Object.keys( fields )[ 0 ];
			const control = first
				? document.querySelector< HTMLElement >( `[data-mpfbs-field="${ first }"] input, [data-mpfbs-field="${ first }"] select, [data-mpfbs-field="${ first }"] textarea` )
				: null;

			( control ?? alertRef.current )?.focus();
		} );
	}, [] );

	const openCreate = useCallback( () => {
		setEditing( null );
		setValues( { name: '', ...config.defaults } );
		setErrors( {} );
		setFormError( '' );
		setStep( 0 );
		setVisited( 0 );
		setRouteFares( {} );
		setDrawerOpen( true );
	}, [ config.defaults ] );

	const openEdit = useCallback(
		( record: ResourceRecord ) => {
			const next: Record< string, unknown > = { name: record.name };

			config.fields.forEach( ( field ) => {
				const stored = record[ field.name ] ?? config.defaults[ field.name ] ?? '';

				// Prices are stored and transmitted in minor units; the form
				// works in the major units an operator actually types.
				next[ field.name ] = field.money ? mpfbsToMajor( Number( stored ) ) : stored;
			} );

			setEditing( record );
			setValues( next );
			setErrors( {} );
			setFormError( '' );
			setStep( 0 );
			// Every step is already reachable on a record that exists: an
			// operator fixing one number should not have to walk to it.
			setVisited( Math.max( 0, ( config.steps?.length ?? 1 ) - 1 ) );
			setRouteFares( {} );
			setDrawerOpen( true );
		},
		[ config.fields, config.defaults ]
	);

	const closeDrawer = useCallback( () => {
		setDrawerOpen( false );
		setEditing( null );
	}, [] );

	const setValue = useCallback( ( name: string, value: unknown ) => {
		setValues( ( current ) => ( { ...current, [ name ]: value } ) );
		setErrors( ( current ) => {
			if ( ! current[ name ] ) {
				return current;
			}

			const next = { ...current };
			delete next[ name ];

			return next;
		} );
	}, [] );

	/*
	 * Fields are laid out by the steps when there are any, and any field a step
	 * forgot to name is appended to the last one. A field that exists but is
	 * unreachable would be a field nobody can ever set, which is a worse
	 * failure than an untidy final step.
	 */
	const layout = useMemo( () => {
		if ( ! stepped ) {
			return [ { step: null, fields: config.fields } ];
		}

		const byName = new Map( config.fields.map( ( field ) => [ field.name, field ] ) );
		const claimed = new Set< string >();

		const groups = steps.map( ( entry ) => {
			const fields: ResourceField[] = [];

			entry.fields.forEach( ( name ) => {
				const field = byName.get( name );

				if ( field ) {
					fields.push( field );
					claimed.add( name );
				}
			} );

			return { step: entry, fields };
		} );

		const orphans = config.fields.filter( ( field ) => ! claimed.has( field.name ) );

		if ( orphans.length > 0 && groups.length > 0 ) {
			groups[ groups.length - 1 ]!.fields.push( ...orphans );
		}

		return groups;
	}, [ config.fields, stepped, steps ] );

	/** Says which step a field is on, for jumping to a rejected one. */
	const stepOf = useCallback(
		( name: string ): number => {
			const index = layout.findIndex( ( group ) => group.fields.some( ( field ) => field.name === name ) );

			return index < 0 ? 0 : index;
		},
		[ layout ]
	);

	/*
	 * Checked before Next rather than only on save. The server is still the
	 * authority — this only stops an operator reaching step four before being
	 * told that step one is incomplete.
	 */
	const validateStep = useCallback(
		( index: number ): boolean => {
			const group = layout[ index ];

			if ( ! group ) {
				return true;
			}

			const missing: Record< string, string > = {};

			if ( group.step?.includesName && config.showNameField && String( values.name ?? '' ).trim() === '' ) {
				missing.name = mpfbsText( 'This cannot be empty.' );
			}

			group.fields.forEach( ( field ) => {
				if ( ! field.required || ( field.visibleWhen && ! field.visibleWhen( values ) ) ) {
					return;
				}

				if ( String( values[ field.name ] ?? '' ).trim() === '' ) {
					missing[ field.name ] = mpfbsText( 'This cannot be empty.' );
				}
			} );

			if ( Object.keys( missing ).length === 0 ) {
				return true;
			}

			setErrors( ( current ) => ( { ...current, ...missing } ) );
			focusFirstProblem( missing );

			return false;
		},
		[ config.showNameField, focusFirstProblem, layout, values ]
	);

	const goToStep = useCallback(
		( index: number ) => {
			// Moving forward one at a time is gated; going back, or jumping
			// about on a record that already exists, never is.
			if ( index > step && index > visited && ! validateStep( step ) ) {
				return;
			}

			setStep( index );
			setVisited( ( seen ) => Math.max( seen, index ) );
		},
		[ step, validateStep, visited ]
	);

	/**
	 * Writes this type's fare onto each route that changed.
	 *
	 * Returns the routes it could not write, so a partial failure is reported
	 * as one rather than swallowed behind a success message.
	 */
	const saveRouteFares = useCallback(
		async ( typeId: number ): Promise< string[] > => {
			if ( typeId < 1 || ! steps.some( ( entry ) => entry.custom === 'route-fares' ) ) {
				return [];
			}

			const failed: string[] = [];

			for ( const route of references.routes ) {
				const typed = routeFares[ route.id ];

				try {
					const current = await mpfbsRequest< { vehicle_prices?: Record< string, unknown > } >( `routes/${ route.id }` );
					const table: Record< string, unknown > = { ...( current.data.vehicle_prices ?? {} ) };
					const before = table[ String( typeId ) ];
					const after = typed === undefined || typed === '' ? undefined : mpfbsToMinor( Number( typed ) );

					if ( String( before ?? '' ) === String( after ?? '' ) ) {
						continue;
					}

					if ( after === undefined ) {
						delete table[ String( typeId ) ];
					} else {
						table[ String( typeId ) ] = after;
					}

					await mpfbsRequest( `routes/${ route.id }`, { method: 'PUT', body: { vehicle_prices: table } } );
				} catch {
					failed.push( route.name );
				}
			}

			return failed;
		},
		[ references.routes, routeFares, steps ]
	);

	const save = useCallback( async () => {
		// Every step, not just the one on screen: Save is reachable from any of
		// them on a record that exists, so an earlier step left incomplete has
		// to be found and shown rather than posted.
		for ( let index = 0; index < Math.max( 1, layout.length ); index++ ) {
			if ( ! validateStep( index ) ) {
				setStep( index );

				return;
			}
		}

		setSaving( true );
		setErrors( {} );
		setFormError( '' );

		const body: Record< string, unknown > = { ...values };

		config.fields.forEach( ( field ) => {
			if ( field.money ) {
				body[ field.name ] = mpfbsToMinor( Number( values[ field.name ] ?? 0 ) );
			}
		} );

		try {
			const response = await mpfbsRequest< ResourceRecord >(
				editing ? `${ config.endpoint }/${ editing.id }` : config.endpoint,
				{ method: editing ? 'PUT' : 'POST', body }
			);

			// The fares are written after the record, because a new type has no
			// id to hang them on until it exists.
			const failed = await saveRouteFares( editing ? editing.id : Number( response.data?.id ?? 0 ) );

			if ( failed.length > 0 ) {
				toast.notify(
					mpfbsFormat( 'Saved, but the fare could not be set on: %s. Set it on the Pricing screen.', failed.join( ', ' ) ),
					'error'
				);
			} else {
				toast.notify(
					editing
						? mpfbsFormat( '%s updated.', mpfbsText( config.singularLabel ) )
						: mpfbsFormat( '%s created.', mpfbsText( config.singularLabel ) ),
					'success'
				);
			}

			closeDrawer();
			collection.reload();

			if ( config.invalidatesReferences ) {
				mpfbsInvalidateReferences();
			}
		} catch ( caught: unknown ) {
			if ( caught instanceof MpfbsApiError ) {
				const fields =
					caught.details.fields && typeof caught.details.fields === 'object'
						? ( caught.details.fields as Record< string, string > )
						: {};

				setErrors( fields );
				setFormError( caught.message );

				// Land on the step holding whatever the server rejected, or the
				// message would be about a field that is not on screen.
				const first = Object.keys( fields )[ 0 ];

				if ( stepped && first ) {
					const target = stepOf( first );
					setStep( target );
					setVisited( ( seen ) => Math.max( seen, target ) );
				}

				focusFirstProblem( fields );
			} else {
				setFormError( mpfbsText( 'Something went wrong.' ) );
				focusFirstProblem( {} );
			}
		} finally {
			setSaving( false );
		}
	}, [
		editing,
		config,
		values,
		toast,
		closeDrawer,
		collection,
		focusFirstProblem,
		layout.length,
		saveRouteFares,
		stepOf,
		stepped,
		validateStep,
	] );

	const confirmDelete = useCallback( async () => {
		if ( ! deleting ) {
			return;
		}

		setDeleteBusy( true );

		try {
			await mpfbsRequest( `${ config.endpoint }/${ deleting.id }`, { method: 'DELETE' } );
			toast.notify( mpfbsFormat( '%s deleted.', mpfbsText( config.singularLabel ) ), 'success' );
			setDeleting( null );
			collection.reload();

			if ( config.invalidatesReferences ) {
				mpfbsInvalidateReferences();
			}
		} catch ( caught: unknown ) {
			toast.notify( caught instanceof MpfbsApiError ? caught.message : mpfbsText( 'Something went wrong.' ), 'error' );
			setDeleting( null );
		} finally {
			setDeleteBusy( false );
		}
	}, [ deleting, config, toast, collection ] );

	const columns = useMemo( () => config.columns( references ), [ config, references ] );

	const current = layout[ stepped ? step : 0 ];

	if ( collection.error ) {
		return (
			<>
				<PageHeader title={ mpfbsText( config.label ) } />
				<ErrorState message={ collection.error.message } code={ collection.error.code } onRetry={ collection.reload } />
			</>
		);
	}

	const total = Number( collection.meta.total ?? 0 );
	const filtered = collection.query.search !== '' || collection.query.status !== '';

	return (
		<>
			<PageHeader
				title={ mpfbsText( config.label ) }
				description={ mpfbsText( config.description ) }
				actions={
					<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ openCreate }>
						{ mpfbsFormat( 'Add %s', mpfbsText( config.singularLabel ) ) }
					</button>
				}
			/>

			<div className="mpfbs-panel">
				<FilterBar
					search={ collection.query.search }
					onSearch={ collection.setSearch }
					searchPlaceholder={ mpfbsText( config.searchPlaceholder ) }
					statusOptions={ config.statusOptions }
					status={ collection.query.status }
					onStatus={ ( value ) => collection.setQuery( { status: value } ) }
				/>

				<DataTable< ResourceRecord >
					columns={ columns }
					items={ collection.items }
					rowKey={ ( item ) => item.id }
					loading={ collection.loading }
					refreshing={ collection.refreshing }
					orderby={ collection.query.orderby }
					order={ collection.query.order }
					onSort={ collection.toggleSort }
					onRowClick={ openEdit }
					actions={ [
						{
							key: 'edit',
							label: mpfbsText( 'Edit' ),
							onSelect: openEdit,
						},
						{
							key: 'delete',
							label: mpfbsText( 'Delete' ),
							tone: 'danger',
							onSelect: ( item ) => setDeleting( item ),
						},
					] }
					emptyState={
						<EmptyState
							icon={ config.icon }
							title={
								filtered
									? mpfbsText( 'No matching records.' )
									: mpfbsFormat( 'No %s yet.', mpfbsText( config.label ).toLowerCase() )
							}
							description={ filtered ? mpfbsText( 'Try a different search or filter.' ) : mpfbsText( config.emptyHint ) }
							action={
								filtered ? null : (
									<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ openCreate }>
										{ mpfbsFormat( 'Add %s', mpfbsText( config.singularLabel ) ) }
									</button>
								)
							}
						/>
					}
				/>

				<Pagination
					page={ Number( collection.meta.page ?? 1 ) }
					perPage={ Number( collection.meta.per_page ?? 20 ) }
					total={ total }
					totalPages={ Number( collection.meta.total_pages ?? 1 ) }
					onPage={ collection.setPage }
					onPerPage={ ( perPage ) => collection.setQuery( { per_page: perPage } ) }
				/>
			</div>

			<Drawer
				open={ drawerOpen }
				title={
					editing
						? mpfbsFormat( 'Edit %s', mpfbsText( config.singularLabel ) )
						: mpfbsFormat( 'Add %s', mpfbsText( config.singularLabel ) )
				}
				description={ editing ? editing.name : mpfbsText( config.description ) }
				onClose={ closeDrawer }
				width={ config.drawerWidth }
				footer={
					<>
						{ stepped && step > 0 ? (
							<button
								type="button"
								className="mpfbs-button mpfbs-button--secondary"
								onClick={ () => goToStep( step - 1 ) }
								disabled={ saving }
							>
								{ mpfbsText( 'Back' ) }
							</button>
						) : (
							<button type="button" className="mpfbs-button mpfbs-button--secondary" onClick={ closeDrawer } disabled={ saving }>
								{ mpfbsText( 'Cancel' ) }
							</button>
						) }

						<div className="mpfbs-drawer__footer-end">
							{ /*
							 * Save sits beside Next from the first step when the
							 * record already exists: an operator correcting a
							 * code should not be walked to the end to commit it.
							 */ }
							{ stepped && ! lastStep && editing ? (
								<button type="button" className="mpfbs-button" onClick={ save } disabled={ saving }>
									{ saving ? mpfbsText( 'Saving…' ) : mpfbsText( 'Save' ) }
								</button>
							) : null }

							{ stepped && ! lastStep ? (
								<button
									type="button"
									className="mpfbs-button mpfbs-button--primary"
									onClick={ () => goToStep( step + 1 ) }
									disabled={ saving }
								>
									{ mpfbsText( 'Next' ) }
								</button>
							) : (
								<button type="button" className="mpfbs-button mpfbs-button--primary" onClick={ save } disabled={ saving }>
									{ saving ? mpfbsText( 'Saving…' ) : mpfbsText( 'Save' ) }
								</button>
							) }
						</div>
					</>
				}
			>
				<form
					className="mpfbs-form"
					onSubmit={ ( event ) => {
						event.preventDefault();
						void save();
					} }
				>
					{ formError ? (
						<div className="mpfbs-alert mpfbs-alert--error" role="alert" ref={ alertRef } tabIndex={ -1 }>
							{ formError }
						</div>
					) : null }

					{ stepped ? (
						<FormSteps
							steps={ steps }
							current={ step }
							reachable={ ( index ) => index <= Math.max( visited, step ) }
							invalid={ ( index ) =>
								Object.keys( errors ).some( ( name ) =>
									name === 'name' ? !! steps[ index ]?.includesName : stepOf( name ) === index
								)
							}
							onSelect={ goToStep }
						/>
					) : null }

					{ current?.step?.description ? (
						<p className="mpfbs-form__note">{ mpfbsText( current.step.description ) }</p>
					) : null }

					{ config.showNameField && ( ! stepped || current?.step?.includesName ) ? (
						<TextField
							label={ mpfbsText( config.nameLabel ) }
							name="name"
							value={ String( values.name ?? '' ) }
							onChange={ ( value ) => setValue( 'name', value ) }
							error={ errors.name }
							required
						/>
					) : null }

					{ ( current?.fields ?? [] ).map( ( field ) => {
						if ( field.visibleWhen && ! field.visibleWhen( values ) ) {
							return null;
						}

						const error = errors[ field.name ];
						const common = {
							key: field.name,
							name: field.name,
							label: mpfbsText( field.label ),
							error,
							hint: field.hint ? mpfbsText( field.hint ) : undefined,
						};

						switch ( field.type ) {
							case 'number':
								return (
									<NumberField
										{ ...common }
										value={ Number( values[ field.name ] ?? 0 ) }
										onChange={ ( value ) => setValue( field.name, value ) }
										min={ field.min }
										max={ field.max }
										step={ field.money ? mpfbsMoneyStep() : field.step }
										suffix={ field.suffix }
										required={ field.required }
									/>
								);

							case 'textarea':
								return (
									<TextAreaField
										{ ...common }
										value={ String( values[ field.name ] ?? '' ) }
										onChange={ ( value ) => setValue( field.name, value ) }
										rows={ field.rows }
									/>
								);

							case 'select':
								return (
									<SelectField
										{ ...common }
										value={ String( values[ field.name ] ?? '' ) }
										options={ field.options ? field.options( references ) : [] }
										onChange={ ( value ) => setValue( field.name, field.numeric ? Number( value ) : value ) }
										placeholder={ field.placeholder ? mpfbsText( field.placeholder ) : undefined }
										required={ field.required }
									/>
								);

							case 'switch':
								return (
									<SwitchField
										key={ field.name }
										label={ mpfbsText( field.label ) }
										checked={ Boolean( values[ field.name ] ) }
										onChange={ ( checked ) => setValue( field.name, checked ) }
										hint={ field.hint ? mpfbsText( field.hint ) : undefined }
									/>
								);

							case 'tags':
								return (
									<TagsField
										key={ field.name }
										label={ mpfbsText( field.label ) }
										values={ ( values[ field.name ] as string[] ) ?? [] }
										onChange={ ( next ) => setValue( field.name, next ) }
										placeholder={ field.placeholder ? mpfbsText( field.placeholder ) : undefined }
										hint={ field.hint ? mpfbsText( field.hint ) : undefined }
									/>
								);

							case 'multiselect':
								return (
									<MultiSelectField
										key={ field.name }
										label={ mpfbsText( field.label ) }
										values={ ( values[ field.name ] as number[] ) ?? [] }
										options={ field.options ? field.options( references ) : [] }
										onChange={ ( next ) => setValue( field.name, next ) }
										hint={ field.hint ? mpfbsText( field.hint ) : undefined }
									/>
								);

							default:
								return (
									<TextField
										{ ...common }
										type={ field.type }
										value={ String( values[ field.name ] ?? '' ) }
										onChange={ ( value ) => setValue( field.name, value ) }
										placeholder={ field.placeholder }
										required={ field.required }
									/>
								);
						}
					} ) }

					{ current?.step?.custom === 'route-fares' ? (
						<RouteFaresField
							typeId={ editing ? editing.id : 0 }
							defaultFare={ Number( values.base_price ?? 0 ) }
							values={ routeFares }
							onChange={ setRouteFares }
						/>
					) : null }
				</form>
			</Drawer>

			<ConfirmDialog
				open={ deleting !== null }
				title={ mpfbsFormat( 'Delete %s?', mpfbsText( config.singularLabel ) ) }
				message={ mpfbsFormat( '“%s” will be moved to the trash. This cannot be undone from here.', deleting?.name ?? '' ) }
				confirmLabel={ mpfbsText( 'Delete' ) }
				busy={ deleteBusy }
				onConfirm={ confirmDelete }
				onCancel={ () => setDeleting( null ) }
			/>
		</>
	);
}
