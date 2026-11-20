<?php
/**
 * Internal Dashboard — front-end page `/dashboard/` with [easylugg_dashboard].
 * Rendering (gate, list, detail, settings). Write handlers live in
 * dashboard-actions.php; create/edit field markup in dashboard-forms.php.
 * SRS refs: SEC-01..04, LIST-01..08, DET-01..06, CFG-01..08.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'dashboard-forms.php';
require_once plugin_dir_path( __FILE__ ) . 'dashboard-actions.php';

final class EasyLugg_Dashboard {

	const NONCE    = 'easylugg_dashboard';
	const PER_PAGE = 20;

	/**
	 * @var EasyLugg_Booking_Plugin
	 */
	private static $plugin;

	/**
	 * @param EasyLugg_Booking_Plugin $plugin Plugin instance.
	 * @return void
	 */
	public static function hooks( $plugin ) {
		self::$plugin = $plugin;
		add_shortcode( 'easylugg_dashboard', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		EasyLugg_Dashboard_Actions::hooks();
	}

	/**
	 * @return EasyLugg_Booking_Plugin
	 */
	public static function plugin() {
		return self::$plugin;
	}

	/**
	 * Dashboard UI text by dotted key; falls back to the key so typos are visible.
	 *
	 * @param string $key Dotted key, e.g. 'nav.open'.
	 * @return string
	 */
	public static function t( $key ) {
		static $texts = null;
		if ( null === $texts ) {
			$texts = include plugin_dir_path( __FILE__ ) . 'dashboard-texts.php';
		}
		$parts = explode( '.', $key, 2 );
		return isset( $parts[1], $texts[ $parts[0] ][ $parts[1] ] ) ? $texts[ $parts[0] ][ $parts[1] ] : $key;
	}

	/**
	 * @param string $key  Dotted key.
	 * @param mixed  ...$args sprintf arguments.
	 * @return string
	 */
	public static function tf( $key, ...$args ) {
		return vsprintf( self::t( $key ), $args );
	}

	/**
	 * @return void
	 */
	public static function send_headers() {
		if ( ! self::$plugin->is_dashboard_request() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: same-origin' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ) {
		if ( self::$plugin && self::$plugin->is_dashboard_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::$plugin->is_dashboard_request() ) {
			return;
		}
		$css = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/booking-dashboard.css';
		$js  = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/booking-dashboard.js';
		wp_enqueue_style( 'easylugg-dashboard-font', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'easylugg-dashboard', plugins_url( 'assets/booking-dashboard.css', dirname( __FILE__ ) ), array( 'easylugg-dashboard-font' ),EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $css ) );
		wp_enqueue_script( 'easylugg-dashboard', plugins_url( 'assets/booking-dashboard.js', dirname( __FILE__ ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $js ), true );
		wp_localize_script(
			'easylugg-dashboard',
			'EasyLuggDashboard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'working' => self::t( 'form.working' ),
				'error'   => self::t( 'form.error_generic' ),
				'confirm' => self::t( 'act.confirm_prompt' ),
			)
		);
	}

	// ---- URL + small helpers ----------------------------------------------

	/**
	 * @param array<string, string> $args Query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		$base = get_permalink();
		if ( ! $base ) {
			$base = EasyLugg_Dashboard_Setup::dashboard_url();
		}
		return add_query_arg( $args, $base );
	}

	/**
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function can( $cap ) {
		return current_user_can( $cap );
	}

	/**
	 * @param string               $corridor Route code.
	 * @param array<string, mixed> $data     Decoded booking_data.
	 * @return string
	 */
	public static function first_time( $corridor, $data ) {
		$key = array(
			'AH' => 'pickup_time',
			'HA' => 'airport_delivery_time',
			'ST' => 'dropoff_time',
		);
		return isset( $key[ $corridor ], $data[ $key[ $corridor ] ] ) ? (string) $data[ $key[ $corridor ] ] : '';
	}

	/**
	 * @param object $row Booking row.
	 * @return bool
	 */
	public static function is_paid( $row ) {
		return in_array( $row->payment_status, array( 'paid', 'ota_paid' ), true );
	}

	/**
	 * @param string $json booking_data JSON.
	 * @return array<string, mixed>
	 */
	public static function decode( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, mixed> $data Decoded booking_data.
	 * @return array<string, int>
	 */
	public static function bag_counts( $data ) {
		$bags = isset( $data['bags'] ) && is_array( $data['bags'] ) ? $data['bags'] : array();
		return $bags;
	}

	/**
	 * @param array<string, mixed> $data Decoded booking_data.
	 * @return int
	 */
	public static function bag_total( $data ) {
		$bags = self::bag_counts( $data );
		if ( isset( $bags['total'] ) ) {
			return (int) $bags['total'];
		}
		return (int) ( $bags['cabin'] ?? 0 ) + (int) ( $bags['medium'] ?? 0 ) + (int) ( $bags['oversize'] ?? 0 );
	}

	/**
	 * @param array<string, mixed> $data Decoded booking_data.
	 * @return string
	 */
	public static function bag_text( $data ) {
		$bags = self::bag_counts( $data );
		if ( isset( $bags['total'] ) ) {
			return (int) $bags['total'] . ' ' . self::t( 'list.bag_storage' );
		}
		$parts = array();
		foreach ( array( 'cabin', 'medium', 'oversize' ) as $size ) {
			if ( ! empty( $bags[ $size ] ) ) {
				$parts[] = (int) $bags[ $size ] . ' ' . self::t( 'list.bag_' . $size );
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * @param array<string, mixed> $data Decoded booking_data.
	 * @return string
	 */
	public static function contact_text( $data ) {
		$customer = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		$channels = isset( $customer['contact_channels'] ) && is_array( $customer['contact_channels'] ) ? $customer['contact_channels'] : array();
		if ( $channels && isset( $channels[0]['channel'], $channels[0]['value'] ) ) {
			return ucfirst( $channels[0]['channel'] ) . ' ' . $channels[0]['value'];
		}
		return '';
	}

	/**
	 * Tracking link for the customer (token is derived, never stored in plain).
	 *
	 * @param object $row Row with booking_code and request_key.
	 * @return string
	 */
	public static function tracking_url( $row ) {
		$token = hash_hmac( 'sha256', $row->request_key, wp_salt( 'secure_auth' ) );
		$page  = get_page_by_path( 'bookings' );
		return add_query_arg(
			array(
				'el_track' => $row->booking_code,
				'el_token' => $token,
			),
			$page ? get_permalink( $page ) : home_url( '/bookings/' )
		);
	}

	// ---- Shortcode ---------------------------------------------------------

	/**
	 * @return string
	 */
	public static function render_shortcode() {
		// Permission is decided BEFORE any DB query (SEC-03).
		if ( ! is_user_logged_in() ) {
			return self::render_login();
		}
		$user = wp_get_current_user();
		if ( EasyLugg_Dashboard_Setup::is_locked( $user->ID ) ) {
			return self::render_forbidden( self::t( 'gate.locked' ) );
		}
		if ( ! current_user_can( 'easylugg_view_dashboard' ) ) {
			return self::render_forbidden( self::t( 'gate.no_permission' ) );
		}

		$view = isset( $_GET['view'] ) && is_scalar( $_GET['view'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['view'] ) ) ) : '';
		$edit = isset( $_GET['edit'] ) && is_scalar( $_GET['edit'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ) : '';
		$tab  = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		ob_start();
		echo '<div class="eld" id="eld">';
		self::render_header( $tab );
		echo '<div class="eld-msg" role="status" aria-live="polite" hidden></div>';
		if ( '' !== $view ) {
			self::render_detail( $view );
		} elseif ( '' !== $edit ) {
			EasyLugg_Dashboard_Forms::render_edit_screen( $edit );
		} elseif ( 'create' === $tab ) {
			if ( current_user_can( 'easylugg_create_booking' ) ) {
				EasyLugg_Dashboard_Forms::render_create_screen();
			} else {
				echo '<p>' . esc_html( self::t( 'gate.forbidden' ) ) . '</p>';
			}
		} elseif ( 'config' === $tab ) {
			if ( current_user_can( 'easylugg_manage_config' ) ) {
				self::render_settings();
			} else {
				echo '<p>' . esc_html( self::t( 'gate.forbidden' ) ) . '</p>';
			}
		} else {
			self::render_list();
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @return string
	 */
	private static function render_login() {
		$form = wp_login_form(
			array(
				'echo'     => false,
				'redirect' => get_permalink() ? get_permalink() : EasyLugg_Dashboard_Setup::dashboard_url(),
			)
		);
		return '<div class="eld eld-gate"><h2>' . esc_html( self::t( 'gate.login_title' ) ) . '</h2><p>' . esc_html( self::t( 'gate.login_text' ) ) . '</p>' . $form . '</div>';
	}

	/**
	 * Plain 403: no booking data at all (SEC-03).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function render_forbidden( $message ) {
		if ( ! headers_sent() ) {
			status_header( 403 );
		}
		return '<div class="eld eld-gate"><h2>403</h2><p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( wp_logout_url( self::url() ) ) . '">' . esc_html( self::t( 'nav.logout' ) ) . '</a></p></div>';
	}

	/**
	 * @param string $tab Current tab.
	 * @return void
	 */
	private static function render_header( $tab ) {
		$w = isset( $_GET['mode'] ) && is_scalar( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'open';
		$q = isset( $_GET['q'] ) && is_scalar( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		?>
		<header class="eld-head">
			<div class="eld-head-top">
				<h2><a href="<?php echo esc_url( self::url() ); ?>"><?php echo esc_html( self::t( 'nav.title' ) ); ?></a></h2>
				<span class="eld-user"><?php echo esc_html( wp_get_current_user()->display_name ); ?> · <a href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>"><?php echo esc_html( self::t( 'nav.logout' ) ); ?></a></span>
			</div>
			<nav class="eld-nav" aria-label="Dashboard">
				<?php foreach ( array( 'open', 'close', 'day', 'held' ) as $mode ) : ?>
					<a class="<?php echo ( '' === $tab && $w === $mode && '' === $q ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'mode' => $mode ) ) ); ?>"><?php echo esc_html( self::t( 'nav.' . $mode ) ); ?></a>
				<?php endforeach; ?>
				<?php if ( current_user_can( 'easylugg_create_booking' ) ) : ?>
					<a class="eld-primary <?php echo 'create' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => 'create' ) ) ); ?>"><?php echo esc_html( self::t( 'nav.create' ) ); ?></a>
				<?php endif; ?>
				<?php if ( current_user_can( 'easylugg_manage_config' ) ) : ?>
					<a class="<?php echo 'config' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => 'config' ) ) ); ?>"><?php echo esc_html( self::t( 'nav.settings' ) ); ?></a>
				<?php endif; ?>
			</nav>
			<form class="eld-search" method="get" action="<?php echo esc_url( self::url() ); ?>">
				<?php
				// Keep the page identity when permalinks are plain.
				if ( isset( $_GET['page_id'] ) && is_scalar( $_GET['page_id'] ) ) {
					echo '<input type="hidden" name="page_id" value="' . esc_attr( absint( $_GET['page_id'] ) ) . '">';
				}
				?>
				<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( self::t( 'nav.search' ) ); ?>" aria-label="<?php echo esc_attr( self::t( 'nav.search' ) ); ?>">
			</form>
		</header>
		<?php
	}

	// ---- List --------------------------------------------------------------

	/**
	 * @return void
	 */
	private static function render_list() {
		global $wpdb;
		$table = EasyLugg_Booking_Plugin::table_name();
		$audit = $wpdb->prefix . 'easylugg_audit_log';
		$today = current_time( 'Y-m-d' );

		$get = function ( $key ) {
			return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$mode     = in_array( $get( 'mode' ), array( 'open', 'close', 'day', 'held' ), true ) ? $get( 'mode' ) : 'open';
		$q        = $get( 'q' );
		$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'd' ) ) ? $get( 'd' ) : $today;
		$corridor = in_array( $get( 'c' ), array( 'AH', 'HA', 'HH', 'ST' ), true ) ? $get( 'c' ) : '';
		$status   = array_key_exists( $get( 'st' ), self::$plugin->status_labels() ) ? $get( 'st' ) : '';
		$source   = in_array( $get( 'src' ), array( 'web', 'internal', 'walk-in', 'ota', 'phone' ), true ) ? $get( 'src' ) : '';
		$page     = max( 1, (int) $get( 'pg' ) );

		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(b.booking_code LIKE %s OR b.customer_name LIKE %s OR b.customer_email LIKE %s)';
			array_push( $args, $like, $like, $like );
			$order = 'b.created_at DESC';
		} else {
			$order = 'b.service_date ASC, ' . self::time_sql() . ' ASC, b.created_at ASC';
			if ( 'open' === $mode ) {
				$where[] = "b.status IN ('requested','confirmed','collected','in_transit') AND b.service_date <= %s";
				$args[]  = $today;
			} elseif ( 'close' === $mode ) {
				$where[] = "b.status = 'delivered'";
			} elseif ( 'held' === $mode ) {
				$where[] = "b.status = 'no_show' AND EXISTS (SELECT 1 FROM {$audit} a WHERE a.entity_type = 'booking' AND a.entity_id = b.booking_code AND a.action = 'custody_held') AND NOT EXISTS (SELECT 1 FROM {$audit} a2 WHERE a2.entity_type = 'booking' AND a2.entity_id = b.booking_code AND a2.action = 'custody_released')";
			} else {
				$where[] = 'b.service_date = %s';
				$args[]  = $date;
				if ( $corridor ) {
					$where[] = 'b.corridor = %s';
					$args[]  = $corridor;
				}
				if ( $status ) {
					$where[] = 'b.status = %s';
					$args[]  = $status;
				}
				if ( $source ) {
					$where[] = 'web' === $source ? 'b.source IS NULL' : 'b.source = %s';
					if ( 'web' !== $source ) {
						$args[] = $source;
					}
				}
			}
		}
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} b WHERE {$where_sql}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$pages     = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page      = min( $page, $pages );

		$list_sql = "SELECT b.* FROM {$table} b WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) ) );
		if ( '' !== $wpdb->last_error ) {
			// Older MySQL without JSON functions: fall back to a plain sort.
			$list_sql = "SELECT b.* FROM {$table} b WHERE {$where_sql} ORDER BY b.service_date ASC, b.created_at ASC LIMIT %d OFFSET %d";
			$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) ) );
		}
		$rows = is_array( $rows ) ? $rows : array();

		// Day totals (orders + bags) for the whole filtered set, capped.
		$bag_sum = null;
		if ( $total > 0 && $total <= 500 ) {
			$all = $args ? $wpdb->get_col( $wpdb->prepare( "SELECT b.booking_data FROM {$table} b WHERE {$where_sql}", $args ) ) : $wpdb->get_col( "SELECT b.booking_data FROM {$table} b WHERE {$where_sql}" );
			$bag_sum = 0;
			foreach ( (array) $all as $json ) {
				$bag_sum += self::bag_total( self::decode( $json ) );
			}
		}

		if ( 'day' === $mode && '' === $q ) {
			self::render_filters( $date, $corridor, $status, $source );
		}
		if ( null !== $bag_sum ) {
			echo '<p class="eld-summary">' . esc_html( self::tf( 'list.summary', $total, $bag_sum ) ) . '</p>';
		}
		?>
		<div class="eld-table-wrap">
		<table class="eld-table">
			<thead>
				<tr>
					<th><?php echo esc_html( self::t( 'list.code' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.customer' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.route' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.when' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.bags' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.total' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.status' ) ); ?></th>
					<th><?php echo esc_html( self::t( 'list.next' ) ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="8"><?php echo esc_html( self::t( 'list.empty' ) ); ?></td></tr>
			<?php endif; ?>
			<?php
			$caps = self::user_caps();
			foreach ( $rows as $row ) :
				$data    = self::decode( $row->booking_data );
				$overdue = in_array( $row->status, EasyLugg_Dashboard_Rules::open_statuses(), true ) && $row->service_date < $today;
				$actions = EasyLugg_Dashboard_Rules::available_actions( $row->status, $caps, self::is_paid( $row ) );
				$time    = self::first_time( $row->corridor, $data );
				?>
				<tr class="<?php echo $overdue ? 'is-overdue' : ''; ?>">
					<td data-label="<?php echo esc_attr( self::t( 'list.code' ) ); ?>"><a href="<?php echo esc_url( self::url( array( 'view' => $row->booking_code ) ) ); ?>"><strong><?php echo esc_html( $row->booking_code ); ?></strong></a></td>
					<td data-label="<?php echo esc_attr( self::t( 'list.customer' ) ); ?>"><?php echo esc_html( $row->customer_name ); ?><br><small><?php echo esc_html( self::contact_text( $data ) ); ?></small></td>
					<td data-label="<?php echo esc_attr( self::t( 'list.route' ) ); ?>"><?php echo esc_html( self::$plugin->corridor_label( $row->corridor ) ); ?></td>
					<td data-label="<?php echo esc_attr( self::t( 'list.when' ) ); ?>"><?php echo esc_html( trim( $row->service_date . ' ' . $time ) ); ?><?php echo $overdue ? ' <span class="eld-badge is-red">' . esc_html( self::t( 'list.overdue' ) ) . '</span>' : ''; ?></td>
					<td data-label="<?php echo esc_attr( self::t( 'list.bags' ) ); ?>"><?php echo esc_html( self::bag_text( $data ) ); ?></td>
					<td data-label="<?php echo esc_attr( self::t( 'list.total' ) ); ?>"><?php echo esc_html( number_format_i18n( (int) $row->price_total ) ); ?></td>
					<td data-label="<?php echo esc_attr( self::t( 'list.status' ) ); ?>">
						<span class="eld-pill is-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( self::$plugin->status_label( $row->status ) ); ?></span>
						<?php echo (int) $row->needs_review ? '<span class="eld-badge is-red">' . esc_html( self::t( 'list.review' ) ) . '</span>' : ''; ?>
					</td>
					<td data-label="<?php echo esc_attr( self::t( 'list.next' ) ); ?>">
						<?php if ( $actions ) : ?>
							<a class="eld-btn" href="<?php echo esc_url( self::url( array( 'view' => $row->booking_code ) ) . '#eld-actions' ); ?>"><?php echo esc_html( self::t( 'act.' . $actions[0] ) ); ?></a>
						<?php else : ?>
							<a href="<?php echo esc_url( self::url( array( 'view' => $row->booking_code ) ) ); ?>"><?php echo esc_html( self::t( 'list.open' ) ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		if ( $pages > 1 ) {
			$keep = array_filter(
				array(
					'mode' => $mode,
					'q'   => $q,
					'd'   => 'day' === $mode ? $date : '',
					'c'   => $corridor,
					'st'  => $status,
					'src' => $source,
				)
			);
			echo '<nav class="eld-pager">';
			if ( $page > 1 ) {
				echo '<a href="' . esc_url( self::url( array_merge( $keep, array( 'pg' => $page - 1 ) ) ) ) . '">' . esc_html( self::t( 'list.prev' ) ) . '</a> ';
			}
			echo '<span>' . esc_html( self::tf( 'list.page', $page, $pages ) ) . '</span>';
			if ( $page < $pages ) {
				echo ' <a href="' . esc_url( self::url( array_merge( $keep, array( 'pg' => $page + 1 ) ) ) ) . '">' . esc_html( self::t( 'list.next_page' ) ) . '</a>';
			}
			echo '</nav>';
		}
	}

	/**
	 * SQL expression for the first service time of a booking (JSON paths).
	 *
	 * @return string
	 */
	private static function time_sql() {
		$x = function ( $path ) {
			return "JSON_UNQUOTE(JSON_EXTRACT(b.booking_data, '$.{$path}'))";
		};
		return "CASE b.corridor WHEN 'ST' THEN COALESCE(" . $x( 'dropoff_time' ) . ", '99:99') WHEN 'HA' THEN COALESCE(" . $x( 'airport_delivery_time' ) . ", '99:99') WHEN 'AH' THEN COALESCE(" . $x( 'pickup_time' ) . ", '99:99') ELSE '99:99' END";
	}

	/**
	 * @param string $date     Date.
	 * @param string $corridor Route filter.
	 * @param string $status   Status filter.
	 * @param string $source   Source filter.
	 * @return void
	 */
	private static function render_filters( $date, $corridor, $status, $source ) {
		?>
		<form class="eld-filters" method="get" action="<?php echo esc_url( self::url() ); ?>">
			<input type="hidden" name="mode" value="day">
			<?php
			if ( isset( $_GET['page_id'] ) && is_scalar( $_GET['page_id'] ) ) {
				echo '<input type="hidden" name="page_id" value="' . esc_attr( absint( $_GET['page_id'] ) ) . '">';
			}
			?>
			<label><?php echo esc_html( self::t( 'list.date' ) ); ?> <input type="date" name="d" value="<?php echo esc_attr( $date ); ?>"></label>
			<label><?php echo esc_html( self::t( 'list.route' ) ); ?>
				<select name="c"><option value=""><?php echo esc_html( self::t( 'list.all' ) ); ?></option>
					<?php foreach ( array( 'AH', 'HA', 'HH', 'ST' ) as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $corridor, $c ); ?>><?php echo esc_html( $c ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<label><?php echo esc_html( self::t( 'list.status' ) ); ?>
				<select name="st"><option value=""><?php echo esc_html( self::t( 'list.all' ) ); ?></option>
					<?php foreach ( self::$plugin->status_labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<label><?php echo esc_html( self::t( 'list.source' ) ); ?>
				<select name="src"><option value=""><?php echo esc_html( self::t( 'list.all' ) ); ?></option>
					<?php foreach ( array( 'web', 'internal', 'walk-in', 'ota', 'phone' ) as $src ) : ?>
						<option value="<?php echo esc_attr( $src ); ?>" <?php selected( $source, $src ); ?>><?php echo esc_html( self::t( 'source.' . $src ) ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<button type="submit" class="eld-btn"><?php echo esc_html( self::t( 'list.filter' ) ); ?></button>
		</form>
		<?php
	}

	/**
	 * Capabilities the current user holds, as a flat list for the rules.
	 *
	 * @return array<int, string>
	 */
	public static function user_caps() {
		$caps = array();
		foreach ( EasyLugg_Dashboard_Setup::all_caps() as $cap ) {
			if ( current_user_can( $cap ) ) {
				$caps[] = $cap;
			}
		}
		return $caps;
	}

	// ---- Detail ------------------------------------------------------------

	/**
	 * @param string $code Booking code.
	 * @return void
	 */
	private static function render_detail( $code ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EasyLugg_Booking_Plugin::table_name() . ' WHERE booking_code = %s', $code ) );
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html( self::t( 'nav.back' ) ) . '</a></p>';
		if ( ! $row ) {
			echo '<p>' . esc_html( self::t( 'detail.not_found' ) ) . '</p>';
			return;
		}

		// DET-04: one audit row per Detail open (never for list rows).
		EasyLugg_Dashboard_Setup::audit( 'booking', $row->booking_code, 'pii_view' );

		$data   = self::decode( $row->booking_data );
		$labels = self::$plugin->booking_detail_labels( 'storage' === ( $data['service'] ?? '' ) );
		$caps   = self::user_caps();
		$paid   = self::is_paid( $row );
		$mode   = EasyLugg_Dashboard_Rules::edit_mode( $row->status );
		?>
		<section class="eld-card">
			<div class="eld-card-head">
				<h3><?php echo esc_html( $row->booking_code ); ?>
					<span class="eld-pill is-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( self::$plugin->status_label( $row->status ) ); ?></span>
					<?php echo (int) $row->needs_review ? '<span class="eld-badge is-red">' . esc_html( self::t( 'detail.review_flag' ) ) . '</span>' : ''; ?>
				</h3>
				<?php if ( current_user_can( 'easylugg_edit_booking' ) && 'note' !== $mode ) : ?>
					<a class="eld-btn" href="<?php echo esc_url( self::url( array( 'edit' => $row->booking_code ) ) ); ?>"><?php echo esc_html( self::t( 'detail.edit' ) ); ?></a>
				<?php endif; ?>
			</div>
		</section>

		<div class="eld-detail">
		<div class="eld-col">
		<section class="eld-card">
			<h4><?php echo esc_html( self::t( 'detail.order' ) ); ?></h4>
			<table class="eld-kv">
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php
					if ( in_array( $key, array( 'customer', 'payment_method', 'estimate' ), true ) ) {
						continue;
					}
					if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] || array() === $data[ $key ] ) {
						continue;
					}
					?>
					<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo wp_kses_post( self::$plugin->format_admin_value( $data[ $key ] ) ); ?></td></tr>
				<?php endforeach; ?>
				<tr><th><?php echo esc_html( self::t( 'detail.source' ) ); ?></th><td><?php echo esc_html( self::t( 'source.' . ( $row->source ? $row->source : 'web' ) ) ); ?></td></tr>
				<?php if ( ! empty( $data['ota_code'] ) ) : ?>
					<tr><th><?php echo esc_html( self::t( 'detail.ota_code' ) ); ?></th><td><?php echo esc_html( $data['ota_code'] ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php echo esc_html( self::t( 'detail.created' ) ); ?></th><td><?php echo esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr>
			</table>
			<p><small><?php echo esc_html( self::t( 'detail.tracking' ) ); ?>: <a href="<?php echo esc_url( self::tracking_url( $row ) ); ?>" rel="noopener noreferrer" target="_blank"><?php echo esc_html( self::tracking_url( $row ) ); ?></a></small></p>
		</section>

		<section class="eld-card">
			<h4><?php echo esc_html( self::t( 'detail.customer' ) ); ?></h4>
			<?php
			// The passport number is never shown on the Dashboard (D-DB8).
			$customer = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
			unset( $customer['passport'] );
			echo '<p>' . wp_kses_post( self::$plugin->format_admin_value( $customer ) ) . '</p>';
			?>
		</section>

		<section class="eld-card">
			<h4><?php echo esc_html( self::t( 'detail.money' ) ); ?></h4>
			<?php $storage_fee = (int) ( $data['storage_fee'] ?? 0 ); ?>
			<table class="eld-kv">
				<?php if ( $storage_fee > 0 && 'ST' !== $row->corridor ) : ?>
					<tr><th><?php echo esc_html( self::t( 'list.total' ) ); ?> (<?php echo esc_html( self::$plugin->text( 'summary.route_st' ) ); ?>)</th><td><?php echo esc_html( number_format_i18n( $storage_fee ) ); ?> VND</td></tr>
				<?php endif; ?>
				<tr><th><?php echo esc_html( self::t( 'detail.total' ) ); ?></th><td><strong><?php echo esc_html( number_format_i18n( (int) $row->price_total ) ); ?> VND</strong></td></tr>
				<tr><th><?php echo esc_html( self::t( 'detail.method' ) ); ?></th><td><?php echo esc_html( self::t( 'pay.' . ( $row->payment_method ? $row->payment_method : 'pending' ) ) ); ?></td></tr>
				<tr><th><?php echo esc_html( self::t( 'detail.pay_status' ) ); ?></th><td><?php echo esc_html( self::t( 'pay.' . ( 'ota_paid' === $row->payment_status ? 'ota_paid' : ( 'paid' === $row->payment_status ? 'paid' : ( 'demo' === $row->payment_status ? 'demo' : 'pending' ) ) ) ) ); ?></td></tr>
				<tr><th><?php echo esc_html( self::t( 'detail.invoice' ) ); ?></th><td><?php echo esc_html( $row->invoice_no ? $row->invoice_no : '—' ); ?></td></tr>
			</table>
		</section>

		<?php self::render_photos( $row ); ?>
		</div>
		<div class="eld-col">
		<section class="eld-card" id="eld-actions">
			<h4><?php echo esc_html( self::t( 'detail.actions' ) ); ?></h4>
			<?php EasyLugg_Dashboard_Forms::render_actions( $row, $data, $caps, $paid ); ?>
		</section>

		<section class="eld-card">
			<h4><?php echo esc_html( self::t( 'detail.timeline' ) ); ?></h4>
			<?php self::render_timeline( $row ); ?>
		</section>
		</div>
		</div>
		<?php
	}

	/**
	 * @param object $row Booking row.
	 * @return void
	 */
	private static function render_photos( $row ) {
		global $wpdb;
		$photos = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, stage, created_at FROM ' . $wpdb->prefix . 'easylugg_booking_photos WHERE booking_code = %s ORDER BY id ASC', $row->booking_code ) );
		$link   = function ( $id ) use ( $row ) {
			return add_query_arg(
				array(
					'action' => 'easylugg_dashboard_photo',
					'code'   => $row->booking_code,
					'id'     => (int) $id,
					'nonce'  => wp_create_nonce( self::NONCE ),
				),
				admin_url( 'admin-ajax.php' )
			);
		};
		echo '<section class="eld-card"><h4>' . esc_html( self::t( 'detail.photos' ) ) . '</h4>';
		if ( ! $row->photo_path && ! $photos ) {
			echo '<p>' . esc_html( self::t( 'detail.no_photos' ) ) . '</p>';
		}
		echo '<ul class="eld-photos">';
		if ( $row->photo_path ) {
			echo '<li><a href="' . esc_url( $link( 0 ) ) . '">' . esc_html( self::t( 'detail.customer_photo' ) ) . '</a></li>';
		}
		foreach ( $photos as $photo ) {
			echo '<li><a href="' . esc_url( $link( $photo->id ) ) . '">' . esc_html( self::t( 'detail.stage_' . $photo->stage ) . ' #' . $photo->id ) . '</a> <small>' . esc_html( get_date_from_gmt( $photo->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</small></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * @param object $row Booking row.
	 * @return void
	 */
	private static function render_timeline( $row ) {
		$events = EasyLugg_Dashboard_Setup::booking_timeline( $row->booking_code );
		if ( ! $events ) {
			echo '<p>—</p>';
			return;
		}
		echo '<ol class="eld-timeline">';
		foreach ( $events as $event ) {
			$user = $event->actor_id ? get_userdata( (int) $event->actor_id ) : false;
			$who  = $user ? $user->display_name : self::t( 'detail.system' );
			$what = self::t( 'audit.' . $event->action );
			if ( $event->from_status || $event->to_status ) {
				$what .= ': ' . ( $event->from_status ? self::$plugin->status_label( $event->from_status ) . ' → ' : '' ) . self::$plugin->status_label( $event->to_status );
			}
			$meta = $event->meta ? json_decode( $event->meta, true ) : array();
			echo '<li><strong>' . esc_html( $what ) . '</strong> · ' . esc_html( $who ) . ' · <small>' . esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</small>';
			if ( '' !== $event->note ) {
				echo '<br>' . esc_html( $event->note );
			}
			if ( is_array( $meta ) && $meta ) {
				echo '<br><small class="eld-meta">' . esc_html( self::meta_text( $meta ) ) . '</small>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Compact one-line rendering of audit meta.
	 *
	 * @param array<string, mixed> $meta Meta.
	 * @return string
	 */
	public static function meta_text( $meta ) {
		$parts = array();
		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$parts[] = $key . ': ' . $value;
		}
		return implode( ' · ', $parts );
	}

	// ---- Settings ----------------------------------------------------------

	/**
	 * @return void
	 */
	private static function render_settings() {
		$cfg    = EasyLugg_Dashboard_Config::get();
		$prices = self::$plugin->prices();
		$field  = function ( $name, $label, $value, $attrs = '' ) {
			echo '<label class="eld-field"><span>' . esc_html( $label ) . '</span><input name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" ' . $attrs . '></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$open = function ( $group, $title, $confirm = '' ) {
			echo '<section class="eld-card"><h4>' . esc_html( $title ) . '</h4>';
			echo '<form class="eld-form" data-action="easylugg_dashboard_save_config"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
			echo '<input type="hidden" name="action" value="easylugg_dashboard_save_config"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"><input type="hidden" name="group" value="' . esc_attr( $group ) . '">';
		};
		$close = function () {
			echo '<button type="submit" class="eld-btn eld-primary">' . esc_html( self::t( 'act.submit' ) ) . '</button></form></section>';
		};

		echo '<h3>' . esc_html( self::t( 'settings.title' ) ) . '</h3>';
		echo '<p><small>' . esc_html( self::t( 'settings.accounts' ) ) . '</small></p>';

		$open( 'pricing', self::t( 'settings.pricing' ), self::t( 'settings.price_confirm' ) );
		foreach ( array( 'cabin', 'medium', 'oversize', 'storage' ) as $key ) {
			$field( 'prices[' . $key . ']', self::t( 'settings.' . $key ), $prices[ $key ], 'type="number" min="0" max="' . (int) EasyLugg_Dashboard_Config::PRICE_CEILING . '" step="1000" required inputmode="numeric"' );
		}
		$close();

		$open( 'hours', self::t( 'settings.hours' ) );
		echo '<label class="eld-field"><span>' . esc_html( self::t( 'settings.airport_slots' ) ) . '</span><textarea name="airport_slots" rows="2">' . esc_textarea( implode( ' ', (array) $cfg['airport_slots'] ) ) . '</textarea></label>';
		$field( 'storage_open', self::t( 'settings.storage_open' ), $cfg['storage_open'], 'type="time" step="1800" required' );
		$field( 'storage_close', self::t( 'settings.storage_close' ), $cfg['storage_close'], 'type="time" step="1800" required' );
		$field( 'buffer_minutes', self::t( 'settings.buffer' ), $cfg['buffer_minutes'], 'type="number" min="0" max="240" required' );
		$field( 'hh_cutoff', self::t( 'settings.hh_cutoff' ), $cfg['hh_cutoff'], 'type="time" required' );
		echo '<p><small>' . esc_html( self::t( 'settings.slots_note' ) ) . '</small></p>';
		$close();

		$open( 'capacity', self::t( 'settings.capacity' ) );
		$field( 'van_capacity', self::t( 'settings.van_capacity' ), $cfg['van_capacity'], 'type="number" min="1" max="500" required' );
		$field( 'warn_percent', self::t( 'settings.warn_percent' ), $cfg['warn_percent'], 'type="number" min="1" max="100" required' );
		$field( 'loops_per_day', self::t( 'settings.loops' ), $cfg['loops_per_day'], 'type="number" min="1" max="6" required' );
		echo '<p><small>' . esc_html( self::t( 'settings.capacity_note' ) ) . '</small></p>';
		$close();

		$open( 'hub', self::t( 'settings.hub' ) );
		$texts = self::$plugin->texts();
		$field( 'hub_name', self::t( 'settings.hub_name' ), $cfg['hub_name'], 'maxlength="190" placeholder="' . esc_attr( $texts['storage']['hub_name'] ?? '' ) . '"' );
		$field( 'hub_address', self::t( 'settings.hub_address' ), $cfg['hub_address'], 'maxlength="300" placeholder="' . esc_attr( $texts['storage']['hub_address'] ?? '' ) . '"' );
		$field( 'hub_hours', self::t( 'settings.hub_hours' ), $cfg['hub_hours'], 'maxlength="100" placeholder="' . esc_attr( $texts['storage']['hub_hours'] ?? '' ) . '"' );
		$field( 'airport_point', self::t( 'settings.airport_point' ), $cfg['airport_point'], 'maxlength="190"' );
		echo '<p><small>' . esc_html( self::t( 'settings.hub_note' ) ) . '</small></p>';
		$close();

		echo '<section class="eld-card"><h4>' . esc_html( self::t( 'settings.history' ) ) . '</h4>';
		$changes = EasyLugg_Dashboard_Config::recent_changes();
		if ( ! $changes ) {
			echo '<p>' . esc_html( self::t( 'settings.no_history' ) ) . '</p>';
		} else {
			echo '<ul class="eld-history">';
			foreach ( $changes as $change ) {
				$user = $change->actor_id ? get_userdata( (int) $change->actor_id ) : false;
				$meta = $change->meta ? json_decode( $change->meta, true ) : array();
				$old  = is_array( $meta ) && array_key_exists( 'old', $meta ) ? ( is_array( $meta['old'] ) ? wp_json_encode( $meta['old'] ) : $meta['old'] ) : '';
				$new  = is_array( $meta ) && array_key_exists( 'new', $meta ) ? ( is_array( $meta['new'] ) ? wp_json_encode( $meta['new'] ) : $meta['new'] ) : '';
				echo '<li><strong>' . esc_html( $change->entity_id ) . '</strong>: ' . esc_html( (string) $old ) . ' → ' . esc_html( (string) $new ) . ' · ' . esc_html( $user ? $user->display_name : self::t( 'detail.system' ) ) . ' · <small>' . esc_html( get_date_from_gmt( $change->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</small></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}
}
