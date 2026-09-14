<?php
/**
 * Settings tab declarations.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Settings;

use FBM\Pricing\PricingSettings;
use FBM\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Declares every settings tab, section and field the dashboard shows.
 *
 * This is the single description the whole settings system is built from: the
 * store takes its defaults and its sanitisers from here, the REST endpoint hands
 * the description to the dashboard, and the dashboard renders whatever it is
 * given. A tab therefore needs no JavaScript of its own, which is what lets the
 * Pro plugin add PDF, QR and webhook settings to a dashboard bundle that was
 * compiled before Pro existed.
 *
 * Tabs whose features belong to Pro are declared here in a locked state so that
 * Pro has something to replace, but a locked tab is never described to the
 * dashboard: without Pro installed, only what the Free plugin can actually do is
 * offered. A settings screen that lists what you cannot configure is a catalogue,
 * not a settings screen.
 *
 * Every tab names a `group`, and the dashboard draws one navigation column of
 * grouped links instead of a single strip. Fifteen tabs never fit across a
 * screen, and a strip that scrolls sideways hides settings behind a gesture
 * nobody makes.
 */
final class SettingsPanels {

	/**
	 * Marks a section as rendering a purpose-built control instead of fields.
	 */
	public const CUSTOM_GATEWAYS  = 'gateways';
	public const CUSTOM_PASSENGER = 'passenger-fields';
	public const CUSTOM_VEHICLE   = 'vehicle-fields';
	public const CUSTOM_PAGES     = 'pages';
	public const CUSTOM_EMAILS    = 'email-test';
	public const CUSTOM_ROLES     = 'roles';
	public const CUSTOM_WEBHOOKS  = 'webhooks';
	public const CUSTOM_TRANSFER  = 'transfer';

	/**
	 * The Free settings option.
	 */
	public const STORE_SETTINGS = 'settings';

	/**
	 * The pricing rules option, which predates this screen and owns tax.
	 */
	public const STORE_PRICING = 'pricing';

	/**
	 * Returns the navigation groups, in display order, keyed by group slug.
	 *
	 * A tab naming an unknown group is not dropped — it is collected under the
	 * last heading, so a third-party tab added through `fbm_settings_panels`
	 * always has somewhere to appear.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			'operation'  => __( 'Operation', 'ferry-booking-manager' ),
			'travellers' => __( 'Travellers', 'ferry-booking-manager' ),
			'money'      => __( 'Money', 'ferry-booking-manager' ),
			'tickets'    => __( 'Tickets and messages', 'ferry-booking-manager' ),
			'system'     => __( 'System', 'ferry-booking-manager' ),
			'more'       => __( 'More', 'ferry-booking-manager' ),
		);
	}

	/**
	 * Returns every tab, in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$tabs = array(
			self::general(),
			self::booking(),
			self::passenger(),
			self::vehicles(),
			self::availability(),
			self::checkout(),
			self::woocommerce(),
			self::payments(),
			self::emails(),
			self::pdf(),
			self::qr(),
			self::taxes(),
			self::integrations(),
			self::roles(),
			self::advanced(),
		);

		/**
		 * Filters the settings tabs.
		 *
		 * Pro replaces its locked placeholder tabs with working ones through
		 * this filter, and may append tabs of its own. Each tab is an array of
		 * `id`, `label`, `description` and `sections`; each section carries
		 * either a `fields` list of SettingField objects or a `custom` slug.
		 *
		 * @since 1.1.0
		 *
		 * @param array<int, array<string, mixed>> $tabs Declared tabs.
		 */
		$tabs = (array) apply_filters( 'fbm_settings_panels', $tabs );

		return array_values(
			array_filter(
				$tabs,
				static function ( $tab ): bool {
					return is_array( $tab ) && isset( $tab['id'] );
				}
			)
		);
	}

	/**
	 * Returns every field belonging to one store, keyed by storage key.
	 *
	 * Tax, discounts and fees live in the pricing option and were configurable
	 * on the Pricing screen long before this one existed. The Taxes tab edits
	 * that store rather than keeping a second copy of the same values, because
	 * two stores holding one setting is how a settings screen starts disagreeing
	 * with what the plugin charges.
	 *
	 * @param string $store Store identifier.
	 * @return array<string, SettingField>
	 */
	public static function fields( string $store = self::STORE_SETTINGS ): array {
		$fields = array();

		foreach ( self::all() as $tab ) {
			foreach ( (array) ( $tab['sections'] ?? array() ) as $section ) {
				if ( (string) ( $section['store'] ?? self::STORE_SETTINGS ) !== $store ) {
					continue;
				}

				foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
					if ( $field instanceof SettingField ) {
						$fields[ $field->key ] = $field;
					}
				}
			}
		}

		return $fields;
	}

	/**
	 * Describes the tabs for the dashboard, with the fields flattened to arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe(): array {
		$described = array();
		$groups    = self::groups();

		foreach ( self::all() as $tab ) {
			/*
			 * A locked tab is a placeholder Pro replaces. If Pro has not
			 * replaced it, the feature is not installed, and the tab is left
			 * out rather than shown as an advertisement among working settings.
			 */
			if ( ! empty( $tab['locked'] ) ) {
				continue;
			}

			$sections = array();

			foreach ( (array) ( $tab['sections'] ?? array() ) as $section ) {
				/*
				 * A hidden section still declares its fields — that is where
				 * their defaults, sanitisers and REST schema come from — but it
				 * is not drawn. It is how a setting edited on its own screen
				 * keeps one definition without appearing in two places at once.
				 */
				if ( ! empty( $section['hidden'] ) ) {
					continue;
				}

				$fields = array();

				foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
					if ( $field instanceof SettingField ) {
						$fields[] = $field->describe();
					}
				}

				$sections[] = array(
					'title'       => (string) ( $section['title'] ?? '' ),
					'description' => (string) ( $section['description'] ?? '' ),
					'custom'      => (string) ( $section['custom'] ?? '' ),
					'store'       => (string) ( $section['store'] ?? self::STORE_SETTINGS ),
					// Where a setting actually lives, when this tab is only a
					// signpost to the screen that owns it.
					'elsewhere'   => isset( $section['elsewhere'] ) && is_array( $section['elsewhere'] )
						? array(
							'path'  => (string) ( $section['elsewhere']['path'] ?? '' ),
							'label' => (string) ( $section['elsewhere']['label'] ?? '' ),
						)
						: null,
					'fields'      => $fields,
				);
			}

			$group = (string) ( $tab['group'] ?? '' );
			$group = isset( $groups[ $group ] ) ? $group : 'more';

			$described[] = array(
				'id'          => (string) $tab['id'],
				'label'       => (string) ( $tab['label'] ?? $tab['id'] ),
				'description' => (string) ( $tab['description'] ?? '' ),
				'group'       => $group,
				'groupLabel'  => (string) $groups[ $group ],
				'sections'    => $sections,
			);
		}

		return $described;
	}

	/**
	 * General tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function general(): array {
		return array(
			'id'          => 'general',
			'label'       => __( 'General', 'ferry-booking-manager' ),
			'group'       => 'operation',
			'description' => __( 'Who you are, how customers reach you, and how prices are shown.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'  => __( 'Operator', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'company_name' )
							->label( __( 'Company name', 'ferry-booking-manager' ) )
							->help( __( 'Shown on confirmations, tickets and emails.', 'ferry-booking-manager' ) )
							->default_to( (string) get_bloginfo( 'name' ) ),
						SettingField::make( 'company_logo', SettingField::TYPE_URL )
							->label( __( 'Logo URL', 'ferry-booking-manager' ) )
							->help( __( 'Used on printed documents. Leave empty to use the company name as text.', 'ferry-booking-manager' ) ),
						SettingField::make( 'support_email', SettingField::TYPE_EMAIL )
							->label( __( 'Support email', 'ferry-booking-manager' ) )
							->help( __( 'Where customers are told to write if something goes wrong.', 'ferry-booking-manager' ) )
							->default_to( (string) get_option( 'admin_email', '' ) ),
						SettingField::make( 'support_phone', SettingField::TYPE_TEL )
							->label( __( 'Support phone', 'ferry-booking-manager' ) ),
					),
				),
				array(
					'title'       => __( 'Currency and policies', 'ferry-booking-manager' ),
					'description' => self::currency_notice(),
					'fields'      => array(
						SettingField::make( 'currency', SettingField::TYPE_CURRENCY )
							->label( __( 'Currency code', 'ferry-booking-manager' ) )
							->placeholder( 'EUR' )
							->help( __( 'Three-letter ISO code, such as EUR or GBP.', 'ferry-booking-manager' ) ),
						SettingField::make( 'currency_symbol', SettingField::TYPE_TEXT )
							->label( __( 'Currency symbol', 'ferry-booking-manager' ) )
							->placeholder( '€' )
							->help( __( 'Leave empty to use the usual symbol for the code above, or the code itself where there is no established symbol.', 'ferry-booking-manager' ) ),
						SettingField::make( 'currency_position', SettingField::TYPE_SELECT )
							->label( __( 'Symbol position', 'ferry-booking-manager' ) )
							->options(
								array(
									'left'        => __( 'Before the amount (€84.00)', 'ferry-booking-manager' ),
									'right'       => __( 'After the amount (84.00€)', 'ferry-booking-manager' ),
									'left_space'  => __( 'Before, with a space (€ 84.00)', 'ferry-booking-manager' ),
									'right_space' => __( 'After, with a space (84.00 €)', 'ferry-booking-manager' ),
								)
							)
							->default_to( 'left' ),
						SettingField::make( 'currency_decimals', SettingField::TYPE_NUMBER )
							->label( __( 'Decimal places', 'ferry-booking-manager' ) )
							->range( 0, 4 )
							->default_to( 2 )
							->help( __( 'Zero for a currency with no minor unit, such as the yen.', 'ferry-booking-manager' ) ),

						/*
						 * Both separators are chosen from a list rather than
						 * typed. A space is a perfectly ordinary thousands
						 * separator and a text field cannot carry one — it is
						 * trimmed away on save — and a free-text pair also lets
						 * an operator set both to a comma, which makes every
						 * price on the site ambiguous.
						 */
						SettingField::make( 'currency_decimal_separator', SettingField::TYPE_SELECT )
							->label( __( 'Decimal separator', 'ferry-booking-manager' ) )
							->options(
								array(
									'.' => __( 'Point (84.00)', 'ferry-booking-manager' ),
									',' => __( 'Comma (84,00)', 'ferry-booking-manager' ),
								)
							)
							->default_to( '.' ),
						SettingField::make( 'currency_thousand_separator', SettingField::TYPE_SELECT )
							->label( __( 'Thousands separator', 'ferry-booking-manager' ) )
							->options(
								array(
									','  => __( 'Comma (1,284.00)', 'ferry-booking-manager' ),
									'.'  => __( 'Point (1.284,00)', 'ferry-booking-manager' ),
									' '  => __( 'Space (1 284.00)', 'ferry-booking-manager' ),
									'\'' => __( 'Apostrophe (1\'284.00)', 'ferry-booking-manager' ),
									''   => __( 'None (1284.00)', 'ferry-booking-manager' ),
								)
							)
							->default_to( ',' ),
						SettingField::make( 'frontend_primary_color', SettingField::TYPE_COLOR )
							->label( __( 'Accent colour', 'ferry-booking-manager' ) )
							->help( __( 'Used for buttons and highlights in the booking form.', 'ferry-booking-manager' ) )
							// Must match --fbmb-accent in the booking stylesheet. If the
							// two drift, the swatch on this screen stops describing the
							// colour the customer actually sees.
							->default_to( '#0b62c4' ),
						SettingField::make( 'terms_url', SettingField::TYPE_URL )
							->label( __( 'Terms and conditions URL', 'ferry-booking-manager' ) ),
						SettingField::make( 'cancellation_policy_url', SettingField::TYPE_URL )
							->label( __( 'Cancellation policy URL', 'ferry-booking-manager' ) ),
					),
				),
				array(
					'title'       => __( 'Booking pages', 'ferry-booking-manager' ),
					'description' => __( 'The plugin keeps these pages for you. Delete one and it is recreated on the next update.', 'ferry-booking-manager' ),
					'custom'      => self::CUSTOM_PAGES,
				),
			),
		);
	}

	/**
	 * Explains where the currency actually comes from on this site.
	 *
	 * WooCommerce wins wherever it is active — it is the thing taking the
	 * payment, and a booking priced in one currency and charged in another is
	 * not a display problem. Saying so on the screen is the difference between
	 * an operator understanding why their setting has no effect and filing a
	 * bug about it.
	 *
	 * @return string
	 */
	private static function currency_notice(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			return sprintf(
				/* translators: %s: the ISO currency code WooCommerce is configured with. */
				__( 'WooCommerce is active, so its currency settings are used and these are ignored. It is currently set to %s.', 'ferry-booking-manager' ),
				(string) get_woocommerce_currency()
			);
		}

		return __( 'How prices are written everywhere the plugin shows one. If WooCommerce is activated later, its own currency settings take over from these.', 'ferry-booking-manager' );
	}

	/**
	 * Booking tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function booking(): array {
		return array(
			'id'          => 'booking',
			'label'       => __( 'Booking', 'ferry-booking-manager' ),
			'group'       => 'operation',
			'description' => __( 'How far ahead people may book, how long a seat is held, and what a reference looks like.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'  => __( 'Booking window', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'hold_minutes', SettingField::TYPE_NUMBER )
							->label( __( 'Hold a seat for', 'ferry-booking-manager' ) )
							->unit( __( 'minutes', 'ferry-booking-manager' ) )
							->help( __( 'How long a seat stays reserved while the customer is paying.', 'ferry-booking-manager' ) )
							->range( 1, 240 )
							->default_to( 15 ),
						SettingField::make( 'min_lead_minutes', SettingField::TYPE_NUMBER )
							->label( __( 'Close sales before departure', 'ferry-booking-manager' ) )
							->unit( __( 'minutes', 'ferry-booking-manager' ) )
							->help( __( 'Zero keeps a sailing on sale until it leaves.', 'ferry-booking-manager' ) )
							->range( 0, 525600 )
							->default_to( 0 ),
						SettingField::make( 'max_lead_days', SettingField::TYPE_NUMBER )
							->label( __( 'Sell no further ahead than', 'ferry-booking-manager' ) )
							->unit( __( 'days', 'ferry-booking-manager' ) )
							->help( __( 'Zero means any published sailing may be booked.', 'ferry-booking-manager' ) )
							->range( 0, 7300 )
							->default_to( 0 ),
					),
				),
				array(
					'title'  => __( 'Limits per booking', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'max_passengers_per_booking', SettingField::TYPE_NUMBER )
							->label( __( 'Most passengers in one booking', 'ferry-booking-manager' ) )
							->help( __( 'Larger parties are asked to contact you instead. Zero removes the limit.', 'ferry-booking-manager' ) )
							->range( 0, 500 )
							->default_to( 9 ),
						SettingField::make( 'max_vehicles_per_booking', SettingField::TYPE_NUMBER )
							->label( __( 'Most vehicles in one booking', 'ferry-booking-manager' ) )
							->help( __( 'Zero removes the limit.', 'ferry-booking-manager' ) )
							->range( 0, 100 )
							->default_to( 4 ),
					),
				),
				array(
					'title'  => __( 'References and accounts', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'booking_reference_prefix', SettingField::TYPE_SLUG )
							->label( __( 'Booking reference prefix', 'ferry-booking-manager' ) )
							->help( __( 'References read like FBM-2071. Changing this does not renumber existing bookings.', 'ferry-booking-manager' ) )
							->default_to( 'FBM' ),
						SettingField::make( 'allow_guest_checkout', SettingField::TYPE_SWITCH )
							->label( __( 'Allow booking without an account', 'ferry-booking-manager' ) )
							->help( __( 'Turn this off to require customers to log in first.', 'ferry-booking-manager' ) )
							->default_to( true ),
						SettingField::make( 'require_phone', SettingField::TYPE_SWITCH )
							->label( __( 'Require a phone number', 'ferry-booking-manager' ) )
							->help( __( 'Useful if you need to reach passengers about a delay.', 'ferry-booking-manager' ) )
							->default_to( false ),
					),
				),
			),
		);
	}

	/**
	 * Passenger tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function passenger(): array {
		return array(
			'id'          => 'passenger',
			'label'       => __( 'Passenger', 'ferry-booking-manager' ),
			'group'       => 'travellers',
			'description' => __( 'What you ask about each traveller. Ask for less and more people finish booking.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Passenger fields', 'ferry-booking-manager' ),
					'description' => __( 'First and last name are always collected — a passenger manifest is a named list. Everything else is yours to decide, and each passenger type can demand more on top.', 'ferry-booking-manager' ),
					'elsewhere'   => array(
						'path'  => '/passengers/fields',
						'label' => __( 'Edit the passenger form', 'ferry-booking-manager' ),
					),
				),
			),
		);
	}

	/**
	 * Vehicles tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function vehicles(): array {
		return array(
			'id'          => 'vehicles',
			'label'       => __( 'Vehicles', 'ferry-booking-manager' ),
			'group'       => 'travellers',
			'description' => __( 'Whether you carry vehicles at all, and what you need to know about each one.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'  => __( 'Vehicle rules', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'vehicles_enabled', SettingField::TYPE_SWITCH )
							->label( __( 'Carry vehicles', 'ferry-booking-manager' ) )
							->help( __( 'Turn this off for a foot-passenger service. The booking form then never mentions vehicles, and a request carrying one is refused.', 'ferry-booking-manager' ) )
							->default_to( true ),
					),
				),
				array(
					'title'       => __( 'Vehicle fields', 'ferry-booking-manager' ),
					'description' => __( 'Choose which details the booking form collects for each vehicle. Each vehicle type can demand more on top — a camper can ask for height where a bicycle does not.', 'ferry-booking-manager' ),
					'elsewhere'   => array(
						'path'  => '/vehicles/fields',
						'label' => __( 'Edit the vehicle form', 'ferry-booking-manager' ),
					),
				),
			),
		);
	}

	/**
	 * Availability tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function availability(): array {
		return array(
			'id'          => 'availability',
			'label'       => __( 'Availability', 'ferry-booking-manager' ),
			'group'       => 'operation',
			'description' => __( 'How much of a vessel you sell, and what customers are told about what is left.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Capacity', 'ferry-booking-manager' ),
					'description' => __( 'Held-back places never appear as available, on any channel. Use them for crew, staff travel or a safety margin.', 'ferry-booking-manager' ),
					'fields'      => array(
						SettingField::make( 'seats_held_back', SettingField::TYPE_NUMBER )
							->label( __( 'Passenger places held back', 'ferry-booking-manager' ) )
							->unit( __( 'places', 'ferry-booking-manager' ) )
							->help( __( 'Subtracted from every sailing before anything is offered for sale.', 'ferry-booking-manager' ) )
							->range( 0, 1000 )
							->default_to( 0 ),
						SettingField::make( 'vehicle_spaces_held_back', SettingField::TYPE_NUMBER )
							->label( __( 'Vehicle spaces held back', 'ferry-booking-manager' ) )
							->unit( __( 'spaces', 'ferry-booking-manager' ) )
							->range( 0, 1000 )
							->default_to( 0 ),
					),
				),
				array(
					'title'  => __( 'What customers see', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'show_remaining_seats', SettingField::TYPE_SWITCH )
							->label( __( 'Show how many places are left', 'ferry-booking-manager' ) )
							->help( __( 'Shown only once a sailing is nearly full, so it reads as useful rather than as pressure.', 'ferry-booking-manager' ) )
							->default_to( true ),
						SettingField::make( 'capacity_warning_percent', SettingField::TYPE_NUMBER )
							->label( __( 'Treat a sailing as filling up at', 'ferry-booking-manager' ) )
							->unit( '%' )
							->help( __( 'Used for the load bars on the dashboard and the remaining-places notice.', 'ferry-booking-manager' ) )
							->range( 1, 100 )
							->default_to( 80 ),
						SettingField::make( 'low_availability_places', SettingField::TYPE_NUMBER )
							->label( __( 'Or when this many places are left', 'ferry-booking-manager' ) )
							->unit( __( 'places', 'ferry-booking-manager' ) )
							->help( __( 'Whichever happens first. A large vessel can be far from the percentage and still be down to its last few places.', 'ferry-booking-manager' ) )
							->range( 0, 500 )
							->default_to( 10 ),
					),
				),
			),
		);
	}

	/**
	 * Checkout tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function checkout(): array {
		return array(
			'id'          => 'checkout',
			'label'       => __( 'Checkout', 'ferry-booking-manager' ),
			'group'       => 'operation',
			'description' => __( 'Which checkout takes the money, and what the customer must agree to.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Checkout engine', 'ferry-booking-manager' ),
					'description' => __( 'Which engine takes the money, the default method and the payment deadline are all set beside the payment methods themselves.', 'ferry-booking-manager' ),
					'elsewhere'   => array(
						'path'  => '/payments',
						'label' => __( 'Open Payments', 'ferry-booking-manager' ),
					),
				),
				array(
					// Declared here so the keys keep one definition; edited on
					// the Payments screen, which is where an operator looks for
					// them, so the section itself is not drawn.
					'hidden' => true,
					'fields' => array(
						SettingField::make( 'checkout_engine', SettingField::TYPE_SELECT )
							->label( __( 'Take payment through', 'ferry-booking-manager' ) )
							->options(
								array(
									Settings::CHECKOUT_NATIVE      => __( 'Ferry checkout (cash, transfer, at the port)', 'ferry-booking-manager' ),
									Settings::CHECKOUT_WOOCOMMERCE => __( 'WooCommerce checkout and payment gateways', 'ferry-booking-manager' ),
								)
							)
							->help( __( 'WooCommerce brings its own gateways, coupons and tax handling. The built-in checkout takes offline payments without any of that.', 'ferry-booking-manager' ) )
							->default_to( Settings::CHECKOUT_NATIVE )
							->wide(),
						SettingField::make( 'default_payment_method', SettingField::TYPE_KEY )
							->label( __( 'Default payment method', 'ferry-booking-manager' ) )
							->help( __( 'Pre-selected on the payment step. Must be one of the methods enabled on the Payments tab.', 'ferry-booking-manager' ) )
							->default_to( 'bank_transfer' ),
						SettingField::make( 'payment_deadline_minutes', SettingField::TYPE_NUMBER )
							->label( __( 'Payment deadline', 'ferry-booking-manager' ) )
							->unit( __( 'minutes', 'ferry-booking-manager' ) )
							->help( __( 'Shown to the customer on the confirmation. Zero means no deadline is stated.', 'ferry-booking-manager' ) )
							->range( 0, 525600 )
							->default_to( 0 ),
					),
				),
				array(
					'title'  => __( 'Agreements', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'require_terms', SettingField::TYPE_SWITCH )
							->label( __( 'Require agreement to the terms', 'ferry-booking-manager' ) )
							->help( __( 'Adds a tick box to the details step. Needs a terms URL on the General tab.', 'ferry-booking-manager' ) )
							->default_to( false ),
					),
				),
			),
		);
	}

	/**
	 * WooCommerce tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function woocommerce(): array {
		return array(
			'id'          => 'woocommerce',
			'label'       => __( 'WooCommerce', 'ferry-booking-manager' ),
			'group'       => 'money',
			'description' => __( 'Only used when the checkout engine is set to WooCommerce.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Orders', 'ferry-booking-manager' ),
					'description' => __( 'A booking becomes one order carrying the booking total. No product is created per sailing.', 'ferry-booking-manager' ),
					'fields'      => array(
						SettingField::make( 'wc_order_status', SettingField::TYPE_SELECT )
							->label( __( 'Create orders as', 'ferry-booking-manager' ) )
							->options( self::wc_unpaid_statuses() )
							->help( __( 'The status a new order is given while it waits to be paid.', 'ferry-booking-manager' ) )
							->default_to( 'pending' ),
						SettingField::make( 'wc_cancel_on_refund', SettingField::TYPE_SWITCH )
							->label( __( 'Cancel the booking when its order is refunded', 'ferry-booking-manager' ) )
							->help( __( 'Turn this off if you refund partially and want to keep the crossing booked.', 'ferry-booking-manager' ) )
							->default_to( true ),
					),
				),
				array(
					'title'       => __( 'Tax', 'ferry-booking-manager' ),
					'description' => __( 'Lets WooCommerce apply the rate you already configured there to the ferry line.', 'ferry-booking-manager' ),
					'fields'      => array(
						SettingField::make( 'wc_tax_class', SettingField::TYPE_SELECT )
							->label( __( 'Tax class for the ferry line', 'ferry-booking-manager' ) )
							->options( self::wc_tax_classes() )
							->help( __( 'The rate WooCommerce applies to the crossing. These are the classes configured in WooCommerce.', 'ferry-booking-manager' ) )
							->default_to( '' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the WooCommerce statuses an unpaid order may be created in.
	 *
	 * Read from WooCommerce rather than listed here, so a site that has added
	 * its own status can use it and a future WooCommerce release cannot leave
	 * this offering something that no longer exists. Paid and finished statuses
	 * are excluded: creating an order already marked complete would confirm a
	 * crossing nobody has paid for.
	 *
	 * @return array<string, string>
	 */
	private static function wc_unpaid_statuses(): array {
		$fallback = array( 'pending' => __( 'Pending payment', 'ferry-booking-manager' ) );

		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $fallback;
		}

		// Settled statuses, plus WooCommerce's internal Store API draft, which is
		// not a status an operator should be able to choose.
		$excluded = array( 'wc-processing', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft' );
		$options  = array();

		foreach ( (array) wc_get_order_statuses() as $slug => $label ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}

			// WooCommerce keys these with a "wc-" prefix that its own status
			// setters do not expect.
			$options[ (string) preg_replace( '/^wc-/', '', (string) $slug ) ] = (string) $label;
		}

		return array() !== $options ? $options : $fallback;
	}

	/**
	 * Returns the WooCommerce tax classes, including the standard rate.
	 *
	 * @return array<string, string>
	 */
	private static function wc_tax_classes(): array {
		$options = array( '' => __( 'Standard rate', 'ferry-booking-manager' ) );

		if ( ! class_exists( 'WC_Tax' ) ) {
			return $options;
		}

		foreach ( (array) \WC_Tax::get_tax_classes() as $label ) {
			$label = (string) $label;
			$slug  = sanitize_title( $label );

			if ( '' !== $slug ) {
				$options[ $slug ] = $label;
			}
		}

		return $options;
	}

	/**
	 * Payments tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function payments(): array {
		return array(
			'id'          => 'payments',
			'label'       => __( 'Payments', 'ferry-booking-manager' ),
			'group'       => 'money',
			'description' => __( 'The methods the built-in ferry checkout offers.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Payment methods', 'ferry-booking-manager' ),
					'description' => __( 'These are offered by the ferry checkout. WooCommerce mode uses its own gateways instead.', 'ferry-booking-manager' ),
					'elsewhere'   => array(
						'path'  => '/payments',
						'label' => __( 'Open Payments', 'ferry-booking-manager' ),
					),
				),
			),
		);
	}

	/**
	 * Emails tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function emails(): array {
		return array(
			'id'          => 'emails',
			'label'       => __( 'Emails', 'ferry-booking-manager' ),
			'group'       => 'tickets',
			'description' => __( 'Who messages come from, and which ones are sent.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Sender and messages', 'ferry-booking-manager' ),
					'description' => __( 'Who messages come from, which ones go out, the wording of each one and a delivery test are all on the Emails screen.', 'ferry-booking-manager' ),
					'elsewhere'   => array(
						'path'  => '/emails',
						'label' => __( 'Open Emails', 'ferry-booking-manager' ),
					),
				),
				array(
					// Declared here so the keys keep one definition; edited on
					// the Emails screen.
					'hidden' => true,
					'fields' => array(
						SettingField::make( 'email_from_name' )
							->label( __( 'From name', 'ferry-booking-manager' ) )
							->default_to( (string) get_bloginfo( 'name' ) ),
						SettingField::make( 'email_from_address', SettingField::TYPE_EMAIL )
							->label( __( 'From address', 'ferry-booking-manager' ) )
							->default_to( (string) get_option( 'admin_email', '' ) ),
						SettingField::make( 'admin_notification_email', SettingField::TYPE_EMAIL )
							->label( __( 'Send admin notices to', 'ferry-booking-manager' ) )
							->default_to( (string) get_option( 'admin_email', '' ) ),
						SettingField::make( 'email_footer_text', SettingField::TYPE_TEXTAREA )
							->label( __( 'Footer text', 'ferry-booking-manager' ) )
							->help( __( 'Added to the bottom of every customer message. Good place for a port address or a check-in reminder.', 'ferry-booking-manager' ) )
							->wide(),
					),
				),
				array(
					'hidden' => true,
					'fields' => array(
						SettingField::make( 'send_booking_received', SettingField::TYPE_SWITCH )
							->label( __( 'Booking received', 'ferry-booking-manager' ) )
							->help( __( 'Sent as soon as a booking is made, before payment clears.', 'ferry-booking-manager' ) )
							->default_to( true ),
						SettingField::make( 'send_booking_confirmed', SettingField::TYPE_SWITCH )
							->label( __( 'Booking confirmed', 'ferry-booking-manager' ) )
							->help( __( 'Sent when payment is settled and the crossing is secured.', 'ferry-booking-manager' ) )
							->default_to( true ),
						SettingField::make( 'send_booking_cancelled', SettingField::TYPE_SWITCH )
							->label( __( 'Booking cancelled', 'ferry-booking-manager' ) )
							->default_to( true ),
						SettingField::make( 'notify_admin_on_booking', SettingField::TYPE_SWITCH )
							->label( __( 'Tell staff about new bookings', 'ferry-booking-manager' ) )
							->default_to( true ),
					),
				),
			),
		);
	}

	/**
	 * PDF tab.
	 *
	 * A placeholder for Pro to replace by id. Locked tabs are never described to
	 * the dashboard, so without Pro the tab simply is not there.
	 *
	 * @return array<string, mixed>
	 */
	private static function pdf(): array {
		return array(
			'id'          => 'pdf',
			'label'       => __( 'PDF', 'ferry-booking-manager' ),
			'group'       => 'tickets',
			'description' => __( 'Printable tickets and boarding passes.', 'ferry-booking-manager' ),
			'locked'      => true,
			'sections'    => array(),
		);
	}

	/**
	 * QR tab.
	 *
	 * A placeholder for Pro to replace by id, like the PDF tab above.
	 *
	 * @return array<string, mixed>
	 */
	private static function qr(): array {
		return array(
			'id'          => 'qr',
			'label'       => __( 'QR', 'ferry-booking-manager' ),
			'group'       => 'tickets',
			'description' => __( 'Scannable codes and check-in at the gate.', 'ferry-booking-manager' ),
			'locked'      => true,
			'sections'    => array(),
		);
	}

	/**
	 * Taxes tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function taxes(): array {
		return array(
			'id'          => 'taxes',
			'label'       => __( 'Taxes', 'ferry-booking-manager' ),
			'group'       => 'money',
			'description' => __( 'Tax on ferry fares. In WooCommerce mode the rate configured in WooCommerce applies instead, set on the WooCommerce tab.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Fare tax', 'ferry-booking-manager' ),
					'description' => __( 'Tax is set beside the fares it applies to, so a rate and the prices it changes are never edited in two places.', 'ferry-booking-manager' ),
					'elsewhere'   => array(
						'path'  => '/pricing/charges',
						'label' => __( 'Open Pricing', 'ferry-booking-manager' ),
					),
				),
				array(
					// Declared here so the tax keys keep one definition; edited
					// on the Pricing screen.
					'hidden' => true,
					'store'  => self::STORE_PRICING,
					'fields' => array(
						SettingField::make( 'tax_enabled', SettingField::TYPE_SWITCH )
							->label( __( 'Charge tax on fares', 'ferry-booking-manager' ) )
							->default_to( false ),
						SettingField::make( 'tax_label' )
							->label( __( 'Tax name', 'ferry-booking-manager' ) )
							->help( __( 'Shown on the price breakdown, for example VAT or IVA.', 'ferry-booking-manager' ) )
							->default_to( __( 'VAT', 'ferry-booking-manager' ) ),
						SettingField::make( 'tax_rate', SettingField::TYPE_PERCENT )
							->label( __( 'Tax rate', 'ferry-booking-manager' ) )
							->unit( '%' )
							->range( 0, 100 )
							->default_to( 0.0 ),
						SettingField::make( 'tax_mode', SettingField::TYPE_SELECT )
							->label( __( 'Fares include tax', 'ferry-booking-manager' ) )
							->options(
								array(
									PricingSettings::TAX_EXCLUSIVE => __( 'No — add tax on top', 'ferry-booking-manager' ),
									PricingSettings::TAX_INCLUSIVE => __( 'Yes — the fare already contains it', 'ferry-booking-manager' ),
								)
							)
							->help( __( 'Most passenger fares are advertised with tax included.', 'ferry-booking-manager' ) )
							->default_to( PricingSettings::TAX_EXCLUSIVE )
							->wide(),
						SettingField::make( 'tax_applies_to_fees', SettingField::TYPE_SWITCH )
							->label( __( 'Tax booking fees as well as fares', 'ferry-booking-manager' ) )
							->default_to( true ),
					),
				),
			),
		);
	}

	/**
	 * Integrations tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function integrations(): array {
		return array(
			'id'          => 'integrations',
			'label'       => __( 'Integrations', 'ferry-booking-manager' ),
			'group'       => 'system',
			'description' => __( 'Sending booking events on to other systems.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Analytics', 'ferry-booking-manager' ),
					'description' => __( 'The booking form pushes a purchase event to the data layer when a booking completes, so your existing tag manager can pick it up.', 'ferry-booking-manager' ),
					'fields'      => array(
						SettingField::make( 'analytics_events', SettingField::TYPE_SWITCH )
							->label( __( 'Push booking events to the data layer', 'ferry-booking-manager' ) )
							->default_to( false ),
						SettingField::make( 'ga4_measurement_id' )
							->label( __( 'GA4 measurement ID', 'ferry-booking-manager' ) )
							->placeholder( 'G-XXXXXXX' )
							->help( __( 'Optional. Included in the event so a tag can route it to the right property.', 'ferry-booking-manager' ) ),
					),
				),
				array(
					'title'       => __( 'Webhooks', 'ferry-booking-manager' ),
					'description' => __( 'Post a signed message to another system when something happens. Payloads carry references and amounts, never passenger details.', 'ferry-booking-manager' ),
					'custom'      => self::CUSTOM_WEBHOOKS,
				),
				array(
					'title'       => __( 'Import and export', 'ferry-booking-manager' ),
					'description' => __( 'Move ports, vessels, routes, sailings and fare types in and out as CSV.', 'ferry-booking-manager' ),
					'custom'      => self::CUSTOM_TRANSFER,
				),
			),
		);
	}

	/**
	 * Roles tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function roles(): array {
		return array(
			'id'          => 'roles',
			'label'       => __( 'Roles', 'ferry-booking-manager' ),
			'group'       => 'system',
			'description' => __( 'What each kind of staff account is allowed to do.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'       => __( 'Staff permissions', 'ferry-booking-manager' ),
					'description' => __( 'Administrators always keep every permission. Changes apply the next time the person loads a page.', 'ferry-booking-manager' ),
					'custom'      => self::CUSTOM_ROLES,
				),
			),
		);
	}

	/**
	 * Advanced tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function advanced(): array {
		return array(
			'id'          => 'advanced',
			'label'       => __( 'Advanced', 'ferry-booking-manager' ),
			'group'       => 'system',
			'description' => __( 'Logging, caching and what happens to your data if you remove the plugin.', 'ferry-booking-manager' ),
			'sections'    => array(
				array(
					'title'  => __( 'Diagnostics', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'log_level', SettingField::TYPE_SELECT )
							->label( __( 'Record log entries from', 'ferry-booking-manager' ) )
							->options(
								array(
									''            => __( 'Follow WP_DEBUG', 'ferry-booking-manager' ),
									Logger::DEBUG => __( 'Everything, including debug', 'ferry-booking-manager' ),
									Logger::INFO  => __( 'Information and above', 'ferry-booking-manager' ),
									Logger::ERROR => __( 'Errors only', 'ferry-booking-manager' ),
									'off'         => __( 'Nothing', 'ferry-booking-manager' ),
								)
							)
							->help( __( 'Debug logging is noisy and is meant for tracking down a specific problem, not for leaving on.', 'ferry-booking-manager' ) )
							->default_to( '' ),
					),
				),
				array(
					'title'  => __( 'Performance', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'cache_seconds', SettingField::TYPE_NUMBER )
							->label( __( 'Cache search and availability for', 'ferry-booking-manager' ) )
							->unit( __( 'seconds', 'ferry-booking-manager' ) )
							->help( __( 'Cleared immediately whenever a booking changes, so a longer window does not risk overselling.', 'ferry-booking-manager' ) )
							->range( 0, 3600 )
							->default_to( 300 ),
						SettingField::make( 'public_rate_limit', SettingField::TYPE_NUMBER )
							->label( __( 'Public requests allowed per minute', 'ferry-booking-manager' ) )
							->unit( __( 'per visitor', 'ferry-booking-manager' ) )
							->help( __( 'Applies to searching, pricing and booking from the front end.', 'ferry-booking-manager' ) )
							->range( 5, 6000 )
							->default_to( 60 ),
					),
				),
				array(
					'title'  => __( 'Removing the plugin', 'ferry-booking-manager' ),
					'fields' => array(
						SettingField::make( 'delete_data_on_uninstall', SettingField::TYPE_SWITCH )
							->label( __( 'Delete all ferry data when the plugin is deleted', 'ferry-booking-manager' ) )
							->help( __( 'Off by default. When on, deleting the plugin permanently removes every vessel, route, sailing and booking. There is no undo.', 'ferry-booking-manager' ) )
							->default_to( false )
							->wide(),
					),
				),
			),
		);
	}
}
