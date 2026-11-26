<?php
/**
 * Dashboard 2 HTML blocks. Everything is escaped here; JS only swaps regions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyLugg_D2_Render {

	/**
	 * @param string $key Text key.
	 * @return string
	 */
	private static function t( $key ) {
		return EasyLugg_D2::t( $key );
	}

	/**
	 * @return EasyLugg_Booking_Plugin
	 */
	private static function plugin() {
		return EasyLugg_Booking_Plugin::instance();
	}

	/**
	 * UTC datetime string -> site-local format.
	 *
	 * @param string $utc    Y-m-d H:i:s (UTC).
	 * @param string $format wp_date format.
	 * @return string
	 */
	private static function local( $utc, $format = 'H:i' ) {
		$ts = strtotime( $utc . ' UTC' );
		return $ts ? wp_date( $format, $ts ) : '';
	}

	/**
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_pill( $status ) {
		return '<span class="eld2-pill is-' . esc_attr( EasyLugg_D2_Metrics::group_of( $status ) . ' s-' . sanitize_html_class( $status ) ) . '">' . esc_html( self::plugin()->status_label( $status ) ) . '</span>';
	}

	/**
	 * @param array $today     counts() for the viewed date.
	 * @param int   $yesterday Total bookings the day before.
	 * @return string
	 */
	public static function kpi( $today, $yesterday ) {
		$total = $today['total'];
		$tiles = array(
			array( 'today', $total, EasyLugg_D2_Metrics::delta( $total, $yesterday ), true ),
			array( 'transit', EasyLugg_D2_Metrics::group_count( $today['by_status'], 'transit' ), null, false ),
			array( 'delivered', EasyLugg_D2_Metrics::group_count( $today['by_status'], 'delivered' ), null, false ),
			array( 'exception', $today['exception'], null, false ),
			array( 'pending', EasyLugg_D2_Metrics::group_count( $today['by_status'], 'pickup' ), null, false ),
		);
		$html = '';
		foreach ( $tiles as $tile ) {
			list( $key, $value, $delta, $is_delta ) = $tile;
			if ( $is_delta ) {
				$sub = null === $delta ? '—' : ( $delta >= 0 ? '▲ ' : '▼ ' ) . abs( $delta ) . '%';
				$bad = null !== $delta && $delta < 0;
			} else {
				$pct = EasyLugg_D2_Metrics::percent( $value, $total );
				$sub = null === $pct ? '—' : $pct . '%';
				$bad = 'exception' === $key && $value > 0;
			}
			$html .= '<div class="eld2-kpi-tile"><div class="eld2-kpi-top"><span class="eld2-kpi-label">' . esc_html( self::t( 'kpi.' . $key ) ) . '</span></div>'
				. '<div class="eld2-kpi-bottom"><span class="eld2-kpi-value">' . (int) $value . '</span><span class="eld2-kpi-delta' . ( $bad ? ' bad' : '' ) . '">' . esc_html( $sub ) . '</span></div></div>';
		}
		$html .= '<div class="eld2-kpi-tile"><div class="eld2-kpi-top"><span class="eld2-kpi-label">' . esc_html( self::t( 'kpi.updated' ) ) . '</span><span class="eld2-live" id="eld2-live">' . esc_html( self::t( 'kpi.live' ) ) . '</span></div>'
			. '<div class="eld2-kpi-bottom"><span class="eld2-kpi-value">' . esc_html( wp_date( 'H:i' ) ) . '</span><span class="eld2-kpi-delta">' . esc_html( wp_date( 'd/m' ) ) . '</span></div></div>';
		return $html;
	}

	/**
	 * @param array $f      Filters (date, tab, corridor, q, page).
	 * @param array $result list_rows() result.
	 * @param array $tabs   tab_counts() result.
	 * @param string $code  Selected booking code.
	 * @return string
	 */
	public static function list_block( $f, $result, $tabs, $code ) {
		$searching = '' !== $f['q'];
		ob_start();
		?>
		<div class="eld2-card-head">
			<h2><?php echo esc_html( self::t( 'list.title' ) ); ?></h2>
			<?php if ( current_user_can( 'easylugg_create_booking' ) ) : ?>
				<a class="eld2-btn" href="<?php echo esc_url( EasyLugg_D2::page_url( array( 'tab' => 'create' ) ) ); ?>"><?php echo esc_html( self::t( 'list.new' ) ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( $searching ) : ?>
			<div class="eld2-bar"><span>“<?php echo esc_html( $f['q'] ); ?>”</span> <button type="button" class="eld2-link" data-d2-clear><?php echo esc_html( self::t( 'list.clear_search' ) ); ?></button></div>
		<?php else : ?>
			<div class="eld2-bar">
				<label class="eld2-dl"><?php echo esc_html( self::t( 'list.from' ) ); ?>
					<input type="date" class="eld2-date" value="<?php echo esc_attr( $f['from'] ); ?>" max="<?php echo esc_attr( $f['to'] ); ?>" data-d2-from></label>
				<label class="eld2-dl"><?php echo esc_html( self::t( 'list.to' ) ); ?>
					<input type="date" class="eld2-date" value="<?php echo esc_attr( $f['to'] ); ?>" min="<?php echo esc_attr( $f['from'] ); ?>" data-d2-to></label>
				<span class="eld2-chips">
					<button type="button" class="eld2-chip<?php echo '' === $f['corridor'] ? ' is-on' : ''; ?>" data-d2-corridor=""><?php echo esc_html( self::t( 'list.all_routes' ) ); ?></button>
					<?php foreach ( array( 'AH', 'HA', 'HH', 'ST' ) as $c ) : ?>
						<button type="button" class="eld2-chip<?php echo $c === $f['corridor'] ? ' is-on' : ''; ?>" data-d2-corridor="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></button>
					<?php endforeach; ?>
				</span>
			</div>
			<div class="eld2-tabs" role="tablist">
				<?php foreach ( array( 'all', 'pickup', 'transit', 'delivered', 'exception' ) as $tab ) : ?>
					<button type="button" role="tab" class="eld2-tab<?php echo $tab === $f['tab'] ? ' is-on' : ''; ?>" data-d2-tab="<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( self::t( 'list.tab_' . $tab ) ); ?> <span class="eld2-count"><?php echo (int) $tabs[ $tab ]; ?></span></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="eld2-table-wrap">
		<table class="eld2-table">
			<thead><tr>
				<th><?php echo esc_html( self::t( 'list.col_id' ) ); ?></th>
				<th><?php echo esc_html( self::t( 'list.col_service' ) ); ?></th>
				<th><?php echo esc_html( self::t( 'list.col_bags' ) ); ?></th>
				<th><?php echo esc_html( self::t( 'list.col_status' ) ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr><td colspan="4" class="eld2-empty"><?php echo esc_html( self::t( 'list.empty' ) ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $row ) : ?>
				<?php $bags = EasyLugg_Dashboard::bag_total( EasyLugg_Dashboard::decode( $row->booking_data ) ); ?>
				<tr class="eld2-row<?php echo $row->booking_code === $code ? ' is-selected' : ''; ?>" data-d2-code="<?php echo esc_attr( $row->booking_code ); ?>" tabindex="0">
					<td><?php echo (int) $row->is_exc ? '<span class="eld2-dot" title="' . esc_attr( self::t( 'list.exception_dot' ) ) . '"></span>' : ''; ?><?php echo esc_html( $row->booking_code ); ?></td>
					<td><?php echo esc_html( self::plugin()->corridor_label( $row->corridor ) ); ?></td>
					<td><?php echo (int) $bags; ?></td>
					<td><?php echo self::status_pill( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		$from = $result['total'] ? ( $result['page'] - 1 ) * EasyLugg_D2_Data::PER_PAGE + 1 : 0;
		$to   = $result['total'] ? $from + count( $result['rows'] ) - 1 : 0;
		?>
		<div class="eld2-foot">
			<span><?php echo esc_html( sprintf( self::t( 'list.showing' ), $from, $to, $result['total'] ) ); ?></span>
			<span>
				<button type="button" class="eld2-chip" data-d2-page="<?php echo (int) $result['page'] - 1; ?>" <?php disabled( $result['page'] <= 1 ); ?>><?php echo esc_html( self::t( 'list.prev' ) ); ?></button>
				<button type="button" class="eld2-chip" data-d2-page="<?php echo (int) $result['page'] + 1; ?>" <?php disabled( $result['page'] >= $result['pages'] ); ?>><?php echo esc_html( self::t( 'list.next' ) ); ?></button>
			</span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return string
	 */
	public static function detail_empty() {
		return '<p class="eld2-muted eld2-pad">' . esc_html( self::t( 'detail.pick' ) ) . '</p>';
	}

	/**
	 * @return string
	 */
	public static function detail_missing() {
		return '<p class="eld2-muted eld2-pad">' . esc_html( self::t( 'detail.not_found' ) ) . '</p>';
	}

	/**
	 * @param array $rows label => value (value already safe HTML).
	 * @param string $title Section title.
	 * @return string
	 */
	private static function section( $title, $rows ) {
		$html = '<section class="eld2-sec"><h3>' . esc_html( $title ) . '</h3><dl class="eld2-kv">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		return $html . '</dl></section>';
	}

	/**
	 * @param array $d detail() result.
	 * @return string
	 */
	public static function detail( $d ) {
		$row    = $d['row'];
		$data   = $d['data'];
		$none   = esc_html( self::t( 'detail.none' ) );
		$cust   = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		$phone  = '';
		$others = array();
		foreach ( isset( $cust['contact_channels'] ) && is_array( $cust['contact_channels'] ) ? $cust['contact_channels'] : array() as $ch ) {
			if ( ! isset( $ch['channel'], $ch['value'] ) ) {
				continue;
			}
			$label = ucfirst( (string) $ch['channel'] ) . ' ' . (string) $ch['value'];
			if ( preg_match( '/^[+\d][\d\s().-]{5,}$/', (string) $ch['value'] ) && ! $phone ) {
				$phone = preg_replace( '/[^\d+]/', '', (string) $ch['value'] );
			}
			$others[] = esc_html( $label );
		}
		$paid_key = in_array( $row->payment_status, array( 'unpaid', 'paid', 'ota_paid', 'demo', 'failed' ), true ) ? $row->payment_status : 'other';
		$bags     = EasyLugg_Dashboard::bag_counts( $data );
		$size     = array();
		foreach ( array( 'cabin', 'medium', 'oversize' ) as $s ) {
			if ( ! empty( $bags[ $s ] ) ) {
				$size[] = (int) $bags[ $s ] . ' ' . self::t( 'detail.size_' . $s );
			}
		}
		$tag    = '';
		$actual = '';
		$handled = '';
		foreach ( $d['events'] as $event ) {
			$meta = $event->meta ? json_decode( $event->meta, true ) : array();
			if ( is_array( $meta ) && ! empty( $meta['tag_code'] ) ) {
				$tag = (string) $meta['tag_code'];
			}
			if ( 'status_change' === $event->action ) {
				$handled = EasyLugg_D2_Data::actor( $event->actor_id );
				if ( 'collected' === $event->to_status && '' === $actual ) {
					$actual = self::local( $event->created_at, 'd/m H:i' );
				}
			}
		}
		$from = isset( $data['from_hotel']['name'] ) ? (string) $data['from_hotel']['name'] : '';
		$to   = isset( $data['to_hotel']['name'] ) ? (string) $data['to_hotel']['name'] : '';
		$air  = self::t( 'detail.airport' );
		$hub  = self::t( 'detail.hub' );
		$map  = array(
			'AH' => array( $air, $to ),
			'HA' => array( $from, $air ),
			'HH' => array( $from, $to ),
			'ST' => array( $hub, $hub ),
		);
		list( $pick, $drop ) = isset( $map[ $row->corridor ] ) ? $map[ $row->corridor ] : array( '', '' );
		$when = EasyLugg_Dashboard::first_time( $row->corridor, $data );

		$d1_view = EasyLugg_D2::page_url( array( 'view' => $row->booking_code ) );
		ob_start();
		?>
		<div class="eld2-card-head">
			<h2><?php echo esc_html( self::t( 'detail.booking' ) . ' #' . $row->booking_code ); ?></h2>
			<?php echo self::status_pill( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<div class="eld2-actions">
			<a class="eld2-btn" href="<?php echo esc_url( $d1_view ); ?>"><?php echo esc_html( self::t( 'detail.open_d1' ) ); ?></a>
			<?php if ( current_user_can( 'easylugg_edit_booking' ) && in_array( EasyLugg_Dashboard_Rules::edit_mode( $row->status ), array( 'full', 'contact' ), true ) ) : ?>
				<a class="eld2-btn" href="<?php echo esc_url( EasyLugg_D2::page_url( array( 'edit' => $row->booking_code ) ) ); ?>"><?php echo esc_html( self::t( 'detail.edit' ) ); ?></a>
			<?php endif; ?>
			<?php if ( $phone ) : ?>
				<a class="eld2-btn" href="<?php echo esc_url( 'tel:' . $phone, array( 'tel' ) ); ?>"><?php echo esc_html( self::t( 'detail.call' ) ); ?></a>
			<?php endif; ?>
			<a class="eld2-btn" href="<?php echo esc_url( 'mailto:' . $row->customer_email, array( 'mailto' ) ); ?>"><?php echo esc_html( self::t( 'detail.email' ) ); ?></a>
		</div>
		<?php
		echo self::section( // phpcs:ignore WordPress.Security.EscapeOutput
			self::t( 'detail.sec_info' ),
			array(
				self::t( 'detail.f_id' )      => esc_html( $row->booking_code ),
				self::t( 'detail.f_channel' ) => esc_html( $row->source ? self::t( 'detail.channel_internal' ) . ' (' . $row->source . ')' : self::t( 'detail.channel_web' ) ),
				self::t( 'detail.f_service' ) => esc_html( self::plugin()->corridor_label( $row->corridor ) ),
				self::t( 'detail.f_payment' ) => esc_html( self::t( 'detail.pay_' . $paid_key ) ),
				self::t( 'detail.f_status' )  => esc_html( self::plugin()->status_label( $row->status ) ),
				self::t( 'detail.f_created' ) => esc_html( self::local( $row->created_at, 'd/m/Y H:i' ) ),
				self::t( 'detail.f_price' )   => esc_html( number_format_i18n( (int) $row->price_total ) . ' VND' ),
			)
		);
		echo self::section( // phpcs:ignore WordPress.Security.EscapeOutput
			self::t( 'detail.sec_customer' ),
			array(
				self::t( 'detail.f_name' )    => esc_html( $row->customer_name ),
				self::t( 'detail.f_contact' ) => $others ? implode( '<br>', $others ) : $none,
				self::t( 'detail.f_email' )   => '<a href="' . esc_url( 'mailto:' . $row->customer_email, array( 'mailto' ) ) . '">' . esc_html( $row->customer_email ) . '</a>',
				self::t( 'detail.f_lang' )    => '' !== (string) $row->lang ? esc_html( $row->lang ) : $none,
				self::t( 'detail.f_note' )    => ! empty( $data['note'] ) && is_scalar( $data['note'] ) ? esc_html( (string) $data['note'] ) : $none,
			)
		);
		echo self::section( // phpcs:ignore WordPress.Security.EscapeOutput
			self::t( 'detail.sec_luggage' ),
			array(
				self::t( 'detail.f_total_bags' ) => (int) EasyLugg_Dashboard::bag_total( $data ),
				self::t( 'detail.f_size' )       => $size ? esc_html( implode( ' / ', $size ) ) : $none,
				self::t( 'detail.f_tag' )        => '' !== $tag ? esc_html( $tag ) : $none,
			)
		);
		echo self::section( // phpcs:ignore WordPress.Security.EscapeOutput
			self::t( 'detail.sec_route' ),
			array(
				self::t( 'detail.f_pickup' )      => '' !== $pick ? esc_html( $pick ) : $none,
				self::t( 'detail.f_pickup_time' ) => esc_html( $row->service_date . ( '' !== $when ? ' ' . $when : '' ) ),
				self::t( 'detail.f_actual' )      => '' !== $actual ? esc_html( $actual ) : $none,
				self::t( 'detail.f_delivery' )    => '' !== $drop ? esc_html( $drop ) : $none,
				self::t( 'detail.f_handled' )     => '' !== $handled ? esc_html( $handled ) : $none,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Live Tracking timeline + Exception alert.
	 *
	 * @param array $d detail() result.
	 * @return string
	 */
	public static function tracking( $d ) {
		$items = array();
		foreach ( $d['events'] as $event ) {
			$what = isset( EasyLugg_D2::texts()['audit'][ $event->action ] ) ? self::t( 'audit.' . $event->action ) : self::t( 'audit.other' );
			if ( $event->to_status ) {
				$what .= ': ' . self::plugin()->status_label( $event->to_status );
			}
			$items[ strtotime( $event->created_at . ' UTC' ) . '-e' . $event->id ] = array( $event->created_at, $what, (string) $event->note );
		}
		foreach ( $d['photos'] as $i => $photo ) {
			$items[ strtotime( $photo->created_at . ' UTC' ) . '-p' . $i ] = array( $photo->created_at, self::t( 'tl.photo' ), '' );
		}
		ksort( $items );
		$html  = '<div class="eld2-card"><div class="eld2-card-head"><h2>' . esc_html( self::t( 'tl.title' ) ) . '</h2></div><ol class="eld2-timeline">';
		$last  = count( $items ) - 1;
		$index = 0;
		foreach ( $items as $item ) {
			$html .= '<li' . ( $index === $last ? ' class="is-now"' : '' ) . '><span class="eld2-time">' . esc_html( self::local( $item[0] ) ) . '</span><span class="eld2-ev"><strong>' . esc_html( $item[1] ) . '</strong>' . ( '' !== $item[2] ? '<br><small>' . esc_html( $item[2] ) . '</small>' : '' ) . '</span></li>';
			++$index;
		}
		if ( ! $items ) {
			$html .= '<li class="eld2-muted">' . esc_html( self::t( 'tl.empty' ) ) . '</li>';
		}
		$html .= '</ol></div>';

		$html .= '<div class="eld2-card eld2-exc' . ( $d['reasons'] ? ' has-exc' : '' ) . '"><div class="eld2-card-head"><h2>' . esc_html( self::t( 'tl.exc_title' ) ) . '</h2></div><div class="eld2-pad">';
		if ( ! $d['reasons'] ) {
			$html .= '<p class="eld2-muted">' . esc_html( self::t( 'tl.exc_none' ) ) . '</p>';
		}
		foreach ( $d['reasons'] as $reason ) {
			$html .= '<p><strong>' . esc_html( self::t( 'tl.exc_' . $reason['type'] ) ) . '</strong>';
			if ( '' !== $reason['note'] ) {
				$html .= '<br>' . esc_html( $reason['note'] );
			}
			if ( '' !== $reason['at'] ) {
				$html .= '<br><small>' . esc_html( self::local( $reason['at'], 'd/m H:i' ) . ( '' !== $reason['who'] ? ' · ' . $reason['who'] : '' ) ) . '</small>';
			}
			$html .= '</p>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Proof & Support strip: photo presence per stage + tracked info.
	 *
	 * @param array $d detail() result.
	 * @return string
	 */
	public static function proof( $d ) {
		$row    = $d['row'];
		$stages = array();
		foreach ( $d['photos'] as $photo ) {
			if ( ! isset( $stages[ $photo->stage ] ) ) {
				$stages[ $photo->stage ] = self::local( $photo->created_at, 'd/m H:i' );
			}
		}
		$boxes = array(
			'pickup'   => isset( $stages['checkin'] ) ? $stages['checkin'] : '',
			'delivery' => isset( $stages['handover'] ) ? $stages['handover'] : '',
			'customer' => $row->photo_path ? '✓' : '',
		);
		$html = '<h2 class="eld2-h">' . esc_html( self::t( 'proof.title' ) ) . '</h2><div class="eld2-proofs">';
		foreach ( $boxes as $key => $at ) {
			$html .= '<div class="eld2-pbox' . ( '' !== $at ? ' has' : '' ) . '"><strong>' . esc_html( self::t( 'proof.' . $key ) ) . '</strong><span>' . esc_html( '' !== $at ? self::t( 'proof.yes' ) . ( '✓' !== $at ? ' · ' . $at : '' ) : self::t( 'proof.no' ) ) . '</span></div>';
		}
		$html .= '</div><h3 class="eld2-h">' . esc_html( self::t( 'proof.tracked' ) ) . '</h3><dl class="eld2-kv eld2-kv-row">';
		$last  = $d['events'] ? self::local( end( $d['events'] )->created_at, 'd/m H:i' ) : '—';
		$cells = array(
			self::t( 'detail.f_id' )      => $row->booking_code,
			self::t( 'detail.f_name' )    => $row->customer_name,
			self::t( 'detail.f_total_bags' ) => (string) EasyLugg_Dashboard::bag_total( $d['data'] ),
			self::t( 'proof.last' )       => $last,
			self::t( 'proof.photos' )     => (string) ( count( $d['photos'] ) + ( $row->photo_path ? 1 : 0 ) ),
			self::t( 'detail.f_status' )  => self::plugin()->status_label( $row->status ),
			self::t( 'detail.f_service' ) => self::plugin()->corridor_label( $row->corridor ),
		);
		foreach ( $cells as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return $html . '</dl>';
	}
}
