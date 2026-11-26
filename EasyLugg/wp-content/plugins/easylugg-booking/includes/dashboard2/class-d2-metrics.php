<?php
/**
 * Dashboard 2 pure helpers (no WordPress calls): status groups and KPI math.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyLugg_D2_Metrics {

	/**
	 * Status groups used by the KPI tiles and the list tabs (SRS 6.3, 6.4).
	 *
	 * @return array<string, string[]>
	 */
	public static function groups() {
		return array(
			'pickup'    => array( 'requested', 'confirmed' ),
			'transit'   => array( 'collected', 'in_transit' ),
			'delivered' => array( 'delivered', 'completed' ),
		);
	}

	/**
	 * Group of a status; unknown statuses (e.g. pending_payment) are 'other'.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function group_of( $status ) {
		foreach ( self::groups() as $group => $statuses ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $group;
			}
		}
		return 'other';
	}

	/**
	 * Sum of counts for a group.
	 *
	 * @param array<string, int> $by_status Counts per status.
	 * @param string             $group     Group key.
	 * @return int
	 */
	public static function group_count( $by_status, $group ) {
		$groups = self::groups();
		$sum    = 0;
		foreach ( isset( $groups[ $group ] ) ? $groups[ $group ] : array() as $status ) {
			$sum += isset( $by_status[ $status ] ) ? (int) $by_status[ $status ] : 0;
		}
		return $sum;
	}

	/**
	 * Share of $part in $total, whole percent; null when total is 0.
	 *
	 * @param int $part  Part.
	 * @param int $total Total.
	 * @return int|null
	 */
	public static function percent( $part, $total ) {
		return $total > 0 ? (int) round( 100 * $part / $total ) : null;
	}

	/**
	 * Change vs yesterday in whole percent; null when yesterday is 0.
	 *
	 * @param int $today     Today.
	 * @param int $yesterday Yesterday.
	 * @return int|null
	 */
	public static function delta( $today, $yesterday ) {
		return $yesterday > 0 ? (int) round( 100 * ( $today - $yesterday ) / $yesterday ) : null;
	}
}
