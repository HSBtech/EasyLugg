<?php
/**
 * Dashboard business rules — pure functions, no WordPress calls.
 *
 * Everything here takes plain data in and returns plain data out so it can be
 * unit-tested from the CLI (tests/dashboard-rules-test.php) without WordPress.
 * SRS refs: OPS-01..03, OPS-05, OPS-06, OPS-07, EDT-01, TIME-01..08.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Dashboard_Rules {

	const CAP_OPERATE = 'easylugg_operate_booking';
	const CAP_CANCEL  = 'easylugg_cancel_booking';

	/**
	 * Statuses that still need work from staff.
	 *
	 * @return array<int, string>
	 */
	public static function open_statuses() {
		return array( 'requested', 'confirmed', 'collected', 'in_transit' );
	}

	/**
	 * Statuses that can never change again (view only, plus notes).
	 *
	 * @return array<int, string>
	 */
	public static function terminal_statuses() {
		return array( 'completed', 'cancelled', 'no_show' );
	}

	/**
	 * The state machine — the single source of truth (OPS-01).
	 * action => from statuses, resulting status, capability.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function actions() {
		return array(
			'confirm'  => array( 'from' => array( 'requested' ), 'to' => 'confirmed', 'cap' => self::CAP_OPERATE ),
			'checkin'  => array( 'from' => array( 'confirmed' ), 'to' => 'collected', 'cap' => self::CAP_OPERATE ),
			'depart'   => array( 'from' => array( 'collected' ), 'to' => 'in_transit', 'cap' => self::CAP_OPERATE ),
			'deliver'  => array( 'from' => array( 'in_transit' ), 'to' => 'delivered', 'cap' => self::CAP_OPERATE ),
			'complete' => array( 'from' => array( 'delivered' ), 'to' => 'completed', 'cap' => self::CAP_OPERATE ),
			'cancel'   => array( 'from' => array( 'requested', 'confirmed' ), 'to' => 'cancelled', 'cap' => self::CAP_CANCEL ),
			'no_show'  => array( 'from' => array( 'confirmed', 'in_transit' ), 'to' => 'no_show', 'cap' => self::CAP_CANCEL ),
		);
	}

	/**
	 * Whether an action is a legal move from a status (ignores permissions).
	 *
	 * @param string $action Action key.
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function action_valid_from( $action, $status ) {
		$actions = self::actions();
		return isset( $actions[ $action ] ) && in_array( $status, $actions[ $action ]['from'], true );
	}

	/**
	 * Actions a user may press on a booking in this state (DET-03: anything
	 * not returned here must not be rendered).
	 *
	 * @param string             $status Current status.
	 * @param array<int, string> $caps   Capabilities the user holds.
	 * @param bool               $is_paid Whether money was already recorded.
	 * @param string             $cancel_paid_cap Capability needed to cancel a paid order.
	 * @return array<int, string>
	 */
	public static function available_actions( $status, $caps, $is_paid = false, $cancel_paid_cap = 'easylugg_cancel_paid_booking' ) {
		$result = array();
		foreach ( self::actions() as $key => $def ) {
			if ( ! in_array( $status, $def['from'], true ) || ! in_array( $def['cap'], $caps, true ) ) {
				continue;
			}
			if ( 'cancel' === $key && $is_paid && ! in_array( $cancel_paid_cap, $caps, true ) ) {
				continue;
			}
			$result[] = $key;
		}
		return $result;
	}

	/**
	 * Gate checks for one action. Returns the first failing gate key, or ''.
	 * `needs_review` is set when a bag-count mismatch was accepted with a reason.
	 *
	 * Input keys (all optional, missing = falsy):
	 * hotel_confirmed, verified, tag_code, actual_bags, booked_bags, reason,
	 * photo_count, loop, receiver_type, receiver_name, proxy_ok,
	 * payment_recorded, is_cash, invoice_no, note.
	 *
	 * @param string               $action Action key.
	 * @param array<string, mixed> $in     Facts about the request.
	 * @return array{error: string, needs_review: bool}
	 */
	public static function check_gates( $action, $in ) {
		$out = array(
			'error'        => '',
			'needs_review' => false,
		);
		$s   = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
		};
		$b   = function ( $key ) use ( $in ) {
			return ! empty( $in[ $key ] );
		};

		switch ( $action ) {
			case 'confirm':
				if ( ! $b( 'hotel_confirmed' ) ) {
					$out['error'] = 'hotel_confirmed_required';
				}
				break;

			case 'checkin':
				if ( ! $b( 'verified' ) ) {
					$out['error'] = 'verify_required';
				} elseif ( '' === $s( 'tag_code' ) ) {
					$out['error'] = 'tag_required';
				} elseif ( ! isset( $in['actual_bags'] ) || ! is_numeric( $in['actual_bags'] ) || (int) $in['actual_bags'] < 1 ) {
					$out['error'] = 'actual_bags_required';
				} elseif ( (int) $in['actual_bags'] !== (int) ( isset( $in['booked_bags'] ) ? $in['booked_bags'] : 0 ) ) {
					if ( '' === $s( 'reason' ) ) {
						$out['error'] = 'bag_mismatch_reason_required';
					} else {
						$out['needs_review'] = true;
					}
				}
				if ( '' === $out['error'] && (int) ( isset( $in['photo_count'] ) ? $in['photo_count'] : 0 ) < 2 ) {
					$out['error'] = 'checkin_photos_required';
				}
				break;

			case 'depart':
				if ( ! in_array( $s( 'loop' ), array( '1', '2' ), true ) ) {
					$out['error'] = 'loop_required';
				}
				break;

			case 'deliver':
				if ( ! $b( 'verified' ) ) {
					$out['error'] = 'verify_required';
				} elseif ( ! in_array( $s( 'receiver_type' ), array( 'customer', 'reception', 'proxy' ), true ) ) {
					$out['error'] = 'receiver_type_required';
				} elseif ( '' === $s( 'receiver_name' ) ) {
					$out['error'] = 'receiver_name_required';
				} elseif ( 'proxy' === $s( 'receiver_type' ) && ! $b( 'proxy_ok' ) ) {
					$out['error'] = 'proxy_not_registered';
				} elseif ( (int) ( isset( $in['photo_count'] ) ? $in['photo_count'] : 0 ) < 1 ) {
					$out['error'] = 'handover_photo_required';
				}
				break;

			case 'complete':
				if ( ! $b( 'payment_recorded' ) ) {
					$out['error'] = 'payment_required';
				} elseif ( $b( 'is_cash' ) && ! self::valid_invoice_no( $s( 'invoice_no' ) ) ) {
					$out['error'] = 'invoice_required';
				}
				break;

			case 'cancel':
				if ( '' === $s( 'reason' ) ) {
					$out['error'] = 'cancel_reason_required';
				}
				break;

			case 'no_show':
				if ( '' === $s( 'note' ) ) {
					$out['error'] = 'no_show_note_required';
				}
				break;

			default:
				$out['error'] = 'unknown_action';
		}

		return $out;
	}

	/**
	 * @param string $value Invoice number as typed.
	 * @return bool
	 */
	public static function valid_invoice_no( $value ) {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9\/._-]{0,39}$/', (string) $value );
	}

	/**
	 * What may be edited at a given status (EDT-01).
	 * full = all booking fields; contact = contact + note; note = note only.
	 *
	 * @param string $status Booking status.
	 * @return string
	 */
	public static function edit_mode( $status ) {
		if ( in_array( $status, array( 'requested', 'confirmed' ), true ) ) {
			return 'full';
		}
		if ( in_array( $status, array( 'collected', 'in_transit' ), true ) ) {
			return 'contact';
		}
		return 'note';
	}

	/**
	 * Future-time rule (TIME-01..06). Pure: `now` is passed in as site-local
	 * 'Y-m-d H:i'. Returns '' when valid, else an error code:
	 * past_date | too_soon | hh_cutoff.
	 *
	 * @param array<string, string> $service Validated service: service, corridor, service_date, pickup_time,
	 *                                       airport_delivery_time, dropoff_date, dropoff_time.
	 * @param string                $now     Site-local now, 'Y-m-d H:i'.
	 * @param int                   $buffer_minutes Buffer in minutes (one constant for every service).
	 * @param string                $hh_cutoff 'H:i' — HH same-day services must be created before this.
	 * @return string
	 */
	public static function validate_future_time( $service, $now, $buffer_minutes = 30, $hh_cutoff = '11:00' ) {
		$today = substr( $now, 0, 10 );
		$date  = isset( $service['service_date'] ) ? $service['service_date'] : '';
		if ( '' === $date || $date < $today ) {
			return 'past_date';
		}

		// Latest-allowed comparison value: now + buffer, as 'Y-m-d H:i'.
		$limit = gmdate( 'Y-m-d H:i', strtotime( $now . ' UTC' ) + (int) $buffer_minutes * 60 );

		$corridor = isset( $service['corridor'] ) ? $service['corridor'] : '';
		if ( 'AH' === $corridor ) {
			$at = $date . ' ' . ( isset( $service['pickup_time'] ) ? $service['pickup_time'] : '' );
			return $at < $limit ? 'too_soon' : '';
		}
		if ( 'HA' === $corridor ) {
			$at = $date . ' ' . ( isset( $service['airport_delivery_time'] ) ? $service['airport_delivery_time'] : '' );
			return $at < $limit ? 'too_soon' : '';
		}
		if ( 'HH' === $corridor ) {
			if ( $date === $today && substr( $now, 11, 5 ) >= $hh_cutoff ) {
				return 'hh_cutoff';
			}
			return '';
		}
		if ( 'ST' === $corridor ) {
			$at = ( isset( $service['dropoff_date'] ) ? $service['dropoff_date'] : $date ) . ' ' . ( isset( $service['dropoff_time'] ) ? $service['dropoff_time'] : '' );
			return $at < $limit ? 'too_soon' : '';
		}

		return '';
	}
}
