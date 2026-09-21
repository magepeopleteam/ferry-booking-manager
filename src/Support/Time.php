<?php
/**
 * Date and time helpers.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Timezone-correct conversions between authored local times and UTC.
 *
 * Sailings are authored in the operator's local wall-clock time. Everything the
 * system compares or sorts by — capacity windows, booking windows, listings — works
 * in UTC. This class is the only place that bridges the two, so a journey that
 * crosses midnight or a site whose timezone is corrected later behaves the same
 * everywhere.
 */
final class Time {

	/**
	 * MySQL date/time format used throughout the plugin.
	 */
	public const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Returns the site timezone.
	 *
	 * @return DateTimeZone
	 */
	public static function timezone(): DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Converts a site-local date/time string to a UTC timestamp.
	 *
	 * @param string $local Local date/time, e.g. "2026-06-01 08:00:00".
	 * @return int UTC timestamp, or 0 when the input cannot be parsed.
	 */
	public static function local_to_timestamp( string $local ): int {
		$local = trim( $local );

		if ( '' === $local ) {
			return 0;
		}

		try {
			$date = new DateTimeImmutable( $local, self::timezone() );
		} catch ( Exception $e ) {
			return 0;
		}

		return $date->getTimestamp();
	}

	/**
	 * Converts a UTC timestamp to a site-local date/time string.
	 *
	 * @param int    $timestamp UTC timestamp.
	 * @param string $format    Output format.
	 * @return string
	 */
	public static function timestamp_to_local( int $timestamp, string $format = self::FORMAT ): string {
		$formatted = wp_date( $format, $timestamp );

		return false === $formatted ? '' : $formatted;
	}

	/**
	 * Returns the current site-local date/time string.
	 *
	 * @param string $format Output format.
	 * @return string
	 */
	public static function now( string $format = self::FORMAT ): string {
		return self::timestamp_to_local( time(), $format );
	}

	/**
	 * Formats a stored local date/time using the site's display preferences.
	 *
	 * @param string $local Local date/time string.
	 * @param bool   $with_time Whether to include the time part.
	 * @return string
	 */
	public static function display( string $local, bool $with_time = true ): string {
		$timestamp = self::local_to_timestamp( $local );

		if ( 0 === $timestamp ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'Y-m-d' );

		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format', 'H:i' );
		}

		return self::timestamp_to_local( $timestamp, $format );
	}

	/**
	 * Adds minutes to a local date/time string, correctly crossing midnight.
	 *
	 * @param string $local   Local date/time string.
	 * @param int    $minutes Minutes to add; may be negative.
	 * @return string Local date/time string, or an empty string on bad input.
	 */
	public static function add_minutes( string $local, int $minutes ): string {
		$timestamp = self::local_to_timestamp( $local );

		if ( 0 === $timestamp ) {
			return '';
		}

		return self::timestamp_to_local( $timestamp + ( $minutes * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Determines whether a string is a usable date, or date and time.
	 *
	 * Deliberately stricter than "PHP can parse it". DateTime happily reads
	 * "next tuesday" and "+1 week", which resolve against the moment they are
	 * read — so a sailing stored from one would sit at a different time every
	 * time it was saved. Anything meant to be *kept* has to be an absolute
	 * date, written the way the pickers write it.
	 *
	 * Both "2026-06-01" and "2026-06-01 08:00" are accepted, with or without
	 * seconds, because a date-only value is a legitimate day boundary.
	 *
	 * @param string $local Local date/time string.
	 * @return bool
	 */
	public static function is_valid( string $local ): bool {
		$local = trim( $local );

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $local, $parts ) ) {
			return false;
		}

		// A well-shaped string can still name a day that does not exist, and
		// checkdate is the only thing that knows February has 28 days most
		// years.
		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return false;
		}

		if ( isset( $parts[4] ) && ( (int) $parts[4] > 23 || (int) $parts[5] > 59 ) ) {
			return false;
		}

		if ( isset( $parts[6] ) && (int) $parts[6] > 59 ) {
			return false;
		}

		return 0 !== self::local_to_timestamp( $local );
	}
}
