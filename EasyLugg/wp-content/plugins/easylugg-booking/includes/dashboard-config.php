<?php
/**
 * Dashboard Settings (Admin only): Pricing, Hours & Slots, Operations & Capacity,
 * Hub & Meeting point. SRS refs: CFG-01..08.
 *
 * Option names: prices stay in `easylugg_booking_prices` (unchanged so live
 * prices carry over); everything else lives in `easylugg_booking_ops_config`.
 * Empty/missing values fall back to today's hard-coded behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Dashboard_Config {

	const OPTION        = 'easylugg_booking_ops_config';
	const PRICE_CEILING = 100000000; // SRS P-8: owner may lower this later.

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'airport_slots'  => array( '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '19:00', '19:30', '20:00' ),
			'storage_open'   => '08:00',
			'storage_close'  => '20:00',
			'buffer_minutes' => 30,
			'hh_cutoff'      => '11:00',
			'van_capacity'   => 20,
			'warn_percent'   => 80,
			'loops_per_day'  => 2,
			// Empty = keep the translated default from texts.php.
			'hub_name'       => '',
			'hub_address'    => '',
			'hub_hours'      => '',
			'airport_point'  => '',
		);
	}

	/**
	 * Saved config merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	/**
	 * @return array<int, string>
	 */
	public static function airport_slots() {
		$slots = self::get()['airport_slots'];
		return is_array( $slots ) && $slots ? array_values( $slots ) : self::defaults()['airport_slots'];
	}

	/**
	 * 30-minute steps from opening to closing time, both inclusive.
	 *
	 * @return array<int, string>
	 */
	public static function storage_slots() {
		$cfg   = self::get();
		$open  = self::minutes( $cfg['storage_open'] );
		$close = self::minutes( $cfg['storage_close'] );
		if ( null === $open || null === $close || $close <= $open ) {
			$open  = 8 * 60;
			$close = 20 * 60;
		}
		$slots = array();
		for ( $m = $open; $m <= $close; $m += 30 ) {
			$slots[] = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
		}
		return $slots;
	}

	/**
	 * @return int
	 */
	public static function buffer_minutes() {
		return (int) self::get()['buffer_minutes'];
	}

	/**
	 * @return string
	 */
	public static function hh_cutoff() {
		return (string) self::get()['hh_cutoff'];
	}

	/**
	 * @param string $time HH:MM.
	 * @return int|null Minutes since midnight, null when invalid.
	 */
	private static function minutes( $time ) {
		if ( ! is_string( $time ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/**
	 * Validate one settings group. Pure of side effects (no writes).
	 *
	 * @param string               $group pricing|hours|capacity|hub.
	 * @param array<string, mixed> $in    Unslashed input.
	 * @return array{clean: array<string, mixed>, error: string}
	 */
	public static function validate_group( $group, $in ) {
		$scalar = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
		};

		if ( 'pricing' === $group ) {
			$clean = array();
			$src   = isset( $in['prices'] ) && is_array( $in['prices'] ) ? $in['prices'] : array();
			foreach ( array( 'cabin', 'medium', 'oversize', 'storage' ) as $key ) {
				$value = isset( $src[ $key ] ) && is_scalar( $src[ $key ] ) ? trim( (string) $src[ $key ] ) : '';
				if ( '' === $value || ! ctype_digit( $value ) || (int) $value > self::PRICE_CEILING || 0 !== (int) $value % 1000 ) {
					return array( 'clean' => array(), 'error' => 'price_invalid' );
				}
				$clean[ $key ] = (int) $value;
			}
			return array( 'clean' => $clean, 'error' => '' );
		}

		if ( 'hours' === $group ) {
			$raw   = preg_split( '/[\s,;]+/', $scalar( 'airport_slots' ), -1, PREG_SPLIT_NO_EMPTY );
			$slots = array();
			foreach ( $raw as $slot ) {
				if ( null === self::minutes( $slot ) ) {
					return array( 'clean' => array(), 'error' => 'slot_invalid' );
				}
				$slots[ $slot ] = $slot;
			}
			if ( ! $slots ) {
				return array( 'clean' => array(), 'error' => 'slot_invalid' );
			}
			$slots = array_values( $slots );
			sort( $slots );
			$open  = self::minutes( $scalar( 'storage_open' ) );
			$close = self::minutes( $scalar( 'storage_close' ) );
			if ( null === $open || null === $close || $close <= $open || 0 !== $open % 30 || 0 !== $close % 30 ) {
				return array( 'clean' => array(), 'error' => 'hours_invalid' );
			}
			$buffer = $scalar( 'buffer_minutes' );
			if ( ! ctype_digit( $buffer ) || (int) $buffer > 240 ) {
				return array( 'clean' => array(), 'error' => 'buffer_invalid' );
			}
			if ( null === self::minutes( $scalar( 'hh_cutoff' ) ) ) {
				return array( 'clean' => array(), 'error' => 'cutoff_invalid' );
			}
			return array(
				'clean' => array(
					'airport_slots'  => $slots,
					'storage_open'   => $scalar( 'storage_open' ),
					'storage_close'  => $scalar( 'storage_close' ),
					'buffer_minutes' => (int) $buffer,
					'hh_cutoff'      => $scalar( 'hh_cutoff' ),
				),
				'error' => '',
			);
		}

		if ( 'capacity' === $group ) {
			$limits = array(
				'van_capacity'  => array( 1, 500 ),
				'warn_percent'  => array( 1, 100 ),
				'loops_per_day' => array( 1, 6 ),
			);
			$clean  = array();
			foreach ( $limits as $key => $range ) {
				$value = $scalar( $key );
				if ( ! ctype_digit( $value ) || (int) $value < $range[0] || (int) $value > $range[1] ) {
					return array( 'clean' => array(), 'error' => 'capacity_invalid' );
				}
				$clean[ $key ] = (int) $value;
			}
			return array( 'clean' => $clean, 'error' => '' );
		}

		if ( 'hub' === $group ) {
			$max   = array( 'hub_name' => 190, 'hub_address' => 300, 'hub_hours' => 100, 'airport_point' => 190 );
			$clean = array();
			foreach ( $max as $key => $limit ) {
				$value = sanitize_text_field( $scalar( $key ) );
				if ( strlen( $value ) > $limit ) {
					return array( 'clean' => array(), 'error' => 'hub_invalid' );
				}
				$clean[ $key ] = $value;
			}
			return array( 'clean' => $clean, 'error' => '' );
		}

		return array( 'clean' => array(), 'error' => 'unknown_group' );
	}

	/**
	 * Persist a validated group and write one audit row per changed field (CFG-07).
	 *
	 * @param EasyLugg_Booking_Plugin $plugin Plugin instance.
	 * @param string                  $group  Group key.
	 * @param array<string, mixed>    $clean  Validated values.
	 * @return int Number of changed fields.
	 */
	public static function save_group( $plugin, $group, $clean ) {
		$changed = 0;

		if ( 'pricing' === $group ) {
			$old = $plugin->prices();
			$new = $plugin->sanitize_prices( $clean );
			foreach ( $new as $key => $value ) {
				if ( (int) $old[ $key ] !== (int) $value ) {
					EasyLugg_Dashboard_Setup::audit( 'config', EasyLugg_Booking_Plugin::PRICES_OPTION . '.' . $key, 'config_change', array( 'meta' => array( 'old' => (int) $old[ $key ], 'new' => (int) $value ) ) );
					$changed++;
				}
			}
			update_option( EasyLugg_Booking_Plugin::PRICES_OPTION, $new );
			return $changed;
		}

		$current = self::get();
		$next    = $current;
		foreach ( $clean as $key => $value ) {
			if ( $current[ $key ] !== $value ) {
				EasyLugg_Dashboard_Setup::audit(
					'config',
					self::OPTION . '.' . $key,
					'config_change',
					array(
						'meta' => array(
							'old' => $current[ $key ],
							'new' => $value,
						),
					)
				);
				$changed++;
			}
			$next[ $key ] = $value;
		}
		update_option( self::OPTION, $next );
		return $changed;
	}

	/**
	 * Recent config changes for display under the form.
	 *
	 * @param int $limit Rows.
	 * @return array<int, object>
	 */
	public static function recent_changes( $limit = 30 ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . "easylugg_audit_log WHERE entity_type = 'config' ORDER BY id DESC LIMIT %d",
				(int) $limit
			)
		);
	}

	/**
	 * Override translated hub strings with configured values (CFG-04).
	 *
	 * @param array<string, mixed> $texts Texts array.
	 * @return array<string, mixed>
	 */
	public static function apply_hub_texts( $texts ) {
		$cfg = self::get();
		foreach ( array( 'hub_name', 'hub_address', 'hub_hours' ) as $key ) {
			if ( '' !== $cfg[ $key ] && isset( $texts['storage'] ) ) {
				$texts['storage'][ $key ] = $cfg[ $key ];
			}
		}
		return $texts;
	}
}
