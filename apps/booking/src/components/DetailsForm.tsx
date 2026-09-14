/**
 * Passenger, vehicle and customer detail capture.
 *
 * Which fields appear is decided by the operator in the dashboard, not here.
 * The server sends the resolved list — fields switched off are absent rather
 * than hidden — so the browser cannot render something it was told not to
 * collect, and the same validator checks it on the way back in.
 */

import type { JSX } from 'preact';

import { config } from '../lib/config';
import { t, tf } from '../lib/i18n';
import type { CaptureField, PassengerTypeOption, VehicleTypeOption } from '../lib/types';
import { Field, Input, Select } from './ui';

export interface PartyMember {
	type_id: number;
	details: Record< string, string >;
}

export interface CustomerDetails {
	name: string;
	email: string;
	phone: string;
	notes: string;
	acceptedTerms: boolean;
}

/**
 * Renders one configured capture field.
 */
function CaptureInput( {
	field,
	value,
	error,
	onChange,
	idPrefix,
}: {
	field: CaptureField;
	value: string;
	error?: string;
	onChange: ( value: string ) => void;
	idPrefix: string;
} ): JSX.Element {
	const id = `${ idPrefix }-${ field.key }`;

	if ( field.type === 'select' && field.key === 'gender' ) {
		return (
			<Field label={ field.label } htmlFor={ id } required={ field.required } error={ error }>
				<Select
					id={ id }
					value={ value }
					placeholder={ t( 'Prefer not to say' ) }
					options={ [
						{ value: 'female', label: t( 'Female' ) },
						{ value: 'male', label: t( 'Male' ) },
						{ value: 'other', label: t( 'Other' ) },
					] }
					onChange={ onChange }
				/>
			</Field>
		);
	}

	if ( field.type === 'select' && field.key === 'document_type' ) {
		return (
			<Field label={ field.label } htmlFor={ id } required={ field.required } error={ error }>
				<Select
					id={ id }
					value={ value }
					placeholder={ t( 'Select' ) }
					options={ [
						{ value: 'passport', label: t( 'Passport' ) },
						{ value: 'id_card', label: t( 'National ID card' ) },
						{ value: 'driving_licence', label: t( 'Driving licence' ) },
					] }
					onChange={ onChange }
				/>
			</Field>
		);
	}

	if ( field.type === 'switch' ) {
		return (
			<div className="fbmb-field">
				<label className="fbmb-checkbox">
					<input
						type="checkbox"
						checked={ value === '1' }
						onChange={ ( event ) => onChange( ( event.target as HTMLInputElement ).checked ? '1' : '' ) }
					/>
					<span>{ field.label }</span>
				</label>
				{ error ? (
					<p className="fbmb-field__error" role="alert">
						{ error }
					</p>
				) : null }
			</div>
		);
	}

	const autoComplete =
		field.key === 'first_name'
			? 'given-name'
			: field.key === 'last_name'
				? 'family-name'
				: field.key === 'email'
					? 'email'
					: field.key === 'phone'
						? 'tel'
						: undefined;

	return (
		<Field label={ field.label } htmlFor={ id } required={ field.required } error={ error }>
			<Input
				id={ id }
				type={ field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : field.type === 'email' ? 'email' : field.type === 'tel' ? 'tel' : 'text' }
				value={ value }
				autoComplete={ autoComplete }
				onChange={ onChange }
			/>
		</Field>
	);
}

export interface PartyDetailsProps {
	passengers: PartyMember[];
	vehicles: PartyMember[];
	passengerFields: CaptureField[];
	vehicleFields: CaptureField[];
	passengerTypes: PassengerTypeOption[];
	vehicleTypes: VehicleTypeOption[];
	errors: Record< string, string >;
	onPassenger: ( index: number, key: string, value: string ) => void;
	onVehicle: ( index: number, key: string, value: string ) => void;
}

/**
 * Renders a detail form for every traveller and vehicle.
 */
export function PartyDetails( {
	passengers,
	vehicles,
	passengerFields,
	vehicleFields,
	passengerTypes,
	vehicleTypes,
	errors,
	onPassenger,
	onVehicle,
}: PartyDetailsProps ): JSX.Element {
	const typeName = ( id: number, list: Array< { id: number; name: string } > ): string =>
		list.find( ( item ) => item.id === id )?.name ?? '';

	return (
		<div className="fbmb-details">
			{ passengers.length > 0 ? (
				<section>
					<h3 className="fbmb-section__title">{ t( 'Passenger details' ) }</h3>
					{ passengers.map( ( member, index ) => {
						const type = passengerTypes.find( ( item ) => item.id === member.type_id );
						const fields = type?.requires_dob
							? withRequiredDob( passengerFields )
							: passengerFields;

						return (
							<div className="fbmb-details__block" key={ `p-${ index }` }>
								<h4 className="fbmb-details__heading">
									{ tf( '%1$s %2$s', typeName( member.type_id, passengerTypes ), String( passengerIndex( passengers, index ) ) ) }
								</h4>
								<div className="fbmb-details__grid">
									{ fields.map( ( field ) => (
										<CaptureInput
											key={ field.key }
											field={ field }
											idPrefix={ `fbm-p${ index }` }
											value={ member.details[ field.key ] ?? '' }
											error={ errors[ `passengers.${ index }.${ field.key }` ] }
											onChange={ ( value ) => onPassenger( index, field.key, value ) }
										/>
									) ) }
								</div>
							</div>
						);
					} ) }
				</section>
			) : null }

			{ vehicles.length > 0 ? (
				<section>
					<h3 className="fbmb-section__title">{ t( 'Vehicle details' ) }</h3>
					{ vehicles.map( ( member, index ) => {
						const type = vehicleTypes.find( ( item ) => item.id === member.type_id );
						const fields = adjustVehicleFields( vehicleFields, type );

						return (
							<div className="fbmb-details__block" key={ `v-${ index }` }>
								<h4 className="fbmb-details__heading">{ typeName( member.type_id, vehicleTypes ) }</h4>
								<div className="fbmb-details__grid">
									{ fields.map( ( field ) => (
										<CaptureInput
											key={ field.key }
											field={ field }
											idPrefix={ `fbm-v${ index }` }
											value={ member.details[ field.key ] ?? '' }
											error={ errors[ `vehicles.${ index }.${ field.key }` ] }
											onChange={ ( value ) => onVehicle( index, field.key, value ) }
										/>
									) ) }
								</div>
							</div>
						);
					} ) }
				</section>
			) : null }
		</div>
	);
}

/**
 * Numbers passengers within their own type, so a form reads "Adult 1, Adult 2".
 */
function passengerIndex( passengers: PartyMember[], index: number ): number {
	const type = passengers[ index ]?.type_id;
	let count = 0;

	for ( let i = 0; i <= index; i++ ) {
		if ( passengers[ i ]?.type_id === type ) {
			count++;
		}
	}

	return count;
}

/**
 * Makes date of birth mandatory for a type that verifies an age band.
 *
 * The operator's global setting decides whether the field is collected; a
 * passenger type that exists to give a discount for being under twelve decides
 * whether it can be left blank.
 */
function withRequiredDob( fields: CaptureField[] ): CaptureField[] {
	const present = fields.some( ( field ) => field.key === 'date_of_birth' );

	if ( ! present ) {
		return [
			...fields,
			{ key: 'date_of_birth', label: t( 'Date of birth' ), type: 'date', required: true },
		];
	}

	return fields.map( ( field ) => ( field.key === 'date_of_birth' ? { ...field, required: true } : field ) );
}

/**
 * Applies a vehicle type's own requirements on top of the global settings.
 */
function adjustVehicleFields( fields: CaptureField[], type: VehicleTypeOption | undefined ): CaptureField[] {
	if ( ! type ) {
		return fields;
	}

	let adjusted = fields.map( ( field ) => {
		if ( field.key === 'registration' && type.requires_registration ) {
			return { ...field, required: true };
		}

		if ( field.key === 'driver_name' && type.requires_driver ) {
			return { ...field, required: true };
		}

		if ( ( field.key === 'length' || field.key === 'height' ) && type.requires_dimensions ) {
			return { ...field, required: true };
		}

		return field;
	} );

	if ( ! type.allows_trailer ) {
		adjusted = adjusted.filter( ( field ) => field.key !== 'trailer' && field.key !== 'trailer_length' );
	}

	if ( type.requires_registration && ! adjusted.some( ( field ) => field.key === 'registration' ) ) {
		adjusted = [ { key: 'registration', label: t( 'Registration number' ), type: 'text', required: true }, ...adjusted ];
	}

	return adjusted;
}

export interface CustomerFormProps {
	customer: CustomerDetails;
	errors: Record< string, string >;
	onChange: ( key: keyof CustomerDetails, value: string | boolean ) => void;
}

/**
 * Renders the lead-booker form.
 */
export function CustomerForm( { customer, errors, onChange }: CustomerFormProps ): JSX.Element {
	const cfg = config();

	return (
		<section>
			<h3 className="fbmb-section__title">{ t( 'Your details' ) }</h3>
			<p className="fbmb-section__note">{ t( 'We send your tickets and any schedule changes to this address.' ) }</p>

			<div className="fbmb-details__grid">
				<Field label={ t( 'Full name' ) } htmlFor="fbm-customer-name" required error={ errors.customer_name }>
					<Input
						id="fbm-customer-name"
						value={ customer.name }
						autoComplete="name"
						onChange={ ( value ) => onChange( 'name', value ) }
					/>
				</Field>

				<Field label={ t( 'Email' ) } htmlFor="fbm-customer-email" required error={ errors.customer_email }>
					<Input
						id="fbm-customer-email"
						type="email"
						value={ customer.email }
						autoComplete="email"
						onChange={ ( value ) => onChange( 'email', value ) }
					/>
				</Field>

				<Field
					label={ t( 'Phone' ) }
					htmlFor="fbm-customer-phone"
					required={ cfg.requirePhone }
					error={ errors.customer_phone }
					hint={ t( 'Used only if we need to reach you about this crossing.' ) }
				>
					<Input
						id="fbm-customer-phone"
						type="tel"
						value={ customer.phone }
						autoComplete="tel"
						onChange={ ( value ) => onChange( 'phone', value ) }
					/>
				</Field>
			</div>

			{ cfg.requireTerms ? (
				<div className={ `fbmb-consent${ errors.accepted_terms ? ' is-invalid' : '' }` }>
					<label className="fbmb-consent__label" htmlFor="fbm-customer-terms">
						<input
							id="fbm-customer-terms"
							type="checkbox"
							className="fbmb-consent__box"
							checked={ customer.acceptedTerms }
							aria-describedby={ errors.accepted_terms ? 'fbm-customer-terms-error' : undefined }
							onChange={ ( event ) =>
								onChange( 'acceptedTerms', ( event.target as HTMLInputElement ).checked )
							}
						/>
						<span>
							{ `${ t( 'I agree to the' ) } ` }
							<a href={ cfg.termsUrl } target="_blank" rel="noreferrer" className="fbmb-link">
								{ t( 'terms and conditions' ) }
							</a>
							{ cfg.cancellationPolicyUrl !== '' ? (
								<>
									{ ` ${ t( 'and the' ) } ` }
									<a href={ cfg.cancellationPolicyUrl } target="_blank" rel="noreferrer" className="fbmb-link">
										{ t( 'cancellation policy' ) }
									</a>
								</>
							) : null }
						</span>
					</label>
					{ errors.accepted_terms ? (
						<p className="fbmb-field__error" id="fbm-customer-terms-error" role="alert">
							{ errors.accepted_terms }
						</p>
					) : null }
				</div>
			) : null }
		</section>
	);
}
