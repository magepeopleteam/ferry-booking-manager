<?php
/**
 * Prefixed option helper.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, typed accessor for `fbm_*` options.
 *
 * Every option the plugin writes goes through here so that uninstall can find
 * them again and so that option names are guaranteed to carry the prefix.
 */
final class Options {

	/**
	 * Option prefix enforced on every key.
	 */
	public const PREFIX = 'fbm_';

	/**
	 * Option storing the list of option names the plugin has written.
	 */
	public const REGISTRY = 'fbm_option_registry';

	/**
	 * Reads an option.
	 *
	 * @param string $key     Option key, with or without the prefix.
	 * @param mixed  $fallback Value returned when nothing is stored.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		return get_option( self::key( $key ), $fallback );
	}

	/**
	 * Reads an option as a string.
	 *
	 * @param string $key     Option key.
	 * @param string $fallback Value returned when nothing is stored.
	 * @return string
	 */
	public static function get_string( string $key, string $fallback = '' ): string {
		$value = self::get( $key, $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Reads an option as an integer.
	 *
	 * @param string $key     Option key.
	 * @param int    $fallback Value returned when nothing is stored.
	 * @return int
	 */
	public static function get_int( string $key, int $fallback = 0 ): int {
		$value = self::get( $key, $fallback );

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Reads an option as a boolean.
	 *
	 * @param string $key     Option key.
	 * @param bool   $fallback Value returned when nothing is stored.
	 * @return bool
	 */
	public static function get_bool( string $key, bool $fallback = false ): bool {
		$value = self::get( $key, $fallback ? '1' : '0' );

		return in_array( $value, array( true, 1, '1', 'yes', 'true' ), true );
	}

	/**
	 * Reads an option as an array.
	 *
	 * @param string       $key     Option key.
	 * @param array<mixed> $fallback Value returned when nothing is stored.
	 * @return array<mixed>
	 */
	public static function get_array( string $key, array $fallback = array() ): array {
		$value = self::get( $key, $fallback );

		return is_array( $value ) ? $value : $fallback;
	}

	/**
	 * Writes an option and records it in the registry.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress should autoload the option.
	 * @return bool
	 */
	public static function set( string $key, $value, bool $autoload = false ): bool {
		$name = self::key( $key );

		self::remember( $name );

		return update_option( $name, $value, $autoload );
	}

	/**
	 * Deletes an option.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function delete( string $key ): bool {
		$name = self::key( $key );

		$registry = self::registry();
		$index    = array_search( $name, $registry, true );

		if ( false !== $index ) {
			unset( $registry[ $index ] );
			update_option( self::REGISTRY, array_values( $registry ), false );
		}

		return delete_option( $name );
	}

	/**
	 * Returns every option name the plugin has written.
	 *
	 * @return string[]
	 */
	public static function registry(): array {
		$registry = get_option( self::REGISTRY, array() );

		return is_array( $registry ) ? array_values( array_filter( $registry, 'is_string' ) ) : array();
	}

	/**
	 * Normalises a key so it always carries the plugin prefix.
	 *
	 * @param string $key Option key.
	 * @return string
	 */
	public static function key( string $key ): string {
		$key = sanitize_key( $key );

		return 0 === strpos( $key, self::PREFIX ) ? $key : self::PREFIX . $key;
	}

	/**
	 * Adds an option name to the registry.
	 *
	 * @param string $name Fully prefixed option name.
	 * @return void
	 */
	private static function remember( string $name ): void {
		$registry = self::registry();

		if ( in_array( $name, $registry, true ) ) {
			return;
		}

		$registry[] = $name;
		update_option( self::REGISTRY, $registry, false );
	}
}
