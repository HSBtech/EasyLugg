<?php
/**
 * Dashboard form markup: create/edit booking and the per-status action forms.
 * Field names deliberately match the customer form so the customer validators
 * can be reused unchanged (SRS CRT-01). SRS refs: CRT, EDT, OPS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Dashboard_Forms {

	/**
	 * @param string $key Dotted text key.
	 * @return string
	 */
	private static function t( $key ) {
		return EasyLugg_Dashboard::t( $key );
	}

	/**
	 * One labelled input. $off = the field belongs to another route: it is
	 * rendered disabled so it is never submitted (same-named fields exist on
	 * several routes, e.g. pickup_time).
	 *
	 * @param string $name  Field name.
	 * @param string $label Label text.
	 * @param string $value Current value.
	 * @param bool   $off   Disabled.
	 * @param string $attrs Extra attributes (already escaped).
	 * @param string $type  Input type.
	 * @return void
	 */
	private static function input( $name, $label, $value, $off = false, $attrs = '', $type = 'text' ) {
		echo '<label class="eld-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( $off ? ' disabled' : '' ) . ' ' . $attrs . '></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * @param string               $name    Field name.
	 * @param string               $label   Label.
	 * @param array<string, string> $options value => label.
	 * @param string               $current Selected value.
	 * @param bool                 $off     Disabled.
	 * @param string               $attrs   Extra attributes.
	 * @return void
	 */
	private static function select( $name, $label, $options, $current, $off = false, $attrs = '' ) {
		echo '<label class="eld-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '"' . ( $off ? ' disabled' : '' ) . ' ' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $current, (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * @param string $routes  Space-separated routes this block belongs to.
	 * @param string $current Current corridor.
	 * @return bool Whether the block is off (belongs to another route).
	 */
	private static function open_panel( $routes, $current ) {
		$off = ! in_array( $current, explode( ' ', $routes ), true );
		echo '<div class="eld-grid" data-routes="' . esc_attr( $routes ) . '"' . ( $off ? ' hidden' : '' ) . '>';
		return $off;
	}

	/**
	 * @return array<string, string>
	 */
	private static function slot_options( $slots, $with_blank = true ) {
		$options = $with_blank ? array( '' => '—' ) : array();
		foreach ( $slots as $slot ) {
			$options[ $slot ] = $slot;
		}
		return $options;
	}

	/**
	 * @param string $prefix from|to.
	 * @param array  $v      Values.
	 * @param bool   $off    Disabled.
	 * @return void
	 */
	private static function hotel_fields( $prefix, $v, $off ) {
		$title = 'from' === $prefix ? self::t( 'form.from_hotel' ) : self::t( 'form.to_hotel' );
		echo '<p class="eld-sub">' . esc_html( $title ) . '</p>';
		self::input( $prefix . '_hotel_name', self::t( 'form.hotel_name' ), $v[ $prefix . '_hotel_name' ] ?? '', $off, 'maxlength="190"' );
		self::input( $prefix . '_hotel_address', self::t( 'form.hotel_address' ), $v[ $prefix . '_hotel_address' ] ?? '', $off, 'maxlength="300"' );
		self::select(
			$prefix . '_reception',
			self::t( 'form.reception' ),
			array(
				''    => self::t( 'form.unknown' ),
				'yes' => self::t( 'form.yes' ),
				'no'  => self::t( 'form.no' ),
			),
			$v[ $prefix . '_reception' ] ?? '',
			$off
		);
		self::input( $prefix . '_booking_code', self::t( 'form.hotel_code' ), $v[ $prefix . '_booking_code' ] ?? '', $off, 'maxlength="80"' );
	}

	/**
	 * Contact channel block (one channel; the validator accepts up to two).
	 *
	 * @param array $v Values.
	 * @return void
	 */
	private static function contact_fields( $v ) {
		$channels = array(
			'whatsapp'  => self::plugin_text( 'step2.channel_whatsapp' ),
			'zalo'      => self::plugin_text( 'step2.channel_zalo' ),
			'kakao'     => self::plugin_text( 'step2.channel_kakao' ),
			'messenger' => self::plugin_text( 'step2.channel_messenger' ),
		);
		$codes = array();
		foreach ( EasyLugg_Booking_Plugin::country_codes() as $code => $country ) {
			$codes[ $code ] = '+' . $code . ' ' . $country;
		}
		self::input( 'full_name', self::t( 'form.full_name' ), $v['full_name'] ?? '', false, 'maxlength="190" required' );
		self::input( 'email', self::t( 'form.email' ), $v['email'] ?? '', false, 'maxlength="190" required', 'email' );
		self::select( 'channels[0][channel]', self::t( 'form.channel' ), $channels, $v['channel'] ?? 'whatsapp' );
		self::select( 'channels[0][country_code]', self::t( 'form.country' ), $codes, $v['country_code'] ?? '84' );
		self::input( 'channels[0][phone]', self::t( 'form.phone' ), $v['phone'] ?? '', false, 'inputmode="tel" maxlength="30"' );
		self::input( 'channels[0][contact_id]', self::t( 'form.contact_id' ), $v['contact_id'] ?? '', false, 'maxlength="120"' );
	}

	/**
	 * @param string $key Plugin text key.
	 * @return string
	 */
	private static function plugin_text( $key ) {
		return EasyLugg_Dashboard::plugin()->text( $key );
	}

	/**
	 * Booking fields shared by create and (full-mode) edit.
	 *
	 * @param array<string, mixed> $v        Current values.
	 * @param bool                 $can_pick Whether the route can be chosen (create only).
	 * @return void
	 */
	private static function booking_fields( $v, $can_pick, $storage_only = false ) {
		$corridor = $v['corridor'] ?? 'AH';
		$slots    = EasyLugg_Dashboard_Config::airport_slots();
		$st_slots = EasyLugg_Dashboard_Config::storage_slots();
		$routes   = array(
			'AH' => self::t( 'form.ah' ),
			'HA' => self::t( 'form.ha' ),
			'HH' => self::t( 'form.hh' ),
			'ST' => self::t( 'form.st' ),
		);
		if ( ! $can_pick ) {
			$routes = array( $corridor => $routes[ $corridor ] );
			echo '<input type="hidden" name="corridor" value="' . esc_attr( $corridor ) . '">';
		}
		echo '<div class="eld-grid">';
		if ( $can_pick ) {
			self::select( 'corridor', self::t( 'form.route' ), $routes, $corridor, false, 'data-eld-route' );
		} else {
			echo '<p class="eld-sub">' . esc_html( $routes[ $corridor ] ) . '</p>';
		}
		echo '</div>';

		$today = current_time( 'Y-m-d' );

		self::open_panel( 'AH HA HH', $corridor );
		$off = ! in_array( $corridor, array( 'AH', 'HA', 'HH' ), true );
		self::input( 'service_date', self::t( 'form.service_date' ), $v['service_date'] ?? $today, $off, 'min="' . esc_attr( $today ) . '" required', 'date' );
		self::input( 'delivery_date', self::t( 'form.delivery_date' ), $v['delivery_date'] ?? ( $v['service_date'] ?? $today ), $off, 'min="' . esc_attr( $today ) . '" required', 'date' );
		echo '</div>';

		self::open_panel( 'AH HA', $corridor );
		self::input( 'flight_number', self::t( 'form.flight' ), $v['flight_number'] ?? '', ! in_array( $corridor, array( 'AH', 'HA' ), true ), 'maxlength="7" required' );
		echo '</div>';

		self::open_panel( 'AH', $corridor );
		self::select( 'pickup_time', self::t( 'form.pickup_time' ), self::slot_options( $slots ), $v['pickup_time'] ?? '', 'AH' !== $corridor, 'required' );
		echo '</div>';

		self::open_panel( 'HA', $corridor );
		self::select( 'airport_delivery_time', self::t( 'form.delivery_time' ), self::slot_options( $slots ), $v['airport_delivery_time'] ?? '', 'HA' !== $corridor, 'required' );
		echo '</div>';

		self::open_panel( 'HA HH', $corridor );
		self::hotel_fields( 'from', $v, ! in_array( $corridor, array( 'HA', 'HH' ), true ) );
		echo '</div>';

		self::open_panel( 'AH HH', $corridor );
		self::hotel_fields( 'to', $v, ! in_array( $corridor, array( 'AH', 'HH' ), true ) );
		echo '</div>';

		$st_off = 'ST' !== $corridor;
		self::open_panel( 'ST', $corridor );
		self::input( 'dropoff_date', self::t( 'form.dropoff_date' ), $v['dropoff_date'] ?? $today, $st_off, 'min="' . esc_attr( $today ) . '" required', 'date' );
		self::select( 'dropoff_time', self::t( 'form.dropoff_time' ), self::slot_options( $st_slots ), $v['dropoff_time'] ?? '', $st_off, 'required' );
		self::input( 'pickup_date', self::t( 'form.pickup_date' ), $v['pickup_date'] ?? $today, $st_off, 'min="' . esc_attr( $today ) . '" required', 'date' );
		self::select( 'pickup_time', self::t( 'form.storage_pickup_time' ), self::slot_options( $st_slots ), $v['pickup_time'] ?? '', $st_off, 'required' );
		echo '</div>';

		self::open_panel( 'AH HA HH', $corridor );
		$bag_off = 'ST' === $corridor;
		foreach ( array( 'cabin', 'medium', 'oversize' ) as $size ) {
			self::input( 'bags[' . $size . ']', self::t( 'form.' . $size ), $v[ 'bags_' . $size ] ?? 0, $bag_off, 'min="0" max="20" step="1" inputmode="numeric"', 'number' );
		}
		echo '</div>';

		self::open_panel( 'ST', $corridor );
		self::input( 'storage_bags', self::t( 'form.storage_bags' ), $v['storage_bags'] ?? 1, $st_off, 'min="1" step="1" inputmode="numeric"', 'number' );
		echo '</div>';

		echo '<div class="eld-grid">';
		self::contact_fields( $v );
		self::input( 'promotion_code', self::t( 'form.promo' ), $v['promotion_code'] ?? '', false, 'maxlength="30"' );
		echo '<label class="eld-field eld-wide"><span>' . esc_html( self::t( 'form.note' ) ) . '</span><textarea name="note" rows="2" maxlength="500">' . esc_textarea( $v['note'] ?? '' ) . '</textarea></label>';
		echo '</div>';
	}

	/**
	 * @return void
	 */
	public static function render_create_screen() {
		$v = array( 'corridor' => 'AH' );
		?>
		<section class="eld-card">
			<h3><?php echo esc_html( self::t( 'form.create_title' ) ); ?></h3>
			<noscript><p><?php echo esc_html( EasyLugg_Dashboard::plugin()->text( 'page.noscript' ) ); ?></p></noscript>
			<form class="eld-form" data-action="easylugg_dashboard_create" enctype="multipart/form-data">
				<input type="hidden" name="action" value="easylugg_dashboard_create">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( EasyLugg_Dashboard::NONCE ) ); ?>">
				<input type="hidden" name="request_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<?php self::booking_fields( $v, true ); ?>
				<div class="eld-grid">
					<?php
					self::select(
						'source',
						self::t( 'form.source' ),
						array(
							'internal' => self::t( 'source.internal' ),
							'walk-in'  => self::t( 'source.walk-in' ),
							'phone'    => self::t( 'source.phone' ),
							'ota'      => self::t( 'source.ota' ),
						),
						'internal',
						false,
						'data-eld-source'
					);
					self::select(
						'payment_method',
						self::t( 'form.payment' ),
						array(
							'cash'          => self::t( 'pay.cash' ),
							'bank_transfer' => self::t( 'pay.bank_transfer' ),
							'ota_paid'      => self::t( 'pay.ota_paid' ),
						),
						'cash',
						false,
						'data-eld-payment'
					);
					self::input( 'ota_code', self::t( 'form.ota_code' ), '', false, 'maxlength="80" data-eld-ota' );
					?>
					<label class="eld-field"><span><?php echo esc_html( self::t( 'form.photo' ) ); ?></span><input type="file" name="luggage_photo" accept="image/jpeg,image/png"></label>
				</div>
				<p><small><?php echo esc_html( self::t( 'form.price_note' ) ); ?></small></p>
				<button type="submit" class="eld-btn eld-primary"><?php echo esc_html( self::t( 'form.submit_create' ) ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Map a stored booking back to form field values.
	 *
	 * @param object               $row  Booking row.
	 * @param array<string, mixed> $data Decoded booking_data.
	 * @return array<string, mixed>
	 */
	public static function values_from_booking( $row, $data ) {
		$v = array(
			'corridor'              => $row->corridor,
			'service_date'          => $row->service_date,
			'delivery_date'         => $data['delivery_date'] ?? $row->service_date,
			'flight_number'         => $data['flight_number'] ?? '',
			'pickup_time'           => $data['pickup_time'] ?? '',
			'airport_delivery_time' => $data['airport_delivery_time'] ?? '',
			'dropoff_date'          => $data['dropoff_date'] ?? '',
			'dropoff_time'          => $data['dropoff_time'] ?? '',
			'pickup_date'           => $data['pickup_date'] ?? '',
			'from_booking_code'     => $data['from_booking_code'] ?? '',
			'to_booking_code'       => $data['to_booking_code'] ?? '',
			'promotion_code'        => $data['promotion_code'] ?? '',
			'note'                  => $data['note'] ?? '',
			'storage_bags'          => (int) ( $data['bags']['total'] ?? 1 ),
			'bags_cabin'            => (int) ( $data['bags']['cabin'] ?? 0 ),
			'bags_medium'           => (int) ( $data['bags']['medium'] ?? 0 ),
			'bags_oversize'         => (int) ( $data['bags']['oversize'] ?? 0 ),
		);
		foreach ( array( 'from', 'to' ) as $prefix ) {
			$hotel = isset( $data[ $prefix . '_hotel' ] ) && is_array( $data[ $prefix . '_hotel' ] ) ? $data[ $prefix . '_hotel' ] : array();
			$v[ $prefix . '_hotel_name' ]    = $hotel['name'] ?? '';
			$v[ $prefix . '_hotel_address' ] = $hotel['address'] ?? '';
			$v[ $prefix . '_reception' ]     = $hotel['reception'] ?? '';
			// Coordinates are carried through unchanged on edit (hidden fields).
			$v[ $prefix . '_hotel_lat' ] = $hotel['latitude'] ?? '';
			$v[ $prefix . '_hotel_lng' ] = $hotel['longitude'] ?? '';
		}
		$customer = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		$v['full_name'] = $customer['full_name'] ?? $row->customer_name;
		$v['email']     = $customer['email'] ?? $row->customer_email;
		$channel        = isset( $customer['contact_channels'][0] ) ? $customer['contact_channels'][0] : array();
		$v['channel']   = $channel['channel'] ?? 'whatsapp';
		$value          = (string) ( $channel['value'] ?? '' );
		if ( in_array( $v['channel'], array( 'whatsapp', 'zalo' ), true ) && 0 === strpos( $value, '+' ) ) {
			$best = '';
			foreach ( array_keys( EasyLugg_Booking_Plugin::country_codes() ) as $code ) {
				if ( 0 === strpos( substr( $value, 1 ), (string) $code ) && strlen( (string) $code ) > strlen( $best ) ) {
					$best = (string) $code;
				}
			}
			$v['country_code'] = '' !== $best ? $best : '84';
			$v['phone']        = '' !== $best ? substr( $value, 1 + strlen( $best ) ) : ltrim( $value, '+' );
		} else {
			$v['contact_id'] = $value;
		}
		return $v;
	}

	/**
	 * @param string $code Booking code.
	 * @return void
	 */
	public static function render_edit_screen( $code ) {
		global $wpdb;
		echo '<p><a href="' . esc_url( EasyLugg_Dashboard::url( array( 'view' => $code ) ) ) . '">' . esc_html( self::t( 'nav.back' ) ) . '</a></p>';
		if ( ! current_user_can( 'easylugg_edit_booking' ) ) {
			echo '<p>' . esc_html( self::t( 'gate.forbidden' ) ) . '</p>';
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EasyLugg_Booking_Plugin::table_name() . ' WHERE booking_code = %s', $code ) );
		if ( ! $row ) {
			echo '<p>' . esc_html( self::t( 'detail.not_found' ) ) . '</p>';
			return;
		}
		$mode = EasyLugg_Dashboard_Rules::edit_mode( $row->status );
		if ( 'note' === $mode ) {
			echo '<p>' . esc_html( self::t( 'form.edit_closed' ) ) . '</p>';
			return;
		}
		// The edit form shows the same personal data as Detail, so it is logged the same way (DET-04).
		EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'pii_view', array( 'meta' => array( 'screen' => 'edit' ) ) );
		$data = EasyLugg_Dashboard::decode( $row->booking_data );
		$v    = self::values_from_booking( $row, $data );
		?>
		<section class="eld-card">
			<h3><?php echo esc_html( self::t( 'form.edit_title' ) ); ?> — <?php echo esc_html( $row->booking_code ); ?></h3>
			<form class="eld-form" data-action="easylugg_dashboard_edit" data-redirect="<?php echo esc_url( EasyLugg_Dashboard::url( array( 'view' => $row->booking_code ) ) ); ?>">
				<input type="hidden" name="action" value="easylugg_dashboard_edit">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( EasyLugg_Dashboard::NONCE ) ); ?>">
				<input type="hidden" name="code" value="<?php echo esc_attr( $row->booking_code ); ?>">
				<input type="hidden" name="row_version" value="<?php echo esc_attr( (string) (int) $row->row_version ); ?>">
				<?php
				if ( 'full' === $mode ) {
					foreach ( array( 'from', 'to' ) as $prefix ) {
						foreach ( array( 'lat', 'lng' ) as $part ) {
							echo '<input type="hidden" name="' . esc_attr( $prefix . '_hotel_' . $part ) . '" value="' . esc_attr( (string) ( $v[ $prefix . '_hotel_' . $part ] ?? '' ) ) . '">';
						}
					}
					self::booking_fields( $v, false );
					echo '<p><small>' . esc_html( self::t( 'form.price_note' ) ) . '</small></p>';
				} else {
					echo '<p><small>' . esc_html( self::t( 'form.edit_limited' ) ) . '</small></p><div class="eld-grid">';
					self::contact_fields( $v );
					echo '<label class="eld-field eld-wide"><span>' . esc_html( self::t( 'form.note' ) ) . '</span><textarea name="note" rows="2" maxlength="500">' . esc_textarea( $v['note'] ) . '</textarea></label></div>';
				}
				?>
				<button type="submit" class="eld-btn eld-primary"><?php echo esc_html( self::t( 'form.submit_edit' ) ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Custody state of a no-show order: '' | held | released.
	 *
	 * @param string $code Booking code.
	 * @return string
	 */
	private static function custody_state( $code ) {
		global $wpdb;
		$audit    = $wpdb->prefix . 'easylugg_audit_log';
		$last_act = $wpdb->get_var(
			$wpdb->prepare( "SELECT action FROM {$audit} WHERE entity_type = 'booking' AND entity_id = %s AND action IN ('custody_held','custody_released') ORDER BY id DESC LIMIT 1", $code )
		);
		return 'custody_held' === $last_act ? 'held' : ( 'custody_released' === $last_act ? 'released' : '' );
	}

	/**
	 * Action forms for the detail page. Invalid actions are not rendered (DET-03).
	 *
	 * @param object               $row  Booking row.
	 * @param array<string, mixed> $data Decoded booking_data.
	 * @param array<int, string>   $caps Capabilities of the current user.
	 * @param bool                 $paid Whether payment is recorded.
	 * @return void
	 */
	public static function render_actions( $row, $data, $caps, $paid ) {
		$actions = EasyLugg_Dashboard_Rules::available_actions( $row->status, $caps, $paid );
		if ( ! $actions && in_array( $row->status, EasyLugg_Dashboard_Rules::terminal_statuses(), true ) ) {
			echo '<p>' . esc_html( self::t( 'detail.no_actions' ) ) . '</p>';
		}
		foreach ( $actions as $action ) {
			self::action_form( $action, $row, $data, $paid );
		}

		$closed = in_array( $row->status, array( 'cancelled', 'no_show' ), true );

		// Payment: any live order that has not been paid yet.
		if ( ! $paid && ! $closed && in_array( 'easylugg_operate_booking', $caps, true ) ) {
			self::simple_form(
				'record_payment',
				$row,
				self::t( 'act.payment' ),
				function () use ( $row ) {
					self::input( 'amount', self::t( 'field.amount' ), (int) $row->price_total, false, 'min="0" step="1000" required inputmode="numeric"', 'number' );
					self::select(
						'method',
						self::t( 'field.method' ),
						array(
							'cash'          => self::t( 'pay.cash' ),
							'bank_transfer' => self::t( 'pay.bank_transfer' ),
						),
						$row->payment_method
					);
					self::input( 'invoice_no', self::t( 'field.invoice_no' ), $row->invoice_no, false, 'maxlength="40"' );
				}
			);
		}

		if ( in_array( 'easylugg_operate_booking', $caps, true ) || in_array( 'easylugg_edit_booking', $caps, true ) ) {
			self::simple_form(
				'add_note',
				$row,
				self::t( 'act.note' ),
				function () {
					echo '<label class="eld-field eld-wide"><span>' . esc_html( self::t( 'field.note' ) ) . '</span><textarea name="note" rows="2" required maxlength="1000"></textarea></label>';
				}
			);
		}

		if ( in_array( 'easylugg_operate_booking', $caps, true ) ) {
			self::simple_form(
				'report_incident',
				$row,
				self::t( 'act.incident' ),
				function () {
					echo '<label class="eld-field eld-wide"><span>' . esc_html( self::t( 'field.incident_type' ) ) . '</span><textarea name="note" rows="2" required maxlength="1000"></textarea></label>';
				}
			);
			if ( 'no_show' === $row->status && 'held' === self::custody_state( $row->booking_code ) ) {
				self::simple_form( 'release_custody', $row, self::t( 'act.release' ), function () {
					echo '<label class="eld-field eld-wide"><span>' . esc_html( self::t( 'field.note' ) ) . '</span><textarea name="note" rows="2" maxlength="1000"></textarea></label>';
				}, true );
			}
		}

		if ( (int) $row->needs_review && in_array( 'easylugg_clear_review', $caps, true ) ) {
			self::simple_form( 'clear_review', $row, self::t( 'act.clear_review' ), function () {
				echo '<label class="eld-field eld-wide"><span>' . esc_html( self::t( 'field.note' ) ) . '</span><textarea name="note" rows="2" maxlength="1000"></textarea></label>';
			} );
		}
	}

	/**
	 * @param string   $handler Handler suffix (easylugg_dashboard_<handler>).
	 * @param object   $row     Booking row.
	 * @param string   $title   Summary text.
	 * @param callable $fields  Prints the fields.
	 * @param bool     $confirm Ask for confirmation first.
	 * @return void
	 */
	private static function simple_form( $handler, $row, $title, $fields, $confirm = false ) {
		echo '<details class="eld-act"><summary>' . esc_html( $title ) . '</summary>';
		echo '<form class="eld-form" data-action="easylugg_dashboard_' . esc_attr( $handler ) . '"' . ( $confirm ? ' data-confirm="' . esc_attr( self::t( 'act.confirm_prompt' ) ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="easylugg_dashboard_' . esc_attr( $handler ) . '"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( EasyLugg_Dashboard::NONCE ) ) . '"><input type="hidden" name="code" value="' . esc_attr( $row->booking_code ) . '">';
		echo '<div class="eld-grid">';
		call_user_func( $fields );
		echo '</div><button type="submit" class="eld-btn">' . esc_html( self::t( 'act.submit' ) ) . '</button></form></details>';
	}

	/**
	 * One state-machine action form.
	 *
	 * @param string               $action Action key.
	 * @param object               $row    Booking row.
	 * @param array<string, mixed> $data   Decoded booking_data.
	 * @param bool                 $paid   Payment recorded.
	 * @return void
	 */
	private static function action_form( $action, $row, $data, $paid ) {
		$confirm = in_array( $action, array( 'cancel', 'no_show', 'complete' ), true );
		echo '<details class="eld-act' . ( 'cancel' === $action ? ' is-danger' : '' ) . '" ' . ( 'confirm' === $action || ( 1 === count( EasyLugg_Dashboard_Rules::available_actions( $row->status, EasyLugg_Dashboard::user_caps(), $paid ) ) ) ? 'open' : '' ) . '><summary>' . esc_html( self::t( 'act.' . $action ) ) . '</summary>';
		echo '<form class="eld-form" enctype="multipart/form-data" data-action="easylugg_dashboard_transition"' . ( $confirm ? ' data-confirm="' . esc_attr( self::t( 'act.confirm_prompt' ) ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="easylugg_dashboard_transition"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( EasyLugg_Dashboard::NONCE ) ) . '">';
		echo '<input type="hidden" name="code" value="' . esc_attr( $row->booking_code ) . '"><input type="hidden" name="act" value="' . esc_attr( $action ) . '"><input type="hidden" name="from" value="' . esc_attr( $row->status ) . '">';
		echo '<div class="eld-grid">';

		$check = function ( $name, $label ) {
			echo '<label class="eld-check eld-wide"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"> <span>' . esc_html( $label ) . '</span></label>';
		};
		$area = function ( $name, $label, $required = false ) {
			echo '<label class="eld-field eld-wide"><span>' . esc_html( $label ) . '</span><textarea name="' . esc_attr( $name ) . '" rows="2" maxlength="1000"' . ( $required ? ' required' : '' ) . '></textarea></label>';
		};
		$photos = function ( $label ) {
			echo '<label class="eld-field eld-wide"><span>' . esc_html( $label ) . '</span><input type="file" name="photos[]" accept="image/*" capture="environment" multiple data-eld-photos></label>';
		};

		switch ( $action ) {
			case 'confirm':
				$check( 'hotel_confirmed', self::t( 'field.hotel_confirmed' ) );
				break;
			case 'checkin':
				$booked = EasyLugg_Dashboard::bag_total( $data );
				$check( 'verified', self::t( 'field.verified' ) );
				self::input( 'tag_code', self::t( 'field.tag_code' ), '', false, 'maxlength="40" required' );
				self::input( 'actual_bags', self::t( 'field.actual_bags' ) . ' (' . EasyLugg_Dashboard::tf( 'field.booked_bags', $booked ) . ')', $booked, false, 'min="1" step="1" required inputmode="numeric"', 'number' );
				$area( 'reason', self::t( 'field.reason' ) );
				$photos( self::t( 'field.photos_checkin' ) );
				break;
			case 'depart':
				self::select( 'loop', self::t( 'field.loop' ), array( '' => '—', '1' => self::t( 'field.loop_1' ), '2' => self::t( 'field.loop_2' ) ), '', false, 'required' );
				break;
			case 'deliver':
				$check( 'verified', self::t( 'field.verified' ) );
				self::select(
					'receiver_type',
					self::t( 'field.receiver_type' ),
					array(
						''          => '—',
						'customer'  => self::t( 'field.recv_customer' ),
						'reception' => self::t( 'field.recv_reception' ),
						'proxy'     => self::t( 'field.recv_proxy' ),
					),
					'',
					false,
					'required'
				);
				self::input( 'receiver_name', self::t( 'field.receiver_name' ), '', false, 'maxlength="190" required' );
				$check( 'proxy_ok', self::t( 'field.proxy_ok' ) );
				$photos( self::t( 'field.photos_handover' ) );
				break;
			case 'complete':
				if ( 'cash' === $row->payment_method ) {
					self::input( 'invoice_no', self::t( 'field.invoice_no' ), $row->invoice_no, false, 'maxlength="40" required' );
				}
				if ( ! $paid ) {
					echo '<p class="eld-wide"><small>' . esc_html( self::t( 'err.payment_required' ) ) . '</small></p>';
				}
				break;
			case 'cancel':
				echo '<p class="eld-wide"><small>' . esc_html( self::t( 'field.cancel_reminder' ) ) . '</small></p>';
				$area( 'reason', self::t( 'field.cancel_reason' ), true );
				break;
			case 'no_show':
				$area( 'note', self::t( 'field.no_show_note' ), true );
				echo '<label class="eld-check eld-wide"><input type="checkbox" name="luggage_held" value="1"' . ( 'in_transit' === $row->status ? ' checked' : '' ) . '> <span>' . esc_html( self::t( 'field.luggage_held' ) ) . '</span></label>';
				break;
		}

		echo '</div><button type="submit" class="eld-btn eld-primary">' . esc_html( self::t( 'act.' . $action ) ) . '</button></form></details>';
	}
}
