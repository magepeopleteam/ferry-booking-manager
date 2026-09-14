<?php
/**
 * First-run setup endpoint.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST\Controllers;

use FBM\REST\AbstractController;
use FBM\Security\Capabilities;
use FBM\Security\Permissions;
use FBM\Setup\SetupStatus;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and advances the first-run setup.
 *
 * Reading the progress is open to anyone who can see the dashboard, because the
 * dashboard needs it to know whether to show itself. Changing it needs the
 * settings capability, and finishing it is refused until there is something
 * to sell.
 */
final class SetupController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'setup';

	/**
	 * Setup progress service.
	 *
	 * @var SetupStatus
	 */
	private SetupStatus $setup;

	/**
	 * Constructor.
	 *
	 * @param Permissions $permissions Permission service.
	 * @param SetupStatus $setup       Setup progress service.
	 */
	public function __construct( Permissions $permissions, SetupStatus $setup ) {
		parent::__construct( $permissions );

		$this->setup = $setup;
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
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => $this->can( Capabilities::ACCESS_DASHBOARD ),
				),
			)
		);

		$text = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/business',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_business' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
					'args'                => array(
						'company_name'    => $text,
						'support_email'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'support_phone'   => $text,
						'currency'        => $text,
						'currency_symbol' => $text,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/complete',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'complete' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	/**
	 * Returns the setup progress.
	 *
	 * The business details are part of the settings, so they are left out for
	 * a user who could not open the Settings screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$status = $this->setup->status();

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			unset( $status['details'] );
		}

		return $this->respond( $status );
	}

	/**
	 * Saves the business details.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_business( WP_REST_Request $request ): WP_REST_Response {
		$input = array();

		foreach ( array( 'company_name', 'support_email', 'support_phone', 'currency', 'currency_symbol' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		return $this->result( $this->setup->save_business( $input ) );
	}

	/**
	 * Finishes setup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->result( $this->setup->complete() );
	}

	/**
	 * Turns a service result into a response.
	 *
	 * @param array<string, mixed>|WP_Error $result Service result.
	 * @return WP_REST_Response
	 */
	private function result( $result ): WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();

			return $this->fail(
				(string) $result->get_error_code(),
				(string) $result->get_error_message(),
				(int) ( $data['status'] ?? 400 ),
				isset( $data['fields'] ) ? array( 'fields' => $data['fields'] ) : array()
			);
		}

		return $this->respond( $result );
	}
}
