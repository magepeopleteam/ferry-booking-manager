/**
 * Date and time formatting.
 *
 * The server sends sailing times as site-local wall-clock strings, never as
 * instants. Passing those to `new Date( value )` would let the viewer's own
 * timezone shift them — an 08:00 departure would read as 07:00 for a manager
 * working from another country — so they are parsed field by field and
 * formatted as UTC, which renders exactly the digits the operator typed.
 */

import { fbmConfig } from './config';

interface DateParts {
	year: number;
	month: number;
	day: number;
	hour: number;
	minute: number;
}

/**
 * Parses "YYYY-MM-DD HH:MM:SS" without applying any timezone.
 */
export function fbmParseLocal( value: string ): DateParts | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( value ?? '' );

	if ( ! match ) {
		return null;
	}

	return {
		year: Number( match[ 1 ] ),
		month: Number( match[ 2 ] ),
		day: Number( match[ 3 ] ),
		hour: Number( match[ 4 ] ?? '0' ),
		minute: Number( match[ 5 ] ?? '0' ),
	};
}

/**
 * Builds a UTC Date carrying the same digits, safe to format.
 */
function toUtcDate( parts: DateParts ): Date {
	return new Date( Date.UTC( parts.year, parts.month - 1, parts.day, parts.hour, parts.minute ) );
}

/**
 * Formats a stored local date/time for display.
 */
export function fbmFormatDateTime( value: string, options: Intl.DateTimeFormatOptions = {} ): string {
	const parts = fbmParseLocal( value );

	if ( ! parts ) {
		return '';
	}

	return new Intl.DateTimeFormat( fbmConfig().locale, {
		dateStyle: 'medium',
		timeStyle: 'short',
		timeZone: 'UTC',
		...options,
	} ).format( toUtcDate( parts ) );
}

/**
 * Formats only the date portion.
 */
export function fbmFormatDate( value: string ): string {
	return fbmFormatDateTime( value, { dateStyle: 'medium', timeStyle: undefined } );
}

/**
 * Formats only the time portion.
 */
export function fbmFormatTime( value: string ): string {
	return fbmFormatDateTime( value, { dateStyle: undefined, timeStyle: 'short' } );
}

/**
 * Converts a stored value to the format a datetime-local input expects.
 */
export function fbmToInputValue( value: string ): string {
	const parts = fbmParseLocal( value );

	if ( ! parts ) {
		return '';
	}

	const pad = ( n: number ): string => String( n ).padStart( 2, '0' );

	return `${ parts.year }-${ pad( parts.month ) }-${ pad( parts.day ) }T${ pad( parts.hour ) }:${ pad( parts.minute ) }`;
}

/**
 * Converts a datetime-local input value back to the stored format.
 */
export function fbmFromInputValue( value: string ): string {
	if ( ! value ) {
		return '';
	}

	const normalised = value.replace( 'T', ' ' );

	return /\d{2}:\d{2}:\d{2}$/.test( normalised ) ? normalised : `${ normalised }:00`;
}

/**
 * Returns today's date in the site timezone as "YYYY-MM-DD".
 */
export function fbmToday(): string {
	return new Intl.DateTimeFormat( 'en-CA', {
		timeZone: fbmConfig().timezone || 'UTC',
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} ).format( new Date() );
}

/**
 * Adds days to a "YYYY-MM-DD" string.
 */
export function fbmAddDays( date: string, days: number ): string {
	const parts = fbmParseLocal( date );

	if ( ! parts ) {
		return date;
	}

	const shifted = toUtcDate( parts );
	shifted.setUTCDate( shifted.getUTCDate() + days );

	return shifted.toISOString().slice( 0, 10 );
}
