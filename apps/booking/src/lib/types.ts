/**
 * Shapes returned by the public booking API.
 */

export interface PortOption {
	id: number;
	name: string;
	code: string;
	city: string;
	country: string;
}

export interface PassengerTypeOption {
	id: number;
	name: string;
	code: string;
	description: string;
	min_age: number;
	max_age: number;
	requires_dob: boolean;
	requires_adult: boolean;
	occupies_seat: boolean;
	min_per_booking: number;
	max_per_booking: number;
	is_free: boolean;
}

export interface VehicleTypeOption {
	id: number;
	name: string;
	code: string;
	category: string;
	description: string;
	length: number;
	height: number;
	lane_metres: number;
	requires_registration: boolean;
	requires_driver: boolean;
	requires_dimensions: boolean;
	allows_trailer: boolean;
	max_per_booking: number;
}

/**
 * Guide fares between two ports, in minor units, keyed by type id.
 *
 * `routed` says whether a crossing was actually found: without one every fare
 * is zero, which means "not known here", never "free".
 */
export interface Fares {
	currency: string;
	routed: boolean;
	vehicles_allowed: boolean;
	passengers: Record< string, number >;
	vehicles: Record< string, number >;
}

export interface CaptureField {
	key: string;
	label: string;
	type: string;
	required: boolean;
}

export interface BookingOptions {
	ports: PortOption[];
	connections: Record< string, number[] >;
	passenger_types: PassengerTypeOption[];
	vehicle_types: VehicleTypeOption[];
	fields: { passenger: CaptureField[]; vehicle: CaptureField[] };
	today: string;
	currency: {
		code: string;
		symbol: string;
		position: 'left' | 'right' | 'left_space' | 'right_space';
		decimals: number;
		decimalSeparator: string;
		thousandSeparator: string;
	};
}

export interface Bucket {
	capacity: number;
	sold: number;
	held: number;
	used: number;
	remaining: number;
	unlimited: boolean;
}

export interface QuoteLine {
	type: string;
	label: string;
	quantity: number;
	unit: number;
	amount: number;
}

export interface SailingResult {
	sailing_id: number;
	route_id: number;
	route_name: string;
	departure: string;
	arrival: string;
	departure_ts: number;
	duration: number;
	vessel: { id: number; name: string; code: string };
	takes_vehicles: boolean;
	availability: {
		passengers: Bucket;
		vehicles: Bucket;
		lane_metres: Bucket;
		sold_out: boolean;
	};
	price?: {
		total: number;
		subtotal: number;
		tax: number;
		fees: number;
		currency: string;
		lines: QuoteLine[];
	} | null;
	usage?: Record< string, number >;
	fits?: boolean;
	unavailable?: string;
}

export interface SearchResponse {
	query: {
		origin: number;
		destination: number;
		date: string;
		return_date: string;
		flexible_days: number;
		passengers: Record< string, number >;
		vehicles: Record< string, number >;
	};
	outbound: SailingResult[];
	inbound: SailingResult[];
	ports: Record< string, string >;
}

export interface Quote {
	sailing_id: number;
	return_sailing_id: number;
	currency: string;
	lines: QuoteLine[];
	usage: Record< string, number >;
	gross: number;
	discount: number;
	subtotal: number;
	fees: number;
	tax: number;
	total: number;
	availability?: Record< string, { fits: boolean; message: string } >;
}

export interface CustomerBookingLeg {
	label: string;
	origin: string;
	destination: string;
	route: string;
	vessel: string;
	departure: string;
	arrival: string;
	status: string;
}

/**
 * A booking as its owner sees it.
 *
 * `tickets` is filled in by the Pro plugin through the fbm_customer_booking
 * filter; Free never sends any, so the card simply renders no actions.
 */
export interface CustomerBooking {
	reference: string;
	status: string;
	status_label: string;
	payment_status: string;
	payment_label: string;
	booking_type: string;
	passenger_count: number;
	vehicle_count: number;
	total: number;
	paid: number;
	balance: number;
	currency: string;
	customer_name: string;
	payment_method: string;
	booked_on: string;
	departure_ts: number;
	upcoming: boolean;
	cancellable: boolean;
	legs: CustomerBookingLeg[];
	tickets?: Array< { label: string; url: string } >;
}

export interface CustomerBookings {
	upcoming: CustomerBooking[];
	past: CustomerBooking[];
}
