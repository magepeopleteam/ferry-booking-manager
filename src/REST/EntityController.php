<?php
/**
 * Generic entity CRUD controller.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST;

use FBM\Models\Entity;
use FBM\Repositories\AbstractRepository;
use FBM\Security\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Full CRUD for any schema-backed entity.
 *
 * The controller does four things and nothing else: authorise, sanitise through
 * the schema, delegate to the repository, and serialise. Domain rules live in
 * the entity, the repository or a service — never here.
 */
abstract class EntityController extends AbstractController {

	/**
	 * Repository handling persistence.
	 *
	 * @var AbstractRepository
	 */
	protected AbstractRepository $repository;

	/**
	 * Field the collection is sorted by when the client asks for nothing.
	 *
	 * Configuration lists are authored in a deliberate order — the sequence
	 * passenger types appear in on the booking form — so alphabetical is the
	 * wrong default for them, while it is the right one for operational data.
	 *
	 * @var string
	 */
	protected string $default_orderby = 'title';

	/**
	 * Direction applied alongside {@see EntityController::$default_orderby}.
	 *
	 * @var string
	 */
	protected string $default_order = 'desc';

	/**
	 * Constructor.
	 *
	 * @param Permissions        $permissions Permission service.
	 * @param AbstractRepository $repository  Entity repository.
	 */
	public function __construct( Permissions $permissions, AbstractRepository $repository ) {
		parent::__construct( $permissions );

		$this->repository = $repository;
	}

	/**
	 * Registers the controller routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$capability = $this->repository->schema()->capability();

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $this->can( $capability ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $this->can( $capability ),
					'args'                => $this->get_write_params( true ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description'       => __( 'Record id.', 'ferry-booking-manager' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $this->can( $capability ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $this->can( $capability ),
					'args'                => $this->get_write_params( false ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $this->can( $capability ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Bypass the trash and delete permanently.', 'ferry-booking-manager' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Lists entities.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$args = array_merge(
			$this->extra_query_args( $request ),
			array(
				'page'     => $this->page( $request ),
				'per_page' => $this->per_page( $request ),
				'search'   => (string) $request->get_param( 'search' ),
				'status'   => (string) $request->get_param( 'status' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) $request->get_param( 'order' ),
			)
		);

		$result = $this->repository->query( $args );

		$items = array_map(
			function ( Entity $entity ): array {
				return $this->prepare_item( $entity );
			},
			$result['items']
		);

		return Response::collection( $items, $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Returns a single entity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response {
		$entity = $this->repository->find( (int) $request->get_param( 'id' ) );

		if ( null === $entity ) {
			return $this->not_found();
		}

		return $this->respond( $this->prepare_item( $entity ) );
	}

	/**
	 * Creates an entity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response {
		return $this->write( $request, 0 );
	}

	/**
	 * Updates an entity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response {
		return $this->write( $request, (int) $request->get_param( 'id' ) );
	}

	/**
	 * Deletes an entity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->repository->delete( $id, (bool) $request->get_param( 'force' ) );

		if ( is_wp_error( $result ) ) {
			return Response::from_wp_error( $result );
		}

		return $this->respond(
			array(
				'id'      => $id,
				'deleted' => true,
			)
		);
	}

	/**
	 * Shared create/update path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $id      Existing id, or 0 to create.
	 * @return WP_REST_Response
	 */
	protected function write( WP_REST_Request $request, int $id ): WP_REST_Response {
		$schema  = $this->repository->schema();
		$payload = $this->request_payload( $request );

		$attributes = $schema->sanitize( $payload );

		if ( 0 === $id ) {
			// Creating: unsupplied fields fall back to their declared defaults.
			foreach ( $schema->fields() as $name => $field ) {
				if ( ! $field->readonly && ! array_key_exists( $name, $attributes ) ) {
					$attributes[ $name ] = $field->default;
				}
			}
		}

		$valid = $schema->validate( $attributes, $id > 0 );

		if ( is_wp_error( $valid ) ) {
			return Response::from_wp_error( $valid );
		}

		$duplicate = $this->check_unique( $schema, $attributes, $id );

		if ( is_wp_error( $duplicate ) ) {
			return Response::from_wp_error( $duplicate );
		}

		$guard = $this->guard( $attributes, $id );

		if ( is_wp_error( $guard ) ) {
			return Response::from_wp_error( $guard );
		}

		$name   = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
		$entity = $this->repository->save( $attributes, $id, $name );

		if ( is_wp_error( $entity ) ) {
			return Response::from_wp_error( $entity );
		}

		return $this->respond( $this->prepare_item( $entity ), array(), 0 === $id ? 201 : 200 );
	}

	/**
	 * Extracts the writable payload from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	protected function request_payload( WP_REST_Request $request ): array {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) || array() === $payload ) {
			$payload = $request->get_body_params();
		}

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Rejects values that would duplicate an identifier another record holds.
	 *
	 * Codes appear on tickets, manifests and boarding passes; two ports sharing
	 * one is not a cosmetic problem, it is a passenger boarding the wrong vessel.
	 *
	 * @param \FBM\Support\Schema\EntitySchema $schema     Entity schema.
	 * @param array<string, mixed>             $attributes Sanitised attributes.
	 * @param int                              $id         Record being updated, or 0.
	 * @return true|WP_Error
	 */
	protected function check_unique( $schema, array $attributes, int $id ) {
		foreach ( $schema->unique_fields() as $name => $field ) {
			if ( ! array_key_exists( $name, $attributes ) ) {
				continue;
			}

			$value = $attributes[ $name ];

			if ( $field->is_empty( $value ) ) {
				continue;
			}

			$conflict = $this->repository->find_conflicting_id( $field->meta_key, $value, $id );

			if ( 0 === $conflict ) {
				continue;
			}

			$trashed = 'trash' === get_post_status( $conflict );

			$message = $trashed
				? sprintf(
					/* translators: 1: field name, 2: the duplicate value. */
					__( 'A deleted record in the trash still uses the %1$s “%2$s”. Choose a different one, or empty the trash first.', 'ferry-booking-manager' ),
					$field->label(),
					(string) $value
				)
				: sprintf(
					/* translators: 1: field name, 2: the duplicate value. */
					__( 'Another record already uses the %1$s “%2$s”. Choose a different one.', 'ferry-booking-manager' ),
					$field->label(),
					(string) $value
				);

			return new WP_Error(
				'fbm_duplicate_value',
				$message,
				array(
					'status' => 409,
					'fields' => array(
						$name => $trashed
							? sprintf(
								/* translators: %s: the duplicate value. */
								__( '“%s” is used by a record in the trash.', 'ferry-booking-manager' ),
								(string) $value
							)
							: sprintf(
								/* translators: %s: the duplicate value. */
								__( '“%s” is already in use.', 'ferry-booking-manager' ),
								(string) $value
							),
					),
				)
			);
		}

		return true;
	}

	/**
	 * Applies domain rules that span more than one field.
	 *
	 * @param array<string, mixed> $attributes Sanitised attributes.
	 * @param int                  $id         Existing id, or 0 when creating.
	 * @return true|WP_Error
	 */
	protected function guard( array $attributes, int $id ) {
		unset( $attributes, $id );

		return true;
	}

	/**
	 * Returns repository arguments derived from controller-specific filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	protected function extra_query_args( WP_REST_Request $request ): array {
		unset( $request );

		return array();
	}

	/**
	 * Serialises an entity for a response.
	 *
	 * @param Entity $entity Entity.
	 * @return array<string, mixed>
	 */
	protected function prepare_item( Entity $entity ): array {
		return $entity->to_array();
	}

	/**
	 * Returns the collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_collection_params(): array {
		$params = $this->collection_params();

		$status = $this->repository->schema()->field( 'status' );

		$params['status'] = array(
			'description'       => __( 'Limit results to a status.', 'ferry-booking-manager' ),
			'type'              => 'string',
			'default'           => '',
			'enum'              => array_merge( array( '' ), null === $status ? array() : $status->enum ),
			'sanitize_callback' => 'sanitize_key',
		);

		$params['orderby'] = array(
			'description'       => __( 'Field to sort by.', 'ferry-booking-manager' ),
			'type'              => 'string',
			'default'           => $this->default_orderby,
			'sanitize_callback' => 'sanitize_key',
		);

		$params['order']['default'] = $this->default_order;

		return $params;
	}

	/**
	 * Returns the write endpoint arguments.
	 *
	 * @param bool $creating Whether the arguments describe a create request.
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_write_params( bool $creating ): array {
		$args = $this->repository->schema()->rest_args( $creating );

		$args['name'] = array(
			'description' => __( 'Display name.', 'ferry-booking-manager' ),
			'type'        => 'string',
			'required'    => false,
		);

		return $args;
	}

	/**
	 * Returns the JSON Schema for the resource.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_item_schema(): array {
		return $this->repository->schema()->rest_schema();
	}

	/**
	 * Builds the standard not-found response.
	 *
	 * @return WP_REST_Response
	 */
	protected function not_found(): WP_REST_Response {
		return $this->fail(
			'fbm_not_found',
			__( 'The record could not be found.', 'ferry-booking-manager' ),
			404
		);
	}
}
