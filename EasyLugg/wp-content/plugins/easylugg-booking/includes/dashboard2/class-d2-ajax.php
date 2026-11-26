<?php
/**
 * Dashboard 2 AJAX (read-only). Logged-in users only; no nopriv actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyLugg_D2_Ajax {

	public static function hooks() {
		add_action( 'wp_ajax_easylugg_d2_refresh', array( __CLASS__, 'refresh' ) );
		add_action( 'wp_ajax_easylugg_d2_detail', array( __CLASS__, 'detail' ) );
	}

	/**
	 * Nonce, enabled and permission check; ends the request on failure.
	 *
	 * @return void
	 */
	private static function guard() {
		check_ajax_referer( EasyLugg_D2::NONCE, 'nonce' );
		if ( ! EasyLugg_D2::is_enabled() || ! EasyLugg_D2::can_view() ) {
			wp_send_json_error( array( 'message' => EasyLugg_D2::t( 'gate.no_permission' ) ), 403 );
		}
	}

	/**
	 * @param string $key Param.
	 * @return string
	 */
	private static function param( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * KPI + list for the current filters.
	 *
	 * @return void
	 */
	public static function refresh() {
		self::guard();
		$today = wp_date( 'Y-m-d' );
		$from  = self::param( 'from' );
		$to    = self::param( 'to' );
		$valid = function ( $v ) {
			return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && false !== strtotime( $v );
		};
		$from  = $valid( $from ) ? $from : $today;
		$to    = $valid( $to ) ? $to : $from;
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}
		$tab   = self::param( 'tab' );
		$cor   = self::param( 'corridor' );
		$f     = array(
			'from'     => $from,
			'to'       => $to,
			'tab'      => in_array( $tab, array( 'pickup', 'transit', 'delivered', 'exception' ), true ) ? $tab : 'all',
			'corridor' => in_array( $cor, array( 'AH', 'HA', 'HH', 'ST' ), true ) ? $cor : '',
			'q'        => mb_substr( self::param( 'q' ), 0, 100 ),
			'page'     => max( 1, (int) self::param( 'page' ) ),
		);
		$code      = strtoupper( self::param( 'code' ) );
		// Previous period of the same length, right before the range (for the KPI delta).
		$days      = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		$prev_to   = wp_date( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$prev_from = wp_date( 'Y-m-d', strtotime( $from . ' -' . $days . ' day' ) );
		$counts    = EasyLugg_D2_Data::counts( $from, $to );
		$yesterday = EasyLugg_D2_Data::counts( $prev_from, $prev_to );
		$result    = EasyLugg_D2_Data::list_rows( $f );
		wp_send_json_success(
			array(
				'kpi'  => EasyLugg_D2_Render::kpi( $counts, $yesterday['total'] ),
				'list' => EasyLugg_D2_Render::list_block( $f, $result, EasyLugg_D2_Data::tab_counts( $counts ), $code ),
				'page' => $result['page'],
			)
		);
	}

	/**
	 * Detail, timeline/exception and proof for one booking. Writes one
	 * pii_view audit row per open (same as Dashboard 1, DET-04).
	 *
	 * @return void
	 */
	public static function detail() {
		self::guard();
		$code = strtoupper( self::param( 'code' ) );
		$d    = '' !== $code ? EasyLugg_D2_Data::detail( $code ) : null;
		if ( ! $d ) {
			wp_send_json_success(
				array(
					'detail'   => EasyLugg_D2_Render::detail_missing(),
					'tracking' => '',
					'proof'    => '',
				)
			);
		}
		EasyLugg_Dashboard_Setup::audit( 'booking', $d['row']->booking_code, 'pii_view' );
		wp_send_json_success(
			array(
				'detail'   => EasyLugg_D2_Render::detail( $d ),
				'tracking' => EasyLugg_D2_Render::tracking( $d ),
				'proof'    => EasyLugg_D2_Render::proof( $d ),
			)
		);
	}
}
