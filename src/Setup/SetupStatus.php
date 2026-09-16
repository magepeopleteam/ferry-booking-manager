<?php
/**
 * First-run setup progress.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Setup;

use FBM\Demo\DemoContent;
use FBM\Frontend\Pages;
use FBM\Models\Port;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\Vessel;
use FBM\Pricing\PricingService;
use FBM\Repositories\PassengerTypeRepository;
use FBM\Settings\Settings;
use FBM\Support\Money;
use FBM\Support\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Works out how far an operator has got with setting the plugin up.
 *
 * An install that starts from nothing has three things to do before it can
 * sell a ticket: say who it is, build one crossing that can actually be sold,
 * and switch the booking page on. Until that is done the dashboard shows only
 * those steps, because every other screen is a list of things that do not
 * exist yet.
 *
 * Progress is read from the data rather than remembered, so a crossing built on
 * the Fleet and schedule tabs counts exactly as one built by the wizard, and a
 * step undone by deleting the route is undone here too. Only the two decisions
 * the data cannot show — "these business details are right" and "go live" —
 * are stored.
 */
final class SetupStatus {

	/**
	 * Option recording that the business details were confirmed.
	 */
	public const OPTION_BUSINESS = 'setup_business_done';

	/**
	 * Option recording that setup was finished.
	 */
	public const OPTION_COMPLETED = 'setup_completed';

	/**
	 * Business settings the first step asks for.
	 */
	private const BUSINESS_KEYS = array( 'company_name', 'support_email', 'support_phone', 'currency', 'currency_symbol' );

	/**
	 * Settings WooCommerce overrides while it is active.
	 */
	private const CURRENCY_KEYS = array( 'currency', 'currency_symbol' );

	/**
	 * Pricing service.
	 *
	 * @var PricingService
	 */
	private PricingService $pricing;

	/**
	 * Passenger type repository.
	 *
	 * @var PassengerTypeRepository
	 */
	private PassengerTypeRepository $passenger_types;

	/**
	 * Managed pages.
	 *
	 * @var Pages
	 */
	private Pages $pages;

	/**
	 * Demo content service.
	 *
	 * @var DemoContent
	 */
	private DemoContent $demo;

	/**
	 * Constructor.
	 *
	 * @param PricingService          $pricing         Pricing service.
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param Pages                   $pages           Managed pages.
	 * @param DemoContent             $demo            Demo content service.
	 */
	public function __construct( PricingService $pricing, PassengerTypeRepository $passenger_types, Pages $pages, DemoContent $demo ) {
		$this->pricing         = $pricing;
		$this->passenger_types = $passenger_types;
		$this->pages           = $pages;
		$this->demo            = $demo;
	}

	/**
	 * Attaches the upgrade hook.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_initialise' ) );
	}

	/**
	 * Decides, once, whether this install still has setup ahead of it.
	 *
	 * An install that already has a catalogue was set up before this existed,
	 * and locking its operators out of their own bookings to walk them through
	 * a wizard for work they did long ago would be the opposite of help. Only a
	 * genuinely empty install starts locked.
	 *
	 * @return void
	 */
	public function maybe_initialise(): void {
		if ( null !== Options::get( self::OPTION_COMPLETED ) ) {
			return;
		}

		$done = $this->demo->is_empty() ? 0 : 1;

		Options::set( self::OPTION_COMPLETED, $done, true );
		Options::set( self::OPTION_BUSINESS, $done, true );
	}

	/**
	 * Marks setup finished without walking through it.
	 *
	 * Used by the demo import: an operator who installed a working operation
	 * to look around in has nothing left to set up.
	 *
	 * @return void
	 */
	public static function mark_complete(): void {
		Options::set( self::OPTION_BUSINESS, 1, true );
		Options::set( self::OPTION_COMPLETED, 1, true );
	}

	/**
	 * Returns the progress of every step.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$this->maybe_initialise();

		$crossing = array(
			'ports'    => $this->count( Port::POST_TYPE, 2, $this->active_clause() ),
			'vessels'  => $this->count( Vessel::POST_TYPE, 1, $this->active_clause() ),
			'routes'   => $this->count( Route::POST_TYPE, 1, $this->active_clause() ),
			'sailings' => $this->count(
				Sailing::POST_TYPE,
				1,
				array(
					array(
						'key'     => '_fbm_departure_ts',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_fbm_status',
						'value'   => array( Sailing::STATUS_SCHEDULED, Sailing::STATUS_DELAYED ),
						'compare' => 'IN',
					),
				)
			),
			'fare'     => $this->has_priced_route(),
		);

		$crossing['ready'] = $crossing['ports'] >= 2
			&& $crossing['vessels'] >= 1
			&& $crossing['routes'] >= 1
			&& $crossing['sailings'] >= 1
			&& $crossing['fare'];

		$business  = Options::get_bool( self::OPTION_BUSINESS );
		$completed = Options::get_bool( self::OPTION_COMPLETED );
		$settings  = Settings::all();

		$status = array(
			'completed'   => $completed,
			'locked'      => ! $completed,
			'business'    => $business,
			'crossing'    => $crossing,
			'done'        => (int) $business + (int) ( $business && $crossing['ready'] ) + (int) $completed,
			'booking_url' => $this->pages->url( 'booking' ),
			'woocommerce' => function_exists( 'get_woocommerce_currency' ),
			'currency'    => Money::currency()['code'],
			'details'     => array(
				'company_name'    => (string) ( $settings['company_name'] ?? '' ),
				'support_email'   => (string) ( $settings['support_email'] ?? '' ),
				'support_phone'   => (string) ( $settings['support_phone'] ?? '' ),
				'currency'        => (string) ( $settings['currency'] ?? '' ),
				'currency_symbol' => (string) ( $settings['currency_symbol'] ?? '' ),
			),
		);

		/**
		 * Filters the first-run setup progress.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $status Setup progress.
		 */
		return (array) apply_filters( 'fbm_setup_status', $status );
	}

	/**
	 * Saves the business details and records the first step as done.
	 *
	 * Written through the same settings store and sanitisers as the Settings
	 * screen, so a value accepted here is one the Settings screen accepts too.
	 *
	 * @param array<string, mixed> $input Submitted details.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_business( array $input ) {
		$details = array_intersect_key( $input, array_flip( self::BUSINESS_KEYS ) );

		// WooCommerce decides the currency while it is active. Accepting one
		// here would store a value that is silently ignored everywhere.
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$details = array_diff_key( $details, array_flip( self::CURRENCY_KEYS ) );
		}

		$fields = array();

		if ( '' === trim( (string) ( $details['company_name'] ?? '' ) ) ) {
			$fields['company_name'] = __( 'Enter the name customers know you by.', 'magepeople-ferry-booking-system' );
		}

		if ( ! is_email( (string) ( $details['support_email'] ?? '' ) ) ) {
			$fields['support_email'] = __( 'Enter a valid email address.', 'magepeople-ferry-booking-system' );
		}

		if ( isset( $details['currency'] ) && ! preg_match( '/^[A-Za-z]{3}$/', trim( (string) $details['currency'] ) ) ) {
			$fields['currency'] = __( 'Use a three-letter currency code, such as EUR or GBP.', 'magepeople-ferry-booking-system' );
		}

		if ( array() !== $fields ) {
			return new WP_Error(
				'fbm_setup_business',
				__( 'Some details need another look.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 422,
					'fields' => $fields,
				)
			);
		}

		if ( isset( $details['currency'] ) ) {
			$details['currency'] = strtoupper( trim( (string) $details['currency'] ) );
		}

		Settings::save( $details );
		Options::set( self::OPTION_BUSINESS, 1, true );

		return $this->status();
	}

	/**
	 * Finishes setup, provided there is something to sell.
	 *
	 * Checked here and not only in the dashboard, so the lock cannot be lifted
	 * by a request that skips the screens.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function complete() {
		$status = $this->status();

		if ( empty( $status['business'] ) ) {
			return new WP_Error(
				'fbm_setup_incomplete',
				__( 'Confirm your business details first.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 422 )
			);
		}

		if ( empty( $status['crossing']['ready'] ) ) {
			return new WP_Error(
				'fbm_setup_incomplete',
				__( 'Finish your first crossing first: it needs two ports, a vessel, a route, a future sailing and a fare.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 422 )
			);
		}

		Options::set( self::OPTION_COMPLETED, 1, true );

		return $this->status();
	}

	/**
	 * Meta clause matching active records.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function active_clause(): array {
		return array(
			array(
				'key'   => '_fbm_status',
				'value' => 'active',
			),
		);
	}

	/**
	 * Counts records of a type, stopping once there are enough.
	 *
	 * @param string                           $post_type  Post type.
	 * @param int                              $enough     How many are needed.
	 * @param array<int, array<string, mixed>> $meta_query Meta conditions.
	 * @return int
	 */
	private function count( string $post_type, int $enough, array $meta_query ): int {
		$found = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => $enough,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Capped at two rows, indexed scalar keys.
			)
		);

		return count( $found );
	}

	/**
	 * Whether any active route charges the base passenger more than nothing.
	 *
	 * The shipped Adult type is priced at zero, so a route left without a fare
	 * would sell every seat for free. That is a mistake worth stopping at the
	 * door rather than finding on the first day's takings.
	 *
	 * @return bool
	 */
	private function has_priced_route(): bool {
		$routes = get_posts(
			array(
				'post_type'      => Route::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $this->active_clause(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Capped, indexed scalar key.
			)
		);

		$base = $this->passenger_types->base_type();

		foreach ( $routes as $route_id ) {
			$origin      = (int) get_post_meta( (int) $route_id, '_fbm_origin_port', true );
			$destination = (int) get_post_meta( (int) $route_id, '_fbm_destination_port', true );

			if ( $origin <= 0 || $destination <= 0 ) {
				continue;
			}

			$fares = (array) $this->pricing->fares( $origin, $destination )['passengers'];

			if ( null !== $base ) {
				if ( (int) ( $fares[ (string) $base->id ] ?? 0 ) > 0 ) {
					return true;
				}

				continue;
			}

			foreach ( $fares as $fare ) {
				if ( (int) $fare > 0 ) {
					return true;
				}
			}
		}

		return false;
	}
}
