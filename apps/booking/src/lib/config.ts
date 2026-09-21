/**
 * Runtime configuration printed by PHP before the bundle runs.
 */

export interface MpfbsCurrency {
	code: string;
	symbol: string;
	position: 'left' | 'right' | 'left_space' | 'right_space';
	decimals: number;
	decimalSeparator: string;
	thousandSeparator: string;
}

export interface MpfbsBookingConfig {
	restUrl: string;
	restNonce: string;
	homeUrl: string;
	locale: string;
	isRtl: boolean;
	dateFormat: string;
	timeFormat: string;
	startOfWeek: number;
	loggedIn: boolean;
	holdMinutes: number;
	i18n: Record< string, string >;

	/* The operator's public choices, from the Settings screen. */
	companyName: string;
	requirePhone: boolean;
	checkoutEngine: string;
	termsUrl: string;
	cancellationPolicyUrl: string;
	primaryColor: string;
	requireTerms: boolean;
	vehiclesEnabled: boolean;
	showRemainingSeats: boolean;
	lowAvailabilityAt: number;
	lowAvailabilityLeft: number;
	maxPassengers: number;
	maxVehicles: number;
	analyticsEvents: boolean;
	measurementId: string;
}

declare global {
	interface Window {
		mpfbsBooking?: Partial< MpfbsBookingConfig >;
	}
}

const FALLBACK: MpfbsBookingConfig = {
	restUrl: '/wp-json/mpfbs/v1/',
	restNonce: '',
	homeUrl: '/',
	locale: 'en-US',
	isRtl: false,
	dateFormat: 'Y-m-d',
	timeFormat: 'H:i',
	startOfWeek: 1,
	loggedIn: false,
	holdMinutes: 15,
	i18n: {},
	companyName: '',
	requirePhone: false,
	checkoutEngine: 'native',
	termsUrl: '',
	cancellationPolicyUrl: '',
	primaryColor: '#0b62c4',
	requireTerms: false,
	// Vehicles default to on: a foot-passenger-only service is the exception,
	// and hiding the option because the config failed to load would quietly
	// cost the operator every vehicle booking.
	vehiclesEnabled: true,
	showRemainingSeats: true,
	lowAvailabilityAt: 80,
	lowAvailabilityLeft: 10,
	maxPassengers: 9,
	maxVehicles: 4,
	analyticsEvents: false,
	measurementId: '',
};

let cached: MpfbsBookingConfig | null = null;

/**
 * Returns the merged runtime configuration.
 */
export function config(): MpfbsBookingConfig {
	if ( ! cached ) {
		cached = { ...FALLBACK, ...( window.mpfbsBooking ?? {} ) } as MpfbsBookingConfig;
	}

	return cached;
}

let currency: MpfbsCurrency = {
	code: 'EUR',
	symbol: '€',
	position: 'left',
	decimals: 2,
	decimalSeparator: '.',
	thousandSeparator: ',',
};

/**
 * Stores the currency settings the options endpoint reported.
 */
export function setCurrency( next: MpfbsCurrency ): void {
	currency = { ...currency, ...next };
}

/**
 * Returns the currency settings.
 */
export function getCurrency(): MpfbsCurrency {
	return currency;
}
