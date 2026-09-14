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
			esc_html__( 'Ferry Booking Manager: the booking application has not been built. Run "npm ci && npm run build" in apps/booking inside the plugin folder.', 'ferry-booking-manager' )
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
			'From'                                         => __( 'From', 'ferry-booking-manager' ),
			'To'                                           => __( 'To', 'ferry-booking-manager' ),
			'Departure'                                    => __( 'Departure', 'ferry-booking-manager' ),
			'Return'                                       => __( 'Return', 'ferry-booking-manager' ),
			'Return journey'                               => __( 'Return journey', 'ferry-booking-manager' ),
			'One way'                                      => __( 'One way', 'ferry-booking-manager' ),
			'Passengers'                                   => __( 'Passengers', 'ferry-booking-manager' ),
			'Vehicles'                                     => __( 'Vehicles', 'ferry-booking-manager' ),
			'Search sailings'                              => __( 'Search sailings', 'ferry-booking-manager' ),
			'Searching…'                                   => __( 'Searching…', 'ferry-booking-manager' ),
			'Select a port'                                => __( 'Select a port', 'ferry-booking-manager' ),
			'Fares shown are per person, one way. Your total is confirmed when you choose a sailing.' => __( 'Fares shown are per person, one way. Your total is confirmed when you choose a sailing.', 'ferry-booking-manager' ),
			'Free'                                         => __( 'Free', 'ferry-booking-manager' ),
			'This crossing carries foot passengers only.'  => __( 'This crossing carries foot passengers only.', 'ferry-booking-manager' ),
			'No crossings run from that port yet.'         => __( 'No crossings run from that port yet.', 'ferry-booking-manager' ),
			'Choose'                                       => __( 'Choose', 'ferry-booking-manager' ),
			'Selected'                                     => __( 'Selected', 'ferry-booking-manager' ),
			'Sold out'                                     => __( 'Sold out', 'ferry-booking-manager' ),
			'I agree to the'                               => __( 'I agree to the', 'ferry-booking-manager' ),
			'terms and conditions'                         => __( 'terms and conditions', 'ferry-booking-manager' ),
			'and the'                                      => __( 'and the', 'ferry-booking-manager' ),
			'cancellation policy'                          => __( 'cancellation policy', 'ferry-booking-manager' ),
			'Largest party we can book online is'          => __( 'Largest party we can book online is', 'ferry-booking-manager' ),
			'Book now'                                     => __( 'Book now', 'ferry-booking-manager' ),
			'Continue'                                     => __( 'Continue', 'ferry-booking-manager' ),
			'Back'                                         => __( 'Back', 'ferry-booking-manager' ),
			'Total'                                        => __( 'Total', 'ferry-booking-manager' ),
			'Loading…'                                     => __( 'Loading…', 'ferry-booking-manager' ),
			'Something went wrong.'                        => __( 'Something went wrong.', 'ferry-booking-manager' ),
			'Try again'                                    => __( 'Try again', 'ferry-booking-manager' ),
			'No sailings found.'                           => __( 'No sailings found.', 'ferry-booking-manager' ),
			'Add a passenger to see fares.'                => __( 'Add a passenger to see fares.', 'ferry-booking-manager' ),
			'Journey'                                      => __( 'Journey', 'ferry-booking-manager' ),
			'Route and date'                               => __( 'Route and date', 'ferry-booking-manager' ),
			'Travellers'                                   => __( 'Travellers', 'ferry-booking-manager' ),
			'Booked as a guest? Use the reference from your confirmation email to find it instead.' => __( 'Booked as a guest? Use the reference from your confirmation email to find it instead.', 'ferry-booking-manager' ),
			'Confirm the email address you booked with to see your crossing.' => __( 'Confirm the email address you booked with to see your crossing.', 'ferry-booking-manager' ),
			'Crossing details are no longer available.'    => __( 'Crossing details are no longer available.', 'ferry-booking-manager' ),
			'Crossings you book will appear here.'         => __( 'Crossings you book will appear here.', 'ferry-booking-manager' ),
			'Enter your booking reference and the email address you booked with.' => __( 'Enter your booking reference and the email address you booked with.', 'ferry-booking-manager' ),
			'Find booking'                                 => __( 'Find booking', 'ferry-booking-manager' ),
			'Find my booking'                              => __( 'Find my booking', 'ferry-booking-manager' ),
			'Please sign in to see your bookings'          => __( 'Please sign in to see your bookings', 'ferry-booking-manager' ),
			'Previous crossings'                           => __( 'Previous crossings', 'ferry-booking-manager' ),
			'See all my bookings'                          => __( 'See all my bookings', 'ferry-booking-manager' ),
			'Still to pay'                                 => __( 'Still to pay', 'ferry-booking-manager' ),
			'Upcoming crossings'                           => __( 'Upcoming crossings', 'ferry-booking-manager' ),
			'You have no bookings yet'                     => __( 'You have no bookings yet', 'ferry-booking-manager' ),
			'Your booking'                                 => __( 'Your booking', 'ferry-booking-manager' ),
			'Your reference is in the confirmation email we sent when you booked.' => __( 'Your reference is in the confirmation email we sent when you booked.', 'ferry-booking-manager' ),
			/* translators: 1: first value, 2: second value, joined with a space. */
			'%1$s %2$s'                                    => __( '%1$s %2$s', 'ferry-booking-manager' ),
			/* translators: 1: origin port, 2: destination port. */
			'%1$s to %2$s'                                 => __( '%1$s to %2$s', 'ferry-booking-manager' ),
			/* translators: %s: number of places still available. */
			'%s left'                                      => __( '%s left', 'ferry-booking-manager' ),
			'1 passenger'                                  => __( '1 passenger', 'ferry-booking-manager' ),
			'1 vehicle'                                    => __( '1 vehicle', 'ferry-booking-manager' ),
			'Add at least one passenger.'                  => __( 'Add at least one passenger.', 'ferry-booking-manager' ),
			'All vessels'                                  => __( 'All vessels', 'ferry-booking-manager' ),
			'Available'                                    => __( 'Available', 'ferry-booking-manager' ),
			'Booking reference'                            => __( 'Booking reference', 'ferry-booking-manager' ),
			'Choose a departure date.'                     => __( 'Choose a departure date.', 'ferry-booking-manager' ),
			'Choose where you are travelling from and to.' => __( 'Choose where you are travelling from and to.', 'ferry-booking-manager' ),
			'Clear a filter to see more crossings.'        => __( 'Clear a filter to see more crossings.', 'ferry-booking-manager' ),
			'Confirm booking'                              => __( 'Confirm booking', 'ferry-booking-manager' ),
			'Confirmation'                                 => __( 'Confirmation', 'ferry-booking-manager' ),
			'Confirming…'                                  => __( 'Confirming…', 'ferry-booking-manager' ),
			'Contact'                                      => __( 'Contact', 'ferry-booking-manager' ),
			'Crossing'                                     => __( 'Crossing', 'ferry-booking-manager' ),
			'Date of birth'                                => __( 'Date of birth', 'ferry-booking-manager' ),
			'Details'                                      => __( 'Details', 'ferry-booking-manager' ),
			'Done'                                         => __( 'Done', 'ferry-booking-manager' ),
			'Driving licence'                              => __( 'Driving licence', 'ferry-booking-manager' ),
			'Earliest first'                               => __( 'Earliest first', 'ferry-booking-manager' ),
			'Email'                                        => __( 'Email', 'ferry-booking-manager' ),
			'Female'                                       => __( 'Female', 'ferry-booking-manager' ),
			'Foot passengers only'                         => __( 'Foot passengers only', 'ferry-booking-manager' ),
			'Full name'                                    => __( 'Full name', 'ferry-booking-manager' ),
			'Hide unavailable'                             => __( 'Hide unavailable', 'ferry-booking-manager' ),
			'Journey type'                                 => __( 'Journey type', 'ferry-booking-manager' ),
			'Latest first'                                 => __( 'Latest first', 'ferry-booking-manager' ),
			'Lowest price'                                 => __( 'Lowest price', 'ferry-booking-manager' ),
			'Male'                                         => __( 'Male', 'ferry-booking-manager' ),
			'National ID card'                             => __( 'National ID card', 'ferry-booking-manager' ),
			'No crossings are on sale yet. Please check back soon.' => __( 'No crossings are on sale yet. Please check back soon.', 'ferry-booking-manager' ),
			'No vehicle space'                             => __( 'No vehicle space', 'ferry-booking-manager' ),
			'Nothing matches those filters.'               => __( 'Nothing matches those filters.', 'ferry-booking-manager' ),
			'Other'                                        => __( 'Other', 'ferry-booking-manager' ),
			'Outbound'                                     => __( 'Outbound', 'ferry-booking-manager' ),
			'Passenger details'                            => __( 'Passenger details', 'ferry-booking-manager' ),
			'Passport'                                     => __( 'Passport', 'ferry-booking-manager' ),
			'Payment'                                      => __( 'Payment', 'ferry-booking-manager' ),
			'Phone'                                        => __( 'Phone', 'ferry-booking-manager' ),
			'Prefer not to say'                            => __( 'Prefer not to say', 'ferry-booking-manager' ),
			'Price'                                        => __( 'Price', 'ferry-booking-manager' ),
			'Registration number'                          => __( 'Registration number', 'ferry-booking-manager' ),
			'Review'                                       => __( 'Review', 'ferry-booking-manager' ),
			'Review booking'                               => __( 'Review booking', 'ferry-booking-manager' ),
			'Select'                                       => __( 'Select', 'ferry-booking-manager' ),
			'Shortest crossing'                            => __( 'Shortest crossing', 'ferry-booking-manager' ),
			'Swap ports'                                   => __( 'Swap ports', 'ferry-booking-manager' ),
			'The departure and arrival ports have to be different.' => __( 'The departure and arrival ports have to be different.', 'ferry-booking-manager' ),
			'Try a different date, or another crossing.'   => __( 'Try a different date, or another crossing.', 'ferry-booking-manager' ),
			'Used only if we need to reach you about this crossing.' => __( 'Used only if we need to reach you about this crossing.', 'ferry-booking-manager' ),
			'Vehicle details'                              => __( 'Vehicle details', 'ferry-booking-manager' ),
			'Vehicles carried'                             => __( 'Vehicles carried', 'ferry-booking-manager' ),
			'We have emailed your confirmation. Please bring your reference to check-in.' => __( 'We have emailed your confirmation. Please bring your reference to check-in.', 'ferry-booking-manager' ),
			'We send your tickets and any schedule changes to this address.' => __( 'We send your tickets and any schedule changes to this address.', 'ferry-booking-manager' ),
			'Your booking is confirmed'                    => __( 'Your booking is confirmed', 'ferry-booking-manager' ),
			'Your crossing'                                => __( 'Your crossing', 'ferry-booking-manager' ),
			'Your details'                                 => __( 'Your details', 'ferry-booking-manager' ),
			'passengers'                                   => __( 'passengers', 'ferry-booking-manager' ),
			'total'                                        => __( 'total', 'ferry-booking-manager' ),
			'under'                                        => __( 'under', 'ferry-booking-manager' ),
			'up to'                                        => __( 'up to', 'ferry-booking-manager' ),
			'vehicles'                                     => __( 'vehicles', 'ferry-booking-manager' ),
			/* translators: %s: a number of passengers. */
			'%s passenger'                                 => __( '%s passenger', 'ferry-booking-manager' ),
			/* translators: %s: a number of passengers. */
			'%s passengers'                                => __( '%s passengers', 'ferry-booking-manager' ),
			/* translators: %s: a number of vehicles. */
			'%s vehicle'                                   => __( '%s vehicle', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'%s per page'                                  => __( '%s per page', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'%s to pay'                                    => __( '%s to pay', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'%s vehicles'                                  => __( '%s vehicles', 'ferry-booking-manager' ),
			'All'                                          => __( 'All', 'ferry-booking-manager' ),
			'Any status'                                   => __( 'Any status', 'ferry-booking-manager' ),
			'Bookings per page'                            => __( 'Bookings per page', 'ferry-booking-manager' ),
			'Filter by status'                             => __( 'Filter by status', 'ferry-booking-manager' ),
			'Hide details'                                 => __( 'Hide details', 'ferry-booking-manager' ),
			'Next'                                         => __( 'Next', 'ferry-booking-manager' ),
			'Nothing matched'                              => __( 'Nothing matched', 'ferry-booking-manager' ),
			'No past crossings'                            => __( 'No past crossings', 'ferry-booking-manager' ),
			'No upcoming crossings'                        => __( 'No upcoming crossings', 'ferry-booking-manager' ),
			'Crossings you have already travelled on will appear here.' => __( 'Crossings you have already travelled on will appear here.', 'ferry-booking-manager' ),
			'Book a crossing and it will appear here.'     => __( 'Book a crossing and it will appear here.', 'ferry-booking-manager' ),
			'Only your most recent bookings are shown. Older crossings are not listed here — ask us if you need one.' => __( 'Only your most recent bookings are shown. Older crossings are not listed here — ask us if you need one.', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2. */
			'Page %1$s of %2$s'                            => __( 'Page %1$s of %2$s', 'ferry-booking-manager' ),
			'Pages'                                        => __( 'Pages', 'ferry-booking-manager' ),
			'Past'                                         => __( 'Past', 'ferry-booking-manager' ),
			'Previous'                                     => __( 'Previous', 'ferry-booking-manager' ),
			'Search by reference, route or port'           => __( 'Search by reference, route or port', 'ferry-booking-manager' ),
			'Search your bookings'                         => __( 'Search your bookings', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'Showing %1$s–%2$s of %3$s'                    => __( 'Showing %1$s–%2$s of %3$s', 'ferry-booking-manager' ),
			'Ticket'                                       => __( 'Ticket', 'ferry-booking-manager' ),
			'Try a different reference, route or port, or clear the filters.' => __( 'Try a different reference, route or port, or clear the filters.', 'ferry-booking-manager' ),
			'Upcoming'                                     => __( 'Upcoming', 'ferry-booking-manager' ),
			'Which bookings'                               => __( 'Which bookings', 'ferry-booking-manager' ),
			'return'                                       => __( 'return', 'ferry-booking-manager' ),
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
