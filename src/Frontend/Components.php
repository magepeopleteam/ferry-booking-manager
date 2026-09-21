<?php
/**
 * Front-end components: blocks and shortcodes.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every way of placing the booking flow on a page.
 *
 * Blocks and shortcodes are two front doors onto one implementation: each
 * renders a mount point and tells the asset loader which component the page
 * needs. Nothing is rendered server-side beyond a container and a no-JavaScript
 * fallback, because the flow is interactive from the first keystroke.
 */
final class Components {

	/**
	 * Block namespace.
	 */
	public const NAMESPACE = 'magepeople-ferry-booking-system';

	/**
	 * Asset loader, which needs to know what a page contains.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Managed pages.
	 *
	 * @var Pages
	 */
	private Pages $pages;

	/**
	 * Constructor.
	 *
	 * @param Assets $assets Asset loader.
	 * @param Pages  $pages  Managed pages.
	 */
	public function __construct( Assets $assets, Pages $pages ) {
		$this->assets = $assets;
		$this->pages  = $pages;
	}

	/**
	 * Returns the components the plugin provides.
	 *
	 * @return array<string, array{title: string, shortcode: string, attributes: array<string, array<string, mixed>>}>
	 */
	public static function catalogue(): array {
		$catalogue = array(
			'booking'      => array(
				'title'      => __( 'Ferry Booking', 'magepeople-ferry-booking-system' ),
				'shortcode'  => 'mpfbs_booking',
				'attributes' => array(
					'origin'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'destination' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'width'       => array(
						'type'    => 'string',
						'default' => 'wide',
					),
				),
			),
			'search'       => array(
				'title'      => __( 'Ferry Search Form', 'magepeople-ferry-booking-system' ),
				'shortcode'  => 'mpfbs_search',
				'attributes' => array(
					'origin'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'destination' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'layout'      => array(
						'type'    => 'string',
						'default' => 'inline',
					),
					'width'       => array(
						'type'    => 'string',
						'default' => 'default',
					),
				),
			),
			'confirmation' => array(
				'title'      => __( 'Ferry Booking Confirmation', 'magepeople-ferry-booking-system' ),
				'shortcode'  => 'mpfbs_confirmation',
				'attributes' => array(),
			),
			'my-bookings'  => array(
				'title'      => __( 'My Ferry Bookings', 'magepeople-ferry-booking-system' ),
				'shortcode'  => 'mpfbs_my_bookings',
				'attributes' => array(
					// A history is a table of crossings, not prose. It defaults
					// to the theme's wide alignment for the same reason the
					// booking flow does: a reading measure is the wrong width
					// for a list of dates, routes and prices.
					'width' => array(
						'type'    => 'string',
						'default' => 'wide',
					),
				),
			),
			'lookup'       => array(
				'title'      => __( 'Find a Ferry Booking', 'magepeople-ferry-booking-system' ),
				'shortcode'  => 'mpfbs_lookup',
				'attributes' => array(),
			),
		);

		/**
		 * Filters the front-end components the plugin registers.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $catalogue Component definitions.
		 */
		return (array) apply_filters( 'mpfbs_components', $catalogue );
	}

	/**
	 * Attaches the hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ), 30 );
	}

	/**
	 * Registers every block and shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::catalogue() as $name => $definition ) {
			$component = (string) $name;

			register_block_type(
				self::NAMESPACE . '/' . $component,
				array(
					'api_version'     => 3,
					'title'           => (string) $definition['title'],
					'category'        => 'widgets',
					'attributes'      => (array) $definition['attributes'],
					'render_callback' => function ( $attributes ) use ( $component ): string {
						return $this->render( $component, is_array( $attributes ) ? $attributes : array() );
					},
				)
			);

			add_shortcode(
				(string) $definition['shortcode'],
				function ( $attributes ) use ( $component, $definition ): string {
					$attributes = shortcode_atts(
						$this->shortcode_defaults( (array) $definition['attributes'] ),
						is_array( $attributes ) ? $attributes : array(),
						(string) $definition['shortcode']
					);

					return $this->render( $component, $attributes );
				}
			);
		}
	}

	/**
	 * Renders a component mount point.
	 *
	 * @param string               $component  Component name.
	 * @param array<string, mixed> $attributes Component attributes.
	 * @return string
	 */
	public function render( string $component, array $attributes ): string {
		$this->assets->require_component( $component );

		$config = array(
			'component'  => $component,
			'attributes' => $this->clean_attributes( $component, $attributes ),
		);

		$id = 'mpfbs-mount-' . $component . '-' . wp_unique_id();

		/*
		 * Most themes cap their content column at around 40rem, which is fine
		 * for prose and far too narrow for a timetable. The width modifier lets
		 * the booking flow claim the space it needs without the operator having
		 * to fight their theme's layout.
		 */
		$width = isset( $config['attributes']['width'] ) ? (string) $config['attributes']['width'] : 'default';
		$width = in_array( $width, array( 'default', 'wide', 'full' ), true ) ? $width : 'default';

		/*
		 * Widening goes through WordPress's own alignment classes rather than a
		 * CSS override. A theme knows where its wide column sits, and using its
		 * mechanism keeps the booking form on the same centre line as the page
		 * title above it — an override wide enough to be useful ends up
		 * centred on the viewport instead, and the mismatch reads as broken.
		 */
		$alignment = '';

		if ( 'wide' === $width ) {
			$alignment = ' alignwide';
		} elseif ( 'full' === $width ) {
			$alignment = ' alignfull';
		}

		return sprintf(
			'<div class="mpfbs-frontend mpfbs-frontend--%1$s mpfbs-frontend--w-%5$s%6$s" id="%2$s" data-mpfbs-component="%1$s" data-mpfbs-config="%3$s">%4$s</div>',
			esc_attr( $component ),
			esc_attr( $id ),
			esc_attr( (string) wp_json_encode( $config ) ),
			$this->fallback( $component ),
			esc_attr( $width ),
			$alignment
		);
	}

	/**
	 * Renders what a visitor sees before the application boots.
	 *
	 * Not a spinner: a page that shows nothing until JavaScript arrives is
	 * indistinguishable from a broken one, and a visitor without JavaScript
	 * needs to be told where else to buy a ticket.
	 *
	 * @param string $component Component name.
	 * @return string
	 */
	private function fallback( string $component ): string {
		$message = 'booking' === $component || 'search' === $component
			? __( 'Loading the timetable…', 'magepeople-ferry-booking-system' )
			: __( 'Loading…', 'magepeople-ferry-booking-system' );

		return sprintf(
			'<div class="mpfbs-frontend__loading" role="status" aria-live="polite"><span class="mpfbs-frontend__spinner" aria-hidden="true"></span><span>%1$s</span></div><noscript><p class="mpfbs-frontend__noscript">%2$s</p></noscript>',
			esc_html( $message ),
			esc_html__( 'Booking online needs JavaScript. Please enable it, or contact us to book by phone.', 'magepeople-ferry-booking-system' )
		);
	}

	/**
	 * Reduces submitted attributes to the ones a component declares.
	 *
	 * @param string               $component  Component name.
	 * @param array<string, mixed> $attributes Submitted attributes.
	 * @return array<string, mixed>
	 */
	private function clean_attributes( string $component, array $attributes ): array {
		$catalogue = self::catalogue();
		$declared  = isset( $catalogue[ $component ] ) ? (array) $catalogue[ $component ]['attributes'] : array();
		$clean     = array();

		foreach ( $declared as $key => $schema ) {
			if ( ! array_key_exists( $key, $attributes ) ) {
				$clean[ $key ] = $schema['default'] ?? null;
				continue;
			}

			$value = $attributes[ $key ];

			switch ( $schema['type'] ?? 'string' ) {
				case 'number':
					$clean[ $key ] = (int) $value;
					break;

				case 'boolean':
					$clean[ $key ] = in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );
					break;

				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$clean['pages'] = array(
			'booking'      => $this->pages->url( 'booking' ),
			'confirmation' => $this->pages->url( 'confirmation' ),
			'myBookings'   => $this->pages->url( 'my_bookings' ),
			'lookup'       => $this->pages->url( 'lookup' ),
		);

		return $clean;
	}

	/**
	 * Builds the shortcode defaults from a block's attribute schema.
	 *
	 * @param array<string, array<string, mixed>> $attributes Attribute schema.
	 * @return array<string, mixed>
	 */
	private function shortcode_defaults( array $attributes ): array {
		$defaults = array();

		foreach ( $attributes as $key => $schema ) {
			$defaults[ $key ] = $schema['default'] ?? '';
		}

		return $defaults;
	}
}
