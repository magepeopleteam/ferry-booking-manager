<?php
/**
 * Payment gateway registry.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace FBM\Payment;

use FBM\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every gateway the booking flow may offer.
 *
 * The Free plugin registers its four offline gateways here; Pro and third
 * parties add theirs through the `fbm_payment_gateways` filter. The registry
 * also owns the availability switches an operator toggles in Settings, so a
 * disabled gateway is invisible everywhere — the checkout, the counter and
 * the REST API all ask this class.
 */
final class PaymentGatewayRegistry {

	/**
	 * Option holding which gateways the operator has turned on.
	 */
	private const ENABLED_OPTION = 'payment_gateways';

	/**
	 * Registered gateways, keyed by id.
	 *
	 * @var array<string, PaymentGatewayInterface>
	 */
	private array $gateways = array();

	/**
	 * Whether the registry has been populated.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Loads the registry once.
	 *
	 * @return void
	 */
	public function load(): void {
		if ( $this->loaded ) {
			return;
		}

		$this->loaded = true;

		$gateways = array(
			new Gateways\Cash(),
			new Gateways\BankTransfer(),
			new Gateways\PayAtPort(),
			new Gateways\Manual(),
		);

		/**
		 * Filters the payment gateways available to bookings.
		 *
		 * Pro registers Stripe, PayPal, Mollie and regional gateways here,
		 * each implementing {@see PaymentGatewayInterface}.
		 *
		 * @since 1.0.0
		 *
		 * @param PaymentGatewayInterface[] $gateways Gateways in offer order.
		 */
		$gateways = (array) apply_filters( 'fbm_payment_gateways', $gateways );

		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof PaymentGatewayInterface ) {
				$this->gateways[ $gateway->get_id() ] = $gateway;
			}
		}
	}

	/**
	 * Returns every registered gateway, whether enabled or not.
	 *
	 * @return array<string, PaymentGatewayInterface>
	 */
	public function all(): array {
		$this->load();

		return $this->gateways;
	}

	/**
	 * Returns the gateways the operator has turned on.
	 *
	 * @return array<string, PaymentGatewayInterface>
	 */
	public function enabled(): array {
		$enabled = array();

		foreach ( $this->all() as $id => $gateway ) {
			if ( $this->is_enabled( $id ) && $gateway->is_available() ) {
				$enabled[ $id ] = $gateway;
			}
		}

		return $enabled;
	}

	/**
	 * Resolves one gateway, or null when it is not registered or disabled.
	 *
	 * @param string $id Gateway id.
	 * @return PaymentGatewayInterface|null
	 */
	public function get( string $id ): ?PaymentGatewayInterface {
		$this->load();

		if ( ! isset( $this->gateways[ $id ] ) || ! $this->is_enabled( $id ) || ! $this->gateways[ $id ]->is_available() ) {
			return null;
		}

		return $this->gateways[ $id ];
	}

	/**
	 * Determines whether a gateway is turned on.
	 *
	 * @param string $id Gateway id.
	 * @return bool
	 */
	public function is_enabled( string $id ): bool {
		$stored = Options::get_array( self::ENABLED_OPTION );

		// A fresh install has every offline gateway on.
		return array() === $stored ? true : ! empty( $stored[ $id ] );
	}

	/**
	 * Stores the enabled set.
	 *
	 * @param array<string, mixed> $input Gateway id => enabled flag.
	 * @return array<string, bool> The stored state.
	 */
	public function save_enabled( array $input ): array {
		$clean = array();

		foreach ( $this->all() as $id => $gateway ) {
			unset( $gateway );

			/*
			 * A gateway the payload does not mention keeps whatever it had.
			 * Treating absent as "off" would mean a screen that submits only
			 * the methods it happens to show could switch off the one the site
			 * actually takes money with.
			 */
			$clean[ $id ] = array_key_exists( $id, $input )
				? in_array( $input[ $id ], array( true, 1, '1', 'yes', 'true', 'on' ), true )
				: $this->is_enabled( $id );
		}

		Options::set( self::ENABLED_OPTION, $clean );

		return $clean;
	}

	/**
	 * Returns the gateways the booking flow may offer, in offer order.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function offer(): array {
		$offer = array();

		foreach ( $this->enabled() as $gateway ) {
			$offer[] = array(
				'id'          => $gateway->get_id(),
				'label'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
			);
		}

		return $offer;
	}

	/**
	 * Returns the operator's chosen default gateway id.
	 *
	 * Falls back to the first enabled gateway.
	 *
	 * @return string
	 */
	public function default_id(): string {
		$settings = Options::get_array( 'settings' );
		$default  = isset( $settings['default_payment_method'] ) ? (string) $settings['default_payment_method'] : '';

		if ( '' !== $default && null !== $this->get( $default ) ) {
			return $default;
		}

		$enabled = $this->enabled();

		return array() === $enabled ? '' : (string) array_key_first( $enabled );
	}
}
