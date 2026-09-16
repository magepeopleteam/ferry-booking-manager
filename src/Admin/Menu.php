<?php
/**
 * Admin menu.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Admin;

use FBM\Core\Assets;
use FBM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the single "Ferry Manager" admin screen.
 *
 * The plugin deliberately owns exactly one WordPress admin page. Everything else
 * — bookings, sailings, reports, settings — is a client-side route inside the
 * dashboard application, so navigating never reloads WordPress.
 */
final class Menu {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'fbm-dashboard';

	/**
	 * Application renderer.
	 *
	 * @var AppRenderer
	 */
	private AppRenderer $renderer;

	/**
	 * Asset loader.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Hook suffix returned by add_menu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param AppRenderer $renderer Application renderer.
	 * @param Assets      $assets   Asset loader.
	 */
	public function __construct( AppRenderer $renderer, Assets $assets ) {
		$this->renderer = $renderer;
		$this->assets   = $assets;
	}

	/**
	 * Attaches the admin hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Registers the top level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Ferry Manager', 'magepeople-ferry-booking-system' ),
			__( 'Ferry Manager', 'magepeople-ferry-booking-system' ),
			Capabilities::ACCESS_DASHBOARD,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			$this->menu_icon(),
			26
		);
	}

	/**
	 * Determines whether the current screen is the dashboard.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return bool
	 */
	public function is_dashboard_screen( string $hook_suffix ): bool {
		return '' !== $this->hook_suffix && $hook_suffix === $this->hook_suffix;
	}

	/**
	 * Enqueues assets on the dashboard screen only.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! $this->is_dashboard_screen( (string) $hook_suffix ) ) {
			return;
		}

		$this->assets->enqueue_admin_app();
	}

	/**
	 * Adds a body class so the dashboard can take over the admin canvas.
	 *
	 * @param string $classes Existing space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && '' !== $this->hook_suffix && $screen->id === $this->hook_suffix ) {
			$classes .= ' fbm-admin-screen';
		}

		return (string) $classes;
	}

	/**
	 * Renders the dashboard page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( Capabilities::ACCESS_DASHBOARD ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access the Ferry Manager dashboard.', 'magepeople-ferry-booking-system' ),
				esc_html__( 'Permission denied', 'magepeople-ferry-booking-system' ),
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap fbm-admin-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Ferry Manager', 'magepeople-ferry-booking-system' ) . '</h1>';

		$this->renderer->render();

		echo '</div>';
	}

	/**
	 * Returns the inline SVG data URI used as the menu icon.
	 *
	 * @return string
	 */
	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black">'
			. '<path d="M10 1.5 12.2 6h-4.4L10 1.5Z"/>'
			. '<path d="M5.5 7h9l1.2 3.6-6.7 2.1-6.7-2.1L3.5 7h2Z"/>'
			. '<path d="M1.6 12.4 10 15l8.4-2.6.9 2.2A3.6 3.6 0 0 1 16 18.5a3.6 3.6 0 0 1-3-1.5 3.6 3.6 0 0 1-3 1.5 3.6 3.6 0 0 1-3-1.5 3.6 3.6 0 0 1-3 1.5A3.6 3.6 0 0 1 .7 14.6l.9-2.2Z"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required data URI encoding.
	}
}
