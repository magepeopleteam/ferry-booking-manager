<?php
/**
 * Admin application renderer.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Admin;

use MPFBS\Contracts\LoggerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the pre-built Next.js dashboard from the plugin's own assets.
 *
 * The Next.js application is exported at build time (`next build` with
 * `output: 'export'`) and post-processed into `assets/admin/app/mpfbs-app.json`,
 * which lists the emitted stylesheets and script chunks plus the pre-rendered
 * shell markup. WordPress enqueues those files itself, so no Node process and no
 * runtime bundler is ever required on the customer's host.
 *
 * The exported `__NEXT_DATA__` payload carries an empty asset prefix; it is
 * rewritten here with the real plugin URL so that any lazily imported chunk
 * resolves against the plugin directory rather than the site root.
 */
final class AppRenderer {

	/**
	 * Directory (relative to the plugin root) holding the exported application.
	 */
	private const APP_DIR = 'assets/admin/app/';

	/**
	 * Manifest filename produced by the build script.
	 */
	private const MANIFEST = 'mpfbs-app.json';

	/**
	 * Markup the pre-rendered loading shell may contain.
	 *
	 * The shell is a skeleton of plain boxes. Everything else is stripped, and
	 * the allowlist matches what the build emits exactly, so the application
	 * still hydrates against the markup it rendered.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const SHELL_TAGS = array(
		'div'  => array(
			'class'       => true,
			'role'        => true,
			'style'       => true,
			'aria-hidden' => true,
			'aria-label'  => true,
		),
		'span' => array(
			'class'       => true,
			'role'        => true,
			'style'       => true,
			'aria-hidden' => true,
			'aria-label'  => true,
		),
	);

	/**
	 * Root element id the exported application hydrates into.
	 */
	private const ROOT_ID = '__next';

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Cached manifest for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $manifest = null;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Determines whether a usable production build is present.
	 *
	 * @return bool
	 */
	public function is_built(): bool {
		$manifest = $this->manifest();

		return array() !== $manifest && ! empty( $manifest['scripts'] );
	}

	/**
	 * Returns the absolute URL of the exported application directory.
	 *
	 * No trailing slash: Next appends "/_next/" itself.
	 *
	 * @return string
	 */
	public function asset_prefix(): string {
		return untrailingslashit( MPFBS_URL . self::APP_DIR );
	}

	/**
	 * Returns the stylesheet URLs to enqueue, in document order.
	 *
	 * @return string[]
	 */
	public function styles(): array {
		return $this->urls( 'styles' );
	}

	/**
	 * Returns the script URLs to enqueue, in document order.
	 *
	 * @return string[]
	 */
	public function scripts(): array {
		return $this->urls( 'scripts' );
	}

	/**
	 * Prints the application root and the hydration payload.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->is_built() ) {
			$this->render_missing_build();

			return;
		}

		$manifest  = $this->manifest();
		$next_data = is_array( $manifest['nextData'] ?? null ) ? $manifest['nextData'] : array();

		$next_data['assetPrefix'] = $this->asset_prefix();

		$shell = is_string( $manifest['html'] ?? null ) ? $manifest['html'] : '';

		printf(
			'<div id="%s">%s</div>',
			esc_attr( self::ROOT_ID ),
			wp_kses( $shell, self::SHELL_TAGS )
		);

		// The hydration payload the exported application reads at startup.
		wp_print_inline_script_tag(
			(string) wp_json_encode( $next_data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
			array(
				'id'   => '__NEXT_DATA__',
				'type' => 'application/json',
			)
		);
	}

	/**
	 * Renders a build-missing message instead of a blank screen.
	 *
	 * @return void
	 */
	private function render_missing_build(): void {
		$this->logger->error(
			'Admin application build is missing.',
			array( 'expected' => self::APP_DIR . self::MANIFEST )
		);

		echo '<div class="notice notice-error"><p><strong>';
		esc_html_e( 'The Ferry Manager dashboard has not been built.', 'magepeople-ferry-booking-system' );
		echo '</strong></p><p>';
		printf(
			/* translators: %s: build command. */
			esc_html__( 'Run %s inside the plugin folder to produce the dashboard bundle, then reload this page.', 'magepeople-ferry-booking-system' ),
			'<code>npm --prefix apps/admin ci &amp;&amp; npm --prefix apps/admin run build</code>'
		);
		echo '</p></div>';
	}

	/**
	 * Maps manifest-relative paths to absolute URLs.
	 *
	 * @param string $key Manifest key holding a list of relative paths.
	 * @return string[]
	 */
	private function urls( string $key ): array {
		$manifest = $this->manifest();
		$paths    = isset( $manifest[ $key ] ) && is_array( $manifest[ $key ] ) ? $manifest[ $key ] : array();
		$base     = trailingslashit( MPFBS_URL . self::APP_DIR );
		$urls     = array();

		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}

			// Only relative paths inside the exported app are ever served.
			if ( false !== strpos( $path, '..' ) || preg_match( '#^[a-z]+://#i', $path ) ) {
				continue;
			}

			$urls[] = $base . ltrim( $path, '/' );
		}

		return $urls;
	}

	/**
	 * Loads and validates the build manifest.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}

		$file = MPFBS_PATH . self::APP_DIR . self::MANIFEST;

		if ( ! is_readable( $file ) ) {
			$this->manifest = array();

			return $this->manifest;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw     = (string) file_get_contents( $file );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			$this->logger->error( 'Admin application manifest could not be decoded.', array( 'file' => $file ) );
			$this->manifest = array();

			return $this->manifest;
		}

		$this->manifest = $decoded;

		return $this->manifest;
	}
}
