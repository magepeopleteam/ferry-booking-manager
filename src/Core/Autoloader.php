<?php
/**
 * Bundled PSR-4 autoloader.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader used when Composer's autoloader is unavailable.
 *
 * Keeps the distributed plugin free of any Composer runtime requirement.
 */
final class Autoloader {

	/**
	 * Registered namespace prefix => base directory pairs.
	 *
	 * @var array<string, string>
	 */
	private static array $prefixes = array();

	/**
	 * Whether the SPL handler has been attached.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers a namespace prefix with the autoloader.
	 *
	 * @param string $prefix   Namespace prefix, e.g. "FBM\".
	 * @param string $base_dir Absolute directory that maps to the prefix.
	 * @return void
	 */
	public static function register( string $prefix, string $base_dir ): void {
		self::$prefixes[ ltrim( $prefix, '\\' ) ] = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( ! self::$registered ) {
			spl_autoload_register( array( self::class, 'load' ) );
			self::$registered = true;
		}
	}

	/**
	 * Resolves a class name to a file and requires it.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				continue;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;

				return;
			}
		}
	}
}
