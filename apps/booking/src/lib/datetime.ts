/**
 * Date and time presentation.
 *
 * Sailing times arrive as the operator authored them — a local wall clock, not
 * an instant — so they are formatted as written rather than converted. An 08:00
 * departure is 08:00 at the port, whatever timezone the browser is in.
 */

import { config } from './config';

/**
 * Splits a "Y-m-d H:i:s" value into its parts without touching a Date object.
 */
function parts( value: string ): { y: number; m: number; d: number; h: number; i: number } | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( value );

	if ( ! match ) {
		return null;
	}

	return {
		y: Number( match[ 1 ] ),
		m: Number( match[ 2 ] ),
		d: Number( match[ 3 ] ),
		h: Number( match[ 4 ] ?? 0 ),
		i: Number( match[ 5 ] ?? 0 ),
	};
}

/**
 * Formats the time portion of a stored datetime.
 */
export function time( value: string ): string {
	const p = parts( value );

	if ( ! p ) {
		return '';
	}

	if ( config().timeFormat.includes( 'g' ) || config().timeFormat.includes( 'h' ) ) {
		const suffix = p.h < 12 ? 'am' : 'pm';
		const hour = p.h % 12 === 0 ? 12 : p.h % 12;

		return `${ hour }:${ String( p.i ).padStart( 2, '0' ) } ${ suffix }`;
	}

	return `${ String( p.h ).padStart( 2, '0' ) }:${ String( p.i ).padStart( 2, '0' ) }`;
}

/**
 * Formats the date portion of a stored datetime in the visitor's locale.
 */
export function date( value: string, withWeekday = false ): string {
	const p = parts( value );

	if ( ! p ) {
		return '';
	}

	const local = new Date( p.y, p.m - 1, p.d );

	return local.toLocaleDateString( config().locale, {
		weekday: withWeekday ? 'short' : undefined,
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} );
}

/**
 * Formats a minute count as hours and minutes.
 */
export function duration( minutes: number ): string {
	if ( minutes <= 0 ) {
		return '—';
	}

	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;

	if ( hours === 0 ) {
		return `${ rest }m`;
	}

	return rest === 0 ? `${ hours }h` : `${ hours }h ${ rest }m`;
}

/**
 * Returns today's date as Y-m-d, in the browser's own timezone.
 */
export function today(): string {
	const now = new Date();

	return [
		now.getFullYear(),
		String( now.getMonth() + 1 ).padStart( 2, '0' ),
		String( now.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

/**
 * Adds days to a Y-m-d date.
 */
export function addDays( value: string, days: number ): string {
	const p = parts( value );

	if ( ! p ) {
		return value;
	}

	const local = new Date( p.y, p.m - 1, p.d );
	local.setDate( local.getDate() + days );

	return [
		local.getFullYear(),
		String( local.getMonth() + 1 ).padStart( 2, '0' ),
		String( local.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}
