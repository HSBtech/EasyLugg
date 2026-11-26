<?php
/**
 * Dashboard read-only queries. Every query is a SELECT through $wpdb->prepare.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyLugg_App_Data {

	const PER_PAGE = 10;

	/**
	 * @return string
	 */
	private static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'easylugg_audit_log';
	}

	/**
	 * SQL condition (alias b) for the derived Exception flag (SRS 6.6.2).
	 * Contains no user input.
	 *
	 * @return string
	 */
	private static function exception_sql() {
		$a = self::audit_table();
		return "(b.needs_review = 1"
			. " OR EXISTS (SELECT 1 FROM {$a} i WHERE i.entity_type = 'booking' AND i.entity_id = b.booking_code AND i.action = 'incident')"
			. " OR (b.status = 'no_show' AND EXISTS (SELECT 1 FROM {$a} h WHERE h.entity_type = 'booking' AND h.entity_id = b.booking_code AND h.action = 'custody_held')"
			. " AND NOT EXISTS (SELECT 1 FROM {$a} r WHERE r.entity_type = 'booking' AND r.entity_id = b.booking_code AND r.action = 'custody_released')))";
	}

	/**
	 * Counts for one service date: per status plus exceptions.
	 *
	 * @param string $from Y-m-d (inclusive).
	 * @param string $to   Y-m-d (inclusive).
	 * @return array{total:int,by_status:array<string,int>,exception:int}
	 */
	public static function counts( $from, $to ) {
		global $wpdb;
		$table = EasyLugg_Booking_Plugin::table_name();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$table} WHERE service_date BETWEEN %s AND %s GROUP BY status", $from, $to ) );
		$by    = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$by[ $row->status ] = (int) $row->c;
			$total             += (int) $row->c;
		}
		$exception = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} b WHERE b.service_date BETWEEN %s AND %s AND " . self::exception_sql(), $from, $to ) );
		return array(
			'total'     => $total,
			'by_status' => $by,
			'exception' => $exception,
		);
	}

	/**
	 * Tab counts for the list (same definitions as the KPI tiles).
	 *
	 * @param array $counts Result of counts().
	 * @return array<string,int>
	 */
	public static function tab_counts( $counts ) {
		return array(
			'all'       => $counts['total'],
			'pickup'    => EasyLugg_App_Metrics::group_count( $counts['by_status'], 'pickup' ),
			'transit'   => EasyLugg_App_Metrics::group_count( $counts['by_status'], 'transit' ),
			'delivered' => EasyLugg_App_Metrics::group_count( $counts['by_status'], 'delivered' ),
			'exception' => $counts['exception'],
		);
	}

	/**
	 * Paged list. A search term overrides date and tab (LIST-08).
	 *
	 * @param array $f Filters: from, to, tab, corridor, q, page.
	 * @return array{rows:array,total:int,page:int,pages:int}
	 */
	public static function list_rows( $f ) {
		global $wpdb;
		$table = EasyLugg_Booking_Plugin::table_name();
		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $f['q'] ) {
			$like    = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$where[] = '(b.booking_code LIKE %s OR b.customer_name LIKE %s OR b.customer_email LIKE %s)';
			array_push( $args, $like, $like, $like );
			$order = 'b.created_at DESC';
		} else {
			$where[] = 'b.service_date BETWEEN %s AND %s';
			array_push( $args, $f['from'], $f['to'] );
			$groups  = EasyLugg_App_Metrics::groups();
			if ( isset( $groups[ $f['tab'] ] ) ) {
				$where[] = 'b.status IN (' . implode( ',', array_fill( 0, count( $groups[ $f['tab'] ] ), '%s' ) ) . ')';
				$args    = array_merge( $args, $groups[ $f['tab'] ] );
			} elseif ( 'exception' === $f['tab'] ) {
				$where[] = self::exception_sql();
			}
			if ( '' !== $f['corridor'] ) {
				$where[] = 'b.corridor = %s';
				$args[]  = $f['corridor'];
			}
			$order = 'b.service_date ASC, b.created_at ASC';
		}
		$where_sql = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} b WHERE {$where_sql}", $args ) );
		$pages     = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page      = min( max( 1, (int) $f['page'] ), $pages );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_code, b.corridor, b.status, b.booking_data, b.needs_review, (" . self::exception_sql() . ") AS is_exc FROM {$table} b WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $args, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) ) );
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		);
	}

	/**
	 * One booking with its timeline, photos and exception reasons.
	 *
	 * @param string $code Booking code.
	 * @return array|null
	 */
	public static function detail( $code ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EasyLugg_Booking_Plugin::table_name() . ' WHERE booking_code = %s', $code ) );
		if ( ! $row ) {
			return null;
		}
		$events = EasyLugg_Dashboard_Setup::booking_timeline( $row->booking_code );
		$photos = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT stage, created_at FROM ' . $wpdb->prefix . 'easylugg_booking_photos WHERE booking_code = %s ORDER BY id ASC', $row->booking_code ) );
		$reasons = array();
		if ( (int) $row->needs_review ) {
			$reasons[] = array( 'type' => 'review', 'note' => '', 'at' => '', 'who' => '' );
		}
		$held     = false;
		$released = false;
		foreach ( $events as $event ) {
			if ( 'incident' === $event->action ) {
				$reasons[] = array( 'type' => 'incident', 'note' => (string) $event->note, 'at' => $event->created_at, 'who' => self::actor( $event->actor_id ) );
			} elseif ( 'custody_held' === $event->action ) {
				$held = $event;
			} elseif ( 'custody_released' === $event->action ) {
				$released = true;
			}
		}
		if ( 'no_show' === $row->status && $held && ! $released ) {
			$reasons[] = array( 'type' => 'held', 'note' => (string) $held->note, 'at' => $held->created_at, 'who' => self::actor( $held->actor_id ) );
		}
		return array(
			'row'     => $row,
			'data'    => EasyLugg_Dashboard::decode( $row->booking_data ),
			'events'  => $events,
			'photos'  => $photos,
			'reasons' => $reasons,
		);
	}

	/**
	 * @param int|string $actor_id User id.
	 * @return string
	 */
	public static function actor( $actor_id ) {
		$user = $actor_id ? get_userdata( (int) $actor_id ) : false;
		return $user ? $user->display_name : '';
	}
}
