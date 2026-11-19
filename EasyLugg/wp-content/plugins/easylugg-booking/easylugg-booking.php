<?php
/**
 * Plugin Name: EasyLugg Delivery Booking
 * Description: Adds a configurable, multi-step luggage delivery booking flow.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: EasyLugg
 * Text Domain: easylugg-booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Storage bookings live in their own module (includes/storage.php).
require_once plugin_dir_path( __FILE__ ) . 'includes/storage.php';

final class EasyLugg_Booking_Plugin {
	const VERSION       = '1.0.1';
	const PRICES_OPTION = 'easylugg_booking_prices';
	const DB_VERSION    = '1';
	const DB_OPTION     = 'easylugg_booking_db_version';
	const NONCE_ACTION  = 'easylugg_booking_submit';
	const SHOW_TRUST_BADGES = false; // 'Live GPS tracking' + 'Tamper-proof Zip' — hidden until the team activates them.

	/**
	 * @var EasyLugg_Booking_Plugin|null
	 */
	private static $instance;

	/**
	 * @var bool
	 */
	private $booking_output_added = false;

	/**
	 * @return EasyLugg_Booking_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade_database' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'bookingPlugin', array( $this, 'render_shortcode' ) );
		add_shortcode( 'easylugg_booking', array( $this, 'render_shortcode' ) );
		add_shortcode( 'easylugg_tracking', array( $this, 'render_tracking_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'disable_booking_page_cache' ), 0 );
		add_action( 'wp_ajax_easylugg_booking_submit', array( $this, 'submit_booking' ) );
		add_action( 'wp_ajax_nopriv_easylugg_booking_submit', array( $this, 'submit_booking' ) );
		add_action( 'wp_ajax_easylugg_booking_lookup', array( $this, 'ajax_lookup_booking' ) );
		add_action( 'wp_ajax_nopriv_easylugg_booking_lookup', array( $this, 'ajax_lookup_booking' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_easylugg_booking_update', array( $this, 'update_booking_status' ) );
		add_action( 'admin_post_easylugg_booking_download_photo', array( $this, 'download_booking_photo' ) );
	}

	/**
	 * @return void
	 */
	public static function activate() {
		self::create_database_table();
		add_option( self::PRICES_OPTION, self::default_prices() );
		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * @return string
	 */
	private static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'easylugg_bookings';
	}

	/**
	 * @return void
	 */
	private static function create_database_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			booking_code varchar(24) NOT NULL,
			request_key char(36) NOT NULL,
			tracking_token_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			service_date date NOT NULL,
			corridor varchar(2) NOT NULL,
			status varchar(20) NOT NULL,
			payment_method varchar(20) NOT NULL DEFAULT '',
			payment_status varchar(20) NOT NULL DEFAULT 'demo',
			price_total bigint unsigned NOT NULL DEFAULT 0,
			customer_email varchar(190) NOT NULL,
			customer_name varchar(190) NOT NULL,
			booking_data longtext NOT NULL,
			photo_path text NOT NULL,
			PRIMARY KEY  (booking_code),
			UNIQUE KEY request_key (request_key),
			KEY service_date (service_date),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Load the plugin text domain so __() in texts.php returns the active
	 * language (WPML or .mo files under languages/). Without this, WordPress
	 * never loads the domain and every string stays English.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'easylugg-booking', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * @return void
	 */
	public function maybe_upgrade_database() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::create_database_table();
			update_option( self::DB_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * @return array<string, int>
	 */
	private static function default_prices() {
		return array(
			'cabin'    => 150000,
			'medium'   => 180000,
			'oversize' => 250000,
			// Overnight storage, VND per bag per night. The first night is
			// free (see storage_charge()), so this only applies from the
			// second night onward.
			'storage'  => 100000,
		);
	}

	/**
	 * Country calling codes offered for WhatsApp / Zalo (code => country name).
	 *
	 * @return array<string, string>
	 */
	/**
	 * Airport time slots, 30-minute steps, morning + evening frames (MM §5).
	 *
	 * @return array<int, string>
	 */
	private static function airport_slots() {
		return array( '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '19:00', '19:30', '20:00' );
	}

	private static function country_codes() {
		return array(
			'84'  => 'Vietnam',
			'82'  => 'South Korea',
			'1'   => 'USA / Canada',
			'44'  => 'United Kingdom',
			'61'  => 'Australia',
			'81'  => 'Japan',
			'65'  => 'Singapore',
			'86'  => 'China',
			'886' => 'Taiwan',
			'852' => 'Hong Kong',
			'60'  => 'Malaysia',
			'66'  => 'Thailand',
			'33'  => 'France',
			'49'  => 'Germany',
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function prices() {
		$defaults = self::default_prices();
		$saved    = get_option( self::PRICES_OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();

		foreach ( $defaults as $size => $default ) {
			if ( isset( $saved[ $size ] ) && is_numeric( $saved[ $size ] ) ) {
				$defaults[ $size ] = max( 0, (int) $saved[ $size ] );
			}
		}

		return $defaults;
	}

	/**
	 * All user-visible text, loaded once from texts.php (grouped by page/section).
	 * Each value is already translated via __() inside texts.php.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function texts() {
		static $texts = null;
		if ( null === $texts ) {
			$texts = include plugin_dir_path( __FILE__ ) . 'texts.php';
			if ( ! is_array( $texts ) ) {
				$texts = array();
			}
		}
		return $texts;
	}

	/**
	 * One string by dotted address, e.g. $this->text( 'step1.continue' ).
	 * Falls back to the address itself when the key is missing, so a typo
	 * shows a visible key instead of silently rendering nothing.
	 *
	 * @param string $key Dotted address (section.key).
	 * @return string
	 */
	private function text( $key ) {
		$texts = $this->texts();
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
	 * Inline an SVG icon from assets/icons/. Returns '' when the file is missing.
	 * Icons are Material Symbols glyphs (fill="currentColor") so CSS color controls them.
	 *
	 * @param string $name Icon slug (file name without .svg).
	 * @return string
	 */
	private function icon( $name ) {
		$name = sanitize_file_name( $name );
		$path = plugin_dir_path( __FILE__ ) . 'assets/icons/' . $name . '.svg';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$svg = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$svg = preg_replace( '/<\?xml[^>]*\?>\s*/', '', $svg );
		$svg = preg_replace( '/<svg\b/', '<svg class="el-icon" aria-hidden="true" focusable="false"', $svg, 1 );
		return $svg;
	}

	/**
	 * Mock hotel directory for the booking form suggestions.
	 *
	 * The entries mirror the Google Places API Place shape. Their IDs are
	 * placeholders, not real Google place IDs; replace this directory when
	 * live Places search is integrated.
	 *
	 * @return array<int, array{displayName: array{text: string, languageCode: string}, formattedAddress: string, location: array{latitude: float, longitude: float}, id: string, types: string[]}>
	 */
	private function mock_hotels() {
		return array(
			// Da Nang.
			array( 'displayName' => array( 'text' => 'Novotel Danang Premier Han River', 'languageCode' => 'en' ), 'formattedAddress' => '36 Bach Dang, Hai Chau District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.068, 'longitude' => 108.225 ), 'id' => 'mock-novotel-danang-premier-han-river', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'InterContinental Danang Sun Peninsula Resort', 'languageCode' => 'en' ), 'formattedAddress' => 'Bai Bac, Son Tra Peninsula, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.125, 'longitude' => 108.286 ), 'id' => 'mock-intercontinental-danang-sun-peninsula', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Furama Resort Danang', 'languageCode' => 'en' ), 'formattedAddress' => '103-105 Vo Nguyen Giap, Ngu Hanh Son District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.041, 'longitude' => 108.247 ), 'id' => 'mock-furama-resort-danang', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Hilton Da Nang', 'languageCode' => 'en' ), 'formattedAddress' => '50 Bach Dang, Hai Chau District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.070, 'longitude' => 108.225 ), 'id' => 'mock-hilton-da-nang', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Vinpearl Resort & Spa Da Nang', 'languageCode' => 'en' ), 'formattedAddress' => '23 Truong Sa, Ngu Hanh Son District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.027, 'longitude' => 108.248 ), 'id' => 'mock-vinpearl-resort-spa-da-nang', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Pullman Danang Beach Resort', 'languageCode' => 'en' ), 'formattedAddress' => '101 Vo Nguyen Giap, Ngu Hanh Son District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.043, 'longitude' => 108.247 ), 'id' => 'mock-pullman-danang-beach-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Hyatt Regency Danang Resort and Spa', 'languageCode' => 'en' ), 'formattedAddress' => '5 Truong Sa, Ngu Hanh Son District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.039, 'longitude' => 108.246 ), 'id' => 'mock-hyatt-regency-danang', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Sheraton Grand Danang Resort', 'languageCode' => 'en' ), 'formattedAddress' => '35 Truong Sa, Ngu Hanh Son District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.033, 'longitude' => 108.248 ), 'id' => 'mock-sheraton-grand-danang-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Muong Thanh Luxury Da Nang', 'languageCode' => 'en' ), 'formattedAddress' => '115 Nguyen Van Linh, Hai Chau District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.068, 'longitude' => 108.214 ), 'id' => 'mock-muong-thanh-luxury-da-nang', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'A La Carte Da Nang Beach', 'languageCode' => 'en' ), 'formattedAddress' => '200 Vo Nguyen Giap, Son Tra District, Da Nang, Vietnam', 'location' => array( 'latitude' => 16.053, 'longitude' => 108.246 ), 'id' => 'mock-a-la-carte-da-nang-beach', 'types' => array( 'lodging' ) ),
			// Hoi An.
			array( 'displayName' => array( 'text' => 'Four Seasons Resort The Nam Hai', 'languageCode' => 'en' ), 'formattedAddress' => 'Ha My Beach, Dien Duong, Dien Ban, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.906, 'longitude' => 108.383 ), 'id' => 'mock-four-seasons-resort-the-nam-hai', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Anantara Hoi An Resort', 'languageCode' => 'en' ), 'formattedAddress' => '1 Pham Hong Thai, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.878, 'longitude' => 108.326 ), 'id' => 'mock-anantara-hoi-an-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Victoria Hoi An Beach Resort & Spa', 'languageCode' => 'en' ), 'formattedAddress' => 'Cua Dai Beach, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.887, 'longitude' => 108.396 ), 'id' => 'mock-victoria-hoi-an-beach-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'La Siesta Hoi An Resort & Spa', 'languageCode' => 'en' ), 'formattedAddress' => '132 Hung Vuong, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.881, 'longitude' => 108.325 ), 'id' => 'mock-la-siesta-hoi-an-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Hoi An Silk Marina Resort & Spa', 'languageCode' => 'en' ), 'formattedAddress' => '74 Bach Dang, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.877, 'longitude' => 108.329 ), 'id' => 'mock-hoi-an-silk-marina-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Almanity Hoi An Wellness Resort', 'languageCode' => 'en' ), 'formattedAddress' => '326 Ly Thuong Kiet, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.883, 'longitude' => 108.324 ), 'id' => 'mock-almanity-hoi-an-wellness-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Vinpearl Resort & Golf Nam Hoi An', 'languageCode' => 'en' ), 'formattedAddress' => 'Binh Minh, Thang Binh, Quang Nam (Nam Hoi An), Vietnam', 'location' => array( 'latitude' => 15.801, 'longitude' => 108.462 ), 'id' => 'mock-vinpearl-resort-golf-nam-hoi-an', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Palm Garden Beach Resort & Spa', 'languageCode' => 'en' ), 'formattedAddress' => 'Lac Long Quan, Cua Dai, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.890, 'longitude' => 108.394 ), 'id' => 'mock-palm-garden-beach-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Boutique Hoi An Resort', 'languageCode' => 'en' ), 'formattedAddress' => '34 Cua Dai, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.888, 'longitude' => 108.386 ), 'id' => 'mock-boutique-hoi-an-resort', 'types' => array( 'lodging' ) ),
			array( 'displayName' => array( 'text' => 'Hotel Royal Hoi An - MGallery', 'languageCode' => 'en' ), 'formattedAddress' => '39 Dao Duy Tu, Hoi An, Vietnam', 'location' => array( 'latitude' => 15.878, 'longitude' => 108.326 ), 'id' => 'mock-hotel-royal-hoi-an-mgallery', 'types' => array( 'lodging' ) ),
		);
	}

	/**
	 * @param mixed $input Submitted option value.
	 * @return array<string, int>
	 */
	public function sanitize_prices( $input ) {
		if ( ! is_array( $input ) ) {
			add_settings_error(
				self::PRICES_OPTION,
				'invalid_prices',
				__( 'Pricing must include a whole-number amount for every price.', 'easylugg-booking' )
			);
			return $this->prices();
		}

		$clean          = array();
		$current_prices = $this->prices();

		foreach ( array( 'cabin', 'medium', 'oversize', 'storage' ) as $size ) {
			$value = isset( $input[ $size ] ) ? $input[ $size ] : '';
			if ( ! is_scalar( $value ) || '' === (string) $value || ! ctype_digit( (string) $value ) ) {
				add_settings_error(
					self::PRICES_OPTION,
					'invalid_' . $size,
					__( 'Enter a whole-number price of 0 or more for every price.', 'easylugg-booking' )
				);
				$clean[ $size ] = $current_prices[ $size ];
				continue;
			}

			$clean[ $size ] = min( 100000000, (int) $value );
		}

		return $clean;
	}

	/**
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'easylugg_booking_settings',
			self::PRICES_OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize_prices' ) )
		);
	}

	/**
	 * Keep anonymous booking nonces and private tracking links out of page caches.
	 *
	 * @return void
	 */
	public function disable_booking_page_cache() {
		if ( is_page( 'bookings' ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'EasyLugg booking form and tracking' );
			nocache_headers();
		}
	}

	/**
	 * @return void
	 */
	public function register_admin_pages() {
		add_menu_page(
			__( 'EasyLugg Bookings', 'easylugg-booking' ),
			__( 'EasyLugg Bookings', 'easylugg-booking' ),
			'manage_options',
			'easylugg-bookings',
			array( $this, 'render_admin_bookings' ),
			'dashicons-admin-site-alt3',
			56
		);
		add_submenu_page(
			'easylugg-bookings',
			__( 'Luggage Pricing', 'easylugg-booking' ),
			__( 'Luggage Pricing', 'easylugg-booking' ),
			'manage_options',
			'easylugg-booking-pricing',
			array( $this, 'render_admin_settings' )
		);
	}

	/**
	 * @return void
	 */
	public function render_admin_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$prices = $this->prices();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EasyLugg luggage pricing', 'easylugg-booking' ); ?></h1>
			<p><?php esc_html_e( 'Set the estimated prices in Vietnamese dong. Delivery prices are per bag; overnight storage is charged per bag for each night after the first free night.', 'easylugg-booking' ); ?></p>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'easylugg_booking_settings' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php
						$labels = array(
							'cabin'    => __( 'Cabin bag', 'easylugg-booking' ),
							'medium'   => __( 'Medium bag', 'easylugg-booking' ),
							'oversize' => __( 'Oversize bag', 'easylugg-booking' ),
							'storage'  => __( 'Overnight storage', 'easylugg-booking' ),
						);
						$units = array(
							'cabin'    => __( 'VND per bag', 'easylugg-booking' ),
							'medium'   => __( 'VND per bag', 'easylugg-booking' ),
							'oversize' => __( 'VND per bag', 'easylugg-booking' ),
							'storage'  => __( 'VND per bag per night', 'easylugg-booking' ),
						);
						foreach ( $labels as $size => $label ) :
							?>
							<tr>
								<th scope="row">
									<label for="easylugg-price-<?php echo esc_attr( $size ); ?>"><?php echo esc_html( $label ); ?></label>
								</th>
								<td>
									<input
										id="easylugg-price-<?php echo esc_attr( $size ); ?>"
										name="<?php echo esc_attr( self::PRICES_OPTION ); ?>[<?php echo esc_attr( $size ); ?>]"
										type="number"
										min="0"
										max="100000000"
										step="1000"
										value="<?php echo esc_attr( (string) $prices[ $size ] ); ?>"
										class="regular-text"
										required
									/>
									<span class="description"><?php echo esc_html( $units[ $size ] ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public function render_admin_bookings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$code = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
		if ( '' !== $code ) {
			$booking = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE booking_code = %s', $code )
			);
			$this->render_admin_booking_detail( $booking );
			return;
		}

		$bookings = $wpdb->get_results(
			'SELECT booking_code, created_at, service_date, corridor, status, payment_method, price_total, customer_name, customer_email
			FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT 100'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EasyLugg delivery bookings', 'easylugg-booking' ); ?></h1>
			<p><?php esc_html_e( 'Customer details are visible only to site administrators.', 'easylugg-booking' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Booking', 'easylugg-booking' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'easylugg-booking' ); ?></th>
						<th><?php esc_html_e( 'Route', 'easylugg-booking' ); ?></th>
						<th><?php esc_html_e( 'Service date', 'easylugg-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'easylugg-booking' ); ?></th>
						<th><?php esc_html_e( 'Estimate', 'easylugg-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $bookings ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No bookings yet.', 'easylugg-booking' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $bookings as $booking ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'easylugg-bookings', 'view' => $booking->booking_code ), admin_url( 'admin.php' ) ) ); ?>">
										<strong><?php echo esc_html( $booking->booking_code ); ?></strong>
									</a>
									<br><small><?php echo esc_html( get_date_from_gmt( $booking->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></small>
								</td>
								<td><?php echo esc_html( $booking->customer_name ); ?><br><small><?php echo esc_html( $booking->customer_email ); ?></small></td>
								<td><?php echo esc_html( $this->corridor_label( $booking->corridor ) ); ?></td>
								<td><?php echo esc_html( $booking->service_date ); ?></td>
								<td><?php echo esc_html( $this->status_label( $booking->status ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $booking->price_total ) ); ?> VND</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Labels for the booking detail rows, shared by the admin detail
	 * page and the public lookup page so both show the same wording.
	 * The pickup_time label is service-aware: it used to appear twice
	 * in this array (storage 'Pick-up time' overwritten by the delivery
	 * 'Airport pickup time'), so storage bookings were mislabelled.
	 *
	 * @param bool $is_storage Whether the booking is a storage booking.
	 * @return array<string, string>
	 */
	private function booking_detail_labels( $is_storage ) {
		return array(
			'corridor'              => __( 'Route', 'easylugg-booking' ),
			'service_date'          => __( 'Service date', 'easylugg-booking' ),
			'hub'                   => __( 'Storage hub', 'easylugg-booking' ),
			'dropoff_date'          => __( 'Drop-off date', 'easylugg-booking' ),
			'dropoff_time'          => __( 'Drop-off time', 'easylugg-booking' ),
			'pickup_date'           => __( 'Pick-up date', 'easylugg-booking' ),
			'pickup_time'           => $is_storage ? __( 'Pick-up time', 'easylugg-booking' ) : __( 'Airport pickup time', 'easylugg-booking' ),
			'storage_days'          => __( 'Storage days', 'easylugg-booking' ),
			'airport_delivery_time' => __( 'Airport delivery time', 'easylugg-booking' ),
			'flight_departure_time' => __( 'Flight departure time', 'easylugg-booking' ),
			'flight_number'         => __( 'Flight number', 'easylugg-booking' ),
			'delivery_day'          => __( 'Hotel delivery', 'easylugg-booking' ),
			'delivery_date'         => __( 'Delivery date', 'easylugg-booking' ),
			'storage_fee'           => __( 'Storage fee', 'easylugg-booking' ),
			'from_hotel'            => __( 'Departure hotel', 'easylugg-booking' ),
			'to_hotel'              => __( 'Destination hotel', 'easylugg-booking' ),
			'from_booking_code'     => __( 'Departure hotel booking code', 'easylugg-booking' ),
			'to_booking_code'       => __( 'Destination hotel booking code', 'easylugg-booking' ),
			'bags'                  => __( 'Luggage counts', 'easylugg-booking' ),
			'promotion_code'        => __( 'Promotion code', 'easylugg-booking' ),
			'note'                  => __( 'Customer note', 'easylugg-booking' ),
			'customer'              => __( 'Contact details', 'easylugg-booking' ),
			'payment_method'        => __( 'Demo payment method', 'easylugg-booking' ),
			'estimate'              => __( 'Estimated price', 'easylugg-booking' ),
			'terms_accepted_at'     => __( 'Terms accepted at', 'easylugg-booking' ),
		);
	}

	/**
	 * @param object|null $booking Booking row.
	 * @return void
	 */
	private function render_admin_booking_detail( $booking ) {
		if ( ! $booking ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Booking not found', 'easylugg-booking' ) . '</h1></div>';
			return;
		}

		$data   = json_decode( $booking->booking_data, true );
		$data   = is_array( $data ) ? $data : array();
		$labels = $this->booking_detail_labels( 'storage' === ( $data['service'] ?? '' ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $booking->booking_code ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=easylugg-bookings' ) ); ?>">&larr; <?php esc_html_e( 'All bookings', 'easylugg-booking' ); ?></a></p>
			<p><strong><?php esc_html_e( 'Status:', 'easylugg-booking' ); ?></strong> <?php echo esc_html( $this->status_label( $booking->status ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;">
				<input type="hidden" name="action" value="easylugg_booking_update">
				<input type="hidden" name="booking_code" value="<?php echo esc_attr( $booking->booking_code ); ?>">
				<?php wp_nonce_field( 'easylugg_booking_update_' . $booking->booking_code ); ?>
				<label for="easylugg-booking-status"><strong><?php esc_html_e( 'Update status', 'easylugg-booking' ); ?></strong></label>
				<select id="easylugg-booking-status" name="status">
					<?php foreach ( $this->status_labels() as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $booking->status, $status ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Save status', 'easylugg-booking' ), 'secondary', 'submit', false ); ?>
			</form>
			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<?php foreach ( $labels as $key => $label ) : ?>
						<?php if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) : ?>
							<tr>
								<th style="width: 220px;"><?php echo esc_html( $label ); ?></th>
								<td><?php echo wp_kses_post( $this->format_admin_value( $data[ $key ] ) ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( $booking->photo_path ) : ?>
						<tr>
							<th><?php esc_html_e( 'Luggage photo', 'easylugg-booking' ); ?></th>
							<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=easylugg_booking_download_photo&booking_code=' . rawurlencode( $booking->booking_code ) ), 'easylugg_booking_photo_' . $booking->booking_code ) ); ?>"><?php esc_html_e( 'Download photo', 'easylugg-booking' ); ?></a></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param mixed $value Admin value.
	 * @return string
	 */
	private function format_admin_value( $value ) {
		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $key => $entry ) {
				$label = is_string( $key ) ? ucwords( str_replace( '_', ' ', $key ) ) . ': ' : '';
					if ( is_array( $entry ) ) {
					// Contact channels: a list of {channel, value} pairs.
					$sub = array();
					foreach ( $entry as $channel ) {
						if ( is_array( $channel ) && isset( $channel['channel'], $channel['value'] ) ) {
							$sub[] = ucfirst( $channel['channel'] ) . ': ' . $channel['value'];
						}
					}
					if ( $sub ) {
						$items[] = esc_html( $label ) . implode( '<br>', array_map( 'esc_html', $sub ) );
						continue;
					}
					// Any other nested array (e.g. customer details):
					// format it the same way instead of dumping JSON.
					$items[] = esc_html( $label ) . $this->format_admin_value( $entry );
					continue;
				}
			$items[] = esc_html( $label . ( is_scalar( $entry ) ? (string) $entry : wp_json_encode( $entry ) ) );
			}

			return implode( '<br>', $items );
		}

		return esc_html( (string) $value );
	}

	/**
	 * @return void
	 */
	public function update_booking_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to update bookings.', 'easylugg-booking' ), '', array( 'response' => 403 ) );
		}

		$code   = isset( $_POST['booking_code'] ) ? sanitize_text_field( wp_unslash( $_POST['booking_code'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		check_admin_referer( 'easylugg_booking_update_' . $code );

		if ( ! $code || ! array_key_exists( $status, $this->status_labels() ) ) {
			wp_die( esc_html__( 'Invalid booking status.', 'easylugg-booking' ), '', array( 'response' => 400 ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			self::table_name(),
			array( 'status' => $status ),
			array( 'booking_code' => $code ),
			array( '%s' ),
			array( '%s' )
		);
		if ( false === $updated ) {
			wp_die( esc_html__( 'The booking status could not be saved.', 'easylugg-booking' ), '', array( 'response' => 500 ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'easylugg-bookings', 'view' => $code, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return void
	 */
	public function download_booking_photo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access booking photos.', 'easylugg-booking' ), '', array( 'response' => 403 ) );
		}

		$code = isset( $_GET['booking_code'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_code'] ) ) : '';
		check_admin_referer( 'easylugg_booking_photo_' . $code );

		global $wpdb;
		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT booking_code, photo_path FROM ' . self::table_name() . ' WHERE booking_code = %s', $code )
		);
		$private_dir = $this->private_photo_directory();
		$real_dir    = realpath( $private_dir );
		$real_file   = $booking && $booking->photo_path ? realpath( $booking->photo_path ) : false;

		if ( ! $real_dir || ! $real_file || 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) || ! is_file( $real_file ) ) {
			wp_die( esc_html__( 'The booking photo could not be found.', 'easylugg-booking' ), '', array( 'response' => 404 ) );
		}

		$image = getimagesize( $real_file );
		if ( ! $image || ! in_array( $image['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			wp_die( esc_html__( 'The stored booking photo is not a supported image.', 'easylugg-booking' ), '', array( 'response' => 415 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $image['mime'] );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $booking->booking_code . '-luggage' . ( 'image/png' === $image['mime'] ? '.png' : '.jpg' ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $real_file );
		exit;
	}

	/**
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_page( 'bookings' ) && ! $this->page_has_booking_shortcode() ) {
			return;
		}

		$style_path          = plugin_dir_path( __FILE__ ) . 'assets/booking.css';
		$script_path         = plugin_dir_path( __FILE__ ) . 'assets/booking.js';
		$storage_script_path = plugin_dir_path( __FILE__ ) . 'assets/booking-storage.js';
		// Font: the form inherits the theme/project font (no Google Fonts load).
		wp_enqueue_style(
			'easylugg-booking',
			plugins_url( 'assets/booking.css', __FILE__ ),
			array(),
			self::VERSION . '.' . filemtime( $style_path )
		);
		wp_enqueue_script(
			'easylugg-booking',
			plugins_url( 'assets/booking.js', __FILE__ ),
			array(),
			self::VERSION . '.' . filemtime( $script_path ),
			true
		);
		wp_enqueue_script(
			'easylugg-booking-storage',
			plugins_url( 'assets/booking-storage.js', __FILE__ ),
			array( 'easylugg-booking' ),
			self::VERSION . '.' . filemtime( $storage_script_path ),
			true
		);
		$tracking_script_path = plugin_dir_path( __FILE__ ) . 'assets/booking-tracking.js';
		wp_enqueue_script(
			'easylugg-booking-tracking',
			plugins_url( 'assets/booking-tracking.js', __FILE__ ),
			array( 'easylugg-booking' ),
			self::VERSION . '.' . filemtime( $tracking_script_path ),
			true
		);
		wp_localize_script(
			'easylugg-booking',
			'EasyLuggBooking',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'prices'  => $this->prices(),
				'hotels'  => $this->mock_hotels(),
				// All UI text (texts.php) so JS reads the same strings as PHP.
				'texts'   => $this->texts(),
				'icons' => array(
					'flight-land'    => $this->icon( 'flight-land' ),
					'flight-takeoff' => $this->icon( 'flight-takeoff' ),
					'location-on'    => $this->icon( 'location-on' ),
					'luggage'        => $this->icon( 'luggage' ),
					'shield'         => $this->icon( 'shield' ),
					'verified-user'  => $this->icon( 'verified-user' ),
					'check-circle'   => $this->icon( 'check-circle' ),
					'qr-code-2'      => $this->icon( 'qr-code-2' ),
					'payments'       => $this->icon( 'payments' ),
					'calendar-month' => $this->icon( 'calendar-month' ),
					'near-me'        => $this->icon( 'near-me' ),
					'content-copy'   => $this->icon( 'content-copy' ),
					'arrow-forward'  => $this->icon( 'arrow-forward' ),
					'chat'           => $this->icon( 'chat' ),
					'assignment'     => $this->icon( 'assignment' ),
					'edit'           => $this->icon( 'edit' ),
					'info'           => $this->icon( 'info' ),
					'schedule'       => $this->icon( 'schedule' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	private function page_has_booking_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();
		return $post instanceof WP_Post && (
			has_shortcode( $post->post_content, 'bookingPlugin' )
			|| has_shortcode( $post->post_content, 'easylugg_booking' )
			|| has_shortcode( $post->post_content, 'easylugg_tracking' )
		);
	}

	/**
	 * @param array<string, string> $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $attributes = array() ) {
		if ( $this->booking_output_added ) {
			return '';
		}

		$this->booking_output_added = true;
		return $this->render_booking_form();
	}

	/**
	 * @return string
	 */
	private function render_booking_form() {
		$tracking_code  = isset( $_GET['el_track'] ) ? sanitize_text_field( wp_unslash( $_GET['el_track'] ) ) : '';
		$tracking_token = isset( $_GET['el_token'] ) ? sanitize_text_field( wp_unslash( $_GET['el_token'] ) ) : '';
		if ( $tracking_code || $tracking_token ) {
			header( 'Referrer-Policy: no-referrer' );
			return $this->render_tracking_page( $tracking_code, $tracking_token );
		}

		ob_start();
		?>
		<section class="easylugg-booking" data-easylugg-booking-flow aria-labelledby="easylugg-booking-title">
			<div class="el-booking-heading">
				<p class="el-booking-eyebrow"><?php echo esc_html( $this->text( 'page.eyebrow' ) ); ?></p>
				<h2 id="easylugg-booking-title"><?php echo esc_html( $this->text( 'page.title' ) ); ?></h2>
				<p><?php echo esc_html( $this->text( 'page.subtitle' ) ); ?></p>
			</div>
			<div class="el-service-tabs" role="tablist" aria-label="<?php echo esc_attr( $this->text( 'service.legend' ) ); ?>">
				<button type="button" class="el-service-tab is-active" data-service-tab="delivery" role="tab" aria-selected="true"><?php echo esc_html( $this->text( 'service.delivery_title' ) ); ?></button>
				<button type="button" class="el-service-tab" data-service-tab="storage" role="tab" aria-selected="false"><?php echo esc_html( $this->text( 'service.storage_title' ) ); ?></button>
				<span class="el-service-tabs-indicator" aria-hidden="true"></span>
			</div>
			<noscript><p class="el-booking-message is-error"><?php echo esc_html( $this->text( 'page.noscript' ) ); ?></p></noscript>
			<div class="el-booking-layout">
				<form class="el-booking-form" enctype="multipart/form-data" novalidate>
					<input type="hidden" name="action" value="easylugg_booking_submit">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
					<input type="hidden" name="request_key" value="">
					<input type="hidden" name="service" value="delivery">
					<div class="el-booking-trap" aria-hidden="true">
						<label><?php echo esc_html( $this->text( 'page.trap_label' ) ); ?><input type="text" name="company_url" tabindex="-1" autocomplete="off"></label>
					</div>

					<fieldset class="el-route-picker" data-service-panel="delivery">
						<legend><?php echo esc_html( $this->text( 'route.legend' ) ); ?></legend>
						<div class="el-route-options">
							<label><input type="radio" name="corridor" value="AH" checked><span class="el-route-card"><span class="el-route-dot" aria-hidden="true"></span><span class="el-route-text"><span class="el-route-title"><strong><?php echo esc_html( $this->text( 'route.ah_title' ) ); ?></strong><span class="el-route-popular"><?php echo esc_html( $this->text( 'route.ah_popular' ) ); ?></span></span><span class="el-route-desc"><?php echo esc_html( $this->text( 'route.ah_desc' ) ); ?></span></span><?php echo $this->icon( 'flight-land' ); ?></span></label>
							<label><input type="radio" name="corridor" value="HA"><span class="el-route-card"><span class="el-route-dot" aria-hidden="true"></span><span class="el-route-text"><span class="el-route-title"><strong><?php echo esc_html( $this->text( 'route.ha_title' ) ); ?></strong></span><span class="el-route-desc"><?php echo esc_html( $this->text( 'route.ha_desc' ) ); ?></span></span><?php echo $this->icon( 'flight-takeoff' ); ?></span></label>
							<label><input type="radio" name="corridor" value="HH"><span class="el-route-card"><span class="el-route-dot" aria-hidden="true"></span><span class="el-route-text"><span class="el-route-title"><strong><?php echo esc_html( $this->text( 'route.hh_title' ) ); ?></strong></span><span class="el-route-desc"><?php echo esc_html( $this->text( 'route.hh_desc' ) ); ?></span></span><?php echo $this->icon( 'apartment' ); ?></span></label>
						</div>
					</fieldset>
					<div class="el-booking-progress" aria-label="<?php echo esc_attr( $this->text( 'progress.label' ) ); ?>">
						<div class="el-progress-step is-current" data-progress-step="1"><span class="el-progress-circle"><span class="el-progress-num">1</span><span class="el-progress-check" hidden><?php echo $this->icon( 'check' ); ?></span></span><span class="el-progress-text"><span class="el-progress-name"><?php echo esc_html( $this->text( 'progress.step1_name' ) ); ?></span><span class="el-progress-state"><?php echo esc_html( $this->text( 'progress.in_progress' ) ); ?></span></span></div>
						<div class="el-progress-connector" aria-hidden="true"></div>
						<div class="el-progress-step" data-progress-step="2"><span class="el-progress-circle"><span class="el-progress-num">2</span><span class="el-progress-check" hidden><?php echo $this->icon( 'check' ); ?></span></span><span class="el-progress-text"><span class="el-progress-name"><?php echo esc_html( $this->text( 'progress.step2_name' ) ); ?></span><span class="el-progress-state"><?php echo esc_html( $this->text( 'progress.upcoming' ) ); ?></span></span></div>
						<div class="el-progress-connector" aria-hidden="true"></div>
						<div class="el-progress-step" data-progress-step="3"><span class="el-progress-circle"><span class="el-progress-num">3</span><span class="el-progress-check" hidden><?php echo $this->icon( 'check' ); ?></span></span><span class="el-progress-text"><span class="el-progress-name"><?php echo esc_html( $this->text( 'progress.step3_name' ) ); ?></span><span class="el-progress-state"><?php echo esc_html( $this->text( 'progress.upcoming' ) ); ?></span></span></div>
					</div>

					<div class="el-booking-step" data-step="1">

						<div data-service-panel="delivery">
							<div class="el-route-fields" data-route-fields="AH">
								<fieldset class="el-pickup-fields">
									<legend><?php echo esc_html( $this->text( 'step1.pickup_legend' ) ); ?></legend>
									<div class="el-booking-grid">
										<div class="el-field el-field--span-12"><label><?php echo esc_html( $this->text( 'step1.departure_airport' ) ); ?></label><input type="text" value="DAD - T2 - Danang International Airport" readonly></div>
										<div class="el-field el-field--span-4"><label for="el-date-ah"><?php echo esc_html( $this->text( 'step1.service_date' ) ); ?> *</label><input id="el-date-ah" name="service_date" type="date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required><small><?php echo esc_html( $this->text( 'step1.service_date_hint' ) ); ?></small></div>
										<div class="el-field el-field--span-4"><label for="el-pickup-time"><?php echo esc_html( $this->text( 'step1.pickup_time' ) ); ?> *</label><select id="el-pickup-time" name="pickup_time" required><option value=""><?php echo esc_html( $this->text( 'step1.pickup_time_placeholder' ) ); ?></option><?php foreach ( self::airport_slots() as $time ) : ?><option value="<?php echo esc_attr( $time ); ?>"><?php echo esc_html( $time ); ?></option><?php endforeach; ?></select><small><?php echo esc_html( $this->text( 'step1.pickup_time_hint' ) ); ?></small></div>
										<div class="el-field el-field--span-4"><label for="el-flight-ah"><?php echo esc_html( $this->text( 'step1.flight_number' ) ); ?> *</label><input id="el-flight-ah" name="flight_number" type="text" maxlength="7" autocomplete="off" placeholder="VN123" required><small><?php echo esc_html( $this->text( 'step1.flight_hint' ) ); ?></small></div>
									</div>
								</fieldset>
								<fieldset class="el-delivery-fields">
									<legend><?php echo esc_html( $this->text( 'step1.delivery_legend' ) ); ?></legend>
									<?php $this->render_hotel_fields( 'to', false, array( 'name' => 'to_booking_code', 'id' => 'el-code-to', 'label' => $this->text( 'step1.hotel_code' ), 'hint' => $this->text( 'step1.hotel_code_hint' ) ), array( 'name' => 'delivery_date', 'id' => 'el-date-to', 'label' => $this->text( 'step1.delivery_date' ), 'hint' => $this->text( 'step1.delivery_date_hint' ) ) ); ?>
								</fieldset>
							</div>

							<div class="el-route-fields" data-route-fields="HA" hidden>
								<fieldset class="el-pickup-fields">
									<legend><?php echo esc_html( $this->text( 'step1.pickup_legend' ) ); ?></legend>
									<?php $this->render_hotel_fields( 'from', false ); ?>
									<div class="el-booking-grid">
										<div class="el-field el-field--span-4"><label for="el-date-ha"><?php echo esc_html( $this->text( 'step1.service_date' ) ); ?> *</label><input id="el-date-ha" name="service_date" type="date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required><small><?php echo esc_html( $this->text( 'step1.service_date_hint' ) ); ?></small></div>
										<div class="el-field el-field--span-8"><label for="el-code-from"><?php echo esc_html( $this->text( 'step1.hotel_code' ) ); ?></label><input id="el-code-from" name="from_booking_code" type="text" maxlength="80"><small><?php echo esc_html( $this->text( 'step1.hotel_code_hint' ) ); ?></small></div>
									</div>
								</fieldset>
								<fieldset class="el-delivery-fields">
									<legend><?php echo esc_html( $this->text( 'step1.delivery_legend' ) ); ?></legend>
									<div class="el-booking-grid">
										<div class="el-field el-field--span-12"><label><?php echo esc_html( $this->text( 'step1.destination_airport' ) ); ?></label><input type="text" value="DAD - T2 - Danang International Airport" readonly></div>
										<div class="el-field el-field--span-4"><label for="el-flight-ha"><?php echo esc_html( $this->text( 'step1.flight_number' ) ); ?> *</label><input id="el-flight-ha" name="flight_number" type="text" maxlength="7" autocomplete="off" placeholder="VN123" required></div>
										<div class="el-field el-field--span-4"><label for="el-departure-date"><?php echo esc_html( $this->text( 'step1.departure_date' ) ); ?> *</label><input id="el-departure-date" name="delivery_date" type="date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required><small><?php echo esc_html( $this->text( 'step1.departure_date_hint' ) ); ?></small></div>
										<div class="el-field el-field--span-4"><label for="el-airport-delivery-time"><?php echo esc_html( $this->text( 'step1.delivery_time' ) ); ?> *</label><select id="el-airport-delivery-time" name="airport_delivery_time" required><option value=""><?php echo esc_html( $this->text( 'step1.delivery_time_placeholder' ) ); ?></option><?php foreach ( self::airport_slots() as $time ) : ?><option value="<?php echo esc_attr( $time ); ?>"><?php echo esc_html( $time ); ?></option><?php endforeach; ?></select><small><?php echo esc_html( $this->text( 'step1.delivery_time_hint' ) ); ?></small></div>
									</div>
								</fieldset>
							</div>

							<div class="el-route-fields" data-route-fields="HH" hidden>
								<fieldset class="el-pickup-fields">
									<legend><?php echo esc_html( $this->text( 'step1.pickup_legend' ) ); ?></legend>
									<?php $this->render_hotel_fields( 'from', false ); ?>
									<div class="el-booking-grid">
										<div class="el-field el-field--span-4"><label for="el-date-hh"><?php echo esc_html( $this->text( 'step1.service_date' ) ); ?> *</label><input id="el-date-hh" name="service_date" type="date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required><small><?php echo esc_html( $this->text( 'step1.service_date_hint' ) ); ?></small></div>
										<div class="el-field el-field--span-8"><label for="el-code-from-hh"><?php echo esc_html( $this->text( 'step1.from_hotel_code_hh' ) ); ?></label><input id="el-code-from-hh" name="from_booking_code" type="text" maxlength="80"><small><?php echo esc_html( $this->text( 'step1.hotel_code_hint' ) ); ?></small></div>
									</div>
								</fieldset>
								<fieldset class="el-delivery-fields">
									<legend><?php echo esc_html( $this->text( 'step1.delivery_legend' ) ); ?></legend>
									<?php $this->render_hotel_fields( 'to', false, array( 'name' => 'to_booking_code', 'id' => 'el-code-to-hh', 'label' => $this->text( 'step1.to_hotel_code_hh' ), 'hint' => $this->text( 'step1.hotel_code_hint' ) ), array( 'name' => 'delivery_date', 'id' => 'el-date-to-hh', 'label' => $this->text( 'step1.delivery_date' ), 'hint' => $this->text( 'step1.delivery_date_hint' ) ) ); ?>
								</fieldset>
							</div>

							<fieldset class="el-bags" aria-label="<?php echo esc_attr( $this->text( 'step1.bags_title' ) ); ?>">
								<div class="el-bags-head"><?php echo $this->icon( 'luggage' ); ?><span class="el-bags-title"><?php echo esc_html( $this->text( 'step1.bags_title' ) ); ?></span><span class="el-bags-note"><?php echo esc_html( $this->text( 'step1.bags_note' ) ); ?></span></div>
								<div class="el-bag-counts">
									<?php
									// Stitch bag cards. Names/descriptions are Stitch copy (the weight
									// labels are Stitch mock values, not yet approved business data).
									// Prices come from the real price config, never from Stitch.
									$bag_prices = $this->prices();
									$bag_meta = array(
										'cabin'    => array(
											'name' => $this->text( 'step1.bag_cabin_name' ),
											'desc' => $this->text( 'step1.bag_cabin_desc' ),
											'icon' => 'backpack',
										),
										'medium'   => array(
											'name' => $this->text( 'step1.bag_medium_name' ),
											'desc' => $this->text( 'step1.bag_medium_desc' ),
											'icon' => 'luggage',
										),
										'oversize' => array(
											'name' => $this->text( 'step1.bag_oversize_name' ),
											'desc' => $this->text( 'step1.bag_oversize_desc' ),
											'icon' => 'sports-golf',
										),
									);
									foreach ( $bag_meta as $size => $meta ) :
										?>
										<div class="el-bag-counter">
											<div class="el-bag-head"><span class="el-bag-name"><?php echo esc_html( $meta['name'] ); ?></span><?php echo $this->icon( $meta['icon'] ); ?></div>
											<p class="el-bag-desc"><?php echo esc_html( $meta['desc'] ); ?></p>
											<div class="el-bag-price"><?php echo esc_html( number_format( (int) ( $bag_prices[ $size ] ?? 0 ) ) . ' VND / ' . $this->text( 'step1.bag_unit' ) ); ?></div>
											<div class="el-bag-stepper">
												<button type="button" class="el-bag-btn el-bag-minus" data-bag-step="<?php echo esc_attr( $size ); ?>|-1" aria-label="<?php echo esc_attr( sprintf( $this->text( 'step1.bag_decrease' ), $meta['name'] ) ); ?>"><?php echo $this->icon( 'remove' ); ?></button>
												<input id="el-bags-<?php echo esc_attr( $size ); ?>" name="bags[<?php echo esc_attr( $size ); ?>]" type="number" min="0" max="20" step="1" value="0" inputmode="numeric" aria-label="<?php echo esc_attr( $meta['name'] ); ?>">
												<button type="button" class="el-bag-btn el-bag-plus" data-bag-step="<?php echo esc_attr( $size ); ?>|1" aria-label="<?php echo esc_attr( sprintf( $this->text( 'step1.bag_increase' ), $meta['name'] ) ); ?>"><?php echo $this->icon( 'add' ); ?></button>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
								<p class="el-bag-price-hint" data-price-hint></p>
							</fieldset>
							<div class="el-booking-grid">
								<div class="el-field el-field--span-6"><label for="el-promotion"><?php echo esc_html( $this->text( 'step1.promo_label' ) ); ?></label><input id="el-promotion" name="promotion_code" type="text" maxlength="30" autocomplete="off"><small><?php echo esc_html( $this->text( 'step1.promo_hint' ) ); ?></small></div>
							</div>
							<div class="el-field"><label for="el-note"><?php echo esc_html( $this->text( 'step1.note_label' ) ); ?></label><textarea id="el-note" name="note" rows="3" maxlength="500"></textarea><small><?php echo esc_html( $this->text( 'step1.note_hint' ) ); ?></small></div>
							<div class="el-field el-photo-field"><label class="el-photo-dropzone" for="el-photo"><?php echo $this->icon( 'add-a-photo' ); ?><span class="el-photo-title"><?php echo esc_html( $this->text( 'step1.photo_title' ) ); ?> <span data-photo-required-label></span></span><span class="el-photo-sub"><?php echo esc_html( $this->text( 'step1.photo_sub' ) ); ?></span><span class="el-photo-formats"><?php echo esc_html( $this->text( 'step1.photo_formats' ) ); ?></span></label><input id="el-photo" name="luggage_photo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"><small data-photo-help><?php echo esc_html( $this->text( 'step1.photo_help_ah' ) ); ?></small></div>
							<div class="el-request-note" data-request-note hidden><?php echo esc_html( $this->text( 'step1.request_note' ) ); ?></div>
						</div>
						<div data-service-panel="storage" hidden>
							<?php EasyLugg_Storage::render_fields( $this->texts(), $this->prices() ); ?>
					</div>
						<p class="el-booking-error" data-step-error="1" role="alert" hidden></p>
						<div class="el-booking-actions"><span></span><button class="el-button el-button-primary" type="button" data-next><?php echo esc_html( $this->text( 'step1.continue' ) ); ?> <?php echo $this->icon( 'arrow-forward' ); ?></button></div>
						<p class="el-booking-assurance"><?php echo $this->icon( 'lock' ); ?><span><?php echo esc_html( $this->text( 'step1.assurance' ) ); ?></span></p>
					</div>

					<div class="el-booking-step" data-step="2" hidden>
						<h3><?php echo esc_html( $this->text( 'step2.title' ) ); ?></h3>
						<div class="el-booking-grid">
							<div class="el-field"><label for="el-customer-name"><?php echo esc_html( $this->text( 'step2.full_name' ) ); ?> *</label><input id="el-customer-name" name="full_name" type="text" maxlength="190" autocomplete="name" required><small><?php echo esc_html( $this->text( 'step2.full_name_hint' ) ); ?></small></div>
							<div class="el-field"><label for="el-customer-email"><?php echo esc_html( $this->text( 'step2.email' ) ); ?> *</label><input id="el-customer-email" name="email" type="email" maxlength="190" autocomplete="email" required><small><?php echo esc_html( $this->text( 'step2.email_hint' ) ); ?></small></div>
							<div class="el-field el-field--span-12">
								<span class="el-channel-label"><?php echo esc_html( $this->text( 'step2.contact_channel' ) ); ?> *</span>
								<?php $this->render_channel_block( 0, true ); ?>
								<?php $this->render_channel_block( 1, false ); ?>
							</div>
							<div class="el-field"><label for="el-passport"><?php echo esc_html( $this->text( 'step2.passport' ) ); ?></label><input id="el-passport" name="passport" type="text" maxlength="50" autocomplete="off"><small><?php echo esc_html( $this->text( 'step2.passport_hint' ) ); ?></small></div>
						</div>
						<div class="el-terms-card">
							<div class="el-terms-head"><?php echo $this->icon( 'verified-user' ); ?><h3><?php echo esc_html( $this->text( 'step2.terms_title' ) ); ?></h3></div>
							<label class="el-consent"><input name="terms_booking" type="checkbox" value="1" required><span class="el-consent-text"><span class="el-consent-title"><?php echo esc_html( $this->text( 'step2.terms_booking_title' ) ); ?> <span class="el-required">*</span></span><span class="el-consent-desc"><?php echo esc_html( $this->text( 'step2.terms_booking_desc' ) ); ?></span></span></label>
							<label class="el-consent"><input name="terms_service" type="checkbox" value="1" required><span class="el-consent-text"><span class="el-consent-title"><?php echo esc_html( $this->text( 'step2.terms_service_title' ) ); ?> <span class="el-required">*</span></span><span class="el-consent-desc"><?php echo esc_html( $this->text( 'step2.terms_service_desc' ) ); ?></span></span></label>
						</div>
						<p class="el-booking-error" data-step-error="2" role="alert" hidden></p>
						<div class="el-booking-actions"><button class="el-button el-button-secondary" type="button" data-back><?php echo $this->icon( 'arrow-back' ); ?> <?php echo esc_html( $this->text( 'step2.back' ) ); ?></button><button class="el-button el-button-primary" type="button" data-next><?php echo esc_html( $this->text( 'step2.next' ) ); ?> <?php echo $this->icon( 'arrow-forward' ); ?></button></div>
					</div>

					<div class="el-booking-step" data-step="3" hidden>
						<h3><?php echo esc_html( $this->text( 'step3.title' ) ); ?></h3>
						<div class="el-booking-review" data-review></div>
						<div class="el-payment-options" data-payment-options>
							<fieldset>
								<legend><?php echo esc_html( $this->text( 'step3.payment_legend' ) ); ?></legend>
								<?php
								$payment_methods = array(
									'visa'          => array( 'label' => $this->text( 'step3.pay_visa' ), 'icon' => 'credit-card', 'hint' => $this->text( 'step3.pay_visa_hint' ) ),
									'apple_pay'     => array( 'label' => $this->text( 'step3.pay_apple' ), 'icon' => 'phone-iphone', 'hint' => $this->text( 'step3.pay_apple_hint' ) ),
									'google_pay'    => array( 'label' => $this->text( 'step3.pay_google' ), 'icon' => 'account-balance-wallet', 'hint' => $this->text( 'step3.pay_google_hint' ) ),
									'bank_transfer' => array( 'label' => $this->text( 'step3.pay_bank' ), 'icon' => 'qr-code-2', 'hint' => $this->text( 'step3.pay_bank_hint' ) ),
									'cash'          => array( 'label' => $this->text( 'step3.pay_cash' ), 'icon' => 'payments', 'hint' => $this->text( 'step3.pay_cash_hint' ) ),
								);
								foreach ( $payment_methods as $method => $meta ) :
									?>
									<label class="el-payment-choice"><input type="radio" name="payment_method" value="<?php echo esc_attr( $method ); ?>" <?php checked( 'cash', $method ); ?>><span class="el-payment-icon"><?php echo $this->icon( $meta['icon'] ); ?></span><span class="el-payment-name"><?php echo esc_html( $meta['label'] ); ?></span><span class="el-payment-hint"><?php echo esc_html( $meta['hint'] ); ?></span></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="el-demo-notice"><?php echo esc_html( $this->text( 'step3.demo_notice' ) ); ?></p>
						</div>
						<div class="el-request-note" data-request-payment-note hidden><?php echo esc_html( $this->text( 'step3.request_payment_note' ) ); ?></div>
						<p class="el-booking-error" data-step-error="3" role="alert" hidden></p>
						<div class="el-booking-actions"><button class="el-button el-button-secondary" type="button" data-back><?php echo $this->icon( 'arrow-back' ); ?> <?php echo esc_html( $this->text( 'step3.back' ) ); ?></button><button class="el-button el-button-primary" type="submit" data-submit><?php echo $this->icon( 'lock' ); ?> <?php echo esc_html( $this->text( 'step3.submit' ) ); ?></button></div>
					</div>
				</form>
				<div class="el-photo-modal" data-photo-modal hidden role="dialog" aria-modal="true" aria-labelledby="el-photo-modal-title">
					<div class="el-photo-modal-card">
						<h3 id="el-photo-modal-title"><?php echo esc_html( $this->text( 'step1.photo_title' ) ); ?></h3>
						<p><?php echo esc_html( $this->text( 'step1.photo_reminder' ) ); ?></p>
						<button class="el-button el-button-primary" type="button" data-photo-modal-ok><?php echo esc_html( $this->text( 'step1.photo_reminder_ok' ) ); ?></button>
					</div>
				</div>
				<aside class="el-booking-summary" data-summary-panel aria-label="<?php echo esc_attr( $this->text( 'summary.title' ) ); ?>">
					<div class="el-summary-head"><h3><?php echo esc_html( $this->text( 'summary.title' ) ); ?></h3><span class="el-summary-step" data-summary-step><?php echo esc_html( sprintf( $this->text( 'progress.step_of' ), 1 ) ); ?></span></div>
					<div data-summary></div>
					<?php if ( self::SHOW_TRUST_BADGES ) : ?>
					<div class="el-trust-badges">
						<div class="el-trust-badge"><?php echo $this->icon( 'qr-code-scanner' ); ?><span><?php echo esc_html( $this->text( 'summary.trust_gps' ) ); ?></span></div>
						<div class="el-trust-badge"><?php echo $this->icon( 'lock-reset' ); ?><span><?php echo esc_html( $this->text( 'summary.trust_zip' ) ); ?></span></div>
					</div>
					<?php endif; ?>
				</aside>
			</div>
			<div class="el-booking-result" data-result aria-live="polite" hidden></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One contact-channel block: a channel picker + only the input that
	 * channel needs (country code + phone for WhatsApp/Zalo, an ID field
	 * for KakaoTalk/Messenger). Panes that do not match the picker are
	 * hidden AND disabled, so only the visible input is submitted.
	 *
	 * @param int  $index Block index (0 = primary, 1 = added).
	 * @param bool $is_primary Whether this is the always-visible first block.
	 * @return void
	 */
	private function render_channel_block( $index, $is_primary ) {
		$channels = array(
			'whatsapp'  => $this->text( 'step2.channel_whatsapp' ),
			'kakao'     => $this->text( 'step2.channel_kakao' ),
			'zalo'      => $this->text( 'step2.channel_zalo' ),
			'messenger' => $this->text( 'step2.channel_messenger' ),
		);
		?>
		<div class="el-channel-block" data-channel-block="<?php echo (int) $index; ?>" <?php echo $is_primary ? '' : 'hidden'; ?>>
			<div class="el-channel-row">
				<select name="channels[<?php echo (int) $index; ?>][channel]" data-channel-select aria-label="<?php echo esc_attr( $this->text( 'step2.contact_channel' ) ); ?>" <?php echo $is_primary ? '' : 'disabled'; ?>>
					<?php foreach ( $channels as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'whatsapp', $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="el-channel-inputs">
					<div class="el-channel-pane" data-channel-pane="whatsapp">
						<select name="channels[<?php echo (int) $index; ?>][country_code]" data-country-code aria-label="<?php echo esc_attr( $this->text( 'step2.country_code' ) ); ?>" <?php echo $is_primary ? '' : 'disabled'; ?>>
							<?php foreach ( self::country_codes() as $code => $country ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( '84', (string) $code ); ?>><?php echo esc_html( $country . ' (+' . $code . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<input name="channels[<?php echo (int) $index; ?>][phone]" type="tel" maxlength="30" autocomplete="tel" placeholder="<?php echo esc_attr( $this->text( 'step2.phone_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( $this->text( 'step2.phone_number' ) ); ?>" <?php echo $is_primary ? 'required' : 'disabled'; ?>>
					</div>
					<div class="el-channel-pane" data-channel-pane="kakao" hidden>
						<input name="channels[<?php echo (int) $index; ?>][contact_id]" type="text" maxlength="120" placeholder="<?php echo esc_attr( $this->text( 'step2.kakao_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( $this->text( 'step2.channel_kakao' ) ); ?>" disabled>
					</div>
					<div class="el-channel-pane" data-channel-pane="zalo" hidden>
						<select name="channels[<?php echo (int) $index; ?>][country_code]" data-country-code aria-label="<?php echo esc_attr( $this->text( 'step2.country_code' ) ); ?>" disabled>
							<?php foreach ( self::country_codes() as $code => $country ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( '84', (string) $code ); ?>><?php echo esc_html( $country . ' (+' . $code . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<input name="channels[<?php echo (int) $index; ?>][phone]" type="tel" maxlength="30" autocomplete="tel" placeholder="<?php echo esc_attr( $this->text( 'step2.phone_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( $this->text( 'step2.phone_number' ) ); ?>" disabled>
					</div>
					<div class="el-channel-pane" data-channel-pane="messenger" hidden>
						<input name="channels[<?php echo (int) $index; ?>][contact_id]" type="text" maxlength="120" placeholder="<?php echo esc_attr( $this->text( 'step2.messenger_placeholder' ) ); ?>" aria-label="<?php echo esc_attr( $this->text( 'step2.channel_messenger' ) ); ?>" disabled>
					</div>
				</div>
				<div class="el-channel-actions">
					<button class="el-channel-icon-btn el-channel-add-btn" type="button" data-add-channel aria-label="<?php echo esc_attr( $this->text( 'step2.add_channel' ) ); ?>">+</button>
					<button class="el-channel-icon-btn el-channel-remove-btn" type="button" data-remove-channel aria-label="<?php echo esc_attr( $this->text( 'step2.remove_channel' ) ); ?>" <?php echo $is_primary ? 'disabled' : ''; ?>>−</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Hotel fields as an inner group (no frame of its own). The group sits
	 * inside a Pick-up From or Delivery To frame; the frame legend tells
	 * the customer which side the hotel is on.
	 *
	 * @param string     $prefix Field prefix.
	 * @param bool       $include_reception Reception question.
	 * @param array|null $code_field Hotel booking code field (name, id, label), rendered under Hotel name.
	 * @param array|null $date_field Delivery date field (name, id, label, hint), rendered in the
	 *                               same row before the booking code field. Delivery To only.
	 * @return void
	 */
	private function render_hotel_fields( $prefix, $include_reception, $code_field = null, $date_field = null ) {
		?>
		<div class="el-hotel-fields">
			<div class="el-booking-grid">
				<div class="el-field el-field--span-12">
					<label for="el-<?php echo esc_attr( $prefix ); ?>-hotel-name"><?php echo esc_html( $this->text( 'hotel.name' ) ); ?> *</label>
					<div class="el-hotel-suggest">
						<input id="el-<?php echo esc_attr( $prefix ); ?>-hotel-name" name="<?php echo esc_attr( $prefix ); ?>_hotel_name" type="text" maxlength="190" autocomplete="off" required>
						<ul class="el-hotel-suggestions" role="listbox" hidden></ul>
					</div>
					<div class="el-hotel-selected" hidden>
						<strong data-hotel-selected-name></strong>
						<small data-hotel-selected-address></small>
					</div>
					<small data-hotel-hint><?php echo esc_html( $this->text( 'hotel.hint' ) ); ?></small>
				</div>
				<?php if ( $date_field ) : ?>
					<div class="el-field el-field--span-6"><label for="<?php echo esc_attr( $date_field['id'] ); ?>"><?php echo esc_html( $date_field['label'] ); ?> *</label><input id="<?php echo esc_attr( $date_field['id'] ); ?>" name="<?php echo esc_attr( $date_field['name'] ); ?>" type="date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required><small><?php echo esc_html( $date_field['hint'] ); ?></small></div>
				<?php endif; ?>
				<?php if ( $code_field ) : ?>
					<div class="el-field el-field-code el-field--span-6"><label for="<?php echo esc_attr( $code_field['id'] ); ?>"><?php echo esc_html( $code_field['label'] ); ?></label><input id="<?php echo esc_attr( $code_field['id'] ); ?>" name="<?php echo esc_attr( $code_field['name'] ); ?>" type="text" maxlength="80"><?php if ( isset( $code_field['hint'] ) ) : ?><small><?php echo esc_html( $code_field['hint'] ); ?></small><?php endif; ?></div>
				<?php endif; ?>
				<?php if ( $include_reception ) : ?>
					<div class="el-field"><label for="el-<?php echo esc_attr( $prefix ); ?>-reception"><?php echo esc_html( $this->text( 'hotel.reception_label' ) ); ?> *</label><select id="el-<?php echo esc_attr( $prefix ); ?>-reception" name="<?php echo esc_attr( $prefix ); ?>_reception" required><option value=""><?php echo esc_html( $this->text( 'hotel.reception_placeholder' ) ); ?></option><option value="yes"><?php echo esc_html( $this->text( 'hotel.reception_yes' ) ); ?></option><option value="no"><?php echo esc_html( $this->text( 'hotel.reception_no' ) ); ?></option></select></div>
				<?php endif; ?>
			</div>
			<input id="el-<?php echo esc_attr( $prefix ); ?>-hotel-address" name="<?php echo esc_attr( $prefix ); ?>_hotel_address" type="hidden">
			<input id="el-<?php echo esc_attr( $prefix ); ?>-hotel-lat" name="<?php echo esc_attr( $prefix ); ?>_hotel_lat" type="hidden">
			<input id="el-<?php echo esc_attr( $prefix ); ?>-hotel-lng" name="<?php echo esc_attr( $prefix ); ?>_hotel_lng" type="hidden">
		</div>
		<?php
	}

	/**
	 * Status timeline (confirmed → collected → in transit → delivered),
	 * shared by the token tracking page and the public lookup result.
	 * Sub-labels name the step number, the active stage, or "upcoming";
	 * no per-step timestamps exist (staff update statuses by hand).
	 *
	 * @param string $status Current booking status.
	 * @return string
	 */
	private function render_status_timeline( $status ) {
		$steps   = array( 'confirmed', 'collected', 'in_transit', 'delivered' );
		$current = array_search( $status, $steps, true );
		$labels  = array(
			'confirmed'  => $this->text( 'tracking.status_received' ),
			'collected'  => $this->text( 'tracking.status_collected' ),
			'in_transit' => $this->text( 'tracking.status_in_transit' ),
			'delivered'  => $this->text( 'tracking.status_delivered' ),
		);

		ob_start();
		?>
		<div class="el-track-journey">
			<div class="el-track-journey-head">
				<h4><?php echo esc_html( $this->text( 'tracking.journey_title' ) ); ?></h4>
				<?php if ( false !== $current ) : ?>
					<span class="el-track-stage-pill"><?php echo esc_html( sprintf( $this->text( 'tracking.timeline_stage' ), $current + 1 ) ); ?></span>
				<?php endif; ?>
			</div>
			<ol class="el-booking-timeline">
				<?php foreach ( $steps as $index => $step ) : ?>
					<?php
					$is_done = false !== $current && $index <= $current;
					$sub = $status === $step
						? $this->text( 'tracking.timeline_active' )
						: ( $is_done ? $this->text( 'tracking.timeline_completed' ) : $this->text( 'tracking.timeline_upcoming' ) );
					?>
					<li class="<?php echo $is_done ? 'is-complete' : ''; ?> <?php echo $status === $step ? 'is-current' : ''; ?>">
						<span aria-hidden="true"><?php echo $is_done ? '✓' : ( $index + 1 ); ?></span>
						<small class="el-timeline-step"><?php echo esc_html( sprintf( $this->text( 'tracking.timeline_step' ), $index + 1 ) ); ?></small>
						<strong><?php echo esc_html( $labels[ $step ] ); ?></strong>
						<small><?php echo esc_html( $sub ); ?></small>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Format a VND amount the way the booking form does (en-US grouping).
	 *
	 * @param mixed $amount Amount in VND.
	 * @return string
	 */
	private function format_vnd( $amount ) {
		return number_format( (int) $amount, 0, '.', ',' ) . ' VND';
	}

	/**
	 * Public booking lookup page: [easylugg_tracking]. A tracking link
	 * from a confirmation email (?el_track= + ?el_token=) still renders
	 * the token-gated tracking page; otherwise the lookup form is shown.
	 *
	 * @param array<string, string> $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_tracking_shortcode( $attributes = array() ) {
		$tracking_code  = isset( $_GET['el_track'] ) ? sanitize_text_field( wp_unslash( $_GET['el_track'] ) ) : '';
		$tracking_token = isset( $_GET['el_token'] ) ? sanitize_text_field( wp_unslash( $_GET['el_token'] ) ) : '';
		if ( $tracking_code || $tracking_token ) {
			header( 'Referrer-Policy: no-referrer' );
			return $this->render_tracking_page( $tracking_code, $tracking_token );
		}

		ob_start();
		?>
		<section class="easylugg-booking" data-easylugg-tracking aria-labelledby="el-tracking-title">
			<div class="el-booking-heading">
				<p class="el-booking-eyebrow"><?php echo esc_html( $this->text( 'tracking.eyebrow' ) ); ?></p>
				<h2 id="el-tracking-title"><?php echo esc_html( $this->text( 'tracking.lookup_title' ) ); ?></h2>
				<p><?php echo esc_html( $this->text( 'tracking.lookup_subtitle' ) ); ?></p>
			</div>
			<div class="el-track-search-card">
				<form class="el-tracking-form" data-tracking-form novalidate>
					<input type="hidden" name="action" value="easylugg_booking_lookup">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
					<div class="el-track-search-field">
						<span class="el-track-search-icon"><?php echo $this->icon( 'search' ); ?></span>
						<label class="el-track-search-label" for="el-tracking-code"><?php echo esc_html( $this->text( 'tracking.lookup_label' ) ); ?></label>
						<input id="el-tracking-code" name="booking_code" type="text" placeholder="<?php echo esc_attr( $this->text( 'tracking.lookup_placeholder' ) ); ?>" autocomplete="off" required maxlength="24">
						<button type="button" class="el-track-clear" data-tracking-clear hidden aria-label="<?php echo esc_attr__( 'Clear', 'easylugg-booking' ); ?>">&times;</button>
					</div>
					<button type="submit" class="el-button el-button-primary" data-tracking-submit><?php echo esc_html( $this->text( 'tracking.lookup_button' ) ); ?> <?php echo $this->icon( 'arrow-forward' ); ?></button>
				</form>
				<div class="el-track-search-meta">
					<p class="el-track-hint"><span class="el-track-hint-icon"><?php echo $this->icon( 'info' ); ?></span><?php echo esc_html( $this->text( 'tracking.lookup_hint' ) ); ?></p>
					<p class="el-track-showing" data-tracking-showing hidden><span class="el-track-dot" aria-hidden="true"></span><span data-tracking-showing-text></span></p>
				</div>
			</div>
			<p class="el-tracking-error" data-tracking-error hidden></p>
			<div class="el-tracking-result" data-tracking-result aria-live="polite">
				<div class="el-track-empty" data-tracking-empty>
					<span class="el-track-empty-icon"><?php echo $this->icon( 'luggage' ); ?></span>
					<h3><?php echo esc_html( $this->text( 'tracking.lookup_empty_title' ) ); ?></h3>
					<p><?php echo esc_html( $this->text( 'tracking.lookup_empty_text' ) ); ?></p>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX booking lookup: code in, full booking details out as HTML.
	 * Same guards as the booking submit: AJAX nonce + 8 lookups per IP
	 * per 10 minutes, so codes cannot be brute-forced at scale.
	 *
	 * @return void
	 */
	public function ajax_lookup_booking() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.session_expired' ) ), 403 );
		}

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$rate_key   = 'el_lookup_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$rate_count = (int) get_transient( $rate_key );
		if ( $rate_count >= 8 ) {
			wp_send_json_error( array( 'message' => $this->text( 'tracking.lookup_too_many' ) ), 429 );
		}
		set_transient( $rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS );

		$code = isset( $_POST['booking_code'] ) && is_scalar( $_POST['booking_code'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_POST['booking_code'] ) ) )
			: '';
		if ( '' === $code || strlen( $code ) > 24 ) {
			wp_send_json_error( array( 'message' => $this->text( 'tracking.lookup_not_found' ) ), 404 );
		}

		global $wpdb;
		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT booking_code, status, created_at, price_total, booking_data FROM ' . self::table_name() . ' WHERE booking_code = %s', $code )
		);
		if ( ! $booking ) {
			wp_send_json_error( array( 'message' => $this->text( 'tracking.lookup_not_found' ) ), 404 );
		}

		wp_send_json_success( array( 'html' => $this->render_lookup_details_html( $booking ) ) );
	}

	/**
	 * Booking Summary rows for the Track page: the same content as the
	 * booking form's sidebar summary (route, places, luggage with
	 * prices, seals, insurance, promo, note, contact), laid out in the
	 * Stitch two-column table. NOT the admin detail rows: no passport,
	 * no payment method, no delivery-day flag, no raw coordinates —
	 * hotels show name + address only, like the summary's place blocks.
	 *
	 * @param array<string, mixed> $data Decoded booking_data (corridor already label-ified, money already format_vnd'd).
	 * @param array<string, string> $labels Admin detail labels (reused for row labels).
	 * @param bool $is_storage Storage booking.
	 * @return array<int, array{0: string, 1: string, 2: bool}> Rows: label, HTML value, multi-line flag.
	 */
	private function tracking_summary_rows( $data, $labels, $is_storage ) {
		$rows = array();
		$add  = function ( $label, $value, $is_multi = false ) use ( &$rows ) {
			if ( '' === $value || null === $value ) {
				return;
			}
			$rows[] = array( $label, $value, $is_multi );
		};
		$hotel_value = function ( $hotel ) {
			$lines = array();
			if ( ! empty( $hotel['name'] ) ) {
				$lines[] = esc_html( $hotel['name'] );
			}
			if ( ! empty( $hotel['address'] ) ) {
				$lines[] = '<span class="el-track-row-sub">' . esc_html( $hotel['address'] ) . '</span>';
			}
			return implode( '<br>', $lines );
		};

		$add( $labels['corridor'], esc_html( $data['corridor'] ?? '' ) );
		if ( $is_storage ) {
			// Storage hub: name + address + hours, as the summary's place block.
			$add(
				$labels['hub'],
				implode(
					'<br>',
					array(
						esc_html( $this->text( 'storage.hub_name' ) ),
						'<span class="el-track-row-sub">' . esc_html( $this->text( 'storage.hub_address' ) ) . '</span>',
						'<span class="el-track-row-sub">' . esc_html( $this->text( 'storage.hub_hours' ) ) . '</span>',
					)
				),
				true
			);
			$add( $labels['dropoff_date'], esc_html( $data['dropoff_date'] ?? '' ) );
			$add( $labels['dropoff_time'], esc_html( $data['dropoff_time'] ?? '' ) );
			$add( $labels['pickup_date'], esc_html( $data['pickup_date'] ?? '' ) );
			$add( $labels['pickup_time'], esc_html( $data['pickup_time'] ?? '' ) );
			// Luggage: "N bags × M days" → price (the summary's storage luggage row).
			$bags = (int) ( $data['bags'] ?? 0 );
			$days = (int) ( $data['storage_days'] ?? 0 );
			if ( $bags > 0 ) {
				$add(
					sprintf( $this->text( 'storage.bags_days' ), $bags, $days ),
					esc_html( $data['estimate'] ?? '' )
				);
			}
		} else {
			$add( $labels['service_date'], esc_html( $data['service_date'] ?? '' ) );
			$add( $labels['pickup_time'], esc_html( $data['pickup_time'] ?? '' ) );
			$add( $labels['flight_number'], esc_html( $data['flight_number'] ?? '' ) );
			if ( ! empty( $data['from_hotel']['name'] ) ) {
				$add( $labels['from_hotel'], $hotel_value( $data['from_hotel'] ), true );
			}
			if ( ! empty( $data['to_hotel']['name'] ) ) {
				$add( $labels['to_hotel'], $hotel_value( $data['to_hotel'] ), true );
			}
			$add( $labels['delivery_date'], esc_html( $data['delivery_date'] ?? '' ) );
			$add( $labels['airport_delivery_time'], esc_html( $data['airport_delivery_time'] ?? '' ) );
			$add( $labels['from_booking_code'], esc_html( $data['from_booking_code'] ?? '' ) );
			$add( $labels['to_booking_code'], esc_html( $data['to_booking_code'] ?? '' ) );
			// Luggage: one row per size with count, priced like the summary.
			$prices = $this->prices();
			foreach ( array( 'cabin', 'medium', 'oversize' ) as $size ) {
				$count = (int) ( $data['bags'][ $size ] ?? 0 );
				if ( $count < 1 ) {
					continue;
				}
				$add(
					sprintf( $this->text( 'summary.bag_line' ), $count, $this->text( 'step1.bag_' . $size . '_name' ) ),
					$this->format_vnd( $count * (int) $prices[ $size ] )
				);
			}
			// Overnight storage charge, when the delivery is not same-day.
			$nights = $this->storage_nights( $data['service_date'] ?? '', $data['delivery_date'] ?? '' );
			if ( $nights > 0 && ! empty( $data['storage_fee'] ) ) {
				$add(
					1 === $nights ? $this->text( 'summary.storage_row_one' ) : sprintf( $this->text( 'summary.storage_row_many' ), $nights ),
					esc_html( $data['storage_fee'] )
				);
			}
		}
		// Tamper-evident seals + insurance, as in the summary (static texts).
		// The seals value renders as a soft pill, as in the Stitch screen.
		$add( $this->text( 'summary.seals' ), '<span class="el-track-value-pill">' . esc_html( $this->text( 'summary.seals_value' ) ) . '</span>' );
		$add( $this->text( 'summary.insurance' ), esc_html( $this->text( 'summary.insurance_value' ) ) );
		// Promotion code + note, when present.
		$add( $labels['promotion_code'], esc_html( $data['promotion_code'] ?? '' ) );
		$add( $labels['note'], esc_html( $data['note'] ?? '' ) );
		// Contact: name, email, channels — the summary's contact section.
		$customer = is_array( $data['customer'] ?? null ) ? $data['customer'] : array();
		$add( $this->text( 'summary.contact_name' ), esc_html( $customer['name'] ?? '' ) );
		$add( $this->text( 'summary.contact_email' ), esc_html( $customer['email'] ?? '' ) );
		foreach ( $customer['details']['contact_channels'] ?? array() as $channel ) {
			if ( ! is_array( $channel ) || empty( $channel['channel'] ) || empty( $channel['value'] ) ) {
				continue;
			}
			$add( $this->text( 'step2.channel_' . $channel['channel'] ), esc_html( $channel['value'] ) );
		}
		return $rows;
	}

	/**
	 * Full booking details for the public lookup result: the Booking
	 * Summary content (same rows as the form's sidebar summary), laid
	 * out in the Stitch two-column table with the Total price bar.
	 * The passport is not shown at all (the summary doesn't show it).
	 *
	 * @param object $booking Booking row.
	 * @return string
	 */
	private function render_lookup_details_html( $booking ) {
		$data       = json_decode( $booking->booking_data, true );
		$data       = is_array( $data ) ? $data : array();
		$is_storage = 'storage' === ( $data['service'] ?? '' );
		$corridor_code = $data['corridor'] ?? '';
		$labels     = $this->booking_detail_labels( $is_storage );
		unset( $labels['terms_accepted_at'] );

		// Guest-facing values: route name, grouped VND prices.
		if ( isset( $data['corridor'] ) ) {
			$data['corridor'] = $this->corridor_label( $data['corridor'] );
		}
		foreach ( array( 'estimate', 'storage_fee' ) as $money_key ) {
			if ( isset( $data[ $money_key ] ) && is_numeric( $data[ $money_key ] ) ) {
				$data[ $money_key ] = $this->format_vnd( $data[ $money_key ] );
			}
		}
		// The passport is not shown on Track (the booking summary doesn't
		// show it either), so no masking is needed here.

		$service_name = $is_storage ? $this->text( 'service.storage_title' ) : $this->text( 'service.delivery_title' );
		$is_request   = 'requested' === $booking->status;
		$is_cancelled = 'cancelled' === $booking->status;
		$steps        = array( 'confirmed', 'collected', 'in_transit', 'delivered' );
		$stage_index  = array_search( $booking->status, $steps, true );

		// Hero sub-line + route card: only real booking data. The Stitch
		// screens show an ETA, a driver and tamper-seal numbers, but the
		// plugin stores none of those, so they are left out rather than
		// invented.
		$hero_sub = $is_storage
			? $this->text( 'storage.hub_name' ) . ', ' . ( $data['dropoff_date'] ?? '' ) . ' → ' . ( $data['pickup_date'] ?? '' )
			: ( $data['corridor'] ?? '' ) . ', ' . ( $data['service_date'] ?? '' );
		$route_lines = array();
		if ( $is_storage ) {
			$route_lines[] = array( $labels['hub'], $this->text( 'storage.hub_name' ), false, 'hotel' );
			$route_lines[] = array( '', $this->text( 'storage.hub_address' ), true, '' );
		} else {
			$airport     = $this->text( 'tracking.airport_name' );
			$origin      = in_array( $corridor_code, array( 'HA', 'HH' ), true ) ? ( $data['from_hotel']['name'] ?? '' ) : $airport;
			$destination = in_array( $corridor_code, array( 'AH', 'HH' ), true ) ? ( $data['to_hotel']['name'] ?? '' ) : $airport;
			if ( '' !== $origin ) {
				$route_lines[] = array( $this->text( 'tracking.origin' ), $origin, false, $airport === $origin ? 'flight-takeoff' : 'hotel' );
				if ( $airport !== $origin && ! empty( $data['from_hotel']['address'] ) ) {
					$route_lines[] = array( '', $data['from_hotel']['address'], true, '' );
				}
			}
			if ( '' !== $destination ) {
				$route_lines[] = array( $this->text( 'tracking.destination' ), $destination, false, $airport === $destination ? 'flight-land' : 'hotel' );
				if ( $airport !== $destination && ! empty( $data['to_hotel']['address'] ) ) {
					$route_lines[] = array( '', $data['to_hotel']['address'], true, '' );
				}
			}
		}

		// Summary rows: the estimate is the total, shown as its own bar
		// below the two-column table (Stitch Executive Transit layout).
		$summary_rows = $this->tracking_summary_rows( $data, $labels, $is_storage );
		$total_price  = isset( $data['estimate'] ) && '' !== $data['estimate'] ? (string) $data['estimate'] : '';

		ob_start();
		?>
		<div class="el-tracking-details">
			<div class="el-track-hero">
				<div class="el-track-hero-main">
					<p class="el-track-hero-pill"><?php echo esc_html( $service_name ); ?></p>
					<h3><?php echo esc_html( $this->status_label( $booking->status ) ); ?> <span class="el-track-hero-dot" aria-hidden="true"></span></h3>
					<p class="el-track-hero-sub"><?php echo esc_html( $hero_sub ); ?></p>
					<p class="el-track-hero-code"><strong><?php echo esc_html( $this->text( 'tracking.booking_code' ) ); ?></strong> <?php echo esc_html( $booking->booking_code ); ?></p>
				</div>
				<?php if ( $route_lines ) : ?>
					<div class="el-track-hero-route">
						<?php foreach ( $route_lines as $line ) : ?>
							<p>
								<?php if ( '' !== $line[3] ) : ?><span class="el-track-hero-route-icon"><?php echo $this->icon( $line[3] ); ?></span><?php endif; ?>
								<span class="el-track-hero-route-text">
									<?php if ( '' !== $line[0] ) : ?><span class="el-track-hero-route-label"><?php echo esc_html( $line[0] ); ?></span><?php endif; ?>
									<?php if ( ! empty( $line[2] ) ) : ?><span class="el-track-hero-route-sub"><?php echo esc_html( $line[1] ); ?></span><?php else : ?><strong><?php echo esc_html( $line[1] ); ?></strong><?php endif; ?>
								</span>
							</p>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="el-track-card">
				<div class="el-track-card-head">
					<h4><?php echo esc_html( $this->text( 'tracking.journey_title' ) ); ?></h4>
					<?php if ( false !== $stage_index ) : ?>
						<span class="el-track-stage-pill"><?php echo esc_html( sprintf( $this->text( 'tracking.stage' ), $stage_index + 1, count( $steps ) ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $is_request ) : ?>
					<p class="el-request-note"><?php echo esc_html( $this->text( 'tracking.on_request' ) ); ?></p>
				<?php elseif ( $is_cancelled ) : ?>
					<p class="el-request-note"><?php echo esc_html( $this->text( 'tracking.cancelled' ) ); ?></p>
				<?php else : ?>
					<?php echo $this->render_status_timeline( $booking->status ); ?>
				<?php endif; ?>
			</div>
			<div class="el-track-card">
				<div class="el-track-card-head">
					<h4><?php echo esc_html( $this->text( 'tracking.details_title' ) ); ?></h4>
				</div>
				<table class="el-tracking-table">
					<tbody>
						<?php foreach ( $summary_rows as $row ) : ?>
							<tr<?php echo $row[2] ? ' class="el-track-row-multi"' : ''; ?>>
								<th><?php echo esc_html( $row[0] ); ?></th>
								<td><?php echo wp_kses_post( $row[1] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( '' !== $total_price ) : ?>
					<div class="el-track-total">
						<span><?php echo esc_html( $this->text( 'tracking.total_price' ) ); ?></span>
						<strong><?php echo esc_html( $total_price ); ?></strong>
					</div>
				<?php endif; ?>
				<p class="el-track-tax-note"><?php echo esc_html( $this->text( 'summary.tax_note' ) ); ?></p>
			</div>
			<div class="el-track-help">
				<span class="el-track-help-icon"><?php echo $this->icon( 'info' ); ?></span>
				<p><?php echo esc_html( $this->text( 'tracking.changes_note' ) ); ?></p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $code Booking code.
	 * @param string $token Tracking token.
	 * @return string
	 */
	private function render_tracking_page( $code, $token ) {
		global $wpdb;

		$booking = $code && $token ? $wpdb->get_row(
			$wpdb->prepare( 'SELECT booking_code, tracking_token_hash, status, created_at FROM ' . self::table_name() . ' WHERE booking_code = %s', $code )
		) : null;
		if ( ! $booking || ! hash_equals( $booking->tracking_token_hash, hash( 'sha256', $token ) ) ) {
			return '<section class="easylugg-booking" data-easylugg-booking-flow><div class="el-booking-result"><h2>' . esc_html( $this->text( 'tracking.not_found_title' ) ) . '</h2><p>' . esc_html( $this->text( 'tracking.not_found_text' ) ) . '</p></div></section>';
		}

		$is_request  = 'requested' === $booking->status;
		$is_cancelled = 'cancelled' === $booking->status;

		ob_start();
		?>
		<section class="easylugg-booking" data-easylugg-booking-flow aria-labelledby="el-track-title">
			<div class="el-booking-result">
				<p class="el-booking-eyebrow"><?php echo esc_html( $this->text( 'tracking.eyebrow' ) ); ?></p>
				<h2 id="el-track-title"><?php echo esc_html( $this->text( 'tracking.title' ) ); ?></h2>
				<p><strong><?php echo esc_html( $this->text( 'tracking.booking_code' ) ); ?></strong> <?php echo esc_html( $booking->booking_code ); ?></p>
				<p><strong><?php echo esc_html( $this->text( 'tracking.created' ) ); ?></strong> <?php echo esc_html( get_date_from_gmt( $booking->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
				<?php if ( $is_request ) : ?>
					<p class="el-request-note"><?php echo esc_html( $this->text( 'tracking.on_request' ) ); ?></p>
				<?php elseif ( $is_cancelled ) : ?>
					<p class="el-request-note"><?php echo esc_html( $this->text( 'tracking.cancelled' ) ); ?></p>
				<?php else : ?>
					<?php echo $this->render_status_timeline( $booking->status ); ?>
				<?php endif; ?>
				<p><?php echo esc_html( $this->text( 'tracking.changes_note' ) ); ?></p>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return void
	 */
	public function submit_booking() {
		$request    = $this->get_booking_request_context();
		$input      = wp_unslash( $_POST );
		$service    = $this->validate_booking_service( $input );
		$is_storage = 'storage' === ( $service['service'] ?? '' );
		$bags       = $is_storage
			? EasyLugg_Storage::validate_bags( $input, $this->texts() )
			: $this->validate_bag_counts( $input );
		$customer = $this->validate_customer_details( $input );

		$estimate       = $is_storage
			? EasyLugg_Storage::estimate( $bags['total'], $service['dropoff_date'], $service['pickup_date'], $this->prices() )
			: $this->calculate_estimate( $bags, $service['service_date'], isset( $service['delivery_date'] ) ? $service['delivery_date'] : $service['service_date'] );
		$payment_method = $this->validate_payment_method( $input, $service['on_request'] );
		if ( $is_storage ) {
			// No photo at booking for storage: drop-off is in person.
			$photo_path = '';
		} else {
			$photo_path = $this->upload_booking_photo( $service['corridor'] );
			if ( is_wp_error( $photo_path ) ) {
				wp_send_json_error( array( 'message' => $photo_path->get_error_message() ), 400 );
			}
		}

		$booking_data = $this->build_booking_data( $input, $service, $bags, $customer['details'], $payment_method, $estimate );
		$booking      = $this->persist_booking( $request, $service, $customer, $payment_method, $estimate, $booking_data, $photo_path );
		$this->send_booking_success( $booking, $request['token'], false, false, $is_storage );
	}

	/**
	 * Save a validated booking and handle duplicate submissions.
	 *
	 * @param array<string, string> $request Idempotency and tracking tokens.
	 * @param array<string, mixed>  $service Validated route and schedule.
	 * @param array<string, mixed>  $customer Validated customer details.
	 * @param string                $payment_method Selected payment method.
	 * @param int                   $estimate Calculated estimate.
	 * @param array<string, mixed>  $booking_data Booking details for storage.
	 * @param string                $photo_path Private luggage photo path.
	 * @return object
	 */
	private function persist_booking( $request, $service, $customer, $payment_method, $estimate, $booking_data, $photo_path ) {
		$booking_code = $this->generate_booking_code();
		$status       = $service['on_request'] ? 'requested' : 'confirmed';
		global $wpdb;
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'booking_code'        => $booking_code,
				'request_key'         => $request['request_key'],
				'tracking_token_hash' => hash( 'sha256', $request['token'] ),
				'created_at'          => current_time( 'mysql', true ),
				'service_date'        => $service['service_date'],
				'corridor'            => $service['corridor'],
				'status'              => $status,
				'payment_method'      => $payment_method,
				'payment_status'      => 'demo',
				'price_total'         => $estimate,
				'customer_email'      => $customer['email'],
				'customer_name'       => $customer['name'],
				'booking_data'        => wp_json_encode( $booking_data ),
				'photo_path'          => is_string( $photo_path ) ? $photo_path : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( is_string( $photo_path ) && '' !== $photo_path && file_exists( $photo_path ) ) {
				wp_delete_file( $photo_path );
			}
			$duplicate = $wpdb->get_row(
				$wpdb->prepare( 'SELECT booking_code, status, price_total, tracking_token_hash FROM ' . self::table_name() . ' WHERE request_key = %s', $request['request_key'] )
			);
			if ( $duplicate ) {
				$this->send_booking_success( $duplicate, $request['token'], true, false );
			}
			wp_send_json_error( array( 'message' => $this->text( 'validation.save_failed' ) ), 500 );
		}

		return (object) array(
			'booking_code'        => $booking_code,
			'status'              => $status,
			'price_total'         => $estimate,
			'tracking_token_hash' => hash( 'sha256', $request['token'] ),
		);
	}

	/**
	 * Validate the AJAX request and return its idempotency and tracking tokens.
	 *
	 * @return array{request_key: string, token: string}
	 */
	private function get_booking_request_context() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.session_expired' ) ), 403 );
		}

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$rate_key   = 'el_booking_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$rate_count = (int) get_transient( $rate_key );
		if ( $rate_count >= 8 ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.too_many_attempts' ) ), 429 );
		}
		set_transient( $rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS );

		if ( ! empty( $_POST['company_url'] ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.submit_failed' ) ), 400 );
		}

		$request_key = isset( $_POST['request_key'] ) && is_scalar( $_POST['request_key'] )
			? sanitize_text_field( wp_unslash( $_POST['request_key'] ) )
			: '';
		if ( ! wp_is_uuid( $request_key, 4 ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.refresh_retry' ) ), 400 );
		}

		$token = hash_hmac( 'sha256', $request_key, wp_salt( 'secure_auth' ) );
		global $wpdb;
		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT booking_code, status, price_total, tracking_token_hash FROM ' . self::table_name() . ' WHERE request_key = %s', $request_key )
		);
		if ( $existing ) {
			$this->send_booking_success( $existing, $token, true, false );
		}

		return array(
			'request_key' => $request_key,
			'token'       => $token,
		);
	}

	/**
	 * Validate the selected route and collect its service-specific details.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @return array<string, mixed>
	 */
	private function validate_booking_service( $input ) {
		// Storage bookings validate in their own module (includes/storage.php).
		if ( 'storage' === $this->input_key( $input, 'service' ) ) {
			return EasyLugg_Storage::validate( $input, $this->texts(), $this->prices() );
		}

		$corridor = $this->input_key( $input, 'corridor' );
		if ( ! in_array( $corridor, array( 'ah', 'ha', 'hh' ), true ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.route_required' ) ), 400 );
		}
		$corridor = strtoupper( $corridor );

		$service_date = $this->input_text( $input, 'service_date' );
		if ( ! $this->is_valid_service_date( $service_date ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.date_invalid' ) ), 400 );
		}

		$flight_number = strtoupper( $this->input_text( $input, 'flight_number' ) );
		if ( in_array( $corridor, array( 'AH', 'HA' ), true ) && ! preg_match( '/^[A-Z0-9-]{1,7}$/', $flight_number ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.flight_invalid' ) ), 400 );
		}

		$service = array(
			'corridor'     => $corridor,
			'service_date' => $service_date,
		);
		$on_request = false;
		if ( 'AH' === $corridor ) {
			$pickup_time = $this->input_text( $input, 'pickup_time' );
			if ( ! in_array( $pickup_time, self::airport_slots(), true ) ) {
				wp_send_json_error( array( 'message' => $this->text( 'validation.pickup_slot' ) ), 400 );
			}
			if ( $service_date === current_time( 'Y-m-d' ) && $pickup_time <= current_time( 'H:i' ) ) {
				wp_send_json_error( array( 'message' => $this->text( 'validation.pickup_past' ) ), 400 );
			}
			$service['flight_number']    = $flight_number;
			$service['pickup_time']      = $pickup_time;
			$service['from']             = array( 'name' => 'DAD - T2 - Danang International Airport' );
			$service['to_hotel']         = $this->validate_hotel( $input, 'to' );
			$service['to_booking_code']  = $this->optional_text( $input, 'to_booking_code', 80 );
			$service['delivery_date']    = $this->validate_delivery_date( $input, $service_date );
			$service['delivery_day']     = $service['delivery_date'] === $service_date ? 'same_day' : 'next_day';
			$on_request                  = 'no' === $service['to_hotel']['reception'];
		} elseif ( 'HA' === $corridor ) {
			$service['from_hotel']      = $this->validate_hotel( $input, 'from' );
			$service['from_booking_code'] = $this->optional_text( $input, 'from_booking_code', 80 );
			$service['flight_number']   = $flight_number;
			$delivery_time              = $this->input_text( $input, 'airport_delivery_time' );
			if ( ! in_array( $delivery_time, self::airport_slots(), true ) ) {
				wp_send_json_error( array( 'message' => $this->text( 'validation.delivery_time_required' ) ), 400 );
			}
			$service['airport_delivery_time'] = $delivery_time;
			$service['to'] = array( 'name' => 'DAD - T2 - Danang International Airport' );
			// The departure date IS the delivery date for Hotel to Airport:
			// luggage is delivered to the airport on the flight day, so the
			// same overnight-storage rules apply as for Delivery Date.
			$service['delivery_date'] = $this->validate_delivery_date( $input, $service_date );
			$on_request = 'no' === $service['from_hotel']['reception'];
		} else {
			$service['from_hotel']      = $this->validate_hotel( $input, 'from' );
			$service['to_hotel']        = $this->validate_hotel( $input, 'to' );
			$service['from_booking_code'] = $this->optional_text( $input, 'from_booking_code', 80 );
			$service['to_booking_code']   = $this->optional_text( $input, 'to_booking_code', 80 );
			$service['delivery_date']     = $this->validate_delivery_date( $input, $service_date );
			// Kept for stored bookings / admin display: the old same-day /
			// next-day choice is now derived from the delivery date.
			$service['delivery_day'] = $service['delivery_date'] === $service_date ? 'same_day' : 'next_day';
			$on_request = 'no' === $service['from_hotel']['reception'] || 'no' === $service['to_hotel']['reception'];
		}

		$service['on_request'] = $on_request;
		return $service;
	}

	/**
	 * Delivery date must be a real Y-m-d date on or after the service date.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param string               $service_date Validated service date (Y-m-d).
	 * @return string Validated delivery date (Y-m-d).
	 */
	private function validate_delivery_date( $input, $service_date ) {
		$delivery_date = $this->input_text( $input, 'delivery_date' );
		$date_object   = DateTime::createFromFormat( '!Y-m-d', $delivery_date, wp_timezone() );
		if ( ! $date_object || $date_object->format( 'Y-m-d' ) !== $delivery_date ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.delivery_date_required' ) ), 400 );
		}
		if ( $delivery_date < $service_date ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.delivery_date_invalid' ) ), 400 );
		}

		return $delivery_date;
	}

	/**
	 * Validate bag quantities and return the normalized counts.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @return array<string, int>
	 */
	private function validate_bag_counts( $input ) {
		$bags       = isset( $input['bags'] ) && is_array( $input['bags'] ) ? $input['bags'] : array();
		$bag_counts = array();
		$total_bags = 0;
		foreach ( array( 'cabin', 'medium', 'oversize' ) as $size ) {
			$value = isset( $bags[ $size ] ) && is_scalar( $bags[ $size ] ) ? (string) $bags[ $size ] : '';
			if ( ! ctype_digit( $value ) || (int) $value > 20 ) {
				wp_send_json_error( array( 'message' => $this->text( 'validation.bag_range' ) ), 400 );
			}
			$bag_counts[ $size ] = (int) $value;
			$total_bags         += (int) $value;
		}
		if ( $total_bags < 1 ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.bag_min' ) ), 400 );
		}

		return $bag_counts;
	}

	/**
	 * Validate contact details and return the customer record.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @return array{name: string, email: string, details: array<string, mixed>}
	 */
	private function validate_customer_details( $input ) {
		$customer_name  = $this->input_text( $input, 'full_name' );
		$customer_email = isset( $input['email'] ) && is_scalar( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		if ( '' === $customer_name || strlen( $customer_name ) > 190 || ! is_email( $customer_email ) || strlen( $customer_email ) > 190 ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.contact_invalid' ) ), 400 );
		}
		if ( empty( $input['terms_service'] ) || ! is_scalar( $input['terms_service'] ) || '1' !== $input['terms_service'] || empty( $input['terms_booking'] ) || ! is_scalar( $input['terms_booking'] ) || '1' !== $input['terms_booking'] ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.terms_required' ) ), 400 );
		}

		$customer = array(
			'full_name'        => $customer_name,
			'email'            => $customer_email,
			'contact_channels' => $this->validate_channels( $input ),
			'passport'         => $this->optional_text( $input, 'passport', 50 ),
		);

		return array(
			'name'    => $customer_name,
			'email'   => $customer_email,
			'details' => $customer,
		);
	}

	/**
	 * Validate the contact channels submitted from Step 2. At least one
	 * channel is required. WhatsApp/Zalo take a country code + phone
	 * number; KakaoTalk/Messenger take an ID. Empty blocks are ignored
	 * (the "add another contact" block may be left blank); a filled
	 * block with an invalid value is rejected.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return array<int, array<string, string>> Validated channels.
	 */
	private function validate_channels( $input ) {
		$raw      = isset( $input['channels'] ) && is_array( $input['channels'] ) ? $input['channels'] : array();
		$allowed  = array( 'whatsapp', 'kakao', 'zalo', 'messenger' );
		$channels = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$type = isset( $entry['channel'] ) ? sanitize_key( $entry['channel'] ) : '';
			if ( ! in_array( $type, $allowed, true ) ) {
				continue;
			}
			if ( 'whatsapp' === $type || 'zalo' === $type ) {
				$code   = isset( $entry['country_code'] ) ? preg_replace( '/[^0-9]/', '', (string) $entry['country_code'] ) : '';
				$digits = isset( $entry['phone'] ) ? preg_replace( '/[^0-9]/', '', (string) $entry['phone'] ) : '';
				if ( '' === $code || '' === $digits ) {
					continue;
				}
				$value = '+' . $code . $digits;
				if ( ! preg_match( '/^\+?[0-9][0-9\s().-]{6,28}$/', $value ) ) {
					wp_send_json_error( array( 'message' => $this->text( 'validation.channel_phone' ) ), 400 );
				}
			} else {
				$value = isset( $entry['contact_id'] ) ? substr( sanitize_text_field( $entry['contact_id'] ), 0, 120 ) : '';
				if ( '' === $value ) {
					continue;
				}
			}
			$channels[] = array(
				'channel' => $type,
				'value'   => $value,
			);
			if ( count( $channels ) >= 2 ) {
				break;
			}
		}
		if ( count( $channels ) < 1 ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.channel_required' ) ), 400 );
		}

		return $channels;
	}

	/**
	 * Charged overnight-storage nights between the service and delivery
	 * dates. The first night is free: delivery 1 day after the service
	 * date costs nothing extra; each further night is charged.
	 *
	 * @param string $service_date Service date (Y-m-d).
	 * @param string $delivery_date Delivery date (Y-m-d).
	 * @return int Charged nights, 0 or more.
	 */
	private function storage_nights( $service_date, $delivery_date ) {
		$start = DateTime::createFromFormat( '!Y-m-d', $service_date, wp_timezone() );
		$end   = DateTime::createFromFormat( '!Y-m-d', $delivery_date, wp_timezone() );
		if ( ! $start || ! $end || $end < $start ) {
			return 0;
		}
		$days = (int) $start->diff( $end )->format( '%a' );
		return max( 0, $days - 1 );
	}

	/**
	 * Overnight-storage charge: charged nights x storage fee x bag count.
	 *
	 * @param array<string, int> $bag_counts Normalized luggage quantities.
	 * @param string             $service_date Service date (Y-m-d).
	 * @param string             $delivery_date Delivery date (Y-m-d).
	 * @return int Storage charge in VND, 0 when there is none.
	 */
	private function storage_charge( $bag_counts, $service_date, $delivery_date ) {
		$nights = $this->storage_nights( $service_date, $delivery_date );
		if ( $nights < 1 ) {
			return 0;
		}
		$prices     = $this->prices();
		$total_bags = (int) $bag_counts['cabin'] + (int) $bag_counts['medium'] + (int) $bag_counts['oversize'];
		return $nights * (int) $prices['storage'] * $total_bags;
	}

	/**
	 * Calculate the configured price for normalized bag quantities.
	 *
	 * @param array<string, int> $bag_counts Normalized luggage quantities.
	 * @param string             $service_date Service date (Y-m-d).
	 * @param string             $delivery_date Delivery date (Y-m-d).
	 * @return int
	 */
	private function calculate_estimate( $bag_counts, $service_date = '', $delivery_date = '' ) {
		$prices = $this->prices();
		$base   = ( $bag_counts['cabin'] * $prices['cabin'] )
			+ ( $bag_counts['medium'] * $prices['medium'] )
			+ ( $bag_counts['oversize'] * $prices['oversize'] );
		return $base + $this->storage_charge( $bag_counts, $service_date, $delivery_date );
	}

	/**
	 * Validate the selected payment method unless the booking requires manual review.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param bool                 $on_request Whether staff must review the booking.
	 * @return string
	 */
	private function validate_payment_method( $input, $on_request ) {
		$payment_method = '';
		if ( ! $on_request ) {
			$payment_method = $this->input_key( $input, 'payment_method' );
			if ( ! in_array( $payment_method, array( 'visa', 'apple_pay', 'google_pay', 'bank_transfer', 'cash' ), true ) ) {
				wp_send_json_error( array( 'message' => $this->text( 'validation.payment_required' ) ), 400 );
			}
		}

		return $payment_method;
	}

	/**
	 * Build the data object stored with each booking.
	 *
	 * @param array<string, mixed>  $input Unslashed request data.
	 * @param array<string, mixed>  $service Validated route and schedule.
	 * @param array<string, int>    $bag_counts Normalized luggage quantities.
	 * @param array<string, string> $customer Validated customer details.
	 * @param string                $payment_method Selected payment method.
	 * @param int                   $estimate Calculated estimate.
	 * @return array<string, mixed>
	 */
	private function build_booking_data( $input, $service, $bag_counts, $customer, $payment_method, $estimate ) {
		$is_storage = 'storage' === ( $service['service'] ?? '' );
		// For storage the whole estimate IS the storage fee; delivery
		// keeps its overnight-storage calculation (bag_counts there is
		// the cabin/medium/oversize shape, not the storage total).
		$storage_fee = $is_storage
			? $estimate
			: $this->storage_charge( $bag_counts, $service['service_date'], isset( $service['delivery_date'] ) ? $service['delivery_date'] : $service['service_date'] );
		$booking_data = array(
			'service'           => $is_storage ? 'storage' : 'delivery',
			'corridor'          => $service['corridor'],
			'service_date'      => $service['service_date'],
			'flight_number'     => isset( $service['flight_number'] ) ? $service['flight_number'] : '',
			'pickup_time'       => isset( $service['pickup_time'] ) ? $service['pickup_time'] : '',
			'airport_delivery_time' => isset( $service['airport_delivery_time'] ) ? $service['airport_delivery_time'] : '',
			'flight_departure_time' => isset( $service['flight_departure_time'] ) ? $service['flight_departure_time'] : '',
			'delivery_day'      => isset( $service['delivery_day'] ) ? $service['delivery_day'] : '',
			'delivery_date'     => isset( $service['delivery_date'] ) ? $service['delivery_date'] : $service['service_date'],
			'storage_fee'       => $storage_fee,
			'from_hotel'        => isset( $service['from_hotel'] ) ? $service['from_hotel'] : array(),
			'to_hotel'          => isset( $service['to_hotel'] ) ? $service['to_hotel'] : array(),
			'bags'              => $bag_counts,
			'promotion_code'    => $this->optional_text( $input, 'promotion_code', 30 ),
			'note'              => isset( $input['note'] ) && is_scalar( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : '',
			'customer'          => $customer,
			'payment_method'    => $payment_method,
			'estimate'          => $estimate,
			'terms_accepted_at' => current_time( 'mysql' ),
		);

		foreach ( array( 'from_booking_code', 'to_booking_code' ) as $key ) {
			if ( isset( $service[ $key ] ) ) {
				$booking_data[ $key ] = $service[ $key ];
			}
		}

		if ( $is_storage ) {
			$booking_data['hub']          = $service['hub'];
			$booking_data['dropoff_date'] = $service['dropoff_date'];
			$booking_data['dropoff_time'] = $service['dropoff_time'];
			$booking_data['pickup_date']  = $service['pickup_date'];
			$booking_data['pickup_time']  = $service['pickup_time'];
			$booking_data['storage_days'] = $service['storage_days'];
			// No delivery date on a storage booking; the admin detail
			// skips empty values, so this row stays hidden there.
			$booking_data['delivery_date'] = '';
		}

		return $booking_data;
	}

	/**
	 * @param array<string, mixed> $input Submitted values.
	 * @param string               $prefix Hotel field prefix.
	 * @return array<string, string>
	 */
	private function validate_hotel( $input, $prefix ) {
		$name      = $this->input_text( $input, $prefix . '_hotel_name' );
		$address   = $this->input_text( $input, $prefix . '_hotel_address' );
		$lat       = $this->input_text( $input, $prefix . '_hotel_lat' );
		$lng       = $this->input_text( $input, $prefix . '_hotel_lng' );
		$reception = $this->input_key( $input, $prefix . '_reception' );

		if ( '' === $name || strlen( $name ) > 190 || '' === $address || strlen( $address ) > 300 ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.hotel_address' ) ), 400 );
		}
		// Phase 1: the reception question is deferred, so an empty value is
		// accepted. When the question returns, a submitted value must still
		// be yes/no.
		if ( '' !== $reception && ! in_array( $reception, array( 'yes', 'no' ), true ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.hotel_reception' ) ), 400 );
		}
		if ( ( '' === $lat ) !== ( '' === $lng ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.hotel_coords_both' ) ), 400 );
		}
		if ( '' !== $lat && ( ! is_numeric( $lat ) || (float) $lat < -90 || (float) $lat > 90 || ! is_numeric( $lng ) || (float) $lng < -180 || (float) $lng > 180 ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.hotel_coords_invalid' ) ), 400 );
		}

		return array(
			'name'      => $name,
			'address'   => $address,
			'latitude'  => '' === $lat ? '' : (string) (float) $lat,
			'longitude' => '' === $lng ? '' : (string) (float) $lng,
			'reception' => $reception,
		);
	}

	/**
	 * @param array<string, mixed> $input Submitted values.
	 * @param string               $key Field key.
	 * @param int                  $max_length Maximum length.
	 * @return string
	 */
	private function optional_text( $input, $key, $max_length ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return '';
		}

		return substr( sanitize_text_field( $input[ $key ] ), 0, $max_length );
	}

	/**
	 * Read and sanitize a scalar text value from an already-unslashed request.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param string               $key Input key.
	 * @return string
	 */
	private function input_text( $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] )
			? sanitize_text_field( $input[ $key ] )
			: '';
	}

	/**
	 * Read and sanitize a scalar key value from an already-unslashed request.
	 *
	 * @param array<string, mixed> $input Unslashed request data.
	 * @param string               $key Input key.
	 * @return string
	 */
	private function input_key( $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] )
			? sanitize_key( $input[ $key ] )
			: '';
	}

	/**
	 * @param string $date Date in Y-m-d format.
	 * @return bool
	 */
	private function is_valid_service_date( $date ) {
		$date_object = DateTime::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $date_object
			&& $date_object->format( 'Y-m-d' ) === $date
			&& $date >= current_time( 'Y-m-d' );
	}

	/**
	 * @param string $time Time in H:i format.
	 * @return bool
	 */
	private function is_valid_time( $time ) {
		$time_object = DateTime::createFromFormat( '!H:i', $time, wp_timezone() );
		return $time_object && $time_object->format( 'H:i' ) === $time;
	}

	/**
	 * @param string $corridor Selected route.
	 * @return string|WP_Error Private file path or error.
	 */
	private function upload_booking_photo( $corridor ) {
		$file = isset( $_FILES['luggage_photo'] ) ? $_FILES['luggage_photo'] : null;
		// The luggage photo is optional on every route: a missing file
		// is not an error (hotel-collection guests are reminded in a
		// popup instead, before they leave Step 1).
		if ( null === $file ) {
			return '';
		}
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['tmp_name'], $file['size'] ) ) {
			return new WP_Error( 'photo_upload', $this->text( 'validation.photo_upload_invalid' ) );
		}
		if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return '';
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'photo_upload', $this->text( 'validation.photo_upload_failed' ) );
		}
		if ( (int) $file['size'] < 1 || (int) $file['size'] > 3 * MB_IN_BYTES ) {
			return new WP_Error( 'photo_size', $this->text( 'validation.photo_too_large' ) );
		}

		$checked = wp_check_filetype_and_ext(
			$file['tmp_name'],
			sanitize_file_name( $file['name'] ),
			array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png' )
		);
		if ( ! in_array( $checked['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'photo_type', $this->text( 'validation.photo_type_only' ) );
		}
		$image = getimagesize( $file['tmp_name'] );
		if ( ! $image || ! in_array( $image['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'photo_content', $this->text( 'validation.photo_not_image' ) );
		}

		$private_dir = $this->private_photo_directory();
		if ( ! wp_mkdir_p( $private_dir ) || ! $this->protect_private_photo_directory( $private_dir ) ) {
			return new WP_Error( 'photo_storage', $this->text( 'validation.photo_storage_unavailable' ) );
		}

		$upload_dir_filter = function ( $uploads ) use ( $private_dir ) {
			$uploads['path']   = $private_dir;
			$uploads['subdir'] = '';
			$uploads['url']    = '';
			return $uploads;
		};
		add_filter( 'upload_dir', $upload_dir_filter, 99 );
		try {
			$uploaded = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png' ),
					'unique_filename_callback' => function ( $directory, $name, $extension ) {
						return bin2hex( random_bytes( 16 ) ) . $extension;
					},
				)
			);
		} finally {
			remove_filter( 'upload_dir', $upload_dir_filter, 99 );
		}

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'photo_storage', $this->text( 'validation.photo_save_failed' ) );
		}

		$real_dir  = realpath( $private_dir );
		$real_file = isset( $uploaded['file'] ) ? realpath( $uploaded['file'] ) : false;
		if ( ! $real_dir || ! $real_file || 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) ) {
			if ( $real_file && is_file( $real_file ) ) {
				wp_delete_file( $real_file );
			}
			return new WP_Error( 'photo_path', $this->text( 'validation.photo_store_failed' ) );
		}

		return $real_file;
	}

	/**
	 * @return string
	 */
	private function private_photo_directory() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'easylugg-booking-private';
	}

	/**
	 * @param string $directory Private image directory.
	 * @return bool
	 */
	private function protect_private_photo_directory( $directory ) {
		$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		$path  = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $path ) && false === file_put_contents( $path, $rules, LOCK_EX ) ) {
			return false;
		}
		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) && false === file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX ) ) {
			return false;
		}

		return is_file( $path ) && is_file( $index );
	}

	/**
	 * @return string
	 */
	private function generate_booking_code() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$random   = '';
		for ( $index = 0; $index < 4; $index++ ) {
			$random .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return 'ELUGG-' . wp_date( 'ymd' ) . '-' . $random;
	}

	/**
	 * @param object $booking Booking row.
	 * @param string $token Tracking token.
	 * @param bool   $duplicate Existing booking.
	 * @param bool   $email_sent Duplicate means no repeat mail.
	 * @return void
	 */
	private function send_booking_success( $booking, $token, $duplicate, $email_sent, $is_storage = false ) {
		if ( ! hash_equals( $booking->tracking_token_hash, hash( 'sha256', $token ) ) ) {
			wp_send_json_error( array( 'message' => $this->text( 'validation.verify_failed' ) ), 500 );
		}

		$page       = get_page_by_path( 'bookings' );
		$tracking_url = add_query_arg(
			array(
				'el_track' => $booking->booking_code,
				'el_token' => $token,
			),
			$page ? get_permalink( $page ) : home_url( '/bookings/' )
		);
		$notice = '';
		if ( ! $duplicate ) {
			$subject = sprintf( $this->text( 'email.subject_received' ), $booking->booking_code );
			$message = sprintf(
				$this->text( $is_storage ? 'email.body_received_storage' : 'email.body_received' ),
				$this->status_label( $booking->status ),
				$booking->booking_code,
				number_format_i18n( (int) $booking->price_total ),
				$tracking_url
			);

			global $wpdb;
			$email = $wpdb->get_var(
				$wpdb->prepare( 'SELECT customer_email FROM ' . self::table_name() . ' WHERE booking_code = %s', $booking->booking_code )
			);
			if ( $email && wp_mail( $email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
				$email_sent = true;
			} else {
				$notice = $this->text( 'validation.email_failed' );
			}
		}

		wp_send_json_success(
			array(
				'booking_code' => $booking->booking_code,
				'status'       => $booking->status,
				'status_label' => $this->status_label( $booking->status ),
				'price'        => number_format_i18n( (int) $booking->price_total ) . ' VND',
				'tracking_url' => esc_url_raw( $tracking_url ),
				'email_sent'   => (bool) $email_sent,
				'notice'       => $notice,
				'duplicate'    => (bool) $duplicate,
			)
		);
	}

	/**
	 * @param string $corridor Corridor code.
	 * @return string
	 */
	private function corridor_label( $corridor ) {
		$labels = array(
			'AH' => $this->text( 'summary.route_ah' ),
			'HA' => $this->text( 'summary.route_ha' ),
			'HH' => $this->text( 'summary.route_hh' ),
			'ST' => $this->text( 'summary.route_st' ),
		);
		return isset( $labels[ $corridor ] ) ? $labels[ $corridor ] : $corridor;
	}

	/**
	 * @return array<string, string>
	 */
	private function status_labels() {
		return array(
			'requested'  => $this->text( 'status.requested' ),
			'confirmed'  => $this->text( 'status.confirmed' ),
			'collected'  => $this->text( 'status.collected' ),
			'in_transit' => $this->text( 'tracking.status_in_transit' ),
			'delivered'  => $this->text( 'tracking.status_delivered' ),
			'cancelled'  => $this->text( 'status.cancelled' ),
		);
	}

	/**
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = $this->status_labels();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $this->text( 'status.unknown' );
	}
}

register_activation_hook( __FILE__, array( 'EasyLugg_Booking_Plugin', 'activate' ) );
EasyLugg_Booking_Plugin::instance();
