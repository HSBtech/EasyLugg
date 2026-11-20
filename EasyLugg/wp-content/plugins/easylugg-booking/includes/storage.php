<?php
/**
 * Luggage Storage service for the EasyLugg booking plugin.
 *
 * Self-contained on purpose: this file only takes plain data in
 * ($texts from texts.php, $prices from the price config) and uses
 * WordPress globals for sanitizing, dates and JSON errors. It never
 * reaches into the main plugin class, so Delivery and Storage can
 * change independently.
 *
 * Decided 10/11/2026 (user tick): 30-minute slots; price is 1 day
 * minimum, no half day; no bag limit; no photo at booking (operations
 * handles it); one hub only (Hoi An); price shown live in the form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Storage {

	/**
	 * Storage time slots: 30-minute steps inside warehouse hours 08:00–20:00.
	 *
	 * @return array<int, string>
	 */
	public static function slots() {
		// Opening/closing hours come from the Dashboard config (defaults: 08:00–20:00).
		return EasyLugg_Dashboard_Config::storage_slots();
	}

	/**
	 * Valid storage hub ids. One hub for now (MM §13: Hoi An only).
	 *
	 * @return array<int, string>
	 */
	public static function hubs() {
		return array( 'hoian' );
	}

	/**
	 * Storage days charged: calendar-day difference, minimum 1 day.
	 * Same-day drop-off + pick-up is 1 day; there is no half day.
	 *
	 * @param string $dropoff_date Drop-off date (Y-m-d).
	 * @param string $pickup_date Pick-up date (Y-m-d).
	 * @return int
	 */
	public static function days( $dropoff_date, $pickup_date ) {
		$start = DateTime::createFromFormat( '!Y-m-d', $dropoff_date, wp_timezone() );
		$end   = DateTime::createFromFormat( '!Y-m-d', $pickup_date, wp_timezone() );
		if ( ! $start || ! $end || $end < $start ) {
			return 1;
		}
		return max( 1, (int) $start->diff( $end )->format( '%a' ) );
	}

	/**
	 * Storage estimate: bags x days x the configured per-bag daily fee.
	 *
	 * @param int   $bags_total Bag count.
	 * @param string $dropoff_date Drop-off date (Y-m-d).
	 * @param string $pickup_date Pick-up date (Y-m-d).
	 * @param array<string, int> $prices Price config (uses 'storage').
	 * @return int Total in VND.
	 */
	public static function estimate( $bags_total, $dropoff_date, $pickup_date, $prices ) {
		return self::days( $dropoff_date, $pickup_date ) * (int) $bags_total * (int) ( $prices['storage'] ?? 0 );
	}

	/**
	 * Render the Storage Step 1 panel (called inside the booking form).
	 *
	 * @param array<string, mixed> $texts All UI text (texts.php).
	 * @param array<string, int>   $prices Price config.
	 * @return void
	 */
	public static function render_fields( $texts, $prices ) {
		$today = current_time( 'Y-m-d' );
		?>
		<div class="el-service-fields" data-service-fields="storage">
			<fieldset class="el-pickup-fields">
				<legend><?php echo esc_html( self::text( $texts, 'storage.dropoff_legend' ) ); ?></legend>
				<div class="el-booking-grid">
					<div class="el-field el-field--span-12"><label><?php echo esc_html( self::text( $texts, 'storage.hub_label' ) ); ?> *</label><div class="el-hotel-suggest" data-storage-hub-wrap><div class="el-hotel-selected" data-storage-hub-selected tabindex="0"><strong data-storage-hub-selected-name><?php echo esc_html( self::text( $texts, 'storage.hub_name' ) ); ?></strong><small><?php echo esc_html( self::text( $texts, 'storage.hub_address' ) ); ?></small></div><ul class="el-hotel-suggestions" role="listbox" data-storage-hub-list hidden><li class="el-hotel-suggestion" data-storage-hub-option="hoian" role="option"><strong><?php echo esc_html( self::text( $texts, 'storage.hub_name' ) ); ?></strong><small><?php echo esc_html( self::text( $texts, 'storage.hub_address' ) ); ?> — <?php echo esc_html( self::text( $texts, 'storage.hub_hours' ) ); ?></small></li></ul></div><input type="hidden" name="storage_hub" value="hoian"><small><?php echo esc_html( self::text( $texts, 'storage.hub_hint' ) ); ?></small></div>
					<div class="el-field el-field--span-6"><label for="el-dropoff-date"><?php echo esc_html( self::text( $texts, 'storage.dropoff_date' ) ); ?> *</label><input id="el-dropoff-date" name="dropoff_date" type="date" min="<?php echo esc_attr( $today ); ?>" required><small><?php echo esc_html( self::text( $texts, 'storage.dropoff_date_hint' ) ); ?></small></div>
					<div class="el-field el-field--span-6"><label for="el-dropoff-time"><?php echo esc_html( self::text( $texts, 'storage.dropoff_time' ) ); ?> *</label><select id="el-dropoff-time" name="dropoff_time" required><option value=""><?php echo esc_html( self::text( $texts, 'storage.dropoff_time_placeholder' ) ); ?></option><?php foreach ( self::slots() as $time ) : ?><option value="<?php echo esc_attr( $time ); ?>"><?php echo esc_html( $time ); ?></option><?php endforeach; ?></select><small><?php echo esc_html( self::text( $texts, 'storage.hub_hours' ) ); ?></small></div>
				</div>
			</fieldset>
			<fieldset class="el-delivery-fields">
				<legend><?php echo esc_html( self::text( $texts, 'storage.pickup_legend' ) ); ?></legend>
				<div class="el-booking-grid">
					<div class="el-field el-field--span-6"><label for="el-pickup-date"><?php echo esc_html( self::text( $texts, 'storage.pickup_date' ) ); ?> *</label><input id="el-pickup-date" name="pickup_date" type="date" min="<?php echo esc_attr( $today ); ?>" required><small><?php echo esc_html( self::text( $texts, 'storage.pickup_date_hint' ) ); ?></small></div>
					<div class="el-field el-field--span-6"><label for="el-pickup-time"><?php echo esc_html( self::text( $texts, 'storage.pickup_time' ) ); ?> *</label><select id="el-pickup-time" name="pickup_time" required><option value=""><?php echo esc_html( self::text( $texts, 'storage.pickup_time_placeholder' ) ); ?></option><?php foreach ( self::slots() as $time ) : ?><option value="<?php echo esc_attr( $time ); ?>"><?php echo esc_html( $time ); ?></option><?php endforeach; ?></select><small><?php echo esc_html( self::text( $texts, 'storage.hub_hours' ) ); ?></small><p class="el-storage-time-warning" data-storage-time-warning hidden><?php echo esc_html( self::text( $texts, 'storage.time_warning' ) ); ?></p></div>
				</div>
			</fieldset>
			<fieldset class="el-bags" aria-label="<?php echo esc_attr( self::text( $texts, 'storage.bags_title' ) ); ?>">
				<div class="el-bags-head"><span class="el-bags-title"><?php echo esc_html( self::text( $texts, 'storage.bags_title' ) ); ?></span><span class="el-bags-note"><?php echo esc_html( self::text( $texts, 'storage.bags_hint' ) ); ?></span></div>
				<div class="el-bag-counts">
					<div class="el-bag-counter">
						<div class="el-bag-head"><span class="el-bag-name"><?php echo esc_html( self::text( $texts, 'storage.bags_title' ) ); ?></span></div>
						<p class="el-bag-desc"><?php echo esc_html( self::text( $texts, 'storage.bags_hint' ) ); ?></p>
						<div class="el-bag-price"><?php echo esc_html( sprintf( self::text( $texts, 'storage.rate_line' ), number_format( (int) ( $prices['storage'] ?? 0 ) ) ) ); ?></div>
						<div class="el-bag-stepper">
							<button type="button" class="el-bag-btn el-bag-minus" data-storage-bag-step="-1" aria-label="<?php echo esc_attr( self::text( $texts, 'storage.bags_title' ) ); ?>">−</button>
							<input id="el-storage-bags" name="storage_bags" type="number" min="0" step="1" value="0" inputmode="numeric" aria-label="<?php echo esc_attr( self::text( $texts, 'storage.bags_title' ) ); ?>">
							<button type="button" class="el-bag-btn el-bag-plus" data-storage-bag-step="1" aria-label="<?php echo esc_attr( self::text( $texts, 'storage.bags_title' ) ); ?>">+</button>
						</div>
					</div>
				</div>
				<p class="el-bag-price-hint" data-storage-price-hint></p>
			</fieldset>
			<div class="el-booking-grid">
				<div class="el-field el-field--span-6"><label for="el-storage-promotion"><?php echo esc_html( self::text( $texts, 'step1.promo_label' ) ); ?></label><input id="el-storage-promotion" name="promotion_code" type="text" maxlength="30" autocomplete="off"><small><?php echo esc_html( self::text( $texts, 'step1.promo_hint' ) ); ?></small></div>
			</div>
			<div class="el-field"><label for="el-storage-note"><?php echo esc_html( self::text( $texts, 'step1.note_label' ) ); ?></label><textarea id="el-storage-note" name="note" rows="3" maxlength="500"></textarea><small><?php echo esc_html( self::text( $texts, 'step1.note_hint' ) ); ?></small></div>
			<div class="el-policy-box">
				<strong><?php echo esc_html( self::text( $texts, 'storage.policy_title' ) ); ?></strong>
				<p><?php echo esc_html( self::text( $texts, 'storage.policy_text' ) ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Validate a storage booking and return its service array.
	 * Sends a JSON error (like the delivery validators) on bad input.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param array<string, mixed> $texts All UI text (texts.php).
	 * @param array<string, int>   $prices Price config.
	 * @return array<string, mixed>
	 */
	public static function validate( $input, $texts, $prices ) {
		$hub = self::input_key( $input, 'storage_hub' );
		if ( ! in_array( $hub, self::hubs(), true ) ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.storage_hub_required' ) ), 400 );
		}

		$dropoff_date = self::input_text( $input, 'dropoff_date' );
		if ( ! self::is_valid_date_from_today( $dropoff_date ) ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.dropoff_date_invalid' ) ), 400 );
		}
		$dropoff_time = self::input_text( $input, 'dropoff_time' );
		if ( ! in_array( $dropoff_time, self::slots(), true ) ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.dropoff_time_required' ) ), 400 );
		}

		$pickup_date = self::input_text( $input, 'pickup_date' );
		if ( ! self::is_valid_date( $pickup_date ) || $pickup_date < $dropoff_date ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.pickup_date_invalid' ) ), 400 );
		}
		$pickup_time = self::input_text( $input, 'pickup_time' );
		if ( ! in_array( $pickup_time, self::slots(), true ) ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.pickup_time_required' ) ), 400 );
		}
		if ( $pickup_date === $dropoff_date && $pickup_time <= $dropoff_time ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.pickup_time_before' ) ), 400 );
		}

		return array(
			'service'      => 'storage',
			'corridor'     => 'ST',
			'service_date' => $dropoff_date,
			'hub'          => $hub,
			'dropoff_date' => $dropoff_date,
			'dropoff_time' => $dropoff_time,
			'pickup_date'  => $pickup_date,
			'pickup_time'  => $pickup_time,
			'storage_days' => self::days( $dropoff_date, $pickup_date ),
			'on_request'   => false,
		);
	}

	/**
	 * Validate the storage bag count. No upper cap (user decision 10/11).
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param array<string, mixed> $texts All UI text (texts.php).
	 * @return array<string, int> array('total' => N).
	 */
	public static function validate_bags( $input, $texts ) {
		$value = isset( $input['storage_bags'] ) && is_scalar( $input['storage_bags'] ) ? (string) $input['storage_bags'] : '';
		if ( ! ctype_digit( $value ) || (int) $value < 1 ) {
			wp_send_json_error( array( 'message' => self::text( $texts, 'validation.storage_bags_min' ) ), 400 );
		}
		return array( 'total' => (int) $value );
	}

	/**
	 * Text lookup in the texts.php array (dotted key, falls back to the key).
	 *
	 * @param array<string, mixed> $texts All UI text (texts.php).
	 * @param string               $key Dotted key, e.g. 'storage.hub_label'.
	 * @return string
	 */
	private static function text( $texts, $key ) {
		$node = $texts;
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $node ) || ! isset( $node[ $part ] ) ) {
				return $key;
			}
			$node = $node[ $part ];
		}
		return is_string( $node ) ? $node : $key;
	}

	/**
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param string               $key Input key.
	 * @return string
	 */
	private static function input_text( $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] )
			? sanitize_text_field( $input[ $key ] )
			: '';
	}

	/**
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param string               $key Input key.
	 * @return string
	 */
	private static function input_key( $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] )
			? sanitize_key( $input[ $key ] )
			: '';
	}

	/**
	 * @param string $date Date in Y-m-d format.
	 * @return bool
	 */
	private static function is_valid_date_from_today( $date ) {
		$date_object = DateTime::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $date_object
			&& $date_object->format( 'Y-m-d' ) === $date
			&& $date >= current_time( 'Y-m-d' );
	}

	/**
	 * @param string $date Date in Y-m-d format.
	 * @return bool
	 */
	private static function is_valid_date( $date ) {
		$date_object = DateTime::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $date_object && $date_object->format( 'Y-m-d' ) === $date;
	}
}
