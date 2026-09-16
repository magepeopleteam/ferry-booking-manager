<?php
/**
 * Booking service.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Booking;

use FBM\Availability\Availability;
use FBM\Availability\AvailabilityService;
use FBM\Availability\HoldManager;
use FBM\Contracts\LoggerInterface;
use FBM\Models\Booking;
use FBM\Models\PassengerType;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\VehicleType;
use FBM\Notification\NotificationService;
use FBM\Payment\PaymentGatewayRegistry;
use FBM\Pricing\PricingService;
use FBM\Pricing\Quote;
use FBM\Repositories\BookingRepository;
use FBM\Repositories\PassengerTypeRepository;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VehicleTypeRepository;
use FBM\Repositories\VesselRepository;
use FBM\Settings\Settings;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a booking from a public request.
 *
 * The service is the single path a customer booking takes. It validates the
 * party and the customer, asks the pricing engine for the authoritative total,
 * reserves capacity through the hold manager, records the booking, applies the
 * payment method (native offline, or a WooCommerce order), and hands the result
 * to the notification service.
 *
 * The server is authoritative on every number; nothing received from the
 * browser is stored as a price. A repeated `idempotency_key` returns the
 * existing booking instead of creating a second one.
 */
final class BookingService {

	/**
	 * Booking repository.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Sailing repository.
	 *
	 * @var SailingRepository
	 */
	private SailingRepository $sailings;

	/**
	 * Route repository.
	 *
	 * @var RouteRepository
	 */
	private RouteRepository $routes;

	/**
	 * Vessel repository.
	 *
	 * @var VesselRepository
	 */
	private VesselRepository $vessels;

	/**
	 * Passenger type repository.
	 *
	 * @var PassengerTypeRepository
	 */
	private PassengerTypeRepository $passenger_types;

	/**
	 * Vehicle type repository.
	 *
	 * @var VehicleTypeRepository
	 */
	private VehicleTypeRepository $vehicle_types;

	/**
	 * Pricing engine.
	 *
	 * @var PricingService
	 */
	private PricingService $pricing;

	/**
	 * Availability engine.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * Hold manager.
	 *
	 * @var HoldManager
	 */
	private HoldManager $holds;

	/**
	 * Payment gateway registry.
	 *
	 * @var PaymentGatewayRegistry
	 */
	private PaymentGatewayRegistry $gateways;

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param BookingRepository       $bookings       Booking repository.
	 * @param SailingRepository       $sailings       Sailing repository.
	 * @param RouteRepository         $routes         Route repository.
	 * @param VesselRepository        $vessels        Vessel repository.
	 * @param PassengerTypeRepository $passenger_types Passenger type repository.
	 * @param VehicleTypeRepository   $vehicle_types  Vehicle type repository.
	 * @param PricingService          $pricing        Pricing engine.
	 * @param AvailabilityService     $availability   Availability engine.
	 * @param HoldManager             $holds          Hold manager.
	 * @param PaymentGatewayRegistry  $gateways       Payment gateway registry.
	 * @param NotificationService     $notifications  Notification service.
	 * @param LoggerInterface         $logger         Logger.
	 */
	public function __construct(
		BookingRepository $bookings,
		SailingRepository $sailings,
		RouteRepository $routes,
		VesselRepository $vessels,
		PassengerTypeRepository $passenger_types,
		VehicleTypeRepository $vehicle_types,
		PricingService $pricing,
		AvailabilityService $availability,
		HoldManager $holds,
		PaymentGatewayRegistry $gateways,
		NotificationService $notifications,
		LoggerInterface $logger
	) {
		$this->bookings        = $bookings;
		$this->sailings        = $sailings;
		$this->routes          = $routes;
		$this->vessels         = $vessels;
		$this->passenger_types = $passenger_types;
		$this->vehicle_types   = $vehicle_types;
		$this->pricing         = $pricing;
		$this->availability    = $availability;
		$this->holds           = $holds;
		$this->gateways        = $gateways;
		$this->notifications   = $notifications;
		$this->logger          = $logger;
	}

	/**
	 * Creates a booking.
	 *
	 * @param array<string, mixed> $request Validated request.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $request ) {
		return $this->make( $request, false );
	}

	/**
	 * Creates a booking on behalf of a customer, taken by a member of staff.
	 *
	 * Same engine, same availability check, same reference series — a counter
	 * booking is a booking. What differs is authority: staff are standing in
	 * front of the customer, so the rules that exist to protect a self-service
	 * form (sign in first, tick the terms box) do not apply, and staff may set
	 * the booking and payment status directly because they are the ones taking
	 * the cash. The caller is responsible for having checked the capability.
	 *
	 * @param array<string, mixed> $request Booking request.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_for_staff( array $request ) {
		return $this->make( $request, true );
	}

	/**
	 * Creates a booking.
	 *
	 * @param array<string, mixed> $request Booking request.
	 * @param bool                 $staff   Whether a member of staff is taking it.
	 * @return array<string, mixed>|WP_Error
	 */
	private function make( array $request, bool $staff ) {
		$sailing = $this->sailings->find( (int) ( $request['sailing_id'] ?? 0 ) );

		if ( ! $sailing instanceof Sailing ) {
			return new WP_Error(
				'fbm_sailing_not_found',
				__( 'That sailing could not be found.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 404 )
			);
		}

		$return_sailing = null;
		$return_id      = (int) ( $request['return_sailing_id'] ?? 0 );

		if ( $return_id > 0 ) {
			$return_sailing = $this->sailings->find( $return_id );

			if ( ! $return_sailing instanceof Sailing ) {
				return new WP_Error(
					'fbm_return_sailing_not_found',
					__( 'That return sailing could not be found.', 'magepeople-ferry-booking-system' ),
					array( 'status' => 404 )
				);
			}
		}

		$party = $this->party( $request['passengers'] ?? array(), $request['vehicles'] ?? array() );

		if ( is_wp_error( $party ) ) {
			return $party;
		}

		$passengers = $party['passengers'];
		$vehicles   = $party['vehicles'];
		$usage      = $this->pricing->usage( $passengers, $vehicles );

		if ( array() === $passengers && array() === $vehicles ) {
			return new WP_Error(
				'fbm_empty_party',
				__( 'Add at least one passenger or vehicle before booking.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		$within_limits = $this->check_party_limits( $passengers, $vehicles );

		if ( is_wp_error( $within_limits ) ) {
			return $within_limits;
		}

		/*
		 * The booking request is carried through, with the values resolved above
		 * put back on top. A booking has to be priced with the same inputs the
		 * customer was quoted on — including anything an add-on prices, such as
		 * extras — or the total on the confirmation would not match the total on
		 * the review screen.
		 */
		$quote = $this->pricing->quote(
			array_merge(
				$request,
				array(
					'sailing_id'        => $sailing->id,
					'return_sailing_id' => $return_sailing instanceof Sailing ? $return_sailing->id : 0,
					'passengers'        => $passengers,
					'vehicles'          => $vehicles,
				)
			)
		);

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		if ( $staff ) {
			$discounted = $this->apply_manual_discount( $quote, $request );

			if ( is_wp_error( $discounted ) ) {
				return $discounted;
			}
		}

		$customer = $this->customer( $request['customer'] ?? array(), $staff );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$settings = Settings::all();

		// Capacity is reserved first, then the booking is written, then the
		// payment path decides what happens to the hold.

		/*
		 * The quote's usage, not the party's. Both agree on seats and vehicles,
		 * but an add-on that sells inventory of its own — a cabin, a berth,
		 * a deck slot measured in metres — declares it on the quote, and the
		 * hold has to reserve everything the customer is actually buying or the
		 * extra inventory would never sell out.
		 */
		$usage = array_merge( $usage, $quote->usage );

		$hold = $this->holds->reserve(
			$sailing->id,
			$usage,
			array(
				'booking_type'      => $return_sailing instanceof Sailing ? Booking::TYPE_RETURN : Booking::TYPE_ONE_WAY,
				'return_sailing_id' => $return_sailing instanceof Sailing ? $return_sailing->id : 0,
				'customer_name'     => $customer['name'],
				'customer_email'    => $customer['email'],
				'customer_phone'    => $customer['phone'],
				'customer_id'       => $customer['id'],
				'channel'           => $staff ? $this->staff_channel( $request ) : 'web',
				// Who took the booking, which for a counter sale is the member
				// of staff rather than the customer they took it for.
				'created_by'        => $staff ? get_current_user_id() : ( $customer['id'] > 0 ? $customer['id'] : 0 ),
			),
			(string) ( $request['idempotency_key'] ?? '' )
		);

		if ( is_wp_error( $hold ) ) {
			return $hold;
		}

		if ( ! $hold instanceof Booking ) {
			$this->logger->error( 'Booking hold returned an unexpected result.', array( 'sailing' => $sailing->id ) );

			return new WP_Error(
				'fbm_booking_failed',
				__( 'The booking could not be created. Please try again.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 500 )
			);
		}

		// The hold covers the outbound sailing; the return leg must still be
		// proven against the same engine, or a round trip could oversell it.
		if ( $return_sailing instanceof Sailing ) {
			$return_availability = $this->availability->for_sailing_entity( $return_sailing );

			$return_verdict = $this->availability->check( $return_availability, $usage );

			if ( is_wp_error( $return_verdict ) ) {
				$this->holds->release( $hold, 'return_leg_unavailable' );

				return $return_verdict;
			}
		}

		// An idempotent retry resolves to the first booking made with this
		// key; a booking that already carries a reference was persisted by an
		// earlier attempt, so there is nothing left to do here.
		if ( '' !== $hold->number() ) {
			$this->logger->info(
				'Replayed a booking request with an existing idempotency key.',
				array(
					'booking' => $hold->id,
					'key'     => (string) ( $request['idempotency_key'] ?? '' ),
				)
			);

			return $this->present( $hold );
		}

		// Now that the hold exists, persist the price, the party and the
		// reference — all the data that makes it a booking rather than a
		// placeholder.
		$reference = $this->reference( $hold->id );

		$attributes = array(
			'booking_number' => $reference,
			'subtotal'       => $quote->subtotal(),
			'discount'       => $quote->discount(),
			'tax'            => $quote->tax(),
			'fees'           => $quote->fees(),
			'total'          => $quote->total(),
			'paid'           => 0,
			'refunded'       => 0,
			'currency'       => $quote->currency,
			'departure_ts'   => $sailing->departure_timestamp(),
			'passengers'     => $this->serialize_party( $passengers, $request['passengers'] ?? array() ),
			'vehicles'       => $this->serialize_party( $vehicles, $request['vehicles'] ?? array() ),
			'payment_method' => '',
			'payment_status' => Booking::PAYMENT_UNPAID,
			'booking_status' => Booking::STATUS_PENDING,
		);

		// Merged, not unioned: `+` keeps the left-hand value for a key present
		// on both sides, which would silently discard every status a member of
		// staff had just set.
		if ( $staff ) {
			$attributes = array_merge( $attributes, $this->staff_attributes( $request, $quote->total() ) );
		}

		$saved = $this->bookings->save( $attributes, $hold->id, $reference );

		if ( is_wp_error( $saved ) ) {
			$this->holds->release( $hold, 'persist_failed' );

			return $saved;
		}

		/** @var Booking $booking */
		$booking = $saved;

		/**
		 * Fires before a booking's payment path runs, once the booking is
		 * fully persisted and priced.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking              $booking Saved booking.
		 * @param array<string, mixed> $request Original request.
		 */
		do_action( 'fbm_before_payment', $booking, $request );

		$payment = $this->apply_payment( $booking, $request, $settings, $staff );

		if ( is_wp_error( $payment ) ) {
			$this->holds->release( $hold, 'payment_failed' );

			return $payment;
		}

		return $this->present( $payment['booking'], $payment['redirect'] ?? '' );
	}

	/**
	 * Applies the requested payment path to a booking.
	 *
	 * @param Booking              $booking  Booking entity.
	 * @param array<string, mixed> $request  Original request.
	 * @param array<string, mixed> $settings Resolved settings.
	 * @param bool                 $staff    Whether a member of staff is taking it.
	 * @return array{booking: Booking, redirect?: string}|WP_Error
	 */
	private function apply_payment( Booking $booking, array $request, array $settings, bool $staff = false ) {
		$engine = (string) $settings['checkout_engine'];

		/**
		 * Filters whether a booking goes through WooCommerce.
		 *
		 * Lets Pro route specific bookings elsewhere without editing Free.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $use_woocommerce Whether WooCommerce handles checkout.
		 * @param Booking $booking         Booking entity.
		 */
		$use_woocommerce = (bool) apply_filters( 'fbm_use_woocommerce_checkout', Settings::CHECKOUT_WOOCOMMERCE === $engine && class_exists( 'WooCommerce' ), $booking );

		if ( $use_woocommerce ) {
			return $this->apply_woocommerce( $booking );
		}

		return $this->apply_native( $booking, $request, $staff );
	}

	/**
	 * Hands a booking to WooCommerce by creating an order for its total.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array{booking: Booking, redirect: string}|WP_Error
	 */
	private function apply_woocommerce( Booking $booking ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error(
				'fbm_woocommerce_missing',
				__( 'WooCommerce is not available on this site.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 409 )
			);
		}

		$existing = (int) $booking->get( 'wc_order_id' );

		if ( $existing > 0 ) {
			$order = wc_get_order( $existing );

			if ( $order instanceof \WC_Order ) {
				return array(
					'booking'  => $booking,
					'redirect' => (string) $order->get_checkout_payment_url(),
				);
			}
		}

		$customer_id = (int) $booking->get( 'customer_id' );
		$settings    = Settings::all();

		try {
			$order = wc_create_order(
				array(
					'customer_id' => $customer_id > 0 ? $customer_id : 0,
				)
			);
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Could not create a WooCommerce order for a booking.',
				array(
					'booking' => $booking->id,
					'error'   => $e->getMessage(),
				)
			);

			return new WP_Error(
				'fbm_wc_order_failed',
				__( 'The checkout could not be started. Please try again.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 500 )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: booking reference. */
				__( 'Ferry booking %s', 'magepeople-ferry-booking-system' ),
				$booking->number()
			)
		);

		// A single line item carrying the whole booking total, rather than a
		// product per sailing: the sailing's own data is the source of truth
		// and nothing here should be re-priced by WooCommerce.
		$line = new \WC_Order_Item_Fee();
		$line->set_name(
			sprintf(
				/* translators: 1: route, 2: booking reference. */
				__( 'Ferry crossing — %1$s (%2$s)', 'magepeople-ferry-booking-system' ),
				$this->route_label( (int) $booking->get( 'sailing_id' ) ),
				$booking->number()
			)
		);
		$line->set_total( $booking->total() / 100 );

		// Letting WooCommerce apply the operator's configured rate to the ferry
		// line is the only way the WooCommerce mode can charge tax at all: the
		// booking total is authoritative and is never re-priced here.
		$tax_class = (string) ( $settings['wc_tax_class'] ?? '' );

		if ( '' !== $tax_class ) {
			$line->set_tax_class( $tax_class );
		}

		$order->add_item( $line );

		$order->set_billing_email( (string) $booking->get( 'customer_email' ) );

		if ( '' !== (string) $booking->get( 'customer_name' ) ) {
			$parts = $this->split_name( (string) $booking->get( 'customer_name' ) );
			$order->set_billing_first_name( $parts[0] );
			$order->set_billing_last_name( $parts[1] );
		}

		if ( '' !== (string) $booking->get( 'customer_phone' ) ) {
			$order->set_billing_phone( (string) $booking->get( 'customer_phone' ) );
		}

		$order->calculate_totals();

		$status = (string) ( $settings['wc_order_status'] ?? 'pending' );

		if ( in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
			$order->set_status( $status );
		}

		$order->save();

		update_post_meta( $order->get_id(), '_fbm_booking_id', $booking->id );

		$saved = $this->bookings->save(
			array(
				'wc_order_id'    => $order->get_id(),
				'payment_method' => 'woocommerce',
			),
			$booking->id
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		/**
		 * Fires once a WooCommerce order has been created for a booking.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking  $booking Booking entity.
		 * @param \WC_Order $order   WooCommerce order.
		 */
		do_action( 'fbm_wc_order_created', $saved, $order );

		return array(
			'booking'  => $saved,
			'redirect' => (string) $order->get_checkout_payment_url(),
		);
	}

	/**
	 * Applies a native (offline) payment method.
	 *
	 * The Free gateways are all non-instant: the booking is recorded as
	 * pending payment, with a payment deadline when the operator configured
	 * one, and the confirmation email carries the payment instructions.
	 *
	 * @param Booking              $booking Booking entity.
	 * @param array<string, mixed> $request Original request.
	 * @param bool                 $staff   Whether a member of staff is taking it.
	 * @return array{booking: Booking}|WP_Error
	 */
	private function apply_native( Booking $booking, array $request, bool $staff = false ) {
		$method_id = sanitize_key( (string) ( $request['payment_method'] ?? '' ) );

		if ( '' === $method_id ) {
			$method_id = $this->gateways->default_id();
		}

		$gateway = $this->gateways->get( $method_id );

		if ( null === $gateway ) {
			return new WP_Error(
				'fbm_payment_method_unavailable',
				__( 'That payment method is not available.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => array( 'payment_method' => __( 'Choose a payment method.', 'magepeople-ferry-booking-system' ) ),
				)
			);
		}

		/**
		 * Filters whether a booking may be paid through the chosen gateway.
		 *
		 * A gateway can present itself and still have to refuse a particular
		 * booking — an account over its credit limit, a card scheme that will
		 * not take this amount. Returning a WP_Error stops the payment, and the
		 * booking's hold is released with it, so nothing half-paid is left
		 * behind.
		 *
		 * @since 1.0.0
		 *
		 * @param true|\WP_Error       $allowed Whether the payment may proceed.
		 * @param string               $method  Gateway id.
		 * @param Booking              $booking Booking entity.
		 * @param array<string, mixed> $request Original request.
		 */
		$allowed = apply_filters( 'fbm_payment_allowed', true, $gateway->get_id(), $booking, $request );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$settings = Settings::all();

		$deadline = (int) $settings['payment_deadline_minutes'];

		$status = $gateway->is_instant() ? Booking::STATUS_CONFIRMED : Booking::STATUS_PENDING;

		$attributes = array(
			'payment_method'  => $gateway->get_id(),
			'payment_status'  => $gateway->is_instant() ? Booking::PAYMENT_PAID : Booking::PAYMENT_UNPAID,
			'booking_status'  => $status,
			'hold_expires_ts' => 0,
			'paid'            => $gateway->is_instant() ? $booking->total() : 0,
		);

		/*
		 * A gateway describes how the money is normally expected to arrive. A
		 * member of staff at the counter knows whether it actually has, so their
		 * answer replaces the gateway's assumption — otherwise a cash sale would
		 * be filed as awaiting a bank transfer.
		 */
		if ( $staff ) {
			$attributes = array_merge( $attributes, $this->staff_attributes( $request, $booking->total() ) );
			$status     = (string) $attributes['booking_status'];
		}

		$saved = $this->bookings->save( $attributes, $booking->id );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// The hold is now permanent either way: instant gateways confirm it,
		// and pending bookings consume capacity while awaiting payment.
		$confirmed = $this->holds->confirm( $saved, $status );

		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		/** @var Booking $saved */
		$saved = $confirmed;

		$deadline_ts = 0;

		if ( Booking::STATUS_PENDING === $status && $deadline > 0 ) {
			$deadline_ts = time() + ( $deadline * MINUTE_IN_SECONDS );
			$saved       = $this->bookings->save( array( 'hold_expires_ts' => $deadline_ts ), $saved->id );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		/*
		 * An instant gateway, or a member of staff marking a counter sale paid,
		 * produces a booking that is already confirmed. Sending "we have
		 * received your booking" to somebody holding the ticket is the wrong
		 * message, so the confirmation goes instead — one email either way.
		 */
		if ( Booking::STATUS_CONFIRMED === $status ) {
			$this->notifications->send_booking_confirmed( $saved );
		} else {
			$this->notifications->send_booking_received( $saved );
		}

		$this->notifications->send_admin_new_booking( $saved );

		if ( Booking::STATUS_CONFIRMED === $status ) {
			/*
			 * The same event the WooCommerce path fires once payment lands.
			 * Without it here, a booking confirmed at the counter or through an
			 * instant gateway would never reach the audit log, the webhooks, the
			 * automations or an agency's account — every one of which is
			 * listening for a booking becoming confirmed, not for one
			 * particular way of paying.
			 */
			do_action( 'fbm_booking_confirmed', $saved );
		}

		/**
		 * Fires after a native payment method has been applied to a booking.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking $booking Booking entity.
		 */
		do_action( 'fbm_native_payment_applied', $saved );

		return array( 'booking' => $saved );
	}

	/**
	 * Confirms a booking once its WooCommerce order is paid.
	 *
	 * @param int $booking_id Booking id.
	 * @return Booking|WP_Error|null
	 */
	public function confirm_wc_paid( int $booking_id ) {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking instanceof Booking ) {
			return null;
		}

		if ( Booking::STATUS_CONFIRMED === $booking->get( 'booking_status' ) ) {
			return $booking;
		}

		$saved = $this->holds->confirm( $booking, Booking::STATUS_CONFIRMED );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$saved = $this->bookings->save(
			array(
				'payment_status' => Booking::PAYMENT_PAID,
				'paid'           => $saved->total(),
			),
			$saved->id
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->notifications->send_booking_confirmed( $saved );

		/**
		 * Fires once a booking is confirmed after WooCommerce payment.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking $booking Confirmed booking.
		 */
		do_action( 'fbm_booking_confirmed', $saved );

		return $saved;
	}

	/**
	 * Cancels a booking and releases its capacity.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $reason     Optional cancellation reason.
	 * @return Booking|WP_Error|null
	 */
	public function cancel( int $booking_id, string $reason = '' ) {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking instanceof Booking ) {
			return null;
		}

		if ( in_array( (string) $booking->get( 'booking_status' ), array( Booking::STATUS_CANCELLED, Booking::STATUS_REFUNDED ), true ) ) {
			return $booking;
		}

		$saved = $this->bookings->save(
			array(
				'booking_status'  => Booking::STATUS_CANCELLED,
				'payment_status'  => Booking::PAYMENT_CANCELLED,
				'hold_expires_ts' => 0,
			),
			$booking->id
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->availability->invalidate( (int) $saved->get( 'sailing_id' ) );

		$wc_order_id = (int) $saved->get( 'wc_order_id' );

		if ( $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $wc_order_id );

			if ( $order instanceof \WC_Order ) {
				$order->update_status( 'cancelled', '' !== $reason ? $reason : __( 'Ferry booking cancelled.', 'magepeople-ferry-booking-system' ) );
			}
		}

		$this->notifications->send_booking_cancelled( $saved, $reason );

		/**
		 * Fires once a booking is cancelled.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking $booking Cancelled booking.
		 * @param string  $reason  Cancellation reason.
		 */
		do_action( 'fbm_booking_cancelled', $saved, $reason );

		return $saved;
	}

	/**
	 * Updates the parts of a booking staff are allowed to change.
	 *
	 * Deliberately narrow. Contact details, statuses, the payment method and
	 * internal notes are editable; the party and the sailing are not, because
	 * changing either is a re-price and a fresh capacity check — a different
	 * operation with different consequences, which belongs to the modification
	 * flow rather than to an edit form.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $input      Submitted changes.
	 * @return Booking|WP_Error
	 */
	public function update( int $booking_id, array $input ) {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking instanceof Booking ) {
			return new WP_Error(
				'fbm_not_found',
				__( 'That booking could not be found.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 404 )
			);
		}

		$attributes = array();
		$fields     = array();

		if ( array_key_exists( 'customer_name', $input ) ) {
			$name = sanitize_text_field( (string) $input['customer_name'] );

			if ( '' === $name ) {
				$fields['customer_name'] = __( 'A name is required.', 'magepeople-ferry-booking-system' );
			} else {
				$attributes['customer_name'] = $name;
			}
		}

		if ( array_key_exists( 'customer_email', $input ) ) {
			$email = sanitize_email( (string) $input['customer_email'] );

			if ( '' === $email || ! is_email( $email ) ) {
				$fields['customer_email'] = __( 'A valid email address is required.', 'magepeople-ferry-booking-system' );
			} else {
				$attributes['customer_email'] = $email;
			}
		}

		if ( array_key_exists( 'customer_phone', $input ) ) {
			$attributes['customer_phone'] = sanitize_text_field( (string) $input['customer_phone'] );
		}

		if ( array_key_exists( 'internal_notes', $input ) ) {
			$attributes['internal_notes'] = sanitize_textarea_field( (string) $input['internal_notes'] );
		}

		if ( array_key_exists( 'payment_method', $input ) ) {
			$attributes['payment_method'] = sanitize_key( (string) $input['payment_method'] );
		}

		$statuses = $booking::schema()->field( 'booking_status' );
		$payments = $booking::schema()->field( 'payment_status' );

		$next_status = $booking->get( 'booking_status' );

		if ( array_key_exists( 'booking_status', $input ) ) {
			$candidate = sanitize_key( (string) $input['booking_status'] );

			if ( null !== $statuses && ! in_array( $candidate, $statuses->enum, true ) ) {
				$fields['booking_status'] = __( 'That is not a booking status.', 'magepeople-ferry-booking-system' );
			} else {
				$next_status                   = $candidate;
				$attributes['booking_status']  = $candidate;
				$attributes['hold_expires_ts'] = 0;
			}
		}

		if ( array_key_exists( 'payment_status', $input ) ) {
			$candidate = sanitize_key( (string) $input['payment_status'] );

			if ( null !== $payments && ! in_array( $candidate, $payments->enum, true ) ) {
				$fields['payment_status'] = __( 'That is not a payment status.', 'magepeople-ferry-booking-system' );
			} else {
				$attributes['payment_status'] = $candidate;
			}
		}

		if ( array() !== $fields ) {
			return new WP_Error(
				'fbm_validation_failed',
				__( 'Please correct the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => $fields,
				)
			);
		}

		/*
		 * Bringing a cancelled booking back to life is the one status change
		 * that can oversell a sailing: its seats went back into the pool when it
		 * was cancelled and may already have been sold to somebody else. So it
		 * is checked against current availability, not simply written.
		 */
		$was_consuming = $booking->consumes_capacity();
		$now_consuming = in_array( $next_status, Booking::CONSUMING_STATUSES, true );

		if ( ! $was_consuming && $now_consuming ) {
			$sailing = $this->sailings->find( (int) $booking->get( 'sailing_id' ) );

			if ( $sailing instanceof Sailing ) {
				$usage = array(
					Availability::PASSENGERS  => (int) $booking->get( 'passenger_count' ),
					Availability::VEHICLES    => (int) $booking->get( 'vehicle_count' ),
					Availability::LANE_METRES => (float) $booking->get( 'lane_metres' ),
				);

				$check = $this->availability->check( $this->availability->fresh( $sailing ), $usage );

				if ( is_wp_error( $check ) ) {
					return new WP_Error(
						'fbm_capacity_taken',
						sprintf(
							/* translators: %s: the reason the sailing cannot take the booking. */
							__( 'This booking cannot be reinstated: %s', 'magepeople-ferry-booking-system' ),
							$check->get_error_message()
						),
						array(
							'status' => 409,
							'fields' => array( 'booking_status' => $check->get_error_message() ),
						)
					);
				}
			}
		}

		if ( array() === $attributes ) {
			return $booking;
		}

		$saved = $this->bookings->save( $attributes, $booking->id );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->availability->invalidate( (int) $saved->get( 'sailing_id' ) );

		$previous = (string) $booking->get( 'booking_status' );
		$current  = (string) $saved->get( 'booking_status' );

		if ( $previous !== $current ) {
			if ( Booking::STATUS_CONFIRMED === $current ) {
				$this->notifications->send_booking_confirmed( $saved );
			} elseif ( Booking::STATUS_CANCELLED === $current ) {
				$this->notifications->send_booking_cancelled( $saved, '' );
			}
		}

		/**
		 * Fires after staff edit a booking.
		 *
		 * @since 1.0.0
		 *
		 * @param Booking $saved    Updated booking.
		 * @param string  $previous Previous booking status.
		 */
		do_action( 'fbm_booking_updated', $saved, $previous );

		return $saved;
	}

	/**
	 * Returns the public representation of a booking.
	 *
	 * @param Booking $booking  Booking entity.
	 * @param string  $redirect Optional payment redirect URL.
	 * @return array<string, mixed>
	 */
	private function present( Booking $booking, string $redirect = '' ): array {
		$payload = array(
			'id'             => $booking->id,
			'reference'      => $booking->number(),
			'status'         => (string) $booking->get( 'booking_status' ),
			'payment_status' => (string) $booking->get( 'payment_status' ),
			'total'          => $booking->total(),
			'currency'       => (string) $booking->get( 'currency' ),
			'payment_method' => (string) $booking->get( 'payment_method' ),
			'instructions'   => $this->payment_instructions( (string) $booking->get( 'payment_method' ) ),
			'redirect'       => $redirect,
		);

		/**
		 * Filters the public booking response.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Response payload.
		 * @param Booking              $booking Booking entity.
		 */
		return (array) apply_filters( 'fbm_booking_response', $payload, $booking );
	}

	/**
	 * Returns the payment instructions shown after a native checkout.
	 *
	 * @param string $method Payment method id.
	 * @return string
	 */
	private function payment_instructions( string $method ): string {
		$settings  = Settings::all();
		$deadline  = (int) $settings['payment_deadline_minutes'];
		$reference = '';

		if ( 'bank_transfer' === $method && $deadline > 0 ) {
			// Written out rather than chosen with a ternary so each string keeps
			// its own translator note; a shared one above a ternary does not
			// reach either message in the .pot file.
			if ( $deadline >= 60 ) {
				$reference = sprintf(
					/* translators: %d: payment deadline in hours. */
					__( 'Please complete your transfer within %d hours and quote the booking reference.', 'magepeople-ferry-booking-system' ),
					(int) ceil( $deadline / 60 )
				);
			} else {
				$reference = sprintf(
					/* translators: %d: payment deadline in minutes. */
					__( 'Please complete your transfer within %d minutes and quote the booking reference.', 'magepeople-ferry-booking-system' ),
					$deadline
				);
			}
		} elseif ( in_array( $method, array( 'cash', 'pay_at_port', 'manual' ), true ) ) {
			$reference = __( 'Your booking is held for you. Please complete payment at the terminal before boarding.', 'magepeople-ferry-booking-system' );
		}

		/**
		 * Filters the payment instructions shown after checkout.
		 *
		 * @since 1.0.0
		 *
		 * @param string $instructions Instructions text.
		 * @param string $method       Payment method id.
		 */
		return (string) apply_filters( 'fbm_payment_instructions', $reference, $method );
	}

	/**
	 * Returns the channel a staff booking was taken through.
	 *
	 * The permitted values come from the booking schema rather than a list
	 * repeated here. Naming a channel the schema does not declare would not
	 * fail — the enum sanitiser would quietly store "web" instead, and every
	 * counter sale would look like a website booking in the reports.
	 *
	 * @param array<string, mixed> $request Booking request.
	 * @return string
	 */
	private function staff_channel( array $request ): string {
		$field   = $this->bookings->schema()->field( 'channel' );
		$allowed = null === $field ? array() : array_values( array_diff( $field->enum, array( 'web' ) ) );
		$channel = sanitize_key( (string) ( $request['channel'] ?? '' ) );

		if ( in_array( $channel, $allowed, true ) ) {
			return $channel;
		}

		// A booking taken by staff through the dashboard.
		return in_array( 'backend', $allowed, true ) ? 'backend' : 'web';
	}

	/**
	 * Returns the attributes only a member of staff may set directly.
	 *
	 * Staff are the ones taking the money, so they say whether it has been
	 * taken. A customer filling in the public form never reaches this.
	 *
	 * @param array<string, mixed> $request Booking request.
	 * @param int                  $total   Booking total in minor units.
	 * @return array<string, mixed>
	 */
	private function staff_attributes( array $request, int $total ): array {
		$attributes = array();

		$note = sanitize_textarea_field( (string) ( $request['internal_notes'] ?? '' ) );

		if ( '' !== $note ) {
			$attributes['internal_notes'] = $note;
		}

		/*
		 * The agency a counter or portal booking is being sold through. Free
		 * records the id; whether it names a real agency, and what that agency's
		 * terms are, is Pro's question to answer before it gets here.
		 */
		$agent_id = absint( $request['agent_id'] ?? 0 );

		if ( $agent_id > 0 ) {
			$attributes['agent_id'] = $agent_id;
		}

		$booking_status = sanitize_key( (string) ( $request['booking_status'] ?? '' ) );

		if ( in_array( $booking_status, self::staff_booking_statuses(), true ) ) {
			$attributes['booking_status'] = $booking_status;
		}

		$payment_status = sanitize_key( (string) ( $request['payment_status'] ?? '' ) );

		if ( in_array( $payment_status, self::staff_payment_statuses(), true ) ) {
			$attributes['payment_status'] = $payment_status;

			// A booking marked paid at the counter has been paid in full;
			// leaving the paid total at zero would show a balance owing on a
			// ticket the customer has already settled.
			if ( Booking::PAYMENT_PAID === $payment_status ) {
				$attributes['paid'] = $total;
			}

			/*
			 * A part payment is only meaningful with an amount against it. It is
			 * clamped to the total because a deposit larger than the fare is a
			 * miskey, and recording it would show the customer a negative
			 * balance the operator then has to explain.
			 */
			if ( Booking::PAYMENT_PARTIAL === $payment_status ) {
				$attributes['paid'] = max( 0, min( $total, (int) ( $request['amount_paid'] ?? 0 ) ) );
			}
		}

		return $attributes;
	}

	/**
	 * Returns the booking statuses staff may set when taking a booking.
	 *
	 * Cancelled, refunded and failed are deliberately absent: those are
	 * outcomes of something happening later, not ways to open a booking.
	 *
	 * @return string[]
	 */
	public static function staff_booking_statuses(): array {
		return array( Booking::STATUS_PENDING, Booking::STATUS_ON_HOLD, Booking::STATUS_CONFIRMED );
	}

	/**
	 * Returns the payment statuses staff may set when taking a booking.
	 *
	 * @return string[]
	 */
	public static function staff_payment_statuses(): array {
		return array( Booking::PAYMENT_UNPAID, Booking::PAYMENT_PARTIAL, Booking::PAYMENT_PAID );
	}

	/**
	 * Applies a discount a member of staff has granted by hand.
	 *
	 * Expressed in minor units and taken off the fare, never off tax or fees,
	 * and never below zero: a booking that pays less than nothing is a refund,
	 * which is a different operation with different authority behind it.
	 *
	 * @param Quote                $quote   Quote to adjust.
	 * @param array<string, mixed> $request Booking request.
	 * @return true|WP_Error
	 */
	private function apply_manual_discount( Quote $quote, array $request ) {
		$amount = (int) ( $request['manual_discount'] ?? 0 );

		if ( $amount <= 0 ) {
			return true;
		}

		if ( $amount > $quote->subtotal() ) {
			return new WP_Error(
				'fbm_discount_too_large',
				__( 'A discount cannot be larger than the fare.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => array( 'manual_discount' => __( 'That is more than the fare.', 'magepeople-ferry-booking-system' ) ),
				)
			);
		}

		$reason = sanitize_text_field( (string) ( $request['discount_reason'] ?? '' ) );

		$quote->add_amount(
			Quote::LINE_DISCOUNT,
			'' !== $reason ? $reason : __( 'Discount', 'magepeople-ferry-booking-system' ),
			-$amount,
			array(
				'rule'   => 'manual',
				'staff'  => get_current_user_id(),
				'reason' => $reason,
			)
		);

		return true;
	}

	/**
	 * Applies the site-wide limits on how large one booking may be.
	 *
	 * A large party is usually a group that wants an invoice and a coach space,
	 * not a self-service web booking, so an operator is given a ceiling to push
	 * them towards contacting the office. The engine checks it rather than the
	 * booking form, because the form is not the only way in.
	 *
	 * @param array<int, int> $passengers Passenger type id => quantity.
	 * @param array<int, int> $vehicles   Vehicle type id => quantity.
	 * @return true|WP_Error
	 */
	private function check_party_limits( array $passengers, array $vehicles ) {
		$settings = Settings::all();

		if ( array() !== $vehicles && empty( $settings['vehicles_enabled'] ) ) {
			return new WP_Error(
				'fbm_vehicles_disabled',
				__( 'This service does not carry vehicles.', 'magepeople-ferry-booking-system' ),
				array( 'status' => 400 )
			);
		}

		$max_passengers = (int) $settings['max_passengers_per_booking'];
		$seats          = array_sum( $passengers );

		if ( $max_passengers > 0 && $seats > $max_passengers ) {
			return new WP_Error(
				'fbm_party_too_large',
				sprintf(
					/* translators: %d: largest number of passengers allowed in one booking. */
					_n(
						'One booking can carry up to %d passenger. Please contact us to arrange a larger party.',
						'One booking can carry up to %d passengers. Please contact us to arrange a larger party.',
						$max_passengers,
						'magepeople-ferry-booking-system'
					),
					$max_passengers
				),
				array( 'status' => 400 )
			);
		}

		$max_vehicles = (int) $settings['max_vehicles_per_booking'];
		$units        = array_sum( $vehicles );

		if ( $max_vehicles > 0 && $units > $max_vehicles ) {
			return new WP_Error(
				'fbm_too_many_vehicles',
				sprintf(
					/* translators: %d: largest number of vehicles allowed in one booking. */
					_n(
						'One booking can carry up to %d vehicle. Please contact us to arrange more.',
						'One booking can carry up to %d vehicles. Please contact us to arrange more.',
						$max_vehicles,
						'magepeople-ferry-booking-system'
					),
					$max_vehicles
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validates and normalises the submitted party.
	 *
	 * @param mixed $passenger_input Submitted passenger entries.
	 * @param mixed $vehicle_input   Submitted vehicle entries.
	 * @return array<string, array<int, int>>|WP_Error
	 */
	private function party( $passenger_input, $vehicle_input ) {
		// The field-configuration group constants, not the plural names used in
		// the request payload. Passing "vehicles" here meant FieldConfig could
		// not recognise the group and fell back to the passenger field set, so
		// every vehicle was validated against first name and last name.
		$passengers = $this->normalise_party_entries( $passenger_input, FieldConfig::GROUP_PASSENGER );
		$vehicles   = $this->normalise_party_entries( $vehicle_input, FieldConfig::GROUP_VEHICLE );

		if ( is_wp_error( $passengers ) ) {
			return $passengers;
		}

		if ( is_wp_error( $vehicles ) ) {
			return $vehicles;
		}

		return array(
			'passengers' => $passengers,
			'vehicles'   => $vehicles,
		);
	}

	/**
	 * Turns submitted party entries into type id => quantity.
	 *
	 * @param mixed  $entries Submitted entries.
	 * @param string $group   Field group used in error keys.
	 * @return array<int, int>|WP_Error
	 */
	private function normalise_party_entries( $entries, string $group ) {
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$quantities = array();

		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$type_id = (int) ( $entry['type_id'] ?? 0 );
			$details = isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array();

			if ( $type_id < 1 ) {
				continue;
			}

			$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + 1;

			$result = $this->validate_details( $group, $type_id, $details, $index );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $quantities;
	}

	/**
	 * Validates one passenger's or vehicle's captured details.
	 *
	 * @param string               $group   Field group.
	 * @param int                  $type_id Type id.
	 * @param array<string, mixed> $details Submitted details.
	 * @param int                  $index   Position in the party.
	 * @return true|WP_Error
	 */
	private function validate_details( string $group, int $type_id, array $details, int $index ) {
		$force = array();

		if ( FieldConfig::GROUP_PASSENGER === $group ) {
			$type = $this->passenger_types->find( $type_id );

			if ( $type instanceof PassengerType && $type->get( 'requires_dob' ) ) {
				$force['date_of_birth'] = true;
			}
		} else {
			$type = $this->vehicle_types->find( $type_id );

			if ( $type instanceof VehicleType ) {
				if ( $type->get( 'requires_registration' ) ) {
					$force['registration'] = true;
				}

				if ( $type->get( 'requires_driver' ) ) {
					$force['driver_name'] = true;
				}

				if ( $type->get( 'requires_dimensions' ) ) {
					$force['length'] = true;
					$force['height'] = true;
				}
			}
		}

		$result = FieldConfig::validate( $group, $details, $force );

		if ( is_wp_error( $result ) ) {
			// WP_Error::get_error_data() takes an error code, not a key into the
			// data array. Passing "fields" returned nothing, so per-field
			// messages never reached the booking form and the customer saw
			// "correct the highlighted fields" with nothing highlighted.
			$data   = $result->get_error_data();
			$fields = ( is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ) ? $data['fields'] : array();
			$map    = array();

			// The booking form addresses its inputs by the plural name it sent,
			// so the error keys have to match that rather than the group.
			$prefix = FieldConfig::GROUP_VEHICLE === $group ? 'vehicles' : 'passengers';

			foreach ( $fields as $key => $message ) {
				$map[ $prefix . '.' . $index . '.' . $key ] = $message;
			}

			return new WP_Error(
				'fbm_validation_failed',
				__( 'Please correct the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => $map,
				)
			);
		}

		return true;
	}

	/**
	 * Serialises the captured party for storage.
	 *
	 * @param array<int, int>                  $quantities Type id => quantity.
	 * @param array<int, array<string, mixed>> $entries Submitted entries.
	 * @return string[]
	 */
	private function serialize_party( array $quantities, array $entries ): array {
		$rows = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$type_id = (int) ( $entry['type_id'] ?? 0 );

			if ( $type_id < 1 || empty( $quantities[ $type_id ] ) ) {
				continue;
			}

			$details = isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array();

			$rows[] = wp_json_encode(
				array(
					'type_id' => $type_id,
					'details' => array_map( 'sanitize_text_field', $details ),
				)
			);
		}

		return $rows;
	}

	/**
	 * Validates and normalises the customer details.
	 *
	 * @param mixed $input Submitted customer.
	 * @param bool  $staff Whether a member of staff is taking the booking.
	 * @return array<string, mixed>|WP_Error
	 */
	private function customer( $input, bool $staff = false ) {
		$input   = is_array( $input ) ? $input : array();
		$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$phone   = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );
		$errors  = array();
		$user_id = 0;

		if ( '' === $name ) {
			$errors['customer_name'] = __( 'Full name is required.', 'magepeople-ferry-booking-system' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['customer_email'] = __( 'A valid email address is required.', 'magepeople-ferry-booking-system' );
		}

		$settings = Settings::all();

		if ( (bool) $settings['require_phone'] && '' === $phone ) {
			$errors['customer_phone'] = __( 'A phone number is required.', 'magepeople-ferry-booking-system' );
		}

		/*
		 * Both of these exist to govern a customer using the public form. A
		 * member of staff at the counter has the customer in front of them, and
		 * refusing to sell a ticket because the customer has no website account
		 * would be absurd.
		 */
		if ( ! $staff ) {
			if ( ! (bool) $settings['allow_guest_checkout'] && ! is_user_logged_in() ) {
				$errors['customer'] = __( 'Please sign in before booking.', 'magepeople-ferry-booking-system' );
			}

			// Checked here as well as on the form. A tick box the server does
			// not verify is decoration, and the operator turned this on because
			// they need to be able to say the customer agreed.
			$requires_terms = (bool) $settings['require_terms'] && '' !== (string) $settings['terms_url'];

			if ( $requires_terms && ! in_array( $input['accepted_terms'] ?? null, array( true, 1, '1', 'yes', 'true', 'on' ), true ) ) {
				$errors['accepted_terms'] = __( 'Please agree to the terms and conditions.', 'magepeople-ferry-booking-system' );
			}
		}

		if ( array() !== $errors ) {
			return new WP_Error(
				'fbm_validation_failed',
				__( 'Please correct the highlighted fields.', 'magepeople-ferry-booking-system' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
		} elseif ( '' !== $email ) {
			$existing = get_user_by( 'email', $email );

			if ( $existing instanceof WP_User ) {
				$user_id = $existing->ID;
			}
		}

		/**
		 * Filters the customer attached to a booking.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $customer Normalised customer.
		 * @param array<string, mixed> $input    Submitted customer.
		 */
		return (array) apply_filters(
			'fbm_booking_customer',
			array(
				'name'  => $name,
				'email' => $email,
				'phone' => $phone,
				'id'    => $user_id,
			),
			$input
		);
	}

	/**
	 * Generates a unique booking reference.
	 *
	 * @param int $booking_id Booking id.
	 * @return string
	 */
	private function reference( int $booking_id ): string {
		$settings = Settings::all();
		$prefix   = (string) $settings['booking_reference_prefix'];
		$prefix   = '' === $prefix ? 'FBM' : $prefix;

		$base = strtoupper( $prefix ) . '-' . ( $booking_id + 1000 );

		// Collisions are vanishingly unlikely, but a ticket reference that is
		// not unique would be a support problem forever, so they are checked.
		$candidate = $base;
		$suffix    = 0;

		while ( $this->bookings->number_exists( $candidate ) ) {
			++$suffix;
			$candidate = $base . '-' . $suffix;
		}

		return $candidate;
	}

	/**
	 * Returns a route label for a booking's sailing.
	 *
	 * @param int $sailing_id Sailing id.
	 * @return string
	 */
	private function route_label( int $sailing_id ): string {
		$sailing = $this->sailings->find( $sailing_id );

		if ( ! $sailing instanceof Sailing ) {
			return '';
		}

		$route = $this->routes->find( $sailing->route_id() );

		return $route instanceof Route ? $route->name : '';
	}

	/**
	 * Splits a full name into first and last for a WooCommerce order.
	 *
	 * @param string $name Full name.
	 * @return string[] First and last name.
	 */
	private function split_name( string $name ): array {
		$parts = preg_split( '/\s+/', trim( $name ), 2 );

		if ( ! is_array( $parts ) || 1 === count( $parts ) ) {
			return array( $name, '' );
		}

		return array( $parts[0], $parts[1] );
	}
}
