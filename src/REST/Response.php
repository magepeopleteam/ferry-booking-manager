<?php
/**
 * REST response envelope.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\REST;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the single response shape used by every `fbm/v1` endpoint.
 *
 * Success: { success: true, data: {...}, meta: {...} }
 * Failure: { success: false, code: "...", message: "...", data: {...} }
 */
final class Response {

	/**
	 * Builds a success envelope.
	 *
	 * @param mixed                $data   Payload.
	 * @param array<string, mixed> $meta   Optional meta (pagination, totals).
	 * @param int                  $status HTTP status code.
	 * @return WP_REST_Response
	 */
	public static function success( $data = null, array $meta = array(), int $status = 200 ): WP_REST_Response {
		$body = array(
			'success' => true,
			'data'    => null === $data ? new \stdClass() : $data,
		);

		if ( array() !== $meta ) {
			$body['meta'] = $meta;
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Builds a paginated success envelope.
	 *
	 * @param array<mixed>         $items    Page of items.
	 * @param int                  $total    Total matching items.
	 * @param int                  $page     Current page, 1-based.
	 * @param int                  $per_page Items per page.
	 * @param array<string, mixed> $extra    Additional meta.
	 * @return WP_REST_Response
	 */
	public static function collection( array $items, int $total, int $page, int $per_page, array $extra = array() ): WP_REST_Response {
		$per_page = max( 1, $per_page );

		$meta = array_merge(
			array(
				'page'        => max( 1, $page ),
				'per_page'    => $per_page,
				'total'       => max( 0, $total ),
				'total_pages' => (int) ceil( max( 0, $total ) / $per_page ),
			),
			$extra
		);

		$response = self::success( array_values( $items ), $meta );
		$response->header( 'X-FBM-Total', (string) max( 0, $total ) );
		$response->header( 'X-FBM-Total-Pages', (string) $meta['total_pages'] );

		return $response;
	}

	/**
	 * Builds an error envelope.
	 *
	 * @param string               $code    Machine readable, `fbm_`-prefixed error code.
	 * @param string               $message Human readable, translated message.
	 * @param array<string, mixed> $data    Additional error context (never secrets).
	 * @param int                  $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	public static function error( string $code, string $message, array $data = array(), int $status = 400 ): WP_REST_Response {
		unset( $data['status'] );

		$body = array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
			'data'    => (object) $data,
		);

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Converts a WP_Error into the plugin error envelope.
	 *
	 * @param WP_Error $error Error object.
	 * @return WP_REST_Response
	 */
	public static function from_wp_error( WP_Error $error ): WP_REST_Response {
		$code    = (string) $error->get_error_code();
		$data    = $error->get_error_data();
		$data    = is_array( $data ) ? $data : array();
		$status  = isset( $data['status'] ) ? (int) $data['status'] : 400;
		$message = (string) $error->get_error_message();

		if ( '' === $code ) {
			$code = 'fbm_error';
		}

		return self::error( $code, $message, $data, $status );
	}

	/**
	 * Normalises any dispatch result into the plugin envelope.
	 *
	 * Used by the `rest_post_dispatch` hook so that failures raised by WordPress
	 * itself (permission callbacks, argument validation) keep the same shape.
	 *
	 * @param WP_HTTP_Response|WP_Error|mixed $result Dispatch result.
	 * @return WP_HTTP_Response|mixed
	 */
	public static function normalize( $result ) {
		if ( $result instanceof WP_Error ) {
			return self::from_wp_error( $result );
		}

		if ( ! $result instanceof WP_HTTP_Response ) {
			return $result;
		}

		$data = $result->get_data();

		if ( is_array( $data ) && array_key_exists( 'success', $data ) ) {
			return $result;
		}

		$status = $result->get_status();

		if ( $status >= 400 ) {
			$code    = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'fbm_error';
			$message = is_array( $data ) && isset( $data['message'] )
				? (string) $data['message']
				: __( 'The request could not be completed.', 'magepeople-ferry-booking-system' );
			$extra   = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

			if ( 0 !== strpos( $code, 'fbm_' ) ) {
				$code = 'fbm_' . ltrim( $code, '_' );
			}

			$normalized = self::error( $code, $message, $extra, $status );
		} else {
			$normalized = self::success( $data, array(), $status );
		}

		foreach ( $result->get_headers() as $header => $value ) {
			$normalized->header( $header, $value );
		}

		return $normalized;
	}
}
