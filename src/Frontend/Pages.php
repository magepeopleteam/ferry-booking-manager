<?php
/**
 * Managed front-end pages.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Frontend;

use MPFBS\Support\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and tracks the pages the booking flow needs.
 *
 * A ferry operator activating the plugin should be able to sell a ticket
 * without first learning which shortcode goes on which page, so the pages are
 * created for them. They are ordinary WordPress pages containing an ordinary
 * block, which means they can be renamed, restyled, translated, moved into a
 * menu or replaced entirely — the plugin only remembers which page it made.
 *
 * Creation runs on the first `init` after activation, not inside the activation
 * hook: block types and post types register on `init`, and the plugin is not
 * loaded when that fires during activation.
 */
final class Pages {

	/**
	 * Option holding page id by key.
	 */
	private const OPTION = 'pages';

	/**
	 * Option marking the one-time creation as done.
	 */
	private const FLAG = 'pages_created';

	/**
	 * Meta key marking a page as one the plugin created.
	 */
	public const MARKER = '_mpfbs_managed_page';

	/**
	 * Returns the pages the plugin manages.
	 *
	 * @return array<string, array{title: string, block: string, content: string, description: string}>
	 */
	public static function definitions(): array {
		$definitions = array(
			'booking'      => array(
				'title'       => __( 'Book a Crossing', 'magepeople-ferry-booking-system' ),
				'block'       => Components::NAMESPACE . '/booking',
				'shortcode'   => 'mpfbs_booking',
				'description' => __( 'Search, choose a sailing and pay. The whole booking flow lives here.', 'magepeople-ferry-booking-system' ),
			),
			'confirmation' => array(
				'title'       => __( 'Booking Confirmation', 'magepeople-ferry-booking-system' ),
				'block'       => Components::NAMESPACE . '/confirmation',
				'shortcode'   => 'mpfbs_confirmation',
				'description' => __( 'Where a customer lands after paying. Shows their booking reference.', 'magepeople-ferry-booking-system' ),
			),
			'my_bookings'  => array(
				'title'       => __( 'My Bookings', 'magepeople-ferry-booking-system' ),
				'block'       => Components::NAMESPACE . '/my-bookings',
				'shortcode'   => 'mpfbs_my_bookings',
				'description' => __( 'A customer’s upcoming and past crossings.', 'magepeople-ferry-booking-system' ),
			),
			'lookup'       => array(
				'title'       => __( 'Find My Booking', 'magepeople-ferry-booking-system' ),
				'block'       => Components::NAMESPACE . '/lookup',
				'shortcode'   => 'mpfbs_lookup',
				'description' => __( 'Lets a guest retrieve a booking with a reference and email address.', 'magepeople-ferry-booking-system' ),
			),
		);

		/**
		 * Filters the front-end pages the plugin manages.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, string>> $definitions Page definitions keyed by slug key.
		 */
		return (array) apply_filters( 'mpfbs_managed_pages', $definitions );
	}

	/**
	 * Attaches the hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'maybe_create' ), 25 );
		add_filter( 'display_post_states', array( $this, 'post_state' ), 10, 2 );
	}

	/**
	 * Creates any managed page that does not exist yet.
	 *
	 * @return void
	 */
	public function maybe_create(): void {
		if ( Options::get_bool( self::FLAG ) && array() !== $this->stored() ) {
			return;
		}

		Options::set( self::FLAG, 1, true );

		$this->install();
	}

	/**
	 * Creates every missing managed page and records its id.
	 *
	 * Safe to call repeatedly: a page that already exists is left alone, and a
	 * page the operator deleted is recreated only when this is called
	 * deliberately from Settings.
	 *
	 * @return array<string, int> Page key => page id.
	 */
	public function install(): array {
		$stored = $this->stored();

		foreach ( self::definitions() as $key => $definition ) {
			$existing = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;

			if ( $existing > 0 && $this->is_usable( $existing ) ) {
				continue;
			}

			$found = $this->find_existing( (string) $definition['shortcode'], (string) $definition['block'] );

			if ( $found > 0 ) {
				$stored[ $key ] = $found;
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => (string) $definition['title'],
					'post_name'    => sanitize_title( (string) $definition['title'] ),
					'post_content' => $this->block_markup( (string) $definition['block'] ),
					'meta_input'   => array( self::MARKER => $key ),
				),
				true
			);

			if ( ! is_wp_error( $page_id ) ) {
				$stored[ $key ] = (int) $page_id;
			}
		}

		Options::set( self::OPTION, $stored, true );

		/**
		 * Fires after the managed pages have been installed.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, int> $stored Page key => page id.
		 */
		do_action( 'mpfbs_pages_installed', $stored );

		return $stored;
	}

	/**
	 * Returns the id of a managed page.
	 *
	 * @param string $key Page key.
	 * @return int Zero when the page is missing.
	 */
	public function id( string $key ): int {
		$stored = $this->stored();
		$id     = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;

		return $this->is_usable( $id ) ? $id : 0;
	}

	/**
	 * Returns the permalink of a managed page.
	 *
	 * @param string               $key   Page key.
	 * @param array<string, mixed> $args  Query arguments to append.
	 * @return string Empty when the page is missing.
	 */
	public function url( string $key, array $args = array() ): string {
		$id = $this->id( $key );

		if ( 0 === $id ) {
			return '';
		}

		$url = (string) get_permalink( $id );

		return array() === $args ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Assigns a managed page to a key, replacing whatever was there.
	 *
	 * @param string $key     Page key.
	 * @param int    $page_id Page id, or 0 to clear.
	 * @return void
	 */
	public function assign( string $key, int $page_id ): void {
		$definitions = self::definitions();

		if ( ! isset( $definitions[ $key ] ) ) {
			return;
		}

		$stored = $this->stored();

		if ( $page_id > 0 && $this->is_usable( $page_id ) ) {
			$stored[ $key ] = $page_id;
			update_post_meta( $page_id, self::MARKER, $key );
		} else {
			unset( $stored[ $key ] );
		}

		Options::set( self::OPTION, $stored, true );
	}

	/**
	 * Reports the state of every managed page, for the Settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function status(): array {
		$stored = $this->stored();
		$status = array();

		foreach ( self::definitions() as $key => $definition ) {
			$id = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
			$ok = $this->is_usable( $id );

			$status[] = array(
				'key'         => $key,
				'title'       => (string) $definition['title'],
				'description' => (string) $definition['description'],
				'shortcode'   => '[' . $definition['shortcode'] . ']',
				'page_id'     => $ok ? $id : 0,
				'page_title'  => $ok ? get_the_title( $id ) : '',
				'url'         => $ok ? (string) get_permalink( $id ) : '',
				'edit_url'    => $ok ? (string) get_edit_post_link( $id, 'raw' ) : '',
				'exists'      => $ok,
			);
		}

		return $status;
	}

	/**
	 * Labels managed pages in the WordPress pages list.
	 *
	 * @param string[] $states Existing post states.
	 * @param WP_Post  $post   Post being listed.
	 * @return string[]
	 */
	public function post_state( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();

		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return $states;
		}

		$key = (string) get_post_meta( $post->ID, self::MARKER, true );

		if ( '' === $key ) {
			return $states;
		}

		$definitions = self::definitions();

		if ( isset( $definitions[ $key ] ) ) {
			$states['mpfbs_page'] = sprintf(
				/* translators: %s: the ferry page's purpose, e.g. "Book a Crossing". */
				__( 'Ferry — %s', 'magepeople-ferry-booking-system' ),
				(string) $definitions[ $key ]['title']
			);
		}

		return $states;
	}

	/**
	 * Returns the stored page map.
	 *
	 * @return array<string, int>
	 */
	private function stored(): array {
		$stored = Options::get_array( self::OPTION );
		$clean  = array();

		foreach ( $stored as $key => $id ) {
			$clean[ (string) $key ] = (int) $id;
		}

		return $clean;
	}

	/**
	 * Determines whether a page id points at a page a customer can open.
	 *
	 * @param int $page_id Page id.
	 * @return bool
	 */
	private function is_usable( int $page_id ): bool {
		if ( $page_id < 1 ) {
			return false;
		}

		$post = get_post( $page_id );

		return $post instanceof WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status;
	}

	/**
	 * Looks for a page that already carries the component.
	 *
	 * An operator who set the booking form up by hand before the plugin got
	 * round to it should not end up with two booking pages.
	 *
	 * @param string $shortcode Shortcode tag.
	 * @param string $block     Block name.
	 * @return int
	 */
	private function find_existing( string $shortcode, string $block ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				's'                      => $shortcode,
			)
		);

		foreach ( (array) $query->posts as $candidate ) {
			$content = (string) get_post_field( 'post_content', (int) $candidate );

			if ( has_shortcode( $content, $shortcode ) || false !== strpos( $content, $block ) ) {
				return (int) $candidate;
			}
		}

		return 0;
	}

	/**
	 * Builds the block comment markup for a page's content.
	 *
	 * @param string $block Block name.
	 * @return string
	 */
	private function block_markup( string $block ): string {
		// The booking flow is a wide layout by default: a timetable in a
		// 40rem prose column is unreadable, and an operator should not have to
		// discover a width setting before their first sale.
		$attributes = Components::NAMESPACE . '/booking' === $block ? ' {"width":"wide"}' : '';

		return sprintf( '<!-- wp:%1$s%2$s /-->', $block, $attributes );
	}
}
