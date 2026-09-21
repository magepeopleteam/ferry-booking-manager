<?php
/**
 * Customer lookup for staff booking.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\REST\Controllers;

use MPFBS\REST\AbstractController;
use MPFBS\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and creates the customer records a counter booking is made against.
 *
 * A customer is a WordPress user, not a record of its own — the plugin creates
 * no tables, and a customer who books online and one who walks up to the desk
 * should be the same person in the system rather than two.
 *
 * Every route needs the create-booking capability. This returns email addresses
 * and names, so it must never be reachable by someone who cannot already see
 * that information on a booking.
 */
final class CustomerController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'customers';

	/**
	 * Largest number of matches returned for one search.
	 */
	private const MAX_RESULTS = 20;

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
					'callback'            => array( $this, 'search' ),
					'permission_callback' => $this->can( Capabilities::CREATE_BOOKING ),
					'args'                => array(
						'search' => array(
							'description'       => __( 'Name or email to search for.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::CREATE_BOOKING ),
					'args'                => array(
						'name'  => array(
							'description'       => __( 'Customer name.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'email' => array(
							'description'       => __( 'Customer email address.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_email',
						),
						'phone' => array(
							'description'       => __( 'Customer phone number.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Finds customers matching a name or email fragment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$term = trim( (string) $request->get_param( 'search' ) );

		// A blank search would return the first twenty accounts on the site,
		// which is a customer list nobody asked for.
		if ( strlen( $term ) < 2 ) {
			return $this->respond( array( 'customers' => array() ) );
		}

		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => self::MAX_RESULTS,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);

		$customers = array();

		foreach ( $users as $user ) {
			if ( $user instanceof WP_User ) {
				$customers[] = $this->present( $user );
			}
		}

		return $this->respond( array( 'customers' => $customers ) );
	}

	/**
	 * Creates a customer account for a counter booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response {
		$name  = (string) $request->get_param( 'name' );
		$email = (string) $request->get_param( 'email' );
		$phone = (string) $request->get_param( 'phone' );

		if ( ! is_email( $email ) ) {
			return $this->fail(
				'mpfbs_invalid_email',
				__( 'That is not a valid email address.', 'magepeople-ferry-booking-system' ),
				400,
				array( 'fields' => array( 'email' => __( 'Enter a valid email address.', 'magepeople-ferry-booking-system' ) ) )
			);
		}

		$existing = get_user_by( 'email', $email );

		// Not an error: the member of staff is trying to reach a customer, and
		// this is that customer. Handing back the existing account is what they
		// wanted, and refusing would only teach them to invent a second email.
		if ( $existing instanceof WP_User ) {
			return $this->respond(
				array(
					'customer' => $this->present( $existing ),
					'existing' => true,
				)
			);
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $this->unique_login( $email ),
				'user_email'   => $email,
				'display_name' => '' !== $name ? $name : $email,
				'first_name'   => $this->name_part( $name, 0 ),
				'last_name'    => $this->name_part( $name, 1 ),
				// A password nobody knows, including us. The customer sets
				// their own through the normal reset flow if they ever want an
				// account; until then this is a record, not a login.
				'user_pass'    => wp_generate_password( 24, true, true ),
				'role'         => 'customer' === get_option( 'default_role' ) ? 'customer' : 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $this->fail(
				'mpfbs_customer_not_created',
				$user_id->get_error_message(),
				400
			);
		}

		if ( '' !== $phone ) {
			update_user_meta( (int) $user_id, 'mpfbs_phone', $phone );

			// Shared with WooCommerce so its checkout is pre-filled too.
			if ( class_exists( 'WooCommerce' ) ) {
				update_user_meta( (int) $user_id, 'billing_phone', $phone );
			}
		}

		$user = get_user_by( 'id', (int) $user_id );

		return $this->respond(
			array(
				'customer' => $user instanceof WP_User ? $this->present( $user ) : array(),
				'existing' => false,
			),
			array(),
			201
		);
	}

	/**
	 * Describes a customer for the booking form.
	 *
	 * @param WP_User $user User.
	 * @return array<string, mixed>
	 */
	private function present( WP_User $user ): array {
		return array(
			'id'    => (int) $user->ID,
			'name'  => (string) $user->display_name,
			'email' => (string) $user->user_email,
			'phone' => $this->phone( $user ),
		);
	}

	/**
	 * Returns a customer's phone number.
	 *
	 * Falls back to the WooCommerce billing phone for customers who were
	 * created by WooCommerce rather than by this plugin.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private function phone( WP_User $user ): string {
		$phone = (string) get_user_meta( $user->ID, 'mpfbs_phone', true );

		if ( '' === $phone ) {
			$phone = (string) get_user_meta( $user->ID, 'billing_phone', true );
		}

		return $phone;
	}

	/**
	 * Builds a login name that is not already taken.
	 *
	 * @param string $email Customer email address.
	 * @return string
	 */
	private function unique_login( string $email ): string {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );

		if ( '' === $base ) {
			$base = 'customer';
		}

		$login = $base;
		$index = 1;

		while ( username_exists( $login ) ) {
			++$index;
			$login = $base . $index;
		}

		return $login;
	}

	/**
	 * Splits a full name into its first and last parts.
	 *
	 * @param string $name  Full name.
	 * @param int    $index 0 for the first name, 1 for the rest.
	 * @return string
	 */
	private function name_part( string $name, int $index ): string {
		$name = trim( $name );

		if ( '' === $name ) {
			return '';
		}

		$position = strpos( $name, ' ' );

		if ( false === $position ) {
			return 0 === $index ? $name : '';
		}

		return 0 === $index ? substr( $name, 0, $position ) : trim( substr( $name, $position + 1 ) );
	}
}
