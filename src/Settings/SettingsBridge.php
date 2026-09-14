<?php
/**
 * Connects settings to the behaviour they control.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Settings;

use FBM\Models\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the settings that infrastructure reads through a filter.
 *
 * The logger, the rate limiter and the mailer are all deliberately unaware of
 * the settings store: the logger runs before the store is loadable, and the
 * others should stay usable in isolation. Each already exposes a filter for the
 * value it needs, so the wiring lives here in one readable list instead of
 * turning every low-level class into a settings consumer.
 */
final class SettingsBridge {

	/**
	 * Attaches the filters.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'fbm_log_level', array( $this, 'log_level' ) );
		add_filter( 'fbm_rate_limit', array( $this, 'rate_limit' ), 10, 2 );
		add_filter( 'fbm_email_body', array( $this, 'email_footer' ), 10, 3 );
		add_action( 'fbm_settings_saved', array( $this, 'mirror_uninstall_choice' ) );
	}

	/**
	 * Copies the uninstall choice to a standalone option.
	 *
	 * Uninstall runs with the plugin deactivated and is the one place that must
	 * keep working after the settings schema has moved on, so it reads a single
	 * flag rather than reasoning about the settings array. Deleting somebody's
	 * booking history because a key was renamed is not a recoverable mistake.
	 *
	 * @return void
	 */
	public function mirror_uninstall_choice(): void {
		update_option(
			'fbm_delete_data_on_uninstall',
			! empty( Settings::all()['delete_data_on_uninstall'] ) ? '1' : '',
			false
		);
	}

	/**
	 * Applies the configured log level.
	 *
	 * @param string $level Level resolved from WP_DEBUG.
	 * @return string
	 */
	public function log_level( $level ): string {
		$configured = (string) ( Settings::all()['log_level'] ?? '' );

		// An empty setting means "follow WP_DEBUG", which is what the logger
		// already worked out for itself.
		return '' !== $configured ? $configured : (string) $level;
	}

	/**
	 * Applies the configured budget to public endpoints.
	 *
	 * Only the buckets reachable without logging in are capped. Throttling an
	 * authenticated staff member who is working through a list of bookings would
	 * be a self-inflicted outage.
	 *
	 * @param int    $limit  Requests allowed in the window.
	 * @param string $bucket Bucket name.
	 * @return int
	 */
	public function rate_limit( $limit, $bucket ): int {
		if ( is_user_logged_in() ) {
			return (int) $limit;
		}

		$configured = (int) ( Settings::all()['public_rate_limit'] ?? 0 );

		if ( $configured < 1 ) {
			return (int) $limit;
		}

		unset( $bucket );

		return $configured;
	}

	/**
	 * Adds the operator's footer to customer messages.
	 *
	 * @param string               $body    Message body.
	 * @param Booking              $booking Booking the message is about.
	 * @param array<string, mixed> $context Message context.
	 * @return string
	 */
	public function email_footer( $body, $booking, $context ): string {
		unset( $booking, $context );

		$footer = trim( (string) ( Settings::all()['email_footer_text'] ?? '' ) );

		if ( '' === $footer ) {
			return (string) $body;
		}

		return rtrim( (string) $body ) . "\n\n" . $footer;
	}
}
