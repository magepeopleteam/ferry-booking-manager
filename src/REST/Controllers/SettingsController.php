<?php
/**
 * Settings endpoints.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\Booking\FieldConfig;
use FBM\Frontend\Pages;
use FBM\Payment\PaymentGatewayRegistry;
use FBM\Pricing\PricingSettings;
use FBM\REST\AbstractController;
use FBM\Security\Capabilities;
use FBM\Security\Permissions;
use FBM\Security\RoleSettings;
use FBM\Settings\Settings;
use FBM\Settings\SettingsPanels;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and stores the Free plugin's settings.
 *
 * One endpoint backs the whole Settings screen. The dashboard reads the full
 * resolved set once and writes it back as one object, which keeps the screen
 * simple and the store's whitelist the single source of truth for what may be
 * configured.
 */
final class SettingsController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'settings';

	/**
	 * Payment gateway registry.
	 *
	 * @var PaymentGatewayRegistry
	 */
	private PaymentGatewayRegistry $gateways;

	/**
	 * Managed front-end pages.
	 *
	 * @var Pages|null
	 */
	private ?Pages $pages;

	/**
	 * Constructor.
	 *
	 * @param Permissions            $permissions Permission service.
	 * @param PaymentGatewayRegistry $gateways    Payment gateway registry.
	 * @param Pages|null             $pages       Managed pages.
	 */
	public function __construct( Permissions $permissions, PaymentGatewayRegistry $gateways, ?Pages $pages = null ) {
		parent::__construct( $permissions );

		$this->gateways = $gateways;
		$this->pages    = $pages;
	}

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
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => 'PUT, PATCH, POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/test-email',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'send_test_email' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
					'args'                => array(
						'to' => array(
							'description'       => __( 'Address to send the test to.', 'magepeople-ferry-booking-system' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payment-methods',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_payment_methods' ),
					'permission_callback' => $this->permissions->rest_public_callback( 'payment-methods', 60 ),
				),
			)
		);
	}

	/**
	 * Returns the resolved settings plus the gateway states.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$settings = Settings::all();

		$gateway_states = array();

		foreach ( $this->gateways->all() as $id => $gateway ) {
			$gateway_states[ $id ] = array(
				'label'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
				'enabled'     => $this->gateways->is_enabled( $id ),
			);
		}

		return $this->respond(
			array(
				'settings'    => $settings,
				'pricing'     => PricingSettings::all(),
				'panels'      => SettingsPanels::describe(),
				'gateways'    => $gateway_states,
				'pages'       => $this->pages_status(),
				'roles'       => RoleSettings::matrix(),
				'fieldConfig' => array(
					'passenger' => FieldConfig::form( FieldConfig::GROUP_PASSENGER ),
					'vehicle'   => FieldConfig::form( FieldConfig::GROUP_VEHICLE ),
				),
			)
		);
	}

	/**
	 * Sends a test email so an operator can prove delivery works.
	 *
	 * Sites lose booking confirmations to spam filters and misconfigured SMTP
	 * far more often than to bugs in the plugin, and the operator has no way to
	 * tell the difference. This makes the difference visible before a customer
	 * is the one who finds out.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function send_test_email( WP_REST_Request $request ): WP_REST_Response {
		$settings = Settings::all();
		$to       = (string) $request->get_param( 'to' );

		if ( '' === $to ) {
			$to = (string) $settings['admin_notification_email'];
		}

		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}

		if ( ! is_email( $to ) ) {
			return $this->fail(
				'fbm_invalid_email',
				__( 'That is not a valid email address.', 'magepeople-ferry-booking-system' ),
				400,
				array( 'fields' => array( 'to' => __( 'Enter a valid email address.', 'magepeople-ferry-booking-system' ) ) )
			);
		}

		$from_name    = '' !== (string) $settings['email_from_name'] ? (string) $settings['email_from_name'] : (string) get_bloginfo( 'name' );
		$from_address = '' !== (string) $settings['email_from_address'] ? (string) $settings['email_from_address'] : (string) get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %1$s <%2$s>', $from_name, $from_address ),
		);

		$body = sprintf(
			'<p>%1$s</p><p>%2$s</p>',
			esc_html__( 'This is a test message from MagePeople Ferry Booking System. If you are reading it, booking emails can reach your customers.', 'magepeople-ferry-booking-system' ),
			esc_html(
				sprintf(
					/* translators: 1: sender name, 2: sender address. */
					__( 'Sent as %1$s <%2$s>.', 'magepeople-ferry-booking-system' ),
					$from_name,
					$from_address
				)
			)
		);

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] MagePeople Ferry Booking System test email', 'magepeople-ferry-booking-system' ),
				(string) get_bloginfo( 'name' )
			),
			$body,
			$headers
		);

		if ( ! $sent ) {
			return $this->fail(
				'fbm_mail_failed',
				__( 'WordPress could not send the message. Check your SMTP settings or mail plugin.', 'magepeople-ferry-booking-system' ),
				500
			);
		}

		return $this->respond(
			array(
				'sent' => true,
				'to'   => $to,
				'from' => $from_address,
			)
		);
	}

	/**
	 * Stores the settings and gateway states.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		Settings::save( isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array() );

		// Tax lives in the pricing option, which predates this screen and is
		// also reachable from the Pricing screen. The Taxes tab edits that store
		// rather than keeping a second copy of the same numbers.
		if ( isset( $payload['pricing'] ) && is_array( $payload['pricing'] ) ) {
			PricingSettings::save( array_merge( PricingSettings::all(), $payload['pricing'] ) );
		}

		if ( isset( $payload['roles'] ) && is_array( $payload['roles'] ) ) {
			RoleSettings::save( $payload['roles'] );
		}

		if ( isset( $payload['gateways'] ) && is_array( $payload['gateways'] ) ) {
			$this->gateways->save_enabled( $payload['gateways'] );
		}

		if ( isset( $payload['fieldConfig'] ) && is_array( $payload['fieldConfig'] ) ) {
			if ( isset( $payload['fieldConfig']['passenger'] ) && is_array( $payload['fieldConfig']['passenger'] ) ) {
				FieldConfig::save_modes( FieldConfig::GROUP_PASSENGER, $payload['fieldConfig']['passenger'] );
			}

			if ( isset( $payload['fieldConfig']['vehicle'] ) && is_array( $payload['fieldConfig']['vehicle'] ) ) {
				FieldConfig::save_modes( FieldConfig::GROUP_VEHICLE, $payload['fieldConfig']['vehicle'] );
			}
		}

		/**
		 * Fires after the Free settings have been saved.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Submitted payload.
		 */
		do_action( 'fbm_settings_saved', $payload );

		// The stored state is returned rather than a bare acknowledgement: the
		// store clamps numbers into range and drops what it does not recognise,
		// and the operator should be looking at what was actually saved.
		return $this->get_settings( $request );
	}

	/**
	 * Returns the payment methods the frontend may offer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_payment_methods( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond(
			array(
				'methods' => $this->gateways->offer(),
				'default' => $this->gateways->default_id(),
			)
		);
	}

	/**
	 * Reports the state of the managed pages, for the Settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pages_status(): array {
		if ( null === $this->pages ) {
			return array();
		}

		return $this->pages->status();
	}
}
