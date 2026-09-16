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
			'Dashboard'                                    => __( 'Dashboard', 'magepeople-ferry-booking-system' ),
			'Bookings'                                     => __( 'Bookings', 'magepeople-ferry-booking-system' ),
			'Balance due'                                  => __( 'Balance due', 'magepeople-ferry-booking-system' ),
			'The fare could not be worked out.'            => __( 'The fare could not be worked out.', 'magepeople-ferry-booking-system' ),
			'Working out the fare…'                        => __( 'Working out the fare…', 'magepeople-ferry-booking-system' ),
			'A booking needs a name and an email to send the confirmation to.' => __( 'A booking needs a name and an email to send the confirmation to.', 'magepeople-ferry-booking-system' ),
			'Add at least one passenger or vehicle.'       => __( 'Add at least one passenger or vehicle.', 'magepeople-ferry-booking-system' ),
			'How it was paid for, and anything staff need to record against it.' => __( 'How it was paid for, and anything staff need to record against it.', 'magepeople-ferry-booking-system' ),
			'How many of each, then a name for every one of them. A manifest is a named list, so the details below are what makes it one.' => __( 'How many of each, then a name for every one of them. A manifest is a named list, so the details below are what makes it one.', 'magepeople-ferry-booking-system' ),
			'No vehicle types are set up.'                 => __( 'No vehicle types are set up.', 'magepeople-ferry-booking-system' ),
			'Nobody added yet.'                            => __( 'Nobody added yet.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a passenger or vehicle type name. */
			'One fewer %s'                                 => __( 'One fewer %s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a passenger or vehicle type name. */
			'One more %s'                                  => __( 'One more %s', 'magepeople-ferry-booking-system' ),
			'Pick the sailing they are travelling on.'     => __( 'Pick the sailing they are travelling on.', 'magepeople-ferry-booking-system' ),
			'Search for a returning customer, or type the details of a new one. The confirmation goes to this address.' => __( 'Search for a returning customer, or type the details of a new one. The confirmation goes to this address.', 'magepeople-ferry-booking-system' ),
			'Where they are going and when. Pick the departure they are actually travelling on — the fare and the deck space both come from it.' => __( 'Where they are going and when. Pick the departure they are actually travelling on — the fare and the deck space both come from it.', 'magepeople-ferry-booking-system' ),
			'Field type'                                   => __( 'Field type', 'magepeople-ferry-booking-system' ),
			'Nothing to choose from.'                      => __( 'Nothing to choose from.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: how many sailings were created, 2: the route name. */
			'%1$s sailings scheduled for %2$s.'            => __( '%1$s sailings scheduled for %2$s.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the name of something the wizard is about to create. */
			'%s (new)'                                     => __( '%s (new)', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a number of minutes. */
			'%s minutes'                                   => __( '%s minutes', 'magepeople-ferry-booking-system' ),
			/* translators: %s: how many ports exist. */
			'%s ports'                                     => __( '%s ports', 'magepeople-ferry-booking-system' ),
			/* translators: %s: how many routes exist. */
			'%s routes'                                    => __( '%s routes', 'magepeople-ferry-booking-system' ),
			/* translators: %s: how many vessels exist. */
			'%s vessels'                                   => __( '%s vessels', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a percentage of the base fare. */
			'%s%% of the base fare'                        => __( '%s%% of the base fare', 'magepeople-ferry-booking-system' ),
			'A crossing needs five things, and it needs them in order: two ports, a vessel, a route joining them, a timetable, and a fare. Set them up together and the booking form has something to find.' => __( 'A crossing needs five things, and it needs them in order: two ports, a vessel, a route joining them, a timetable, and a fare. Set them up together and the booking form has something to find.', 'magepeople-ferry-booking-system' ),
			'A crossing needs two different ports.'        => __( 'A crossing needs two different ports.', 'magepeople-ferry-booking-system' ),
			'A crossing takes at least a minute.'          => __( 'A crossing takes at least a minute.', 'magepeople-ferry-booking-system' ),
			'A vessel has to carry somebody.'              => __( 'A vessel has to carry somebody.', 'magepeople-ferry-booking-system' ),
			'Add a new port…'                              => __( 'Add a new port…', 'magepeople-ferry-booking-system' ),
			'Add a new vessel…'                            => __( 'Add a new vessel…', 'magepeople-ferry-booking-system' ),
			'Add another crossing'                         => __( 'Add another crossing', 'magepeople-ferry-booking-system' ),
			'Add at least one departure time.'             => __( 'Add at least one departure time.', 'magepeople-ferry-booking-system' ),
			'Also create the return direction'             => __( 'Also create the return direction', 'magepeople-ferry-booking-system' ),
			'Both directions'                              => __( 'Both directions', 'magepeople-ferry-booking-system' ),
			'Carries vehicles'                             => __( 'Carries vehicles', 'magepeople-ferry-booking-system' ),
			'Choose at least one day.'                     => __( 'Choose at least one day.', 'magepeople-ferry-booking-system' ),
			'Create it all'                                => __( 'Create it all', 'magepeople-ferry-booking-system' ),
			'Creates the mirror route and its own timetable, so return journeys can be sold.' => __( 'Creates the mirror route and its own timetable, so return journeys can be sold.', 'magepeople-ferry-booking-system' ),
			'Crossing time'                                => __( 'Crossing time', 'magepeople-ferry-booking-system' ),
			'Days it runs'                                 => __( 'Days it runs', 'magepeople-ferry-booking-system' ),
			'Departures a week'                            => __( 'Departures a week', 'magepeople-ferry-booking-system' ),
			'Everything a crossing needs before it can be sold, in one place.' => __( 'Everything a crossing needs before it can be sold, in one place.', 'magepeople-ferry-booking-system' ),
			'First day'                                    => __( 'First day', 'magepeople-ferry-booking-system' ),
			'Fleet & schedule'                             => __( 'Fleet & schedule', 'magepeople-ferry-booking-system' ),
			'Fleet and schedule'                           => __( 'Fleet and schedule', 'magepeople-ferry-booking-system' ),
			'Foot passengers only'                         => __( 'Foot passengers only', 'magepeople-ferry-booking-system' ),
			'Last day'                                     => __( 'Last day', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the route name. */
			'No departures fitted the day for %s.'         => __( 'No departures fitted the day for %s.', 'magepeople-ferry-booking-system' ),
			'No ports yet'                                 => __( 'No ports yet', 'magepeople-ferry-booking-system' ),
			'No routes yet'                                => __( 'No routes yet', 'magepeople-ferry-booking-system' ),
			'No vessels yet'                               => __( 'No vessels yet', 'magepeople-ferry-booking-system' ),
			'Nothing has been written yet. This is what Create will make.' => __( 'Nothing has been written yet. This is what Create will make.', 'magepeople-ferry-booking-system' ),
			'Nothing is on sale yet'                       => __( 'Nothing is on sale yet', 'magepeople-ferry-booking-system' ),
			'One direction'                                => __( 'One direction', 'magepeople-ferry-booking-system' ),
			'Optional. Leave blank and the route is known by its name.' => __( 'Optional. Leave blank and the route is known by its name.', 'magepeople-ferry-booking-system' ),
			'Outbound times. The return leg is offset automatically so the vessel is never in two places at once.' => __( 'Outbound times. The return leg is offset automatically so the vessel is never in two places at once.', 'magepeople-ferry-booking-system' ),
			'Passenger fares'                              => __( 'Passenger fares', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the port name. */
			'Port “%s” created.'                           => __( 'Port “%s” created.', 'magepeople-ferry-booking-system' ),
			'Ports, vessel, route, timetable and fares — asked once, written together.' => __( 'Ports, vessel, route, timetable and fares — asked once, written together.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the route name. */
			'Route “%s” created, with its fares.'          => __( 'Route “%s” created, with its fares.', 'magepeople-ferry-booking-system' ),
			'Runs'                                         => __( 'Runs', 'magepeople-ferry-booking-system' ),
			'Set up a crossing'                            => __( 'Set up a crossing', 'magepeople-ferry-booking-system' ),
			'Setting up…'                                  => __( 'Setting up…', 'magepeople-ferry-booking-system' ),
			'Shown on tickets and manifests.'              => __( 'Shown on tickets and manifests.', 'magepeople-ferry-booking-system' ),
			'The boat that works this crossing. Its capacities are what the crossing sells against, so a sailing can never be sold beyond the deck it has.' => __( 'The boat that works this crossing. Its capacities are what the crossing sells against, so a sailing can never be sold beyond the deck it has.', 'magepeople-ferry-booking-system' ),
			'The crossing is set up and on sale.'          => __( 'The crossing is set up and on sale.', 'magepeople-ferry-booking-system' ),
			'The journey itself. A return leg is a separate route on the opposite ports, so an operator who only declares one direction cannot sell a return at all — which is why it is offered here.' => __( 'The journey itself. A return leg is a separate route on the opposite ports, so an operator who only declares one direction cannot sell a return at all — which is why it is offered here.', 'magepeople-ferry-booking-system' ),
			'The last day cannot be before the first.'     => __( 'The last day cannot be before the first.', 'magepeople-ferry-booking-system' ),
			'The length of deck available. Long vehicles are sold against this, not against a headcount.' => __( 'The length of deck available. Long vehicles are sold against this, not against a headcount.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: total minutes after the outbound departure. */
			'The return leaves this long after the vessel arrives, so %s minutes after each outbound departure.' => __( 'The return leaves this long after the vessel arrives, so %s minutes after each outbound departure.', 'magepeople-ferry-booking-system' ),
			'The wizard asks for the ports, the vessel, the route, the timetable and the fares once, then writes them together. The tabs above edit any of it afterwards.' => __( 'The wizard asks for the ports, the vessel, the route, the timetable and the fares once, then writes them together. The tabs above edit any of it afterwards.', 'magepeople-ferry-booking-system' ),
			'This crossing carries vehicles'               => __( 'This crossing carries vehicles', 'magepeople-ferry-booking-system' ),
			'Turn off for a foot-passenger crossing. Vehicle fares and deck space are then not asked for.' => __( 'Turn off for a foot-passenger crossing. Vehicle fares and deck space are then not asked for.', 'magepeople-ferry-booking-system' ),
			'Turnaround'                                   => __( 'Turnaround', 'magepeople-ferry-booking-system' ),
			'Vehicle fares'                                => __( 'Vehicle fares', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the vessel name. */
			'Vessel “%s” created.'                         => __( 'Vessel “%s” created.', 'magepeople-ferry-booking-system' ),
			'What a ticket costs on this crossing. Leave a fare blank to charge the type’s own price; a percentage type works itself out from the base fare.' => __( 'What a ticket costs on this crossing. Leave a fare blank to charge the type’s own price; a percentage type works itself out from the base fare.', 'magepeople-ferry-booking-system' ),
			'When it sails. Every combination of a day and a time below becomes a sailing that can be booked.' => __( 'When it sails. Every combination of a day and a time below becomes a sailing that can be booked.', 'magepeople-ferry-booking-system' ),
			'Where the crossing runs between. Pick a terminal you already have, or describe a new one and it is created with everything else.' => __( 'Where the crossing runs between. Pick a terminal you already have, or describe a new one and it is created with everything else.', 'magepeople-ferry-booking-system' ),
			'Money'                                        => __( 'Money', 'magepeople-ferry-booking-system' ),
			'Operations'                                   => __( 'Operations', 'magepeople-ferry-booking-system' ),
			'System'                                       => __( 'System', 'magepeople-ferry-booking-system' ),
			'What you sell'                                => __( 'What you sell', 'magepeople-ferry-booking-system' ),
			'Lane metre override'                          => __( 'Lane metre override', 'magepeople-ferry-booking-system' ),
			'Leave at 0 to use the vessel’s own lane metres.' => __( 'Leave at 0 to use the vessel’s own lane metres.', 'magepeople-ferry-booking-system' ),
			'At booking'                                   => __( 'At booking', 'magepeople-ferry-booking-system' ),
			'Back'                                         => __( 'Back', 'magepeople-ferry-booking-system' ),
			'Deck space'                                   => __( 'Deck space', 'magepeople-ferry-booking-system' ),
			'Fare on each route'                           => __( 'Fare on each route', 'magepeople-ferry-booking-system' ),
			'Fares'                                        => __( 'Fares', 'magepeople-ferry-booking-system' ),
			'Form steps'                                   => __( 'Form steps', 'magepeople-ferry-booking-system' ),
			'The fare this type charges by default, and what it charges on each route instead.' => __( 'The fare this type charges by default, and what it charges on each route instead.', 'magepeople-ferry-booking-system' ),
			'The vehicle'                                  => __( 'The vehicle', 'magepeople-ferry-booking-system' ),
			'There are no routes yet. Add one and its fare for this vehicle can be set here.' => __( 'There are no routes yet. Add one and its fare for this vehicle can be set here.', 'magepeople-ferry-booking-system' ),
			'This cannot be empty.'                        => __( 'This cannot be empty.', 'magepeople-ferry-booking-system' ),
			'What a customer has to tell you when they bring one of these aboard.' => __( 'What a customer has to tell you when they bring one of these aboard.', 'magepeople-ferry-booking-system' ),
			'What one of these takes off a vessel. Availability is measured against these, so a vessel with 40 lane metres sells out on the numbers here, not on a headcount.' => __( 'What one of these takes off a vessel. Availability is measured against these, so a vessel with 40 lane metres sells out on the numbers here, not on a headcount.', 'magepeople-ferry-booking-system' ),
			'What this class of vehicle is called, and where it sits in your list.' => __( 'What this class of vehicle is called, and where it sits in your list.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the vehicle type’s own formatted fare. */
			'Leave a route blank to charge this type’s own fare of %s. A route that carries no vehicles is not listed.' => __( 'Leave a route blank to charge this type’s own fare of %s. A route that carries no vehicles is not listed.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: comma-separated list of route names. */
			'Saved, but the fare could not be set on: %s. Set it on the Pricing screen.' => __( 'Saved, but the fare could not be set on: %s. Set it on the Pricing screen.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the current step number, 2: how many steps there are. */
			'Step %1$s of %2$s'                            => __( 'Step %1$s of %2$s', 'magepeople-ferry-booking-system' ),
			'Export CSV'                                   => __( 'Export CSV', 'magepeople-ferry-booking-system' ),
			/* translators: %s: formatted amount still outstanding on the booking. */
			'Left to pay: %s'                              => __( 'Left to pay: %s', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the crossing the booking was on, 2: the crossing it moved to. */
			'Moved: %1$s → %2$s'                           => __( 'Moved: %1$s → %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the party the booking had, 2: the party it has now. */
			'Party: %1$s → %2$s'                           => __( 'Party: %1$s → %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: formatted amount owed back to the customer. */
			'Refund owed: %s'                              => __( 'Refund owed: %s', 'magepeople-ferry-booking-system' ),
			'The customer was not told.'                   => __( 'The customer was not told.', 'magepeople-ferry-booking-system' ),
			'The customer was told.'                       => __( 'The customer was told.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: name of the member of staff who made the change. */
			'by %s'                                        => __( 'by %s', 'magepeople-ferry-booking-system' ),
			'Apply change'                                 => __( 'Apply change', 'magepeople-ferry-booking-system' ),
			'Booking updated.'                             => __( 'Booking updated.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: formatted new total for the booking. */
			'Booking updated. New total %s.'               => __( 'Booking updated. New total %s.', 'magepeople-ferry-booking-system' ),
			'Change the party or pick another crossing to see what it comes to.' => __( 'Change the party or pick another crossing to see what it comes to.', 'magepeople-ferry-booking-system' ),
			'Kept on the booking’s history'                => __( 'Kept on the booking’s history', 'magepeople-ferry-booking-system' ),
			'Move to another crossing'                     => __( 'Move to another crossing', 'magepeople-ferry-booking-system' ),
			'New total'                                    => __( 'New total', 'magepeople-ferry-booking-system' ),
			'Tell the customer'                            => __( 'Tell the customer', 'magepeople-ferry-booking-system' ),
			/* translators: %s: formatted amount by which the booking got cheaper. */
			'The booking is %s cheaper. Record the refund on the Refund tab.' => __( 'The booking is %s cheaper. Record the refund on the Refund tab.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: formatted amount the customer still owes. */
			'The customer owes a further %s.'              => __( 'The customer owes a further %s.', 'magepeople-ferry-booking-system' ),
			'The money'                                    => __( 'The money', 'magepeople-ferry-booking-system' ),
			'The new crossing is checked for room before the change is written. Leave off to keep the booking where it is.' => __( 'The new crossing is checked for room before the change is written. Leave off to keep the booking where it is.', 'magepeople-ferry-booking-system' ),
			'The price is unchanged.'                      => __( 'The price is unchanged.', 'magepeople-ferry-booking-system' ),
			'Turn off to correct a booking quietly. Nothing is sent unless an automation is set up for a changed booking.' => __( 'Turn off to correct a booking quietly. Nothing is sent unless an automation is set up for a changed booking.', 'magepeople-ferry-booking-system' ),
			'Was'                                          => __( 'Was', 'magepeople-ferry-booking-system' ),
			'No details were captured.'                    => __( 'No details were captured.', 'magepeople-ferry-booking-system' ),
			'No passenger details were captured for this booking.' => __( 'No passenger details were captured for this booking.', 'magepeople-ferry-booking-system' ),
			'Refund due'                                   => __( 'Refund due', 'magepeople-ferry-booking-system' ),
			'Unknown type'                                 => __( 'Unknown type', 'magepeople-ferry-booking-system' ),
			'Calendar'                                     => __( 'Calendar', 'magepeople-ferry-booking-system' ),
			'Sailings'                                     => __( 'Sailings', 'magepeople-ferry-booking-system' ),
			'Routes'                                       => __( 'Routes', 'magepeople-ferry-booking-system' ),
			'Ports'                                        => __( 'Ports', 'magepeople-ferry-booking-system' ),
			'Vessels'                                      => __( 'Vessels', 'magepeople-ferry-booking-system' ),
			'Pricing'                                      => __( 'Pricing', 'magepeople-ferry-booking-system' ),
			'Passengers'                                   => __( 'Passengers', 'magepeople-ferry-booking-system' ),
			'Vehicles'                                     => __( 'Vehicles', 'magepeople-ferry-booking-system' ),
			'Check-In'                                     => __( 'Check-In', 'magepeople-ferry-booking-system' ),
			'Manifests'                                    => __( 'Manifests', 'magepeople-ferry-booking-system' ),
			'Agents'                                       => __( 'Agents', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the event name, 2: the HTTP status returned. */
			'%1$s accepted (%2$s)'                         => __( '%1$s accepted (%2$s)', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the event name, 2: the HTTP status returned. */
			'%1$s was not accepted (%2$s)'                 => __( '%1$s was not accepted (%2$s)', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of rows written. */
			'%s rows imported.'                            => __( '%s rows imported.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: comma-separated list of column names. */
			'Columns: %s'                                  => __( 'Columns: %s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of rows in the file. */
			'Import %s rows'                               => __( 'Import %s rows', 'magepeople-ferry-booking-system' ),
			'Add an endpoint'                              => __( 'Add an endpoint', 'magepeople-ferry-booking-system' ),
			'Check the file'                               => __( 'Check the file', 'magepeople-ferry-booking-system' ),
			'Choose a CSV file'                            => __( 'Choose a CSV file', 'magepeople-ferry-booking-system' ),
			'Each event is posted as JSON shortly after it happens, never during the request that caused it — so a slow endpoint can never delay a customer.' => __( 'Each event is posted as JSON shortly after it happens, never during the request that caused it — so a slow endpoint can never delay a customer.', 'magepeople-ferry-booking-system' ),
			'Every request carries an X-FBM-Signature header: an HMAC-SHA256 of the exact body, using this secret. Recompute it at your end and compare — if it matches, the call is genuine and nothing was altered on the way.' => __( 'Every request carries an X-FBM-Signature header: an HMAC-SHA256 of the exact body, using this secret. Recompute it at your end and compare — if it matches, the call is genuine and nothing was altered on the way.', 'magepeople-ferry-booking-system' ),
			'Export'                                       => __( 'Export', 'magepeople-ferry-booking-system' ),
			'Getting data out of here, and into here.'     => __( 'Getting data out of here, and into here.', 'magepeople-ferry-booking-system' ),
			'Hide'                                         => __( 'Hide', 'magepeople-ferry-booking-system' ),
			'Import'                                       => __( 'Import', 'magepeople-ferry-booking-system' ),
			'Integrations'                                 => __( 'Integrations', 'magepeople-ferry-booking-system' ),
			'Must start with http:// or https://. Anything else is discarded when you save.' => __( 'Must start with http:// or https://. Anything else is discarded when you save.', 'magepeople-ferry-booking-system' ),
			'No endpoints yet'                             => __( 'No endpoints yet', 'magepeople-ferry-booking-system' ),
			'Nothing is sent anywhere until you add one.'  => __( 'Nothing is sent anywhere until you add one.', 'magepeople-ferry-booking-system' ),
			'Nothing was imported. Fix these and try again:' => __( 'Nothing was imported. Fix these and try again:', 'magepeople-ferry-booking-system' ),
			'Recent deliveries'                            => __( 'Recent deliveries', 'magepeople-ferry-booking-system' ),
			'Remove this endpoint'                         => __( 'Remove this endpoint', 'magepeople-ferry-booking-system' ),
			'Rows in the file'                             => __( 'Rows in the file', 'magepeople-ferry-booking-system' ),
			'Rows with an id update that record; rows without one create a new record. Check it first — nothing is written until you say so, and a single bad row stops the whole file rather than leaving half of it applied.' => __( 'Rows with an id update that record; rows without one create a new record. Check it first — nothing is written until you say so, and a single bad row stops the whole file rather than leaving half of it applied.', 'magepeople-ferry-booking-system' ),
			'Send booking events to another system as they happen, and move your timetable in and out as a spreadsheet.' => __( 'Send booking events to another system as they happen, and move your timetable in and out as a spreadsheet.', 'magepeople-ferry-booking-system' ),
			'Send these events'                            => __( 'Send these events', 'magepeople-ferry-booking-system' ),
			'Sending'                                      => __( 'Sending', 'magepeople-ferry-booking-system' ),
			'Show'                                         => __( 'Show', 'magepeople-ferry-booking-system' ),
			'Take a copy before you change anything, or edit a season of sailings in a spreadsheet and bring it back.' => __( 'Take a copy before you change anything, or edit a season of sailings in a spreadsheet and bring it back.', 'magepeople-ferry-booking-system' ),
			'Turn off to stop sending without losing the setup.' => __( 'Turn off to stop sending without losing the setup.', 'magepeople-ferry-booking-system' ),
			'Webhook signing secret'                       => __( 'Webhook signing secret', 'magepeople-ferry-booking-system' ),
			'Webhooks'                                     => __( 'Webhooks', 'magepeople-ferry-booking-system' ),
			'Webhooks saved.'                              => __( 'Webhooks saved.', 'magepeople-ferry-booking-system' ),
			'What to move'                                 => __( 'What to move', 'magepeople-ferry-booking-system' ),
			'Where events are sent'                        => __( 'Where events are sent', 'magepeople-ferry-booking-system' ),
			'Would create'                                 => __( 'Would create', 'magepeople-ferry-booking-system' ),
			'Would update'                                 => __( 'Would update', 'magepeople-ferry-booking-system' ),
			'Import and export'                            => __( 'Import and export', 'magepeople-ferry-booking-system' ),
			'Verifying a call came from here'              => __( 'Verifying a call came from here', 'magepeople-ferry-booking-system' ),
			/* translators: 1: number of berths, 2: number of rooms, 3: revenue when full. */
			'%1$s berths across %2$s rooms, earning %3$s.' => __( '%1$s berths across %2$s rooms, earning %3$s.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: number of rooms, 2: berths in each. */
			'%1$s rooms of %2$s berths'                    => __( '%1$s rooms of %2$s berths', 'magepeople-ferry-booking-system' ),
			/* translators: 1: number of rooms, 2: total berths, 3: revenue when full. */
			'%1$s rooms sleeping up to %2$s people, earning %3$s.' => __( '%1$s rooms sleeping up to %2$s people, earning %3$s.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the price, 2: how it is sold, 3: how many exist. */
			'%1$s, %2$s — %3$s'                            => __( '%1$s, %2$s — %3$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of rooms. */
			'%s rooms'                                     => __( '%s rooms', 'magepeople-ferry-booking-system' ),
			'Add a cabin class'                            => __( 'Add a cabin class', 'magepeople-ferry-booking-system' ),
			'Add one to start selling overnight accommodation.' => __( 'Add one to start selling overnight accommodation.', 'magepeople-ferry-booking-system' ),
			'Berths in each room'                          => __( 'Berths in each room', 'magepeople-ferry-booking-system' ),
			'Cabins'                                       => __( 'Cabins', 'magepeople-ferry-booking-system' ),
			'Cabins saved.'                                => __( 'Cabins saved.', 'magepeople-ferry-booking-system' ),
			'Customers only see it once this is on and there is at least one room.' => __( 'Customers only see it once this is on and there is at least one room.', 'magepeople-ferry-booking-system' ),
			'Delete this class'                            => __( 'Delete this class', 'magepeople-ferry-booking-system' ),
			'Each class has its own stock, so one selling out never affects another.' => __( 'Each class has its own stock, so one selling out never affects another.', 'magepeople-ferry-booking-system' ),
			'How many people sleep in one room.'           => __( 'How many people sleep in one room.', 'magepeople-ferry-booking-system' ),
			'How many separate rooms the vessel has.'      => __( 'How many separate rooms the vessel has.', 'magepeople-ferry-booking-system' ),
			'New cabin class'                              => __( 'New cabin class', 'magepeople-ferry-booking-system' ),
			'No cabin classes yet'                         => __( 'No cabin classes yet', 'magepeople-ferry-booking-system' ),
			'None on board'                                => __( 'None on board', 'magepeople-ferry-booking-system' ),
			'Nothing, until you say how many rooms the vessel has.' => __( 'Nothing, until you say how many rooms the vessel has.', 'magepeople-ferry-booking-system' ),
			'On a full sailing'                            => __( 'On a full sailing', 'magepeople-ferry-booking-system' ),
			'Price per berth'                              => __( 'Price per berth', 'magepeople-ferry-booking-system' ),
			'Price per room'                               => __( 'Price per room', 'magepeople-ferry-booking-system' ),
			'Rooms of this class on board'                 => __( 'Rooms of this class on board', 'magepeople-ferry-booking-system' ),
			'Sell cabins and berths with their own stock, so the last family cabin selling out does not close the inside doubles.' => __( 'Sell cabins and berths with their own stock, so the last family cabin selling out does not close the inside doubles.', 'magepeople-ferry-booking-system' ),
			'Sold'                                         => __( 'Sold', 'magepeople-ferry-booking-system' ),
			'What the customer sees when choosing.'        => __( 'What the customer sees when choosing.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the highest daily revenue in the range. */
			'Peak %s'                                      => __( 'Peak %s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of bookings actually read. */
			'This range holds more bookings than one report reads. The figures below cover the first %s and are a floor, not a total — narrow the dates for an exact answer.' => __( 'This range holds more bookings than one report reads. The figures below cover the first %s and are a floor, not a total — narrow the dates for an exact answer.', 'magepeople-ferry-booking-system' ),
			'Awaiting payment'                             => __( 'Awaiting payment', 'magepeople-ferry-booking-system' ),
			'By the day the booking was taken, not the day it sails.' => __( 'By the day the booking was taken, not the day it sails.', 'magepeople-ferry-booking-system' ),
			'Collected'                                    => __( 'Collected', 'magepeople-ferry-booking-system' ),
			'Every route'                                  => __( 'Every route', 'magepeople-ferry-booking-system' ),
			'Fare types'                                   => __( 'Fare types', 'magepeople-ferry-booking-system' ),
			'Nothing to show for this range.'              => __( 'Nothing to show for this range.', 'magepeople-ferry-booking-system' ),
			'Nothing was sold in this range.'              => __( 'Nothing was sold in this range.', 'magepeople-ferry-booking-system' ),
			'Revenue'                                      => __( 'Revenue', 'magepeople-ferry-booking-system' ),
			'Revenue by day'                               => __( 'Revenue by day', 'magepeople-ferry-booking-system' ),
			'Revenue over time, and where it came from: by route, vessel, sales channel, payment method, fare type and extra.' => __( 'Revenue over time, and where it came from: by route, vessel, sales channel, payment method, fare type and extra.', 'magepeople-ferry-booking-system' ),
			'Sales channel'                                => __( 'Sales channel', 'magepeople-ferry-booking-system' ),
			'What you sold, and where it came from.'       => __( 'What you sold, and where it came from.', 'magepeople-ferry-booking-system' ),
			'Last 7 days'                                  => __( 'Last 7 days', 'magepeople-ferry-booking-system' ),
			'Last 30 days'                                 => __( 'Last 30 days', 'magepeople-ferry-booking-system' ),
			'Last 90 days'                                 => __( 'Last 90 days', 'magepeople-ferry-booking-system' ),
			'Vessel types'                                 => __( 'Vessel types', 'magepeople-ferry-booking-system' ),
			'Vehicle types'                                => __( 'Vehicle types', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the previous total, 2: the new total. */
			'Total changed from %1$s to %2$s'              => __( 'Total changed from %1$s to %2$s', 'magepeople-ferry-booking-system' ),
			'Already refunded'                             => __( 'Already refunded', 'magepeople-ferry-booking-system' ),
			'Customer gets back'                           => __( 'Customer gets back', 'magepeople-ferry-booking-system' ),
			'Fee'                                          => __( 'Fee', 'magepeople-ferry-booking-system' ),
			'Kept with the refund record'                  => __( 'Kept with the refund record', 'magepeople-ferry-booking-system' ),
			'Leave off to cancel the whole booking. Turn it on to refund one cabin, one passenger or one extra.' => __( 'Leave off to cancel the whole booking. Turn it on to refund one cabin, one passenger or one extra.', 'magepeople-ferry-booking-system' ),
			'Not being cancelled'                          => __( 'Not being cancelled', 'magepeople-ferry-booking-system' ),
			'Nothing has changed on this booking since it was made.' => __( 'Nothing has changed on this booking since it was made.', 'magepeople-ferry-booking-system' ),
			'Only part of the booking'                     => __( 'Only part of the booking', 'magepeople-ferry-booking-system' ),
			'Penalty'                                      => __( 'Penalty', 'magepeople-ferry-booking-system' ),
			'Reason'                                       => __( 'Reason', 'magepeople-ferry-booking-system' ),
			'Record a refund of'                           => __( 'Record a refund of', 'magepeople-ferry-booking-system' ),
			'Recording…'                                   => __( 'Recording…', 'magepeople-ferry-booking-system' ),
			'Refund recorded:'                             => __( 'Refund recorded:', 'magepeople-ferry-booking-system' ),
			'The booking stays on the crossing. Its places are not released.' => __( 'The booking stays on the crossing. Its places are not released.', 'magepeople-ferry-booking-system' ),
			'This refunds everything paid, so the booking is cancelled and its places go back on sale.' => __( 'This refunds everything paid, so the booking is cancelled and its places go back on sale.', 'magepeople-ferry-booking-system' ),
			'Value being cancelled'                        => __( 'Value being cancelled', 'magepeople-ferry-booking-system' ),
			'What the cancelled part was worth. The penalty applies to this, not to the whole booking.' => __( 'What the cancelled part was worth. The penalty applies to this, not to the whole booking.', 'magepeople-ferry-booking-system' ),
			'Use the cancellation policy'                  => __( 'Use the cancellation policy', 'magepeople-ferry-booking-system' ),
			'No penalty'                                   => __( 'No penalty', 'magepeople-ferry-booking-system' ),
			'A percentage of what is cancelled'            => __( 'A percentage of what is cancelled', 'magepeople-ferry-booking-system' ),
			'A fixed fee'                                  => __( 'A fixed fee', 'magepeople-ferry-booking-system' ),
			'Details'                                      => __( 'Details', 'magepeople-ferry-booking-system' ),
			'Refund'                                       => __( 'Refund', 'magepeople-ferry-booking-system' ),
			'History'                                      => __( 'History', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the amount charged. */
			'A booking pays %s.'                           => __( 'A booking pays %s.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the total charged, 2: the amount per leg. */
			'A return booking pays %1$s — %2$s each way.'  => __( 'A return booking pays %1$s — %2$s each way.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: the price, 2: how it is charged. */
			'%1$s, %2$s'                                   => __( '%1$s, %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: how many of the extra were added. */
			'%s added'                                     => __( '%s added', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of passengers. */
			'%s passengers'                                => __( '%s passengers', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of vehicles. */
			'%s vehicle'                                   => __( '%s vehicle', 'magepeople-ferry-booking-system' ),
			/* translators: 1: who is travelling, 2: the amount charged. */
			'A booking with %1$s pays %2$s.'               => __( 'A booking with %1$s pays %2$s.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: who is travelling, 2: the total charged, 3: the amount per leg. */
			'A return booking with %1$s pays %2$s — %3$s each way.' => __( 'A return booking with %1$s pays %2$s — %3$s each way.', 'magepeople-ferry-booking-system' ),
			'Add an extra'                                 => __( 'Add an extra', 'magepeople-ferry-booking-system' ),
			'Add one to start selling meals, pets or bicycles alongside a crossing.' => __( 'Add one to start selling meals, pets or bicycles alongside a crossing.', 'magepeople-ferry-booking-system' ),
			'Charge on each leg of a return'               => __( 'Charge on each leg of a return', 'magepeople-ferry-booking-system' ),
			'Charged'                                      => __( 'Charged', 'magepeople-ferry-booking-system' ),
			'Customers only see it once this is on.'       => __( 'Customers only see it once this is on.', 'magepeople-ferry-booking-system' ),
			'Delete this extra'                            => __( 'Delete this extra', 'magepeople-ferry-booking-system' ),
			'Extras'                                       => __( 'Extras', 'magepeople-ferry-booking-system' ),
			'Extras saved.'                                => __( 'Extras saved.', 'magepeople-ferry-booking-system' ),
			'For example'                                  => __( 'For example', 'magepeople-ferry-booking-system' ),
			'Most one booking may add'                     => __( 'Most one booking may add', 'magepeople-ferry-booking-system' ),
			'New extra'                                    => __( 'New extra', 'magepeople-ferry-booking-system' ),
			'No extras yet'                                => __( 'No extras yet', 'magepeople-ferry-booking-system' ),
			'On for something consumed on both crossings, like a meal. Off for something bought once, like insurance.' => __( 'On for something consumed on both crossings, like a meal. Off for something bought once, like insurance.', 'magepeople-ferry-booking-system' ),
			'On sale'                                      => __( 'On sale', 'magepeople-ferry-booking-system' ),
			'One line, shown under the name.'              => __( 'One line, shown under the name.', 'magepeople-ferry-booking-system' ),
			'Sell meals, pets, bicycles, priority boarding and anything else you carry, charged per booking, per passenger or per vehicle.' => __( 'Sell meals, pets, bicycles, priority boarding and anything else you carry, charged per booking, per passenger or per vehicle.', 'magepeople-ferry-booking-system' ),
			'What a customer can add to a crossing. Only the ones switched on appear on the booking form.' => __( 'What a customer can add to a crossing. Only the ones switched on appear on the booking form.', 'magepeople-ferry-booking-system' ),
			'What the customer sees on the booking form.'  => __( 'What the customer sees on the booking form.', 'magepeople-ferry-booking-system' ),
			'Zero means no limit.'                         => __( 'Zero means no limit.', 'magepeople-ferry-booking-system' ),
			'a booking'                                    => __( 'a booking', 'magepeople-ferry-booking-system' ),
			/* translators: 1: what the rule does, 2: the conditions it applies under. */
			'%1$s, when %2$s'                              => __( '%1$s, when %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: what the rule does. */
			'%s, on every crossing'                        => __( '%s, on every crossing', 'magepeople-ferry-booking-system' ),
			'Add a rule'                                   => __( 'Add a rule', 'magepeople-ferry-booking-system' ),
			'Amount'                                       => __( 'Amount', 'magepeople-ferry-booking-system' ),
			'Any rule below this one is skipped when this one applies.' => __( 'Any rule below this one is skipped when this one applies.', 'magepeople-ferry-booking-system' ),
			'Applied in order, top to bottom. Each rule works on the fare the one above it left behind.' => __( 'Applied in order, top to bottom. Each rule works on the fare the one above it left behind.', 'magepeople-ferry-booking-system' ),
			'Change fares by season, day of the week, departure time, how far ahead someone books, or how full the sailing already is.' => __( 'Change fares by season, day of the week, departure time, how far ahead someone books, or how full the sailing already is.', 'magepeople-ferry-booking-system' ),
			'Delete this rule'                             => __( 'Delete this rule', 'magepeople-ferry-booking-system' ),
			'Fares stay exactly as set on the Fares tab until you add one.' => __( 'Fares stay exactly as set on the Fares tab until you add one.', 'magepeople-ferry-booking-system' ),
			'Leave a rule off while you build it. Nothing is applied until you turn it on.' => __( 'Leave a rule off while you build it. Nothing is applied until you turn it on.', 'magepeople-ferry-booking-system' ),
			'Leave everything blank to apply the rule to every crossing. Anything you fill in narrows it.' => __( 'Leave everything blank to apply the rule to every crossing. Anything you fill in narrows it.', 'magepeople-ferry-booking-system' ),
			'Move down'                                    => __( 'Move down', 'magepeople-ferry-booking-system' ),
			'Move up'                                      => __( 'Move up', 'magepeople-ferry-booking-system' ),
			'Name'                                         => __( 'Name', 'magepeople-ferry-booking-system' ),
			'New rule'                                     => __( 'New rule', 'magepeople-ferry-booking-system' ),
			'No price rules yet'                           => __( 'No price rules yet', 'magepeople-ferry-booking-system' ),
			'Off'                                          => __( 'Off', 'magepeople-ferry-booking-system' ),
			'On'                                           => __( 'On', 'magepeople-ferry-booking-system' ),
			'Price rules'                                  => __( 'Price rules', 'magepeople-ferry-booking-system' ),
			'Pricing rules saved.'                         => __( 'Pricing rules saved.', 'magepeople-ferry-booking-system' ),
			'Rule is on'                                   => __( 'Rule is on', 'magepeople-ferry-booking-system' ),
			'Shown to the customer on the price breakdown, so name it the way you would explain it.' => __( 'Shown to the customer on the price breakdown, so name it the way you would explain it.', 'magepeople-ferry-booking-system' ),
			'Stop after this rule'                         => __( 'Stop after this rule', 'magepeople-ferry-booking-system' ),
			'What it does'                                 => __( 'What it does', 'magepeople-ferry-booking-system' ),
			'When it applies'                              => __( 'When it applies', 'magepeople-ferry-booking-system' ),
			'A named passenger and vehicle list for every sailing, showing who has checked in and boarded, exportable as CSV or PDF.' => __( 'A named passenger and vehicle list for every sailing, showing who has checked in and boarded, exportable as CSV or PDF.', 'magepeople-ferry-booking-system' ),
			'Age'                                          => __( 'Age', 'magepeople-ferry-booking-system' ),
			'Download CSV'                                 => __( 'Download CSV', 'magepeople-ferry-booking-system' ),
			'Driver'                                       => __( 'Driver', 'magepeople-ferry-booking-system' ),
			'Fare type'                                    => __( 'Fare type', 'magepeople-ferry-booking-system' ),
			'First name'                                   => __( 'First name', 'magepeople-ferry-booking-system' ),
			'Length'                                       => __( 'Length', 'magepeople-ferry-booking-system' ),
			'Manifests appear once a crossing is scheduled.' => __( 'Manifests appear once a crossing is scheduled.', 'magepeople-ferry-booking-system' ),
			'Nationality'                                  => __( 'Nationality', 'magepeople-ferry-booking-system' ),
			'No sailings today'                            => __( 'No sailings today', 'magepeople-ferry-booking-system' ),
			'Nobody is booked on this sailing yet.'        => __( 'Nobody is booked on this sailing yet.', 'magepeople-ferry-booking-system' ),
			'Print manifest'                               => __( 'Print manifest', 'magepeople-ferry-booking-system' ),
			'Registration'                                 => __( 'Registration', 'magepeople-ferry-booking-system' ),
			'Surname'                                      => __( 'Surname', 'magepeople-ferry-booking-system' ),
			'Type'                                         => __( 'Type', 'magepeople-ferry-booking-system' ),
			'Vehicle'                                      => __( 'Vehicle', 'magepeople-ferry-booking-system' ),
			'Who and what is aboard each crossing.'        => __( 'Who and what is aboard each crossing.', 'magepeople-ferry-booking-system' ),
			'Any sailing today'                            => __( 'Any sailing today', 'magepeople-ferry-booking-system' ),
			'Boarded'                                      => __( 'Boarded', 'magepeople-ferry-booking-system' ),
			'Check in'                                     => __( 'Check in', 'magepeople-ferry-booking-system' ),
			'Checked in'                                   => __( 'Checked in', 'magepeople-ferry-booking-system' ),
			'Choosing one stops a ticket for a later crossing being boarded onto this vessel.' => __( 'Choosing one stops a ticket for a later crossing being boarded onto this vessel.', 'magepeople-ferry-booking-system' ),
			'Do not board'                                 => __( 'Do not board', 'magepeople-ferry-booking-system' ),
			'Hold the code steady in the frame.'           => __( 'Hold the code steady in the frame.', 'magepeople-ferry-booking-system' ),
			'Look it up'                                   => __( 'Look it up', 'magepeople-ferry-booking-system' ),
			'Mark boarded'                                 => __( 'Mark boarded', 'magepeople-ferry-booking-system' ),
			'Next passenger'                               => __( 'Next passenger', 'magepeople-ferry-booking-system' ),
			'Passenger'                                    => __( 'Passenger', 'magepeople-ferry-booking-system' ),
			'Paste or type a ticket code'                  => __( 'Paste or type a ticket code', 'magepeople-ferry-booking-system' ),
			'Sailing'                                      => __( 'Sailing', 'magepeople-ferry-booking-system' ),
			'Scan a ticket, or type its reference.'        => __( 'Scan a ticket, or type its reference.', 'magepeople-ferry-booking-system' ),
			'Scan tickets from a phone camera, check passengers in and board them, with duplicate-scan protection and a history you can audit.' => __( 'Scan tickets from a phone camera, check passengers in and board them, with duplicate-scan protection and a history you can audit.', 'magepeople-ferry-booking-system' ),
			'Scan with the camera'                         => __( 'Scan with the camera', 'magepeople-ferry-booking-system' ),
			'Scanned by'                                   => __( 'Scanned by', 'magepeople-ferry-booking-system' ),
			'Stop the camera'                              => __( 'Stop the camera', 'magepeople-ferry-booking-system' ),
			'The camera could not be opened. Type the reference instead.' => __( 'The camera could not be opened. Type the reference instead.', 'magepeople-ferry-booking-system' ),
			'This browser cannot use the camera for scanning. Type the reference below.' => __( 'This browser cannot use the camera for scanning. Type the reference below.', 'magepeople-ferry-booking-system' ),
			'Ticket reference'                             => __( 'Ticket reference', 'magepeople-ferry-booking-system' ),
			'Valid'                                        => __( 'Valid', 'magepeople-ferry-booking-system' ),
			'Waiting for a ticket.'                        => __( 'Waiting for a ticket.', 'magepeople-ferry-booking-system' ),
			'Amount taken'                                 => __( 'Amount taken', 'magepeople-ferry-booking-system' ),
			'What the customer has handed over so far.'    => __( 'What the customer has handed over so far.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: traveller type, 2: position in the party. */
			'%1$s %2$s'                                    => __( '%1$s %2$s', 'magepeople-ferry-booking-system' ),
			'A passenger manifest is a named list, so every traveller needs a name.' => __( 'A passenger manifest is a named list, so every traveller needs a name.', 'magepeople-ferry-booking-system' ),
			'Traveller details'                            => __( 'Traveller details', 'magepeople-ferry-booking-system' ),
			'Use the customer name'                        => __( 'Use the customer name', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of seats still available. */
			'%s seats left'                                => __( '%s seats left', 'magepeople-ferry-booking-system' ),
			/* translators: %s: booking reference. */
			'Booking %s created.'                          => __( 'Booking %s created.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: formatted discount amount. */
			'Less %s discount at confirmation.'            => __( 'Less %s discount at confirmation.', 'magepeople-ferry-booking-system' ),
			'Back to bookings'                             => __( 'Back to bookings', 'magepeople-ferry-booking-system' ),
			'Choose a port'                                => __( 'Choose a port', 'magepeople-ferry-booking-system' ),
			'Choose a sailing and who is travelling to see the fare.' => __( 'Choose a sailing and who is travelling to see the fare.', 'magepeople-ferry-booking-system' ),
			'Choose a sailing first.'                      => __( 'Choose a sailing first.', 'magepeople-ferry-booking-system' ),
			'Choose both ports and a date first.'          => __( 'Choose both ports and a date first.', 'magepeople-ferry-booking-system' ),
			'Create booking'                               => __( 'Create booking', 'magepeople-ferry-booking-system' ),
			'Creating…'                                    => __( 'Creating…', 'magepeople-ferry-booking-system' ),
			'Crossing'                                     => __( 'Crossing', 'magepeople-ferry-booking-system' ),
			'Date'                                         => __( 'Date', 'magepeople-ferry-booking-system' ),
			'Find sailings'                                => __( 'Find sailings', 'magepeople-ferry-booking-system' ),
			'Internal note'                                => __( 'Internal note', 'magepeople-ferry-booking-system' ),
			'Name or email'                                => __( 'Name or email', 'magepeople-ferry-booking-system' ),
			'New booking'                                  => __( 'New booking', 'magepeople-ferry-booking-system' ),
			'No sailings on that date.'                    => __( 'No sailings on that date.', 'magepeople-ferry-booking-system' ),
			'Payment and status'                           => __( 'Payment and status', 'magepeople-ferry-booking-system' ),
			'Pick a sailing, add at least one traveller, and give a name and email.' => __( 'Pick a sailing, add at least one traveller, and give a name and email.', 'magepeople-ferry-booking-system' ),
			'Reason for the discount'                      => __( 'Reason for the discount', 'magepeople-ferry-booking-system' ),
			'Search customers'                             => __( 'Search customers', 'magepeople-ferry-booking-system' ),
			'Search for a returning customer, or type the details of a new one.' => __( 'Search for a returning customer, or type the details of a new one.', 'magepeople-ferry-booking-system' ),
			'Searching…'                                   => __( 'Searching…', 'magepeople-ferry-booking-system' ),
			'Sold out'                                     => __( 'Sold out', 'magepeople-ferry-booking-system' ),
			'Staff only. The customer never sees this.'    => __( 'Staff only. The customer never sees this.', 'magepeople-ferry-booking-system' ),
			'Take a booking at the desk or over the phone.' => __( 'Take a booking at the desk or over the phone.', 'magepeople-ferry-booking-system' ),
			'Taken off the fare. Recorded against your account.' => __( 'Taken off the fare. Recorded against your account.', 'magepeople-ferry-booking-system' ),
			'Who is travelling'                            => __( 'Who is travelling', 'magepeople-ferry-booking-system' ),
			'Leave empty to use the admin address'         => __( 'Leave empty to use the admin address', 'magepeople-ferry-booking-system' ),
			'No ferry roles are installed.'                => __( 'No ferry roles are installed.', 'magepeople-ferry-booking-system' ),
			'No payment methods are available.'            => __( 'No payment methods are available.', 'magepeople-ferry-booking-system' ),
			'Permission'                                   => __( 'Permission', 'magepeople-ferry-booking-system' ),
			'Send a test message to'                       => __( 'Send a test message to', 'magepeople-ferry-booking-system' ),
			'Send test message'                            => __( 'Send test message', 'magepeople-ferry-booking-system' ),
			'Settings could not be loaded.'                => __( 'Settings could not be loaded.', 'magepeople-ferry-booking-system' ),
			'Test message sent to'                         => __( 'Test message sent to', 'magepeople-ferry-booking-system' ),
			'View'                                         => __( 'View', 'magepeople-ferry-booking-system' ),
			'people'                                       => __( 'people', 'magepeople-ferry-booking-system' ),
			'person'                                       => __( 'person', 'magepeople-ferry-booking-system' ),
			'Reports'                                      => __( 'Reports', 'magepeople-ferry-booking-system' ),
			'Emails'                                       => __( 'Emails', 'magepeople-ferry-booking-system' ),
			'Payments'                                     => __( 'Payments', 'magepeople-ferry-booking-system' ),
			'Settings'                                     => __( 'Settings', 'magepeople-ferry-booking-system' ),
			'Search'                                       => __( 'Search', 'magepeople-ferry-booking-system' ),
			'Loading'                                      => __( 'Loading', 'magepeople-ferry-booking-system' ),
			'Retry'                                        => __( 'Retry', 'magepeople-ferry-booking-system' ),
			'Cancel'                                       => __( 'Cancel', 'magepeople-ferry-booking-system' ),
			'Save'                                         => __( 'Save', 'magepeople-ferry-booking-system' ),
			'Close'                                        => __( 'Close', 'magepeople-ferry-booking-system' ),
			'Something went wrong.'                        => __( 'Something went wrong.', 'magepeople-ferry-booking-system' ),
			'You do not have permission to view this section.' => __( 'You do not have permission to view this section.', 'magepeople-ferry-booking-system' ),
			'Page not found.'                              => __( 'Page not found.', 'magepeople-ferry-booking-system' ),
			'Available in MagePeople Ferry Booking System Pro.' => __( 'Available in MagePeople Ferry Booking System Pro.', 'magepeople-ferry-booking-system' ),
			'Coming in a later phase.'                     => __( 'Coming in a later phase.', 'magepeople-ferry-booking-system' ),
			'Connected'                                    => __( 'Connected', 'magepeople-ferry-booking-system' ),
			'Disconnected'                                 => __( 'Disconnected', 'magepeople-ferry-booking-system' ),
			'Skip to dashboard content'                    => __( 'Skip to dashboard content', 'magepeople-ferry-booking-system' ),
			'Toggle navigation'                            => __( 'Toggle navigation', 'magepeople-ferry-booking-system' ),
			'Back to WordPress'                            => __( 'Back to WordPress', 'magepeople-ferry-booking-system' ),
			'Start with a working ferry operation'         => __( 'Start with a working ferry operation', 'magepeople-ferry-booking-system' ),
			'Install the demo'                             => __( 'Install the demo', 'magepeople-ferry-booking-system' ),
			'Installing…'                                  => __( 'Installing…', 'magepeople-ferry-booking-system' ),
			'Starting…'                                    => __( 'Starting…', 'magepeople-ferry-booking-system' ),
			'Start from scratch'                           => __( 'Start from scratch', 'magepeople-ferry-booking-system' ),
			'The demo could not be installed.'             => __( 'The demo could not be installed.', 'magepeople-ferry-booking-system' ),
			'Everything it adds is marked as demo content and can be removed again from Settings → Advanced, without touching anything you have created yourself.' => __( 'Everything it adds is marked as demo content and can be removed again from Settings → Advanced, without touching anything you have created yourself.', 'magepeople-ferry-booking-system' ),
			'Ferry Manager'                                => __( 'Ferry Manager', 'magepeople-ferry-booking-system' ),
			'MagePeople Ferry Booking System Pro'          => __( 'MagePeople Ferry Booking System Pro', 'magepeople-ferry-booking-system' ),
			'Plugin version'                               => __( 'Plugin version', 'magepeople-ferry-booking-system' ),
			'WordPress'                                    => __( 'WordPress', 'magepeople-ferry-booking-system' ),
			'PHP'                                          => __( 'PHP', 'magepeople-ferry-booking-system' ),
			'WooCommerce'                                  => __( 'WooCommerce', 'magepeople-ferry-booking-system' ),
			'Site timezone'                                => __( 'Site timezone', 'magepeople-ferry-booking-system' ),
			'Active'                                       => __( 'Active', 'magepeople-ferry-booking-system' ),
			'Not active'                                   => __( 'Not active', 'magepeople-ferry-booking-system' ),
			'Not installed'                                => __( 'Not installed', 'magepeople-ferry-booking-system' ),
			'Operational metrics'                          => __( 'Operational metrics', 'magepeople-ferry-booking-system' ),
			'Today’s sailings, passengers, vehicles, revenue, check-ins and pending payments appear here once the sailing, booking and availability engines are in place.' => __( 'Today’s sailings, passengers, vehicles, revenue, check-ins and pending payments appear here once the sailing, booking and availability engines are in place.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: development phase number. */
			'This module is delivered in development phase %s.' => __( 'This module is delivered in development phase %s.', 'magepeople-ferry-booking-system' ),
			/* translators: record type, e.g. "Port". */
			'%s created.'                                  => __( '%s created.', 'magepeople-ferry-booking-system' ),
			/* translators: record type. */
			'%s deleted.'                                  => __( '%s deleted.', 'magepeople-ferry-booking-system' ),
			/* translators: record type. */
			'%s updated.'                                  => __( '%s updated.', 'magepeople-ferry-booking-system' ),
			'Accepts vehicles'                             => __( 'Accepts vehicles', 'magepeople-ferry-booking-system' ),
			'Actions'                                      => __( 'Actions', 'magepeople-ferry-booking-system' ),
			/* translators: record type. */
			'Add %s'                                       => __( 'Add %s', 'magepeople-ferry-booking-system' ),
			'Add a vessel so sailings have capacity to sell.' => __( 'Add a vessel so sailings have capacity to sell.', 'magepeople-ferry-booking-system' ),
			'Add the terminals you sail between before creating routes.' => __( 'Add the terminals you sail between before creating routes.', 'magepeople-ferry-booking-system' ),
			'Address'                                      => __( 'Address', 'magepeople-ferry-booking-system' ),
			'Add…'                                         => __( 'Add…', 'magepeople-ferry-booking-system' ),
			'All statuses'                                 => __( 'All statuses', 'magepeople-ferry-booking-system' ),
			'Arrived'                                      => __( 'Arrived', 'magepeople-ferry-booking-system' ),
			'Bar, Wi-Fi, Sun deck…'                        => __( 'Bar, Wi-Fi, Sun deck…', 'magepeople-ferry-booking-system' ),
			'Boarding instructions'                        => __( 'Boarding instructions', 'magepeople-ferry-booking-system' ),
			'Cancelled'                                    => __( 'Cancelled', 'magepeople-ferry-booking-system' ),
			'Check-in'                                     => __( 'Check-in', 'magepeople-ferry-booking-system' ),
			'Check-in closes'                              => __( 'Check-in closes', 'magepeople-ferry-booking-system' ),
			'Check-in instructions'                        => __( 'Check-in instructions', 'magepeople-ferry-booking-system' ),
			'City'                                         => __( 'City', 'magepeople-ferry-booking-system' ),
			'Code'                                         => __( 'Code', 'magepeople-ferry-booking-system' ),
			'Contact email'                                => __( 'Contact email', 'magepeople-ferry-booking-system' ),
			'Contact phone'                                => __( 'Contact phone', 'magepeople-ferry-booking-system' ),
			'Country'                                      => __( 'Country', 'magepeople-ferry-booking-system' ),
			'Country code'                                 => __( 'Country code', 'magepeople-ferry-booking-system' ),
			'Create a route once you have at least two ports.' => __( 'Create a route once you have at least two ports.', 'magepeople-ferry-booking-system' ),
			'Crew capacity'                                => __( 'Crew capacity', 'magepeople-ferry-booking-system' ),
			'Default vessel'                               => __( 'Default vessel', 'magepeople-ferry-booking-system' ),
			'Delayed'                                      => __( 'Delayed', 'magepeople-ferry-booking-system' ),
			'Delete'                                       => __( 'Delete', 'magepeople-ferry-booking-system' ),
			/* translators: record type. */
			'Delete %s?'                                   => __( 'Delete %s?', 'magepeople-ferry-booking-system' ),
			'Departed'                                     => __( 'Departed', 'magepeople-ferry-booking-system' ),
			'Description'                                  => __( 'Description', 'magepeople-ferry-booking-system' ),
			'Destination port'                             => __( 'Destination port', 'magepeople-ferry-booking-system' ),
			'Distance'                                     => __( 'Distance', 'magepeople-ferry-booking-system' ),
			'Duration'                                     => __( 'Duration', 'magepeople-ferry-booking-system' ),
			'Edit'                                         => __( 'Edit', 'magepeople-ferry-booking-system' ),
			/* translators: record type. */
			'Edit %s'                                      => __( 'Edit %s', 'magepeople-ferry-booking-system' ),
			'Facilities'                                   => __( 'Facilities', 'magepeople-ferry-booking-system' ),
			'IT'                                           => __( 'IT', 'magepeople-ferry-booking-system' ),
			'Inactive'                                     => __( 'Inactive', 'magepeople-ferry-booking-system' ),
			'Intermediate calls'                           => __( 'Intermediate calls', 'magepeople-ferry-booking-system' ),
			'Lane metres'                                  => __( 'Lane metres', 'magepeople-ferry-booking-system' ),
			'Latitude'                                     => __( 'Latitude', 'magepeople-ferry-booking-system' ),
			'Longitude'                                    => __( 'Longitude', 'magepeople-ferry-booking-system' ),
			'Maintenance'                                  => __( 'Maintenance', 'magepeople-ferry-booking-system' ),
			'Next'                                         => __( 'Next', 'magepeople-ferry-booking-system' ),
			'No'                                           => __( 'No', 'magepeople-ferry-booking-system' ),
			/* translators: plural record type, e.g. "ports". */
			'No %s yet.'                                   => __( 'No %s yet.', 'magepeople-ferry-booking-system' ),
			'No default'                                   => __( 'No default', 'magepeople-ferry-booking-system' ),
			'No matching records.'                         => __( 'No matching records.', 'magepeople-ferry-booking-system' ),
			'Optional. Leave blank and the route is named after its ports.' => __( 'Optional. Leave blank and the route is named after its ports.', 'magepeople-ferry-booking-system' ),
			'Origin port'                                  => __( 'Origin port', 'magepeople-ferry-booking-system' ),
			/* translators: 1: current page, 2: total pages. */
			'Page %1$s of %2$s'                            => __( 'Page %1$s of %2$s', 'magepeople-ferry-booking-system' ),
			'Pagination'                                   => __( 'Pagination', 'magepeople-ferry-booking-system' ),
			'Passenger capacity'                           => __( 'Passenger capacity', 'magepeople-ferry-booking-system' ),
			'Port'                                         => __( 'Port', 'magepeople-ferry-booking-system' ),
			'Port code'                                    => __( 'Port code', 'magepeople-ferry-booking-system' ),
			'Port name'                                    => __( 'Port name', 'magepeople-ferry-booking-system' ),
			'Ports called at along the way, in order.'     => __( 'Ports called at along the way, in order.', 'magepeople-ferry-booking-system' ),
			'Pre-selected when scheduling sailings on this route.' => __( 'Pre-selected when scheduling sailings on this route.', 'magepeople-ferry-booking-system' ),
			'Previous'                                     => __( 'Previous', 'magepeople-ferry-booking-system' ),
			'Registration number'                          => __( 'Registration number', 'magepeople-ferry-booking-system' ),
			'Remove'                                       => __( 'Remove', 'magepeople-ferry-booking-system' ),
			'Route'                                        => __( 'Route', 'magepeople-ferry-booking-system' ),
			'Route code'                                   => __( 'Route code', 'magepeople-ferry-booking-system' ),
			'Route name'                                   => __( 'Route name', 'magepeople-ferry-booking-system' ),
			'Rows per page'                                => __( 'Rows per page', 'magepeople-ferry-booking-system' ),
			'Saving…'                                      => __( 'Saving…', 'magepeople-ferry-booking-system' ),
			'Scheduled'                                    => __( 'Scheduled', 'magepeople-ferry-booking-system' ),
			'Search ports by name or code'                 => __( 'Search ports by name or code', 'magepeople-ferry-booking-system' ),
			'Search routes by name or code'                => __( 'Search routes by name or code', 'magepeople-ferry-booking-system' ),
			'Search vessels by name, code or registration' => __( 'Search vessels by name, code or registration', 'magepeople-ferry-booking-system' ),
			'Select a port'                                => __( 'Select a port', 'magepeople-ferry-booking-system' ),
			'Service speed'                                => __( 'Service speed', 'magepeople-ferry-booking-system' ),
			'Short identifier shown on tickets and manifests, for example NAP.' => __( 'Short identifier shown on tickets and manifests, for example NAP.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: first row, 2: last row, 3: total rows. */
			'Showing %1$s to %2$s of %3$s'                 => __( 'Showing %1$s to %2$s of %3$s', 'magepeople-ferry-booking-system' ),
			'Shown to passengers on their ticket.'         => __( 'Shown to passengers on their ticket.', 'magepeople-ferry-booking-system' ),
			'Status'                                       => __( 'Status', 'magepeople-ferry-booking-system' ),
			'Terminals your sailings depart from and arrive into.' => __( 'Terminals your sailings depart from and arrive into.', 'magepeople-ferry-booking-system' ),
			'The journeys you operate between ports.'      => __( 'The journeys you operate between ports.', 'magepeople-ferry-booking-system' ),
			'Try a different search or filter.'            => __( 'Try a different search or filter.', 'magepeople-ferry-booking-system' ),
			'Turn off for passenger-only crossings.'       => __( 'Turn off for passenger-only crossings.', 'magepeople-ferry-booking-system' ),
			'Used for lane-metre availability when vehicles have different lengths.' => __( 'Used for lane-metre availability when vehicles have different lengths.', 'magepeople-ferry-booking-system' ),
			'Vehicle capacity'                             => __( 'Vehicle capacity', 'magepeople-ferry-booking-system' ),
			'Vehicle deck capacity'                        => __( 'Vehicle deck capacity', 'magepeople-ferry-booking-system' ),
			'Vessel'                                       => __( 'Vessel', 'magepeople-ferry-booking-system' ),
			'Vessel code'                                  => __( 'Vessel code', 'magepeople-ferry-booking-system' ),
			'Vessel name'                                  => __( 'Vessel name', 'magepeople-ferry-booking-system' ),
			'Yes'                                          => __( 'Yes', 'magepeople-ferry-booking-system' ),
			'Your fleet, and the capacity each ship brings to a sailing.' => __( 'Your fleet, and the capacity each ship brings to a sailing.', 'magepeople-ferry-booking-system' ),
			'h'                                            => __( 'h', 'magepeople-ferry-booking-system' ),
			'm'                                            => __( 'm', 'magepeople-ferry-booking-system' ),
			'min'                                          => __( 'min', 'magepeople-ferry-booking-system' ),
			/* translators: record name. */
			'“%s” will be moved to the trash. This cannot be undone from here.' => __( '“%s” will be moved to the trash. This cannot be undone from here.', 'magepeople-ferry-booking-system' ),
			/* translators: number of sailings. */
			'%s already scheduled'                         => __( '%s already scheduled', 'magepeople-ferry-booking-system' ),
			/* translators: number of sailings. */
			'%s in conflict'                               => __( '%s in conflict', 'magepeople-ferry-booking-system' ),
			/* translators: number of sailings. */
			'%s new'                                       => __( '%s new', 'magepeople-ferry-booking-system' ),
			/* translators: number of sailings. */
			'%s sailings created.'                         => __( '%s sailings created.', 'magepeople-ferry-booking-system' ),
			'Add sailing'                                  => __( 'Add sailing', 'magepeople-ferry-booking-system' ),
			'All routes'                                   => __( 'All routes', 'magepeople-ferry-booking-system' ),
			'Already scheduled'                            => __( 'Already scheduled', 'magepeople-ferry-booking-system' ),
			'Arrival'                                      => __( 'Arrival', 'magepeople-ferry-booking-system' ),
			'Bookings close'                               => __( 'Bookings close', 'magepeople-ferry-booking-system' ),
			'Bookings open'                                => __( 'Bookings open', 'magepeople-ferry-booking-system' ),
			'Bulk schedule'                                => __( 'Bulk schedule', 'magepeople-ferry-booking-system' ),
			'Capacity'                                     => __( 'Capacity', 'magepeople-ferry-booking-system' ),
			/* translators: number of sailings. */
			'Create %s sailings'                           => __( 'Create %s sailings', 'magepeople-ferry-booking-system' ),
			'Create sailings'                              => __( 'Create sailings', 'magepeople-ferry-booking-system' ),
			'Days of the week'                             => __( 'Days of the week', 'magepeople-ferry-booking-system' ),
			'Delete sailing?'                              => __( 'Delete sailing?', 'magepeople-ferry-booking-system' ),
			'Departure'                                    => __( 'Departure', 'magepeople-ferry-booking-system' ),
			'Departure times'                              => __( 'Departure times', 'magepeople-ferry-booking-system' ),
			'Each time runs on every selected day.'        => __( 'Each time runs on every selected day.', 'magepeople-ferry-booking-system' ),
			'Edit sailing'                                 => __( 'Edit sailing', 'magepeople-ferry-booking-system' ),
			'Every dated departure you operate.'           => __( 'Every dated departure you operate.', 'magepeople-ferry-booking-system' ),
			'From'                                         => __( 'From', 'magepeople-ferry-booking-system' ),
			'Leave at 0 to use the vessel’s own capacity.' => __( 'Leave at 0 to use the vessel’s own capacity.', 'magepeople-ferry-booking-system' ),
			'Leave blank to accept bookings until departure.' => __( 'Leave blank to accept bookings until departure.', 'magepeople-ferry-booking-system' ),
			'Leave blank to derive it from the route duration.' => __( 'Leave blank to derive it from the route duration.', 'magepeople-ferry-booking-system' ),
			'No sailings scheduled.'                       => __( 'No sailings scheduled.', 'magepeople-ferry-booking-system' ),
			'Operational notes'                            => __( 'Operational notes', 'magepeople-ferry-booking-system' ),
			'Passenger capacity override'                  => __( 'Passenger capacity override', 'magepeople-ferry-booking-system' ),
			'Preview'                                      => __( 'Preview', 'magepeople-ferry-booking-system' ),
			'Repeat a departure pattern across a date range.' => __( 'Repeat a departure pattern across a date range.', 'magepeople-ferry-booking-system' ),
			'Sailing created.'                             => __( 'Sailing created.', 'magepeople-ferry-booking-system' ),
			'Sailing deleted.'                             => __( 'Sailing deleted.', 'magepeople-ferry-booking-system' ),
			'Sailing updated.'                             => __( 'Sailing updated.', 'magepeople-ferry-booking-system' ),
			'Schedule one departure.'                      => __( 'Schedule one departure.', 'magepeople-ferry-booking-system' ),
			'Search sailings'                              => __( 'Search sailings', 'magepeople-ferry-booking-system' ),
			'Select a route'                               => __( 'Select a route', 'magepeople-ferry-booking-system' ),
			'Select a vessel'                              => __( 'Select a vessel', 'magepeople-ferry-booking-system' ),
			/* translators: total number of candidates. */
			'Showing the first 60 of %s.'                  => __( 'Showing the first 60 of %s.', 'magepeople-ferry-booking-system' ),
			'The sailing will be moved to the trash. Sailings that already carry bookings cannot be deleted — cancel them instead so passengers are notified.' => __( 'The sailing will be moved to the trash. Sailings that already carry bookings cannot be deleted — cancel them instead so passengers are notified.', 'magepeople-ferry-booking-system' ),
			'To'                                           => __( 'To', 'magepeople-ferry-booking-system' ),
			'Use bulk scheduling to lay out a season in one go, or add a single departure.' => __( 'Use bulk scheduling to lay out a season in one go, or add a single departure.', 'magepeople-ferry-booking-system' ),
			'Vehicle capacity override'                    => __( 'Vehicle capacity override', 'magepeople-ferry-booking-system' ),
			'Vessel busy'                                  => __( 'Vessel busy', 'magepeople-ferry-booking-system' ),
			/* translators: name of the conflicting sailing. */
			'Vessel busy: %s'                              => __( 'Vessel busy: %s', 'magepeople-ferry-booking-system' ),
			'Will be created'                              => __( 'Will be created', 'magepeople-ferry-booking-system' ),
			'min before departure'                         => __( 'min before departure', 'magepeople-ferry-booking-system' ),
			'seats'                                        => __( 'seats', 'magepeople-ferry-booking-system' ),
			'vehicles'                                     => __( 'vehicles', 'magepeople-ferry-booking-system' ),
			/* translators: 1: number of required fields, 2: number of optional fields. */
			'%1$s required, %2$s optional'                 => __( '%1$s required, %2$s optional', 'magepeople-ferry-booking-system' ),
			/* translators: 1: lowest age in the band, 2: highest age in the band. */
			'%1$s to %2$s'                                 => __( '%1$s to %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: lowest age in the band. */
			'%s and over'                                  => __( '%s and over', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a length in metres. */
			'%s m'                                         => __( '%s m', 'magepeople-ferry-booking-system' ),
			/* translators: %s: percentage of the base passenger fare. */
			'%s%% of base'                                 => __( '%s%% of base', 'magepeople-ferry-booking-system' ),
			'A fixed fare'                                 => __( 'A fixed fare', 'magepeople-ferry-booking-system' ),
			'A percentage of the base fare'                => __( 'A percentage of the base fare', 'magepeople-ferry-booking-system' ),
			'Add a custom field'                           => __( 'Add a custom field', 'magepeople-ferry-booking-system' ),
			'Add a vehicle type to start selling deck space.' => __( 'Add a vehicle type to start selling deck space.', 'magepeople-ferry-booking-system' ),
			'Add at least one passenger type so sailings have something to sell.' => __( 'Add at least one passenger type so sailings have something to sell.', 'magepeople-ferry-booking-system' ),
			'Add field'                                    => __( 'Add field', 'magepeople-ferry-booking-system' ),
			'Added on top of the fare, multiplied by the lane metres above.' => __( 'Added on top of the fare, multiplied by the lane metres above.', 'magepeople-ferry-booking-system' ),
			'Additional fare per lane metre'               => __( 'Additional fare per lane metre', 'magepeople-ferry-booking-system' ),
			'Allow a trailer'                              => __( 'Allow a trailer', 'magepeople-ferry-booking-system' ),
			'Any age'                                      => __( 'Any age', 'magepeople-ferry-booking-system' ),
			'Appears on manifests and boarding lists, for example CAR.' => __( 'Appears on manifests and boarding lists, for example CAR.', 'magepeople-ferry-booking-system' ),
			'Appears on manifests and tickets, for example ADULT.' => __( 'Appears on manifests and tickets, for example ADULT.', 'magepeople-ferry-booking-system' ),
			'Ask for a date of birth'                      => __( 'Ask for a date of birth', 'magepeople-ferry-booking-system' ),
			'Ask the customer for the real length and height, not just the class maximum.' => __( 'Ask the customer for the real length and height, not just the class maximum.', 'magepeople-ferry-booking-system' ),
			'Base'                                         => __( 'Base', 'magepeople-ferry-booking-system' ),
			'Bicycle'                                      => __( 'Bicycle', 'magepeople-ferry-booking-system' ),
			'Booking form saved.'                          => __( 'Booking form saved.', 'magepeople-ferry-booking-system' ),
			'Bus'                                          => __( 'Bus', 'magepeople-ferry-booking-system' ),
			'Camper'                                       => __( 'Camper', 'magepeople-ferry-booking-system' ),
			'Car'                                          => __( 'Car', 'magepeople-ferry-booking-system' ),
			'Category'                                     => __( 'Category', 'magepeople-ferry-booking-system' ),
			'Custom'                                       => __( 'Custom', 'magepeople-ferry-booking-system' ),
			'Display order'                                => __( 'Display order', 'magepeople-ferry-booking-system' ),
			'Fare'                                         => __( 'Fare', 'magepeople-ferry-booking-system' ),
			'Fare amount'                                  => __( 'Fare amount', 'magepeople-ferry-booking-system' ),
			'Field label'                                  => __( 'Field label', 'magepeople-ferry-booking-system' ),
			'Free'                                         => __( 'Free', 'magepeople-ferry-booking-system' ),
			'How many of a vessel’s vehicle spaces one of these takes.' => __( 'How many of a vessel’s vehicle spaces one of these takes.', 'magepeople-ferry-booking-system' ),
			'Lane metres consumed'                         => __( 'Lane metres consumed', 'magepeople-ferry-booking-system' ),
			'Leave at 0 to use the maximum length. This is what deck availability is measured against.' => __( 'Leave at 0 to use the maximum length. This is what deck availability is measured against.', 'magepeople-ferry-booking-system' ),
			'Lower numbers appear first on the booking form.' => __( 'Lower numbers appear first on the booking form.', 'magepeople-ferry-booking-system' ),
			'Max'                                          => __( 'Max', 'magepeople-ferry-booking-system' ),
			'Maximum age'                                  => __( 'Maximum age', 'magepeople-ferry-booking-system' ),
			'Maximum height'                               => __( 'Maximum height', 'magepeople-ferry-booking-system' ),
			'Maximum length'                               => __( 'Maximum length', 'magepeople-ferry-booking-system' ),
			'Maximum per booking'                          => __( 'Maximum per booking', 'magepeople-ferry-booking-system' ),
			'Maximum weight'                               => __( 'Maximum weight', 'magepeople-ferry-booking-system' ),
			'Maximum width'                                => __( 'Maximum width', 'magepeople-ferry-booking-system' ),
			'Minibus'                                      => __( 'Minibus', 'magepeople-ferry-booking-system' ),
			'Minimum age'                                  => __( 'Minimum age', 'magepeople-ferry-booking-system' ),
			'Minimum per booking'                          => __( 'Minimum per booking', 'magepeople-ferry-booking-system' ),
			'Motorcycle'                                   => __( 'Motorcycle', 'magepeople-ferry-booking-system' ),
			'Must travel with an adult'                    => __( 'Must travel with an adult', 'magepeople-ferry-booking-system' ),
			'Name shown to customers'                      => __( 'Name shown to customers', 'magepeople-ferry-booking-system' ),
			'Occupies a passenger seat'                    => __( 'Occupies a passenger seat', 'magepeople-ferry-booking-system' ),
			'Order'                                        => __( 'Order', 'magepeople-ferry-booking-system' ),
			'Other'                                        => __( 'Other', 'magepeople-ferry-booking-system' ),
			'Passenger fares included'                     => __( 'Passenger fares included', 'magepeople-ferry-booking-system' ),
			'Passenger type'                               => __( 'Passenger type', 'magepeople-ferry-booking-system' ),
			'Passenger types'                              => __( 'Passenger types', 'magepeople-ferry-booking-system' ),
			'Passengers up to this number travel free with the vehicle.' => __( 'Passengers up to this number travel free with the vehicle.', 'magepeople-ferry-booking-system' ),
			'Percentage fares are worked out from this type. Only one type can hold it.' => __( 'Percentage fares are worked out from this type. Only one type can hold it.', 'magepeople-ferry-booking-system' ),
			'Percentage of the base fare'                  => __( 'Percentage of the base fare', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the field label to remove. */
			'Remove %s'                                    => __( 'Remove %s', 'magepeople-ferry-booking-system' ),
			'Require a registration number'                => __( 'Require a registration number', 'magepeople-ferry-booking-system' ),
			'Require driver details'                       => __( 'Require driver details', 'magepeople-ferry-booking-system' ),
			'Require exact dimensions'                     => __( 'Require exact dimensions', 'magepeople-ferry-booking-system' ),
			'Routes and sailings can override this later.' => __( 'Routes and sailings can override this later.', 'magepeople-ferry-booking-system' ),
			'SUV'                                          => __( 'SUV', 'magepeople-ferry-booking-system' ),
			'Save changes'                                 => __( 'Save changes', 'magepeople-ferry-booking-system' ),
			'Search passenger types by name or code'       => __( 'Search passenger types by name or code', 'magepeople-ferry-booking-system' ),
			'Search vehicle types by name or code'         => __( 'Search vehicle types by name or code', 'magepeople-ferry-booking-system' ),
			'Seat'                                         => __( 'Seat', 'magepeople-ferry-booking-system' ),
			'Slots'                                        => __( 'Slots', 'magepeople-ferry-booking-system' ),
			'The categories of traveller you sell, what each one costs, and what each one occupies.' => __( 'The categories of traveller you sell, what each one costs, and what each one occupies.', 'magepeople-ferry-booking-system' ),
			'This is the base fare'                        => __( 'This is the base fare', 'magepeople-ferry-booking-system' ),
			'Trailer'                                      => __( 'Trailer', 'magepeople-ferry-booking-system' ),
			'Truck'                                        => __( 'Truck', 'magepeople-ferry-booking-system' ),
			'Turn off for lap infants, who travel without consuming capacity.' => __( 'Turn off for lap infants, who travel without consuming capacity.', 'magepeople-ferry-booking-system' ),
			'Turn on when the age band has to be proven at check-in.' => __( 'Turn on when the age band has to be proven at check-in.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the age a passenger must be under. */
			'Under %s'                                     => __( 'Under %s', 'magepeople-ferry-booking-system' ),
			'Use -1 for no lower limit.'                   => __( 'Use -1 for no lower limit.', 'magepeople-ferry-booking-system' ),
			'Use -1 for no upper limit.'                   => __( 'Use -1 for no upper limit.', 'magepeople-ferry-booking-system' ),
			'Use 0 to allow any number.'                   => __( 'Use 0 to allow any number.', 'magepeople-ferry-booking-system' ),
			'Van'                                          => __( 'Van', 'magepeople-ferry-booking-system' ),
			'Vehicle slots consumed'                       => __( 'Vehicle slots consumed', 'magepeople-ferry-booking-system' ),
			'Vehicle type'                                 => __( 'Vehicle type', 'magepeople-ferry-booking-system' ),
			'What you carry on the vehicle deck, and how much space each one takes.' => __( 'What you carry on the vehicle deck, and how much space each one takes.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: percentage of the base fare. */
			'%s%% of the base fare unless set here'        => __( '%s%% of the base fare unless set here', 'magepeople-ferry-booking-system' ),
			'A blank fare falls back to the type’s own price. Percentage passenger types are worked out from the base type’s fare on this route.' => __( 'A blank fare falls back to the type’s own price. Percentage passenger types are worked out from the base type’s fare on this route.', 'magepeople-ferry-booking-system' ),
			'Always free'                                  => __( 'Always free', 'magepeople-ferry-booking-system' ),
			'Applied on top of the fare. Every total the customer sees is worked out on the server with these rules.' => __( 'Applied on top of the fare. Every total the customer sees is worked out on the server with these rules.', 'magepeople-ferry-booking-system' ),
			'Charge tax'                                   => __( 'Charge tax', 'magepeople-ferry-booking-system' ),
			'Default'                                      => __( 'Default', 'magepeople-ferry-booking-system' ),
			'Discounts'                                    => __( 'Discounts', 'magepeople-ferry-booking-system' ),
			/* translators: %s: passenger or vehicle type name. */
			'Fare for %s'                                  => __( 'Fare for %s', 'magepeople-ferry-booking-system' ),
			'Fares are set per route, so create a route first.' => __( 'Fares are set per route, so create a route first.', 'magepeople-ferry-booking-system' ),
			'Fares by route'                               => __( 'Fares by route', 'magepeople-ferry-booking-system' ),
			'Fares include tax'                            => __( 'Fares include tax', 'magepeople-ferry-booking-system' ),
			'Fares saved.'                                 => __( 'Fares saved.', 'magepeople-ferry-booking-system' ),
			'Fee name'                                     => __( 'Fee name', 'magepeople-ferry-booking-system' ),
			'Fees'                                         => __( 'Fees', 'magepeople-ferry-booking-system' ),
			'Group discount'                               => __( 'Group discount', 'magepeople-ferry-booking-system' ),
			'Group discount from'                          => __( 'Group discount from', 'magepeople-ferry-booking-system' ),
			'No routes yet.'                               => __( 'No routes yet.', 'magepeople-ferry-booking-system' ),
			'No — add tax on top'                          => __( 'No — add tax on top', 'magepeople-ferry-booking-system' ),
			'Pricing saved.'                               => __( 'Pricing saved.', 'magepeople-ferry-booking-system' ),
			'Reductions are always taken from the fare before tax, and can never exceed it.' => __( 'Reductions are always taken from the fare before tax, and can never exceed it.', 'magepeople-ferry-booking-system' ),
			'Return journey discount'                      => __( 'Return journey discount', 'magepeople-ferry-booking-system' ),
			'Shown on tickets and invoices, for example VAT or GST.' => __( 'Shown on tickets and invoices, for example VAT or GST.', 'magepeople-ferry-booking-system' ),
			'Taken off the whole round trip when both legs are booked together.' => __( 'Taken off the whole round trip when both legs are booked together.', 'magepeople-ferry-booking-system' ),
			'Tax booking fees too'                         => __( 'Tax booking fees too', 'magepeople-ferry-booking-system' ),
			'Tax name'                                     => __( 'Tax name', 'magepeople-ferry-booking-system' ),
			'Tax rate'                                     => __( 'Tax rate', 'magepeople-ferry-booking-system' ),
			'Taxes and fees'                               => __( 'Taxes and fees', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the type’s own fare. */
			'Type default %s'                              => __( 'Type default %s', 'magepeople-ferry-booking-system' ),
			'Use 0 to turn the group discount off. Passengers who take no seat do not count.' => __( 'Use 0 to turn the group discount off. Passengers who take no seat do not count.', 'magepeople-ferry-booking-system' ),
			'What a crossing costs, what is added on top, and what comes off.' => __( 'What a crossing costs, what is added on top, and what comes off.', 'magepeople-ferry-booking-system' ),
			'Yes — tax is already in the fare'             => __( 'Yes — tax is already in the fare', 'magepeople-ferry-booking-system' ),
			'passengers'                                   => __( 'passengers', 'magepeople-ferry-booking-system' ),
			'Discard'                                      => __( 'Discard', 'magepeople-ferry-booking-system' ),
			'Unsaved changes'                              => __( 'Unsaved changes', 'magepeople-ferry-booking-system' ),
			'Adjust by a fixed amount'                     => __( 'Adjust by a fixed amount', 'magepeople-ferry-booking-system' ),
			'Adjust by a percentage'                       => __( 'Adjust by a percentage', 'magepeople-ferry-booking-system' ),
			'Applies to fares on this departure only. A negative value reduces them.' => __( 'Applies to fares on this departure only. A negative value reduces them.', 'magepeople-ferry-booking-system' ),
			'Fare adjustment'                              => __( 'Fare adjustment', 'magepeople-ferry-booking-system' ),
			'Fixed adjustment per fare'                    => __( 'Fixed adjustment per fare', 'magepeople-ferry-booking-system' ),
			'Percentage adjustment'                        => __( 'Percentage adjustment', 'magepeople-ferry-booking-system' ),
			'Standard route fares'                         => __( 'Standard route fares', 'magepeople-ferry-booking-system' ),
			/* Bookings screen. */
			'Every crossing sold, and what still needs to happen before departure.' => __( 'Every crossing sold, and what still needs to happen before departure.', 'magepeople-ferry-booking-system' ),
			'Reference'                                    => __( 'Reference', 'magepeople-ferry-booking-system' ),
			'Customer'                                     => __( 'Customer', 'magepeople-ferry-booking-system' ),
			'Party'                                        => __( 'Party', 'magepeople-ferry-booking-system' ),
			'Total'                                        => __( 'Total', 'magepeople-ferry-booking-system' ),
			'Search by reference, customer or email'       => __( 'Search by reference, customer or email', 'magepeople-ferry-booking-system' ),
			'Pending'                                      => __( 'Pending', 'magepeople-ferry-booking-system' ),
			'On hold'                                      => __( 'On hold', 'magepeople-ferry-booking-system' ),
			'Confirmed'                                    => __( 'Confirmed', 'magepeople-ferry-booking-system' ),
			'Completed'                                    => __( 'Completed', 'magepeople-ferry-booking-system' ),
			'Failed'                                       => __( 'Failed', 'magepeople-ferry-booking-system' ),
			'Refunded'                                     => __( 'Refunded', 'magepeople-ferry-booking-system' ),
			'Unpaid'                                       => __( 'Unpaid', 'magepeople-ferry-booking-system' ),
			'Paid'                                         => __( 'Paid', 'magepeople-ferry-booking-system' ),
			'Partially paid'                               => __( 'Partially paid', 'magepeople-ferry-booking-system' ),
			'No bookings yet.'                             => __( 'No bookings yet.', 'magepeople-ferry-booking-system' ),
			'Bookings made on the website, at the counter or through agents appear here.' => __( 'Bookings made on the website, at the counter or through agents appear here.', 'magepeople-ferry-booking-system' ),
			'Cancel booking?'                              => __( 'Cancel booking?', 'magepeople-ferry-booking-system' ),
			/* translators: %s: booking reference. */
			'Cancel booking %s? Its capacity returns to the sailing and the customer is notified.' => __( 'Cancel booking %s? Its capacity returns to the sailing and the customer is notified.', 'magepeople-ferry-booking-system' ),
			'Cancel booking'                               => __( 'Cancel booking', 'magepeople-ferry-booking-system' ),
			'Booking cancelled.'                           => __( 'Booking cancelled.', 'magepeople-ferry-booking-system' ),
			'Email'                                        => __( 'Email', 'magepeople-ferry-booking-system' ),
			'Phone'                                        => __( 'Phone', 'magepeople-ferry-booking-system' ),
			'Journey'                                      => __( 'Journey', 'magepeople-ferry-booking-system' ),
			'Return'                                       => __( 'Return', 'magepeople-ferry-booking-system' ),
			'One way'                                      => __( 'One way', 'magepeople-ferry-booking-system' ),
			/* translators: 1: passenger count, 2: vehicle count. */
			'%1$s passengers, %2$s vehicles'               => __( '%1$s passengers, %2$s vehicles', 'magepeople-ferry-booking-system' ),
			'Subtotal'                                     => __( 'Subtotal', 'magepeople-ferry-booking-system' ),
			'Discount'                                     => __( 'Discount', 'magepeople-ferry-booking-system' ),
			'Tax'                                          => __( 'Tax', 'magepeople-ferry-booking-system' ),
			'Payment'                                      => __( 'Payment', 'magepeople-ferry-booking-system' ),
			'Created'                                      => __( 'Created', 'magepeople-ferry-booking-system' ),
			/* Settings screen. */
			'How the ferry operation behaves, sells and communicates.' => __( 'How the ferry operation behaves, sells and communicates.', 'magepeople-ferry-booking-system' ),
			'General'                                      => __( 'General', 'magepeople-ferry-booking-system' ),
			'Booking'                                      => __( 'Booking', 'magepeople-ferry-booking-system' ),
			'Checkout'                                     => __( 'Checkout', 'magepeople-ferry-booking-system' ),
			'Company name'                                 => __( 'Company name', 'magepeople-ferry-booking-system' ),
			'Shown on booking confirmations and emails.'   => __( 'Shown on booking confirmations and emails.', 'magepeople-ferry-booking-system' ),
			'Support email'                                => __( 'Support email', 'magepeople-ferry-booking-system' ),
			'Support phone'                                => __( 'Support phone', 'magepeople-ferry-booking-system' ),
			'Terms URL'                                    => __( 'Terms URL', 'magepeople-ferry-booking-system' ),
			'Cancellation policy URL'                      => __( 'Cancellation policy URL', 'magepeople-ferry-booking-system' ),
			'Booking reference prefix'                     => __( 'Booking reference prefix', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the reference prefix. */
			'References look like PREFIX-1001.'            => __( 'References look like PREFIX-1001.', 'magepeople-ferry-booking-system' ),
			'Hold minutes'                                 => __( 'Hold minutes', 'magepeople-ferry-booking-system' ),
			/* translators: unit of time. */
			'minutes'                                      => __( 'minutes', 'magepeople-ferry-booking-system' ),
			'How long capacity stays reserved while a customer completes checkout.' => __( 'How long capacity stays reserved while a customer completes checkout.', 'magepeople-ferry-booking-system' ),
			'Minimum lead time'                            => __( 'Minimum lead time', 'magepeople-ferry-booking-system' ),
			'minutes before departure'                     => __( 'minutes before departure', 'magepeople-ferry-booking-system' ),
			'Use 0 to accept bookings until the sailing departs or its booking window closes.' => __( 'Use 0 to accept bookings until the sailing departs or its booking window closes.', 'magepeople-ferry-booking-system' ),
			'Maximum booking horizon'                      => __( 'Maximum booking horizon', 'magepeople-ferry-booking-system' ),
			'days ahead'                                   => __( 'days ahead', 'magepeople-ferry-booking-system' ),
			'Use 0 for no horizon beyond the sailings you have scheduled.' => __( 'Use 0 for no horizon beyond the sailings you have scheduled.', 'magepeople-ferry-booking-system' ),
			'Allow guest checkout'                         => __( 'Allow guest checkout', 'magepeople-ferry-booking-system' ),
			'Turn off to require a signed-in account before booking.' => __( 'Turn off to require a signed-in account before booking.', 'magepeople-ferry-booking-system' ),
			'Require a phone number'                       => __( 'Require a phone number', 'magepeople-ferry-booking-system' ),
			'Checkout engine'                              => __( 'Checkout engine', 'magepeople-ferry-booking-system' ),
			'Ferry checkout (cash, transfer, at the port)' => __( 'Ferry checkout (cash, transfer, at the port)', 'magepeople-ferry-booking-system' ),
			'WooCommerce checkout and payment gateways'    => __( 'WooCommerce checkout and payment gateways', 'magepeople-ferry-booking-system' ),
			'WooCommerce mode creates an order for each booking and lets WooCommerce take the payment.' => __( 'WooCommerce mode creates an order for each booking and lets WooCommerce take the payment.', 'magepeople-ferry-booking-system' ),
			'Payment deadline'                             => __( 'Payment deadline', 'magepeople-ferry-booking-system' ),
			'How long a booking stays pending payment before the hold is released. Use 0 for no deadline.' => __( 'How long a booking stays pending payment before the hold is released. Use 0 for no deadline.', 'magepeople-ferry-booking-system' ),
			'Payment methods'                              => __( 'Payment methods', 'magepeople-ferry-booking-system' ),
			'Sender name'                                  => __( 'Sender name', 'magepeople-ferry-booking-system' ),
			'Sender address'                               => __( 'Sender address', 'magepeople-ferry-booking-system' ),
			'Admin notifications to'                       => __( 'Admin notifications to', 'magepeople-ferry-booking-system' ),
			'Notify the admin on every new booking'        => __( 'Notify the admin on every new booking', 'magepeople-ferry-booking-system' ),
			'Send booking received'                        => __( 'Send booking received', 'magepeople-ferry-booking-system' ),
			'Send booking confirmed'                       => __( 'Send booking confirmed', 'magepeople-ferry-booking-system' ),
			'Send booking cancelled'                       => __( 'Send booking cancelled', 'magepeople-ferry-booking-system' ),
			'Booking pages'                                => __( 'Booking pages', 'magepeople-ferry-booking-system' ),
			'The plugin created these pages on activation. They hold the booking form, the confirmation and the customer booking history.' => __( 'The plugin created these pages on activation. They hold the booking form, the confirmation and the customer booking history.', 'magepeople-ferry-booking-system' ),
			'No managed pages found.'                      => __( 'No managed pages found.', 'magepeople-ferry-booking-system' ),
			'Missing'                                      => __( 'Missing', 'magepeople-ferry-booking-system' ),
			'Booking rules'                                => __( 'Booking rules', 'magepeople-ferry-booking-system' ),
			'Checkout and payments'                        => __( 'Checkout and payments', 'magepeople-ferry-booking-system' ),
			'Email notifications'                          => __( 'Email notifications', 'magepeople-ferry-booking-system' ),
			'Your branding, contact details and how booking references are formed.' => __( 'Your branding, contact details and how booking references are formed.', 'magepeople-ferry-booking-system' ),
			'How long capacity is held, how far ahead and how late customers may book.' => __( 'How long capacity is held, how far ahead and how late customers may book.', 'magepeople-ferry-booking-system' ),
			'Which engine takes payment, and which payment methods customers may choose.' => __( 'Which engine takes payment, and which payment methods customers may choose.', 'magepeople-ferry-booking-system' ),
			'Who receives which booking messages, and from which address.' => __( 'Who receives which booking messages, and from which address.', 'magepeople-ferry-booking-system' ),
			'Settings saved.'                              => __( 'Settings saved.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: number of enabled messages, 2: total messages. */
			'%1$s of %2$s messages enabled'                => __( '%1$s of %2$s messages enabled', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of enabled payment methods. */
			'%s payment methods enabled'                   => __( '%s payment methods enabled', 'magepeople-ferry-booking-system' ),
			'Built-in checkout'                            => __( 'Built-in checkout', 'magepeople-ferry-booking-system' ),
			'Check delivery'                               => __( 'Check delivery', 'magepeople-ferry-booking-system' ),
			'Default method'                               => __( 'Default method', 'magepeople-ferry-booking-system' ),
			'Email settings saved.'                        => __( 'Email settings saved.', 'magepeople-ferry-booking-system' ),
			'First enabled method'                         => __( 'First enabled method', 'magepeople-ferry-booking-system' ),
			'From address'                                 => __( 'From address', 'magepeople-ferry-booking-system' ),
			'From name'                                    => __( 'From name', 'magepeople-ferry-booking-system' ),
			'How long an unpaid booking is held before staff should chase it. Use 0 for no deadline.' => __( 'How long an unpaid booking is held before staff should chase it. Use 0 for no deadline.', 'magepeople-ferry-booking-system' ),
			'Leave blank to use the site name.'            => __( 'Leave blank to use the site name.', 'magepeople-ferry-booking-system' ),
			'Messages'                                     => __( 'Messages', 'magepeople-ferry-booking-system' ),
			'No methods enabled — customers cannot pay.'   => __( 'No methods enabled — customers cannot pay.', 'magepeople-ferry-booking-system' ),
			'No payment methods are registered.'           => __( 'No payment methods are registered.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: payment method name. */
			'Offer %s'                                     => __( 'Offer %s', 'magepeople-ferry-booking-system' ),
			'Payment settings saved.'                      => __( 'Payment settings saved.', 'magepeople-ferry-booking-system' ),
			'Pre-selected on the booking form.'            => __( 'Pre-selected on the booking form.', 'magepeople-ferry-booking-system' ),
			'Send test email'                              => __( 'Send test email', 'magepeople-ferry-booking-system' ),
			'Send the test to'                             => __( 'Send the test to', 'magepeople-ferry-booking-system' ),
			'Sender'                                       => __( 'Sender', 'magepeople-ferry-booking-system' ),
			'Sending…'                                     => __( 'Sending…', 'magepeople-ferry-booking-system' ),
			'Sends one message through WordPress using the sender above. If it does not arrive, the problem is mail delivery on this site rather than the plugin.' => __( 'Sends one message through WordPress using the sender above. If it does not arrive, the problem is mail delivery on this site rather than the plugin.', 'magepeople-ferry-booking-system' ),
			'Staff notification address'                   => __( 'Staff notification address', 'magepeople-ferry-booking-system' ),
			'Switching a message off does not stop the booking — it only stops the notification.' => __( 'Switching a message off does not stop the booking — it only stops the notification.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: recipient email address. */
			'Test message sent to %s.'                     => __( 'Test message sent to %s.', 'magepeople-ferry-booking-system' ),
			'Use an address on your own domain, or messages will be treated as spam.' => __( 'Use an address on your own domain, or messages will be treated as spam.', 'magepeople-ferry-booking-system' ),
			'What the plugin sends, and who it comes from.' => __( 'What the plugin sends, and who it comes from.', 'magepeople-ferry-booking-system' ),
			'Where a customer pays, and what they can pay with.' => __( 'Where a customer pays, and what they can pay with.', 'magepeople-ferry-booking-system' ),
			'Where new booking alerts go. Leave blank to use the site administrator.' => __( 'Where new booking alerts go. Leave blank to use the site administrator.', 'magepeople-ferry-booking-system' ),
			'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.' => __( 'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.', 'magepeople-ferry-booking-system' ),
			'WooCommerce is not active, so bookings will fall back to the built-in checkout.' => __( 'WooCommerce is not active, so bookings will fall back to the built-in checkout.', 'magepeople-ferry-booking-system' ),
			'WooCommerce — not installed'                  => __( 'WooCommerce — not installed', 'magepeople-ferry-booking-system' ),
			'you@example.com'                              => __( 'you@example.com', 'magepeople-ferry-booking-system' ),
			/* translators: %s: booking reference. */
			'Booking %s updated.'                          => __( 'Booking %s updated.', 'magepeople-ferry-booking-system' ),
			'Booking status'                               => __( 'Booking status', 'magepeople-ferry-booking-system' ),
			'Full name'                                    => __( 'Full name', 'magepeople-ferry-booking-system' ),
			'Internal notes'                               => __( 'Internal notes', 'magepeople-ferry-booking-system' ),
			'Notes'                                        => __( 'Notes', 'magepeople-ferry-booking-system' ),
			'Payment method'                               => __( 'Payment method', 'magepeople-ferry-booking-system' ),
			'Payment status'                               => __( 'Payment status', 'magepeople-ferry-booking-system' ),
			'Price'                                        => __( 'Price', 'magepeople-ferry-booking-system' ),
			'Reinstating a cancelled booking is checked against the sailing’s remaining capacity.' => __( 'Reinstating a cancelled booking is checked against the sailing’s remaining capacity.', 'magepeople-ferry-booking-system' ),
			'Staff only. Never shown to the customer.'     => __( 'Staff only. Never shown to the customer.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: seats booked, 2: total seats. */
			'%1$s of %2$s'                                 => __( '%1$s of %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of seats booked. */
			'%s booked'                                    => __( '%s booked', 'magepeople-ferry-booking-system' ),
			/* translators: %s: number of vehicles. */
			'%s vehicles'                                  => __( '%s vehicles', 'magepeople-ferry-booking-system' ),
			/* translators: %s: percentage of seats sold. */
			'%s%% full'                                    => __( '%s%% full', 'magepeople-ferry-booking-system' ),
			'All bookings'                                 => __( 'All bookings', 'magepeople-ferry-booking-system' ),
			'All sailings'                                 => __( 'All sailings', 'magepeople-ferry-booking-system' ),
			'How full each crossing is, right now.'        => __( 'How full each crossing is, right now.', 'magepeople-ferry-booking-system' ),
			'Latest bookings'                              => __( 'Latest bookings', 'magepeople-ferry-booking-system' ),
			'Needs MagePeople Ferry Booking System Pro'    => __( 'Needs MagePeople Ferry Booking System Pro', 'magepeople-ferry-booking-system' ),
			'Nothing sails today.'                         => __( 'Nothing sails today.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: the date being shown. */
			'Operating day %s'                             => __( 'Operating day %s', 'magepeople-ferry-booking-system' ),
			'Plugin'                                       => __( 'Plugin', 'magepeople-ferry-booking-system' ),
			'Pro'                                          => __( 'Pro', 'magepeople-ferry-booking-system' ),
			'Schedule a departure and it will appear here.' => __( 'Schedule a departure and it will appear here.', 'magepeople-ferry-booking-system' ),
			'System information'                           => __( 'System information', 'magepeople-ferry-booking-system' ),
			'Timezone'                                     => __( 'Timezone', 'magepeople-ferry-booking-system' ),
			'Today'                                        => __( 'Today', 'magepeople-ferry-booking-system' ),
			'Today is busier than these totals can add up exactly. The figures are a floor, not a total.' => __( 'Today is busier than these totals can add up exactly. The figures are a floor, not a total.', 'magepeople-ferry-booking-system' ),
			'Today’s departures'                           => __( 'Today’s departures', 'magepeople-ferry-booking-system' ),
			'Your sailings, passengers and takings for today.' => __( 'Your sailings, passengers and takings for today.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s / %2$s'                                  => __( '%1$s / %2$s', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'%1$s crossings · %2$s passengers · %3$s vehicles' => __( '%1$s crossings · %2$s passengers · %3$s vehicles', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s of %2$s seats'                           => __( '%1$s of %2$s seats', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s of %2$s seats booked'                    => __( '%1$s of %2$s seats booked', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2. */
			'%1$s passengers · %2$s vehicles'              => __( '%1$s passengers · %2$s vehicles', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'%1$s: %2$s → %3$s'                            => __( '%1$s: %2$s → %3$s', 'magepeople-ferry-booking-system' ),
			'1. Crossing'                                  => __( '1. Crossing', 'magepeople-ferry-booking-system' ),
			'2. Who is travelling'                         => __( '2. Who is travelling', 'magepeople-ferry-booking-system' ),
			'3. Customer'                                  => __( '3. Customer', 'magepeople-ferry-booking-system' ),
			'4. Take the money'                            => __( '4. Take the money', 'magepeople-ferry-booking-system' ),
			'A blank line starts a new paragraph. Basic formatting and links are kept.' => __( 'A blank line starts a new paragraph. Basic formatting and links are kept.', 'magepeople-ferry-booking-system' ),
			'A common first rule: send the departure reminder 24 hours before a crossing.' => __( 'A common first rule: send the departure reminder 24 hours before a crossing.', 'magepeople-ferry-booking-system' ),
			'A day, week and month view of every crossing, showing how full each one is, with delays and cancellations made from the same screen.' => __( 'A day, week and month view of every crossing, showing how full each one is, with delays and cancellations made from the same screen.', 'magepeople-ferry-booking-system' ),
			'A fast till for selling at the quayside, taking cash or card and printing a ticket on a receipt printer.' => __( 'A fast till for selling at the quayside, taking cash or card and printing a ticket on a receipt printer.', 'magepeople-ferry-booking-system' ),
			'A suspended agency keeps its bookings and its balance but cannot make new ones.' => __( 'A suspended agency keeps its bookings and its balance but cannot make new ones.', 'magepeople-ferry-booking-system' ),
			'Accent'                                       => __( 'Accent', 'magepeople-ferry-booking-system' ),
			'Account'                                      => __( 'Account', 'magepeople-ferry-booking-system' ),
			'Add sailings, or generate a timetable from the Sailings screen.' => __( 'Add sailings, or generate a timetable from the Sailings screen.', 'magepeople-ferry-booking-system' ),
			'Add them to FBM\\Core\\Assets::translations() so they reach translators.' => __( 'Add them to FBM\\Core\\Assets::translations() so they reach translators.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Affect %s bookings, without telling anyone'   => __( 'Affect %s bookings, without telling anyone', 'magepeople-ferry-booking-system' ),
			'After'                                        => __( 'After', 'magepeople-ferry-booking-system' ),
			'Agencies selling on your behalf.'             => __( 'Agencies selling on your behalf.', 'magepeople-ferry-booking-system' ),
			'Agency'                                       => __( 'Agency', 'magepeople-ferry-booking-system' ),
			'Agency saved.'                                => __( 'Agency saved.', 'magepeople-ferry-booking-system' ),
			'All vessels'                                  => __( 'All vessels', 'magepeople-ferry-booking-system' ),
			'An adjustment keeps its sign: enter a negative amount to take money off.' => __( 'An adjustment keeps its sign: enter a negative amount to take money off.', 'magepeople-ferry-booking-system' ),
			'An agency is a WordPress user holding the Ferry Agent role. Create one under Users, then set its commercial terms here.' => __( 'An agency is a WordPress user holding the Ferry Agent role. Create one under Users, then set its commercial terms here.', 'magepeople-ferry-booking-system' ),
			'Any status'                                   => __( 'Any status', 'magepeople-ferry-booking-system' ),
			'Appears at the bottom of every message. Variables work here too.' => __( 'Appears at the bottom of every message. Variables work here too.', 'magepeople-ferry-booking-system' ),
			'Apply the change'                             => __( 'Apply the change', 'magepeople-ferry-booking-system' ),
			'Automations'                                  => __( 'Automations', 'magepeople-ferry-booking-system' ),
			'Automations saved.'                           => __( 'Automations saved.', 'magepeople-ferry-booking-system' ),
			'Balance'                                      => __( 'Balance', 'magepeople-ferry-booking-system' ),
			'Bank transfer, invoice number…'               => __( 'Bank transfer, invoice number…', 'magepeople-ferry-booking-system' ),
			'Before'                                       => __( 'Before', 'magepeople-ferry-booking-system' ),
			'Body'                                         => __( 'Body', 'magepeople-ferry-booking-system' ),
			'Booked'                                       => __( 'Booked', 'magepeople-ferry-booking-system' ),
			'Button label'                                 => __( 'Button label', 'magepeople-ferry-booking-system' ),
			'Button link'                                  => __( 'Button link', 'magepeople-ferry-booking-system' ),
			'By'                                           => __( 'By', 'magepeople-ferry-booking-system' ),
			'Calendar view'                                => __( 'Calendar view', 'magepeople-ferry-booking-system' ),
			'Can spend'                                    => __( 'Can spend', 'magepeople-ferry-booking-system' ),
			'Can still spend'                              => __( 'Can still spend', 'magepeople-ferry-booking-system' ),
			'Cash received'                                => __( 'Cash received', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Change %s'                                    => __( 'Change %s', 'magepeople-ferry-booking-system' ),
			'Change this crossing'                         => __( 'Change this crossing', 'magepeople-ferry-booking-system' ),
			'Changing a crossing that already has passengers on it updates the sailing, keeps every ticket valid, and — if you ask it to — tells the people booked.' => __( 'Changing a crossing that already has passengers on it updates the sailing, keeps every ticket valid, and — if you ask it to — tells the people booked.', 'magepeople-ferry-booking-system' ),
			'Check what this changes'                      => __( 'Check what this changes', 'magepeople-ferry-booking-system' ),
			'Clear'                                        => __( 'Clear', 'magepeople-ferry-booking-system' ),
			'Commission'                                   => __( 'Commission', 'magepeople-ferry-booking-system' ),
			'Commission amount'                            => __( 'Commission amount', 'magepeople-ferry-booking-system' ),
			'Commission earned'                            => __( 'Commission earned', 'magepeople-ferry-booking-system' ),
			'Commission is earned when a booking is paid, and taken back if it is cancelled.' => __( 'Commission is earned when a booking is paid, and taken back if it is cancelled.', 'magepeople-ferry-booking-system' ),
			'Commission rate'                              => __( 'Commission rate', 'magepeople-ferry-booking-system' ),
			'Commission reversed'                          => __( 'Commission reversed', 'magepeople-ferry-booking-system' ),
			'Counter'                                      => __( 'Counter', 'magepeople-ferry-booking-system' ),
			'Credit limit'                                 => __( 'Credit limit', 'magepeople-ferry-booking-system' ),
			'Crossing updated.'                            => __( 'Crossing updated.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Crossing updated. %s passengers are being told.' => __( 'Crossing updated. %s passengers are being told.', 'magepeople-ferry-booking-system' ),
			'Crossings'                                    => __( 'Crossings', 'magepeople-ferry-booking-system' ),
			'Currently taken from the company logo on the General tab.' => __( 'Currently taken from the company logo on the General tab.', 'magepeople-ferry-booking-system' ),
			'Day'                                          => __( 'Day', 'magepeople-ferry-booking-system' ),
			'Desk 1'                                       => __( 'Desk 1', 'magepeople-ferry-booking-system' ),
			'Discard changes'                              => __( 'Discard changes', 'magepeople-ferry-booking-system' ),
			'Each booking is only ever acted on once by this rule.' => __( 'Each booking is only ever acted on once by this rule.', 'magepeople-ferry-booking-system' ),
			'Edited'                                       => __( 'Edited', 'magepeople-ferry-booking-system' ),
			'Email preview'                                => __( 'Email preview', 'magepeople-ferry-booking-system' ),
			'Enter what the customer handed over and the change is worked out.' => __( 'Enter what the customer handed over and the change is worked out.', 'magepeople-ferry-booking-system' ),
			'Every crossing, and how full it is.'          => __( 'Every crossing, and how full it is.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Filled in from booking %s.'                   => __( 'Filled in from booking %s.', 'magepeople-ferry-booking-system' ),
			'Filled in with example details, because there are no bookings to preview against yet.' => __( 'Filled in with example details, because there are no bookings to preview against yet.', 'magepeople-ferry-booking-system' ),
			'Footer'                                       => __( 'Footer', 'magepeople-ferry-booking-system' ),
			'Full'                                         => __( 'Full', 'magepeople-ferry-booking-system' ),
			'Give a user the Ferry Agent role and they will appear here.' => __( 'Give a user the Ferry Agent role and they will appear here.', 'magepeople-ferry-booking-system' ),
			'Heading'                                      => __( 'Heading', 'magepeople-ferry-booking-system' ),
			'How commission is worked out'                 => __( 'How commission is worked out', 'magepeople-ferry-booking-system' ),
			'How far the account may go below zero. Leave at nothing to require payment up front.' => __( 'How far the account may go below zero. Leave at nothing to require payment up front.', 'magepeople-ferry-booking-system' ),
			'How long before departure'                    => __( 'How long before departure', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'How many %s'                                  => __( 'How many %s', 'magepeople-ferry-booking-system' ),
			'Just for you — how this rule appears in the list.' => __( 'Just for you — how this rule appears in the list.', 'magepeople-ferry-booking-system' ),
			'Kind'                                         => __( 'Kind', 'magepeople-ferry-booking-system' ),
			'Leave blank for no button.'                   => __( 'Leave blank for no button.', 'magepeople-ferry-booking-system' ),
			'Leave blank to send to yourself'              => __( 'Leave blank to send to yourself', 'magepeople-ferry-booking-system' ),
			'Left'                                         => __( 'Left', 'magepeople-ferry-booking-system' ),
			'Logo URL'                                     => __( 'Logo URL', 'magepeople-ferry-booking-system' ),
			'Look and feel'                                => __( 'Look and feel', 'magepeople-ferry-booking-system' ),
			'Look and feel saved.'                         => __( 'Look and feel saved.', 'magepeople-ferry-booking-system' ),
			'Measure'                                      => __( 'Measure', 'magepeople-ferry-booking-system' ),
			'Message'                                      => __( 'Message', 'magepeople-ferry-booking-system' ),
			'Message background'                           => __( 'Message background', 'magepeople-ferry-booking-system' ),
			'Month'                                        => __( 'Month', 'magepeople-ferry-booking-system' ),
			'Movement'                                     => __( 'Movement', 'magepeople-ferry-booking-system' ),
			'Movements cannot be edited or deleted. To correct a mistake, record an adjustment — the error and the correction both stay on the statement.' => __( 'Movements cannot be edited or deleted. To correct a mistake, record an adjustment — the error and the correction both stay on the statement.', 'magepeople-ferry-booking-system' ),
			'Moving the departure moves the arrival by the same amount.' => __( 'Moving the departure moves the arrival by the same amount.', 'magepeople-ferry-booking-system' ),
			'Name, company or email'                       => __( 'Name, company or email', 'magepeople-ferry-booking-system' ),
			'Next customer'                                => __( 'Next customer', 'magepeople-ferry-booking-system' ),
			'No agencies yet.'                             => __( 'No agencies yet.', 'magepeople-ferry-booking-system' ),
			'No capacity set on these crossings.'          => __( 'No capacity set on these crossings.', 'magepeople-ferry-booking-system' ),
			'No crossings on this day.'                    => __( 'No crossings on this day.', 'magepeople-ferry-booking-system' ),
			'No crossings.'                                => __( 'No crossings.', 'magepeople-ferry-booking-system' ),
			'No limit'                                     => __( 'No limit', 'magepeople-ferry-booking-system' ),
			'No rules yet.'                                => __( 'No rules yet.', 'magepeople-ferry-booking-system' ),
			'No seat limit'                                => __( 'No seat limit', 'magepeople-ferry-booking-system' ),
			'No templates are available.'                  => __( 'No templates are available.', 'magepeople-ferry-booking-system' ),
			'Nobody is booked on this crossing yet.'       => __( 'Nobody is booked on this crossing yet.', 'magepeople-ferry-booking-system' ),
			'Not on sale'                                  => __( 'Not on sale', 'magepeople-ferry-booking-system' ),
			'Note'                                         => __( 'Note', 'magepeople-ferry-booking-system' ),
			'Nothing has moved on this account yet.'       => __( 'Nothing has moved on this account yet.', 'magepeople-ferry-booking-system' ),
			'Nothing is scheduled in this period.'         => __( 'Nothing is scheduled in this period.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2. */
			'Notify %1$s bookings covering %2$s passengers' => __( 'Notify %1$s bookings covering %2$s passengers', 'magepeople-ferry-booking-system' ),
			'One set of colours and one logo for every message, so a rebrand does not leave one email looking like the old company. Leave a field blank to take it from your General settings.' => __( 'One set of colours and one logo for every message, so a rebrand does not leave one email looking like the old company. Leave a field blank to take it from your General settings.', 'magepeople-ferry-booking-system' ),
			'Page background'                              => __( 'Page background', 'magepeople-ferry-booking-system' ),
			'Paper'                                        => __( 'Paper', 'magepeople-ferry-booking-system' ),
			'Passengers booked'                            => __( 'Passengers booked', 'magepeople-ferry-booking-system' ),
			'Paused'                                       => __( 'Paused', 'magepeople-ferry-booking-system' ),
			'Pick the day and route, then the departure.'  => __( 'Pick the day and route, then the departure.', 'magepeople-ferry-booking-system' ),
			'Print the ticket'                             => __( 'Print the ticket', 'magepeople-ferry-booking-system' ),
			'Printed on the receipt, for reconciling a shift.' => __( 'Printed on the receipt, for reconciling a shift.', 'magepeople-ferry-booking-system' ),
			'Quiet text'                                   => __( 'Quiet text', 'magepeople-ferry-booking-system' ),
			'Record a movement'                            => __( 'Record a movement', 'magepeople-ferry-booking-system' ),
			'Record it'                                    => __( 'Record it', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Recorded. The account now holds %s.'          => __( 'Recorded. The account now holds %s.', 'magepeople-ferry-booking-system' ),
			'Remove it'                                    => __( 'Remove it', 'magepeople-ferry-booking-system' ),
			'Remove this rule?'                            => __( 'Remove this rule?', 'magepeople-ferry-booking-system' ),
			'Restore it'                                   => __( 'Restore it', 'magepeople-ferry-booking-system' ),
			'Restore the shipped wording'                  => __( 'Restore the shipped wording', 'magepeople-ferry-booking-system' ),
			'Restore the shipped wording?'                 => __( 'Restore the shipped wording?', 'magepeople-ferry-booking-system' ),
			'Rules that run on their own. Each one watches for something happening and does one thing about it.' => __( 'Rules that run on their own. Each one watches for something happening and does one thing about it.', 'magepeople-ferry-booking-system' ),
			'Save agency'                                  => __( 'Save agency', 'magepeople-ferry-booking-system' ),
			'Save automations'                             => __( 'Save automations', 'magepeople-ferry-booking-system' ),
			'Save look and feel'                           => __( 'Save look and feel', 'magepeople-ferry-booking-system' ),
			'Save template'                                => __( 'Save template', 'magepeople-ferry-booking-system' ),
			'Seats available'                              => __( 'Seats available', 'magepeople-ferry-booking-system' ),
			'Seats filled'                                 => __( 'Seats filled', 'magepeople-ferry-booking-system' ),
			'Sell a crossing at the quayside.'             => __( 'Sell a crossing at the quayside.', 'magepeople-ferry-booking-system' ),
			'Selling…'                                     => __( 'Selling…', 'magepeople-ferry-booking-system' ),
			'Send this message'                            => __( 'Send this message', 'magepeople-ferry-booking-system' ),
			'Send yourself a copy'                         => __( 'Send yourself a copy', 'magepeople-ferry-booking-system' ),
			'Shared by every message'                      => __( 'Shared by every message', 'magepeople-ferry-booking-system' ),
			'Shown to passengers in the message they receive.' => __( 'Shown to passengers in the message they receive.', 'magepeople-ferry-booking-system' ),
			'Signed with the same secret as your webhooks, and carrying no passenger details.' => __( 'Signed with the same secret as your webhooks, and carrying no passenger details.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Sold — %s'                                    => __( 'Sold — %s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Sold. Reference %s.'                          => __( 'Sold. Reference %s.', 'magepeople-ferry-booking-system' ),
			'Statement'                                    => __( 'Statement', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Still %s short'                               => __( 'Still %s short', 'magepeople-ferry-booking-system' ),
			'Subject'                                      => __( 'Subject', 'magepeople-ferry-booking-system' ),
			'Take payment'                                 => __( 'Take payment', 'magepeople-ferry-booking-system' ),
			'Taken'                                        => __( 'Taken', 'magepeople-ferry-booking-system' ),
			'Telephone'                                    => __( 'Telephone', 'magepeople-ferry-booking-system' ),
			'Tell the passengers already booked'           => __( 'Tell the passengers already booked', 'magepeople-ferry-booking-system' ),
			'Template restored to the wording that ships with the plugin.' => __( 'Template restored to the wording that ships with the plugin.', 'magepeople-ferry-booking-system' ),
			'Template saved.'                              => __( 'Template saved.', 'magepeople-ferry-booking-system' ),
			'Templates'                                    => __( 'Templates', 'magepeople-ferry-booking-system' ),
			'Terms'                                        => __( 'Terms', 'magepeople-ferry-booking-system' ),
			'Text'                                         => __( 'Text', 'magepeople-ferry-booking-system' ),
			'The rule stops running. Anything it has already done stays as it is.' => __( 'The rule stops running. Anything it has already done stays as it is.', 'magepeople-ferry-booking-system' ),
			'The ticket and the confirmation go to this address.' => __( 'The ticket and the confirmation go to this address.', 'magepeople-ferry-booking-system' ),
			'Then'                                         => __( 'Then', 'magepeople-ferry-booking-system' ),
			'This message is currently switched off under Messages, so nothing is sent whatever you write here.' => __( 'This message is currently switched off under Messages, so nothing is sent whatever you write here.', 'magepeople-ferry-booking-system' ),
			'This message is switched on under Messages. This screen decides what it says.' => __( 'This message is switched on under Messages. This screen decides what it says.', 'magepeople-ferry-booking-system' ),
			'This period has more crossings than one view can show. Narrow it by route or vessel, or use the week view.' => __( 'This period has more crossings than one view can show. Narrow it by route or vessel, or use the week view.', 'magepeople-ferry-booking-system' ),
			'This rule is running'                         => __( 'This rule is running', 'magepeople-ferry-booking-system' ),
			'This would:'                                  => __( 'This would:', 'magepeople-ferry-booking-system' ),
			'Till'                                         => __( 'Till', 'magepeople-ferry-booking-system' ),
			'Timed rules are checked every hour by WordPress’s scheduler. On a quiet site the scheduler only runs when somebody visits, so a reminder can be late.' => __( 'Timed rules are checked every hour by WordPress’s scheduler. On a quiet site the scheduler only runs when somebody visits, so a reminder can be late.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Timed rules are checked every hour. The next check is at %s.' => __( 'Timed rules are checked every hour. The next check is at %s.', 'magepeople-ferry-booking-system' ),
			/* translators: 1: value 1, 2: value 2, 3: value 3. */
			'Total %1$s · Tendered %2$s · Change %3$s'     => __( 'Total %1$s · Tendered %2$s · Change %3$s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Total %s'                                     => __( 'Total %s', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Totalled over this agency’s %s most recent bookings.' => __( 'Totalled over this agency’s %s most recent bookings.', 'magepeople-ferry-booking-system' ),
			'Trading'                                      => __( 'Trading', 'magepeople-ferry-booking-system' ),
			'Trading name'                                 => __( 'Trading name', 'magepeople-ferry-booking-system' ),
			'Travel agencies with their own logins, commission terms, a prepaid account and a credit limit — each seeing only their own bookings.' => __( 'Travel agencies with their own logins, commission terms, a prepaid account and a credit limit — each seeing only their own bookings.', 'magepeople-ferry-booking-system' ),
			'Type one of these anywhere in the subject, heading, body or button. Anything the booking cannot supply comes out blank.' => __( 'Type one of these anywhere in the subject, heading, body or button. Anything the booking cannot supply comes out blank.', 'magepeople-ferry-booking-system' ),
			'URL to call'                                  => __( 'URL to call', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'Up to %s passengers on one booking.'          => __( 'Up to %s passengers on one booking.', 'magepeople-ferry-booking-system' ),
			'Variables'                                    => __( 'Variables', 'magepeople-ferry-booking-system' ),
			'Variables work here: {{route}}, {{departure_time}}, {{booking_number}}.' => __( 'Variables work here: {{route}}, {{departure_time}}, {{booking_number}}.', 'magepeople-ferry-booking-system' ),
			'Vehicles booked'                              => __( 'Vehicles booked', 'magepeople-ferry-booking-system' ),
			'Week'                                         => __( 'Week', 'magepeople-ferry-booking-system' ),
			'What the plugin sends, how it looks, and who it comes from.' => __( 'What the plugin sends, how it looks, and who it comes from.', 'magepeople-ferry-booking-system' ),
			'When'                                         => __( 'When', 'magepeople-ferry-booking-system' ),
			'Which email'                                  => __( 'Which email', 'magepeople-ferry-booking-system' ),
			'Who is booked'                                => __( 'Who is booked', 'magepeople-ferry-booking-system' ),
			'Your edits to this template are discarded and it goes back to the wording the plugin ships with.' => __( 'Your edits to this template are discarded and it goes back to the wording the plugin ships with.', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'arrives %s'                                   => __( 'arrives %s', 'magepeople-ferry-booking-system' ),
			'day'                                          => __( 'day', 'magepeople-ferry-booking-system' ),
			/* translators: %s: a value substituted at runtime. */
			'from %s'                                      => __( 'from %s', 'magepeople-ferry-booking-system' ),
			'hours'                                        => __( 'hours', 'magepeople-ferry-booking-system' ),
			'week'                                         => __( 'week', 'magepeople-ferry-booking-system' ),
			'Nobody is booked on this crossing, so nobody is affected.' => __( 'Nobody is booked on this crossing, so nobody is affected.', 'magepeople-ferry-booking-system' ),
			'Change nothing — the values are the same as they are now.' => __( 'Change nothing — the values are the same as they are now.', 'magepeople-ferry-booking-system' ),
			'Footer text'                                  => __( 'Footer text', 'magepeople-ferry-booking-system' ),
			'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.' => __( 'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.', 'magepeople-ferry-booking-system' ),
			'A route'                                      => __( 'A route', 'magepeople-ferry-booking-system' ),
			'A vessel'                                     => __( 'A vessel', 'magepeople-ferry-booking-system' ),
			'A fare'                                       => __( 'A fare', 'magepeople-ferry-booking-system' ),
			'An administrator needs to finish setting up the ferry operation before this part of the dashboard opens.' => __( 'An administrator needs to finish setting up the ferry operation before this part of the dashboard opens.', 'magepeople-ferry-booking-system' ),
			'Change payment methods'                       => __( 'Change payment methods', 'magepeople-ferry-booking-system' ),
			'Checking…'                                    => __( 'Checking…', 'magepeople-ferry-booking-system' ),
			'Continue'                                     => __( 'Continue', 'magepeople-ferry-booking-system' ),
			'Currency code'                                => __( 'Currency code', 'magepeople-ferry-booking-system' ),
			'Currency symbol'                              => __( 'Currency symbol', 'magepeople-ferry-booking-system' ),
			'Finish setup'                                 => __( 'Finish setup', 'magepeople-ferry-booking-system' ),
			'Finishing…'                                   => __( 'Finishing…', 'magepeople-ferry-booking-system' ),
			'Get started'                                  => __( 'Get started', 'magepeople-ferry-booking-system' ),
			'Go to the dashboard'                          => __( 'Go to the dashboard', 'magepeople-ferry-booking-system' ),
			'How customers pay'                            => __( 'How customers pay', 'magepeople-ferry-booking-system' ),
			'How your business appears on tickets, confirmations and emails. Check what is filled in and correct anything that is wrong.' => __( 'How your business appears on tickets, confirmations and emails. Check what is filled in and correct anything that is wrong.', 'magepeople-ferry-booking-system' ),
			'Leave empty to use the usual symbol for the code above.' => __( 'Leave empty to use the usual symbol for the code above.', 'magepeople-ferry-booking-system' ),
			'Needs a route'                                => __( 'Needs a route', 'magepeople-ferry-booking-system' ),
			'Needs a vessel'                               => __( 'Needs a vessel', 'magepeople-ferry-booking-system' ),
			'Needs two ports'                              => __( 'Needs two ports', 'magepeople-ferry-booking-system' ),
			'No fare yet: tickets would be free'           => __( 'No fare yet: tickets would be free', 'magepeople-ferry-booking-system' ),
			'No future sailings yet'                       => __( 'No future sailings yet', 'magepeople-ferry-booking-system' ),
			'No payment method is switched on, so customers could not finish a booking.' => __( 'No payment method is switched on, so customers could not finish a booking.', 'magepeople-ferry-booking-system' ),
			'Open Fleet and schedule'                      => __( 'Open Fleet and schedule', 'magepeople-ferry-booking-system' ),
			'Open Settings'                                => __( 'Open Settings', 'magepeople-ferry-booking-system' ),
			'Open the booking page'                        => __( 'Open the booking page', 'magepeople-ferry-booking-system' ),
			'Opens when setup is finished'                 => __( 'Opens when setup is finished', 'magepeople-ferry-booking-system' ),
			'Part of a crossing already exists. Pick those ports and that vessel in the wizard below, or finish it on the Fleet and schedule tabs.' => __( 'Part of a crossing already exists. Pick those ports and that vessel in the wizard below, or finish it on the Fleet and schedule tabs.', 'magepeople-ferry-booking-system' ),
			'Required: the base fare the other types are worked out from' => __( 'Required: the base fare the other types are worked out from', 'magepeople-ferry-booking-system' ),
			'Save and continue'                            => __( 'Save and continue', 'magepeople-ferry-booking-system' ),
			/* translators: %s: passenger type name, such as Adult. */
			'Set a fare for %s. It has no price of its own, so without one every ticket would be free.' => __( 'Set a fare for %s. It has no price of its own, so without one every ticket would be free.', 'magepeople-ferry-booking-system' ),
			'Setup is finished'                            => __( 'Setup is finished', 'magepeople-ferry-booking-system' ),
			'Setup is finished. Your crossing is on sale.' => __( 'Setup is finished. Your crossing is on sale.', 'magepeople-ferry-booking-system' ),
			'Setup is not finished yet'                    => __( 'Setup is not finished yet', 'magepeople-ferry-booking-system' ),
			'The last check before customers can book. Nothing here is final: payments and pages can be changed at any time.' => __( 'The last check before customers can book. Nothing here is final: payments and pages can be changed at any time.', 'magepeople-ferry-booking-system' ),
			'There is no booking page. Create one in Settings → Pages.' => __( 'There is no booking page. Create one in Settings → Pages.', 'magepeople-ferry-booking-system' ),
			'Three steps, in order, and your first crossing is on sale. The rest of the dashboard opens when they are done.' => __( 'Three steps, in order, and your first crossing is on sale. The rest of the dashboard opens when they are done.', 'magepeople-ferry-booking-system' ),
			'Three-letter ISO code, such as EUR or GBP.'   => __( 'Three-letter ISO code, such as EUR or GBP.', 'magepeople-ferry-booking-system' ),
			'Two ports'                                    => __( 'Two ports', 'magepeople-ferry-booking-system' ),
			'Upcoming sailings'                            => __( 'Upcoming sailings', 'magepeople-ferry-booking-system' ),
			'What a crossing needs'                        => __( 'What a crossing needs', 'magepeople-ferry-booking-system' ),
			'Where customers are told to write if something goes wrong.' => __( 'Where customers are told to write if something goes wrong.', 'magepeople-ferry-booking-system' ),
			'Where customers book'                         => __( 'Where customers book', 'magepeople-ferry-booking-system' ),
			/* translators: %s: three-letter currency code. */
			'WooCommerce is active, so prices use its currency (%s). Change it in WooCommerce → Settings.' => __( 'WooCommerce is active, so prices use its currency (%s). Change it in WooCommerce → Settings.', 'magepeople-ferry-booking-system' ),
			'Your first crossing is on sale. Everything can be changed later on its own screen.' => __( 'Your first crossing is on sale. Everything can be changed later on its own screen.', 'magepeople-ferry-booking-system' ),
			'Your first crossing is ready to sell. More can be added later from Fleet and schedule.' => __( 'Your first crossing is ready to sell. More can be added later from Fleet and schedule.', 'magepeople-ferry-booking-system' ),
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
