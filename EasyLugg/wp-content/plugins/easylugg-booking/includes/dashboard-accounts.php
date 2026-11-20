<?php
/**
 * wp-admin "Booking Plugin" page: issue Staff accounts and manage them.
 * Thin on purpose (SRS ACC-01..08): users are ordinary WordPress users, the
 * role is fixed to easylugg_staff on the server, passwords are never typed or
 * shown — the person sets their own through WordPress' set-password link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EasyLugg_Dashboard_Accounts {

	const SLUG = 'easylugg-accounts';

	/**
	 * Capabilities Admin may switch off per Staff account (ACC-06).
	 *
	 * @return array<string, string>
	 */
	private static function toggle_caps() {
		return array(
			'easylugg_create_booking'      => __( 'Create bookings', 'easylugg-booking' ),
			'easylugg_edit_booking'        => __( 'Edit bookings', 'easylugg-booking' ),
			'easylugg_operate_booking'     => __( 'Operate (status, photos, payments)', 'easylugg-booking' ),
			'easylugg_cancel_booking'      => __( 'Cancel / no-show', 'easylugg-booking' ),
			'easylugg_cancel_paid_booking' => __( 'Cancel paid orders', 'easylugg-booking' ),
			'easylugg_clear_review'        => __( 'Clear review flag', 'easylugg-booking' ),
		);
	}

	/**
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		foreach ( array( 'create', 'lock', 'unlock', 'link', 'caps' ) as $name ) {
			add_action( 'admin_post_easylugg_account_' . $name, array( __CLASS__, 'handle_' . $name ) );
		}
		add_action( 'wp_login', array( __CLASS__, 'remember_login' ), 10, 2 );
	}

	/**
	 * @param string  $user_login Login.
	 * @param WP_User $user       User.
	 * @return void
	 */
	public static function remember_login( $user_login, $user ) {
		if ( user_can( $user, 'easylugg_view_dashboard' ) ) {
			update_user_meta( $user->ID, 'easylugg_last_login', current_time( 'mysql', true ) );
		}
	}

	/**
	 * @return void
	 */
	public static function register_page() {
		add_menu_page(
			__( 'Booking Plugin', 'easylugg-booking' ),
			__( 'Booking Plugin', 'easylugg-booking' ),
			'easylugg_manage_accounts',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-groups',
			57
		);
	}

	/**
	 * @param string $action Nonce action suffix.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'easylugg_manage_accounts' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage EasyLugg accounts.', 'easylugg-booking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easylugg_account_' . $action );
	}

	/**
	 * @param string $notice Notice key.
	 * @return void
	 */
	private static function back( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'el_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A target must be an EasyLugg Staff account — this page never touches
	 * administrators or other site users (no privilege changes, ACC-03).
	 *
	 * @return WP_User
	 */
	private static function target_staff() {
		$id   = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$user = $id ? get_userdata( $id ) : false;
		if ( ! $user || ! in_array( 'easylugg_staff', (array) $user->roles, true ) || user_can( $user, 'manage_options' ) ) {
			self::back( 'invalid' );
		}
		return $user;
	}

	/**
	 * Create a Staff account and send the set-password link.
	 *
	 * @return void
	 */
	public static function handle_create() {
		self::guard( 'create' );
		$name  = isset( $_POST['display_name'] ) && is_scalar( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$email = isset( $_POST['email'] ) && is_scalar( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( '' === $name || ! is_email( $email ) ) {
			self::back( 'invalid' );
		}
		if ( email_exists( $email ) || username_exists( $email ) ) {
			self::back( 'exists' );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'display_name' => $name,
				'nickname'     => $name,
				// The role is fixed here; a posted role is ignored (ACC-03).
				'role'         => 'easylugg_staff',
				'user_pass'    => wp_generate_password( 32, true, true ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::back( 'failed' );
		}
		EasyLugg_Dashboard_Setup::audit( 'account', (string) $user_id, 'account_created', array( 'meta' => array( 'email' => $email ) ) );
		self::issue_link( get_userdata( $user_id ) );
		self::back( 'created' );
	}

	/**
	 * Generate a set-password link, email it, and keep it for one display.
	 *
	 * @param WP_User $user Target user.
	 * @return void
	 */
	private static function issue_link( $user ) {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return;
		}
		$link = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		set_transient( 'easylugg_pw_link_' . get_current_user_id(), array( 'login' => $user->user_login, 'link' => $link ), 10 * MINUTE_IN_SECONDS );
		wp_mail(
			$user->user_email,
			__( 'Set your EasyLugg staff password', 'easylugg-booking' ),
			sprintf(
				/* translators: 1: dashboard URL, 2: set-password link */
				__( "Hello,\n\nAn EasyLugg staff account was created for you.\nSet your password here: %2\$s\n\nThen sign in at: %1\$s\n", 'easylugg-booking' ),
				EasyLugg_Dashboard_Setup::dashboard_url(),
				$link
			)
		);
	}

	/**
	 * @return void
	 */
	public static function handle_link() {
		self::guard( 'link' );
		$user = self::target_staff();
		self::issue_link( $user );
		EasyLugg_Dashboard_Setup::audit( 'account', (string) $user->ID, 'password_link_sent' );
		self::back( 'link' );
	}

	/**
	 * @return void
	 */
	public static function handle_lock() {
		self::guard( 'lock' );
		$user = self::target_staff();
		EasyLugg_Dashboard_Setup::set_locked( $user->ID, true );
		EasyLugg_Dashboard_Setup::audit( 'account', (string) $user->ID, 'account_locked' );
		self::back( 'locked' );
	}

	/**
	 * @return void
	 */
	public static function handle_unlock() {
		self::guard( 'unlock' );
		$user = self::target_staff();
		EasyLugg_Dashboard_Setup::set_locked( $user->ID, false );
		EasyLugg_Dashboard_Setup::audit( 'account', (string) $user->ID, 'account_unlocked' );
		self::back( 'unlocked' );
	}

	/**
	 * Per-account capability switches: checked = role default, unchecked = denied.
	 *
	 * @return void
	 */
	public static function handle_caps() {
		self::guard( 'caps' );
		$user    = self::target_staff();
		$allowed = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['caps'] ) ) : array();
		$changes = array();
		foreach ( array_keys( self::toggle_caps() ) as $cap ) {
			$on = in_array( $cap, $allowed, true );
			if ( $on ) {
				$user->remove_cap( $cap );
			} else {
				$user->add_cap( $cap, false );
			}
			$changes[ $cap ] = $on;
		}
		EasyLugg_Dashboard_Setup::audit( 'account', (string) $user->ID, 'caps_changed', array( 'meta' => $changes ) );
		self::back( 'caps' );
	}

	/**
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'easylugg_manage_accounts' ) ) {
			return;
		}
		$notices = array(
			'created'  => __( 'Account created. A set-password link was emailed to the person.', 'easylugg-booking' ),
			'link'     => __( 'A new set-password link was emailed.', 'easylugg-booking' ),
			'locked'   => __( 'Account locked and signed out.', 'easylugg-booking' ),
			'unlocked' => __( 'Account unlocked.', 'easylugg-booking' ),
			'caps'     => __( 'Permissions saved.', 'easylugg-booking' ),
			'exists'   => __( 'An account with that email already exists.', 'easylugg-booking' ),
			'invalid'  => __( 'Please check the name and email.', 'easylugg-booking' ),
			'failed'   => __( 'The account could not be created.', 'easylugg-booking' ),
		);
		$key = isset( $_GET['el_notice'] ) && is_scalar( $_GET['el_notice'] ) ? sanitize_key( wp_unslash( $_GET['el_notice'] ) ) : '';
		$link = get_transient( 'easylugg_pw_link_' . get_current_user_id() );
		if ( $link ) {
			delete_transient( 'easylugg_pw_link_' . get_current_user_id() );
		}
		$users = get_users( array( 'role' => 'easylugg_staff', 'orderby' => 'display_name' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Booking Plugin — Staff accounts', 'easylugg-booking' ); ?></h1>
			<p><?php esc_html_e( 'Staff sign in on the Dashboard page. Administrators are managed in Users. Each person gets their own account; do not share logins.', 'easylugg-booking' ); ?></p>
			<?php if ( $key && isset( $notices[ $key ] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notices[ $key ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $link ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'If the email does not arrive, give this one-time link to the person (shown only once):', 'easylugg-booking' ); ?><br>
					<code><?php echo esc_html( $link['link'] ); ?></code>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a staff account', 'easylugg-booking' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="easylugg_account_create">
				<?php wp_nonce_field( 'easylugg_account_create' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th><label for="el-acc-name"><?php esc_html_e( 'Name', 'easylugg-booking' ); ?></label></th><td><input id="el-acc-name" name="display_name" class="regular-text" required></td></tr>
					<tr><th><label for="el-acc-email"><?php esc_html_e( 'Email', 'easylugg-booking' ); ?></label></th><td><input id="el-acc-email" name="email" type="email" class="regular-text" required></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Create account and email set-password link', 'easylugg-booking' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Staff accounts', 'easylugg-booking' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'easylugg-booking' ); ?></th>
					<th><?php esc_html_e( 'Status', 'easylugg-booking' ); ?></th>
					<th><?php esc_html_e( 'Last sign-in', 'easylugg-booking' ); ?></th>
					<th><?php esc_html_e( 'Permissions', 'easylugg-booking' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'easylugg-booking' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $users ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No staff accounts yet.', 'easylugg-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$locked = EasyLugg_Dashboard_Setup::is_locked( $user->ID );
					$last   = get_user_meta( $user->ID, 'easylugg_last_login', true );
					?>
					<tr>
						<td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><small><?php echo esc_html( $user->user_email ); ?></small></td>
						<td><?php echo $locked ? esc_html__( 'Locked', 'easylugg-booking' ) : esc_html__( 'Active', 'easylugg-booking' ); ?></td>
						<td><?php echo $last ? esc_html( get_date_from_gmt( $last, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '—'; ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="easylugg_account_caps">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
								<?php wp_nonce_field( 'easylugg_account_caps' ); ?>
								<?php foreach ( self::toggle_caps() as $cap => $label ) : ?>
									<label style="display:block"><input type="checkbox" name="caps[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( user_can( $user, $cap ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
								<?php submit_button( __( 'Save', 'easylugg-booking' ), 'small', 'submit', false ); ?>
							</form>
						</td>
						<td>
							<?php foreach ( array( $locked ? 'unlock' : 'lock' => $locked ? __( 'Unlock', 'easylugg-booking' ) : __( 'Lock', 'easylugg-booking' ), 'link' => __( 'Send set-password link', 'easylugg-booking' ) ) as $action => $label ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:6px">
									<input type="hidden" name="action" value="easylugg_account_<?php echo esc_attr( $action ); ?>">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
									<?php wp_nonce_field( 'easylugg_account_' . $action ); ?>
									<?php submit_button( $label, 'secondary small', 'submit', false ); ?>
								</form>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
