<?php
/**
 * Asset loader.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Admin\AppRenderer;
use FBM\Security\Permissions;
use FBM\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues plugin assets.
 *
 * Nothing is enqueued globally. Admin assets load only on the Ferry Manager
 * screen; front-end assets load only on pages that actually render a Ferry
 * Booking Manager component.
 */
final class Assets {

	/**
	 * Handle of the runtime bootstrap script that carries the app configuration.
	 */
	public const ADMIN_RUNTIME_HANDLE = 'fbm-admin-runtime';

	/**
	 * Handle prefix used for the exported Next.js chunks.
	 */
	private const ADMIN_CHUNK_HANDLE = 'fbm-admin-chunk-';

	/**
	 * Application renderer.
	 *
	 * @var AppRenderer
	 */
	private AppRenderer $renderer;

	/**
	 * Permission service.
	 *
	 * @var Permissions
	 */
	private Permissions $permissions;

	/**
	 * Constructor.
	 *
	 * @param AppRenderer $renderer    Application renderer.
	 * @param Permissions $permissions Permission service.
	 */
	public function __construct( AppRenderer $renderer, Permissions $permissions ) {
		$this->renderer    = $renderer;
		$this->permissions = $permissions;
	}

	/**
	 * Returns a cache-busting version for a hand-written asset.
	 *
	 * The exported bundle carries a content hash in its filename, but these two
	 * files do not: they keep one URL for the life of the plugin. Versioning
	 * them by FBM_VERSION means an edit that does not also bump the plugin
	 * version is never fetched again — the browser keeps serving what it already
	 * has, and a fixed stylesheet looks like a fix that did not work. The file's
	 * own modification time changes whenever its contents do, which is exactly
	 * the question the browser is asking.
	 *
	 * @param string $relative Path relative to the plugin directory.
	 * @return string
	 */
	private static function asset_version( string $relative ): string {
		$path     = FBM_PATH . ltrim( $relative, '/' );
		$modified = is_readable( $path ) ? filemtime( $path ) : false;

		return false === $modified ? FBM_VERSION : FBM_VERSION . '.' . (string) $modified;
	}

	/**
	 * Enqueues the dashboard assets.
	 *
	 * Chunks are chained through their dependency list so that WordPress prints
	 * them in the exact order the exporter recorded, and so that the runtime
	 * configuration is always available before the first chunk executes.
	 *
	 * @return void
	 */
	public function enqueue_admin_app(): void {
		wp_enqueue_style(
			'fbm-admin',
			FBM_URL . 'assets/admin/css/fbm-admin.css',
			array(),
			self::asset_version( 'assets/admin/css/fbm-admin.css' )
		);

		/*
		 * Exported filenames are content hashed, so no cache-busting query is
		 * needed. Leaving it off also matters functionally: the bundler matches
		 * already-present assets by exact URL when it decides whether a chunk
		 * still has to be fetched.
		 */
		foreach ( $this->renderer->styles() as $index => $url ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Filenames are content hashed; a version query would stop the bundler matching already-loaded chunks.
			wp_enqueue_style( 'fbm-admin-app-' . $index, $url, array( 'fbm-admin' ), null );
		}

		wp_enqueue_script(
			self::ADMIN_RUNTIME_HANDLE,
			FBM_URL . 'assets/admin/js/fbm-admin-runtime.js',
			array(),
			self::asset_version( 'assets/admin/js/fbm-admin-runtime.js' ),
			true
		);

		wp_add_inline_script(
			self::ADMIN_RUNTIME_HANDLE,
			'window.fbmAdmin = ' . wp_json_encode( $this->admin_config() ) . ';',
			'before'
		);

		$previous = self::ADMIN_RUNTIME_HANDLE;

		foreach ( $this->renderer->scripts() as $index => $url ) {
			$handle = self::ADMIN_CHUNK_HANDLE . $index;

			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Filenames are content hashed; a version query would stop the bundler matching already-loaded chunks.
			wp_enqueue_script( $handle, $url, array( $previous ), null, true );

			$previous = $handle;
		}
	}

	/**
	 * Builds the configuration object handed to the dashboard application.
	 *
	 * Contains no secrets: the REST nonce is scoped to the signed-in user and is
	 * the same value WordPress already exposes to every admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public function admin_config(): array {
		$config = array(
			'version'      => FBM_VERSION,
			'restUrl'      => esc_url_raw( trailingslashit( rest_url( 'fbm/v1' ) ) ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl'     => esc_url_raw( admin_url() ),
			'pageUrl'      => esc_url_raw( admin_url( 'admin.php?page=fbm-dashboard' ) ),
			'assetUrl'     => esc_url_raw( trailingslashit( FBM_URL . 'assets/' ) ),
			'chunkBase'    => esc_url_raw( trailingslashit( $this->renderer->asset_prefix() ) . '_next/' ),
			'homeUrl'      => esc_url_raw( home_url( '/' ) ),
			'capabilities' => $this->permissions->current_user_capabilities(),
			'user'         => $this->current_user(),
			'locale'       => str_replace( '_', '-', determine_locale() ),
			'isRtl'        => is_rtl(),
			'timezone'     => wp_timezone_string(),
			'dateFormat'   => (string) get_option( 'date_format', 'Y-m-d' ),
			'timeFormat'   => (string) get_option( 'time_format', 'H:i' ),
			'startOfWeek'  => (int) get_option( 'start_of_week', 1 ),
			'currency'     => $this->currency(),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'proActive'    => (bool) apply_filters( 'fbm_pro_active', false ),
			'i18n'         => $this->translations(),
		);

		/**
		 * Filters the configuration object exposed to the admin application.
		 *
		 * Never add secrets here: the value is printed into the admin page.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config Admin configuration.
		 */
		return (array) apply_filters( 'fbm_admin_config', $config );
	}

	/**
	 * Returns a minimal description of the signed-in user.
	 *
	 * @return array<string, mixed>
	 */
	private function current_user(): array {
		$user = wp_get_current_user();

		return array(
			'id'     => (int) $user->ID,
			'name'   => (string) $user->display_name,
			'email'  => (string) $user->user_email,
			'avatar' => esc_url_raw( (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ) ),
		);
	}

	/**
	 * Returns the currency display settings.
	 *
	 * Resolved by the shared money helper so the dashboard, the booking form,
	 * tickets and emails cannot disagree about how a price is written.
	 *
	 * @return array<string, mixed>
	 */
	private function currency(): array {
		return Money::currency();
	}

	/**
	 * Returns the JavaScript translation dictionary.
	 *
	 * Strings are translated in PHP and looked up by their English source text,
	 * which keeps every user-facing string inside the plugin's .pot file.
	 *
	 * @return array<string, string>
	 */
	private function translations(): array {
		$strings = array(
			'Dashboard'                                    => __( 'Dashboard', 'ferry-booking-manager' ),
			'Bookings'                                     => __( 'Bookings', 'ferry-booking-manager' ),
			'Balance due'                                  => __( 'Balance due', 'ferry-booking-manager' ),
			'The fare could not be worked out.'            => __( 'The fare could not be worked out.', 'ferry-booking-manager' ),
			'Working out the fare…'                        => __( 'Working out the fare…', 'ferry-booking-manager' ),
			'A booking needs a name and an email to send the confirmation to.' => __( 'A booking needs a name and an email to send the confirmation to.', 'ferry-booking-manager' ),
			'Add at least one passenger or vehicle.'       => __( 'Add at least one passenger or vehicle.', 'ferry-booking-manager' ),
			'How it was paid for, and anything staff need to record against it.' => __( 'How it was paid for, and anything staff need to record against it.', 'ferry-booking-manager' ),
			'How many of each, then a name for every one of them. A manifest is a named list, so the details below are what makes it one.' => __( 'How many of each, then a name for every one of them. A manifest is a named list, so the details below are what makes it one.', 'ferry-booking-manager' ),
			'No vehicle types are set up.'                 => __( 'No vehicle types are set up.', 'ferry-booking-manager' ),
			'Nobody added yet.'                            => __( 'Nobody added yet.', 'ferry-booking-manager' ),
			/* translators: %s: a passenger or vehicle type name. */
			'One fewer %s'                                 => __( 'One fewer %s', 'ferry-booking-manager' ),
			/* translators: %s: a passenger or vehicle type name. */
			'One more %s'                                  => __( 'One more %s', 'ferry-booking-manager' ),
			'Pick the sailing they are travelling on.'     => __( 'Pick the sailing they are travelling on.', 'ferry-booking-manager' ),
			'Search for a returning customer, or type the details of a new one. The confirmation goes to this address.' => __( 'Search for a returning customer, or type the details of a new one. The confirmation goes to this address.', 'ferry-booking-manager' ),
			'Where they are going and when. Pick the departure they are actually travelling on — the fare and the deck space both come from it.' => __( 'Where they are going and when. Pick the departure they are actually travelling on — the fare and the deck space both come from it.', 'ferry-booking-manager' ),
			'Field type'                                   => __( 'Field type', 'ferry-booking-manager' ),
			'Nothing to choose from.'                      => __( 'Nothing to choose from.', 'ferry-booking-manager' ),
			/* translators: 1: how many sailings were created, 2: the route name. */
			'%1$s sailings scheduled for %2$s.'            => __( '%1$s sailings scheduled for %2$s.', 'ferry-booking-manager' ),
			/* translators: %s: the name of something the wizard is about to create. */
			'%s (new)'                                     => __( '%s (new)', 'ferry-booking-manager' ),
			/* translators: %s: a number of minutes. */
			'%s minutes'                                   => __( '%s minutes', 'ferry-booking-manager' ),
			/* translators: %s: how many ports exist. */
			'%s ports'                                     => __( '%s ports', 'ferry-booking-manager' ),
			/* translators: %s: how many routes exist. */
			'%s routes'                                    => __( '%s routes', 'ferry-booking-manager' ),
			/* translators: %s: how many vessels exist. */
			'%s vessels'                                   => __( '%s vessels', 'ferry-booking-manager' ),
			/* translators: %s: a percentage of the base fare. */
			'%s%% of the base fare'                        => __( '%s%% of the base fare', 'ferry-booking-manager' ),
			'A crossing needs five things, and it needs them in order: two ports, a vessel, a route joining them, a timetable, and a fare. Set them up together and the booking form has something to find.' => __( 'A crossing needs five things, and it needs them in order: two ports, a vessel, a route joining them, a timetable, and a fare. Set them up together and the booking form has something to find.', 'ferry-booking-manager' ),
			'A crossing needs two different ports.'        => __( 'A crossing needs two different ports.', 'ferry-booking-manager' ),
			'A crossing takes at least a minute.'          => __( 'A crossing takes at least a minute.', 'ferry-booking-manager' ),
			'A vessel has to carry somebody.'              => __( 'A vessel has to carry somebody.', 'ferry-booking-manager' ),
			'Add a new port…'                              => __( 'Add a new port…', 'ferry-booking-manager' ),
			'Add a new vessel…'                            => __( 'Add a new vessel…', 'ferry-booking-manager' ),
			'Add another crossing'                         => __( 'Add another crossing', 'ferry-booking-manager' ),
			'Add at least one departure time.'             => __( 'Add at least one departure time.', 'ferry-booking-manager' ),
			'Also create the return direction'             => __( 'Also create the return direction', 'ferry-booking-manager' ),
			'Both directions'                              => __( 'Both directions', 'ferry-booking-manager' ),
			'Carries vehicles'                             => __( 'Carries vehicles', 'ferry-booking-manager' ),
			'Choose at least one day.'                     => __( 'Choose at least one day.', 'ferry-booking-manager' ),
			'Create it all'                                => __( 'Create it all', 'ferry-booking-manager' ),
			'Creates the mirror route and its own timetable, so return journeys can be sold.' => __( 'Creates the mirror route and its own timetable, so return journeys can be sold.', 'ferry-booking-manager' ),
			'Crossing time'                                => __( 'Crossing time', 'ferry-booking-manager' ),
			'Days it runs'                                 => __( 'Days it runs', 'ferry-booking-manager' ),
			'Departures a week'                            => __( 'Departures a week', 'ferry-booking-manager' ),
			'Everything a crossing needs before it can be sold, in one place.' => __( 'Everything a crossing needs before it can be sold, in one place.', 'ferry-booking-manager' ),
			'First day'                                    => __( 'First day', 'ferry-booking-manager' ),
			'Fleet & schedule'                             => __( 'Fleet & schedule', 'ferry-booking-manager' ),
			'Fleet and schedule'                           => __( 'Fleet and schedule', 'ferry-booking-manager' ),
			'Foot passengers only'                         => __( 'Foot passengers only', 'ferry-booking-manager' ),
			'Last day'                                     => __( 'Last day', 'ferry-booking-manager' ),
			/* translators: %s: the route name. */
			'No departures fitted the day for %s.'         => __( 'No departures fitted the day for %s.', 'ferry-booking-manager' ),
			'No ports yet'                                 => __( 'No ports yet', 'ferry-booking-manager' ),
			'No routes yet'                                => __( 'No routes yet', 'ferry-booking-manager' ),
			'No vessels yet'                               => __( 'No vessels yet', 'ferry-booking-manager' ),
			'Nothing has been written yet. This is what Create will make.' => __( 'Nothing has been written yet. This is what Create will make.', 'ferry-booking-manager' ),
			'Nothing is on sale yet'                       => __( 'Nothing is on sale yet', 'ferry-booking-manager' ),
			'One direction'                                => __( 'One direction', 'ferry-booking-manager' ),
			'Optional. Leave blank and the route is known by its name.' => __( 'Optional. Leave blank and the route is known by its name.', 'ferry-booking-manager' ),
			'Outbound times. The return leg is offset automatically so the vessel is never in two places at once.' => __( 'Outbound times. The return leg is offset automatically so the vessel is never in two places at once.', 'ferry-booking-manager' ),
			'Passenger fares'                              => __( 'Passenger fares', 'ferry-booking-manager' ),
			/* translators: %s: the port name. */
			'Port “%s” created.'                           => __( 'Port “%s” created.', 'ferry-booking-manager' ),
			'Ports, vessel, route, timetable and fares — asked once, written together.' => __( 'Ports, vessel, route, timetable and fares — asked once, written together.', 'ferry-booking-manager' ),
			/* translators: %s: the route name. */
			'Route “%s” created, with its fares.'          => __( 'Route “%s” created, with its fares.', 'ferry-booking-manager' ),
			'Runs'                                         => __( 'Runs', 'ferry-booking-manager' ),
			'Set up a crossing'                            => __( 'Set up a crossing', 'ferry-booking-manager' ),
			'Setting up…'                                  => __( 'Setting up…', 'ferry-booking-manager' ),
			'Shown on tickets and manifests.'              => __( 'Shown on tickets and manifests.', 'ferry-booking-manager' ),
			'The boat that works this crossing. Its capacities are what the crossing sells against, so a sailing can never be sold beyond the deck it has.' => __( 'The boat that works this crossing. Its capacities are what the crossing sells against, so a sailing can never be sold beyond the deck it has.', 'ferry-booking-manager' ),
			'The crossing is set up and on sale.'          => __( 'The crossing is set up and on sale.', 'ferry-booking-manager' ),
			'The journey itself. A return leg is a separate route on the opposite ports, so an operator who only declares one direction cannot sell a return at all — which is why it is offered here.' => __( 'The journey itself. A return leg is a separate route on the opposite ports, so an operator who only declares one direction cannot sell a return at all — which is why it is offered here.', 'ferry-booking-manager' ),
			'The last day cannot be before the first.'     => __( 'The last day cannot be before the first.', 'ferry-booking-manager' ),
			'The length of deck available. Long vehicles are sold against this, not against a headcount.' => __( 'The length of deck available. Long vehicles are sold against this, not against a headcount.', 'ferry-booking-manager' ),
			/* translators: %s: total minutes after the outbound departure. */
			'The return leaves this long after the vessel arrives, so %s minutes after each outbound departure.' => __( 'The return leaves this long after the vessel arrives, so %s minutes after each outbound departure.', 'ferry-booking-manager' ),
			'The wizard asks for the ports, the vessel, the route, the timetable and the fares once, then writes them together. The tabs above edit any of it afterwards.' => __( 'The wizard asks for the ports, the vessel, the route, the timetable and the fares once, then writes them together. The tabs above edit any of it afterwards.', 'ferry-booking-manager' ),
			'This crossing carries vehicles'               => __( 'This crossing carries vehicles', 'ferry-booking-manager' ),
			'Turn off for a foot-passenger crossing. Vehicle fares and deck space are then not asked for.' => __( 'Turn off for a foot-passenger crossing. Vehicle fares and deck space are then not asked for.', 'ferry-booking-manager' ),
			'Turnaround'                                   => __( 'Turnaround', 'ferry-booking-manager' ),
			'Vehicle fares'                                => __( 'Vehicle fares', 'ferry-booking-manager' ),
			/* translators: %s: the vessel name. */
			'Vessel “%s” created.'                         => __( 'Vessel “%s” created.', 'ferry-booking-manager' ),
			'What a ticket costs on this crossing. Leave a fare blank to charge the type’s own price; a percentage type works itself out from the base fare.' => __( 'What a ticket costs on this crossing. Leave a fare blank to charge the type’s own price; a percentage type works itself out from the base fare.', 'ferry-booking-manager' ),
			'When it sails. Every combination of a day and a time below becomes a sailing that can be booked.' => __( 'When it sails. Every combination of a day and a time below becomes a sailing that can be booked.', 'ferry-booking-manager' ),
			'Where the crossing runs between. Pick a terminal you already have, or describe a new one and it is created with everything else.' => __( 'Where the crossing runs between. Pick a terminal you already have, or describe a new one and it is created with everything else.', 'ferry-booking-manager' ),
			'Money'                                        => __( 'Money', 'ferry-booking-manager' ),
			'Operations'                                   => __( 'Operations', 'ferry-booking-manager' ),
			'System'                                       => __( 'System', 'ferry-booking-manager' ),
			'What you sell'                                => __( 'What you sell', 'ferry-booking-manager' ),
			'Lane metre override'                          => __( 'Lane metre override', 'ferry-booking-manager' ),
			'Leave at 0 to use the vessel’s own lane metres.' => __( 'Leave at 0 to use the vessel’s own lane metres.', 'ferry-booking-manager' ),
			'At booking'                                   => __( 'At booking', 'ferry-booking-manager' ),
			'Back'                                         => __( 'Back', 'ferry-booking-manager' ),
			'Deck space'                                   => __( 'Deck space', 'ferry-booking-manager' ),
			'Fare on each route'                           => __( 'Fare on each route', 'ferry-booking-manager' ),
			'Fares'                                        => __( 'Fares', 'ferry-booking-manager' ),
			'Form steps'                                   => __( 'Form steps', 'ferry-booking-manager' ),
			'The fare this type charges by default, and what it charges on each route instead.' => __( 'The fare this type charges by default, and what it charges on each route instead.', 'ferry-booking-manager' ),
			'The vehicle'                                  => __( 'The vehicle', 'ferry-booking-manager' ),
			'There are no routes yet. Add one and its fare for this vehicle can be set here.' => __( 'There are no routes yet. Add one and its fare for this vehicle can be set here.', 'ferry-booking-manager' ),
			'This cannot be empty.'                        => __( 'This cannot be empty.', 'ferry-booking-manager' ),
			'What a customer has to tell you when they bring one of these aboard.' => __( 'What a customer has to tell you when they bring one of these aboard.', 'ferry-booking-manager' ),
			'What one of these takes off a vessel. Availability is measured against these, so a vessel with 40 lane metres sells out on the numbers here, not on a headcount.' => __( 'What one of these takes off a vessel. Availability is measured against these, so a vessel with 40 lane metres sells out on the numbers here, not on a headcount.', 'ferry-booking-manager' ),
			'What this class of vehicle is called, and where it sits in your list.' => __( 'What this class of vehicle is called, and where it sits in your list.', 'ferry-booking-manager' ),
			/* translators: %s: the vehicle type’s own formatted fare. */
			'Leave a route blank to charge this type’s own fare of %s. A route that carries no vehicles is not listed.' => __( 'Leave a route blank to charge this type’s own fare of %s. A route that carries no vehicles is not listed.', 'ferry-booking-manager' ),
			/* translators: %s: comma-separated list of route names. */
			'Saved, but the fare could not be set on: %s. Set it on the Pricing screen.' => __( 'Saved, but the fare could not be set on: %s. Set it on the Pricing screen.', 'ferry-booking-manager' ),
			/* translators: 1: the current step number, 2: how many steps there are. */
			'Step %1$s of %2$s'                            => __( 'Step %1$s of %2$s', 'ferry-booking-manager' ),
			'Export CSV'                                   => __( 'Export CSV', 'ferry-booking-manager' ),
			/* translators: %s: formatted amount still outstanding on the booking. */
			'Left to pay: %s'                              => __( 'Left to pay: %s', 'ferry-booking-manager' ),
			/* translators: 1: the crossing the booking was on, 2: the crossing it moved to. */
			'Moved: %1$s → %2$s'                           => __( 'Moved: %1$s → %2$s', 'ferry-booking-manager' ),
			/* translators: 1: the party the booking had, 2: the party it has now. */
			'Party: %1$s → %2$s'                           => __( 'Party: %1$s → %2$s', 'ferry-booking-manager' ),
			/* translators: %s: formatted amount owed back to the customer. */
			'Refund owed: %s'                              => __( 'Refund owed: %s', 'ferry-booking-manager' ),
			'The customer was not told.'                   => __( 'The customer was not told.', 'ferry-booking-manager' ),
			'The customer was told.'                       => __( 'The customer was told.', 'ferry-booking-manager' ),
			/* translators: %s: name of the member of staff who made the change. */
			'by %s'                                        => __( 'by %s', 'ferry-booking-manager' ),
			'Apply change'                                 => __( 'Apply change', 'ferry-booking-manager' ),
			'Booking updated.'                             => __( 'Booking updated.', 'ferry-booking-manager' ),
			/* translators: %s: formatted new total for the booking. */
			'Booking updated. New total %s.'               => __( 'Booking updated. New total %s.', 'ferry-booking-manager' ),
			'Change the party or pick another crossing to see what it comes to.' => __( 'Change the party or pick another crossing to see what it comes to.', 'ferry-booking-manager' ),
			'Kept on the booking’s history'                => __( 'Kept on the booking’s history', 'ferry-booking-manager' ),
			'Move to another crossing'                     => __( 'Move to another crossing', 'ferry-booking-manager' ),
			'New total'                                    => __( 'New total', 'ferry-booking-manager' ),
			'Tell the customer'                            => __( 'Tell the customer', 'ferry-booking-manager' ),
			/* translators: %s: formatted amount by which the booking got cheaper. */
			'The booking is %s cheaper. Record the refund on the Refund tab.' => __( 'The booking is %s cheaper. Record the refund on the Refund tab.', 'ferry-booking-manager' ),
			/* translators: %s: formatted amount the customer still owes. */
			'The customer owes a further %s.'              => __( 'The customer owes a further %s.', 'ferry-booking-manager' ),
			'The money'                                    => __( 'The money', 'ferry-booking-manager' ),
			'The new crossing is checked for room before the change is written. Leave off to keep the booking where it is.' => __( 'The new crossing is checked for room before the change is written. Leave off to keep the booking where it is.', 'ferry-booking-manager' ),
			'The price is unchanged.'                      => __( 'The price is unchanged.', 'ferry-booking-manager' ),
			'Turn off to correct a booking quietly. Nothing is sent unless an automation is set up for a changed booking.' => __( 'Turn off to correct a booking quietly. Nothing is sent unless an automation is set up for a changed booking.', 'ferry-booking-manager' ),
			'Was'                                          => __( 'Was', 'ferry-booking-manager' ),
			'No details were captured.'                    => __( 'No details were captured.', 'ferry-booking-manager' ),
			'No passenger details were captured for this booking.' => __( 'No passenger details were captured for this booking.', 'ferry-booking-manager' ),
			'Refund due'                                   => __( 'Refund due', 'ferry-booking-manager' ),
			'Unknown type'                                 => __( 'Unknown type', 'ferry-booking-manager' ),
			'Calendar'                                     => __( 'Calendar', 'ferry-booking-manager' ),
			'Sailings'                                     => __( 'Sailings', 'ferry-booking-manager' ),
			'Routes'                                       => __( 'Routes', 'ferry-booking-manager' ),
			'Ports'                                        => __( 'Ports', 'ferry-booking-manager' ),
			'Vessels'                                      => __( 'Vessels', 'ferry-booking-manager' ),
			'Pricing'                                      => __( 'Pricing', 'ferry-booking-manager' ),
			'Passengers'                                   => __( 'Passengers', 'ferry-booking-manager' ),
			'Vehicles'                                     => __( 'Vehicles', 'ferry-booking-manager' ),
			'Check-In'                                     => __( 'Check-In', 'ferry-booking-manager' ),
			'Manifests'                                    => __( 'Manifests', 'ferry-booking-manager' ),
			'Agents'                                       => __( 'Agents', 'ferry-booking-manager' ),
			/* translators: 1: the event name, 2: the HTTP status returned. */
			'%1$s accepted (%2$s)'                         => __( '%1$s accepted (%2$s)', 'ferry-booking-manager' ),
			/* translators: 1: the event name, 2: the HTTP status returned. */
			'%1$s was not accepted (%2$s)'                 => __( '%1$s was not accepted (%2$s)', 'ferry-booking-manager' ),
			/* translators: %s: number of rows written. */
			'%s rows imported.'                            => __( '%s rows imported.', 'ferry-booking-manager' ),
			/* translators: %s: comma-separated list of column names. */
			'Columns: %s'                                  => __( 'Columns: %s', 'ferry-booking-manager' ),
			/* translators: %s: number of rows in the file. */
			'Import %s rows'                               => __( 'Import %s rows', 'ferry-booking-manager' ),
			'Add an endpoint'                              => __( 'Add an endpoint', 'ferry-booking-manager' ),
			'Check the file'                               => __( 'Check the file', 'ferry-booking-manager' ),
			'Choose a CSV file'                            => __( 'Choose a CSV file', 'ferry-booking-manager' ),
			'Each event is posted as JSON shortly after it happens, never during the request that caused it — so a slow endpoint can never delay a customer.' => __( 'Each event is posted as JSON shortly after it happens, never during the request that caused it — so a slow endpoint can never delay a customer.', 'ferry-booking-manager' ),
			'Every request carries an X-FBM-Signature header: an HMAC-SHA256 of the exact body, using this secret. Recompute it at your end and compare — if it matches, the call is genuine and nothing was altered on the way.' => __( 'Every request carries an X-FBM-Signature header: an HMAC-SHA256 of the exact body, using this secret. Recompute it at your end and compare — if it matches, the call is genuine and nothing was altered on the way.', 'ferry-booking-manager' ),
			'Export'                                       => __( 'Export', 'ferry-booking-manager' ),
			'Getting data out of here, and into here.'     => __( 'Getting data out of here, and into here.', 'ferry-booking-manager' ),
			'Hide'                                         => __( 'Hide', 'ferry-booking-manager' ),
			'Import'                                       => __( 'Import', 'ferry-booking-manager' ),
			'Integrations'                                 => __( 'Integrations', 'ferry-booking-manager' ),
			'Must start with http:// or https://. Anything else is discarded when you save.' => __( 'Must start with http:// or https://. Anything else is discarded when you save.', 'ferry-booking-manager' ),
			'No endpoints yet'                             => __( 'No endpoints yet', 'ferry-booking-manager' ),
			'Nothing is sent anywhere until you add one.'  => __( 'Nothing is sent anywhere until you add one.', 'ferry-booking-manager' ),
			'Nothing was imported. Fix these and try again:' => __( 'Nothing was imported. Fix these and try again:', 'ferry-booking-manager' ),
			'Recent deliveries'                            => __( 'Recent deliveries', 'ferry-booking-manager' ),
			'Remove this endpoint'                         => __( 'Remove this endpoint', 'ferry-booking-manager' ),
			'Rows in the file'                             => __( 'Rows in the file', 'ferry-booking-manager' ),
			'Rows with an id update that record; rows without one create a new record. Check it first — nothing is written until you say so, and a single bad row stops the whole file rather than leaving half of it applied.' => __( 'Rows with an id update that record; rows without one create a new record. Check it first — nothing is written until you say so, and a single bad row stops the whole file rather than leaving half of it applied.', 'ferry-booking-manager' ),
			'Send booking events to another system as they happen, and move your timetable in and out as a spreadsheet.' => __( 'Send booking events to another system as they happen, and move your timetable in and out as a spreadsheet.', 'ferry-booking-manager' ),
			'Send these events'                            => __( 'Send these events', 'ferry-booking-manager' ),
			'Sending'                                      => __( 'Sending', 'ferry-booking-manager' ),
			'Show'                                         => __( 'Show', 'ferry-booking-manager' ),
			'Take a copy before you change anything, or edit a season of sailings in a spreadsheet and bring it back.' => __( 'Take a copy before you change anything, or edit a season of sailings in a spreadsheet and bring it back.', 'ferry-booking-manager' ),
			'Turn off to stop sending without losing the setup.' => __( 'Turn off to stop sending without losing the setup.', 'ferry-booking-manager' ),
			'Webhook signing secret'                       => __( 'Webhook signing secret', 'ferry-booking-manager' ),
			'Webhooks'                                     => __( 'Webhooks', 'ferry-booking-manager' ),
			'Webhooks saved.'                              => __( 'Webhooks saved.', 'ferry-booking-manager' ),
			'What to move'                                 => __( 'What to move', 'ferry-booking-manager' ),
			'Where events are sent'                        => __( 'Where events are sent', 'ferry-booking-manager' ),
			'Would create'                                 => __( 'Would create', 'ferry-booking-manager' ),
			'Would update'                                 => __( 'Would update', 'ferry-booking-manager' ),
			'Import and export'                            => __( 'Import and export', 'ferry-booking-manager' ),
			'Verifying a call came from here'              => __( 'Verifying a call came from here', 'ferry-booking-manager' ),
			/* translators: 1: number of berths, 2: number of rooms, 3: revenue when full. */
			'%1$s berths across %2$s rooms, earning %3$s.' => __( '%1$s berths across %2$s rooms, earning %3$s.', 'ferry-booking-manager' ),
			/* translators: 1: number of rooms, 2: berths in each. */
			'%1$s rooms of %2$s berths'                    => __( '%1$s rooms of %2$s berths', 'ferry-booking-manager' ),
			/* translators: 1: number of rooms, 2: total berths, 3: revenue when full. */
			'%1$s rooms sleeping up to %2$s people, earning %3$s.' => __( '%1$s rooms sleeping up to %2$s people, earning %3$s.', 'ferry-booking-manager' ),
			/* translators: 1: the price, 2: how it is sold, 3: how many exist. */
			'%1$s, %2$s — %3$s'                            => __( '%1$s, %2$s — %3$s', 'ferry-booking-manager' ),
			/* translators: %s: number of rooms. */
			'%s rooms'                                     => __( '%s rooms', 'ferry-booking-manager' ),
			'Add a cabin class'                            => __( 'Add a cabin class', 'ferry-booking-manager' ),
			'Add one to start selling overnight accommodation.' => __( 'Add one to start selling overnight accommodation.', 'ferry-booking-manager' ),
			'Berths in each room'                          => __( 'Berths in each room', 'ferry-booking-manager' ),
			'Cabins'                                       => __( 'Cabins', 'ferry-booking-manager' ),
			'Cabins saved.'                                => __( 'Cabins saved.', 'ferry-booking-manager' ),
			'Customers only see it once this is on and there is at least one room.' => __( 'Customers only see it once this is on and there is at least one room.', 'ferry-booking-manager' ),
			'Delete this class'                            => __( 'Delete this class', 'ferry-booking-manager' ),
			'Each class has its own stock, so one selling out never affects another.' => __( 'Each class has its own stock, so one selling out never affects another.', 'ferry-booking-manager' ),
			'How many people sleep in one room.'           => __( 'How many people sleep in one room.', 'ferry-booking-manager' ),
			'How many separate rooms the vessel has.'      => __( 'How many separate rooms the vessel has.', 'ferry-booking-manager' ),
			'New cabin class'                              => __( 'New cabin class', 'ferry-booking-manager' ),
			'No cabin classes yet'                         => __( 'No cabin classes yet', 'ferry-booking-manager' ),
			'None on board'                                => __( 'None on board', 'ferry-booking-manager' ),
			'Nothing, until you say how many rooms the vessel has.' => __( 'Nothing, until you say how many rooms the vessel has.', 'ferry-booking-manager' ),
			'On a full sailing'                            => __( 'On a full sailing', 'ferry-booking-manager' ),
			'Price per berth'                              => __( 'Price per berth', 'ferry-booking-manager' ),
			'Price per room'                               => __( 'Price per room', 'ferry-booking-manager' ),
			'Rooms of this class on board'                 => __( 'Rooms of this class on board', 'ferry-booking-manager' ),
			'Sell cabins and berths with their own stock, so the last family cabin selling out does not close the inside doubles.' => __( 'Sell cabins and berths with their own stock, so the last family cabin selling out does not close the inside doubles.', 'ferry-booking-manager' ),
			'Sold'                                         => __( 'Sold', 'ferry-booking-manager' ),
			'What the customer sees when choosing.'        => __( 'What the customer sees when choosing.', 'ferry-booking-manager' ),
			/* translators: %s: the highest daily revenue in the range. */
			'Peak %s'                                      => __( 'Peak %s', 'ferry-booking-manager' ),
			/* translators: %s: number of bookings actually read. */
			'This range holds more bookings than one report reads. The figures below cover the first %s and are a floor, not a total — narrow the dates for an exact answer.' => __( 'This range holds more bookings than one report reads. The figures below cover the first %s and are a floor, not a total — narrow the dates for an exact answer.', 'ferry-booking-manager' ),
			'Awaiting payment'                             => __( 'Awaiting payment', 'ferry-booking-manager' ),
			'By the day the booking was taken, not the day it sails.' => __( 'By the day the booking was taken, not the day it sails.', 'ferry-booking-manager' ),
			'Collected'                                    => __( 'Collected', 'ferry-booking-manager' ),
			'Every route'                                  => __( 'Every route', 'ferry-booking-manager' ),
			'Fare types'                                   => __( 'Fare types', 'ferry-booking-manager' ),
			'Nothing to show for this range.'              => __( 'Nothing to show for this range.', 'ferry-booking-manager' ),
			'Nothing was sold in this range.'              => __( 'Nothing was sold in this range.', 'ferry-booking-manager' ),
			'Revenue'                                      => __( 'Revenue', 'ferry-booking-manager' ),
			'Revenue by day'                               => __( 'Revenue by day', 'ferry-booking-manager' ),
			'Revenue over time, and where it came from: by route, vessel, sales channel, payment method, fare type and extra.' => __( 'Revenue over time, and where it came from: by route, vessel, sales channel, payment method, fare type and extra.', 'ferry-booking-manager' ),
			'Sales channel'                                => __( 'Sales channel', 'ferry-booking-manager' ),
			'What you sold, and where it came from.'       => __( 'What you sold, and where it came from.', 'ferry-booking-manager' ),
			'Last 7 days'                                  => __( 'Last 7 days', 'ferry-booking-manager' ),
			'Last 30 days'                                 => __( 'Last 30 days', 'ferry-booking-manager' ),
			'Last 90 days'                                 => __( 'Last 90 days', 'ferry-booking-manager' ),
			'Vessel types'                                 => __( 'Vessel types', 'ferry-booking-manager' ),
			'Vehicle types'                                => __( 'Vehicle types', 'ferry-booking-manager' ),
			/* translators: 1: the previous total, 2: the new total. */
			'Total changed from %1$s to %2$s'              => __( 'Total changed from %1$s to %2$s', 'ferry-booking-manager' ),
			'Already refunded'                             => __( 'Already refunded', 'ferry-booking-manager' ),
			'Customer gets back'                           => __( 'Customer gets back', 'ferry-booking-manager' ),
			'Fee'                                          => __( 'Fee', 'ferry-booking-manager' ),
			'Kept with the refund record'                  => __( 'Kept with the refund record', 'ferry-booking-manager' ),
			'Leave off to cancel the whole booking. Turn it on to refund one cabin, one passenger or one extra.' => __( 'Leave off to cancel the whole booking. Turn it on to refund one cabin, one passenger or one extra.', 'ferry-booking-manager' ),
			'Not being cancelled'                          => __( 'Not being cancelled', 'ferry-booking-manager' ),
			'Nothing has changed on this booking since it was made.' => __( 'Nothing has changed on this booking since it was made.', 'ferry-booking-manager' ),
			'Only part of the booking'                     => __( 'Only part of the booking', 'ferry-booking-manager' ),
			'Penalty'                                      => __( 'Penalty', 'ferry-booking-manager' ),
			'Reason'                                       => __( 'Reason', 'ferry-booking-manager' ),
			'Record a refund of'                           => __( 'Record a refund of', 'ferry-booking-manager' ),
			'Recording…'                                   => __( 'Recording…', 'ferry-booking-manager' ),
			'Refund recorded:'                             => __( 'Refund recorded:', 'ferry-booking-manager' ),
			'The booking stays on the crossing. Its places are not released.' => __( 'The booking stays on the crossing. Its places are not released.', 'ferry-booking-manager' ),
			'This refunds everything paid, so the booking is cancelled and its places go back on sale.' => __( 'This refunds everything paid, so the booking is cancelled and its places go back on sale.', 'ferry-booking-manager' ),
			'Value being cancelled'                        => __( 'Value being cancelled', 'ferry-booking-manager' ),
			'What the cancelled part was worth. The penalty applies to this, not to the whole booking.' => __( 'What the cancelled part was worth. The penalty applies to this, not to the whole booking.', 'ferry-booking-manager' ),
			'Use the cancellation policy'                  => __( 'Use the cancellation policy', 'ferry-booking-manager' ),
			'No penalty'                                   => __( 'No penalty', 'ferry-booking-manager' ),
			'A percentage of what is cancelled'            => __( 'A percentage of what is cancelled', 'ferry-booking-manager' ),
			'A fixed fee'                                  => __( 'A fixed fee', 'ferry-booking-manager' ),
			'Details'                                      => __( 'Details', 'ferry-booking-manager' ),
			'Refund'                                       => __( 'Refund', 'ferry-booking-manager' ),
			'History'                                      => __( 'History', 'ferry-booking-manager' ),
			/* translators: %s: the amount charged. */
			'A booking pays %s.'                           => __( 'A booking pays %s.', 'ferry-booking-manager' ),
			/* translators: 1: the total charged, 2: the amount per leg. */
			'A return booking pays %1$s — %2$s each way.'  => __( 'A return booking pays %1$s — %2$s each way.', 'ferry-booking-manager' ),
			/* translators: 1: the price, 2: how it is charged. */
			'%1$s, %2$s'                                   => __( '%1$s, %2$s', 'ferry-booking-manager' ),
			/* translators: %s: how many of the extra were added. */
			'%s added'                                     => __( '%s added', 'ferry-booking-manager' ),
			/* translators: %s: number of passengers. */
			'%s passengers'                                => __( '%s passengers', 'ferry-booking-manager' ),
			/* translators: %s: number of vehicles. */
			'%s vehicle'                                   => __( '%s vehicle', 'ferry-booking-manager' ),
			/* translators: 1: who is travelling, 2: the amount charged. */
			'A booking with %1$s pays %2$s.'               => __( 'A booking with %1$s pays %2$s.', 'ferry-booking-manager' ),
			/* translators: 1: who is travelling, 2: the total charged, 3: the amount per leg. */
			'A return booking with %1$s pays %2$s — %3$s each way.' => __( 'A return booking with %1$s pays %2$s — %3$s each way.', 'ferry-booking-manager' ),
			'Add an extra'                                 => __( 'Add an extra', 'ferry-booking-manager' ),
			'Add one to start selling meals, pets or bicycles alongside a crossing.' => __( 'Add one to start selling meals, pets or bicycles alongside a crossing.', 'ferry-booking-manager' ),
			'Charge on each leg of a return'               => __( 'Charge on each leg of a return', 'ferry-booking-manager' ),
			'Charged'                                      => __( 'Charged', 'ferry-booking-manager' ),
			'Customers only see it once this is on.'       => __( 'Customers only see it once this is on.', 'ferry-booking-manager' ),
			'Delete this extra'                            => __( 'Delete this extra', 'ferry-booking-manager' ),
			'Extras'                                       => __( 'Extras', 'ferry-booking-manager' ),
			'Extras saved.'                                => __( 'Extras saved.', 'ferry-booking-manager' ),
			'For example'                                  => __( 'For example', 'ferry-booking-manager' ),
			'Most one booking may add'                     => __( 'Most one booking may add', 'ferry-booking-manager' ),
			'New extra'                                    => __( 'New extra', 'ferry-booking-manager' ),
			'No extras yet'                                => __( 'No extras yet', 'ferry-booking-manager' ),
			'On for something consumed on both crossings, like a meal. Off for something bought once, like insurance.' => __( 'On for something consumed on both crossings, like a meal. Off for something bought once, like insurance.', 'ferry-booking-manager' ),
			'On sale'                                      => __( 'On sale', 'ferry-booking-manager' ),
			'One line, shown under the name.'              => __( 'One line, shown under the name.', 'ferry-booking-manager' ),
			'Sell meals, pets, bicycles, priority boarding and anything else you carry, charged per booking, per passenger or per vehicle.' => __( 'Sell meals, pets, bicycles, priority boarding and anything else you carry, charged per booking, per passenger or per vehicle.', 'ferry-booking-manager' ),
			'What a customer can add to a crossing. Only the ones switched on appear on the booking form.' => __( 'What a customer can add to a crossing. Only the ones switched on appear on the booking form.', 'ferry-booking-manager' ),
			'What the customer sees on the booking form.'  => __( 'What the customer sees on the booking form.', 'ferry-booking-manager' ),
			'Zero means no limit.'                         => __( 'Zero means no limit.', 'ferry-booking-manager' ),
			'a booking'                                    => __( 'a booking', 'ferry-booking-manager' ),
			/* translators: 1: what the rule does, 2: the conditions it applies under. */
			'%1$s, when %2$s'                              => __( '%1$s, when %2$s', 'ferry-booking-manager' ),
			/* translators: %s: what the rule does. */
			'%s, on every crossing'                        => __( '%s, on every crossing', 'ferry-booking-manager' ),
			'Add a rule'                                   => __( 'Add a rule', 'ferry-booking-manager' ),
			'Amount'                                       => __( 'Amount', 'ferry-booking-manager' ),
			'Any rule below this one is skipped when this one applies.' => __( 'Any rule below this one is skipped when this one applies.', 'ferry-booking-manager' ),
			'Applied in order, top to bottom. Each rule works on the fare the one above it left behind.' => __( 'Applied in order, top to bottom. Each rule works on the fare the one above it left behind.', 'ferry-booking-manager' ),
			'Change fares by season, day of the week, departure time, how far ahead someone books, or how full the sailing already is.' => __( 'Change fares by season, day of the week, departure time, how far ahead someone books, or how full the sailing already is.', 'ferry-booking-manager' ),
			'Delete this rule'                             => __( 'Delete this rule', 'ferry-booking-manager' ),
			'Fares stay exactly as set on the Fares tab until you add one.' => __( 'Fares stay exactly as set on the Fares tab until you add one.', 'ferry-booking-manager' ),
			'Leave a rule off while you build it. Nothing is applied until you turn it on.' => __( 'Leave a rule off while you build it. Nothing is applied until you turn it on.', 'ferry-booking-manager' ),
			'Leave everything blank to apply the rule to every crossing. Anything you fill in narrows it.' => __( 'Leave everything blank to apply the rule to every crossing. Anything you fill in narrows it.', 'ferry-booking-manager' ),
			'Move down'                                    => __( 'Move down', 'ferry-booking-manager' ),
			'Move up'                                      => __( 'Move up', 'ferry-booking-manager' ),
			'Name'                                         => __( 'Name', 'ferry-booking-manager' ),
			'New rule'                                     => __( 'New rule', 'ferry-booking-manager' ),
			'No price rules yet'                           => __( 'No price rules yet', 'ferry-booking-manager' ),
			'Off'                                          => __( 'Off', 'ferry-booking-manager' ),
			'On'                                           => __( 'On', 'ferry-booking-manager' ),
			'Price rules'                                  => __( 'Price rules', 'ferry-booking-manager' ),
			'Pricing rules saved.'                         => __( 'Pricing rules saved.', 'ferry-booking-manager' ),
			'Rule is on'                                   => __( 'Rule is on', 'ferry-booking-manager' ),
			'Shown to the customer on the price breakdown, so name it the way you would explain it.' => __( 'Shown to the customer on the price breakdown, so name it the way you would explain it.', 'ferry-booking-manager' ),
			'Stop after this rule'                         => __( 'Stop after this rule', 'ferry-booking-manager' ),
			'What it does'                                 => __( 'What it does', 'ferry-booking-manager' ),
			'When it applies'                              => __( 'When it applies', 'ferry-booking-manager' ),
			'A named passenger and vehicle list for every sailing, showing who has checked in and boarded, exportable as CSV or PDF.' => __( 'A named passenger and vehicle list for every sailing, showing who has checked in and boarded, exportable as CSV or PDF.', 'ferry-booking-manager' ),
			'Age'                                          => __( 'Age', 'ferry-booking-manager' ),
			'Download CSV'                                 => __( 'Download CSV', 'ferry-booking-manager' ),
			'Driver'                                       => __( 'Driver', 'ferry-booking-manager' ),
			'Fare type'                                    => __( 'Fare type', 'ferry-booking-manager' ),
			'First name'                                   => __( 'First name', 'ferry-booking-manager' ),
			'Length'                                       => __( 'Length', 'ferry-booking-manager' ),
			'Manifests appear once a crossing is scheduled.' => __( 'Manifests appear once a crossing is scheduled.', 'ferry-booking-manager' ),
			'Nationality'                                  => __( 'Nationality', 'ferry-booking-manager' ),
			'No sailings today'                            => __( 'No sailings today', 'ferry-booking-manager' ),
			'Nobody is booked on this sailing yet.'        => __( 'Nobody is booked on this sailing yet.', 'ferry-booking-manager' ),
			'Print manifest'                               => __( 'Print manifest', 'ferry-booking-manager' ),
			'Registration'                                 => __( 'Registration', 'ferry-booking-manager' ),
			'Surname'                                      => __( 'Surname', 'ferry-booking-manager' ),
			'Type'                                         => __( 'Type', 'ferry-booking-manager' ),
			'Vehicle'                                      => __( 'Vehicle', 'ferry-booking-manager' ),
			'Who and what is aboard each crossing.'        => __( 'Who and what is aboard each crossing.', 'ferry-booking-manager' ),
			'Any sailing today'                            => __( 'Any sailing today', 'ferry-booking-manager' ),
			'Boarded'                                      => __( 'Boarded', 'ferry-booking-manager' ),
			'Check in'                                     => __( 'Check in', 'ferry-booking-manager' ),
			'Checked in'                                   => __( 'Checked in', 'ferry-booking-manager' ),
			'Choosing one stops a ticket for a later crossing being boarded onto this vessel.' => __( 'Choosing one stops a ticket for a later crossing being boarded onto this vessel.', 'ferry-booking-manager' ),
			'Do not board'                                 => __( 'Do not board', 'ferry-booking-manager' ),
			'Hold the code steady in the frame.'           => __( 'Hold the code steady in the frame.', 'ferry-booking-manager' ),
			'Look it up'                                   => __( 'Look it up', 'ferry-booking-manager' ),
			'Mark boarded'                                 => __( 'Mark boarded', 'ferry-booking-manager' ),
			'Next passenger'                               => __( 'Next passenger', 'ferry-booking-manager' ),
			'Passenger'                                    => __( 'Passenger', 'ferry-booking-manager' ),
			'Paste or type a ticket code'                  => __( 'Paste or type a ticket code', 'ferry-booking-manager' ),
			'Sailing'                                      => __( 'Sailing', 'ferry-booking-manager' ),
			'Scan a ticket, or type its reference.'        => __( 'Scan a ticket, or type its reference.', 'ferry-booking-manager' ),
			'Scan tickets from a phone camera, check passengers in and board them, with duplicate-scan protection and a history you can audit.' => __( 'Scan tickets from a phone camera, check passengers in and board them, with duplicate-scan protection and a history you can audit.', 'ferry-booking-manager' ),
			'Scan with the camera'                         => __( 'Scan with the camera', 'ferry-booking-manager' ),
			'Scanned by'                                   => __( 'Scanned by', 'ferry-booking-manager' ),
			'Stop the camera'                              => __( 'Stop the camera', 'ferry-booking-manager' ),
			'The camera could not be opened. Type the reference instead.' => __( 'The camera could not be opened. Type the reference instead.', 'ferry-booking-manager' ),
			'This browser cannot use the camera for scanning. Type the reference below.' => __( 'This browser cannot use the camera for scanning. Type the reference below.', 'ferry-booking-manager' ),
			'Ticket reference'                             => __( 'Ticket reference', 'ferry-booking-manager' ),
			'Valid'                                        => __( 'Valid', 'ferry-booking-manager' ),
			'Waiting for a ticket.'                        => __( 'Waiting for a ticket.', 'ferry-booking-manager' ),
			'Amount taken'                                 => __( 'Amount taken', 'ferry-booking-manager' ),
			'What the customer has handed over so far.'    => __( 'What the customer has handed over so far.', 'ferry-booking-manager' ),
			/* translators: 1: traveller type, 2: position in the party. */
			'%1$s %2$s'                                    => __( '%1$s %2$s', 'ferry-booking-manager' ),
			'A passenger manifest is a named list, so every traveller needs a name.' => __( 'A passenger manifest is a named list, so every traveller needs a name.', 'ferry-booking-manager' ),
			'Traveller details'                            => __( 'Traveller details', 'ferry-booking-manager' ),
			'Use the customer name'                        => __( 'Use the customer name', 'ferry-booking-manager' ),
			/* translators: %s: number of seats still available. */
			'%s seats left'                                => __( '%s seats left', 'ferry-booking-manager' ),
			/* translators: %s: booking reference. */
			'Booking %s created.'                          => __( 'Booking %s created.', 'ferry-booking-manager' ),
			/* translators: %s: formatted discount amount. */
			'Less %s discount at confirmation.'            => __( 'Less %s discount at confirmation.', 'ferry-booking-manager' ),
			'Back to bookings'                             => __( 'Back to bookings', 'ferry-booking-manager' ),
			'Choose a port'                                => __( 'Choose a port', 'ferry-booking-manager' ),
			'Choose a sailing and who is travelling to see the fare.' => __( 'Choose a sailing and who is travelling to see the fare.', 'ferry-booking-manager' ),
			'Choose a sailing first.'                      => __( 'Choose a sailing first.', 'ferry-booking-manager' ),
			'Choose both ports and a date first.'          => __( 'Choose both ports and a date first.', 'ferry-booking-manager' ),
			'Create booking'                               => __( 'Create booking', 'ferry-booking-manager' ),
			'Creating…'                                    => __( 'Creating…', 'ferry-booking-manager' ),
			'Crossing'                                     => __( 'Crossing', 'ferry-booking-manager' ),
			'Date'                                         => __( 'Date', 'ferry-booking-manager' ),
			'Find sailings'                                => __( 'Find sailings', 'ferry-booking-manager' ),
			'Internal note'                                => __( 'Internal note', 'ferry-booking-manager' ),
			'Name or email'                                => __( 'Name or email', 'ferry-booking-manager' ),
			'New booking'                                  => __( 'New booking', 'ferry-booking-manager' ),
			'No sailings on that date.'                    => __( 'No sailings on that date.', 'ferry-booking-manager' ),
			'Payment and status'                           => __( 'Payment and status', 'ferry-booking-manager' ),
			'Pick a sailing, add at least one traveller, and give a name and email.' => __( 'Pick a sailing, add at least one traveller, and give a name and email.', 'ferry-booking-manager' ),
			'Reason for the discount'                      => __( 'Reason for the discount', 'ferry-booking-manager' ),
			'Search customers'                             => __( 'Search customers', 'ferry-booking-manager' ),
			'Search for a returning customer, or type the details of a new one.' => __( 'Search for a returning customer, or type the details of a new one.', 'ferry-booking-manager' ),
			'Searching…'                                   => __( 'Searching…', 'ferry-booking-manager' ),
			'Sold out'                                     => __( 'Sold out', 'ferry-booking-manager' ),
			'Staff only. The customer never sees this.'    => __( 'Staff only. The customer never sees this.', 'ferry-booking-manager' ),
			'Take a booking at the desk or over the phone.' => __( 'Take a booking at the desk or over the phone.', 'ferry-booking-manager' ),
			'Taken off the fare. Recorded against your account.' => __( 'Taken off the fare. Recorded against your account.', 'ferry-booking-manager' ),
			'Who is travelling'                            => __( 'Who is travelling', 'ferry-booking-manager' ),
			'Leave empty to use the admin address'         => __( 'Leave empty to use the admin address', 'ferry-booking-manager' ),
			'No ferry roles are installed.'                => __( 'No ferry roles are installed.', 'ferry-booking-manager' ),
			'No payment methods are available.'            => __( 'No payment methods are available.', 'ferry-booking-manager' ),
			'Permission'                                   => __( 'Permission', 'ferry-booking-manager' ),
			'Send a test message to'                       => __( 'Send a test message to', 'ferry-booking-manager' ),
			'Send test message'                            => __( 'Send test message', 'ferry-booking-manager' ),
			'Settings could not be loaded.'                => __( 'Settings could not be loaded.', 'ferry-booking-manager' ),
			'Test message sent to'                         => __( 'Test message sent to', 'ferry-booking-manager' ),
			'View'                                         => __( 'View', 'ferry-booking-manager' ),
			'people'                                       => __( 'people', 'ferry-booking-manager' ),
			'person'                                       => __( 'person', 'ferry-booking-manager' ),
			'Reports'                                      => __( 'Reports', 'ferry-booking-manager' ),
			'Emails'                                       => __( 'Emails', 'ferry-booking-manager' ),
			'Payments'                                     => __( 'Payments', 'ferry-booking-manager' ),
			'Settings'                                     => __( 'Settings', 'ferry-booking-manager' ),
			'Search'                                       => __( 'Search', 'ferry-booking-manager' ),
			'Loading'                                      => __( 'Loading', 'ferry-booking-manager' ),
			'Retry'                                        => __( 'Retry', 'ferry-booking-manager' ),
			'Cancel'                                       => __( 'Cancel', 'ferry-booking-manager' ),
			'Save'                                         => __( 'Save', 'ferry-booking-manager' ),
			'Close'                                        => __( 'Close', 'ferry-booking-manager' ),
			'Something went wrong.'                        => __( 'Something went wrong.', 'ferry-booking-manager' ),
			'You do not have permission to view this section.' => __( 'You do not have permission to view this section.', 'ferry-booking-manager' ),
			'Page not found.'                              => __( 'Page not found.', 'ferry-booking-manager' ),
			'Available in Ferry Booking Manager Pro.'      => __( 'Available in Ferry Booking Manager Pro.', 'ferry-booking-manager' ),
			'Coming in a later phase.'                     => __( 'Coming in a later phase.', 'ferry-booking-manager' ),
			'Connected'                                    => __( 'Connected', 'ferry-booking-manager' ),
			'Disconnected'                                 => __( 'Disconnected', 'ferry-booking-manager' ),
			'Skip to dashboard content'                    => __( 'Skip to dashboard content', 'ferry-booking-manager' ),
			'Toggle navigation'                            => __( 'Toggle navigation', 'ferry-booking-manager' ),
			'Back to WordPress'                            => __( 'Back to WordPress', 'ferry-booking-manager' ),
			'Start with a working ferry operation'         => __( 'Start with a working ferry operation', 'ferry-booking-manager' ),
			'Install the demo'                             => __( 'Install the demo', 'ferry-booking-manager' ),
			'Installing…'                                  => __( 'Installing…', 'ferry-booking-manager' ),
			'Starting…'                                    => __( 'Starting…', 'ferry-booking-manager' ),
			'Start from scratch'                           => __( 'Start from scratch', 'ferry-booking-manager' ),
			'The demo could not be installed.'             => __( 'The demo could not be installed.', 'ferry-booking-manager' ),
			'Everything it adds is marked as demo content and can be removed again from Settings → Advanced, without touching anything you have created yourself.' => __( 'Everything it adds is marked as demo content and can be removed again from Settings → Advanced, without touching anything you have created yourself.', 'ferry-booking-manager' ),
			'Ferry Manager'                                => __( 'Ferry Manager', 'ferry-booking-manager' ),
			'Ferry Booking Manager Pro'                    => __( 'Ferry Booking Manager Pro', 'ferry-booking-manager' ),
			'Plugin version'                               => __( 'Plugin version', 'ferry-booking-manager' ),
			'WordPress'                                    => __( 'WordPress', 'ferry-booking-manager' ),
			'PHP'                                          => __( 'PHP', 'ferry-booking-manager' ),
			'WooCommerce'                                  => __( 'WooCommerce', 'ferry-booking-manager' ),
			'Site timezone'                                => __( 'Site timezone', 'ferry-booking-manager' ),
			'Active'                                       => __( 'Active', 'ferry-booking-manager' ),
			'Not active'                                   => __( 'Not active', 'ferry-booking-manager' ),
			'Not installed'                                => __( 'Not installed', 'ferry-booking-manager' ),
			'Operational metrics'                          => __( 'Operational metrics', 'ferry-booking-manager' ),
			'Today’s sailings, passengers, vehicles, revenue, check-ins and pending payments appear here once the sailing, booking and availability engines are in place.' => __( 'Today’s sailings, passengers, vehicles, revenue, check-ins and pending payments appear here once the sailing, booking and availability engines are in place.', 'ferry-booking-manager' ),
			/* translators: %s: development phase number. */
			'This module is delivered in development phase %s.' => __( 'This module is delivered in development phase %s.', 'ferry-booking-manager' ),
			/* translators: record type, e.g. "Port". */
			'%s created.'                                  => __( '%s created.', 'ferry-booking-manager' ),
			/* translators: record type. */
			'%s deleted.'                                  => __( '%s deleted.', 'ferry-booking-manager' ),
			/* translators: record type. */
			'%s updated.'                                  => __( '%s updated.', 'ferry-booking-manager' ),
			'Accepts vehicles'                             => __( 'Accepts vehicles', 'ferry-booking-manager' ),
			'Actions'                                      => __( 'Actions', 'ferry-booking-manager' ),
			/* translators: record type. */
			'Add %s'                                       => __( 'Add %s', 'ferry-booking-manager' ),
			'Add a vessel so sailings have capacity to sell.' => __( 'Add a vessel so sailings have capacity to sell.', 'ferry-booking-manager' ),
			'Add the terminals you sail between before creating routes.' => __( 'Add the terminals you sail between before creating routes.', 'ferry-booking-manager' ),
			'Address'                                      => __( 'Address', 'ferry-booking-manager' ),
			'Add…'                                         => __( 'Add…', 'ferry-booking-manager' ),
			'All statuses'                                 => __( 'All statuses', 'ferry-booking-manager' ),
			'Arrived'                                      => __( 'Arrived', 'ferry-booking-manager' ),
			'Bar, Wi-Fi, Sun deck…'                        => __( 'Bar, Wi-Fi, Sun deck…', 'ferry-booking-manager' ),
			'Boarding instructions'                        => __( 'Boarding instructions', 'ferry-booking-manager' ),
			'Cancelled'                                    => __( 'Cancelled', 'ferry-booking-manager' ),
			'Check-in'                                     => __( 'Check-in', 'ferry-booking-manager' ),
			'Check-in closes'                              => __( 'Check-in closes', 'ferry-booking-manager' ),
			'Check-in instructions'                        => __( 'Check-in instructions', 'ferry-booking-manager' ),
			'City'                                         => __( 'City', 'ferry-booking-manager' ),
			'Code'                                         => __( 'Code', 'ferry-booking-manager' ),
			'Contact email'                                => __( 'Contact email', 'ferry-booking-manager' ),
			'Contact phone'                                => __( 'Contact phone', 'ferry-booking-manager' ),
			'Country'                                      => __( 'Country', 'ferry-booking-manager' ),
			'Country code'                                 => __( 'Country code', 'ferry-booking-manager' ),
			'Create a route once you have at least two ports.' => __( 'Create a route once you have at least two ports.', 'ferry-booking-manager' ),
			'Crew capacity'                                => __( 'Crew capacity', 'ferry-booking-manager' ),
			'Default vessel'                               => __( 'Default vessel', 'ferry-booking-manager' ),
			'Delayed'                                      => __( 'Delayed', 'ferry-booking-manager' ),
			'Delete'                                       => __( 'Delete', 'ferry-booking-manager' ),
			/* translators: record type. */
			'Delete %s?'                                   => __( 'Delete %s?', 'ferry-booking-manager' ),
			'Departed'                                     => __( 'Departed', 'ferry-booking-manager' ),
			'Description'                                  => __( 'Description', 'ferry-booking-manager' ),
			'Destination port'                             => __( 'Destination port', 'ferry-booking-manager' ),
			'Distance'                                     => __( 'Distance', 'ferry-booking-manager' ),
			'Duration'                                     => __( 'Duration', 'ferry-booking-manager' ),
			'Edit'                                         => __( 'Edit', 'ferry-booking-manager' ),
			/* translators: record type. */
			'Edit %s'                                      => __( 'Edit %s', 'ferry-booking-manager' ),
			'Facilities'                                   => __( 'Facilities', 'ferry-booking-manager' ),
			'IT'                                           => __( 'IT', 'ferry-booking-manager' ),
			'Inactive'                                     => __( 'Inactive', 'ferry-booking-manager' ),
			'Intermediate calls'                           => __( 'Intermediate calls', 'ferry-booking-manager' ),
			'Lane metres'                                  => __( 'Lane metres', 'ferry-booking-manager' ),
			'Latitude'                                     => __( 'Latitude', 'ferry-booking-manager' ),
			'Longitude'                                    => __( 'Longitude', 'ferry-booking-manager' ),
			'Maintenance'                                  => __( 'Maintenance', 'ferry-booking-manager' ),
			'Next'                                         => __( 'Next', 'ferry-booking-manager' ),
			'No'                                           => __( 'No', 'ferry-booking-manager' ),
			/* translators: plural record type, e.g. "ports". */
			'No %s yet.'                                   => __( 'No %s yet.', 'ferry-booking-manager' ),
			'No default'                                   => __( 'No default', 'ferry-booking-manager' ),
			'No matching records.'                         => __( 'No matching records.', 'ferry-booking-manager' ),
			'Optional. Leave blank and the route is named after its ports.' => __( 'Optional. Leave blank and the route is named after its ports.', 'ferry-booking-manager' ),
			'Origin port'                                  => __( 'Origin port', 'ferry-booking-manager' ),
			/* translators: 1: current page, 2: total pages. */
			'Page %1$s of %2$s'                            => __( 'Page %1$s of %2$s', 'ferry-booking-manager' ),
			'Pagination'                                   => __( 'Pagination', 'ferry-booking-manager' ),
			'Passenger capacity'                           => __( 'Passenger capacity', 'ferry-booking-manager' ),
			'Port'                                         => __( 'Port', 'ferry-booking-manager' ),
			'Port code'                                    => __( 'Port code', 'ferry-booking-manager' ),
			'Port name'                                    => __( 'Port name', 'ferry-booking-manager' ),
			'Ports called at along the way, in order.'     => __( 'Ports called at along the way, in order.', 'ferry-booking-manager' ),
			'Pre-selected when scheduling sailings on this route.' => __( 'Pre-selected when scheduling sailings on this route.', 'ferry-booking-manager' ),
			'Previous'                                     => __( 'Previous', 'ferry-booking-manager' ),
			'Registration number'                          => __( 'Registration number', 'ferry-booking-manager' ),
			'Remove'                                       => __( 'Remove', 'ferry-booking-manager' ),
			'Route'                                        => __( 'Route', 'ferry-booking-manager' ),
			'Route code'                                   => __( 'Route code', 'ferry-booking-manager' ),
			'Route name'                                   => __( 'Route name', 'ferry-booking-manager' ),
			'Rows per page'                                => __( 'Rows per page', 'ferry-booking-manager' ),
			'Saving…'                                      => __( 'Saving…', 'ferry-booking-manager' ),
			'Scheduled'                                    => __( 'Scheduled', 'ferry-booking-manager' ),
			'Search ports by name or code'                 => __( 'Search ports by name or code', 'ferry-booking-manager' ),
			'Search routes by name or code'                => __( 'Search routes by name or code', 'ferry-booking-manager' ),
			'Search vessels by name, code or registration' => __( 'Search vessels by name, code or registration', 'ferry-booking-manager' ),
			'Select a port'                                => __( 'Select a port', 'ferry-booking-manager' ),
			'Service speed'                                => __( 'Service speed', 'ferry-booking-manager' ),
			'Short identifier shown on tickets and manifests, for example NAP.' => __( 'Short identifier shown on tickets and manifests, for example NAP.', 'ferry-booking-manager' ),
			/* translators: 1: first row, 2: last row, 3: total rows. */
			'Showing %1$s to %2$s of %3$s'                 => __( 'Showing %1$s to %2$s of %3$s', 'ferry-booking-manager' ),
			'Shown to passengers on their ticket.'         => __( 'Shown to passengers on their ticket.', 'ferry-booking-manager' ),
			'Status'                                       => __( 'Status', 'ferry-booking-manager' ),
			'Terminals your sailings depart from and arrive into.' => __( 'Terminals your sailings depart from and arrive into.', 'ferry-booking-manager' ),
			'The journeys you operate between ports.'      => __( 'The journeys you operate between ports.', 'ferry-booking-manager' ),
			'Try a different search or filter.'            => __( 'Try a different search or filter.', 'ferry-booking-manager' ),
			'Turn off for passenger-only crossings.'       => __( 'Turn off for passenger-only crossings.', 'ferry-booking-manager' ),
			'Used for lane-metre availability when vehicles have different lengths.' => __( 'Used for lane-metre availability when vehicles have different lengths.', 'ferry-booking-manager' ),
			'Vehicle capacity'                             => __( 'Vehicle capacity', 'ferry-booking-manager' ),
			'Vehicle deck capacity'                        => __( 'Vehicle deck capacity', 'ferry-booking-manager' ),
			'Vessel'                                       => __( 'Vessel', 'ferry-booking-manager' ),
			'Vessel code'                                  => __( 'Vessel code', 'ferry-booking-manager' ),
			'Vessel name'                                  => __( 'Vessel name', 'ferry-booking-manager' ),
			'Yes'                                          => __( 'Yes', 'ferry-booking-manager' ),
			'Your fleet, and the capacity each ship brings to a sailing.' => __( 'Your fleet, and the capacity each ship brings to a sailing.', 'ferry-booking-manager' ),
			'h'                                            => __( 'h', 'ferry-booking-manager' ),
			'm'                                            => __( 'm', 'ferry-booking-manager' ),
			'min'                                          => __( 'min', 'ferry-booking-manager' ),
			/* translators: record name. */
			'“%s” will be moved to the trash. This cannot be undone from here.' => __( '“%s” will be moved to the trash. This cannot be undone from here.', 'ferry-booking-manager' ),
			/* translators: number of sailings. */
			'%s already scheduled'                         => __( '%s already scheduled', 'ferry-booking-manager' ),
			/* translators: number of sailings. */
			'%s in conflict'                               => __( '%s in conflict', 'ferry-booking-manager' ),
			/* translators: number of sailings. */
			'%s new'                                       => __( '%s new', 'ferry-booking-manager' ),
			/* translators: number of sailings. */
			'%s sailings created.'                         => __( '%s sailings created.', 'ferry-booking-manager' ),
			'Add sailing'                                  => __( 'Add sailing', 'ferry-booking-manager' ),
			'All routes'                                   => __( 'All routes', 'ferry-booking-manager' ),
			'Already scheduled'                            => __( 'Already scheduled', 'ferry-booking-manager' ),
			'Arrival'                                      => __( 'Arrival', 'ferry-booking-manager' ),
			'Bookings close'                               => __( 'Bookings close', 'ferry-booking-manager' ),
			'Bookings open'                                => __( 'Bookings open', 'ferry-booking-manager' ),
			'Bulk schedule'                                => __( 'Bulk schedule', 'ferry-booking-manager' ),
			'Capacity'                                     => __( 'Capacity', 'ferry-booking-manager' ),
			/* translators: number of sailings. */
			'Create %s sailings'                           => __( 'Create %s sailings', 'ferry-booking-manager' ),
			'Create sailings'                              => __( 'Create sailings', 'ferry-booking-manager' ),
			'Days of the week'                             => __( 'Days of the week', 'ferry-booking-manager' ),
			'Delete sailing?'                              => __( 'Delete sailing?', 'ferry-booking-manager' ),
			'Departure'                                    => __( 'Departure', 'ferry-booking-manager' ),
			'Departure times'                              => __( 'Departure times', 'ferry-booking-manager' ),
			'Each time runs on every selected day.'        => __( 'Each time runs on every selected day.', 'ferry-booking-manager' ),
			'Edit sailing'                                 => __( 'Edit sailing', 'ferry-booking-manager' ),
			'Every dated departure you operate.'           => __( 'Every dated departure you operate.', 'ferry-booking-manager' ),
			'From'                                         => __( 'From', 'ferry-booking-manager' ),
			'Leave at 0 to use the vessel’s own capacity.' => __( 'Leave at 0 to use the vessel’s own capacity.', 'ferry-booking-manager' ),
			'Leave blank to accept bookings until departure.' => __( 'Leave blank to accept bookings until departure.', 'ferry-booking-manager' ),
			'Leave blank to derive it from the route duration.' => __( 'Leave blank to derive it from the route duration.', 'ferry-booking-manager' ),
			'No sailings scheduled.'                       => __( 'No sailings scheduled.', 'ferry-booking-manager' ),
			'Operational notes'                            => __( 'Operational notes', 'ferry-booking-manager' ),
			'Passenger capacity override'                  => __( 'Passenger capacity override', 'ferry-booking-manager' ),
			'Preview'                                      => __( 'Preview', 'ferry-booking-manager' ),
			'Repeat a departure pattern across a date range.' => __( 'Repeat a departure pattern across a date range.', 'ferry-booking-manager' ),
			'Sailing created.'                             => __( 'Sailing created.', 'ferry-booking-manager' ),
			'Sailing deleted.'                             => __( 'Sailing deleted.', 'ferry-booking-manager' ),
			'Sailing updated.'                             => __( 'Sailing updated.', 'ferry-booking-manager' ),
			'Schedule one departure.'                      => __( 'Schedule one departure.', 'ferry-booking-manager' ),
			'Search sailings'                              => __( 'Search sailings', 'ferry-booking-manager' ),
			'Select a route'                               => __( 'Select a route', 'ferry-booking-manager' ),
			'Select a vessel'                              => __( 'Select a vessel', 'ferry-booking-manager' ),
			/* translators: total number of candidates. */
			'Showing the first 60 of %s.'                  => __( 'Showing the first 60 of %s.', 'ferry-booking-manager' ),
			'The sailing will be moved to the trash. Sailings that already carry bookings cannot be deleted — cancel them instead so passengers are notified.' => __( 'The sailing will be moved to the trash. Sailings that already carry bookings cannot be deleted — cancel them instead so passengers are notified.', 'ferry-booking-manager' ),
			'To'                                           => __( 'To', 'ferry-booking-manager' ),
			'Use bulk scheduling to lay out a season in one go, or add a single departure.' => __( 'Use bulk scheduling to lay out a season in one go, or add a single departure.', 'ferry-booking-manager' ),
			'Vehicle capacity override'                    => __( 'Vehicle capacity override', 'ferry-booking-manager' ),
			'Vessel busy'                                  => __( 'Vessel busy', 'ferry-booking-manager' ),
			/* translators: name of the conflicting sailing. */
			'Vessel busy: %s'                              => __( 'Vessel busy: %s', 'ferry-booking-manager' ),
			'Will be created'                              => __( 'Will be created', 'ferry-booking-manager' ),
			'min before departure'                         => __( 'min before departure', 'ferry-booking-manager' ),
			'seats'                                        => __( 'seats', 'ferry-booking-manager' ),
			'vehicles'                                     => __( 'vehicles', 'ferry-booking-manager' ),
			/* translators: 1: number of required fields, 2: number of optional fields. */
			'%1$s required, %2$s optional'                 => __( '%1$s required, %2$s optional', 'ferry-booking-manager' ),
			/* translators: 1: lowest age in the band, 2: highest age in the band. */
			'%1$s to %2$s'                                 => __( '%1$s to %2$s', 'ferry-booking-manager' ),
			/* translators: %s: lowest age in the band. */
			'%s and over'                                  => __( '%s and over', 'ferry-booking-manager' ),
			/* translators: %s: a length in metres. */
			'%s m'                                         => __( '%s m', 'ferry-booking-manager' ),
			/* translators: %s: percentage of the base passenger fare. */
			'%s%% of base'                                 => __( '%s%% of base', 'ferry-booking-manager' ),
			'A fixed fare'                                 => __( 'A fixed fare', 'ferry-booking-manager' ),
			'A percentage of the base fare'                => __( 'A percentage of the base fare', 'ferry-booking-manager' ),
			'Add a custom field'                           => __( 'Add a custom field', 'ferry-booking-manager' ),
			'Add a vehicle type to start selling deck space.' => __( 'Add a vehicle type to start selling deck space.', 'ferry-booking-manager' ),
			'Add at least one passenger type so sailings have something to sell.' => __( 'Add at least one passenger type so sailings have something to sell.', 'ferry-booking-manager' ),
			'Add field'                                    => __( 'Add field', 'ferry-booking-manager' ),
			'Added on top of the fare, multiplied by the lane metres above.' => __( 'Added on top of the fare, multiplied by the lane metres above.', 'ferry-booking-manager' ),
			'Additional fare per lane metre'               => __( 'Additional fare per lane metre', 'ferry-booking-manager' ),
			'Allow a trailer'                              => __( 'Allow a trailer', 'ferry-booking-manager' ),
			'Any age'                                      => __( 'Any age', 'ferry-booking-manager' ),
			'Appears on manifests and boarding lists, for example CAR.' => __( 'Appears on manifests and boarding lists, for example CAR.', 'ferry-booking-manager' ),
			'Appears on manifests and tickets, for example ADULT.' => __( 'Appears on manifests and tickets, for example ADULT.', 'ferry-booking-manager' ),
			'Ask for a date of birth'                      => __( 'Ask for a date of birth', 'ferry-booking-manager' ),
			'Ask the customer for the real length and height, not just the class maximum.' => __( 'Ask the customer for the real length and height, not just the class maximum.', 'ferry-booking-manager' ),
			'Base'                                         => __( 'Base', 'ferry-booking-manager' ),
			'Bicycle'                                      => __( 'Bicycle', 'ferry-booking-manager' ),
			'Booking form saved.'                          => __( 'Booking form saved.', 'ferry-booking-manager' ),
			'Bus'                                          => __( 'Bus', 'ferry-booking-manager' ),
			'Camper'                                       => __( 'Camper', 'ferry-booking-manager' ),
			'Car'                                          => __( 'Car', 'ferry-booking-manager' ),
			'Category'                                     => __( 'Category', 'ferry-booking-manager' ),
			'Custom'                                       => __( 'Custom', 'ferry-booking-manager' ),
			'Display order'                                => __( 'Display order', 'ferry-booking-manager' ),
			'Fare'                                         => __( 'Fare', 'ferry-booking-manager' ),
			'Fare amount'                                  => __( 'Fare amount', 'ferry-booking-manager' ),
			'Field label'                                  => __( 'Field label', 'ferry-booking-manager' ),
			'Free'                                         => __( 'Free', 'ferry-booking-manager' ),
			'How many of a vessel’s vehicle spaces one of these takes.' => __( 'How many of a vessel’s vehicle spaces one of these takes.', 'ferry-booking-manager' ),
			'Lane metres consumed'                         => __( 'Lane metres consumed', 'ferry-booking-manager' ),
			'Leave at 0 to use the maximum length. This is what deck availability is measured against.' => __( 'Leave at 0 to use the maximum length. This is what deck availability is measured against.', 'ferry-booking-manager' ),
			'Lower numbers appear first on the booking form.' => __( 'Lower numbers appear first on the booking form.', 'ferry-booking-manager' ),
			'Max'                                          => __( 'Max', 'ferry-booking-manager' ),
			'Maximum age'                                  => __( 'Maximum age', 'ferry-booking-manager' ),
			'Maximum height'                               => __( 'Maximum height', 'ferry-booking-manager' ),
			'Maximum length'                               => __( 'Maximum length', 'ferry-booking-manager' ),
			'Maximum per booking'                          => __( 'Maximum per booking', 'ferry-booking-manager' ),
			'Maximum weight'                               => __( 'Maximum weight', 'ferry-booking-manager' ),
			'Maximum width'                                => __( 'Maximum width', 'ferry-booking-manager' ),
			'Minibus'                                      => __( 'Minibus', 'ferry-booking-manager' ),
			'Minimum age'                                  => __( 'Minimum age', 'ferry-booking-manager' ),
			'Minimum per booking'                          => __( 'Minimum per booking', 'ferry-booking-manager' ),
			'Motorcycle'                                   => __( 'Motorcycle', 'ferry-booking-manager' ),
			'Must travel with an adult'                    => __( 'Must travel with an adult', 'ferry-booking-manager' ),
			'Name shown to customers'                      => __( 'Name shown to customers', 'ferry-booking-manager' ),
			'Occupies a passenger seat'                    => __( 'Occupies a passenger seat', 'ferry-booking-manager' ),
			'Order'                                        => __( 'Order', 'ferry-booking-manager' ),
			'Other'                                        => __( 'Other', 'ferry-booking-manager' ),
			'Passenger fares included'                     => __( 'Passenger fares included', 'ferry-booking-manager' ),
			'Passenger type'                               => __( 'Passenger type', 'ferry-booking-manager' ),
			'Passenger types'                              => __( 'Passenger types', 'ferry-booking-manager' ),
			'Passengers up to this number travel free with the vehicle.' => __( 'Passengers up to this number travel free with the vehicle.', 'ferry-booking-manager' ),
			'Percentage fares are worked out from this type. Only one type can hold it.' => __( 'Percentage fares are worked out from this type. Only one type can hold it.', 'ferry-booking-manager' ),
			'Percentage of the base fare'                  => __( 'Percentage of the base fare', 'ferry-booking-manager' ),
			/* translators: %s: the field label to remove. */
			'Remove %s'                                    => __( 'Remove %s', 'ferry-booking-manager' ),
			'Require a registration number'                => __( 'Require a registration number', 'ferry-booking-manager' ),
			'Require driver details'                       => __( 'Require driver details', 'ferry-booking-manager' ),
			'Require exact dimensions'                     => __( 'Require exact dimensions', 'ferry-booking-manager' ),
			'Routes and sailings can override this later.' => __( 'Routes and sailings can override this later.', 'ferry-booking-manager' ),
			'SUV'                                          => __( 'SUV', 'ferry-booking-manager' ),
			'Save changes'                                 => __( 'Save changes', 'ferry-booking-manager' ),
			'Search passenger types by name or code'       => __( 'Search passenger types by name or code', 'ferry-booking-manager' ),
			'Search vehicle types by name or code'         => __( 'Search vehicle types by name or code', 'ferry-booking-manager' ),
			'Seat'                                         => __( 'Seat', 'ferry-booking-manager' ),
			'Slots'                                        => __( 'Slots', 'ferry-booking-manager' ),
			'The categories of traveller you sell, what each one costs, and what each one occupies.' => __( 'The categories of traveller you sell, what each one costs, and what each one occupies.', 'ferry-booking-manager' ),
			'This is the base fare'                        => __( 'This is the base fare', 'ferry-booking-manager' ),
			'Trailer'                                      => __( 'Trailer', 'ferry-booking-manager' ),
			'Truck'                                        => __( 'Truck', 'ferry-booking-manager' ),
			'Turn off for lap infants, who travel without consuming capacity.' => __( 'Turn off for lap infants, who travel without consuming capacity.', 'ferry-booking-manager' ),
			'Turn on when the age band has to be proven at check-in.' => __( 'Turn on when the age band has to be proven at check-in.', 'ferry-booking-manager' ),
			/* translators: %s: the age a passenger must be under. */
			'Under %s'                                     => __( 'Under %s', 'ferry-booking-manager' ),
			'Use -1 for no lower limit.'                   => __( 'Use -1 for no lower limit.', 'ferry-booking-manager' ),
			'Use -1 for no upper limit.'                   => __( 'Use -1 for no upper limit.', 'ferry-booking-manager' ),
			'Use 0 to allow any number.'                   => __( 'Use 0 to allow any number.', 'ferry-booking-manager' ),
			'Van'                                          => __( 'Van', 'ferry-booking-manager' ),
			'Vehicle slots consumed'                       => __( 'Vehicle slots consumed', 'ferry-booking-manager' ),
			'Vehicle type'                                 => __( 'Vehicle type', 'ferry-booking-manager' ),
			'What you carry on the vehicle deck, and how much space each one takes.' => __( 'What you carry on the vehicle deck, and how much space each one takes.', 'ferry-booking-manager' ),
			/* translators: %s: percentage of the base fare. */
			'%s%% of the base fare unless set here'        => __( '%s%% of the base fare unless set here', 'ferry-booking-manager' ),
			'A blank fare falls back to the type’s own price. Percentage passenger types are worked out from the base type’s fare on this route.' => __( 'A blank fare falls back to the type’s own price. Percentage passenger types are worked out from the base type’s fare on this route.', 'ferry-booking-manager' ),
			'Always free'                                  => __( 'Always free', 'ferry-booking-manager' ),
			'Applied on top of the fare. Every total the customer sees is worked out on the server with these rules.' => __( 'Applied on top of the fare. Every total the customer sees is worked out on the server with these rules.', 'ferry-booking-manager' ),
			'Charge tax'                                   => __( 'Charge tax', 'ferry-booking-manager' ),
			'Default'                                      => __( 'Default', 'ferry-booking-manager' ),
			'Discounts'                                    => __( 'Discounts', 'ferry-booking-manager' ),
			/* translators: %s: passenger or vehicle type name. */
			'Fare for %s'                                  => __( 'Fare for %s', 'ferry-booking-manager' ),
			'Fares are set per route, so create a route first.' => __( 'Fares are set per route, so create a route first.', 'ferry-booking-manager' ),
			'Fares by route'                               => __( 'Fares by route', 'ferry-booking-manager' ),
			'Fares include tax'                            => __( 'Fares include tax', 'ferry-booking-manager' ),
			'Fares saved.'                                 => __( 'Fares saved.', 'ferry-booking-manager' ),
			'Fee name'                                     => __( 'Fee name', 'ferry-booking-manager' ),
			'Fees'                                         => __( 'Fees', 'ferry-booking-manager' ),
			'Group discount'                               => __( 'Group discount', 'ferry-booking-manager' ),
			'Group discount from'                          => __( 'Group discount from', 'ferry-booking-manager' ),
			'No routes yet.'                               => __( 'No routes yet.', 'ferry-booking-manager' ),
			'No — add tax on top'                          => __( 'No — add tax on top', 'ferry-booking-manager' ),
			'Pricing saved.'                               => __( 'Pricing saved.', 'ferry-booking-manager' ),
			'Reductions are always taken from the fare before tax, and can never exceed it.' => __( 'Reductions are always taken from the fare before tax, and can never exceed it.', 'ferry-booking-manager' ),
			'Return journey discount'                      => __( 'Return journey discount', 'ferry-booking-manager' ),
			'Shown on tickets and invoices, for example VAT or GST.' => __( 'Shown on tickets and invoices, for example VAT or GST.', 'ferry-booking-manager' ),
			'Taken off the whole round trip when both legs are booked together.' => __( 'Taken off the whole round trip when both legs are booked together.', 'ferry-booking-manager' ),
			'Tax booking fees too'                         => __( 'Tax booking fees too', 'ferry-booking-manager' ),
			'Tax name'                                     => __( 'Tax name', 'ferry-booking-manager' ),
			'Tax rate'                                     => __( 'Tax rate', 'ferry-booking-manager' ),
			'Taxes and fees'                               => __( 'Taxes and fees', 'ferry-booking-manager' ),
			/* translators: %s: the type’s own fare. */
			'Type default %s'                              => __( 'Type default %s', 'ferry-booking-manager' ),
			'Use 0 to turn the group discount off. Passengers who take no seat do not count.' => __( 'Use 0 to turn the group discount off. Passengers who take no seat do not count.', 'ferry-booking-manager' ),
			'What a crossing costs, what is added on top, and what comes off.' => __( 'What a crossing costs, what is added on top, and what comes off.', 'ferry-booking-manager' ),
			'Yes — tax is already in the fare'             => __( 'Yes — tax is already in the fare', 'ferry-booking-manager' ),
			'passengers'                                   => __( 'passengers', 'ferry-booking-manager' ),
			'Discard'                                      => __( 'Discard', 'ferry-booking-manager' ),
			'Unsaved changes'                              => __( 'Unsaved changes', 'ferry-booking-manager' ),
			'Adjust by a fixed amount'                     => __( 'Adjust by a fixed amount', 'ferry-booking-manager' ),
			'Adjust by a percentage'                       => __( 'Adjust by a percentage', 'ferry-booking-manager' ),
			'Applies to fares on this departure only. A negative value reduces them.' => __( 'Applies to fares on this departure only. A negative value reduces them.', 'ferry-booking-manager' ),
			'Fare adjustment'                              => __( 'Fare adjustment', 'ferry-booking-manager' ),
			'Fixed adjustment per fare'                    => __( 'Fixed adjustment per fare', 'ferry-booking-manager' ),
			'Percentage adjustment'                        => __( 'Percentage adjustment', 'ferry-booking-manager' ),
			'Standard route fares'                         => __( 'Standard route fares', 'ferry-booking-manager' ),
			/* Bookings screen. */
			'Every crossing sold, and what still needs to happen before departure.' => __( 'Every crossing sold, and what still needs to happen before departure.', 'ferry-booking-manager' ),
			'Reference'                                    => __( 'Reference', 'ferry-booking-manager' ),
			'Customer'                                     => __( 'Customer', 'ferry-booking-manager' ),
			'Party'                                        => __( 'Party', 'ferry-booking-manager' ),
			'Total'                                        => __( 'Total', 'ferry-booking-manager' ),
			'Search by reference, customer or email'       => __( 'Search by reference, customer or email', 'ferry-booking-manager' ),
			'Pending'                                      => __( 'Pending', 'ferry-booking-manager' ),
			'On hold'                                      => __( 'On hold', 'ferry-booking-manager' ),
			'Confirmed'                                    => __( 'Confirmed', 'ferry-booking-manager' ),
			'Completed'                                    => __( 'Completed', 'ferry-booking-manager' ),
			'Failed'                                       => __( 'Failed', 'ferry-booking-manager' ),
			'Refunded'                                     => __( 'Refunded', 'ferry-booking-manager' ),
			'Unpaid'                                       => __( 'Unpaid', 'ferry-booking-manager' ),
			'Paid'                                         => __( 'Paid', 'ferry-booking-manager' ),
			'Partially paid'                               => __( 'Partially paid', 'ferry-booking-manager' ),
			'No bookings yet.'                             => __( 'No bookings yet.', 'ferry-booking-manager' ),
			'Bookings made on the website, at the counter or through agents appear here.' => __( 'Bookings made on the website, at the counter or through agents appear here.', 'ferry-booking-manager' ),
			'Cancel booking?'                              => __( 'Cancel booking?', 'ferry-booking-manager' ),
			/* translators: %s: booking reference. */
			'Cancel booking %s? Its capacity returns to the sailing and the customer is notified.' => __( 'Cancel booking %s? Its capacity returns to the sailing and the customer is notified.', 'ferry-booking-manager' ),
			'Cancel booking'                               => __( 'Cancel booking', 'ferry-booking-manager' ),
			'Booking cancelled.'                           => __( 'Booking cancelled.', 'ferry-booking-manager' ),
			'Email'                                        => __( 'Email', 'ferry-booking-manager' ),
			'Phone'                                        => __( 'Phone', 'ferry-booking-manager' ),
			'Journey'                                      => __( 'Journey', 'ferry-booking-manager' ),
			'Return'                                       => __( 'Return', 'ferry-booking-manager' ),
			'One way'                                      => __( 'One way', 'ferry-booking-manager' ),
			/* translators: 1: passenger count, 2: vehicle count. */
			'%1$s passengers, %2$s vehicles'               => __( '%1$s passengers, %2$s vehicles', 'ferry-booking-manager' ),
			'Subtotal'                                     => __( 'Subtotal', 'ferry-booking-manager' ),
			'Discount'                                     => __( 'Discount', 'ferry-booking-manager' ),
			'Tax'                                          => __( 'Tax', 'ferry-booking-manager' ),
			'Payment'                                      => __( 'Payment', 'ferry-booking-manager' ),
			'Created'                                      => __( 'Created', 'ferry-booking-manager' ),
			/* Settings screen. */
			'How the ferry operation behaves, sells and communicates.' => __( 'How the ferry operation behaves, sells and communicates.', 'ferry-booking-manager' ),
			'General'                                      => __( 'General', 'ferry-booking-manager' ),
			'Booking'                                      => __( 'Booking', 'ferry-booking-manager' ),
			'Checkout'                                     => __( 'Checkout', 'ferry-booking-manager' ),
			'Company name'                                 => __( 'Company name', 'ferry-booking-manager' ),
			'Shown on booking confirmations and emails.'   => __( 'Shown on booking confirmations and emails.', 'ferry-booking-manager' ),
			'Support email'                                => __( 'Support email', 'ferry-booking-manager' ),
			'Support phone'                                => __( 'Support phone', 'ferry-booking-manager' ),
			'Terms URL'                                    => __( 'Terms URL', 'ferry-booking-manager' ),
			'Cancellation policy URL'                      => __( 'Cancellation policy URL', 'ferry-booking-manager' ),
			'Booking reference prefix'                     => __( 'Booking reference prefix', 'ferry-booking-manager' ),
			/* translators: %s: the reference prefix. */
			'References look like PREFIX-1001.'            => __( 'References look like PREFIX-1001.', 'ferry-booking-manager' ),
			'Hold minutes'                                 => __( 'Hold minutes', 'ferry-booking-manager' ),
			/* translators: unit of time. */
			'minutes'                                      => __( 'minutes', 'ferry-booking-manager' ),
			'How long capacity stays reserved while a customer completes checkout.' => __( 'How long capacity stays reserved while a customer completes checkout.', 'ferry-booking-manager' ),
			'Minimum lead time'                            => __( 'Minimum lead time', 'ferry-booking-manager' ),
			'minutes before departure'                     => __( 'minutes before departure', 'ferry-booking-manager' ),
			'Use 0 to accept bookings until the sailing departs or its booking window closes.' => __( 'Use 0 to accept bookings until the sailing departs or its booking window closes.', 'ferry-booking-manager' ),
			'Maximum booking horizon'                      => __( 'Maximum booking horizon', 'ferry-booking-manager' ),
			'days ahead'                                   => __( 'days ahead', 'ferry-booking-manager' ),
			'Use 0 for no horizon beyond the sailings you have scheduled.' => __( 'Use 0 for no horizon beyond the sailings you have scheduled.', 'ferry-booking-manager' ),
			'Allow guest checkout'                         => __( 'Allow guest checkout', 'ferry-booking-manager' ),
			'Turn off to require a signed-in account before booking.' => __( 'Turn off to require a signed-in account before booking.', 'ferry-booking-manager' ),
			'Require a phone number'                       => __( 'Require a phone number', 'ferry-booking-manager' ),
			'Checkout engine'                              => __( 'Checkout engine', 'ferry-booking-manager' ),
			'Ferry checkout (cash, transfer, at the port)' => __( 'Ferry checkout (cash, transfer, at the port)', 'ferry-booking-manager' ),
			'WooCommerce checkout and payment gateways'    => __( 'WooCommerce checkout and payment gateways', 'ferry-booking-manager' ),
			'WooCommerce mode creates an order for each booking and lets WooCommerce take the payment.' => __( 'WooCommerce mode creates an order for each booking and lets WooCommerce take the payment.', 'ferry-booking-manager' ),
			'Payment deadline'                             => __( 'Payment deadline', 'ferry-booking-manager' ),
			'How long a booking stays pending payment before the hold is released. Use 0 for no deadline.' => __( 'How long a booking stays pending payment before the hold is released. Use 0 for no deadline.', 'ferry-booking-manager' ),
			'Payment methods'                              => __( 'Payment methods', 'ferry-booking-manager' ),
			'Sender name'                                  => __( 'Sender name', 'ferry-booking-manager' ),
			'Sender address'                               => __( 'Sender address', 'ferry-booking-manager' ),
			'Admin notifications to'                       => __( 'Admin notifications to', 'ferry-booking-manager' ),
			'Notify the admin on every new booking'        => __( 'Notify the admin on every new booking', 'ferry-booking-manager' ),
			'Send booking received'                        => __( 'Send booking received', 'ferry-booking-manager' ),
			'Send booking confirmed'                       => __( 'Send booking confirmed', 'ferry-booking-manager' ),
			'Send booking cancelled'                       => __( 'Send booking cancelled', 'ferry-booking-manager' ),
			'Booking pages'                                => __( 'Booking pages', 'ferry-booking-manager' ),
			'The plugin created these pages on activation. They hold the booking form, the confirmation and the customer booking history.' => __( 'The plugin created these pages on activation. They hold the booking form, the confirmation and the customer booking history.', 'ferry-booking-manager' ),
			'No managed pages found.'                      => __( 'No managed pages found.', 'ferry-booking-manager' ),
			'Missing'                                      => __( 'Missing', 'ferry-booking-manager' ),
			'Booking rules'                                => __( 'Booking rules', 'ferry-booking-manager' ),
			'Checkout and payments'                        => __( 'Checkout and payments', 'ferry-booking-manager' ),
			'Email notifications'                          => __( 'Email notifications', 'ferry-booking-manager' ),
			'Your branding, contact details and how booking references are formed.' => __( 'Your branding, contact details and how booking references are formed.', 'ferry-booking-manager' ),
			'How long capacity is held, how far ahead and how late customers may book.' => __( 'How long capacity is held, how far ahead and how late customers may book.', 'ferry-booking-manager' ),
			'Which engine takes payment, and which payment methods customers may choose.' => __( 'Which engine takes payment, and which payment methods customers may choose.', 'ferry-booking-manager' ),
			'Who receives which booking messages, and from which address.' => __( 'Who receives which booking messages, and from which address.', 'ferry-booking-manager' ),
			'Settings saved.'                              => __( 'Settings saved.', 'ferry-booking-manager' ),
			/* translators: 1: number of enabled messages, 2: total messages. */
			'%1$s of %2$s messages enabled'                => __( '%1$s of %2$s messages enabled', 'ferry-booking-manager' ),
			/* translators: %s: number of enabled payment methods. */
			'%s payment methods enabled'                   => __( '%s payment methods enabled', 'ferry-booking-manager' ),
			'Built-in checkout'                            => __( 'Built-in checkout', 'ferry-booking-manager' ),
			'Check delivery'                               => __( 'Check delivery', 'ferry-booking-manager' ),
			'Default method'                               => __( 'Default method', 'ferry-booking-manager' ),
			'Email settings saved.'                        => __( 'Email settings saved.', 'ferry-booking-manager' ),
			'First enabled method'                         => __( 'First enabled method', 'ferry-booking-manager' ),
			'From address'                                 => __( 'From address', 'ferry-booking-manager' ),
			'From name'                                    => __( 'From name', 'ferry-booking-manager' ),
			'How long an unpaid booking is held before staff should chase it. Use 0 for no deadline.' => __( 'How long an unpaid booking is held before staff should chase it. Use 0 for no deadline.', 'ferry-booking-manager' ),
			'Leave blank to use the site name.'            => __( 'Leave blank to use the site name.', 'ferry-booking-manager' ),
			'Messages'                                     => __( 'Messages', 'ferry-booking-manager' ),
			'No methods enabled — customers cannot pay.'   => __( 'No methods enabled — customers cannot pay.', 'ferry-booking-manager' ),
			'No payment methods are registered.'           => __( 'No payment methods are registered.', 'ferry-booking-manager' ),
			/* translators: %s: payment method name. */
			'Offer %s'                                     => __( 'Offer %s', 'ferry-booking-manager' ),
			'Payment settings saved.'                      => __( 'Payment settings saved.', 'ferry-booking-manager' ),
			'Pre-selected on the booking form.'            => __( 'Pre-selected on the booking form.', 'ferry-booking-manager' ),
			'Send test email'                              => __( 'Send test email', 'ferry-booking-manager' ),
			'Send the test to'                             => __( 'Send the test to', 'ferry-booking-manager' ),
			'Sender'                                       => __( 'Sender', 'ferry-booking-manager' ),
			'Sending…'                                     => __( 'Sending…', 'ferry-booking-manager' ),
			'Sends one message through WordPress using the sender above. If it does not arrive, the problem is mail delivery on this site rather than the plugin.' => __( 'Sends one message through WordPress using the sender above. If it does not arrive, the problem is mail delivery on this site rather than the plugin.', 'ferry-booking-manager' ),
			'Staff notification address'                   => __( 'Staff notification address', 'ferry-booking-manager' ),
			'Switching a message off does not stop the booking — it only stops the notification.' => __( 'Switching a message off does not stop the booking — it only stops the notification.', 'ferry-booking-manager' ),
			/* translators: %s: recipient email address. */
			'Test message sent to %s.'                     => __( 'Test message sent to %s.', 'ferry-booking-manager' ),
			'Use an address on your own domain, or messages will be treated as spam.' => __( 'Use an address on your own domain, or messages will be treated as spam.', 'ferry-booking-manager' ),
			'What the plugin sends, and who it comes from.' => __( 'What the plugin sends, and who it comes from.', 'ferry-booking-manager' ),
			'Where a customer pays, and what they can pay with.' => __( 'Where a customer pays, and what they can pay with.', 'ferry-booking-manager' ),
			'Where new booking alerts go. Leave blank to use the site administrator.' => __( 'Where new booking alerts go. Leave blank to use the site administrator.', 'ferry-booking-manager' ),
			'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.' => __( 'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.', 'ferry-booking-manager' ),
			'WooCommerce is not active, so bookings will fall back to the built-in checkout.' => __( 'WooCommerce is not active, so bookings will fall back to the built-in checkout.', 'ferry-booking-manager' ),
			'WooCommerce — not installed'                  => __( 'WooCommerce — not installed', 'ferry-booking-manager' ),
			'you@example.com'                              => __( 'you@example.com', 'ferry-booking-manager' ),
			/* translators: %s: booking reference. */
			'Booking %s updated.'                          => __( 'Booking %s updated.', 'ferry-booking-manager' ),
			'Booking status'                               => __( 'Booking status', 'ferry-booking-manager' ),
			'Full name'                                    => __( 'Full name', 'ferry-booking-manager' ),
			'Internal notes'                               => __( 'Internal notes', 'ferry-booking-manager' ),
			'Notes'                                        => __( 'Notes', 'ferry-booking-manager' ),
			'Payment method'                               => __( 'Payment method', 'ferry-booking-manager' ),
			'Payment status'                               => __( 'Payment status', 'ferry-booking-manager' ),
			'Price'                                        => __( 'Price', 'ferry-booking-manager' ),
			'Reinstating a cancelled booking is checked against the sailing’s remaining capacity.' => __( 'Reinstating a cancelled booking is checked against the sailing’s remaining capacity.', 'ferry-booking-manager' ),
			'Staff only. Never shown to the customer.'     => __( 'Staff only. Never shown to the customer.', 'ferry-booking-manager' ),
			/* translators: 1: seats booked, 2: total seats. */
			'%1$s of %2$s'                                 => __( '%1$s of %2$s', 'ferry-booking-manager' ),
			/* translators: %s: number of seats booked. */
			'%s booked'                                    => __( '%s booked', 'ferry-booking-manager' ),
			/* translators: %s: number of vehicles. */
			'%s vehicles'                                  => __( '%s vehicles', 'ferry-booking-manager' ),
			/* translators: %s: percentage of seats sold. */
			'%s%% full'                                    => __( '%s%% full', 'ferry-booking-manager' ),
			'All bookings'                                 => __( 'All bookings', 'ferry-booking-manager' ),
			'All sailings'                                 => __( 'All sailings', 'ferry-booking-manager' ),
			'How full each crossing is, right now.'        => __( 'How full each crossing is, right now.', 'ferry-booking-manager' ),
			'Latest bookings'                              => __( 'Latest bookings', 'ferry-booking-manager' ),
			'Needs Ferry Booking Manager Pro'              => __( 'Needs Ferry Booking Manager Pro', 'ferry-booking-manager' ),
			'Nothing sails today.'                         => __( 'Nothing sails today.', 'ferry-booking-manager' ),
			/* translators: %s: the date being shown. */
			'Operating day %s'                             => __( 'Operating day %s', 'ferry-booking-manager' ),
			'Plugin'                                       => __( 'Plugin', 'ferry-booking-manager' ),
			'Pro'                                          => __( 'Pro', 'ferry-booking-manager' ),
			'Schedule a departure and it will appear here.' => __( 'Schedule a departure and it will appear here.', 'ferry-booking-manager' ),
			'System information'                           => __( 'System information', 'ferry-booking-manager' ),
			'Timezone'                                     => __( 'Timezone', 'ferry-booking-manager' ),
			'Today'                                        => __( 'Today', 'ferry-booking-manager' ),
			'Today is busier than these totals can add up exactly. The figures are a floor, not a total.' => __( 'Today is busier than these totals can add up exactly. The figures are a floor, not a total.', 'ferry-booking-manager' ),
			'Today’s departures'                           => __( 'Today’s departures', 'ferry-booking-manager' ),
			'Your sailings, passengers and takings for today.' => __( 'Your sailings, passengers and takings for today.', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s / %2$s'                                  => __( '%1$s / %2$s', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'%1$s crossings · %2$s passengers · %3$s vehicles' => __( '%1$s crossings · %2$s passengers · %3$s vehicles', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s of %2$s seats'                           => __( '%1$s of %2$s seats', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s of %2$s seats booked'                    => __( '%1$s of %2$s seats booked', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s passengers · %2$s vehicles'              => __( '%1$s passengers · %2$s vehicles', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'%1$s: %2$s → %3$s'                            => __( '%1$s: %2$s → %3$s', 'ferry-booking-manager' ),
			'1. Crossing'                                  => __( '1. Crossing', 'ferry-booking-manager' ),
			'2. Who is travelling'                         => __( '2. Who is travelling', 'ferry-booking-manager' ),
			'3. Customer'                                  => __( '3. Customer', 'ferry-booking-manager' ),
			'4. Take the money'                            => __( '4. Take the money', 'ferry-booking-manager' ),
			'A blank line starts a new paragraph. Basic formatting and links are kept.' => __( 'A blank line starts a new paragraph. Basic formatting and links are kept.', 'ferry-booking-manager' ),
			'A common first rule: send the departure reminder 24 hours before a crossing.' => __( 'A common first rule: send the departure reminder 24 hours before a crossing.', 'ferry-booking-manager' ),
			'A day, week and month view of every crossing, showing how full each one is, with delays and cancellations made from the same screen.' => __( 'A day, week and month view of every crossing, showing how full each one is, with delays and cancellations made from the same screen.', 'ferry-booking-manager' ),
			'A fast till for selling at the quayside, taking cash or card and printing a ticket on a receipt printer.' => __( 'A fast till for selling at the quayside, taking cash or card and printing a ticket on a receipt printer.', 'ferry-booking-manager' ),
			'A suspended agency keeps its bookings and its balance but cannot make new ones.' => __( 'A suspended agency keeps its bookings and its balance but cannot make new ones.', 'ferry-booking-manager' ),
			'Accent'                                       => __( 'Accent', 'ferry-booking-manager' ),
			'Account'                                      => __( 'Account', 'ferry-booking-manager' ),
			'Add sailings, or generate a timetable from the Sailings screen.' => __( 'Add sailings, or generate a timetable from the Sailings screen.', 'ferry-booking-manager' ),
			'Add them to FBM\\Core\\Assets::translations() so they reach translators.' => __( 'Add them to FBM\\Core\\Assets::translations() so they reach translators.', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Affect %s bookings, without telling anyone'   => __( 'Affect %s bookings, without telling anyone', 'ferry-booking-manager' ),
			'After'                                        => __( 'After', 'ferry-booking-manager' ),
			'Agencies selling on your behalf.'             => __( 'Agencies selling on your behalf.', 'ferry-booking-manager' ),
			'Agency'                                       => __( 'Agency', 'ferry-booking-manager' ),
			'Agency saved.'                                => __( 'Agency saved.', 'ferry-booking-manager' ),
			'All vessels'                                  => __( 'All vessels', 'ferry-booking-manager' ),
			'An adjustment keeps its sign: enter a negative amount to take money off.' => __( 'An adjustment keeps its sign: enter a negative amount to take money off.', 'ferry-booking-manager' ),
			'An agency is a WordPress user holding the Ferry Agent role. Create one under Users, then set its commercial terms here.' => __( 'An agency is a WordPress user holding the Ferry Agent role. Create one under Users, then set its commercial terms here.', 'ferry-booking-manager' ),
			'Any status'                                   => __( 'Any status', 'ferry-booking-manager' ),
			'Appears at the bottom of every message. Variables work here too.' => __( 'Appears at the bottom of every message. Variables work here too.', 'ferry-booking-manager' ),
			'Apply the change'                             => __( 'Apply the change', 'ferry-booking-manager' ),
			'Automations'                                  => __( 'Automations', 'ferry-booking-manager' ),
			'Automations saved.'                           => __( 'Automations saved.', 'ferry-booking-manager' ),
			'Balance'                                      => __( 'Balance', 'ferry-booking-manager' ),
			'Bank transfer, invoice number…'               => __( 'Bank transfer, invoice number…', 'ferry-booking-manager' ),
			'Before'                                       => __( 'Before', 'ferry-booking-manager' ),
			'Body'                                         => __( 'Body', 'ferry-booking-manager' ),
			'Booked'                                       => __( 'Booked', 'ferry-booking-manager' ),
			'Button label'                                 => __( 'Button label', 'ferry-booking-manager' ),
			'Button link'                                  => __( 'Button link', 'ferry-booking-manager' ),
			'By'                                           => __( 'By', 'ferry-booking-manager' ),
			'Calendar view'                                => __( 'Calendar view', 'ferry-booking-manager' ),
			'Can spend'                                    => __( 'Can spend', 'ferry-booking-manager' ),
			'Can still spend'                              => __( 'Can still spend', 'ferry-booking-manager' ),
			'Cash received'                                => __( 'Cash received', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Change %s'                                    => __( 'Change %s', 'ferry-booking-manager' ),
			'Change this crossing'                         => __( 'Change this crossing', 'ferry-booking-manager' ),
			'Changing a crossing that already has passengers on it updates the sailing, keeps every ticket valid, and — if you ask it to — tells the people booked.' => __( 'Changing a crossing that already has passengers on it updates the sailing, keeps every ticket valid, and — if you ask it to — tells the people booked.', 'ferry-booking-manager' ),
			'Check what this changes'                      => __( 'Check what this changes', 'ferry-booking-manager' ),
			'Clear'                                        => __( 'Clear', 'ferry-booking-manager' ),
			'Commission'                                   => __( 'Commission', 'ferry-booking-manager' ),
			'Commission amount'                            => __( 'Commission amount', 'ferry-booking-manager' ),
			'Commission earned'                            => __( 'Commission earned', 'ferry-booking-manager' ),
			'Commission is earned when a booking is paid, and taken back if it is cancelled.' => __( 'Commission is earned when a booking is paid, and taken back if it is cancelled.', 'ferry-booking-manager' ),
			'Commission rate'                              => __( 'Commission rate', 'ferry-booking-manager' ),
			'Commission reversed'                          => __( 'Commission reversed', 'ferry-booking-manager' ),
			'Counter'                                      => __( 'Counter', 'ferry-booking-manager' ),
			'Credit limit'                                 => __( 'Credit limit', 'ferry-booking-manager' ),
			'Crossing updated.'                            => __( 'Crossing updated.', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Crossing updated. %s passengers are being told.' => __( 'Crossing updated. %s passengers are being told.', 'ferry-booking-manager' ),
			'Crossings'                                    => __( 'Crossings', 'ferry-booking-manager' ),
			'Currently taken from the company logo on the General tab.' => __( 'Currently taken from the company logo on the General tab.', 'ferry-booking-manager' ),
			'Day'                                          => __( 'Day', 'ferry-booking-manager' ),
			'Desk 1'                                       => __( 'Desk 1', 'ferry-booking-manager' ),
			'Discard changes'                              => __( 'Discard changes', 'ferry-booking-manager' ),
			'Each booking is only ever acted on once by this rule.' => __( 'Each booking is only ever acted on once by this rule.', 'ferry-booking-manager' ),
			'Edited'                                       => __( 'Edited', 'ferry-booking-manager' ),
			'Email preview'                                => __( 'Email preview', 'ferry-booking-manager' ),
			'Enter what the customer handed over and the change is worked out.' => __( 'Enter what the customer handed over and the change is worked out.', 'ferry-booking-manager' ),
			'Every crossing, and how full it is.'          => __( 'Every crossing, and how full it is.', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Filled in from booking %s.'                   => __( 'Filled in from booking %s.', 'ferry-booking-manager' ),
			'Filled in with example details, because there are no bookings to preview against yet.' => __( 'Filled in with example details, because there are no bookings to preview against yet.', 'ferry-booking-manager' ),
			'Footer'                                       => __( 'Footer', 'ferry-booking-manager' ),
			'Full'                                         => __( 'Full', 'ferry-booking-manager' ),
			'Give a user the Ferry Agent role and they will appear here.' => __( 'Give a user the Ferry Agent role and they will appear here.', 'ferry-booking-manager' ),
			'Heading'                                      => __( 'Heading', 'ferry-booking-manager' ),
			'How commission is worked out'                 => __( 'How commission is worked out', 'ferry-booking-manager' ),
			'How far the account may go below zero. Leave at nothing to require payment up front.' => __( 'How far the account may go below zero. Leave at nothing to require payment up front.', 'ferry-booking-manager' ),
			'How long before departure'                    => __( 'How long before departure', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'How many %s'                                  => __( 'How many %s', 'ferry-booking-manager' ),
			'Just for you — how this rule appears in the list.' => __( 'Just for you — how this rule appears in the list.', 'ferry-booking-manager' ),
			'Kind'                                         => __( 'Kind', 'ferry-booking-manager' ),
			'Leave blank for no button.'                   => __( 'Leave blank for no button.', 'ferry-booking-manager' ),
			'Leave blank to send to yourself'              => __( 'Leave blank to send to yourself', 'ferry-booking-manager' ),
			'Left'                                         => __( 'Left', 'ferry-booking-manager' ),
			'Logo URL'                                     => __( 'Logo URL', 'ferry-booking-manager' ),
			'Look and feel'                                => __( 'Look and feel', 'ferry-booking-manager' ),
			'Look and feel saved.'                         => __( 'Look and feel saved.', 'ferry-booking-manager' ),
			'Measure'                                      => __( 'Measure', 'ferry-booking-manager' ),
			'Message'                                      => __( 'Message', 'ferry-booking-manager' ),
			'Message background'                           => __( 'Message background', 'ferry-booking-manager' ),
			'Month'                                        => __( 'Month', 'ferry-booking-manager' ),
			'Movement'                                     => __( 'Movement', 'ferry-booking-manager' ),
			'Movements cannot be edited or deleted. To correct a mistake, record an adjustment — the error and the correction both stay on the statement.' => __( 'Movements cannot be edited or deleted. To correct a mistake, record an adjustment — the error and the correction both stay on the statement.', 'ferry-booking-manager' ),
			'Moving the departure moves the arrival by the same amount.' => __( 'Moving the departure moves the arrival by the same amount.', 'ferry-booking-manager' ),
			'Name, company or email'                       => __( 'Name, company or email', 'ferry-booking-manager' ),
			'Next customer'                                => __( 'Next customer', 'ferry-booking-manager' ),
			'No agencies yet.'                             => __( 'No agencies yet.', 'ferry-booking-manager' ),
			'No capacity set on these crossings.'          => __( 'No capacity set on these crossings.', 'ferry-booking-manager' ),
			'No crossings on this day.'                    => __( 'No crossings on this day.', 'ferry-booking-manager' ),
			'No crossings.'                                => __( 'No crossings.', 'ferry-booking-manager' ),
			'No limit'                                     => __( 'No limit', 'ferry-booking-manager' ),
			'No rules yet.'                                => __( 'No rules yet.', 'ferry-booking-manager' ),
			'No seat limit'                                => __( 'No seat limit', 'ferry-booking-manager' ),
			'No templates are available.'                  => __( 'No templates are available.', 'ferry-booking-manager' ),
			'Nobody is booked on this crossing yet.'       => __( 'Nobody is booked on this crossing yet.', 'ferry-booking-manager' ),
			'Not on sale'                                  => __( 'Not on sale', 'ferry-booking-manager' ),
			'Note'                                         => __( 'Note', 'ferry-booking-manager' ),
			'Nothing has moved on this account yet.'       => __( 'Nothing has moved on this account yet.', 'ferry-booking-manager' ),
			'Nothing is scheduled in this period.'         => __( 'Nothing is scheduled in this period.', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2. */
			'Notify %1$s bookings covering %2$s passengers' => __( 'Notify %1$s bookings covering %2$s passengers', 'ferry-booking-manager' ),
			'One set of colours and one logo for every message, so a rebrand does not leave one email looking like the old company. Leave a field blank to take it from your General settings.' => __( 'One set of colours and one logo for every message, so a rebrand does not leave one email looking like the old company. Leave a field blank to take it from your General settings.', 'ferry-booking-manager' ),
			'Page background'                              => __( 'Page background', 'ferry-booking-manager' ),
			'Paper'                                        => __( 'Paper', 'ferry-booking-manager' ),
			'Passengers booked'                            => __( 'Passengers booked', 'ferry-booking-manager' ),
			'Paused'                                       => __( 'Paused', 'ferry-booking-manager' ),
			'Pick the day and route, then the departure.'  => __( 'Pick the day and route, then the departure.', 'ferry-booking-manager' ),
			'Print the ticket'                             => __( 'Print the ticket', 'ferry-booking-manager' ),
			'Printed on the receipt, for reconciling a shift.' => __( 'Printed on the receipt, for reconciling a shift.', 'ferry-booking-manager' ),
			'Quiet text'                                   => __( 'Quiet text', 'ferry-booking-manager' ),
			'Record a movement'                            => __( 'Record a movement', 'ferry-booking-manager' ),
			'Record it'                                    => __( 'Record it', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Recorded. The account now holds %s.'          => __( 'Recorded. The account now holds %s.', 'ferry-booking-manager' ),
			'Remove it'                                    => __( 'Remove it', 'ferry-booking-manager' ),
			'Remove this rule?'                            => __( 'Remove this rule?', 'ferry-booking-manager' ),
			'Restore it'                                   => __( 'Restore it', 'ferry-booking-manager' ),
			'Restore the shipped wording'                  => __( 'Restore the shipped wording', 'ferry-booking-manager' ),
			'Restore the shipped wording?'                 => __( 'Restore the shipped wording?', 'ferry-booking-manager' ),
			'Rules that run on their own. Each one watches for something happening and does one thing about it.' => __( 'Rules that run on their own. Each one watches for something happening and does one thing about it.', 'ferry-booking-manager' ),
			'Save agency'                                  => __( 'Save agency', 'ferry-booking-manager' ),
			'Save automations'                             => __( 'Save automations', 'ferry-booking-manager' ),
			'Save look and feel'                           => __( 'Save look and feel', 'ferry-booking-manager' ),
			'Save template'                                => __( 'Save template', 'ferry-booking-manager' ),
			'Seats available'                              => __( 'Seats available', 'ferry-booking-manager' ),
			'Seats filled'                                 => __( 'Seats filled', 'ferry-booking-manager' ),
			'Sell a crossing at the quayside.'             => __( 'Sell a crossing at the quayside.', 'ferry-booking-manager' ),
			'Selling…'                                     => __( 'Selling…', 'ferry-booking-manager' ),
			'Send this message'                            => __( 'Send this message', 'ferry-booking-manager' ),
			'Send yourself a copy'                         => __( 'Send yourself a copy', 'ferry-booking-manager' ),
			'Shared by every message'                      => __( 'Shared by every message', 'ferry-booking-manager' ),
			'Shown to passengers in the message they receive.' => __( 'Shown to passengers in the message they receive.', 'ferry-booking-manager' ),
			'Signed with the same secret as your webhooks, and carrying no passenger details.' => __( 'Signed with the same secret as your webhooks, and carrying no passenger details.', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Sold — %s'                                    => __( 'Sold — %s', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Sold. Reference %s.'                          => __( 'Sold. Reference %s.', 'ferry-booking-manager' ),
			'Statement'                                    => __( 'Statement', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Still %s short'                               => __( 'Still %s short', 'ferry-booking-manager' ),
			'Subject'                                      => __( 'Subject', 'ferry-booking-manager' ),
			'Take payment'                                 => __( 'Take payment', 'ferry-booking-manager' ),
			'Taken'                                        => __( 'Taken', 'ferry-booking-manager' ),
			'Telephone'                                    => __( 'Telephone', 'ferry-booking-manager' ),
			'Tell the passengers already booked'           => __( 'Tell the passengers already booked', 'ferry-booking-manager' ),
			'Template restored to the wording that ships with the plugin.' => __( 'Template restored to the wording that ships with the plugin.', 'ferry-booking-manager' ),
			'Template saved.'                              => __( 'Template saved.', 'ferry-booking-manager' ),
			'Templates'                                    => __( 'Templates', 'ferry-booking-manager' ),
			'Terms'                                        => __( 'Terms', 'ferry-booking-manager' ),
			'Text'                                         => __( 'Text', 'ferry-booking-manager' ),
			'The rule stops running. Anything it has already done stays as it is.' => __( 'The rule stops running. Anything it has already done stays as it is.', 'ferry-booking-manager' ),
			'The ticket and the confirmation go to this address.' => __( 'The ticket and the confirmation go to this address.', 'ferry-booking-manager' ),
			'Then'                                         => __( 'Then', 'ferry-booking-manager' ),
			'This message is currently switched off under Messages, so nothing is sent whatever you write here.' => __( 'This message is currently switched off under Messages, so nothing is sent whatever you write here.', 'ferry-booking-manager' ),
			'This message is switched on under Messages. This screen decides what it says.' => __( 'This message is switched on under Messages. This screen decides what it says.', 'ferry-booking-manager' ),
			'This period has more crossings than one view can show. Narrow it by route or vessel, or use the week view.' => __( 'This period has more crossings than one view can show. Narrow it by route or vessel, or use the week view.', 'ferry-booking-manager' ),
			'This rule is running'                         => __( 'This rule is running', 'ferry-booking-manager' ),
			'This would:'                                  => __( 'This would:', 'ferry-booking-manager' ),
			'Till'                                         => __( 'Till', 'ferry-booking-manager' ),
			'Timed rules are checked every hour by WordPress’s scheduler. On a quiet site the scheduler only runs when somebody visits, so a reminder can be late.' => __( 'Timed rules are checked every hour by WordPress’s scheduler. On a quiet site the scheduler only runs when somebody visits, so a reminder can be late.', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Timed rules are checked every hour. The next check is at %s.' => __( 'Timed rules are checked every hour. The next check is at %s.', 'ferry-booking-manager' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'Total %1$s · Tendered %2$s · Change %3$s'     => __( 'Total %1$s · Tendered %2$s · Change %3$s', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Total %s'                                     => __( 'Total %s', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Totalled over this agency’s %s most recent bookings.' => __( 'Totalled over this agency’s %s most recent bookings.', 'ferry-booking-manager' ),
			'Trading'                                      => __( 'Trading', 'ferry-booking-manager' ),
			'Trading name'                                 => __( 'Trading name', 'ferry-booking-manager' ),
			'Travel agencies with their own logins, commission terms, a prepaid account and a credit limit — each seeing only their own bookings.' => __( 'Travel agencies with their own logins, commission terms, a prepaid account and a credit limit — each seeing only their own bookings.', 'ferry-booking-manager' ),
			'Type one of these anywhere in the subject, heading, body or button. Anything the booking cannot supply comes out blank.' => __( 'Type one of these anywhere in the subject, heading, body or button. Anything the booking cannot supply comes out blank.', 'ferry-booking-manager' ),
			'URL to call'                                  => __( 'URL to call', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'Up to %s passengers on one booking.'          => __( 'Up to %s passengers on one booking.', 'ferry-booking-manager' ),
			'Variables'                                    => __( 'Variables', 'ferry-booking-manager' ),
			'Variables work here: {{route}}, {{departure_time}}, {{booking_number}}.' => __( 'Variables work here: {{route}}, {{departure_time}}, {{booking_number}}.', 'ferry-booking-manager' ),
			'Vehicles booked'                              => __( 'Vehicles booked', 'ferry-booking-manager' ),
			'Week'                                         => __( 'Week', 'ferry-booking-manager' ),
			'What the plugin sends, how it looks, and who it comes from.' => __( 'What the plugin sends, how it looks, and who it comes from.', 'ferry-booking-manager' ),
			'When'                                         => __( 'When', 'ferry-booking-manager' ),
			'Which email'                                  => __( 'Which email', 'ferry-booking-manager' ),
			'Who is booked'                                => __( 'Who is booked', 'ferry-booking-manager' ),
			'Your edits to this template are discarded and it goes back to the wording the plugin ships with.' => __( 'Your edits to this template are discarded and it goes back to the wording the plugin ships with.', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'arrives %s'                                   => __( 'arrives %s', 'ferry-booking-manager' ),
			'day'                                          => __( 'day', 'ferry-booking-manager' ),
			/* translators: %s: a value substituted at runtime. */
			'from %s'                                      => __( 'from %s', 'ferry-booking-manager' ),
			'hours'                                        => __( 'hours', 'ferry-booking-manager' ),
			'week'                                         => __( 'week', 'ferry-booking-manager' ),
			'Nobody is booked on this crossing, so nobody is affected.' => __( 'Nobody is booked on this crossing, so nobody is affected.', 'ferry-booking-manager' ),
			'Change nothing — the values are the same as they are now.' => __( 'Change nothing — the values are the same as they are now.', 'ferry-booking-manager' ),
			'Footer text'                                  => __( 'Footer text', 'ferry-booking-manager' ),
			'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.' => __( 'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.', 'ferry-booking-manager' ),
			'A route'                                      => __( 'A route', 'ferry-booking-manager' ),
			'A vessel'                                     => __( 'A vessel', 'ferry-booking-manager' ),
			'A fare'                                       => __( 'A fare', 'ferry-booking-manager' ),
			'An administrator needs to finish setting up the ferry operation before this part of the dashboard opens.' => __( 'An administrator needs to finish setting up the ferry operation before this part of the dashboard opens.', 'ferry-booking-manager' ),
			'Change payment methods'                       => __( 'Change payment methods', 'ferry-booking-manager' ),
			'Checking…'                                    => __( 'Checking…', 'ferry-booking-manager' ),
			'Continue'                                     => __( 'Continue', 'ferry-booking-manager' ),
			'Currency code'                                => __( 'Currency code', 'ferry-booking-manager' ),
			'Currency symbol'                              => __( 'Currency symbol', 'ferry-booking-manager' ),
			'Finish setup'                                 => __( 'Finish setup', 'ferry-booking-manager' ),
			'Finishing…'                                   => __( 'Finishing…', 'ferry-booking-manager' ),
			'Get started'                                  => __( 'Get started', 'ferry-booking-manager' ),
			'Go to the dashboard'                          => __( 'Go to the dashboard', 'ferry-booking-manager' ),
			'How customers pay'                            => __( 'How customers pay', 'ferry-booking-manager' ),
			'How your business appears on tickets, confirmations and emails. Check what is filled in and correct anything that is wrong.' => __( 'How your business appears on tickets, confirmations and emails. Check what is filled in and correct anything that is wrong.', 'ferry-booking-manager' ),
			'Leave empty to use the usual symbol for the code above.' => __( 'Leave empty to use the usual symbol for the code above.', 'ferry-booking-manager' ),
			'Needs a route'                                => __( 'Needs a route', 'ferry-booking-manager' ),
			'Needs a vessel'                               => __( 'Needs a vessel', 'ferry-booking-manager' ),
			'Needs two ports'                              => __( 'Needs two ports', 'ferry-booking-manager' ),
			'No fare yet: tickets would be free'           => __( 'No fare yet: tickets would be free', 'ferry-booking-manager' ),
			'No future sailings yet'                       => __( 'No future sailings yet', 'ferry-booking-manager' ),
			'No payment method is switched on, so customers could not finish a booking.' => __( 'No payment method is switched on, so customers could not finish a booking.', 'ferry-booking-manager' ),
			'Open Fleet and schedule'                      => __( 'Open Fleet and schedule', 'ferry-booking-manager' ),
			'Open Settings'                                => __( 'Open Settings', 'ferry-booking-manager' ),
			'Open the booking page'                        => __( 'Open the booking page', 'ferry-booking-manager' ),
			'Opens when setup is finished'                 => __( 'Opens when setup is finished', 'ferry-booking-manager' ),
			'Part of a crossing already exists. Pick those ports and that vessel in the wizard below, or finish it on the Fleet and schedule tabs.' => __( 'Part of a crossing already exists. Pick those ports and that vessel in the wizard below, or finish it on the Fleet and schedule tabs.', 'ferry-booking-manager' ),
			'Required: the base fare the other types are worked out from' => __( 'Required: the base fare the other types are worked out from', 'ferry-booking-manager' ),
			'Save and continue'                            => __( 'Save and continue', 'ferry-booking-manager' ),
			/* translators: %s: passenger type name, such as Adult. */
			'Set a fare for %s. It has no price of its own, so without one every ticket would be free.' => __( 'Set a fare for %s. It has no price of its own, so without one every ticket would be free.', 'ferry-booking-manager' ),
			'Setup is finished'                            => __( 'Setup is finished', 'ferry-booking-manager' ),
			'Setup is finished. Your crossing is on sale.' => __( 'Setup is finished. Your crossing is on sale.', 'ferry-booking-manager' ),
			'Setup is not finished yet'                    => __( 'Setup is not finished yet', 'ferry-booking-manager' ),
			'The last check before customers can book. Nothing here is final: payments and pages can be changed at any time.' => __( 'The last check before customers can book. Nothing here is final: payments and pages can be changed at any time.', 'ferry-booking-manager' ),
			'There is no booking page. Create one in Settings → Pages.' => __( 'There is no booking page. Create one in Settings → Pages.', 'ferry-booking-manager' ),
			'Three steps, in order, and your first crossing is on sale. The rest of the dashboard opens when they are done.' => __( 'Three steps, in order, and your first crossing is on sale. The rest of the dashboard opens when they are done.', 'ferry-booking-manager' ),
			'Three-letter ISO code, such as EUR or GBP.'   => __( 'Three-letter ISO code, such as EUR or GBP.', 'ferry-booking-manager' ),
			'Two ports'                                    => __( 'Two ports', 'ferry-booking-manager' ),
			'Upcoming sailings'                            => __( 'Upcoming sailings', 'ferry-booking-manager' ),
			'What a crossing needs'                        => __( 'What a crossing needs', 'ferry-booking-manager' ),
			'Where customers are told to write if something goes wrong.' => __( 'Where customers are told to write if something goes wrong.', 'ferry-booking-manager' ),
			'Where customers book'                         => __( 'Where customers book', 'ferry-booking-manager' ),
			/* translators: %s: three-letter currency code. */
			'WooCommerce is active, so prices use its currency (%s). Change it in WooCommerce → Settings.' => __( 'WooCommerce is active, so prices use its currency (%s). Change it in WooCommerce → Settings.', 'ferry-booking-manager' ),
			'Your first crossing is on sale. Everything can be changed later on its own screen.' => __( 'Your first crossing is on sale. Everything can be changed later on its own screen.', 'ferry-booking-manager' ),
			'Your first crossing is ready to sell. More can be added later from Fleet and schedule.' => __( 'Your first crossing is ready to sell. More can be added later from Fleet and schedule.', 'ferry-booking-manager' ),
		);

		/**
		 * Filters the translation dictionary handed to the JavaScript applications.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $strings Source string => translation.
		 */
		return (array) apply_filters( 'fbm_js_translations', $strings );
	}
}
