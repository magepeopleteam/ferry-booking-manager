/**
 * Resource definitions for the dashboard.
 *
 * The PHP side declares each entity once and derives storage, validation and
 * the REST contract from it. This is the interface half of the same idea: one
 * declaration per resource produces its table, its filters and its form, so a
 * new field is added in two places rather than twelve.
 */

import type { JSX } from 'react';

import type { Column } from '../components/DataTable';
import type { FilterOption } from '../components/FilterBar';
import type { SelectOption } from '../components/Fields';
import type { IconName } from '../components/Icon';
import { fbmFormat, fbmText } from '../lib/i18n';
import { fbmFormatMoney } from '../lib/money';
import type { FbmReferences } from '../lib/references';

export interface ResourceRecord {
	id: number;
	name: string;
	[ key: string ]: unknown;
}

export interface ResourceField {
	name: string;
	label: string;
	type: 'text' | 'email' | 'tel' | 'url' | 'number' | 'textarea' | 'select' | 'switch' | 'tags' | 'multiselect' | 'date' | 'time' | 'datetime-local';
	required?: boolean;
	hint?: string;
	placeholder?: string;
	min?: number;
	max?: number;
	step?: number;
	rows?: number;
	suffix?: string;
	numeric?: boolean;
	/** Value is an amount in minor units; the form shows and accepts major units. */
	money?: boolean;
	/** Hides the field unless the rest of the form makes it meaningful. */
	visibleWhen?: ( values: Record< string, unknown > ) => boolean;
	options?: ( references: FbmReferences ) => SelectOption[];
}

/**
 * One step of a multi-step resource form.
 *
 * Declared as a list of field names rather than a nested field list, so the
 * fields themselves stay in one flat, ordered declaration and a step is only a
 * view of them. A field left out of every step still renders — at the end, on
 * its own — which means adding a field can never make it silently unreachable.
 */
export interface ResourceStep {
	id: string;
	title: string;
	description?: string;
	/** Field names shown on this step, in order. */
	fields: string[];
	/** Renders the name field at the top of this step. */
	includesName?: boolean;
	/** A panel this step owns that is not a plain field. */
	custom?: 'route-fares';
}

export interface ResourceConfig {
	endpoint: string;
	label: string;
	singularLabel: string;
	description: string;
	searchPlaceholder: string;
	emptyHint: string;
	icon: IconName;
	showNameField: boolean;
	nameLabel: string;
	drawerWidth?: 'default' | 'wide' | 'xwide';
	/**
	 * Splits the form into steps. Omit for a single-page form.
	 *
	 * Worth it once a form has more fields than anybody can hold in their head
	 * at once — a vehicle type asks twenty questions about four unrelated
	 * things — and actively worse on a form with four fields.
	 */
	steps?: ResourceStep[];
	invalidatesReferences?: boolean;
	initialQuery?: Record< string, string | number >;
	statusOptions: FilterOption[];
	defaults: Record< string, unknown >;
	fields: ResourceField[];
	columns: ( references: FbmReferences ) => Array< Column< ResourceRecord > >;
}

/**
 * Renders a status pill.
 */
function statusBadge( value: unknown ): JSX.Element {
	const status = String( value ?? '' );
	const tone =
		status === 'active' || status === 'scheduled'
			? 'positive'
			: status === 'maintenance' || status === 'delayed'
				? 'warning'
				: status === 'cancelled'
					? 'danger'
					: 'muted';

	return <span className={ `fbm-pill fbm-pill--${ tone }` }>{ fbmText( statusLabel( status ) ) }</span>;
}

/**
 * Maps a status slug to its display label.
 */
function statusLabel( status: string ): string {
	const labels: Record< string, string > = {
		active: 'Active',
		inactive: 'Inactive',
		maintenance: 'Maintenance',
		scheduled: 'Scheduled',
		delayed: 'Delayed',
		departed: 'Departed',
		arrived: 'Arrived',
		cancelled: 'Cancelled',
	};

	return labels[ status ] ?? status;
}

/**
 * Renders a monospaced code cell.
 */
function codeCell( value: unknown ): JSX.Element {
	const code = String( value ?? '' );

	return code === '' ? <span className="fbm-muted">—</span> : <code className="fbm-code">{ code }</code>;
}

/**
 * Builds select options from a reference list.
 */
function referenceOptions( items: Array< { id: number; name: string; code?: string } > ): SelectOption[] {
	return items.map( ( item ) => ( {
		value: item.id,
		label: item.code ? `${ item.name } (${ item.code })` : item.name,
	} ) );
}

const ACTIVE_STATUS: FilterOption[] = [
	{ value: 'active', label: 'Active' },
	{ value: 'inactive', label: 'Inactive' },
];

export const FBM_PORT_RESOURCE: ResourceConfig = {
	endpoint: 'ports',
	label: 'Ports',
	singularLabel: 'Port',
	description: 'Terminals your sailings depart from and arrive into.',
	searchPlaceholder: 'Search ports by name or code',
	emptyHint: 'Add the terminals you sail between before creating routes.',
	icon: 'anchor',
	showNameField: true,
	nameLabel: 'Port name',
	invalidatesReferences: true,
	statusOptions: ACTIVE_STATUS,
	defaults: {
		code: '',
		city: '',
		country: '',
		address: '',
		latitude: 0,
		longitude: 0,
		checkin_instructions: '',
		boarding_instructions: '',
		checkin_minutes: 30,
		contact_phone: '',
		contact_email: '',
		timezone: '',
		status: 'active',
	},
	fields: [
		{ name: 'code', label: 'Port code', type: 'text', required: true, hint: 'Short identifier shown on tickets and manifests, for example NAP.' },
		{ name: 'city', label: 'City', type: 'text' },
		{ name: 'country', label: 'Country code', type: 'text', placeholder: 'IT' },
		{ name: 'address', label: 'Address', type: 'textarea', rows: 2 },
		{ name: 'latitude', label: 'Latitude', type: 'number', step: 0.000001, min: -90, max: 90 },
		{ name: 'longitude', label: 'Longitude', type: 'number', step: 0.000001, min: -180, max: 180 },
		{ name: 'checkin_minutes', label: 'Check-in closes', type: 'number', min: 0, max: 1440, suffix: 'min before departure' },
		{ name: 'contact_phone', label: 'Contact phone', type: 'tel' },
		{ name: 'contact_email', label: 'Contact email', type: 'email' },
		{ name: 'checkin_instructions', label: 'Check-in instructions', type: 'textarea', rows: 3, hint: 'Shown to passengers on their ticket.' },
		{ name: 'boarding_instructions', label: 'Boarding instructions', type: 'textarea', rows: 3 },
		{
			name: 'status',
			label: 'Status',
			type: 'select',
			options: () => [
				{ value: 'active', label: fbmText( 'Active' ) },
				{ value: 'inactive', label: fbmText( 'Inactive' ) },
			],
		},
	],
	columns: () => [
		{
			key: 'name',
			label: fbmText( 'Port' ),
			sortBy: 'title',
			render: ( item ) => (
				<div className="fbm-cell-primary">
					<span className="fbm-cell-primary__title">{ item.name }</span>
					{ item.city ? <span className="fbm-cell-primary__meta">{ String( item.city ) }</span> : null }
				</div>
			),
		},
		{ key: 'code', label: fbmText( 'Code' ), sortBy: 'code', width: '120px', render: ( item ) => codeCell( item.code ) },
		{
			key: 'country',
			label: fbmText( 'Country' ),
			width: '110px',
			render: ( item ) => <span>{ String( item.country ?? '' ).toUpperCase() || '—' }</span>,
		},
		{
			key: 'checkin',
			label: fbmText( 'Check-in' ),
			width: '130px',
			align: 'end',
			render: ( item ) => <span>{ `${ Number( item.checkin_minutes ?? 0 ) } ${ fbmText( 'min' ) }` }</span>,
		},
		{ key: 'status', label: fbmText( 'Status' ), width: '120px', render: ( item ) => statusBadge( item.status ) },
	],
};

export const FBM_VESSEL_RESOURCE: ResourceConfig = {
	endpoint: 'vessels',
	label: 'Vessels',
	singularLabel: 'Vessel',
	description: 'Your fleet, and the capacity each ship brings to a sailing.',
	searchPlaceholder: 'Search vessels by name, code or registration',
	emptyHint: 'Add a vessel so sailings have capacity to sell.',
	icon: 'ship',
	showNameField: true,
	nameLabel: 'Vessel name',
	invalidatesReferences: true,
	statusOptions: [
		{ value: 'active', label: 'Active' },
		{ value: 'maintenance', label: 'Maintenance' },
		{ value: 'inactive', label: 'Inactive' },
	],
	defaults: {
		code: '',
		registration: '',
		passenger_capacity: 0,
		vehicle_capacity: 0,
		deck_capacity: 0,
		crew_capacity: 0,
		speed_knots: 0,
		facilities: [],
		description: '',
		status: 'active',
	},
	fields: [
		{ name: 'code', label: 'Vessel code', type: 'text', required: true },
		{ name: 'registration', label: 'Registration number', type: 'text' },
		{ name: 'passenger_capacity', label: 'Passenger capacity', type: 'number', required: true, min: 0, suffix: 'seats' },
		{ name: 'vehicle_capacity', label: 'Vehicle capacity', type: 'number', min: 0, suffix: 'vehicles' },
		{
			name: 'deck_capacity',
			label: 'Vehicle deck capacity',
			type: 'number',
			min: 0,
			step: 0.1,
			suffix: 'lane metres',
			hint: 'Used for lane-metre availability when vehicles have different lengths.',
		},
		{ name: 'crew_capacity', label: 'Crew capacity', type: 'number', min: 0 },
		{ name: 'speed_knots', label: 'Service speed', type: 'number', min: 0, step: 0.1, suffix: 'knots' },
		{ name: 'facilities', label: 'Facilities', type: 'tags', placeholder: 'Bar, Wi-Fi, Sun deck…' },
		{ name: 'description', label: 'Description', type: 'textarea', rows: 3 },
		{
			name: 'status',
			label: 'Status',
			type: 'select',
			options: () => [
				{ value: 'active', label: fbmText( 'Active' ) },
				{ value: 'maintenance', label: fbmText( 'Maintenance' ) },
				{ value: 'inactive', label: fbmText( 'Inactive' ) },
			],
		},
	],
	columns: () => [
		{
			key: 'name',
			label: fbmText( 'Vessel' ),
			sortBy: 'title',
			render: ( item ) => (
				<div className="fbm-cell-primary">
					<span className="fbm-cell-primary__title">{ item.name }</span>
					{ item.registration ? <span className="fbm-cell-primary__meta">{ String( item.registration ) }</span> : null }
				</div>
			),
		},
		{ key: 'code', label: fbmText( 'Code' ), sortBy: 'code', width: '110px', render: ( item ) => codeCell( item.code ) },
		{
			key: 'passenger_capacity',
			label: fbmText( 'Passengers' ),
			sortBy: 'passenger_capacity',
			width: '130px',
			align: 'end',
			render: ( item ) => <span>{ Number( item.passenger_capacity ?? 0 ).toLocaleString() }</span>,
		},
		{
			key: 'vehicle_capacity',
			label: fbmText( 'Vehicles' ),
			sortBy: 'vehicle_capacity',
			width: '110px',
			align: 'end',
			render: ( item ) => <span>{ Number( item.vehicle_capacity ?? 0 ).toLocaleString() }</span>,
		},
		{
			key: 'deck_capacity',
			label: fbmText( 'Lane metres' ),
			width: '120px',
			align: 'end',
			render: ( item ) =>
				Number( item.deck_capacity ?? 0 ) > 0 ? <span>{ Number( item.deck_capacity ).toLocaleString() }</span> : <span className="fbm-muted">—</span>,
		},
		{ key: 'status', label: fbmText( 'Status' ), width: '130px', render: ( item ) => statusBadge( item.status ) },
	],
};

export const FBM_ROUTE_RESOURCE: ResourceConfig = {
	endpoint: 'routes',
	label: 'Routes',
	singularLabel: 'Route',
	description: 'The journeys you operate between ports.',
	searchPlaceholder: 'Search routes by name or code',
	emptyHint: 'Create a route once you have at least two ports.',
	icon: 'compass',
	showNameField: false,
	nameLabel: 'Route name',
	drawerWidth: 'wide',
	invalidatesReferences: true,
	statusOptions: ACTIVE_STATUS,
	defaults: {
		code: '',
		origin_port: 0,
		destination_port: 0,
		intermediate_ports: [],
		duration: 60,
		distance: 0,
		default_vessel: 0,
		allows_vehicles: true,
		description: '',
		status: 'active',
	},
	fields: [
		{ name: 'code', label: 'Route code', type: 'text', hint: 'Optional. Leave blank and the route is named after its ports.' },
		{
			name: 'origin_port',
			label: 'Origin port',
			type: 'select',
			required: true,
			numeric: true,
			placeholder: 'Select a port',
			options: ( references ) => referenceOptions( references.ports ),
		},
		{
			name: 'destination_port',
			label: 'Destination port',
			type: 'select',
			required: true,
			numeric: true,
			placeholder: 'Select a port',
			options: ( references ) => referenceOptions( references.ports ),
		},
		{
			name: 'intermediate_ports',
			label: 'Intermediate calls',
			type: 'multiselect',
			hint: 'Ports called at along the way, in order.',
			options: ( references ) => referenceOptions( references.ports ),
		},
		{ name: 'duration', label: 'Duration', type: 'number', required: true, min: 1, suffix: 'minutes' },
		{ name: 'distance', label: 'Distance', type: 'number', min: 0, step: 0.1, suffix: 'nautical miles' },
		{
			name: 'default_vessel',
			label: 'Default vessel',
			type: 'select',
			numeric: true,
			placeholder: 'No default',
			hint: 'Pre-selected when scheduling sailings on this route.',
			options: ( references ) => referenceOptions( references.vessels ),
		},
		{ name: 'allows_vehicles', label: 'Accepts vehicles', type: 'switch', hint: 'Turn off for passenger-only crossings.' },
		{ name: 'description', label: 'Description', type: 'textarea', rows: 3 },
		{
			name: 'status',
			label: 'Status',
			type: 'select',
			options: () => [
				{ value: 'active', label: fbmText( 'Active' ) },
				{ value: 'inactive', label: fbmText( 'Inactive' ) },
			],
		},
	],
	columns: () => [
		{
			key: 'name',
			label: fbmText( 'Route' ),
			sortBy: 'title',
			render: ( item ) => (
				<div className="fbm-cell-primary">
					<span className="fbm-cell-primary__title">{ item.name }</span>
					<span className="fbm-cell-primary__meta">
						{ String( item.origin_port_name ?? '' ) } → { String( item.destination_port_name ?? '' ) }
					</span>
				</div>
			),
		},
		{ key: 'code', label: fbmText( 'Code' ), sortBy: 'code', width: '130px', render: ( item ) => codeCell( item.code ) },
		{
			key: 'duration',
			label: fbmText( 'Duration' ),
			sortBy: 'duration',
			width: '120px',
			align: 'end',
			render: ( item ) => <span>{ formatDuration( Number( item.duration ?? 0 ) ) }</span>,
		},
		{
			key: 'vessel',
			label: fbmText( 'Default vessel' ),
			width: '180px',
			render: ( item ) =>
				item.default_vessel_name ? <span>{ String( item.default_vessel_name ) }</span> : <span className="fbm-muted">—</span>,
		},
		{
			key: 'vehicles',
			label: fbmText( 'Vehicles' ),
			width: '110px',
			render: ( item ) =>
				item.allows_vehicles ? (
					<span className="fbm-pill fbm-pill--muted">{ fbmText( 'Yes' ) }</span>
				) : (
					<span className="fbm-muted">{ fbmText( 'No' ) }</span>
				),
		},
		{ key: 'status', label: fbmText( 'Status' ), width: '120px', render: ( item ) => statusBadge( item.status ) },
	],
};


const PASSENGER_PRICE_MODES: SelectOption[] = [
	{ value: 'fixed', label: 'A fixed fare' },
	{ value: 'percent', label: 'A percentage of the base fare' },
	{ value: 'free', label: 'Free' },
];

const VEHICLE_CATEGORIES = [
	'bicycle',
	'motorcycle',
	'car',
	'suv',
	'van',
	'camper',
	'minibus',
	'bus',
	'truck',
	'trailer',
	'other',
] as const;

const VEHICLE_CATEGORY_LABELS: Record< string, string > = {
	bicycle: 'Bicycle',
	motorcycle: 'Motorcycle',
	car: 'Car',
	suv: 'SUV',
	van: 'Van',
	camper: 'Camper',
	minibus: 'Minibus',
	bus: 'Bus',
	truck: 'Truck',
	trailer: 'Trailer',
	other: 'Other',
};

/**
 * Renders an age band as a readable range.
 *
 * -1 means "no bound in this direction", which reads very differently at each
 * end: an open lower bound is "under 18", an open upper bound is "18+".
 */
function ageBand( min: unknown, max: unknown ): string {
	const from = Number( min ?? -1 );
	const to = Number( max ?? -1 );

	if ( from < 0 && to < 0 ) {
		return fbmText( 'Any age' );
	}

	if ( from < 0 ) {
		return fbmFormat( 'Under %s', String( to + 1 ) );
	}

	if ( to < 0 ) {
		return fbmFormat( '%s and over', String( from ) );
	}

	return fbmFormat( '%1$s to %2$s', String( from ), String( to ) );
}

/**
 * Renders the fare a passenger type charges.
 */
function passengerFare( item: ResourceRecord ): JSX.Element {
	const mode = String( item.price_mode ?? 'fixed' );

	if ( mode === 'free' ) {
		return <span className="fbm-pill fbm-pill--positive">{ fbmText( 'Free' ) }</span>;
	}

	if ( mode === 'percent' ) {
		return <span>{ fbmFormat( '%s%% of base', String( Number( item.price_percent ?? 0 ) ) ) }</span>;
	}

	return <span>{ fbmFormatMoney( Number( item.base_price ?? 0 ) ) }</span>;
}

export const FBM_PASSENGER_TYPE_RESOURCE: ResourceConfig = {
	endpoint: 'passenger-types',
	label: 'Passenger types',
	singularLabel: 'Passenger type',
	description: 'The categories of traveller you sell, what each one costs, and what each one occupies.',
	searchPlaceholder: 'Search passenger types by name or code',
	emptyHint: 'Add at least one passenger type so sailings have something to sell.',
	icon: 'users',
	showNameField: true,
	nameLabel: 'Name shown to customers',
	drawerWidth: 'wide',
	invalidatesReferences: true,
	initialQuery: { orderby: 'sort_order', order: 'asc' },
	statusOptions: ACTIVE_STATUS,
	defaults: {
		code: '',
		min_age: -1,
		max_age: -1,
		requires_dob: false,
		requires_adult: false,
		occupies_seat: true,
		is_base: false,
		price_mode: 'fixed',
		base_price: 0,
		price_percent: 100,
		min_per_booking: 0,
		max_per_booking: 9,
		sort_order: 0,
		description: '',
		status: 'active',
	},
	fields: [
		{ name: 'code', label: 'Code', type: 'text', required: true, hint: 'Appears on manifests and tickets, for example ADULT.' },
		{ name: 'min_age', label: 'Minimum age', type: 'number', min: -1, max: 130, hint: 'Use -1 for no lower limit.' },
		{ name: 'max_age', label: 'Maximum age', type: 'number', min: -1, max: 130, hint: 'Use -1 for no upper limit.' },
		{ name: 'requires_dob', label: 'Ask for a date of birth', type: 'switch', hint: 'Turn on when the age band has to be proven at check-in.' },
		{ name: 'requires_adult', label: 'Must travel with an adult', type: 'switch' },
		{ name: 'occupies_seat', label: 'Occupies a passenger seat', type: 'switch', hint: 'Turn off for lap infants, who travel without consuming capacity.' },
		{ name: 'is_base', label: 'This is the base fare', type: 'switch', hint: 'Percentage fares are worked out from this type. Only one type can hold it.' },
		{ name: 'price_mode', label: 'Fare', type: 'select', options: () => PASSENGER_PRICE_MODES.map( ( option ) => ( { ...option, label: fbmText( option.label ) } ) ) },
		{
			name: 'base_price',
			label: 'Fare amount',
			type: 'number',
			min: 0,
			money: true,
			visibleWhen: ( values ) => values.price_mode === 'fixed',
			hint: 'Routes and sailings can override this later.',
		},
		{
			name: 'price_percent',
			label: 'Percentage of the base fare',
			type: 'number',
			min: 0,
			max: 100,
			step: 0.5,
			suffix: '%',
			visibleWhen: ( values ) => values.price_mode === 'percent',
		},
		{ name: 'min_per_booking', label: 'Minimum per booking', type: 'number', min: 0, max: 99 },
		{ name: 'max_per_booking', label: 'Maximum per booking', type: 'number', min: 0, max: 99, hint: 'Use 0 to allow any number.' },
		{ name: 'sort_order', label: 'Display order', type: 'number', min: 0, max: 999, hint: 'Lower numbers appear first on the booking form.' },
		{ name: 'description', label: 'Description', type: 'textarea', rows: 2 },
		{
			name: 'status',
			label: 'Status',
			type: 'select',
			options: () => [
				{ value: 'active', label: fbmText( 'Active' ) },
				{ value: 'inactive', label: fbmText( 'Inactive' ) },
			],
		},
	],
	columns: () => [
		{
			key: 'name',
			label: fbmText( 'Passenger type' ),
			sortBy: 'title',
			render: ( item ) => (
				<div className="fbm-cell-primary">
					<span className="fbm-cell-primary__title">
						{ item.name }
						{ item.is_base ? <span className="fbm-pill fbm-pill--muted">{ fbmText( 'Base' ) }</span> : null }
					</span>
					<span className="fbm-cell-primary__meta">{ ageBand( item.min_age, item.max_age ) }</span>
				</div>
			),
		},
		{ key: 'code', label: fbmText( 'Code' ), sortBy: 'code', width: '130px', render: ( item ) => codeCell( item.code ) },
		{ key: 'fare', label: fbmText( 'Fare' ), width: '160px', align: 'end', render: passengerFare },
		{
			key: 'occupies_seat',
			label: fbmText( 'Seat' ),
			width: '100px',
			render: ( item ) =>
				item.occupies_seat ? (
					<span className="fbm-pill fbm-pill--muted">{ fbmText( 'Yes' ) }</span>
				) : (
					<span className="fbm-muted">{ fbmText( 'No' ) }</span>
				),
		},
		{
			key: 'max_per_booking',
			label: fbmText( 'Max' ),
			width: '90px',
			align: 'end',
			render: ( item ) =>
				Number( item.max_per_booking ?? 0 ) > 0 ? <span>{ String( item.max_per_booking ) }</span> : <span className="fbm-muted">—</span>,
		},
		{
			key: 'sort_order',
			label: fbmText( 'Order' ),
			sortBy: 'sort_order',
			width: '90px',
			align: 'end',
			render: ( item ) => <span className="fbm-muted">{ String( item.sort_order ?? 0 ) }</span>,
		},
		{ key: 'status', label: fbmText( 'Status' ), width: '120px', render: ( item ) => statusBadge( item.status ) },
	],
};

export const FBM_VEHICLE_TYPE_RESOURCE: ResourceConfig = {
	endpoint: 'vehicle-types',
	label: 'Vehicle types',
	singularLabel: 'Vehicle type',
	description: 'What you carry on the vehicle deck, and how much space each one takes.',
	searchPlaceholder: 'Search vehicle types by name or code',
	emptyHint: 'Add a vehicle type to start selling deck space.',
	icon: 'car',
	showNameField: true,
	nameLabel: 'Name shown to customers',
	/*
	 * Four questions get asked about a vehicle type and they have nothing to do
	 * with each other: what it is called, how much deck it eats, what it costs
	 * to carry, and what the customer has to tell us about theirs. As one list
	 * of twenty fields an operator has to know the domain to fill it in; as
	 * four steps the form explains the domain as they go.
	 */
	steps: [
		{
			id: 'vehicle',
			title: 'The vehicle',
			description: 'What this class of vehicle is called, and where it sits in your list.',
			includesName: true,
			fields: [ 'code', 'category', 'description', 'sort_order', 'status' ],
		},
		{
			id: 'space',
			title: 'Deck space',
			description: 'What one of these takes off a vessel. Availability is measured against these, so a vessel with 40 lane metres sells out on the numbers here, not on a headcount.',
			fields: [ 'length', 'width', 'height', 'weight', 'lane_metres', 'capacity_units' ],
		},
		{
			id: 'fares',
			title: 'Fares',
			description: 'The fare this type charges by default, and what it charges on each route instead.',
			fields: [ 'base_price', 'price_per_metre', 'included_passengers', 'max_per_booking' ],
			custom: 'route-fares',
		},
		{
			id: 'booking',
			title: 'At booking',
			description: 'What a customer has to tell you when they bring one of these aboard.',
			fields: [ 'requires_registration', 'requires_driver', 'requires_dimensions', 'allows_trailer' ],
		},
	],
	drawerWidth: 'xwide',
	invalidatesReferences: true,
	initialQuery: { orderby: 'sort_order', order: 'asc' },
	statusOptions: ACTIVE_STATUS,
	defaults: {
		code: '',
		category: 'car',
		length: 0,
		width: 0,
		height: 0,
		weight: 0,
		lane_metres: 0,
		capacity_units: 1,
		included_passengers: 0,
		base_price: 0,
		price_per_metre: 0,
		requires_registration: true,
		requires_driver: true,
		requires_dimensions: false,
		allows_trailer: false,
		max_per_booking: 4,
		sort_order: 0,
		description: '',
		status: 'active',
	},
	fields: [
		{ name: 'code', label: 'Code', type: 'text', required: true, hint: 'Appears on manifests and boarding lists, for example CAR.' },
		{
			name: 'category',
			label: 'Category',
			type: 'select',
			options: () => VEHICLE_CATEGORIES.map( ( value ) => ( { value, label: fbmText( VEHICLE_CATEGORY_LABELS[ value ] ?? value ) } ) ),
		},
		{ name: 'length', label: 'Maximum length', type: 'number', min: 0, max: 100, step: 0.1, suffix: 'm' },
		{ name: 'width', label: 'Maximum width', type: 'number', min: 0, max: 20, step: 0.1, suffix: 'm' },
		{ name: 'height', label: 'Maximum height', type: 'number', min: 0, max: 20, step: 0.1, suffix: 'm' },
		{ name: 'weight', label: 'Maximum weight', type: 'number', min: 0, max: 100000, step: 10, suffix: 'kg' },
		{
			name: 'lane_metres',
			label: 'Lane metres consumed',
			type: 'number',
			min: 0,
			max: 100,
			step: 0.1,
			suffix: 'm',
			hint: 'Leave at 0 to use the maximum length. This is what deck availability is measured against.',
		},
		{
			name: 'capacity_units',
			label: 'Vehicle slots consumed',
			type: 'number',
			min: 0,
			max: 50,
			hint: 'How many of a vessel’s vehicle spaces one of these takes.',
		},
		{
			name: 'included_passengers',
			label: 'Passenger fares included',
			type: 'number',
			min: 0,
			max: 99,
			hint: 'Passengers up to this number travel free with the vehicle.',
		},
		{ name: 'base_price', label: 'Fare', type: 'number', min: 0, money: true },
		{
			name: 'price_per_metre',
			label: 'Additional fare per lane metre',
			type: 'number',
			min: 0,
			money: true,
			hint: 'Added on top of the fare, multiplied by the lane metres above.',
		},
		{ name: 'requires_registration', label: 'Require a registration number', type: 'switch' },
		{ name: 'requires_driver', label: 'Require driver details', type: 'switch' },
		{ name: 'requires_dimensions', label: 'Require exact dimensions', type: 'switch', hint: 'Ask the customer for the real length and height, not just the class maximum.' },
		{ name: 'allows_trailer', label: 'Allow a trailer', type: 'switch' },
		{ name: 'max_per_booking', label: 'Maximum per booking', type: 'number', min: 0, max: 99, hint: 'Use 0 to allow any number.' },
		{ name: 'sort_order', label: 'Display order', type: 'number', min: 0, max: 999 },
		{ name: 'description', label: 'Description', type: 'textarea', rows: 2 },
		{
			name: 'status',
			label: 'Status',
			type: 'select',
			options: () => [
				{ value: 'active', label: fbmText( 'Active' ) },
				{ value: 'inactive', label: fbmText( 'Inactive' ) },
			],
		},
	],
	columns: () => [
		{
			key: 'name',
			label: fbmText( 'Vehicle type' ),
			sortBy: 'title',
			render: ( item ) => (
				<div className="fbm-cell-primary">
					<span className="fbm-cell-primary__title">{ item.name }</span>
					<span className="fbm-cell-primary__meta">
						{ fbmText( VEHICLE_CATEGORY_LABELS[ String( item.category ?? '' ) ] ?? String( item.category ?? '' ) ) }
					</span>
				</div>
			),
		},
		{ key: 'code', label: fbmText( 'Code' ), sortBy: 'code', width: '140px', render: ( item ) => codeCell( item.code ) },
		{
			key: 'lane_metres',
			label: fbmText( 'Lane metres' ),
			sortBy: 'lane_metres',
			width: '130px',
			align: 'end',
			render: ( item ) => <span>{ fbmFormat( '%s m', String( Number( item.lane_metres ?? 0 ) ) ) }</span>,
		},
		{
			key: 'capacity_units',
			label: fbmText( 'Slots' ),
			width: '90px',
			align: 'end',
			render: ( item ) => <span>{ String( item.capacity_units ?? 0 ) }</span>,
		},
		{
			key: 'base_price',
			label: fbmText( 'Fare' ),
			sortBy: 'base_price',
			width: '140px',
			align: 'end',
			render: ( item ) => <span>{ fbmFormatMoney( Number( item.base_price ?? 0 ) ) }</span>,
		},
		{
			key: 'sort_order',
			label: fbmText( 'Order' ),
			sortBy: 'sort_order',
			width: '90px',
			align: 'end',
			render: ( item ) => <span className="fbm-muted">{ String( item.sort_order ?? 0 ) }</span>,
		},
		{ key: 'status', label: fbmText( 'Status' ), width: '120px', render: ( item ) => statusBadge( item.status ) },
	],
};

/**
 * Renders a minute count as hours and minutes.
 */
export function formatDuration( minutes: number ): string {
	if ( minutes <= 0 ) {
		return '—';
	}

	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;

	if ( hours === 0 ) {
		return `${ rest }${ fbmText( 'm' ) }`;
	}

	return rest === 0 ? `${ hours }${ fbmText( 'h' ) }` : `${ hours }${ fbmText( 'h' ) } ${ rest }${ fbmText( 'm' ) }`;
}
