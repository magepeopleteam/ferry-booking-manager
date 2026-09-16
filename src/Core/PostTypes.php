<?php
/**
 * Post type and meta registration.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

use FBM\Models\Booking;
use FBM\Models\Entity;
use FBM\Models\PassengerType;
use FBM\Models\Port;
use FBM\Models\Route;
use FBM\Models\Sailing;
use FBM\Models\VehicleType;
use FBM\Models\Vessel;
use FBM\Security\Permissions;
use FBM\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the five post types that hold every ferry record.
 *
 * The plugin creates no database tables. Operational records live in the posts
 * table, their properties in post meta, and configuration in options — which
 * means every WordPress backup, migration and multisite tool already
 * understands the data.
 *
 * None of the post types are publicly queryable and none render a WordPress
 * editor screen: the dashboard is the only interface, so there is no second
 * write path that could bypass validation.
 */
final class PostTypes {

	/**
	 * Permission service used for meta authorisation callbacks.
	 *
	 * @var Permissions
	 */
	private Permissions $permissions;

	/**
	 * Constructor.
	 *
	 * @param Permissions $permissions Permission service.
	 */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Returns the entity classes backed by a post type.
	 *
	 * @return array<int, class-string<Entity>>
	 */
	public static function entities(): array {
		$entities = array(
			Port::class,
			Vessel::class,
			Route::class,
			Sailing::class,
			PassengerType::class,
			VehicleType::class,
			Booking::class,
		);

		/**
		 * Filters the entity classes registered by the plugin.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, class-string<Entity>> $entities Entity class names.
		 */
		return (array) apply_filters( 'fbm_entities', $entities );
	}

	/**
	 * Attaches the registration hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
	}

	/**
	 * Registers post types and their meta.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Driven from the entity list, not from a second hardcoded list beside
		 * it. An add-on that registers an entity gets its post type as well as
		 * its meta — before this, the filter registered the meta for a post
		 * type that did not exist, which is a record WordPress will store and
		 * never sanitise.
		 */
		foreach ( self::entities() as $entity_class ) {
			$labels = $entity_class::labels();

			$this->register_post_type(
				$entity_class::post_type(),
				(string) ( $labels['plural'] ?? $entity_class::key() ),
				(string) ( $labels['singular'] ?? $entity_class::key() )
			);

			$this->register_meta( $entity_class );
		}

		/**
		 * Fires once MagePeople Ferry Booking System post types and meta are registered.
		 *
		 * @since 1.0.0
		 */
		do_action( 'fbm_post_types_registered' );
	}

	/**
	 * Flushes rewrite rules once after activation or an upgrade.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		if ( ! Options::get_bool( 'needs_rewrite_flush' ) ) {
			return;
		}

		Options::delete( 'needs_rewrite_flush' );
		flush_rewrite_rules( false );
	}

	/**
	 * Registers a single internal post type.
	 *
	 * @param string $post_type Post type key.
	 * @param string $plural    Plural label.
	 * @param string $singular  Singular label.
	 * @return void
	 */
	private function register_post_type( string $post_type, string $plural, string $singular ): void {
		$args = array(
			'labels'              => array(
				'name'          => $plural,
				'singular_name' => $singular,
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'supports'            => array( 'title', 'author', 'custom-fields' ),
			'map_meta_cap'        => true,
			'capability_type'     => 'post',
		);

		/**
		 * Filters the arguments used to register a MagePeople Ferry Booking System post type.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args      Registration arguments.
		 * @param string               $post_type Post type key.
		 */
		$args = (array) apply_filters( 'fbm_post_type_args', $args, $post_type );

		register_post_type( $post_type, $args );
	}

	/**
	 * Registers every meta key an entity declares.
	 *
	 * Registering meta gives WordPress the sanitisation callback and, more
	 * importantly, an authorisation callback: without it, any user who can edit
	 * a post could write ferry meta through a generic meta endpoint.
	 *
	 * @param string $entity_class Entity class name, guaranteed to extend Entity.
	 * @return void
	 */
	private function register_meta( string $entity_class ): void {
		$schema     = $entity_class::schema();
		$capability = $entity_class::capability();

		foreach ( $schema->meta_fields() as $field ) {
			register_post_meta(
				$schema->post_type(),
				$field->meta_key,
				array(
					'type'              => 'array' === $field->rest_type() ? 'array' : $field->rest_type(),
					'description'       => $field->description,
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => static function ( $value ) use ( $field ) {
						// Sanitising to the storage form, rather than the PHP
						// form, keeps a direct update_post_meta() call writing
						// the same unambiguous value the repository writes.
						return $field->to_storage( $field->sanitize( $value ) );
					},
					'auth_callback'     => function () use ( $capability ): bool {
						return $this->permissions->current_user_can( $capability );
					},
				)
			);
		}
	}
}
