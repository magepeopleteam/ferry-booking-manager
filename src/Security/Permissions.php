<?php
/**
 * Permission checks.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Central authority for "may the current user do X".
 *
 * REST controllers, the admin menu and every service consult this class instead
 * of calling current_user_can() directly, so object-level rules (an agent may
 * only touch their own bookings, for example) have exactly one home.
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
		return (bool) apply_filters( 'fbm_current_user_can', $allowed, $capability, $args );
	}

	/**
	 * Returns a REST permission callback for a capability.
	 *
	 * @param string $capability Capability slug.
	 * @return callable(): (true|WP_Error)
	 */
	public function rest_capability_callback( string $capability ): callable {
		return function () use ( $capability ) {
			return $this->authorize( $capability );
		};
	}

	/**
	 * Authorises a capability, returning a REST-ready error when denied.
	 *
	 * @param string $capability Capability slug.
	 * @param mixed  ...$args    Optional object arguments.
	 * @return true|WP_Error
	 */
	public function authorize( string $capability, ...$args ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'fbm_not_authenticated',
				__( 'You must be signed in to perform this action.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->current_user_can( $capability, ...$args ) ) {
			return new WP_Error(
				'fbm_forbidden',
				__( 'You do not have permission to perform this action.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Returns a REST permission callback for a publicly readable endpoint.
	 *
	 * Schedules, capacity and fares are public information on any ferry
	 * website: a customer has to see them before they have an account. The
	 * callback still exists rather than being `__return_true`, so that a site
	 * can close the public API with one filter, and so throttling has a home.
	 *
	 * @param string $bucket Rate-limit bucket name.
	 * @param int    $limit  Requests allowed per minute.
	 * @return callable(): (true|WP_Error)
	 */
	public function rest_public_callback( string $bucket, int $limit = 60 ): callable {
		return function () use ( $bucket, $limit ) {
			/**
			 * Filters whether the public booking API is reachable.
			 *
			 * @since 1.0.0
			 *
			 * @param bool   $allowed Whether anonymous access is permitted.
			 * @param string $bucket  Endpoint bucket.
			 */
			$allowed = (bool) apply_filters( 'fbm_allow_public_api', true, $bucket );

			if ( ! $allowed && ! is_user_logged_in() ) {
				return new WP_Error(
					'fbm_public_api_disabled',
					__( 'Online booking is not available.', 'magepeople-ferry-booking-system' ),
					array( 'status' => 403 )
				);
			}

			return $this->limiter->check( $bucket, $limit );
		};
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
