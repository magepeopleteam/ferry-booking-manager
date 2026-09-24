<?php
/**
 * Permission checks.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Central authority for "may the current user do X".
 *
 * REST routes declare their own capability check inline, as WordPress expects.
 * This class is where the rest lives: the throttle applied to public endpoints,
 * and object-level rules that a capability alone cannot answer.
 */
final class Permissions {

	/**
	 * Request throttle applied to public endpoints.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter|null $limiter Throttle, or null for the default.
	 */
	public function __construct( ?RateLimiter $limiter = null ) {
		$this->limiter = $limiter ?? new RateLimiter();
	}

	/**
	 * Determines whether the current user holds a capability.
	 *
	 * @param string $capability Capability slug.
	 * @param mixed  ...$args    Optional object arguments forwarded to current_user_can().
	 * @return bool
	 */
	public function current_user_can( string $capability, ...$args ): bool {
		$allowed = current_user_can( $capability, ...$args );

		/**
		 * Filters a MagePeople Ferry Booking System capability check.
		 *
		 * @since 1.0.0
		 *
		 * @param bool         $allowed    Whether the capability is granted.
		 * @param string       $capability Capability slug.
		 * @param array<mixed> $args       Object arguments.
		 */
		return (bool) apply_filters( 'mpfbs_current_user_can', $allowed, $capability, $args );
	}

	/**
	 * Applies the public-API switch and rate limit to a public request.
	 *
	 * Schedules, capacity and fares are public information on any ferry
	 * website, and a guest has to be able to book without an account, so those
	 * endpoints register `__return_true` as their permission callback. Their
	 * handlers call this first, so that a site can still close the public API
	 * with one filter and every public endpoint is throttled per visitor.
	 *
	 * @param string $bucket Rate-limit bucket name.
	 * @param int    $limit  Requests allowed per minute.
	 * @return true|WP_Error
	 */
	public function public_access( string $bucket, int $limit = 60 ) {
		/**
		 * Filters whether the public booking API is reachable.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $allowed Whether anonymous access is permitted.
		 * @param string $bucket  Endpoint bucket.
		 */
		$allowed = (bool) apply_filters( 'mpfbs_allow_public_api', true, $bucket );

		if ( ! $allowed && ! is_user_logged_in() ) {
			return new WP_Error(
				'mpfbs_public_api_disabled',
				__( 'Online booking is not available.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 403 )
			);
		}

		return $this->limiter->check( $bucket, $limit );
	}

	/**
	 * Returns the capabilities the current user holds, for the admin application.
	 *
	 * @return array<string, bool>
	 */
	public function current_user_capabilities(): array {
		$capabilities = array();

		foreach ( Capabilities::all() as $capability ) {
			$capabilities[ $capability ] = $this->current_user_can( $capability );
		}

		return $capabilities;
	}
}
