<?php
/**
 * The dashboard (Stitch "Operational Console" design), rendered for the
 * shortcode [easylugg_dashboard].
 *
 * Screens: overview (KPI, booking list, detail, tracking, proof) and the
 * hosted Create booking / Settings / edit / detail screens of the previous
 * dashboard (EasyLugg_Dashboard), which are reused unchanged.
 *
 * Permissions: everyone with easylugg_view_dashboard. The Settings menu item
 * is Admin-only (easylugg_manage_config). Kill switch: option
 * easylugg_app_enabled = 0 serves the previous dashboard again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_App {

	const NONCE          = 'easylugg_app';
	const OPTION_ENABLED = 'easylugg_app_enabled';

	/**
	 * @return void
	 */
	public static function hooks() {
		// This app is rendered under the shortcode [easylugg_dashboard], so the
		// page content never changes. Registered on init (after the previous
		// dashboard registered its own) so ours wins; the previous render
		// function is still called directly for the hosted screens and as the
		// fallback when the kill switch is off.
		add_action( 'init', array( __CLASS__, 'take_over_shortcode' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * @return void
	 */
	public static function take_over_shortcode() {
		remove_shortcode( 'easylugg_dashboard' );
		add_shortcode( 'easylugg_dashboard', array( __CLASS__, 'render_shortcode' ) );
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
	 * The single place that decides who can see the dashboard:
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
	 * Whether the current request is a page carrying [easylugg_dashboard].
	 * Mirrors EasyLugg_Booking_Plugin::is_dashboard_request().
	 *
	 * @return bool
	 */
	public static function is_app_request() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! self::is_enabled() ) {
			return false; // Kill switch off: the previous dashboard serves the page on its own.
		}
		return has_shortcode( $post->post_content, 'easylugg_dashboard' );
	}

	/**
	 * Which screen this request asks for:
	 *  home    = overview (KPI, list, detail, tracking, proof)
	 *  embed   = a the previous dashboard screen (bookings table, detail, edit, create,
	 *            settings) hosted inside the shell
		 *
	 * @return string
	 */
	public static function view_mode() {
		$has = function ( $key ) {
			return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification
		};
		foreach ( array( 'view', 'edit', 'tab', 'mode', 'q' ) as $key ) {
			if ( $has( $key ) ) {
				return 'embed';
			}
		}
		return 'home';
	}

	/**
	 * Which the previous dashboard screen an embed request shows: create | config | other.
	 *
	 * @return string
	 */
	public static function embed_screen() {
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $tab, array( 'create', 'config' ), true ) ? $tab : 'other';
	}

	/**
	 * Current page URL with extra query args (the shell works on whichever
	 * page carries the shortcode: the dashboard page).
	 *
	 * @param array<string,string> $args Query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$base = get_permalink();
		if ( ! $base ) {
			$base = self::dashboard_page_url();
		}
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * URL of the dashboard page (slug dashboard), or the fallback path.
	 *
	 * @return string
	 */
	public static function dashboard_page_url() {
		$page = get_page_by_path( 'dashboard' );
		if ( ! $page ) {
			return home_url( '/dashboard/' );
		}
		// WPML: link to the translation for the current language (slug stays dashboard).
		$lang = EasyLugg_Booking_Plugin::current_lang();
		$id   = '' !== $lang ? apply_filters( 'wpml_object_id', $page->ID, 'page', true, $lang ) : $page->ID;
		return get_permalink( $id ? $id : $page->ID );
	}

	/**
	 * All dashboard text, loaded once per language from texts.php.
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
	 * No-cache + noindex for the dashboard page only.
	 * Mirrors EasyLugg_Dashboard::send_headers().
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( ! self::is_app_request() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: same-origin' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'EasyLugg Dashboard' );
	}

	/**
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ) {
		if ( self::is_app_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Enqueue dashboard assets, only on the dashboard page.
	 * The customer booking scripts (booking.js, booking-storage.js,
	 * booking-tracking.js) and the the previous dashboard assets are never loaded here.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_app_request() ) {
			return;
		}
		$root = plugin_dir_path( dirname( dirname( __FILE__ ) ) );
		$css  = $root . 'assets/dashboard-app/dashboard-app.css';
		$js   = $root . 'assets/dashboard-app/dashboard-app.js';
		wp_enqueue_style( 'easylugg-app-style', plugins_url( 'assets/dashboard-app/dashboard-app.css', dirname( dirname( __FILE__ ) ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $css ) );
		$mode = self::view_mode();
		if ( 'home' === $mode ) {
			// The overview does not use the previous dashboard's assets (its own enqueue ran
			// first because the page carries [easylugg_dashboard]).
			wp_dequeue_style( 'easylugg-dashboard' );
			wp_dequeue_script( 'easylugg-dashboard' );
		} elseif ( ! wp_script_is( 'easylugg-dashboard', 'enqueued' ) ) {
			// the previous dashboard screens need the previous dashboard's own assets (same handles,
			// localized object and nonce) when its enqueue did not already run.
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
		}
		if ( 'home' !== $mode ) {
			$ejs = $root . 'assets/dashboard-app/dashboard-app-embed.js';
			wp_enqueue_script( 'easylugg-app-embed', plugins_url( 'assets/dashboard-app/dashboard-app-embed.js', dirname( dirname( __FILE__ ) ) ), array( 'easylugg-dashboard' ), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $ejs ), true );
			wp_localize_script(
				'easylugg-app-embed',
				'EasyLuggAppEmbed',
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
		wp_enqueue_script( 'easylugg-app', plugins_url( 'assets/dashboard-app/dashboard-app.js', dirname( dirname( __FILE__ ) ) ), array(), EasyLugg_Booking_Plugin::VERSION . '.' . filemtime( $js ), true );
		$lang     = EasyLugg_Booking_Plugin::current_lang();
		$ajax_url = admin_url( 'admin-ajax.php' );
		if ( '' !== $lang ) {
			$ajax_url = add_query_arg( 'lang', $lang, $ajax_url );
		}
		wp_localize_script(
			'easylugg-app',
			'EasyLuggApp',
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
		// Kill switch off: the old dashboard serves the page unchanged.
		if ( ! self::is_enabled() ) {
			return EasyLugg_Dashboard::render_shortcode();
		}
		// Permission is decided BEFORE any DB query (same order as the previous dashboard).
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
		ob_start();
		// elapp-app = app shell inside the theme page (site header/footer stay).
		echo '<div class="elapp elapp-app" id="elapp">';
		self::render_sidebar( $mode );
		echo '<div class="elapp-body">';
		self::render_header( $mode );
		echo '<main class="elapp-main">';
		if ( 'embed' === $mode ) {
			// the previous dashboard screens (bookings table, detail, edit, create, settings),
			// reusing the previous dashboard's own rendering and save handlers unchanged.
			echo '<div class="elapp-embed" data-screen="' . esc_attr( self::embed_screen() ) . '">' . EasyLugg_Dashboard::render_shortcode() . '</div>';
		} else {
			// Regions filled by dashboard-app.js from the AJAX endpoints.
			echo '<div class="elapp-kpi" id="elapp-kpi" aria-live="polite"></div>';
			echo '<div class="elapp-work">';
			echo '<div class="elapp-list" id="elapp-list"></div>';
			echo '<div class="elapp-detail" id="elapp-detail">' . EasyLugg_App_Render::detail_empty() . '</div>';
			echo '<div class="elapp-tracking" id="elapp-tracking"></div>';
			echo '</div>';
			echo '<div class="elapp-proof" id="elapp-proof"></div>';
		}
		echo '</main>';
		echo '</div>';
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
				'redirect' => self::dashboard_page_url(),
			)
		);
		return '<div class="elapp elapp-gate"><h2>' . esc_html( self::t( 'gate.login_title' ) ) . '</h2><p>' . esc_html( self::t( 'gate.login_text' ) ) . '</p>' . $form . '</div>';
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
		return '<div class="elapp elapp-gate"><h2>403</h2><p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( wp_logout_url( self::dashboard_page_url() ) ) . '">' . esc_html( self::t( 'gate.logout' ) ) . '</a></p></div>';
	}

	/**
	 * Fixed navy sidebar: logo (text placeholder until the owner sends
	 * the logo file) + left menu. Operations, Customers, Partners,
	 * Reports are NOT rendered in Phase 1 (no data/process yet, SRS §9).
	 * Settings links to the the previous dashboard Settings tab and is Admin-only.
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
		<aside class="elapp-side">
			<div class="elapp-logo"><?php echo esc_html( self::t( 'header.logo' ) ); ?></div>
			<nav class="elapp-nav" aria-label="<?php echo esc_attr( self::t( 'nav.aria' ) ); ?>">
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
	 * only, no function yet — search/bell count), account
	 * chip with avatar initials, display name and role.
	 *
	 * @return void
	 */
	private static function render_header( $mode = 'home' ) {
		$user = wp_get_current_user();
		$role = current_user_can( 'easylugg_manage_config' ) ? self::t( 'header.role_admin' ) : self::t( 'header.role_staff' );
		?>
		<header class="elapp-head">
			<div class="elapp-title">
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
				<div class="elapp-search"></div>
			<?php elseif ( 'home' === $mode ) : ?>
				<div class="elapp-search">
					<input type="search" id="elapp-search" placeholder="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>">
				</div>
			<?php else : ?>
				<form class="elapp-search" method="get" action="<?php echo esc_url( self::page_url() ); ?>">
					<?php
					if ( isset( $_GET['page_id'] ) && is_scalar( $_GET['page_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
						echo '<input type="hidden" name="page_id" value="' . esc_attr( absint( $_GET['page_id'] ) ) . '">';
					}
					?>
					<input type="search" name="q" placeholder="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( self::t( 'header.search_placeholder' ) ); ?>">
				</form>
			<?php endif; ?>
			<button type="button" class="elapp-bell" aria-label="<?php echo esc_attr( self::t( 'header.bell_label' ) ); ?>">&#128276;</button>
			<div class="elapp-acct">
				<span class="elapp-avatar" aria-hidden="true"><?php echo esc_html( self::initials( $user->display_name ) ); ?></span>
				<span class="elapp-acct-text">
					<span class="elapp-acct-name"><?php echo esc_html( $user->display_name ); ?></span>
					<span class="elapp-acct-role"><?php echo esc_html( $role ); ?></span>
				</span>
			</div>
		</header>
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
