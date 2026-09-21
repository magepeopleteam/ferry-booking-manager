/**
 * Runtime configuration handed to the dashboard by WordPress.
 *
 * The object is printed by `MPFBS\Core\Assets::admin_config()` before any
 * application chunk executes. Everything here is read-only.
 */

export interface MpfbsCurrency {
	code: string;
	symbol: string;
	position: 'left' | 'right' | 'left_space' | 'right_space';
	decimals: number;
	decimalSeparator: string;
	thousandSeparator: string;
}

export interface MpfbsUser {
	id: number;
	name: string;
	email: string;
	avatar: string;
}

export interface MpfbsAdminConfig {
	version: string;
	restUrl: string;
	restNonce: string;
	adminUrl: string;
	pageUrl: string;
	assetUrl: string;
	homeUrl: string;
	capabilities: Record< string, boolean >;
	user: MpfbsUser;
	locale: string;
	isRtl: boolean;
	timezone: string;
	dateFormat: string;
	timeFormat: string;
	startOfWeek: number;
	currency: MpfbsCurrency;
	woocommerce: boolean;
	i18n: Record< string, string >;
}

declare global {
	interface Window {
		mpfbsAdmin?: Partial< MpfbsAdminConfig >;
		mpfbsAdminBootFailure?: ( message: string ) => void;
	}
}

const FALLBACK: MpfbsAdminConfig = {
	version: '0.0.0',
	restUrl: '/wp-json/mpfbs/v1/',
	restNonce: '',
	adminUrl: '/wp-admin/',
	pageUrl: '/wp-admin/admin.php?page=mpfbs-dashboard',
	assetUrl: '',
	homeUrl: '/',
	capabilities: {},
	user: { id: 0, name: '', email: '', avatar: '' },
	locale: 'en-US',
	isRtl: false,
	timezone: 'UTC',
	dateFormat: 'Y-m-d',
	timeFormat: 'H:i',
	startOfWeek: 1,
	currency: {
		code: 'EUR',
		symbol: '€',
		position: 'left',
		decimals: 2,
		decimalSeparator: '.',
		thousandSeparator: ',',
	},
	woocommerce: false,
	i18n: {},
};

let cached: MpfbsAdminConfig | null = null;

/**
 * Returns the merged runtime configuration.
 *
 * Safe to call during static export, where `window` does not exist.
 */
export function mpfbsConfig(): MpfbsAdminConfig {
	if ( cached ) {
		return cached;
	}

	if ( typeof window === 'undefined' || ! window.mpfbsAdmin ) {
		return FALLBACK;
	}

	cached = { ...FALLBACK, ...window.mpfbsAdmin } as MpfbsAdminConfig;

	return cached;
}

/**
 * Determines whether the signed-in user holds a capability.
 */
export function mpfbsCan( capability: string ): boolean {
	return mpfbsConfig().capabilities[ capability ] === true;
}
