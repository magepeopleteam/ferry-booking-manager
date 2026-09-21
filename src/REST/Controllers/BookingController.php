<?php
/**
 * Public booking endpoints.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\Booking\BookingPresenter;
use MPFBS\Booking\BookingService;
use MPFBS\Booking\PartyPresenter;
use MPFBS\Models\Booking;
use MPFBS\Repositories\BookingRepository;
use MPFBS\REST\AbstractController;
use MPFBS\REST\Response;
use MPFBS\Security\Capabilities;
use MPFBS\Security\Permissions;
use MPFBS\Support\Money;
use MPFBS\Support\Time;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Creates bookings from the public wizard.
 *
 * The endpoint is public because a customer has to be able to book before they
 * have an account; rate limiting and idempotency keys are what stop it being
 * abused, not a login wall. Everything written goes through the booking
 * service, which is the one place a booking can be created.
 */
final class BookingController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'bookings';

	/**
	 * Most of a customer's own bookings the history screen will read.
	 *
	 * Filtering and searching happen over this set, so it is generous rather
	 * than a page size. A customer past it is told their oldest crossings are
	 * not shown, which is better than quietly losing them.
	 */
	private const HISTORY_LIMIT = 200;

	/**
	 * Bookings hydrated per pass while an export is being written.
	 */
	private const EXPORT_CHUNK = 200;

	/**
	 * Most passes an export will make.
	 *
	 * A ceiling rather than a limit anybody should reach: it stops a filter that
	 * matches everything from running until the request times out, and 200,000
	 * bookings is far beyond what a spreadsheet can open anyway.
	 */
	private const EXPORT_MAX_PAGES = 1000;

	/**
	 * Booking service.
	 *
	 * @var BookingService
	 */
	private BookingService $bookings;

	/**
	 * Booking repository, for staff-facing reads.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param Permissions       $permissions Permission service.
	 * @param BookingService    $bookings    Booking service.
	 * @param BookingRepository $repository  Booking repository.
	 * @param BookingPresenter  $presenter   Customer-facing presenter.
	 * @param PartyPresenter    $party       Traveller presenter.
	 */
	public function __construct(
		Permissions $permissions,
		BookingService $bookings,
		BookingRepository $repository,
		BookingPresenter $presenter,
		PartyPresenter $party
	) {
		parent::__construct( $permissions );

		$this->bookings   = $bookings;
		$this->repository = $repository;
		$this->presenter  = $presenter;
		$this->party      = $party;
	}

	/**
	 * Traveller presenter.
	 *
	 * @var PartyPresenter
	 */
	private PartyPresenter $party;

	/**
	 * Customer-facing presenter.
	 *
	 * @var BookingPresenter
	 */
	private BookingPresenter $presenter;

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_BOOKINGS ),
					'args'                => $this->collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => $this->public_handler( 'bookings', 30, array( $this, 'create_booking' ) ),
					'permission_callback' => '__return_true',
					'args'                => $this->create_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description'       => __( 'Booking id.', 'magepeople-ferry-booking-system' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_BOOKINGS ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $this->can( Capabilities::MODIFY_BOOKING ),
					'args'                => $this->update_args(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $this->can( Capabilities::CANCEL_BOOKING ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'export_items' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_BOOKINGS ),
					'args'                => array(
						'search' => array(
							'description'       => __( 'Reference, customer or email to match.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status' => array(
							'description'       => __( 'Status to limit the export to.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/staff',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_staff_booking' ),
					'permission_callback' => $this->can( Capabilities::CREATE_BOOKING ),
					'args'                => $this->create_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/mine',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_my_bookings' ),
					// Any signed-in account may list its own bookings: every
					// role holds `read`, and the handler only ever returns
					// bookings owned by the current user.
					'permission_callback' => $this->can( 'read' ),
					'args'                => array(
						'page'     => array(
							'description'       => __( 'Page of the history.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'description'       => __( 'Bookings shown per page.', 'magepeople-ferry-booking-system' ),
							'type'              => 'integer',
							'default'           => 20,
							'enum'              => self::PER_PAGE_OPTIONS,
							'sanitize_callback' => 'absint',
						),
						'search'   => array(
							'description'       => __( 'Match a reference, route, port or vessel.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'   => array(
							'description'       => __( 'Limit to one booking status.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'when'     => array(
							'description'       => __( 'Upcoming crossings, past ones, or both.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => 'upcoming',
							'enum'              => array( 'upcoming', 'past', 'all' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/lookup',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => $this->public_handler( 'lookup', 20, array( $this, 'lookup_booking' ) ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'reference' => array(
							'description'       => __( 'Booking reference.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'email'     => array(
							'description'       => __( 'Email used for the booking.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);
	}

	/**
	 * Lists bookings for the staff screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->repository->query(
			array(
				'page'             => $this->page( $request ),
				'per_page'         => $this->per_page( $request ),
				'search'           => (string) $request->get_param( 'search' ),
				'status'           => (string) $request->get_param( 'status' ),
				'orderby'          => (string) $request->get_param( 'orderby' ),
				'order'            => (string) $request->get_param( 'order' ),
				'travels_on'       => (int) $request->get_param( 'sailing' ),
				'booking_statuses' => $this->status_filter( $request ),
			)
		);

		$items = array();

		$this->prime_journeys( $result['items'] );

		foreach ( $result['items'] as $booking ) {
			if ( $booking instanceof Booking ) {
				$items[] = $this->staff_row( $booking );
			}
		}

		return $this->respond(
			$items,
			array(
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
				'total'       => $result['total'],
				'total_pages' => (int) ceil( $result['total'] / max( 1, $result['per_page'] ) ),
			)
		);
	}

	/**
	 * Returns one booking for the staff screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response {
		$booking = $this->repository->find( (int) $request->get_param( 'id' ) );

		if ( ! $booking instanceof Booking || ! $this->may_read( $booking ) ) {
			return $this->fail(
				'mpfbs_booking_not_found',
				__( 'That booking could not be found.', 'magepeople-ferry-booking-system' ),
				404
			);
		}

		return $this->respond( $this->staff_record( $booking ) );
	}

	/**
	 * Decides whether the current user may see one booking.
	 *
	 * The capability behind the route says the user may work with bookings; it
	 * does not say they may work with *this* one. Extensions that partition the
	 * bookings between staff members answer that here.
	 *
	 * @param Booking $booking Booking entity.
	 * @return bool
	 */
	private function may_read( Booking $booking ): bool {
		/**
		 * Filters whether the current user may read one booking.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $allowed Whether the read is permitted.
		 * @param Booking $booking Booking entity.
		 */
		return (bool) apply_filters( 'mpfbs_can_view_booking', true, $booking );
	}

	/**
	 * Decides whether the current user may change one booking.
	 *
	 * @param Booking $booking Booking entity.
	 * @return bool
	 */
	private function may_write( Booking $booking ): bool {
		/**
		 * Filters whether the current user may change one booking.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $allowed Whether the change is permitted.
		 * @param Booking $booking Booking entity.
		 */
		return (bool) apply_filters( 'mpfbs_can_edit_booking', true, $booking );
	}

	/**
	 * Refuses a booking the current user may not act on.
	 *
	 * Reported as "not found" rather than "forbidden": telling somebody a
	 * reference exists but is not theirs is itself a disclosure.
	 *
	 * @return WP_REST_Response
	 */
	private function refuse(): WP_REST_Response {
		return $this->fail(
			'mpfbs_booking_not_found',
			__( 'That booking could not be found.', 'magepeople-ferry-booking-system' ),
			404
		);
	}

	/**
	 * Cancels a booking and releases its capacity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response {
		$existing = $this->repository->find( (int) $request->get_param( 'id' ) );

		if ( ! $existing instanceof Booking || ! $this->may_write( $existing ) ) {
			return $this->refuse();
		}

		$result = $this->bookings->cancel( (int) $request->get_param( 'id' ), __( 'Cancelled from the dashboard.', 'magepeople-ferry-booking-system' ) );

		if ( is_wp_error( $result ) ) {
			return Response::from_wp_error( $result );
		}

		if ( null === $result ) {
			return $this->fail(
				'mpfbs_booking_not_found',
				__( 'That booking could not be found.', 'magepeople-ferry-booking-system' ),
				404
			);
		}

		return $this->respond( $this->staff_row( $result ) );
	}

	/**
	 * Sends the current list of bookings as a CSV.
	 *
	 * Exports what is on screen — the same search and status filter — rather
	 * than always the whole book, because an operator reconciling one day's
	 * sailings does not want the other ten thousand rows. It is one row per
	 * booking with the travellers folded into a cell: a spreadsheet is read by
	 * a person, and a person reading a booking list wants one line per booking.
	 *
	 * Money is exported in major units. A spreadsheet is opened by people and
	 * by accounting packages, and neither of them expects a figure in cents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export_items( WP_REST_Request $request ): WP_REST_Response {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream for fputcsv, not a filesystem operation.

		if ( false === $handle ) {
			return $this->fail(
				'mpfbs_export_failed',
				__( 'The export could not be built.', 'magepeople-ferry-booking-system' ),
				500
			);
		}

		// Excel reads a UTF-8 CSV as Latin-1 without a byte-order mark.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to the in-memory stream above.

		fputcsv(
			$handle,
			array(
				__( 'Reference', 'magepeople-ferry-booking-system' ),
				__( 'Booking status', 'magepeople-ferry-booking-system' ),
				__( 'Payment status', 'magepeople-ferry-booking-system' ),
				__( 'Customer', 'magepeople-ferry-booking-system' ),
				__( 'Email', 'magepeople-ferry-booking-system' ),
				__( 'Phone', 'magepeople-ferry-booking-system' ),
				__( 'Journey', 'magepeople-ferry-booking-system' ),
				__( 'From', 'magepeople-ferry-booking-system' ),
				__( 'To', 'magepeople-ferry-booking-system' ),
				__( 'Route', 'magepeople-ferry-booking-system' ),
				__( 'Vessel', 'magepeople-ferry-booking-system' ),
				__( 'Departure', 'magepeople-ferry-booking-system' ),
				__( 'Return', 'magepeople-ferry-booking-system' ),
				__( 'Passengers', 'magepeople-ferry-booking-system' ),
				__( 'Vehicles', 'magepeople-ferry-booking-system' ),
				__( 'Passenger details', 'magepeople-ferry-booking-system' ),
				__( 'Vehicle details', 'magepeople-ferry-booking-system' ),
				__( 'Subtotal', 'magepeople-ferry-booking-system' ),
				__( 'Discount', 'magepeople-ferry-booking-system' ),
				__( 'Tax', 'magepeople-ferry-booking-system' ),
				__( 'Fees', 'magepeople-ferry-booking-system' ),
				__( 'Total', 'magepeople-ferry-booking-system' ),
				__( 'Paid', 'magepeople-ferry-booking-system' ),
				__( 'Balance', 'magepeople-ferry-booking-system' ),
				__( 'Currency', 'magepeople-ferry-booking-system' ),
				__( 'Payment method', 'magepeople-ferry-booking-system' ),
				__( 'Channel', 'magepeople-ferry-booking-system' ),
				__( 'Created', 'magepeople-ferry-booking-system' ),
			)
		);

		/*
		 * Paged rather than fetched in one query: an operator with a season's
		 * bookings would otherwise hydrate every one of them into memory at
		 * once, and the export would fail on exactly the sites that need it
		 * most.
		 */
		$page = 1;

		do {
			$result = $this->repository->query(
				array(
					'page'             => $page,
					'per_page'         => self::EXPORT_CHUNK,
					'search'           => (string) $request->get_param( 'search' ),
					'orderby'          => 'created',
					'order'            => 'desc',
					'booking_statuses' => $this->status_filter( $request ),
				)
			);

			$this->prime_journeys( $result['items'] );

			foreach ( $result['items'] as $booking ) {
				if ( $booking instanceof Booking ) {
					fputcsv( $handle, $this->export_row( $booking ) );
				}
			}

			$written = count( $result['items'] );
			++$page;
		} while ( $written >= self::EXPORT_CHUNK && $page <= self::EXPORT_MAX_PAGES );

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the in-memory stream above.

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $csv ) );
		header(
			sprintf(
				'Content-Disposition: attachment; filename="%s"',
				sanitize_file_name( sprintf( 'ferry-bookings-%s.csv', gmdate( 'Y-m-d' ) ) )
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV body; escaping would quote-mangle every cell.
		echo $csv;
		exit;
	}

	/**
	 * Builds one row of the bookings export.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array<int, string>
	 */
	private function export_row( Booking $booking ): array {
		$legs  = $this->presenter->legs( $booking );
		$party = $this->party->describe( $booking );
		$out   = $legs[0] ?? array();
		$back  = $legs[1] ?? array();

		return array(
			$booking->number(),
			(string) $booking->get( 'booking_status' ),
			(string) $booking->get( 'payment_status' ),
			(string) $booking->get( 'customer_name' ),
			(string) $booking->get( 'customer_email' ),
			(string) $booking->get( 'customer_phone' ),
			'return' === (string) $booking->get( 'booking_type' )
				? __( 'Return', 'magepeople-ferry-booking-system' )
				: __( 'One way', 'magepeople-ferry-booking-system' ),
			(string) ( $out['origin'] ?? '' ),
			(string) ( $out['destination'] ?? '' ),
			(string) ( $out['route'] ?? '' ),
			(string) ( $out['vessel'] ?? '' ),
			(string) ( $out['departure'] ?? '' ),
			(string) ( $back['departure'] ?? '' ),
			(string) (int) $booking->get( 'passenger_count' ),
			(string) (int) $booking->get( 'vehicle_count' ),
			$this->export_party( $party['passengers'] ),
			$this->export_party( $party['vehicles'] ),
			Money::to_major( (int) $booking->get( 'subtotal' ) ),
			Money::to_major( (int) $booking->get( 'discount' ) ),
			Money::to_major( (int) $booking->get( 'tax' ) ),
			Money::to_major( (int) $booking->get( 'fees' ) ),
			Money::to_major( (int) $booking->get( 'total' ) ),
			Money::to_major( (int) $booking->get( 'paid' ) ),
			Money::to_major( $booking->balance() ),
			(string) $booking->get( 'currency' ),
			(string) $booking->get( 'payment_method' ),
			(string) $booking->get( 'channel' ),
			(string) get_post_time( 'Y-m-d H:i:s', false, $booking->id ),
		);
	}

	/**
	 * Folds one half of a party into a single spreadsheet cell.
	 *
	 * Travellers are separated by a line break rather than a comma: a cell can
	 * hold several lines and stay readable, and a comma inside a value is one
	 * more thing for a spreadsheet to get wrong.
	 *
	 * @param array<int, array<string, mixed>> $travellers Described travellers.
	 * @return string
	 */
	private function export_party( array $travellers ): string {
		$lines = array();

		foreach ( $travellers as $index => $traveller ) {
			$details = array();

			foreach ( (array) ( $traveller['details'] ?? array() ) as $detail ) {
				$details[] = sprintf( '%s: %s', (string) $detail['label'], (string) $detail['value'] );
			}

			$lines[] = trim(
				sprintf(
					'%d. %s %s',
					$index + 1,
					(string) ( $traveller['type_name'] ?? '' ),
					array() === $details ? '' : '— ' . implode( '; ', $details )
				)
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Warms the journey caches for a page of bookings.
	 *
	 * Every row names its two ports, which costs a sailing, a route and two
	 * ports to work out. One at a time that is a query each; asked for together
	 * it is one query per layer. On a page of twenty crossings that share no
	 * sailing the difference measured seventy queries against ten.
	 *
	 * @param array<int, mixed> $bookings Bookings about to be rendered.
	 * @return void
	 */
	private function prime_journeys( array $bookings ): void {
		$sailings = array();

		foreach ( $bookings as $booking ) {
			if ( $booking instanceof Booking ) {
				$sailings[] = (int) $booking->get( 'sailing_id' );
				$sailings[] = (int) $booking->get( 'return_sailing_id' );
			}
		}

		$this->presenter->prime( $sailings );
	}

	/**
	 * Builds the staff-facing representation of a booking.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array<string, mixed>
	 */
	private function staff_row( Booking $booking ): array {
		$row = $booking->to_array();

		$row['name']      = (string) $booking->get( 'customer_name' );
		$row['number']    = $booking->number();
		$row['departure'] = Time::display( $this->departure_local( $booking ), true );
		$row['balance']   = $booking->balance();

		/*
		 * The list needs to answer "where is this one going" without a second
		 * request per row. Only the two port names travel with the row; the
		 * vessel, the times and the return leg are the drawer's business.
		 */
		$legs               = $this->presenter->legs( $booking );
		$row['origin']      = isset( $legs[0]['origin'] ) ? (string) $legs[0]['origin'] : '';
		$row['destination'] = isset( $legs[0]['destination'] ) ? (string) $legs[0]['destination'] : '';
		$row['route_name']  = isset( $legs[0]['route'] ) ? (string) $legs[0]['route'] : '';

		// The stored travellers are JSON blobs, useless to a list and heavy to
		// send. The single-booking route resolves them properly instead.
		unset( $row['passengers'], $row['vehicles'] );

		return $row;
	}

	/**
	 * Builds the full staff-facing record of one booking.
	 *
	 * The list row plus everything a member of staff opens a booking to see:
	 * both legs of the journey resolved to ports and vessels, and every
	 * traveller with the detail fields the operator asked them for. It costs
	 * several more reads than the list row, which is why only the
	 * single-booking route pays for it.
	 *
	 * @param Booking $booking Booking entity.
	 * @return array<string, mixed>
	 */
	private function staff_record( Booking $booking ): array {
		$record = $this->staff_row( $booking );
		$party  = $this->party->describe( $booking );

		$record['legs']       = $this->presenter->legs( $booking );
		$record['passengers'] = $party['passengers'];
		$record['vehicles']   = $party['vehicles'];

		return $record;
	}

	/**
	 * Returns the outbound departure in local time.
	 *
	 * @param Booking $booking Booking entity.
	 * @return string
	 */
	private function departure_local( Booking $booking ): string {
		$ts = (int) $booking->get( 'departure_ts' );

		return $ts > 0 ? Time::timestamp_to_local( $ts ) : '';
	}

	/**
	 * Maps the status filter parameter to booking statuses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string[]
	 */
	private function status_filter( WP_REST_Request $request ): array {
		$status = (string) $request->get_param( 'status' );

		if ( '' === $status ) {
			return array();
		}

		$map = array(
			'pending'   => array( Booking::STATUS_PENDING ),
			'on_hold'   => array( Booking::STATUS_ON_HOLD ),
			'confirmed' => array( Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED ),
			'cancelled' => array( Booking::STATUS_CANCELLED, Booking::STATUS_FAILED, Booking::STATUS_REFUNDED ),
		);

		return $map[ $status ] ?? array( sanitize_key( $status ) );
	}

	/**
	 * Creates a booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_booking( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$result = $this->bookings->create( $payload );

		if ( is_wp_error( $result ) ) {
			return Response::from_wp_error( $result );
		}

		return $this->respond( $result, array(), 201 );
	}

	/**
	 * Lets a guest retrieve their own booking with a reference and email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function lookup_booking( WP_REST_Request $request ): WP_REST_Response {
		$reference = (string) $request->get_param( 'reference' );
		$email     = (string) $request->get_param( 'email' );

		$booking = $this->repository->find_by_number( $reference );

		/*
		 * One message for "no such reference" and for "that is not the email on
		 * it". Telling the two apart would turn this endpoint into a way to
		 * confirm which references exist, and the rate limit on the route only
		 * slows that down rather than preventing it.
		 */
		if ( null === $booking || 0 !== strcasecmp( (string) $booking->get( 'customer_email' ), $email ) ) {
			return $this->fail(
				'mpfbs_booking_not_found',
				__( 'No booking matches that reference and email address.', 'magepeople-ferry-booking-system' ),
				404
			);
		}

		return $this->respond( $this->presenter->present( $booking ) );
	}

	/**
	 * Creates a booking on behalf of a customer, taken by staff.
	 *
	 * A separate route from the public one so the two carry different
	 * permissions and neither can be mistaken for the other: this needs the
	 * create-booking capability and grants authority the public form has not.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_staff_booking( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$result = $this->bookings->create_for_staff( $payload );

		if ( is_wp_error( $result ) ) {
			return Response::from_wp_error( $result );
		}

		return $this->respond( $result, array(), 201 );
	}

	/**
	 * Returns the signed-in customer's own bookings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_bookings( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();

		if ( ! $user instanceof \WP_User || 0 === (int) $user->ID ) {
			return $this->fail(
				'mpfbs_not_signed_in',
				__( 'Please sign in to see your bookings.', 'magepeople-ferry-booking-system' ),
				401
			);
		}

		$bookings = $this->repository->for_customer( (int) $user->ID, (string) $user->user_email, self::HISTORY_LIMIT );

		$now      = time();
		$rows     = array();
		$upcoming = 0;
		$past     = 0;

		/*
		 * Collected across the whole history, not the page on screen. Options
		 * derived from the current page appear and disappear as the customer
		 * pages through, which makes the filter look broken.
		 */
		$statuses = array();

		foreach ( $bookings as $booking ) {
			$row = $this->presenter->present( $booking );

			$row['upcoming'] = (int) $booking->get( 'departure_ts' ) >= $now;

			if ( $row['upcoming'] ) {
				++$upcoming;
			} else {
				++$past;
			}

			$statuses[ (string) $row['status'] ] = (string) $row['status_label'];

			$rows[] = $row;
		}

		ksort( $statuses );

		$when = (string) $request->get_param( 'when' );

		if ( in_array( $when, array( 'upcoming', 'past' ), true ) ) {
			$wanted = 'upcoming' === $when;

			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $wanted ): bool {
						return (bool) $row['upcoming'] === $wanted;
					}
				)
			);
		}

		$status = (string) $request->get_param( 'status' );

		if ( '' !== $status ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $status ): bool {
						return (string) ( $row['status'] ?? '' ) === $status;
					}
				)
			);
		}

		$search = trim( (string) $request->get_param( 'search' ) );

		if ( '' !== $search ) {
			$rows = array_values( array_filter( $rows, $this->history_matcher( $search ) ) );
		}

		/*
		 * The next crossing first when looking forward, the most recent first
		 * when looking back. Both answer "what is this page for": an upcoming
		 * list is a reminder, a past list is a receipt.
		 */
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( (bool) $a['upcoming'] !== (bool) $b['upcoming'] ) {
					return $a['upcoming'] ? -1 : 1;
				}

				$left  = (int) ( $a['departure_ts'] ?? 0 );
				$right = (int) ( $b['departure_ts'] ?? 0 );

				return $a['upcoming'] ? $left <=> $right : $right <=> $left;
			}
		);

		$total    = count( $rows );
		$per_page = $this->per_page( $request );
		$pages    = (int) ceil( $total / max( 1, $per_page ) );
		$page     = min( max( 1, $this->page( $request ) ), max( 1, $pages ) );

		return $this->respond(
			array(
				'items'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
				'counts'   => array(
					'all'      => $upcoming + $past,
					'upcoming' => $upcoming,
					'past'     => $past,
				),
				'statuses' => $statuses,
				// Says so rather than pretending the oldest bookings are gone.
				'capped'   => count( $bookings ) >= self::HISTORY_LIMIT,
			),
			array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $pages,
			)
		);
	}

	/**
	 * Builds a matcher for the history search box.
	 *
	 * Searches what is on the card — the reference, the name it was booked in,
	 * and the route, ports and vessel of every leg — because those are the words
	 * a customer has in front of them when they go looking.
	 *
	 * @param string $search Raw search text.
	 * @return callable(array<string, mixed>): bool
	 */
	private function history_matcher( string $search ): callable {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

		return static function ( array $row ) use ( $needle ): bool {
			$haystack = array(
				(string) ( $row['reference'] ?? '' ),
				(string) ( $row['customer_name'] ?? '' ),
				(string) ( $row['status_label'] ?? '' ),
			);

			foreach ( (array) ( $row['legs'] ?? array() ) as $leg ) {
				if ( ! is_array( $leg ) ) {
					continue;
				}

				foreach ( array( 'route', 'origin', 'destination', 'vessel' ) as $key ) {
					$haystack[] = (string) ( $leg[ $key ] ?? '' );
				}
			}

			$text = implode( ' ', $haystack );
			$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

			return false !== strpos( $text, $needle );
		};
	}

	/**
	 * Applies staff edits to a booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$existing = $this->repository->find( (int) $request->get_param( 'id' ) );

		if ( ! $existing instanceof Booking || ! $this->may_write( $existing ) ) {
			return $this->refuse();
		}

		$updated = $this->bookings->update( (int) $request->get_param( 'id' ), $payload );

		if ( is_wp_error( $updated ) ) {
			return Response::from_wp_error( $updated );
		}

		return $this->respond( $this->staff_row( $updated ) );
	}

	/**
	 * Returns the update endpoint arguments.
	 *
	 * Nothing is required: an edit form sends only what changed, and the
	 * service treats an absent key as "leave it alone".
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function update_args(): array {
		$args = array();

		foreach ( array( 'customer_name', 'customer_email', 'customer_phone', 'booking_status', 'payment_status', 'payment_method', 'internal_notes' ) as $key ) {
			$args[ $key ] = array(
				'description' => __( 'Editable booking field.', 'magepeople-ferry-booking-system' ),
				'type'        => 'string',
			);
		}

		return $args;
	}

	/**
	 * Returns the create endpoint's argument definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function create_args(): array {
		return array(
			'sailing_id'        => array(
				'description'       => __( 'Outbound sailing id.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'return_sailing_id' => array(
				'description'       => __( 'Return sailing id.', 'magepeople-ferry-booking-system' ),
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'idempotency_key'   => array(
				'description'       => __( 'Client-generated key that makes a retried request safe.', 'magepeople-ferry-booking-system' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'payment_method'    => array(
				'description'       => __( 'Native checkout payment method.', 'magepeople-ferry-booking-system' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
