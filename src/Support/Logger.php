<?php
/**
 * File logger.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Support;

use FBM\Contracts\LoggerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight file logger with redaction, rotation and retention.
 * Logs live in a protected uploads sub-directory whose filenames carry a
 * site-specific hash, so log files are not guessable from the outside.
 */
final class Logger implements LoggerInterface {
	/**
	 * Option holding the per-site filename hash.
	 */
	private const HASH_OPTION = 'fbm_log_hash';

	/**
	 * Option holding the configured minimum level.
	 */
	private const LEVEL_OPTION = 'fbm_log_level';

	/**
	 * Maximum size of a single log file before it is rotated, in bytes.
	 */
	private const MAX_BYTES = 5242880;

	/**
	 * Days of log history to keep.
	 */
	private const RETENTION_DAYS = 30;

	/**
	 * Level severities; higher is more severe.
	 *
	 * @var array<string, int>
	 */
	private const SEVERITY = array(
		self::DEBUG     => 100,
		self::INFO      => 200,
		self::NOTICE    => 250,
		self::WARNING   => 300,
		self::ERROR     => 400,
		self::CRITICAL  => 500,
		self::ALERT     => 550,
		self::EMERGENCY => 600,
	);

	/**
	 * Context keys whose values are never written to disk.
	 *
	 * @var string[]
	 */
	private const REDACTED_KEYS = array(
		'password',
		'pass',
		'pwd',
		'secret',
		'token',
		'access_token',
		'refresh_token',
		'api_key',
		'apikey',
		'key',
		'private_key',
		'authorization',
		'auth',
		'nonce',
		'card',
		'card_number',
		'cardnumber',
		'pan',
		'cvv',
		'cvc',
		'iban',
	);

	/**
	 * Cached minimum severity for this request.
	 *
	 * @var int|null
	 */
	private ?int $threshold = null;

	/**
	 * Cached log directory path.
	 *
	 * @var string|null
	 */
	private ?string $directory = null;

	/**
	 * Writes a log record.
	 *
	 * @param string               $level   One of the level constants.
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context. Secrets are redacted.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! isset( self::SEVERITY[ $level ] ) ) {
			$level = self::INFO;
		}

		if ( self::SEVERITY[ $level ] < $this->threshold() ) {
			return;
		}

		$directory = $this->directory();

		if ( '' === $directory ) {
			return;
		}

		$file = $directory . $this->filename();

		$this->rotate( $file );

		$line = sprintf(
			'[%s] %s: %s%s%s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? ' ' . (string) wp_json_encode( $this->redact( $context ) ) : '',
			PHP_EOL
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Appending with a lock is what makes this safe under concurrency; logging must never break a request.
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Writes an error record.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Writes a warning record.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Writes an informational record.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * Writes a debug record. Only persisted while debug logging is enabled.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::DEBUG, $message, $context );
	}

	/**
	 * Deletes log files older than the retention window.
	 *
	 * @return int Number of files removed.
	 */
	public function purge_expired(): int {
		$directory = $this->directory();

		if ( '' === $directory ) {
			return 0;
		}

		$removed = 0;
		$cutoff  = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$files   = glob( $directory . 'fbm-*.log' );

		foreach ( (array) $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Resolves the minimum severity that gets written.
	 *
	 * @return int
	 */
	private function threshold(): int {
		if ( null !== $this->threshold ) {
			return $this->threshold;
		}

		$level = (string) get_option( self::LEVEL_OPTION, '' );

		if ( '' === $level ) {
			$debug_mode = ( defined( 'FBM_DEBUG' ) && FBM_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
			$level      = $debug_mode ? self::DEBUG : self::ERROR;
		}

		/**
		 * Filters the minimum log level that is persisted.
		 *
		 * Return "off" to disable file logging entirely.
		 *
		 * @since 1.0.0
		 *
		 * @param string $level One of the LoggerInterface level constants, or "off".
		 */
		$level = (string) apply_filters( 'fbm_log_level', $level );

		if ( 'off' === $level ) {
			$this->threshold = PHP_INT_MAX;

			return $this->threshold;
		}

		$this->threshold = self::SEVERITY[ $level ] ?? self::SEVERITY[ self::ERROR ];

		return $this->threshold;
	}

	/**
	 * Returns (and lazily creates) the protected log directory.
	 *
	 * @return string Trailing-slashed path, or an empty string when unavailable.
	 */
	private function directory(): string {
		if ( null !== $this->directory ) {
			return $this->directory;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			$this->directory = '';

			return $this->directory;
		}

		$directory = trailingslashit( $uploads['basedir'] ) . 'fbm-logs/';

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			$this->directory = '';

			return $this->directory;
		}

		$this->protect( $directory );

		$this->directory = $directory;

		return $this->directory;
	}

	/**
	 * Writes the directory protection files once.
	 *
	 * @param string $directory Trailing-slashed directory path.
	 * @return void
	 */
	private function protect( string $directory ): void {
		$htaccess = $directory . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is not available this early, and logging must never break a request.
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		$index = $directory . 'index.html';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is not available this early, and logging must never break a request.
			@file_put_contents( $index, '' );
		}
	}

	/**
	 * Builds today's log filename, including the per-site hash.
	 *
	 * @return string
	 */
	private function filename(): string {
		$hash = (string) get_option( self::HASH_OPTION, '' );

		if ( '' === $hash ) {
			$hash = wp_generate_password( 20, false, false );
			update_option( self::HASH_OPTION, $hash, false );
		}

		return sprintf( 'fbm-%s-%s.log', gmdate( 'Y-m-d' ), $hash );
	}

	/**
	 * Rotates a log file that has grown past the size limit.
	 *
	 * @param string $file Absolute file path.
	 * @return void
	 */
	private function rotate( string $file ): void {
		if ( ! is_file( $file ) || filesize( $file ) < self::MAX_BYTES ) {
			return;
		}

		$rotated = preg_replace( '/\.log$/', '-' . time() . '.log', $file );

		if ( is_string( $rotated ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Rotation is best-effort and must never break a request.
			@rename( $file, $rotated );
		}
	}

	/**
	 * Recursively removes sensitive values from a context array.
	 *
	 * @param array<mixed> $context Context data.
	 * @param int          $depth   Current recursion depth.
	 * @return array<mixed>
	 */
	private function redact( array $context, int $depth = 0 ): array {
		if ( $depth > 6 ) {
			return array( '…' );
		}

		$clean = array();

		foreach ( $context as $key => $value ) {
			$needle = is_string( $key ) ? strtolower( $key ) : '';

			if ( '' !== $needle && $this->is_sensitive( $needle ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->redact( $value, $depth + 1 );
				continue;
			}

			if ( is_object( $value ) ) {
				$clean[ $key ] = get_class( $value );
				continue;
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Determines whether a context key looks sensitive.
	 *
	 * @param string $key Lower-cased context key.
	 * @return bool
	 */
	private function is_sensitive( string $key ): bool {
		foreach ( self::REDACTED_KEYS as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
