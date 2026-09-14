/**
 * Runtime configuration handed to the dashboard by WordPress.
 *
 * The object is printed by `FBM\Core\Assets::admin_config()` before any
 * application chunk executes. Everything here is read-only.
 */

export interface FbmCurrency {
	code: string;
	symbol: string;
	position: 'left' | 'right' | 'left_space' | 'right_space';
	decimals: number;
	decimalSeparator: string;
	thousandSeparator: string;
}

export interface FbmUser {
	id: number;
	name: string;
	email: string;
	avatar: string;
}

export interface FbmAdminConfig {
	version: string;
	restUrl: string;
	restNonce: string;
	adminUrl: string;
	pageUrl: string;
	assetUrl: string;
	homeUrl: string;
	capabilities: Record< string, boolean >;
	user: FbmUser;
	locale: string;
	isRtl: boolean;
	timezone: string;
	dateFormat: string;
	timeFormat: string;
	startOfWeek: number;
	currency: FbmCurrency;
	woocommerce: boolean;
	proActive: boolean;
	i18n: Record< string, string >;
}

declare global {
	interface Window {
		fbmAdmin?: Partial< FbmAdminConfig >;
		fbmAdminBootFailure?: ( message: string ) => void;
	}
}

const FALLBACK: FbmAdminConfig = {
	version: '0.0.0',
	restUrl: '/wp-json/fbm/v1/',
	restNonce: '',
	adminUrl: '/wp-admin/',
	pageUrl: '/wp-admin/admin.php?page=fbm-dashboard',
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
	proActive: false,
	i18n: {},
};

let cached: FbmAdminConfig | null = null;

/**
 * Returns the merged runtime configuration.
 *
 * Safe to call during static export, where `window` does not exist.
 */
export function fbmConfig(): FbmAdminConfig {
	if ( cached ) {
		return cached;
	}

	if ( typeof window === 'undefined' || ! window.fbmAdmin ) {
		return FALLBACK;
	}

	cached = { ...FALLBACK, ...window.fbmAdmin } as FbmAdminConfig;

	return cached;
}

/**
 * Determines whether the signed-in user holds a capability.
 */
export function fbmCan( capability: string ): boolean {
	return fbmConfig().capabilities[ capability ] === true;
}
