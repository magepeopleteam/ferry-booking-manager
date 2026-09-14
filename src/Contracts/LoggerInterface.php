<?php
/**
 * Logger contract.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight PSR-3 inspired logger contract.
 */
interface LoggerInterface {

	public const EMERGENCY = 'emergency';
	public const ALERT     = 'alert';
	public const CRITICAL  = 'critical';
	public const ERROR     = 'error';
	public const WARNING   = 'warning';
	public const NOTICE    = 'notice';
	public const INFO      = 'info';
	public const DEBUG     = 'debug';

	/**
	 * Writes a log record.
	 *
	 * @param string               $level   One of the level constants.
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context. Secrets are redacted.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void;

	/**
	 * Writes an error record.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void;

	/**
	 * Writes a warning record.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Writes an informational record.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Writes a debug record. Only persisted while debug logging is enabled.
	 *
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void;
}
