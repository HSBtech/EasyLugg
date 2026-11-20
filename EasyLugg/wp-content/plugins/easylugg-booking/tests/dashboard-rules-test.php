<?php
// Run: php tests/dashboard-rules-test.php   (no WordPress needed)
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/dashboard-rules.php';

$fail = 0;
$n    = 0;
function check( $label, $actual, $expected ) {
	global $fail, $n;
	$n++;
	if ( $actual !== $expected ) {
		$fail++;
		echo "FAIL: $label — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}
$R = 'EasyLugg_Dashboard_Rules';

// State machine: every legal move, and a sample of illegal ones.
check( 'confirm from requested', $R::action_valid_from( 'confirm', 'requested' ), true );
check( 'checkin from confirmed', $R::action_valid_from( 'checkin', 'confirmed' ), true );
check( 'jump confirmed->deliver', $R::action_valid_from( 'deliver', 'confirmed' ), false );
check( 'cancelled can do nothing', $R::available_actions( 'cancelled', array( 'easylugg_operate_booking', 'easylugg_cancel_booking' ) ), array() );
check( 'no_show can do nothing', $R::available_actions( 'no_show', array( 'easylugg_operate_booking', 'easylugg_cancel_booking' ) ), array() );
check( 'completed can do nothing', $R::available_actions( 'completed', array( 'easylugg_operate_booking', 'easylugg_cancel_booking' ) ), array() );
check( 'cancel from collected not allowed', $R::action_valid_from( 'cancel', 'collected' ), false );
check( 'unknown action', $R::action_valid_from( 'delete', 'confirmed' ), false );
check( 'confirmed actions (both caps)', $R::available_actions( 'confirmed', array( 'easylugg_operate_booking', 'easylugg_cancel_booking' ) ), array( 'checkin', 'cancel', 'no_show' ) );
check( 'confirmed actions (operate only)', $R::available_actions( 'confirmed', array( 'easylugg_operate_booking' ) ), array( 'checkin' ) );
check( 'paid cancel hidden w/o cap', $R::available_actions( 'confirmed', array( 'easylugg_cancel_booking' ), true ), array( 'no_show' ) );
check( 'paid cancel shown with cap', $R::available_actions( 'confirmed', array( 'easylugg_cancel_booking', 'easylugg_cancel_paid_booking' ), true ), array( 'cancel', 'no_show' ) );

// Gates.
check( 'confirm needs tick', $R::check_gates( 'confirm', array() )['error'], 'hotel_confirmed_required' );
check( 'confirm ok', $R::check_gates( 'confirm', array( 'hotel_confirmed' => '1' ) )['error'], '' );
$ci = array( 'verified' => 1, 'tag_code' => 'T1', 'actual_bags' => 2, 'booked_bags' => 2, 'photo_count' => 2 );
check( 'checkin ok', $R::check_gates( 'checkin', $ci )['error'], '' );
check( 'checkin 1 photo blocked', $R::check_gates( 'checkin', array_merge( $ci, array( 'photo_count' => 1 ) ) )['error'], 'checkin_photos_required' );
check( 'checkin no verify', $R::check_gates( 'checkin', array_merge( $ci, array( 'verified' => 0 ) ) )['error'], 'verify_required' );
check( 'checkin no tag', $R::check_gates( 'checkin', array_merge( $ci, array( 'tag_code' => ' ' ) ) )['error'], 'tag_required' );
check( 'checkin mismatch needs reason', $R::check_gates( 'checkin', array_merge( $ci, array( 'actual_bags' => 3 ) ) )['error'], 'bag_mismatch_reason_required' );
$mm = $R::check_gates( 'checkin', array_merge( $ci, array( 'actual_bags' => 3, 'reason' => 'extra bag' ) ) );
check( 'checkin mismatch with reason ok', $mm['error'], '' );
check( 'checkin mismatch sets needs_review', $mm['needs_review'], true );
check( 'checkin match no review', $R::check_gates( 'checkin', $ci )['needs_review'], false );
check( 'depart needs loop', $R::check_gates( 'depart', array() )['error'], 'loop_required' );
check( 'depart loop 2', $R::check_gates( 'depart', array( 'loop' => '2' ) )['error'], '' );
$dv = array( 'verified' => 1, 'receiver_type' => 'customer', 'receiver_name' => 'A', 'photo_count' => 1 );
check( 'deliver ok', $R::check_gates( 'deliver', $dv )['error'], '' );
check( 'deliver no photo', $R::check_gates( 'deliver', array_merge( $dv, array( 'photo_count' => 0 ) ) )['error'], 'handover_photo_required' );
check( 'deliver proxy unregistered', $R::check_gates( 'deliver', array_merge( $dv, array( 'receiver_type' => 'proxy' ) ) )['error'], 'proxy_not_registered' );
check( 'deliver proxy registered', $R::check_gates( 'deliver', array_merge( $dv, array( 'receiver_type' => 'proxy', 'proxy_ok' => 1 ) ) )['error'], '' );
check( 'complete needs payment', $R::check_gates( 'complete', array() )['error'], 'payment_required' );
check( 'complete cash needs invoice', $R::check_gates( 'complete', array( 'payment_recorded' => 1, 'is_cash' => 1 ) )['error'], 'invoice_required' );
check( 'complete cash with invoice', $R::check_gates( 'complete', array( 'payment_recorded' => 1, 'is_cash' => 1, 'invoice_no' => 'INV-001' ) )['error'], '' );
check( 'complete non-cash no invoice', $R::check_gates( 'complete', array( 'payment_recorded' => 1 ) )['error'], '' );
check( 'cancel needs reason', $R::check_gates( 'cancel', array( 'reason' => '  ' ) )['error'], 'cancel_reason_required' );
check( 'no_show needs note', $R::check_gates( 'no_show', array() )['error'], 'no_show_note_required' );
check( 'invoice bad chars', $R::valid_invoice_no( "x'; DROP" ), false );

// Edit modes.
check( 'edit full', $R::edit_mode( 'confirmed' ), 'full' );
check( 'edit contact', $R::edit_mode( 'in_transit' ), 'contact' );
check( 'edit note', $R::edit_mode( 'delivered' ), 'note' );
check( 'edit note cancelled', $R::edit_mode( 'cancelled' ), 'note' );

// Future-time rule (30-minute buffer).
$ah = array( 'corridor' => 'AH', 'service_date' => '2026-11-20', 'pickup_time' => '10:30' );
check( 'AH now+30 exactly ok', $R::validate_future_time( $ah, '2026-11-20 10:00' ), '' );
check( 'AH now+29 rejected', $R::validate_future_time( $ah, '2026-11-20 10:01' ), 'too_soon' );
check( 'AH started slot rejected', $R::validate_future_time( $ah, '2026-11-20 10:45' ), 'too_soon' );
check( 'AH tomorrow ok', $R::validate_future_time( $ah, '2026-11-19 23:59' ), '' );
check( 'past date rejected', $R::validate_future_time( $ah, '2026-11-21 08:00' ), 'past_date' );
check( 'buffer crosses midnight', $R::validate_future_time( array( 'corridor' => 'AH', 'service_date' => '2026-11-21', 'pickup_time' => '00:00' ), '2026-11-20 23:45' ), 'too_soon' );
$ha = array( 'corridor' => 'HA', 'service_date' => '2026-11-20', 'airport_delivery_time' => '19:00' );
check( 'HA ok', $R::validate_future_time( $ha, '2026-11-20 12:00' ), '' );
check( 'HA too soon', $R::validate_future_time( $ha, '2026-11-20 18:45' ), 'too_soon' );
$hh = array( 'corridor' => 'HH', 'service_date' => '2026-11-20' );
check( 'HH 10:59 ok', $R::validate_future_time( $hh, '2026-11-20 10:59' ), '' );
check( 'HH 11:00 rejected', $R::validate_future_time( $hh, '2026-11-20 11:00' ), 'hh_cutoff' );
check( 'HH tomorrow after 11 ok', $R::validate_future_time( array( 'corridor' => 'HH', 'service_date' => '2026-11-21' ), '2026-11-20 15:00' ), '' );
$st = array( 'corridor' => 'ST', 'service_date' => '2026-11-20', 'dropoff_date' => '2026-11-20', 'dropoff_time' => '09:00' );
check( 'ST ok', $R::validate_future_time( $st, '2026-11-20 08:30' ), '' );
check( 'ST too soon', $R::validate_future_time( $st, '2026-11-20 08:31' ), 'too_soon' );
check( 'custom buffer 60', $R::validate_future_time( $ah, '2026-11-20 10:00', 60 ), 'too_soon' );

echo $fail ? "$fail of $n FAILED\n" : "OK — all $n checks passed\n";
exit( $fail ? 1 : 0 );
