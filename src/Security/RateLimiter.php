<?php
/**
 * Request throttling.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Caps how often one caller may hit an unauthenticated endpoint.
 *
 * The booking search, the quote endpoint and booking creation are open to the
 * public by necessity — a customer is not logged in. That makes them the only
 * unauthenticated write surface in the plugin, so they get a budget.
 *
 * Counters live in transients, which means they are approximate: a site without
 * a persistent object cache stores them in options, and two requests landing in
 * the same millisecond can both read the same count. That is an acceptable
 * trade for a throttle whose job is to stop a script hammering an endpoint, not
 * to enforce an exact quota.
 */
final class RateLimiter {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'fbm_rl_';

	/**
	 * Applies a budget to the current caller.
	 *
	 * @param string $bucket  What is being limited, e.g. "search".
	 * @param int    $limit   Requests allowed in the window.
	 * @param int    $window  Window length in seconds.
	 * @return true|WP_Error
	 */
	public function check( string $bucket, int $limit = 60, int $window = 60 ) {
		/**
		 * Filters the request budget for an endpoint bucket.
		 *
		 * Return 0 to disable throttling for that bucket.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $limit  Requests allowed in the window.
		 * @param string $bucket Bucket name.
		 */
		$limit = (int) apply_filters( 'fbm_rate_limit', $limit, $bucket );

		if ( $limit < 1 ) {
			return true;
		}

		// A signed-in member of staff working a counter legitimately makes far
		// more requests than a browsing customer, and they are already
		// accountable through their account.
		if ( is_user_logged_in() ) {
			return true;
		}

		$key   = self::PREFIX . $bucket . '_' . $this->fingerprint();
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'fbm_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'magepeople-ferry-booking-system' ),
				array(
					'status'      => 429,
					'retry_after' => $window,
				)
			);
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Identifies the caller well enough to throttle them.
	 *
	 * Hashed, and never stored in the clear: a rate-limit counter is not a
	 * reason to keep a log of who visited a booking page.
	 *
	 * @return string
	 */
	private function fingerprint(): string {
		$address = '';

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$candidate = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$address = $candidate;
					break;
				}
			}
		}

		if ( '' === $address ) {
			$address = 'unknown';
		}

		return substr( md5( $address . wp_salt( 'nonce' ) ), 0, 16 );
	}
}
