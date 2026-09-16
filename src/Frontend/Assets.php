<?php
/**
 * Front-end asset loading.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Frontend;

use FBM\Booking\FieldConfig;
use FBM\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the booking application, and only where it is needed.
 *
 * The bundle is enqueued lazily: a component renders, says so, and the script
 * is added to the footer for that request only. A site with the plugin active
 * but no booking form on the current page ships not one byte of it — which is
 * the difference between a plugin that slows a homepage down and one that does
 * not.
 */
final class Assets {

	/**
	 * Script handle.
	 */
	public const SCRIPT = 'fbm-booking';

	/**
	 * Style handle.
	 */
	public const STYLE = 'fbm-booking';

	/**
	 * Components rendered on this request.
	 *
	 * @var array<string, bool>
	 */
	private array $required = array();

	/**
	 * Whether the bundle has been enqueued already.
	 *
	 * @var bool
	 */
	private bool $enqueued = false;

	/**
	 * Attaches the hooks.
	 *
	 * Registration runs on `init` rather than `wp_enqueue_scripts`: in a block
	 * theme the content blocks can render before the enqueue action fires, and
	 * `wp_add_inline_script()` silently does nothing for a handle that is not
	 * registered yet. Registering early costs nothing — an unenqueued script
	 * ships no bytes — and guarantees the runtime configuration is attached
	 * whenever the component renders.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ), 40 );
		add_action( 'wp_footer', array( $this, 'flush' ), 5 );
	}

	/**
	 * Registers the bundle without enqueuing it.
	 *
	 * @return void
	 */
	public function register(): void {
		$manifest = $this->manifest();

		if ( array() === $manifest ) {
			return;
		}

		wp_register_style(
			self::STYLE,
			FBM_URL . 'assets/frontend/' . $manifest['css'],
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The filename carries a content hash, so a version query would only defeat caching.
		);

		wp_register_script(
			self::SCRIPT,
			FBM_URL . 'assets/frontend/' . $manifest['js'],
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Content-hashed filename.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Records that a component needs the bundle.
	 *
	 * @param string $component Component name.
	 * @return void
	 */
	public function require_component( string $component ): void {
		$this->required[ $component ] = true;

		if ( ! $this->enqueued && ! did_action( 'wp_footer' ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueues the bundle and its configuration.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( $this->enqueued || array() === $this->manifest() ) {
			return;
		}

		$this->enqueued = true;

		wp_enqueue_style( self::STYLE );
		$this->accent();
		wp_enqueue_script( self::SCRIPT );

		wp_add_inline_script(
			self::SCRIPT,
			'window.fbmBooking = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}

	/**
	 * Applies the operator's accent colour to the booking form.
	 *
	 * Only the base colour is configurable. The hover and tint shades are
	 * derived from it here rather than being three more settings, because an
	 * operator asked for one brand colour and picking three that work together
	 * is not their job. The tint is mixed against the page so it stays legible
	 * whatever the base colour is.
	 *
	 * @return void
	 */
	private function accent(): void {
		$accent = sanitize_hex_color( (string) ( Settings::all()['frontend_primary_color'] ?? '' ) );

		if ( ! is_string( $accent ) || '' === $accent ) {
			return;
		}

		// The shipped default is already in the stylesheet, so emitting it again
		// would be a pointless inline rule on every page. Compared against the
		// declared default rather than a literal, so the two cannot drift.
		$shipped = (string) ( Settings::defaults()['frontend_primary_color'] ?? '' );

		if ( '' !== $shipped && strtolower( $accent ) === strtolower( $shipped ) ) {
			return;
		}

		wp_add_inline_style(
			self::STYLE,
			sprintf(
				'.fbm-frontend{--fbmb-accent:%1$s;--fbmb-accent-hover:color-mix(in srgb,%1$s 82%%,#000);--fbmb-accent-soft:color-mix(in srgb,%1$s 12%%,#fff);}',
				$accent
			)
		);
	}

	/**
	 * Emits a notice when the bundle is missing.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( array() === $this->required || array() !== $this->manifest() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div style="margin:1em;padding:1em;border:1px solid #c2372f;background:#fcecea;color:#7a1f1a;font-family:sans-serif">%s</div>',
			esc_html__( 'MagePeople Ferry Booking System: the booking application has not been built. Run "npm ci && npm run build" in apps/booking inside the plugin folder.', 'magepeople-ferry-booking-system' )
		);
	}

	/**
	 * Returns the built bundle filenames.
	 *
	 * @return array{js: string, css: string}|array{}
	 */
	private function manifest(): array {
		static $manifest = null;

		if ( null !== $manifest ) {
			return $manifest;
		}

		$path = FBM_PATH . 'assets/frontend/manifest.json';

		if ( ! is_readable( $path ) ) {
			$manifest = array();

			return $manifest;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a build artefact shipped inside the plugin, not a remote resource.
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$manifest = is_array( $decoded ) && ! empty( $decoded['js'] ) && ! empty( $decoded['css'] )
			? array(
				'js'  => (string) $decoded['js'],
				'css' => (string) $decoded['css'],
			)
			: array();

		return $manifest;
	}

	/**
	 * Builds the runtime configuration handed to the booking application.
	 *
	 * The REST URL and the managed-page URLs are host-relative: the browser
	 * resolves them against the origin it loaded the page from. An absolute
	 * URL would pin the site to one hostname — breaking the booking flow on a
	 * LAN address or over HTTPS when WordPress was configured with
	 * "http://localhost" — because every fetch would become a cross-origin or
	 * mixed-content request.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$settings = Settings::all();

		$config = array(
			'restUrl'     => wp_make_link_relative( rest_url( 'fbm/v1/' ) ),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'homeUrl'     => esc_url_raw( home_url( '/' ) ),
			'locale'      => str_replace( '_', '-', get_user_locale() ),
			'isRtl'       => is_rtl(),
			'dateFormat'  => (string) get_option( 'date_format', 'Y-m-d' ),
			'timeFormat'  => (string) get_option( 'time_format', 'H:i' ),
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			'loggedIn'    => is_user_logged_in(),
			// The same value the hold engine uses. This read a standalone
			// option that nothing wrote, so the countdown a customer saw was
			// always 15 minutes whatever the operator had configured.
			'holdMinutes' => max( 1, (int) $settings['hold_minutes'] ),
			'i18n'        => $this->translations(),
		);

		// The operator's public-facing choices: what the form must ask for,
		// whether it may offer vehicles, and what it may say about how full a
		// sailing is.
		$config += Settings::public_context();

		/**
		 * Filters the runtime configuration handed to the booking application.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config Runtime configuration.
		 */
		return (array) apply_filters( 'fbm_frontend_config', $config );
	}

	/**
	 * Returns the translated strings the booking application uses.
	 *
	 * Translating in PHP keeps every string in the plugin's .pot file, so the
	 * booking flow works with WPML, Polylang and TranslatePress without a
	 * second catalogue.
	 *
	 * @return array<string, string>
	 */
	private function translations(): array {
		$strings = array(
			'From'                                         => __( 'From', 'magepeople-ferry-booking-system' ),
			'To'                                           => __( 'To', 'magepeople-ferry-booking-system' ),
			'Departure'                                    => __( 'Departure', 'magepeople-ferry-booking-system' ),
			'Return'                                       => __( 'Return', 'magepeople-ferry-booking-system' ),
			'Return journey'                               => __( 'Return journey', 'magepeople-ferry-booking-system' ),
			'One way'                                      => __( 'One way', 'magepeople-ferry-booking-system' ),
			'Passengers'                                   => __( 'Passengers', 'magepeople-ferry-booking-system' ),
			'Vehicles'                                     => __( 'Vehicles', 'magepeople-ferry-booking-system' ),
			'Search sailings'                              => __( 'Search sailings', 'magepeople-ferry-booking-system' ),
			'Searching…'                                   => __( 'Searching…', 'magepeople-ferry-booking-system' ),
			'Select a port'                                => __( 'Select a port', 'magepeople-ferry-booking-system' ),
			'Fares shown are per person, one way. Your total is confirmed when you choose a sailing.' => __( 'Fares shown are per person, one way. Your total is confirmed when you choose a sailing.', 'magepeople-ferry-booking-system' ),
			'Free'                                         => __( 'Free', 'magepeople-ferry-booking-system' ),
			'This crossing carries foot passengers only.'  => __( 'This crossing carries foot passengers only.', 'magepeople-ferry-booking-system' ),
			'No crossings run from that port yet.'         => __( 'No crossings run from that port yet.', 'magepeople-ferry-booking-system' ),
			'Choose'                                       => __( 'Choose', 'magepeople-ferry-booking-system' ),
			'Selected'                                     => __( 'Selected', 'magepeople-ferry-booking-system' ),
			'Sold out'                                     => __( 'Sold out', 'magepeople-ferry-booking-system' ),
			'I agree to the'                               => __( 'I agree to the', 'magepeople-ferry-booking-system' ),
			'terms and conditions'                         => __( 'terms and conditions', 'magepeople-ferry-booking-system' ),
			'and the'                                      => __( 'and the', 'magepeople-ferry-booking-system' ),
			'cancellation policy'                          => __( 'cancellation policy', 'magepeople-ferry-booking-system' ),
			'Largest party we can book online is'          => __( 'Largest party we can book online is', 'magepeople-ferry-booking-system' ),
			'Book now'                                     => __( 'Book now', 'magepeople-ferry-booking-system' ),
			'Continue'                                     => __( 'Continue', 'magepeople-ferry-booking-system' ),
			'Back'                                         => __( 'Back', 'magepeople-ferry-booking-system' ),
			'Total'                                        => __( 'Total', 'magepeople-ferry-booking-system' ),
			'Loading…'                                     => __( 'Loading…', 'magepeople-ferry-booking-system' ),
			'Something went wrong.'                        => __( 'Something went wrong.', 'magepeople-ferry-booking-system' ),
			'Try again'                                    => __( 'Try again', 'magepeople-ferry-booking-system' ),
			'No sailings found.'                           => __( 'No sailings found.', 'magepeople-ferry-booking-system' ),
			'Add a passenger to see fares.'                => __( 'Add a passenger to see fares.', 'magepeople-ferry-booking-system' ),
			'Journey'                                      => __( 'Journey', 'magepeople-ferry-booking-system' ),
			'Route and date'                               => __( 'Route and date', 'magepeople-ferry-booking-system' ),
			'Travellers'                                   => __( 'Travellers', 'magepeople-ferry-booking-system' ),
			'Booked as a guest? Use the reference from your confirmation email to find it instead.' => __( 'Booked as a guest? Use the reference from your confirmation email to find it instead.', 'magepeople-ferry-booking-system' ),
			'Confirm the email address you booked with to see your crossing.' => __( 'Confirm the email address you booked with to see your crossing.', 'magepeople-ferry-booking-system' ),
			'Crossing details are no longer available.'    => __( 'Crossing details are no longer available.', 'magepeople-ferry-booking-system' ),
			'Crossings you book will appear here.'         => __( 'Crossings you book will appear here.', 'magepeople-ferry-booking-system' ),
			'Enter your booking reference and the email address you booked with.' => __( 'Enter your booking reference and the email address you booked with.', 'magepeople-ferry-booking-system' ),
			'Find booking'                                 => __( 'Find booking', 'magepeople-ferry-booking-system' ),
			'Find my booking'                              => __( 'Find my booking', 'magepeople-ferry-booking-system' ),
			'Please sign in to see your bookings'          => __( 'Please sign in to see your bookings', 'magepeople-ferry-booking-system' ),
			'Previous crossings'                           => __( 'Previous crossings', 'magepeople-ferry-booking-system' ),
			'See all my bookings'                          => __( 'See all my bookings', 'magepeople-ferry-booking-system' ),
			'Still to pay'                                 => __( 'Still to pay', 'magepeople-ferry-booking-system' ),
			'Upcoming crossings'                           => __( 'Upcoming crossings', 'magepeople-ferry-booking-system' ),
			'You have no bookings yet'                     => __( 'You have no bookings yet', 'magepeople-ferry-booking-system' ),
			'Your booking'                                 => __( 'Your booking', 'magepeople-ferry-booking-system' ),
			'Your reference is in the confirmation email we sent when you booked.' => __( 'Your reference is in the confirmation email we sent when you booked.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: first value, 2: second value, joined with a space. */
			'%1$s %2$s'                                    => __( '%1$s %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: 1: origin port, 2: destination port. */
			'%1$s to %2$s'                                 => __( '%1$s to %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of places still available. */
			'%s left'                                      => __( '%s left', 'magepeople-ferry-booking-system' ),
			'1 passenger'                                  => __( '1 passenger', 'magepeople-ferry-booking-system' ),
			'1 vehicle'                                    => __( '1 vehicle', 'magepeople-ferry-booking-system' ),
			'Add at least one passenger.'                  => __( 'Add at least one passenger.', 'magepeople-ferry-booking-system' ),
			'All vessels'                                  => __( 'All vessels', 'magepeople-ferry-booking-system' ),
			'Available'                                    => __( 'Available', 'magepeople-ferry-booking-system' ),
			'Booking reference'                            => __( 'Booking reference', 'magepeople-ferry-booking-system' ),
			'Choose a departure date.'                     => __( 'Choose a departure date.', 'magepeople-ferry-booking-system' ),
			'Choose where you are travelling from and to.' => __( 'Choose where you are travelling from and to.', 'magepeople-ferry-booking-system' ),
			'Clear a filter to see more crossings.'        => __( 'Clear a filter to see more crossings.', 'magepeople-ferry-booking-system' ),
			'Confirm booking'                              => __( 'Confirm booking', 'magepeople-ferry-booking-system' ),
			'Confirmation'                                 => __( 'Confirmation', 'magepeople-ferry-booking-system' ),
			'Confirming…'                                  => __( 'Confirming…', 'magepeople-ferry-booking-system' ),
			'Contact'                                      => __( 'Contact', 'magepeople-ferry-booking-system' ),
			'Crossing'                                     => __( 'Crossing', 'magepeople-ferry-booking-system' ),
			'Date of birth'                                => __( 'Date of birth', 'magepeople-ferry-booking-system' ),
			'Details'                                      => __( 'Details', 'magepeople-ferry-booking-system' ),
			'Done'                                         => __( 'Done', 'magepeople-ferry-booking-system' ),
			'Driving licence'                              => __( 'Driving licence', 'magepeople-ferry-booking-system' ),
			'Earliest first'                               => __( 'Earliest first', 'magepeople-ferry-booking-system' ),
			'Email'                                        => __( 'Email', 'magepeople-ferry-booking-system' ),
			'Female'                                       => __( 'Female', 'magepeople-ferry-booking-system' ),
			'Foot passengers only'                         => __( 'Foot passengers only', 'magepeople-ferry-booking-system' ),
			'Full name'                                    => __( 'Full name', 'magepeople-ferry-booking-system' ),
			'Hide unavailable'                             => __( 'Hide unavailable', 'magepeople-ferry-booking-system' ),
			'Journey type'                                 => __( 'Journey type', 'magepeople-ferry-booking-system' ),
			'Latest first'                                 => __( 'Latest first', 'magepeople-ferry-booking-system' ),
			'Lowest price'                                 => __( 'Lowest price', 'magepeople-ferry-booking-system' ),
			'Male'                                         => __( 'Male', 'magepeople-ferry-booking-system' ),
			'National ID card'                             => __( 'National ID card', 'magepeople-ferry-booking-system' ),
			'No crossings are on sale yet. Please check back soon.' => __( 'No crossings are on sale yet. Please check back soon.', 'magepeople-ferry-booking-system' ),
			'No vehicle space'                             => __( 'No vehicle space', 'magepeople-ferry-booking-system' ),
			'Nothing matches those filters.'               => __( 'Nothing matches those filters.', 'magepeople-ferry-booking-system' ),
			'Other'                                        => __( 'Other', 'magepeople-ferry-booking-system' ),
			'Outbound'                                     => __( 'Outbound', 'magepeople-ferry-booking-system' ),
			'Passenger details'                            => __( 'Passenger details', 'magepeople-ferry-booking-system' ),
			'Passport'                                     => __( 'Passport', 'magepeople-ferry-booking-system' ),
			'Payment'                                      => __( 'Payment', 'magepeople-ferry-booking-system' ),
			'Phone'                                        => __( 'Phone', 'magepeople-ferry-booking-system' ),
			'Prefer not to say'                            => __( 'Prefer not to say', 'magepeople-ferry-booking-system' ),
			'Price'                                        => __( 'Price', 'magepeople-ferry-booking-system' ),
			'Registration number'                          => __( 'Registration number', 'magepeople-ferry-booking-system' ),
			'Review'                                       => __( 'Review', 'magepeople-ferry-booking-system' ),
			'Review booking'                               => __( 'Review booking', 'magepeople-ferry-booking-system' ),
			'Select'                                       => __( 'Select', 'magepeople-ferry-booking-system' ),
			'Shortest crossing'                            => __( 'Shortest crossing', 'magepeople-ferry-booking-system' ),
			'Swap ports'                                   => __( 'Swap ports', 'magepeople-ferry-booking-system' ),
			'The departure and arrival ports have to be different.' => __( 'The departure and arrival ports have to be different.', 'magepeople-ferry-booking-system' ),
			'Try a different date, or another crossing.'   => __( 'Try a different date, or another crossing.', 'magepeople-ferry-booking-system' ),
			'Used only if we need to reach you about this crossing.' => __( 'Used only if we need to reach you about this crossing.', 'magepeople-ferry-booking-system' ),
			'Vehicle details'                              => __( 'Vehicle details', 'magepeople-ferry-booking-system' ),
			'Vehicles carried'                             => __( 'Vehicles carried', 'magepeople-ferry-booking-system' ),
			'We have emailed your confirmation. Please bring your reference to check-in.' => __( 'We have emailed your confirmation. Please bring your reference to check-in.', 'magepeople-ferry-booking-system' ),
			'We send your tickets and any schedule changes to this address.' => __( 'We send your tickets and any schedule changes to this address.', 'magepeople-ferry-booking-system' ),
			'Your booking is confirmed'                    => __( 'Your booking is confirmed', 'magepeople-ferry-booking-system' ),
			'Your crossing'                                => __( 'Your crossing', 'magepeople-ferry-booking-system' ),
			'Your details'                                 => __( 'Your details', 'magepeople-ferry-booking-system' ),
			'passengers'                                   => __( 'passengers', 'magepeople-ferry-booking-system' ),
			'total'                                        => __( 'total', 'magepeople-ferry-booking-system' ),
			'under'                                        => __( 'under', 'magepeople-ferry-booking-system' ),
			'up to'                                        => __( 'up to', 'magepeople-ferry-booking-system' ),
			'vehicles'                                     => __( 'vehicles', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a number of passengers. */
			'%s passenger'                                 => __( '%s passenger', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a number of passengers. */
			'%s passengers'                                => __( '%s passengers', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a number of vehicles. */
			'%s vehicle'                                   => __( '%s vehicle', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'%s per page'                                  => __( '%s per page', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'%s to pay'                                    => __( '%s to pay', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'%s vehicles'                                  => __( '%s vehicles', 'magepeople-ferry-booking-system' ),
			'All'                                          => __( 'All', 'magepeople-ferry-booking-system' ),
			'Any status'                                   => __( 'Any status', 'magepeople-ferry-booking-system' ),
			'Bookings per page'                            => __( 'Bookings per page', 'magepeople-ferry-booking-system' ),
			'Filter by status'                             => __( 'Filter by status', 'magepeople-ferry-booking-system' ),
			'Hide details'                                 => __( 'Hide details', 'magepeople-ferry-booking-system' ),
			'Next'                                         => __( 'Next', 'magepeople-ferry-booking-system' ),
			'Nothing matched'                              => __( 'Nothing matched', 'magepeople-ferry-booking-system' ),
			'No past crossings'                            => __( 'No past crossings', 'magepeople-ferry-booking-system' ),
			'No upcoming crossings'                        => __( 'No upcoming crossings', 'magepeople-ferry-booking-system' ),
			'Crossings you have already travelled on will appear here.' => __( 'Crossings you have already travelled on will appear here.', 'magepeople-ferry-booking-system' ),
			'Book a crossing and it will appear here.'     => __( 'Book a crossing and it will appear here.', 'magepeople-ferry-booking-system' ),
			'Only your most recent bookings are shown. Older crossings are not listed here — ask us if you need one.' => __( 'Only your most recent bookings are shown. Older crossings are not listed here — ask us if you need one.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2. */
			'Page %1$s of %2$s'                            => __( 'Page %1$s of %2$s', 'magepeople-ferry-booking-system' ),
			'Pages'                                        => __( 'Pages', 'magepeople-ferry-booking-system' ),
			'Past'                                         => __( 'Past', 'magepeople-ferry-booking-system' ),
			'Previous'                                     => __( 'Previous', 'magepeople-ferry-booking-system' ),
			'Search by reference, route or port'           => __( 'Search by reference, route or port', 'magepeople-ferry-booking-system' ),
			'Search your bookings'                         => __( 'Search your bookings', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'Showing %1$s–%2$s of %3$s'                    => __( 'Showing %1$s–%2$s of %3$s', 'magepeople-ferry-booking-system' ),
			'Ticket'                                       => __( 'Ticket', 'magepeople-ferry-booking-system' ),
			'Try a different reference, route or port, or clear the filters.' => __( 'Try a different reference, route or port, or clear the filters.', 'magepeople-ferry-booking-system' ),
			'Upcoming'                                     => __( 'Upcoming', 'magepeople-ferry-booking-system' ),
			'Which bookings'                               => __( 'Which bookings', 'magepeople-ferry-booking-system' ),
			'return'                                       => __( 'return', 'magepeople-ferry-booking-system' ),
		);

		/**
		 * Filters the booking application's translation dictionary.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $strings Source string => translation.
		 */
		return (array) apply_filters( 'fbm_frontend_translations', $strings );
	}

	/**
	 * Returns the configured capture fields, for a server-rendered fallback.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function capture_fields(): array {
		return array(
			'passenger' => FieldConfig::form( FieldConfig::GROUP_PASSENGER ),
			'vehicle'   => FieldConfig::form( FieldConfig::GROUP_VEHICLE ),
		);
	}
}
