<?php
/**
 * Dashboard write handlers (wp_ajax_ only — never nopriv, SEC-04).
 * Every handler: nonce + capability + locked-account check, then the rules in
 * dashboard-rules.php. SRS refs: CRT, EDT, OPS-01..09, CFG-01, AUD.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Dashboard_Actions {

	/**
	 * @return void
	 */
	public static function hooks() {
		foreach ( array( 'transition', 'create', 'edit', 'record_payment', 'add_note', 'report_incident', 'release_custody', 'clear_review', 'save_config', 'photo' ) as $name ) {
			add_action( 'wp_ajax_easylugg_dashboard_' . $name, array( __CLASS__, $name ) );
		}
	}

	// ---- Shared plumbing ---------------------------------------------------

	/**
	 * @param string $key    Error text key (err.*).
	 * @param int    $status HTTP status.
	 * @param mixed  ...$args sprintf arguments for the message.
	 * @return void
	 */
	private static function fail( $key, $status = 400, ...$args ) {
		$message = $args ? vsprintf( EasyLugg_Dashboard::t( 'err.' . $key ), $args ) : EasyLugg_Dashboard::t( 'err.' . $key );
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	/**
	 * Nonce + login + not locked + capability. Dies on failure.
	 *
	 * @param string $cap Capability required ('' = just view).
	 * @return void
	 */
	private static function guard( $cap = '' ) {
		if ( ! is_user_logged_in() || ! check_ajax_referer( EasyLugg_Dashboard::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => EasyLugg_Dashboard::t( 'gate.session' ) ), 403 );
		}
		if ( EasyLugg_Dashboard_Setup::is_locked( get_current_user_id() ) || ! current_user_can( 'easylugg_view_dashboard' ) || ( $cap && ! current_user_can( $cap ) ) ) {
			wp_send_json_error( array( 'message' => EasyLugg_Dashboard::t( 'gate.forbidden' ) ), 403 );
		}
	}

	/**
	 * @param string $key Field key.
	 * @return string
	 */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * @return object Booking row (dies with 404 when missing).
	 */
	private static function load_booking() {
		global $wpdb;
		$code = strtoupper( self::post( 'code' ) );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EasyLugg_Booking_Plugin::table_name() . ' WHERE booking_code = %s', $code ) );
		if ( ! $row ) {
			self::fail( 'not_found', 404 );
		}
		return $row;
	}

	/**
	 * @param string $message Success message.
	 * @param array  $extra   Extra response fields.
	 * @return void
	 */
	private static function ok( $message, $extra = array() ) {
		wp_send_json_success( array_merge( array( 'message' => $message, 'reload' => true ), $extra ) );
	}

	/**
	 * Save one uploaded photo via the plugin's existing validated uploader
	 * (3 MB cap, MIME + image sniffing, private folder).
	 *
	 * @param array<string, mixed> $file One $_FILES-style entry.
	 * @return string|WP_Error Real path or error.
	 */
	private static function save_photo( $file ) {
		$previous                = isset( $_FILES['luggage_photo'] ) ? $_FILES['luggage_photo'] : null;
		$_FILES['luggage_photo'] = $file;
		$result                  = EasyLugg_Dashboard::plugin()->upload_booking_photo( 'AH' );
		if ( null === $previous ) {
			unset( $_FILES['luggage_photo'] );
		} else {
			$_FILES['luggage_photo'] = $previous;
		}
		return $result;
	}

	/**
	 * Save every file in $_FILES['photos'].
	 *
	 * @return array<int, string> Saved paths (dies and cleans up on failure).
	 */
	private static function collect_photos() {
		$paths = array();
		if ( empty( $_FILES['photos'] ) || ! is_array( $_FILES['photos'] ) || ! isset( $_FILES['photos']['name'] ) || ! is_array( $_FILES['photos']['name'] ) ) {
			return $paths;
		}
		$raw = $_FILES['photos'];
		foreach ( array_keys( $raw['name'] ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
				continue;
			}
			$saved = self::save_photo(
				array(
					'name'     => $raw['name'][ $i ],
					'type'     => $raw['type'][ $i ],
					'tmp_name' => $raw['tmp_name'][ $i ],
					'error'    => $raw['error'][ $i ],
					'size'     => $raw['size'][ $i ],
				)
			);
			if ( is_wp_error( $saved ) || ! is_string( $saved ) || '' === $saved ) {
				self::delete_files( $paths );
				self::fail( 'photo_failed', 400 );
			}
			$paths[] = $saved;
		}
		return $paths;
	}

	/**
	 * @param array<int, string> $paths Files to remove.
	 * @return void
	 */
	private static function delete_files( $paths ) {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Same confirmation email the customer form sends (token is derived from
	 * the stored request_key, so the tracking link is identical).
	 *
	 * @param object $row Row with booking_code, status, price_total, request_key, customer_email, corridor.
	 * @return bool
	 */
	private static function email_customer( $row ) {
		// Send in the customer's booking language, not the staff member's.
		return (bool) EasyLugg_Booking_Plugin::with_language(
			isset( $row->lang ) ? $row->lang : '',
			function () use ( $row ) {
				$plugin  = EasyLugg_Dashboard::plugin();
				$storage = 'ST' === $row->corridor;
				$subject = sprintf( $plugin->text( 'email.subject_received' ), $row->booking_code );
				$message = sprintf(
					$plugin->text( $storage ? 'email.body_received_storage' : 'email.body_received' ),
					$plugin->status_label( $row->status ),
					$row->booking_code,
					number_format_i18n( (int) $row->price_total ),
					EasyLugg_Dashboard::tracking_url( $row )
				);
				return wp_mail( $row->customer_email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
			}
		);
	}

	// ---- Status transitions (OPS) -----------------------------------------

	/**
	 * @return void
	 */
	public static function transition() {
		self::guard();
		global $wpdb;
		$act  = sanitize_key( self::post( 'act' ) );
		$from = sanitize_key( self::post( 'from' ) );
		$defs = EasyLugg_Dashboard_Rules::actions();
		if ( ! isset( $defs[ $act ] ) ) {
			self::fail( 'unknown_action', 400 );
		}
		if ( ! current_user_can( $defs[ $act ]['cap'] ) ) {
			wp_send_json_error( array( 'message' => EasyLugg_Dashboard::t( 'gate.forbidden' ) ), 403 );
		}
		$row = self::load_booking();
		if ( in_array( $row->status, EasyLugg_Dashboard_Rules::terminal_statuses(), true ) ) {
			self::fail( 'closed', 409 );
		}
		if ( $row->status !== $from ) {
			self::fail( 'conflict', 409 );
		}
		if ( ! EasyLugg_Dashboard_Rules::action_valid_from( $act, $row->status ) ) {
			self::fail( 'illegal_transition', 400 );
		}
		$paid = EasyLugg_Dashboard::is_paid( $row );
		if ( 'cancel' === $act && $paid && ! current_user_can( 'easylugg_cancel_paid_booking' ) ) {
			wp_send_json_error( array( 'message' => EasyLugg_Dashboard::t( 'gate.forbidden' ) ), 403 );
		}

		$data  = EasyLugg_Dashboard::decode( $row->booking_data );
		$stage = 'checkin' === $act ? 'checkin' : ( 'deliver' === $act ? 'handover' : '' );
		$new   = $stage ? self::collect_photos() : array();
		$have  = $stage ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'easylugg_booking_photos WHERE booking_code = %s AND stage = %s', $row->booking_code, $stage ) ) : 0;

		$invoice = self::post( 'invoice_no' );
		$facts   = array(
			'hotel_confirmed'  => '1' === self::post( 'hotel_confirmed' ),
			'verified'         => '1' === self::post( 'verified' ),
			'tag_code'         => self::post( 'tag_code' ),
			'actual_bags'      => self::post( 'actual_bags' ),
			'booked_bags'      => EasyLugg_Dashboard::bag_total( $data ),
			'reason'           => self::post( 'reason' ),
			'photo_count'      => $have + count( $new ),
			'loop'             => self::post( 'loop' ),
			'receiver_type'    => self::post( 'receiver_type' ),
			'receiver_name'    => self::post( 'receiver_name' ),
			'proxy_ok'         => '1' === self::post( 'proxy_ok' ),
			'payment_recorded' => $paid,
			'is_cash'          => 'cash' === $row->payment_method,
			'invoice_no'       => '' !== $invoice ? $invoice : (string) $row->invoice_no,
			'note'             => self::post( 'note' ),
		);
		$gate    = EasyLugg_Dashboard_Rules::check_gates( $act, $facts );
		if ( '' !== $gate['error'] ) {
			self::delete_files( $new );
			self::fail( $gate['error'], 400 );
		}

		$to   = $defs[ $act ]['to'];
		$now  = current_time( 'mysql', true );
		$set  = 'status = %s, row_version = row_version + 1, updated_at = %s';
		$args = array( $to, $now );
		if ( $gate['needs_review'] ) {
			$set .= ', needs_review = 1';
		}
		if ( 'complete' === $act && '' !== $facts['invoice_no'] ) {
			$set   .= ', invoice_no = %s';
			$args[] = $facts['invoice_no'];
		}
		$args[] = $row->booking_code;
		$args[] = $from;

		// OPS-02: atomic compare-and-set + audit row in one transaction.
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . EasyLugg_Booking_Plugin::table_name() . " SET {$set} WHERE booking_code = %s AND status = %s", $args ) );
		if ( 1 !== $updated ) {
			$duplicate = false === $updated && false !== stripos( (string) $wpdb->last_error, 'duplicate' );
			$wpdb->query( 'ROLLBACK' );
			self::delete_files( $new );
			self::fail( $duplicate ? 'invoice_duplicate' : 'conflict', 409 );
		}

		$meta = array();
		$note = '';
		switch ( $act ) {
			case 'checkin':
				$meta = array(
					'tag_code'    => $facts['tag_code'],
					'actual_bags' => (int) $facts['actual_bags'],
					'booked_bags' => (int) $facts['booked_bags'],
					'photos'      => count( $new ),
				);
				$note = $facts['reason'];
				break;
			case 'depart':
				$meta = array( 'loop' => $facts['loop'] );
				break;
			case 'deliver':
				$meta = array(
					'receiver_type' => $facts['receiver_type'],
					'receiver_name' => $facts['receiver_name'],
					'proxy_ok'      => $facts['proxy_ok'],
					'photos'        => count( $new ),
				);
				break;
			case 'complete':
				$meta = array( 'invoice_no' => $facts['invoice_no'] );
				break;
			case 'cancel':
				$note = $facts['reason'];
				break;
			case 'no_show':
				$note = $facts['note'];
				break;
		}
		$logged = EasyLugg_Dashboard_Setup::audit(
			'booking',
			$row->booking_code,
			'status_change',
			array(
				'from_status' => $from,
				'to_status'   => $to,
				'note'        => $note,
				'meta'        => $meta ? $meta : null,
			)
		);
		if ( $logged && 'checkin' === $act && $gate['needs_review'] ) {
			$logged = EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'incident', array( 'note' => $facts['reason'], 'meta' => array( 'type' => 'bag_mismatch' ) ) );
		}
		if ( $logged && 'no_show' === $act && '1' === self::post( 'luggage_held' ) ) {
			$logged = EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'custody_held', array( 'note' => $facts['note'] ) );
		}
		if ( $logged && $new ) {
			foreach ( $new as $path ) {
				$logged = $logged && false !== $wpdb->insert(
					$wpdb->prefix . 'easylugg_booking_photos',
					array(
						'booking_code' => $row->booking_code,
						'stage'        => $stage,
						'path'         => $path,
						'actor_id'     => get_current_user_id(),
						'created_at'   => $now,
					),
					array( '%s', '%s', '%s', '%d', '%s' )
				);
			}
		}
		if ( ! $logged ) {
			$wpdb->query( 'ROLLBACK' );
			self::delete_files( $new );
			self::fail( 'save_failed', 500 );
		}
		$wpdb->query( 'COMMIT' );

		if ( 'confirm' === $act ) {
			$row->status = 'confirmed';
			self::email_customer( $row );
		}
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ) );
	}

	// ---- Create ------------------------------------------------------------

	/**
	 * Run the customer-form validators on staff input and return everything
	 * needed to store a booking. The validators answer with wp_send_json_error
	 * and stop, exactly as on the customer form (CRT-01).
	 *
	 * @param array<string, mixed> $input Unslashed input.
	 * @return array<string, mixed>
	 */
	private static function validate_booking( $input ) {
		$plugin = EasyLugg_Dashboard::plugin();
		// Staff attest the terms on the customer's behalf.
		$input['terms_service'] = '1';
		$input['terms_booking'] = '1';
		$corridor               = strtoupper( isset( $input['corridor'] ) && is_scalar( $input['corridor'] ) ? (string) $input['corridor'] : '' );
		if ( 'ST' === $corridor ) {
			$input['service']     = 'storage';
			$input['storage_hub'] = 'hoian';
		} else {
			$input['service'] = 'delivery';
			if ( empty( $input['delivery_date'] ) && ! empty( $input['service_date'] ) ) {
				$input['delivery_date'] = $input['service_date'];
			}
		}

		$service    = $plugin->validate_booking_service( $input );
		$is_storage = 'storage' === ( $service['service'] ?? '' );
		$bags       = $is_storage ? EasyLugg_Storage::validate_bags( $input, $plugin->texts() ) : $plugin->validate_bag_counts( $input );
		$customer   = $plugin->validate_customer_details( $input );
		$estimate   = $is_storage
			? EasyLugg_Storage::estimate( $bags['total'], $service['dropoff_date'], $service['pickup_date'], $plugin->prices() )
			: $plugin->calculate_estimate( $bags, $service['service_date'], isset( $service['delivery_date'] ) ? $service['delivery_date'] : $service['service_date'] );

		return array(
			'input'    => $input,
			'service'  => $service,
			'bags'     => $bags,
			'customer' => $customer,
			'estimate' => $estimate,
		);
	}

	/**
	 * Server-side future-time rule (TIME-01..06); answers with an error and stops.
	 *
	 * @param array<string, mixed> $service Validated service.
	 * @return void
	 */
	private static function enforce_future_time( $service ) {
		$buffer = EasyLugg_Dashboard_Config::buffer_minutes();
		$cutoff = EasyLugg_Dashboard_Config::hh_cutoff();
		$error  = EasyLugg_Dashboard_Rules::validate_future_time(
			array(
				'corridor'              => (string) $service['corridor'],
				'service_date'          => (string) $service['service_date'],
				'pickup_time'           => (string) ( $service['pickup_time'] ?? '' ),
				'airport_delivery_time' => (string) ( $service['airport_delivery_time'] ?? '' ),
				'dropoff_date'          => (string) ( $service['dropoff_date'] ?? '' ),
				'dropoff_time'          => (string) ( $service['dropoff_time'] ?? '' ),
			),
			current_time( 'Y-m-d H:i' ),
			$buffer,
			$cutoff
		);
		if ( 'too_soon' === $error ) {
			self::fail( 'too_soon', 400, $buffer );
		}
		if ( 'hh_cutoff' === $error ) {
			self::fail( 'hh_cutoff', 400, $cutoff );
		}
		if ( '' !== $error ) {
			self::fail( $error, 400 );
		}
	}

	/**
	 * @return void
	 */
	public static function create() {
		self::guard( 'easylugg_create_booking' );
		global $wpdb;
		$plugin = EasyLugg_Dashboard::plugin();
		$table  = EasyLugg_Booking_Plugin::table_name();

		// CRT-07: one-time key rendered with the form; a repeat returns the first booking.
		$request_key = self::post( 'request_key' );
		if ( ! wp_is_uuid( $request_key, 4 ) ) {
			self::fail( 'save_failed', 400 );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT booking_code FROM {$table} WHERE request_key = %s", $request_key ) );
		if ( $existing ) {
			self::ok( EasyLugg_Dashboard::t( 'form.saved' ), array( 'redirect' => EasyLugg_Dashboard::url( array( 'view' => $existing ) ), 'reload' => false ) );
		}

		$v = self::validate_booking( wp_unslash( $_POST ) );
		self::enforce_future_time( $v['service'] );

		$source = self::post( 'source' );
		if ( ! in_array( $source, array( 'internal', 'walk-in', 'phone', 'ota' ), true ) ) {
			self::fail( 'source_invalid', 400 );
		}
		$method = self::post( 'payment_method' );
		if ( ! in_array( $method, array( 'cash', 'bank_transfer', 'ota_paid' ), true ) || ( 'ota' === $source ) !== ( 'ota_paid' === $method ) ) {
			self::fail( 'payment_invalid', 400 );
		}
		$ota_code = sanitize_text_field( self::post( 'ota_code' ) );
		if ( 'ota' === $source && '' === $ota_code ) {
			self::fail( 'ota_code_required', 400 );
		}

		$is_storage = 'storage' === ( $v['service']['service'] ?? '' );
		$photo_path = '';
		if ( ! $is_storage ) {
			$photo_path = $plugin->upload_booking_photo( $v['service']['corridor'] );
			if ( is_wp_error( $photo_path ) ) {
				self::fail( 'photo_failed', 400 );
			}
		}

		$data = $plugin->build_booking_data( $v['input'], $v['service'], $v['bags'], $v['customer']['details'], $method, $v['estimate'] );
		if ( 'ota' === $source ) {
			$data['ota_code'] = $ota_code;
		}
		$code   = $plugin->generate_booking_code();
		$status = $v['service']['on_request'] ? 'requested' : 'confirmed';
		$token  = hash_hmac( 'sha256', $request_key, wp_salt( 'secure_auth' ) );
		$now    = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'booking_code'        => $code,
				'request_key'         => $request_key,
				'tracking_token_hash' => hash( 'sha256', $token ),
				'created_at'          => $now,
				'service_date'        => $v['service']['service_date'],
				'corridor'            => $v['service']['corridor'],
				'status'              => $status,
				'payment_method'      => $method,
				'payment_status'      => 'ota_paid' === $method ? 'ota_paid' : 'pending',
				'price_total'         => $v['estimate'],
				'customer_email'      => $v['customer']['email'],
				'customer_name'       => $v['customer']['name'],
				'booking_data'        => wp_json_encode( $data ),
				'photo_path'          => is_string( $photo_path ) ? $photo_path : '',
				'source'              => $source,
				'created_by'          => get_current_user_id(),
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( false === $inserted ) {
			if ( is_string( $photo_path ) && '' !== $photo_path ) {
				wp_delete_file( $photo_path );
			}
			$dup = $wpdb->get_var( $wpdb->prepare( "SELECT booking_code FROM {$table} WHERE request_key = %s", $request_key ) );
			if ( $dup ) {
				self::ok( EasyLugg_Dashboard::t( 'form.saved' ), array( 'redirect' => EasyLugg_Dashboard::url( array( 'view' => $dup ) ), 'reload' => false ) );
			}
			self::fail( 'save_failed', 500 );
		}

		EasyLugg_Dashboard_Setup::audit(
			'booking',
			$code,
			'created',
			array(
				'to_status' => $status,
				'meta'      => array(
					'source'  => $source,
					'payment' => $method,
					'total'   => (int) $v['estimate'],
				),
			)
		);
		self::email_customer(
			(object) array(
				'booking_code'   => $code,
				'status'         => $status,
				'price_total'    => $v['estimate'],
				'request_key'    => $request_key,
				'customer_email' => $v['customer']['email'],
				'corridor'       => $v['service']['corridor'],
			)
		);
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ), array( 'redirect' => EasyLugg_Dashboard::url( array( 'view' => $code ) ), 'reload' => false ) );
	}

	// ---- Edit --------------------------------------------------------------

	/**
	 * Changed leaf values between two nested arrays, as [{field, old, new}].
	 *
	 * @param array<string, mixed> $old Old data.
	 * @param array<string, mixed> $new New data.
	 * @return array<int, array<string, mixed>>
	 */
	private static function diff( $old, $new ) {
		$flat = function ( $arr, $prefix = '' ) use ( &$flat ) {
			$out = array();
			foreach ( $arr as $key => $value ) {
				$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
				if ( is_array( $value ) ) {
					$out += $flat( $value, $path );
				} else {
					$out[ $path ] = $value;
				}
			}
			return $out;
		};
		$a       = $flat( $old );
		$b       = $flat( $new );
		$changes = array();
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
			if ( 'terms_accepted_at' === $key || 'estimate' === $key || 'storage_fee' === $key ) {
				continue;
			}
			$x = $a[ $key ] ?? null;
			$y = $b[ $key ] ?? null;
			if ( (string) $x !== (string) $y ) {
				$changes[] = array( 'field' => $key, 'old' => $x, 'new' => $y );
			}
		}
		return $changes;
	}

	/**
	 * @return void
	 */
	public static function edit() {
		self::guard( 'easylugg_edit_booking' );
		global $wpdb;
		$plugin = EasyLugg_Dashboard::plugin();
		$row    = self::load_booking();
		$mode   = EasyLugg_Dashboard_Rules::edit_mode( $row->status );
		if ( 'note' === $mode ) {
			self::fail( 'not_editable', 409 );
		}
		if ( (int) self::post( 'row_version' ) !== (int) $row->row_version ) {
			self::fail( 'conflict', 409 );
		}

		$old_data = EasyLugg_Dashboard::decode( $row->booking_data );
		$input    = wp_unslash( $_POST );
		$set_sql  = 'booking_data = %s, customer_name = %s, customer_email = %s, row_version = row_version + 1, updated_at = %s';
		$old_total = (int) $row->price_total;

		if ( 'contact' === $mode ) {
			// EDT-01: once collected, route/date/bags can no longer change (a crafted request is refused).
			if ( isset( $input['corridor'] ) || isset( $input['service_date'] ) || isset( $input['bags'] ) || isset( $input['storage_bags'] ) ) {
				self::fail( 'not_editable', 403 );
			}
			$input['terms_service'] = '1';
			$input['terms_booking'] = '1';
			$customer               = $plugin->validate_customer_details( $input );
			$details                = $customer['details'];
			$details['passport']    = $old_data['customer']['passport'] ?? '';
			$new_data               = $old_data;
			$new_data['customer']   = array_merge( isset( $old_data['customer'] ) && is_array( $old_data['customer'] ) ? $old_data['customer'] : array(), $details );
			$new_data['note']       = isset( $input['note'] ) && is_scalar( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : '';
			$vals                   = array( wp_json_encode( $new_data ), $customer['name'], $customer['email'], current_time( 'mysql', true ) );
			$new_total              = $old_total;
		} else {
			$input['corridor'] = $row->corridor; // The route cannot switch service type on edit.
			$v                 = self::validate_booking( $input );
			$old_sched         = array( $row->service_date, $old_data['pickup_time'] ?? '', $old_data['airport_delivery_time'] ?? '', $old_data['dropoff_date'] ?? '', $old_data['dropoff_time'] ?? '' );
			$new_sched         = array( $v['service']['service_date'], $v['service']['pickup_time'] ?? '', $v['service']['airport_delivery_time'] ?? '', $v['service']['dropoff_date'] ?? '', $v['service']['dropoff_time'] ?? '' );
			// TIME-06: the future rule applies only when the date/time changed.
			if ( $old_sched !== $new_sched ) {
				self::enforce_future_time( $v['service'] );
			}
			$built                  = $plugin->build_booking_data( $v['input'], $v['service'], $v['bags'], $v['customer']['details'], $row->payment_method, $v['estimate'] );
			$built['terms_accepted_at'] = $old_data['terms_accepted_at'] ?? $built['terms_accepted_at'];
			$built['customer']['passport'] = $old_data['customer']['passport'] ?? '';
			$new_data               = array_merge( $old_data, $built );
			$new_total              = (int) $v['estimate'];
			$vals                   = array( wp_json_encode( $new_data ), $v['customer']['name'], $v['customer']['email'], current_time( 'mysql', true ) );
			$set_sql               .= ', service_date = %s, corridor = %s, price_total = %d';
			$vals[]                 = $v['service']['service_date'];
			$vals[]                 = $v['service']['corridor'];
			$vals[]                 = $new_total;
		}

		$changes = self::diff( $old_data, $new_data );
		if ( ! $changes && $new_total === $old_total ) {
			self::ok( EasyLugg_Dashboard::t( 'form.saved' ), array( 'redirect' => EasyLugg_Dashboard::url( array( 'view' => $row->booking_code ) ), 'reload' => false ) );
		}

		$vals[] = $row->booking_code;
		$vals[] = (int) $row->row_version;
		$vals[] = $row->status;
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . EasyLugg_Booking_Plugin::table_name() . " SET {$set_sql} WHERE booking_code = %s AND row_version = %d AND status = %s", $vals ) );
		$logged  = 1 === $updated && EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'edit', array( 'meta' => array( 'changes' => $changes ) ) );
		if ( $logged && $new_total !== $old_total ) {
			$logged = EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'price_recalculated', array( 'meta' => array( 'old' => $old_total, 'new' => $new_total ) ) );
		}
		if ( ! $logged ) {
			$wpdb->query( 'ROLLBACK' );
			self::fail( 1 === $updated ? 'save_failed' : 'conflict', 1 === $updated ? 500 : 409 );
		}
		$wpdb->query( 'COMMIT' );
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ), array( 'redirect' => EasyLugg_Dashboard::url( array( 'view' => $row->booking_code ) ), 'reload' => false ) );
	}

	// ---- Payment, notes, incident, custody, review ------------------------

	/**
	 * @return void
	 */
	public static function record_payment() {
		self::guard( 'easylugg_operate_booking' );
		global $wpdb;
		$row    = self::load_booking();
		$amount = self::post( 'amount' );
		$method = self::post( 'method' );
		if ( ! ctype_digit( $amount ) || ! in_array( $method, array( 'cash', 'bank_transfer' ), true ) ) {
			self::fail( 'amount_invalid', 400 );
		}
		$invoice = self::post( 'invoice_no' );
		if ( '' !== $invoice && ! EasyLugg_Dashboard_Rules::valid_invoice_no( $invoice ) ) {
			self::fail( 'invoice_required', 400 );
		}
		$flag = (int) $amount !== (int) $row->price_total ? ', needs_review = 1' : '';

		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . EasyLugg_Booking_Plugin::table_name() . " SET payment_status = 'paid', payment_method = %s, invoice_no = COALESCE(NULLIF(%s, ''), invoice_no), row_version = row_version + 1, updated_at = %s{$flag} WHERE booking_code = %s AND payment_status NOT IN ('paid','ota_paid') AND status NOT IN ('cancelled','no_show')",
				$method,
				$invoice,
				current_time( 'mysql', true ),
				$row->booking_code
			)
		);
		if ( 1 !== $updated ) {
			$duplicate = false === $updated && false !== stripos( (string) $wpdb->last_error, 'duplicate' );
			$wpdb->query( 'ROLLBACK' );
			self::fail( $duplicate ? 'invoice_duplicate' : 'already_paid', 409 );
		}
		$logged = EasyLugg_Dashboard_Setup::audit(
			'booking',
			$row->booking_code,
			'payment_recorded',
			array(
				'meta' => array(
					'amount'      => (int) $amount,
					'expected'    => (int) $row->price_total,
					'method'      => $method,
					'invoice_no'  => $invoice,
					'received_by' => get_current_user_id(),
				),
			)
		);
		if ( ! $logged ) {
			$wpdb->query( 'ROLLBACK' );
			self::fail( 'save_failed', 500 );
		}
		$wpdb->query( 'COMMIT' );
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ) );
	}

	/**
	 * @return void
	 */
	public static function add_note() {
		self::guard();
		if ( ! current_user_can( 'easylugg_operate_booking' ) && ! current_user_can( 'easylugg_edit_booking' ) ) {
			wp_send_json_error( array( 'message' => EasyLugg_Dashboard::t( 'gate.forbidden' ) ), 403 );
		}
		$row  = self::load_booking();
		$note = self::post( 'note' );
		if ( '' === $note ) {
			self::fail( 'note_required', 400 );
		}
		EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'note', array( 'note' => $note ) );
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ) );
	}

	/**
	 * @return void
	 */
	public static function report_incident() {
		self::guard( 'easylugg_operate_booking' );
		global $wpdb;
		$row  = self::load_booking();
		$note = self::post( 'note' );
		if ( '' === $note ) {
			self::fail( 'note_required', 400 );
		}
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . EasyLugg_Booking_Plugin::table_name() . ' SET needs_review = 1, row_version = row_version + 1, updated_at = %s WHERE booking_code = %s', current_time( 'mysql', true ), $row->booking_code ) );
		if ( false === $updated || ! EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'incident', array( 'note' => $note ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::fail( 'save_failed', 500 );
		}
		$wpdb->query( 'COMMIT' );
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ) );
	}

	/**
	 * @return void
	 */
	public static function release_custody() {
		self::guard( 'easylugg_operate_booking' );
		$row = self::load_booking();
		if ( 'no_show' !== $row->status ) {
			self::fail( 'illegal_transition', 400 );
		}
		EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'custody_released', array( 'note' => self::post( 'note' ) ) );
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ) );
	}

	/**
	 * @return void
	 */
	public static function clear_review() {
		self::guard( 'easylugg_clear_review' );
		global $wpdb;
		$row = self::load_booking();
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . EasyLugg_Booking_Plugin::table_name() . ' SET needs_review = 0, row_version = row_version + 1, updated_at = %s WHERE booking_code = %s', current_time( 'mysql', true ), $row->booking_code ) );
		if ( false === $updated || ! EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'review_cleared', array( 'note' => self::post( 'note' ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::fail( 'save_failed', 500 );
		}
		$wpdb->query( 'COMMIT' );
		self::ok( EasyLugg_Dashboard::t( 'form.saved' ) );
	}

	// ---- Settings ----------------------------------------------------------

	/**
	 * @return void
	 */
	public static function save_config() {
		self::guard( 'easylugg_manage_config' );
		$group  = sanitize_key( self::post( 'group' ) );
		$result = EasyLugg_Dashboard_Config::validate_group( $group, wp_unslash( $_POST ) );
		if ( '' !== $result['error'] ) {
			self::fail( $result['error'], 400 );
		}
		$changed = EasyLugg_Dashboard_Config::save_group( EasyLugg_Dashboard::plugin(), $group, $result['clean'] );
		self::ok( EasyLugg_Dashboard::tf( 'settings.saved', $changed ) );
	}

	// ---- Photo download (DET-05) ------------------------------------------

	/**
	 * @return void
	 */
	public static function photo() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( EasyLugg_Dashboard::NONCE, 'nonce', false ) ) {
			wp_die( esc_html( EasyLugg_Dashboard::t( 'gate.session' ) ), '', array( 'response' => 403 ) );
		}
		if ( EasyLugg_Dashboard_Setup::is_locked( get_current_user_id() ) || ! current_user_can( 'easylugg_view_dashboard' ) ) {
			wp_die( esc_html( EasyLugg_Dashboard::t( 'gate.forbidden' ) ), '', array( 'response' => 403 ) );
		}
		global $wpdb;
		$plugin = EasyLugg_Dashboard::plugin();
		$code   = isset( $_GET['code'] ) && is_scalar( $_GET['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( 0 === $id ) {
			$path = $wpdb->get_var( $wpdb->prepare( 'SELECT photo_path FROM ' . EasyLugg_Booking_Plugin::table_name() . ' WHERE booking_code = %s', $code ) );
		} else {
			$path = $wpdb->get_var( $wpdb->prepare( 'SELECT path FROM ' . $wpdb->prefix . 'easylugg_booking_photos WHERE id = %d AND booking_code = %s', $id, $code ) );
		}
		$real_dir  = realpath( $plugin->private_photo_directory() );
		$real_file = $path ? realpath( $path ) : false;
		if ( ! $real_dir || ! $real_file || 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) || ! is_file( $real_file ) ) {
			wp_die( esc_html( EasyLugg_Dashboard::t( 'err.not_found' ) ), '', array( 'response' => 404 ) );
		}
		$image = getimagesize( $real_file );
		if ( ! $image || ! in_array( $image['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			wp_die( esc_html( EasyLugg_Dashboard::t( 'err.photo_failed' ) ), '', array( 'response' => 415 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $image['mime'] );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $code . '-' . $id . ( 'image/png' === $image['mime'] ? '.png' : '.jpg' ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $real_file );
		exit;
	}
}
