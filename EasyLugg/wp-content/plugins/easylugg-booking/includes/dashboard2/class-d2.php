<?php
/**
 * Dashboard 2 — skeleton (Phase 1A, brief D2-01) with the Stitch
 * "Operational Console" design applied (25/11/2026).
 *
 * Shortcode [easylugg_dashboard2] on /dashboard-2/. Read-only frame:
 * sidebar + header + empty content regions. No booking data is queried
 * or rendered yet (that arrives in D2-02/D2-03/D2-04).
 *
 * Layout follows the Stitch screen "EasyLugg Dashboard 2 - Operational
 * Console" (company project 17483541559657137315, 2560x3004): fixed navy
 * sidebar, fixed white header, KPI row, 3-column workspace
 * (List | Detail | Live Tracking + Exception), Proof & Support row,
 * floating switch button. All styling is plain CSS in
 * assets/dashboard2/dashboard2.css (no Tailwind CDN, no external fonts).
 * Sample data from the Stitch screen (booking codes, guest names, KPI
 * numbers, photos) is NEVER copied into this file — the regions stay
 * empty until D2-02/D2-03/D2-04 fill them with real database data.
 *
 * Permissions: everyone with easylugg_view_dashboard sees Dashboard 2
 * (D2-07). The Settings menu item is Admin-only (easylugg_manage_config).
 * The switch button is a static link to Dashboard 1 for now; the
 * toggle/remember behaviour is built in D2-05.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_D2 {

	const NONCE          = 'easylugg_d2';
	const OPTION_ENABLED = 'easylugg_d2_enabled';

	/**
	 * @return void
	 */
	public static function hooks() {
		add_shortcode( 'easylugg_dashboard2', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Kill switch (SW-06). Default on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === (string) get_option( self::OPTION_ENABLED, '1' );
	}

	/**
	 * The single place that decides who can see Dashboard 2 (D2-07):
	 * logged in, not locked, and has easylugg_view_dashboard.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return 'ok' === self::gate_state();
	}

	/**
	 * Access state, so the shortcode and every AJAX handler share ONE rule:
	 * 'login' (not logged in), 'locked', 'forbidden' (no capability) or 'ok'.
	 *
	 * @return string
	 */
	public static function gate_state() {
		if ( ! is_user_logged_in() ) {
			return 'login';
		}
		if ( EasyLugg_Dashboard_Setup::is_locked( get_current_user_id() ) ) {
			return 'locked';
		}
		return current_user_can( 'easylugg_view_dashboard' ) ? 'ok' : 'forbidden';
	}

	/**
	 * Whether the current request is a page carrying [easylugg_dashboard2].
	 * Mirrors EasyLugg_Booking_Plugin::is_dashboard_request().
	 *
	 * @return bool
	 */
	public static function is_dashboard2_request() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post && has_shortcode( $post->post_content, 'easylugg_dashboard2' );
	}

	/**
	 * Which screen this request asks for:
	 *  home    = Dashboard 2 overview (KPI, list, detail, tracking, proof)
	 *  embed   = a Dashboard 1 screen (bookings table, detail, edit, create,
	 *            settings) hosted inside the Dashboard 2 shell
	 *  classic = the untouched Dashboard 1 page (?classic=1)
	 *
	 * @return string
	 */
	public static function view_mode() {
		$has = function ( $key ) {
			return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification
		};
		if ( $has( 'classic' ) ) {
			return 'classic';
		}
		foreach ( array( 'view', 'edit', 'tab', 'mode', 'q' ) as $key ) {
			if ( $has( $key ) ) {
				return 'embed';
			}
		}
		return 'home';
	}

	/**
	 * Which Dashboard 1 screen an embed request shows: create | config | other.
	 *
	 * @return string
	 */
	public static function embed_screen() {
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $tab, array( 'create', 'config' ), true ) ? $tab : 'other';
	}

	/**
	 * Current page URL with extra query args (the shell works on whichever
	 * page carries the shortcode: /dashboard/ or /dashboard-2/).
	 *
	 * @param array<string,string> $args Query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$base = get_permalink();
		if ( ! $base ) {
			$base = self::dashboard2_url();
		}
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * URL of the Dashboard 2 page (slug dashboard-2), or the fallback path.
	 *
	 * @return string
	 */
	public static function dashboard2_url() {
		$page = get_page_by_path( 'dashboard-2' );
		if ( ! $page ) {
			return home_url( '/dashboard-2/' );
		}
		// WPML: link to the translation for the current language (slug stays dashboard-2).
		$lang = EasyLugg_Booking_Plugin::current_lang();
		$id   = '' !== $lang ? apply_filters( 'wpml_object_id', $page->ID, 'page', true, $lang ) : $page->ID;
		return get_permalink( $id ? $id : $page->ID );
	}

	/**
	 * All Dashboard 2 text, loaded once per language from texts.php.
	 * Cached per language like EasyLugg_Booking_Plugin::texts() (emails
	 * switch language mid-request), never once for all languages.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function texts() {
		static $cache = array();
		$lang = EasyLugg_Booking_Plugin::current_lang();
		if ( ! isset( $cache[ $lang ] ) ) {
			$texts = include plugin_dir_path( __FILE__ ) . 'texts.php';
			if ( ! is_array( $texts ) ) {
				$texts = array();
			}
			$cache[ $lang ] = $texts;
		}
		return $cache[ $lang ];
	}

	/**
	 * One string by dotted address, e.g. t( 'nav.dashboard' ).
	 * Falls back to the address itself when the key is missing, so a typo
	 * shows a visible key instead of silently rendering nothing.
	 *
	 * @param string $key Dotted address (section.key).
	 * @return string
	 */
	public static function t( $key ) {
		$texts = self::texts();
		$parts = explode( '.', $key, 2 );
		if ( 2 !== count( $parts ) ) {
			return $key;
		}
		list( $section, $name ) = $parts;
		if ( isset( $texts[ $section ][ $name ] ) && is_string( $texts[ $section ][ $name ] ) ) {
			return $texts[ $section ][ $name ];
		}
		return $key;
	}

	/**
	 * No-cache + noindex for the Dashboard 2 page only.
	 * Mirrors EasyLugg_Dashboard::send_headers().
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( ! self::is_dashboard2_request() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: same-origin' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'EasyLugg Dashboard 2' );
	}

	/**
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ) {
		if ( self::is_dashboard2_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Enqueue Dashboard 2 assets, only on the Dashboard 2 page.
	 * The customer booking scripts (booking.js, booking-storage.js,
	 * booking-tracking.js) and the Dashboard 1 assets are never loaded here.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_dashboard2_request() ) {
			return;
		}
		$root = plugin_dir_path( dirname( dirname( __FILE__ ) ) );
		$css  = $root . 'assets/dashboard2/dashboard2.css';
		$js   = $root . 'assets/dashboard2/dashboard2.js';
		wp_enqueue_style( 'easylugg-d2-style', plugins_url( 'assets/dashboard2/dashboard2.css', dirname( dirname( __FILE__ ) ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $css ) );
		$mode = self::view_mode();
		if ( 'home' !== $mode ) {
			// Dashboard 1 screens: load Dashboard 1's own assets (same handles, same
			// localized object, same nonce) because its shortcode page check
			// does not match this page.
			$d1css = $root . 'assets/booking-dashboard.css';
			$d1js  = $root . 'assets/booking-dashboard.js';
			wp_enqueue_style( 'easylugg-dashboard', plugins_url( 'assets/booking-dashboard.css', dirname( dirname( __FILE__ ) ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $d1css ) );
			wp_enqueue_script( 'easylugg-dashboard', plugins_url( 'assets/booking-dashboard.js', dirname( dirname( __FILE__ ) ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $d1js ), true );
			wp_localize_script(
				'easylugg-dashboard',
				'EasyLuggDashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( EasyLugg_Dashboard::NONCE ),
					'working' => EasyLugg_Dashboard::t( 'form.working' ),
					'error'   => EasyLugg_Dashboard::t( 'form.error_generic' ),
					'confirm' => EasyLugg_Dashboard::t( 'act.confirm_prompt' ),
				)
			);
			$ejs = $root . 'assets/dashboard2/dashboard2-embed.js';
			wp_enqueue_script( 'easylugg-d2-embed', plugins_url( 'assets/dashboard2/dashboard2-embed.js', dirname( dirname( __FILE__ ) ) ), array( 'easylugg-dashboard' ), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $ejs ), true );
			wp_localize_script(
				'easylugg-d2-embed',
				'EasyLuggD2Embed',
				array(
					'home'   => self::page_url(),
					'labels' => array(
						'schedule'  => self::t( 'create.sec_schedule' ),
						'locations' => self::t( 'create.sec_locations' ),
						'luggage'   => self::t( 'create.sec_luggage' ),
						'customer'  => self::t( 'create.sec_customer' ),
						'details'   => self::t( 'create.sec_details' ),
						'cancel'    => self::t( 'create.cancel' ),
						'minus'     => self::t( 'create.minus' ),
						'plus'      => self::t( 'create.plus' ),
						'sections'  => self::t( 'settings.sections' ),
					),
				)
			);
			return;
		}
		wp_enqueue_script( 'easylugg-d2', plugins_url( 'assets/dashboard2/dashboard2.js', dirname( dirname( __FILE__ ) ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $js ), true );
		$lang     = EasyLugg_Booking_Plugin::current_lang();
		$ajax_url = admin_url( 'admin-ajax.php' );
		if ( '' !== $lang ) {
			$ajax_url = add_query_arg( 'lang', $lang, $ajax_url );
		}
		wp_localize_script(
			'easylugg-d2',
			'EasyLuggD2',
			array(
				'ajaxUrl' => $ajax_url,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'texts'   => array(
					'error'   => self::t( 'list.error' ),
					'retry'   => self::t( 'list.retry' ),
					'offline' => self::t( 'kpi.offline' ),
					'live'    => self::t( 'kpi.live' ),
				),
				'today'   => wp_date( 'Y-m-d' ),
			)
		);
	}

	// ---- Shortcode ---------------------------------------------------------

	/**
	 * @return string
	 */
	public static function render_shortcode() {
		// Disabled: one short message, nothing else.
		if ( ! self::is_enabled() ) {
			return '<div class="eld2 eld2-gate"><p>' . esc_html( self::t( 'disabled.message' ) ) . '</p></div>';
		}
		// Permission is decided BEFORE any DB query (same order as Dashboard 1).
		$gate = self::gate_state();
		if ( 'login' === $gate ) {
			return self::render_login();
		}
		if ( 'locked' === $gate ) {
			return self::render_forbidden( self::t( 'gate.locked' ) );
		}
		if ( 'ok' !== $gate ) {
			return self::render_forbidden( self::t( 'gate.no_permission' ) );
		}

		$mode = self::view_mode();
		if ( 'classic' === $mode ) {
			return '<div class="eld2-classic">' . do_shortcode( '[easylugg_dashboard]' ) . '</div>'
				. '<aside class="eld2-switch eld2-switch-classic"><a href="' . esc_url( self::page_url() ) . '">&#8646; ' . esc_html( self::t( 'switch.to_dashboard2' ) ) . '</a></aside>';
		}

		ob_start();
		// eld2-app = app shell inside the theme page (site header/footer stay).
		echo '<div class="eld2 eld2-app" id="eld2">';
		self::render_sidebar( $mode );
		echo '<div class="eld2-body">';
		self::render_header( $mode );
		echo '<main class="eld2-main">';
		if ( 'embed' === $mode ) {
			// Dashboard 1 screens (bookings table, detail, edit, create, settings),
			// reusing Dashboard 1's own rendering and save handlers unchanged.
			echo '<div class="eld2-embed" data-screen="' . esc_attr( self::embed_screen() ) . '">' . do_shortcode( '[easylugg_dashboard]' ) . '</div>';
		} else {
			// Regions filled by dashboard2.js from the AJAX endpoints.
			echo '<div class="eld2-kpi" id="eld2-kpi" aria-live="polite"></div>';
			echo '<div class="eld2-work">';
			echo '<div class="eld2-list" id="eld2-list"></div>';
			echo '<div class="eld2-detail" id="eld2-detail">' . EasyLugg_D2_Render::detail_empty() . '</div>';
			echo '<div class="eld2-tracking" id="eld2-tracking"></div>';
			echo '</div>';
			echo '<div class="eld2-proof" id="eld2-proof"></div>';
		}
		echo '</main>';
		echo '</div>';
		self::render_switch();
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
				'redirect' => self::dashboard2_url(),
			)
		);
		return '<div class="eld2 eld2-gate"><h2>' . esc_html( self::t( 'gate.login_title' ) ) . '</h2><p>' . esc_html( self::t( 'gate.login_text' ) ) . '</p>' . $form . '</div>';
	}

	/**
	 * Plain 403: no booking data at all.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function render_forbidden( $message ) {
		if ( ! headers_sent() ) {
			status_header( 403 );
		}
		return '<div class="eld2 eld2-gate"><h2>403</h2><p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( wp_logout_url( self::dashboard2_url() ) ) . '">' . esc_html( self::t( 'gate.logout' ) ) . '</a></p></div>';
	}

	/**
	 * Fixed navy sidebar: logo (text placeholder until the owner sends
	 * the logo file) + left menu. Operations, Customers, Partners,
	 * Reports are NOT rendered in Phase 1 (no data/process yet, SRS §9).
	 * Settings links to the Dashboard 1 Settings tab and is Admin-only.
	 *
	 * @return void
	 */
	private static function render_sidebar( $mode = 'home' ) {
		$tab          = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$is_settings  = 'embed' === $mode && 'config' === $tab;
		$is_bookings  = 'embed' === $mode && ! $is_settings;
		$home_url     = self::page_url();
		$bookings_url = current_user_can( 'easylugg_create_booking' ) ? self::page_url( array( 'tab' => 'create' ) ) : self::page_url( array( 'mode' => 'open' ) );
		$settings_url = self::page_url( array( 'tab' => 'config' ) );
		?>
		<aside class="eld2-side">
			<div class="eld2-logo"><?php echo esc_html( self::t( 'header.logo' ) ); ?></div>
			<nav class="eld2-nav" aria-label="<?php echo esc_attr( self::t( 'nav.aria' ) ); ?>">
				<a class="<?php echo 'home' === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url( $home_url ); ?>"><?php echo esc_html( self::t( 'nav.dashboard' ) ); ?></a>
				<a class="<?php echo $is_bookings ? 'is-active' : ''; ?>" href="<?php echo esc_url( $bookings_url ); ?>"><?php echo esc_html( self::t( 'nav.bookings' ) ); ?></a>
				<?php if ( current_user_can( 'easylugg_manage_config' ) ) : ?>
					<a class="<?php echo $is_settings ? 'is-active' : ''; ?>" href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html( self::t( 'nav.settings' ) ); ?></a>
				<?php endif; ?>
			</nav>
		</aside>
		<?php
	}

	/**
	 * Fixed white header: title + subtitle, search and bell (position
	 * only, no function yet — search/bell count come in D2-03), account
	 * chip with avatar initials, display name and role.
	 *
	 * @return void
	 */
	private static function render_header( $mode = 'home' ) {
		$user = wp_get_current_user();
		$role = current_user_can( 'easylugg_manage_config' ) ? self::t( 'header.role_admin' ) : self::t( 'header.role_staff' );
		?>
		<header class="eld2-head">
			<div class="eld2-title">
				<?php
				$screen = 'embed' === $mode ? self::embed_screen() : 'home';
				$titles = array(
					'create' => array( 'create.title', 'create.subtitle' ),
					'config' => array( 'settings.title', 'settings.subtitle' ),
				);
				$pair   = isset( $titles[ $screen ] ) ? $titles[ $screen ] : array( 'header.title', 'header.subtitle' );
				?>
				<h1><?php echo esc_html( self::t( $pair[0] ) ); ?></h1>
				<p><?php echo esc_html( self::t( $pair[1] ) ); ?></p>
			</div>
			<?php if ( 'embed' === $mode && in_array( self::embed_screen(), array( 'create', 'config' ), true ) ) : ?>
				<div class="eld2-search"></div>
			<?php elseif ( 'home' === $mode ) : ?>
				<div class="eld2-search">
					<input type="search" id="eld2-search" placeholder="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>">
				</div>
			<?php else : ?>
				<form class="eld2-search" method="get" action="<?php echo esc_url( self::page_url() ); ?>">
					<?php
					if ( isset( $_GET['page_id'] ) && is_scalar( $_GET['page_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
						echo '<input type="hidden" name="page_id" value="' . esc_attr( absint( $_GET['page_id'] ) ) . '">';
					}
					?>
					<input type="search" name="q" placeholder="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>">
				</form>
			<?php endif; ?>
			<button type="button" class="eld2-bell" aria-label="<?php echo esc_attr( self::t( 'header.bell_label' ) ); ?>">&#128276;</button>
			<div class="eld2-acct">
				<span class="eld2-avatar" aria-hidden="true"><?php echo esc_html( self::initials( $user->display_name ) ); ?></span>
				<span class="eld2-acct-text">
					<span class="eld2-acct-name"><?php echo esc_html( $user->display_name ); ?></span>
					<span class="eld2-acct-role"><?php echo esc_html( $role ); ?></span>
				</span>
			</div>
		</header>
		<?php
	}

	/**
	 * Floating switch button (bottom right). Static link to Dashboard 1
	 * for now; the toggle/remember behaviour is D2-05.
	 *
	 * @return void
	 */
	private static function render_switch() {
		?>
		<aside class="eld2-switch">
			<a href="<?php echo esc_url( self::page_url( array( 'classic' => '1' ) ) ); ?>">&#8646; <?php echo esc_html( self::t( 'switch.to_dashboard1' ) ); ?></a>
		</aside>
		<?php
	}

	/**
	 * Avatar initials from a display name (first letters of the first
	 * two words, uppercased). Falls back to "?" for an empty name.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private static function initials( $name ) {
		$words = preg_split( '/\s+/u', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return '?';
		}
		$first = mb_substr( $words[0], 0, 1, 'UTF-8' );
		$second = isset( $words[1] ) ? mb_substr( $words[1], 0, 1, 'UTF-8' ) : '';
		$out = mb_strtoupper( $first . $second, 'UTF-8' );
		return '' !== $out ? $out : '?';
	}
}
