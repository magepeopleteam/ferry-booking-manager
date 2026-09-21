<?php
/**
 * One configurable setting.
 *
 * @package FerryBookingManager
 */

declare( strict_types=1 );

namespace MPFBS\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A single settings field, declared once and used everywhere.
 *
 * The Settings screen used to hand-write its tabs in TypeScript while the store
 * hand-wrote a matching sanitiser for each key. Two lists of the same thing
 * drift: a field gets renamed on one side, or a default is written twice and the
 * screen starts disagreeing with what the plugin actually does. Declaring the
 * field once and deriving the default, the sanitiser, the REST schema and the
 * rendered control from that declaration removes the opportunity.
 *
 * It also means an extension can add a whole settings tab without the
 * dashboard bundle being rebuilt, because the screen renders whatever
 * description it is given rather than a layout compiled into JavaScript.
 */
final class SettingField {

	public const TYPE_TEXT     = 'text';
	public const TYPE_TEXTAREA = 'textarea';
	public const TYPE_EMAIL    = 'email';
	public const TYPE_URL      = 'url';
	public const TYPE_TEL      = 'tel';
	public const TYPE_NUMBER   = 'number';
	public const TYPE_SELECT   = 'select';
	public const TYPE_SWITCH   = 'switch';
	public const TYPE_COLOR    = 'color';
	public const TYPE_CURRENCY = 'currency';
	public const TYPE_SLUG     = 'slug';
	public const TYPE_KEY      = 'key';
	public const TYPE_PERCENT  = 'percent';

	/**
	 * Storage key.
	 *
	 * @var string
	 */
	public string $key;

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Human label.
	 *
	 * @var string
	 */
	public string $label = '';

	/**
	 * Explanatory text shown under the control.
	 *
	 * @var string
	 */
	public string $help = '';

	/**
	 * Placeholder, for text-like controls.
	 *
	 * @var string
	 */
	public string $placeholder = '';

	/**
	 * Suffix rendered beside a number, such as a unit.
	 *
	 * @var string
	 */
	public string $unit = '';

	/**
	 * Default value.
	 *
	 * @var mixed
	 */
	public $default = '';

	/**
	 * Select options, as value => label.
	 *
	 * @var array<string, string>
	 */
	public array $options = array();

	/**
	 * Lower bound for numbers.
	 *
	 * @var int
	 */
	public int $min = 0;

	/**
	 * Upper bound for numbers.
	 *
	 * @var int
	 */
	public int $max = 0;

	/**
	 * Increment for a numeric control.
	 *
	 * @var float
	 */
	public float $step = 1.0;

	/**
	 * Whether the field spans the full width of its section.
	 *
	 * @var bool
	 */
	public bool $wide = false;

	/**
	 * Constructor.
	 *
	 * @param string $key  Storage key.
	 * @param string $type Control type.
	 */
	private function __construct( string $key, string $type ) {
		$this->key  = $key;
		$this->type = $type;
	}

	/**
	 * Starts a field declaration.
	 *
	 * @param string $key  Storage key.
	 * @param string $type Control type.
	 * @return self
	 */
	public static function make( string $key, string $type = self::TYPE_TEXT ): self {
		$field = new self( $key, $type );

		// A rate of 22.5% is ordinary, so a percentage accepts decimals unless
		// the declaration narrows it.
		if ( self::TYPE_PERCENT === $type ) {
			$field->step = 0.01;
		}

		return $field;
	}

	/**
	 * Sets the label.
	 *
	 * @param string $label Label.
	 * @return self
	 */
	public function label( string $label ): self {
		$this->label = $label;

		return $this;
	}

	/**
	 * Sets the help text.
	 *
	 * @param string $help Help text.
	 * @return self
	 */
	public function help( string $help ): self {
		$this->help = $help;

		return $this;
	}

	/**
	 * Sets the placeholder.
	 *
	 * @param string $placeholder Placeholder.
	 * @return self
	 */
	public function placeholder( string $placeholder ): self {
		$this->placeholder = $placeholder;

		return $this;
	}

	/**
	 * Sets the unit suffix.
	 *
	 * @param string $unit Unit.
	 * @return self
	 */
	public function unit( string $unit ): self {
		$this->unit = $unit;

		return $this;
	}

	/**
	 * Sets the default value.
	 *
	 * @param mixed $value Default.
	 * @return self
	 */
	public function default_to( $value ): self {
		$this->default = $value;

		return $this;
	}

	/**
	 * Sets the select options.
	 *
	 * @param array<string, string> $options Options as value => label.
	 * @return self
	 */
	public function options( array $options ): self {
		$this->options = $options;

		return $this;
	}

	/**
	 * Sets the numeric bounds.
	 *
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 * @return self
	 */
	public function range( int $min, int $max ): self {
		$this->min = $min;
		$this->max = $max;

		return $this;
	}

	/**
	 * Sets the increment for a numeric control.
	 *
	 * @param float $step Increment.
	 * @return self
	 */
	public function step( float $step ): self {
		$this->step = $step;

		return $this;
	}

	/**
	 * Marks the field as full width.
	 *
	 * @return self
	 */
	public function wide(): self {
		$this->wide = true;

		return $this;
	}

	/**
	 * Sanitises a submitted value.
	 *
	 * The current value is passed in so that a field the payload does not
	 * mention keeps what it already had. The Settings screen submits one tab at
	 * a time, so treating an absent key as "empty" would mean saving the General
	 * tab wiped every email preference.
	 *
	 * @param array<string, mixed> $input   Submitted payload.
	 * @param mixed                $current Currently stored value.
	 * @return mixed
	 */
	public function sanitize( array $input, $current ) {
		if ( self::TYPE_SWITCH === $this->type ) {
			// A checkbox is absent from the payload when it is unticked in some
			// clients and present-but-false in others, so only an explicit
			// mention may change it.
			if ( ! array_key_exists( $this->key, $input ) ) {
				return (bool) $current;
			}

			return in_array( $input[ $this->key ], array( true, 1, '1', 'yes', 'true', 'on' ), true );
		}

		if ( ! array_key_exists( $this->key, $input ) ) {
			return $current;
		}

		$value = $input[ $this->key ];

		switch ( $this->type ) {
			case self::TYPE_TEXTAREA:
				return sanitize_textarea_field( (string) $value );

			case self::TYPE_EMAIL:
				return sanitize_email( (string) $value );

			case self::TYPE_URL:
				return esc_url_raw( (string) $value );

			case self::TYPE_NUMBER:
				return $this->bounded( $value, $current );

			case self::TYPE_PERCENT:
				return $this->percentage( $value, $current );

			case self::TYPE_SELECT:
				$candidate = (string) $value;

				return isset( $this->options[ $candidate ] ) ? $candidate : (string) $current;

			case self::TYPE_COLOR:
				$colour = sanitize_hex_color( (string) $value );

				return is_string( $colour ) && '' !== $colour ? $colour : (string) $current;

			case self::TYPE_CURRENCY:
				$code = strtoupper( sanitize_key( (string) $value ) );

				return 3 === strlen( $code ) ? $code : '';

			case self::TYPE_SLUG:
				return strtoupper( sanitize_key( (string) $value ) );

			case self::TYPE_KEY:
				return sanitize_key( (string) $value );

			case self::TYPE_TEL:
			case self::TYPE_TEXT:
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Clamps a number into the declared range.
	 *
	 * @param mixed $value   Raw value.
	 * @param mixed $current Currently stored value.
	 * @return int
	 */
	private function bounded( $value, $current ): int {
		if ( ! is_numeric( $value ) ) {
			return (int) $current;
		}

		return max( $this->min, min( $this->max, (int) $value ) );
	}

	/**
	 * Reads a percentage, kept to two decimal places.
	 *
	 * @param mixed $value   Raw value.
	 * @param mixed $current Currently stored value.
	 * @return float
	 */
	private function percentage( $value, $current ): float {
		if ( ! is_numeric( $value ) ) {
			return (float) $current;
		}

		return round( max( 0.0, min( 100.0, (float) $value ) ), 2 );
	}

	/**
	 * Describes the field for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		$description = array(
			'key'   => $this->key,
			'type'  => $this->type,
			'label' => $this->label,
		);

		if ( '' !== $this->help ) {
			$description['help'] = $this->help;
		}

		if ( '' !== $this->placeholder ) {
			$description['placeholder'] = $this->placeholder;
		}

		if ( '' !== $this->unit ) {
			$description['unit'] = $this->unit;
		}

		if ( array() !== $this->options ) {
			$options = array();

			foreach ( $this->options as $value => $label ) {
				$options[] = array(
					'value' => (string) $value,
					'label' => (string) $label,
				);
			}

			$description['options'] = $options;
		}

		if ( self::TYPE_NUMBER === $this->type || self::TYPE_PERCENT === $this->type ) {
			$description['min']  = $this->min;
			$description['max']  = $this->max;
			$description['step'] = $this->step;
		}

		if ( $this->wide ) {
			$description['wide'] = true;
		}

		return $description;
	}
}
