<?php
/**
 * Email notifications.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Notification;

use FBM\Models\Booking;
use FBM\Repositories\RouteRepository;
use FBM\Repositories\SailingRepository;
use FBM\Repositories\VesselRepository;
use FBM\Settings\Settings;
use FBM\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the Free plugin's basic booking emails.
 *
 * Every message is composed from one template with a small set of variables, so
 * the confirmation, the cancellation and the admin notice cannot drift apart in
 * what they say about a booking. Pro replaces this service's templates with its
 * email builder; the send points here are the stable contract it plugs into.
 */
final class NotificationService {

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
	 * Constructor.
	 *
	 * @param SailingRepository $sailings Sailing repository.
	 * @param RouteRepository   $routes   Route repository.
	 * @param VesselRepository  $vessels  Vessel repository.
	 */
	public function __construct( SailingRepository $sailings, RouteRepository $routes, VesselRepository $vessels ) {
		$this->sailings = $sailings;
		$this->routes   = $routes;
		$this->vessels  = $vessels;
	}

	/**
	 * Sends the "booking received" email when a booking is created.
	 *
	 * @param Booking $booking Booking entity.
	 * @return void
	 */
	public function send_booking_received( Booking $booking ): void {
		$settings = Settings::all();

		if ( ! (bool) $settings['send_booking_received'] ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: company name, 2: booking reference. */
			__( '%1$s — we have received your booking %2$s', 'ferry-booking-manager' ),
			(string) $settings['company_name'],
			$booking->number()
		);

		$this->send(
			$booking,
			$subject,
			$this->body(
				$booking,
				array(
					'heading' => __( 'Your booking has been received', 'ferry-booking-manager' ),
					'lede'    => __( 'Thank you for booking with us. Your booking is being processed and your seats are held.', 'ferry-booking-manager' ),
				)
			),
			'booking_confirmation'
		);
	}

	/**
	 * Sends the "booking confirmed" email.
	 *
	 * @param Booking $booking Booking entity.
	 * @return void
	 */
	public function send_booking_confirmed( Booking $booking ): void {
		$settings = Settings::all();

		if ( ! (bool) $settings['send_booking_confirmed'] ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: company name, 2: booking reference. */
			__( '%1$s — booking %2$s confirmed', 'ferry-booking-manager' ),
			(string) $settings['company_name'],
			$booking->number()
		);

		$this->send(
			$booking,
			$subject,
			$this->body(
				$booking,
				array(
					'heading' => __( 'Your booking is confirmed', 'ferry-booking-manager' ),
					'lede'    => __( 'Payment has been received and your crossing is confirmed. Please bring your booking reference to check-in.', 'ferry-booking-manager' ),
				)
			),
			'payment_confirmation'
		);
	}

	/**
	 * Sends the cancellation notice.
	 *
	 * @param Booking $booking Booking entity.
	 * @param string  $reason  Optional reason.
	 * @return void
	 */
	public function send_booking_cancelled( Booking $booking, string $reason = '' ): void {
		$settings = Settings::all();

		if ( ! (bool) $settings['send_booking_cancelled'] ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: company name, 2: booking reference. */
			__( '%1$s — booking %2$s cancelled', 'ferry-booking-manager' ),
			(string) $settings['company_name'],
			$booking->number()
		);

		$this->send(
			$booking,
			$subject,
			$this->body(
				$booking,
				array(
					'heading' => __( 'Your booking has been cancelled', 'ferry-booking-manager' ),
					'lede'    => '' !== $reason
						? sprintf(
							/* translators: %s: cancellation reason. */
							__( 'Your booking was cancelled: %s', 'ferry-booking-manager' ),
							$reason
						)
						: __( 'Your booking has been cancelled.', 'ferry-booking-manager' ),
				)
			),
			'cancellation',
			array( 'change_reason' => $reason )
		);
	}

	/**
	 * Notifies the operator that a new booking arrived.
	 *
	 * @param Booking $booking Booking entity.
	 * @return void
	 */
	public function send_admin_new_booking( Booking $booking ): void {
		$settings = Settings::all();

		if ( ! (bool) $settings['notify_admin_on_booking'] ) {
			return;
		}

		$to = (string) $settings['admin_notification_email'];

		if ( '' === $to ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: booking reference. */
			__( '[Ferry] New booking %s', 'ferry-booking-manager' ),
			$booking->number()
		);

		$body = $this->body(
			$booking,
			array(
				'heading' => __( 'New booking received', 'ferry-booking-manager' ),
				'lede'    => sprintf(
					/* translators: 1: customer name, 2: booking reference. */
					__( '%1$s booked reference %2$s through the website.', 'ferry-booking-manager' ),
					(string) $booking->get( 'customer_name' ),
					$booking->number()
				),
			)
		);

		$this->dispatch( $to, $subject, $body, $booking, 'admin_new_booking' );
	}

	/**
	 * Composes the plain-text body of a booking email.
	 *
	 * @param Booking               $booking Booking entity.
	 * @param array<string, string> $context Heading and lede.
	 * @return string
	 */
	private function body( Booking $booking, array $context ): string {
		$settings = Settings::all();

		$lines = array(
			strtoupper( (string) $context['heading'] ),
			'',
			(string) $context['lede'],
			'',
			sprintf(
				/* translators: %s: booking reference. */
				__( 'Booking reference: %s', 'ferry-booking-manager' ),
				$booking->number()
			),
		);

		$crossing = $this->crossing_lines( $booking );

		if ( array() !== $crossing ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $crossing );
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: formatted total. */
			__( 'Total: %s', 'ferry-booking-manager' ),
			$this->money( (int) $booking->get( 'total' ), (string) $booking->get( 'currency' ) )
		);

		$passengers = (int) $booking->get( 'passenger_count' );

		if ( $passengers > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of passengers. */
				_n( 'Passengers: %d', 'Passengers: %d', $passengers, 'ferry-booking-manager' ),
				$passengers
			);
		}

		$vehicles = (int) $booking->get( 'vehicle_count' );

		if ( $vehicles > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of vehicles. */
				_n( 'Vehicles: %d', 'Vehicles: %d', $vehicles, 'ferry-booking-manager' ),
				$vehicles
			);
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: company name. */
			__( 'Thank you for travelling with %s.', 'ferry-booking-manager' ),
			(string) $settings['company_name']
		);

		if ( '' !== (string) $settings['support_phone'] ) {
			$lines[] = sprintf(
				/* translators: %s: support phone number. */
				__( 'Questions? Call %s.', 'ferry-booking-manager' ),
				(string) $settings['support_phone']
			);
		}

		/**
		 * Filters the plain-text body of a booking email.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $body    Email body.
		 * @param Booking $booking Booking entity.
		 * @param array<string, string> $context Heading and lede.
		 */
		return (string) apply_filters( 'fbm_email_body', implode( "\n", $lines ), $booking, $context );
	}

	/**
	 * Builds the crossing description lines.
	 *
	 * @param Booking $booking Booking entity.
	 * @return string[]
	 */
	private function crossing_lines( Booking $booking ): array {
		$lines = array();

		foreach ( array(
			__( 'Outbound', 'ferry-booking-manager' ) => (int) $booking->get( 'sailing_id' ),
			__( 'Return', 'ferry-booking-manager' )   => (int) $booking->get( 'return_sailing_id' ),
		) as $label => $sailing_id ) {
			if ( $sailing_id < 1 ) {
				continue;
			}

			$sailing = $this->sailings->find( $sailing_id );

			if ( null === $sailing ) {
				continue;
			}

			$route  = $this->routes->find( $sailing->route_id() );
			$vessel = $this->vessels->find( $sailing->vessel_id() );

			$when = Time::display( (string) $sailing->get( 'departure_datetime' ) );

			$line = $label . ': ';

			$line .= $route instanceof \FBM\Models\Route ? $route->name : $sailing->name;

			if ( '' !== $when ) {
				$line .= ' — ' . $when;
			}

			if ( $vessel instanceof \FBM\Models\Vessel ) {
				$line .= ' — ' . $vessel->name;
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Sends a booking email to its customer.
	 *
	 * @param Booking              $booking  Booking entity.
	 * @param string               $subject  Subject.
	 * @param string               $body     Plain-text body.
	 * @param string               $template Template key naming the message.
	 * @param array<string, mixed> $context  Extra values describing this send.
	 * @return void
	 */
	private function send( Booking $booking, string $subject, string $body, string $template = '', array $context = array() ): void {
		$to = (string) $booking->get( 'customer_email' );

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$this->dispatch( $to, $subject, $body, $booking, $template, $context );
	}

	/**
	 * Dispatches one email through the configured from address.
	 *
	 * @param string               $to       Recipient.
	 * @param string               $subject  Subject.
	 * @param string               $body     Plain-text body.
	 * @param Booking              $booking  Booking entity.
	 * @param string               $template Template key naming the message.
	 * @param array<string, mixed> $context  Extra values describing this send.
	 * @return void
	 */
	private function dispatch( string $to, string $subject, string $body, Booking $booking, string $template = '', array $context = array() ): void {
		$settings = Settings::all();

		$headers = array();

		$from_name  = (string) $settings['email_from_name'];
		$from_email = (string) $settings['email_from_address'];

		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = '' !== $from_name
				? sprintf( 'From: %1$s <%2$s>', $from_name, $from_email )
				: sprintf( 'From: %s', $from_email );
		}

		/**
		 * Filters an outgoing booking email in full, immediately before sending.
		 *
		 * The whole message is offered rather than the body alone, because a
		 * replacement that cannot change the subject or set a content type can
		 * only ever be a different flavour of plain text. `template` names the
		 * message so a replacement does not have to recognise it by its
		 * translated heading.
		 *
		 * Returning an empty `to` cancels the send.
		 *
		 * @since 1.0.0
		 *
		 * @param array{to: string, subject: string, body: string, headers: string[], template: string, context: array<string, mixed>} $message Message about to be sent.
		 * @param Booking                                                                                                             $booking Booking entity.
		 */
		$message = (array) apply_filters(
			'fbm_email_message',
			array(
				'to'       => $to,
				'subject'  => $subject,
				'body'     => $body,
				'headers'  => $headers,
				'template' => $template,
				'context'  => $context,
			),
			$booking
		);

		$to      = isset( $message['to'] ) ? (string) $message['to'] : '';
		$subject = isset( $message['subject'] ) ? (string) $message['subject'] : $subject;
		$body    = isset( $message['body'] ) ? (string) $message['body'] : $body;
		$headers = isset( $message['headers'] ) && is_array( $message['headers'] )
			? array_values( array_map( 'strval', $message['headers'] ) )
			: $headers;

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		/**
		 * Fires after a Ferry Booking Manager email has been attempted.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $sent    Whether wp_mail accepted the message.
		 * @param string  $to      Recipient.
		 * @param string  $subject Subject.
		 * @param string  $body    Body.
		 * @param Booking $booking Booking entity.
		 */
		do_action( 'fbm_email_sent', $sent, $to, $subject, $body, $booking );
	}

	/**
	 * Formats an amount in minor units.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function money( int $amount, string $currency ): string {
		$major = number_format_i18n( $amount / 100, 2 );

		return '' === $currency ? $major : $major . ' ' . $currency;
	}
}
