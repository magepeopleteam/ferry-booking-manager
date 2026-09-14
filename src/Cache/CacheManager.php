<?php
/**
 * Cache manager.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Cache;

use FBM\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Group-aware cache facade built on the WordPress object cache and transients.
 *
 * Because the plugin may not create database tables, expensive aggregate reads
 * (availability summaries, dashboard totals, route search) are cached here and
 * invalidated by bumping a group version, which avoids scanning the options
 * table for stale transient rows.
 */
final class CacheManager {

	public const GROUP_AVAILABILITY = 'availability';
	public const GROUP_PRICING      = 'pricing';
	public const GROUP_SEARCH       = 'search';
	public const GROUP_DASHBOARD    = 'dashboard';
	public const GROUP_ENTITIES     = 'entities';

	/**
	 * Object cache group used for the in-request/persistent object cache.
	 */
	private const OBJECT_GROUP = 'fbm';

	/**
	 * Per-request memo of group versions.
	 *
	 * @var array<string, int>
	 */
	private array $versions = array();

	/**
	 * Reads a cached value.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Cache key within the group.
	 * @param mixed  $fallback Value returned on a miss.
	 * @return mixed
	 */
	public function get( string $group, string $key, $fallback = null ) {
		$name  = $this->name( $group, $key );
		$found = false;
		$value = wp_cache_get( $name, self::OBJECT_GROUP, false, $found );

		if ( $found ) {
			return $value;
		}

		$value = get_transient( $name );

		if ( false === $value ) {
			return $fallback;
		}

		wp_cache_set( $name, $value, self::OBJECT_GROUP, MINUTE_IN_SECONDS );

		return $value;
	}

	/**
	 * Writes a cached value.
	 *
	 * @param string $group      Cache group.
	 * @param string $key        Cache key within the group.
	 * @param mixed  $value      Value to store. Must not be boolean false.
	 * @param int    $expiration Lifetime in seconds.
	 * @return void
	 */
	public function set( string $group, string $key, $value, int $expiration = 300 ): void {
		$expiration = $this->lifetime( $group, $expiration );

		if ( false === $value ) {
			return;
		}

		// A lifetime of zero means the operator turned caching off. Storing it
		// anyway would be worse than not caching: a persistent object cache
		// reads zero as "keep for ever".
		if ( $expiration < 1 ) {
			return;
		}

		$name = $this->name( $group, $key );

		set_transient( $name, $value, $expiration );
		wp_cache_set( $name, $value, self::OBJECT_GROUP, min( $expiration, MINUTE_IN_SECONDS ) );
	}

	/**
	 * Returns a cached value, computing and storing it on a miss.
	 *
	 * @param string   $group      Cache group.
	 * @param string   $key        Cache key within the group.
	 * @param callable $callback   Producer invoked on a miss.
	 * @param int      $expiration Lifetime in seconds.
	 * @return mixed
	 */
	public function remember( string $group, string $key, callable $callback, int $expiration = 300 ) {
		$expiration = $this->lifetime( $group, $expiration );

		$sentinel = '__fbm_miss__';
		$value    = $this->get( $group, $key, $sentinel );

		if ( $sentinel !== $value ) {
			return $value;
		}

		$value = $callback();

		$this->set( $group, $key, $value, $expiration );

		return $value;
	}

	/**
	 * Deletes a single cached value.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Cache key within the group.
	 * @return void
	 */
	public function forget( string $group, string $key ): void {
		$name = $this->name( $group, $key );

		delete_transient( $name );
		wp_cache_delete( $name, self::OBJECT_GROUP );
	}

	/**
	 * Invalidates an entire cache group by bumping its version.
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	public function flush_group( string $group ): void {
		$group   = $this->normalise_group( $group );
		$option  = 'fbm_cache_version_' . $group;
		$version = $this->version( $group ) + 1;

		update_option( $option, $version, true );
		$this->versions[ $group ] = $version;

		/**
		 * Fires after a Ferry Booking Manager cache group is invalidated.
		 *
		 * @since 1.0.0
		 *
		 * @param string $group   Cache group slug.
		 * @param int    $version New group version.
		 */
		do_action( 'fbm_cache_group_flushed', $group, $version );
	}

	/**
	 * Invalidates every cache group.
	 *
	 * @return void
	 */
	public function flush_all(): void {
		foreach ( $this->groups() as $group ) {
			$this->flush_group( $group );
		}
	}

	/**
	 * Returns the known cache groups.
	 *
	 * @return string[]
	 */
	public function groups(): array {
		$groups = array(
			self::GROUP_AVAILABILITY,
			self::GROUP_PRICING,
			self::GROUP_SEARCH,
			self::GROUP_DASHBOARD,
			self::GROUP_ENTITIES,
		);

		/**
		 * Filters the cache groups Ferry Booking Manager knows about.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $groups Group slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'fbm_cache_groups', $groups ) ) );
	}

	/**
	 * Resolves how long an entry should live.
	 *
	 * The operator sets one window on the Advanced tab. It is safe to make it
	 * long: every group is versioned and bumped the moment a booking, sailing or
	 * price changes, so a stale entry is discarded rather than served. The
	 * dashboard keeps the window its caller asked for, because its figures are a
	 * summary that nothing invalidates.
	 *
	 * @param string $group      Cache group.
	 * @param int    $expiration Caller's default, in seconds.
	 * @return int
	 */
	private function lifetime( string $group, int $expiration ): int {
		if ( self::GROUP_DASHBOARD === $this->normalise_group( $group ) ) {
			return $expiration;
		}

		$configured = Settings::all()['cache_seconds'] ?? null;

		if ( null !== $configured ) {
			$expiration = max( 0, (int) $configured );
		}

		/**
		 * Filters how long a cached entry lives.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $expiration Seconds, where 0 means do not cache.
		 * @param string $group      Cache group.
		 */
		return (int) apply_filters( 'fbm_cache_lifetime', $expiration, $group );
	}

	/**
	 * Builds a fully qualified, version-scoped cache key.
	 *
	 * Transient names are capped at 172 characters, so long keys are hashed.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Cache key within the group.
	 * @return string
	 */
	private function name( string $group, string $key ): string {
		$group = $this->normalise_group( $group );
		$name  = sprintf( 'fbm_%s_%d_%s', $group, $this->version( $group ), $key );

		if ( strlen( $name ) > 140 ) {
			$name = sprintf( 'fbm_%s_%d_%s', $group, $this->version( $group ), md5( $key ) );
		}

		return $name;
	}

	/**
	 * Returns the current version number for a group.
	 *
	 * @param string $group Normalised cache group.
	 * @return int
	 */
	private function version( string $group ): int {
		if ( isset( $this->versions[ $group ] ) ) {
			return $this->versions[ $group ];
		}

		$version = (int) get_option( 'fbm_cache_version_' . $group, 1 );

		if ( $version < 1 ) {
			$version = 1;
		}

		$this->versions[ $group ] = $version;

		return $version;
	}

	/**
	 * Normalises a group slug.
	 *
	 * @param string $group Raw group slug.
	 * @return string
	 */
	private function normalise_group( string $group ): string {
		$group = sanitize_key( $group );

		return '' === $group ? 'default' : $group;
	}
}
