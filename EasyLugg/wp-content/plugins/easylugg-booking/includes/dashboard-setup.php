<?php
/**
 * Dashboard foundation: DB v2 tables, roles/capabilities, audit log helper,
 * login hardening and keeping Staff out of wp-admin.
 * SRS refs: SEC-05..07, ACC-05, AUD-01..04, DB v2 (§5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Dashboard_Setup {

	const LOCK_META = 'easylugg_locked';

	/**
	 * Every capability the Dashboard knows (SRS §2.1).
	 *
	 * @return array<int, string>
	 */
	public static function all_caps() {
		return array(
			'easylugg_view_dashboard',
			'easylugg_create_booking',
			'easylugg_edit_booking',
			'easylugg_operate_booking',
			'easylugg_cancel_booking',
			'easylugg_cancel_paid_booking',
			'easylugg_clear_review',
			'easylugg_manage_config',
			'easylugg_manage_accounts',
		);
	}

	/**
	 * Default Staff capabilities. Cancelling a paid order and clearing the
	 * review flag stay with Staff until the owner ticks P-6 (SRS fallback).
	 *
	 * @return array<int, string>
	 */
	public static function staff_caps() {
		return array(
			'easylugg_view_dashboard',
			'easylugg_create_booking',
			'easylugg_edit_booking',
			'easylugg_operate_booking',
			'easylugg_cancel_booking',
			'easylugg_cancel_paid_booking',
			'easylugg_clear_review',
		);
	}

	/**
	 * Hook the login/wp-admin protections. Called once from the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_filter( 'authenticate', array( __CLASS__, 'check_login_lockout' ), 30, 3 );
		add_filter( 'authenticate', array( __CLASS__, 'check_account_locked' ), 40, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_login_failure' ), 10, 1 );
		add_action( 'wp_login', array( __CLASS__, 'record_login_success' ), 10, 2 );
		// 'init' (not 'admin_init'): deep links such as admin.php?page=… are access-checked
		// by WordPress before admin_init, which would otherwise end in a bare 403.
		add_action( 'init', array( __CLASS__, 'keep_staff_out_of_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_for_staff' ) );
	}

	/**
	 * Create/upgrade tables, roles and capabilities. Idempotent.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$audit   = $wpdb->prefix . 'easylugg_audit_log';
		$photos  = $wpdb->prefix . 'easylugg_booking_photos';

		dbDelta(
			"CREATE TABLE {$audit} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(20) NOT NULL,
			entity_id varchar(100) NOT NULL,
			action varchar(40) NOT NULL,
			from_status varchar(20) NOT NULL DEFAULT '',
			to_status varchar(20) NOT NULL DEFAULT '',
			actor_id bigint unsigned NOT NULL DEFAULT 0,
			note text NOT NULL,
			meta longtext NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entity (entity_type,entity_id),
			KEY created_at (created_at)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$photos} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			booking_code varchar(24) NOT NULL,
			stage varchar(12) NOT NULL,
			path text NOT NULL,
			actor_id bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_code (booking_code)
		) {$charset};"
		);

		self::install_roles();
	}

	/**
	 * @return void
	 */
	private static function install_roles() {
		$staff_caps = array( 'read' => true );
		foreach ( self::staff_caps() as $cap ) {
			$staff_caps[ $cap ] = true;
		}
		$admin_caps = array( 'read' => true );
		foreach ( self::all_caps() as $cap ) {
			$admin_caps[ $cap ] = true;
		}

		if ( ! get_role( 'easylugg_staff' ) ) {
			add_role( 'easylugg_staff', __( 'EasyLugg Staff', 'easylugg-booking' ), $staff_caps );
		}
		if ( ! get_role( 'easylugg_admin' ) ) {
			add_role( 'easylugg_admin', __( 'EasyLugg Admin', 'easylugg-booking' ), $admin_caps );
		}
		// Re-apply so a changed default list reaches existing role records.
		$staff = get_role( 'easylugg_staff' );
		if ( $staff ) {
			foreach ( array_keys( $staff_caps ) as $cap ) {
				$staff->add_cap( $cap );
			}
		}
		$admin_role = get_role( 'easylugg_admin' );
		if ( $admin_role ) {
			foreach ( array_keys( $admin_caps ) as $cap ) {
				$admin_role->add_cap( $cap );
			}
		}
		// WordPress administrators are EasyLugg Admins.
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::all_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}
	}

	/**
	 * Append-only audit log (AUD-01). There is no update/delete path anywhere.
	 *
	 * @param string               $entity_type booking | config | account | auth.
	 * @param string               $entity_id   Booking code, config key, user id…
	 * @param string               $action      status_change, edit, pii_view, …
	 * @param array<string, mixed> $args        from_status, to_status, note, meta, actor_id.
	 * @return bool
	 */
	public static function audit( $entity_type, $entity_id, $action, $args = array() ) {
		global $wpdb;
		$meta = isset( $args['meta'] ) ? self::redact( $args['meta'] ) : null;
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 ) : '';
		$ok   = $wpdb->insert(
			$wpdb->prefix . 'easylugg_audit_log',
			array(
				'entity_type' => substr( (string) $entity_type, 0, 20 ),
				'entity_id'   => substr( (string) $entity_id, 0, 100 ),
				'action'      => substr( (string) $action, 0, 40 ),
				'from_status' => isset( $args['from_status'] ) ? (string) $args['from_status'] : '',
				'to_status'   => isset( $args['to_status'] ) ? (string) $args['to_status'] : '',
				'actor_id'    => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				'note'        => isset( $args['note'] ) ? (string) $args['note'] : '',
				'meta'        => null === $meta ? null : wp_json_encode( $meta ),
				'ip'          => $ip,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return false !== $ok;
	}

	/**
	 * Never write secrets or passport numbers to the log (AUD-04).
	 *
	 * @param mixed $value Meta value.
	 * @return mixed
	 */
	private static function redact( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $entry ) {
				if ( is_string( $key ) && preg_match( '/pass(word|port)|token|secret/i', $key ) ) {
					$value[ $key ] = '[redacted]';
				} else {
					$value[ $key ] = self::redact( $entry );
				}
			}
		}
		return $value;
	}

	/**
	 * Audit rows for one booking, oldest first (AUD-03).
	 *
	 * @param string $code Booking code.
	 * @return array<int, object>
	 */
	public static function booking_timeline( $code ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . "easylugg_audit_log WHERE entity_type = 'booking' AND entity_id = %s AND action <> 'pii_view' ORDER BY id ASC",
				$code
			)
		);
	}

	// ---- Login hardening (SEC-05, SEC-06) --------------------------------

	const MAX_FAILURES = 5;
	const LOCK_SECONDS = 900;

	/**
	 * @param string $kind ip|user.
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function fail_key( $kind, $value ) {
		return 'el_fail_' . $kind . '_' . hash_hmac( 'sha256', strtolower( (string) $value ), wp_salt( 'auth' ) );
	}

	/**
	 * @return string
	 */
	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Block login while too many recent failures exist for this IP or this
	 * account name. The message is identical either way (no account probing).
	 *
	 * @param WP_User|WP_Error|null $user     Auth result so far.
	 * @param string                $username Login name.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_login_lockout( $user, $username, $password ) {
		if ( '' === (string) $username ) {
			return $user;
		}
		$ip_hits   = (int) get_transient( self::fail_key( 'ip', self::client_ip() ) );
		$user_hits = (int) get_transient( self::fail_key( 'user', $username ) );
		if ( $ip_hits >= self::MAX_FAILURES || $user_hits >= self::MAX_FAILURES ) {
			return new WP_Error( 'easylugg_locked_out', __( 'Too many failed sign-in attempts. Please wait 15 minutes and try again.', 'easylugg-booking' ) );
		}
		return $user;
	}

	/**
	 * @param string $username Login name that failed.
	 * @return void
	 */
	public static function record_login_failure( $username ) {
		foreach ( array(
			self::fail_key( 'ip', self::client_ip() ),
			self::fail_key( 'user', $username ),
		) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::LOCK_SECONDS );
		}
		self::audit( 'auth', sanitize_user( (string) $username, true ), 'login_failed', array( 'actor_id' => 0 ) );
	}

	/**
	 * @param string  $user_login Login name.
	 * @param WP_User $user       User.
	 * @return void
	 */
	public static function record_login_success( $user_login, $user ) {
		delete_transient( self::fail_key( 'user', $user_login ) );
		if ( user_can( $user, 'easylugg_view_dashboard' ) ) {
			self::audit( 'auth', (string) $user->ID, 'login_success', array( 'actor_id' => (int) $user->ID ) );
		}
	}

	/**
	 * A locked account cannot sign in even with the right password.
	 *
	 * @param WP_User|WP_Error|null $user     Auth result so far.
	 * @param string                $username Login name.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_account_locked( $user, $username, $password ) {
		if ( $user instanceof WP_User && self::is_locked( $user->ID ) ) {
			return new WP_Error( 'easylugg_account_locked', __( 'This account is locked. Please contact your administrator.', 'easylugg-booking' ) );
		}
		return $user;
	}

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_locked( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::LOCK_META, true );
	}

	/**
	 * Lock or unlock an account; locking destroys its sessions (SEC-06).
	 *
	 * @param int  $user_id User ID.
	 * @param bool $locked  Lock state.
	 * @return void
	 */
	public static function set_locked( $user_id, $locked ) {
		if ( $locked ) {
			update_user_meta( (int) $user_id, self::LOCK_META, '1' );
			$sessions = WP_Session_Tokens::get_instance( (int) $user_id );
			$sessions->destroy_all();
		} else {
			delete_user_meta( (int) $user_id, self::LOCK_META );
		}
	}

	// ---- Keep Staff out of wp-admin (SEC-07) ------------------------------

	/**
	 * Staff = can use the Dashboard but cannot administer the site.
	 *
	 * @return bool
	 */
	private static function is_staff_only() {
		return is_user_logged_in() && current_user_can( 'easylugg_view_dashboard' ) && ! current_user_can( 'manage_options' );
	}

	/**
	 * @return void
	 */
	public static function keep_staff_out_of_admin() {
		if ( ! is_admin() || ! self::is_staff_only() || wp_doing_ajax() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) && is_scalar( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
		if ( 'admin-post.php' === $script ) {
			return;
		}
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	/**
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function hide_admin_bar_for_staff( $show ) {
		return self::is_staff_only() ? false : $show;
	}

	/**
	 * @return string
	 */
	public static function dashboard_url() {
		$page = get_page_by_path( 'dashboard' );
		return $page ? get_permalink( $page ) : home_url( '/dashboard/' );
	}
}
